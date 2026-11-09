<?php
/**
 * API Surat Masuk (surat yang diterima TSU dari pihak luar maupun unit lain di dalam kampus).
 * Alur: Penerimaan & pencatatan -> Disposisi (pimpinan memberi arahan) -> Tindak lanjut.
 *   GET  api/surat_masuk.php?aksi=daftar&status=&arsip=&jenis=&q=&tahun=&bulan=
 *   GET  api/surat_masuk.php?aksi=detail&id=
 *   GET  api/surat_masuk.php?aksi=file&id=&unduh=1
 *   POST (multipart/form-data atau form biasa), kolom 'aksi':
 *        tambah | ubah | hapus | status | arsip_simpan | arsip_batal
 * Status surat: diterima -> didisposisikan -> ditindaklanjuti. Status arsip: belum (merah) / diarsipkan (hijau).
 */
require __DIR__ . '/db.php';

const MAKS_UKURAN = 10 * 1024 * 1024;                       // 10 MB
const TIPE_FILE = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
const STATUS_BOLEH = ['diterima', 'didisposisikan', 'ditindaklanjuti'];
const SIFAT_BOLEH = ['penting', 'segera', 'rahasia', 'biasa'];
const JENIS_PENGIRIM = ['eksternal', 'internal'];

function keluar($data, int $kode = 200): void
{
    http_response_code($kode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function teks($v, int $maks = 255): string
{
    $v = trim(preg_replace('/\s+/u', ' ', (string) $v));
    return function_exists('mb_substr') ? mb_substr($v, 0, $maks) : substr($v, 0, $maks);
}

function tanggal_valid($v): ?string
{
    $d = DateTime::createFromFormat('Y-m-d', (string) $v);
    return ($d && $d->format('Y-m-d') === $v) ? $v : null;
}

function nama_aman(string $f): bool { return (bool) preg_match('/^[a-f0-9]{32}\.(pdf|jpg|png)$/', $f); }
function hapus_file(string $f): void { if ($f !== '' && nama_aman($f)) @unlink(FOLDER_ARSIP . '/' . $f); }

function ambil(PDO $pdo, int $id): ?array
{
    $s = $pdo->prepare('SELECT * FROM surat_masuk WHERE id = ?');
    $s->execute([$id]);
    return $s->fetch() ?: null;
}

/** Simpan file unggahan (bila ada). Mengembalikan [nama_di_server, nama_asli] atau null bila tidak ada file. */
function simpan_unggahan(): ?array
{
    $up = $_FILES['file'] ?? null;
    if (!$up || $up['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ($up['error'] === UPLOAD_ERR_INI_SIZE || $up['error'] === UPLOAD_ERR_FORM_SIZE) keluar(['error' => 'Ukuran file melebihi batas server (upload_max_filesize di php.ini).'], 413);
    if ($up['error'] !== UPLOAD_ERR_OK) keluar(['error' => 'Upload file gagal (kode ' . $up['error'] . ').'], 400);
    if ($up['size'] > MAKS_UKURAN) keluar(['error' => 'Ukuran file maksimal 10 MB.'], 422);
    $mime = function_exists('finfo_open') ? finfo_file(finfo_open(FILEINFO_MIME_TYPE), $up['tmp_name']) : '';
    if (!isset(TIPE_FILE[$mime])) keluar(['error' => 'Format file harus PDF, JPG, atau PNG.'], 422);
    $nama = bin2hex(random_bytes(16)) . '.' . TIPE_FILE[$mime];
    if (!move_uploaded_file($up['tmp_name'], FOLDER_ARSIP . '/' . $nama)) keluar(['error' => 'Gagal menyimpan file ke folder data/arsip.'], 500);
    return [$nama, teks(basename((string) $up['name']), 200)];
}

/** Validasi isian form; mengembalikan [data, pesan_error]. */
function baca_form(array $in): array
{
    $d = [
        'tanggal_terima'   => tanggal_valid($in['tanggal_terima'] ?? ''),
        'tanggal_surat'    => tanggal_valid($in['tanggal_surat'] ?? '') ?: null,
        'nomor_surat_asal' => teks($in['nomor_surat_asal'] ?? '', 100),
        'jenis_pengirim'   => in_array($in['jenis_pengirim'] ?? '', JENIS_PENGIRIM, true) ? $in['jenis_pengirim'] : 'eksternal',
        'pengirim'         => teks($in['pengirim'] ?? '', 150),
        'perihal'          => teks($in['perihal'] ?? ''),
        'sifat'            => in_array($in['sifat'] ?? '', SIFAT_BOLEH, true) ? $in['sifat'] : 'biasa',
        'tujuan'           => teks($in['tujuan'] ?? '', 150),
        'status'           => in_array($in['status'] ?? '', STATUS_BOLEH, true) ? $in['status'] : 'diterima',
    ];
    if (!$d['tanggal_terima']) return [null, 'Tanggal terima tidak valid.'];
    if ($d['pengirim'] === '')  return [null, 'Pengirim wajib diisi.'];
    if ($d['perihal'] === '')   return [null, 'Perihal wajib diisi.'];
    return [$d, null];
}

const ACC_SQL = '(CASE WHEN s.acc_masuk = 1 OR (SELECT d.status FROM disposisi d WHERE d.surat_masuk_id = s.id OR (s.dari_surat_id IS NOT NULL AND d.surat_id = s.dari_surat_id) ORDER BY d.id LIMIT 1) = \'disetujui\' THEN \'disetujui\' END)';

const KOLOM = 's.id, s.nomor_agenda, s.nomor_surat_asal, s.tanggal_surat, s.tanggal_terima, s.jenis_pengirim, s.pengirim, s.perihal, s.sifat, s.tujuan, s.status,
               s.status_arsip, s.tanggal_arsip, s.lokasi_arsip, s.keterangan_arsip, s.file_nama, s.link_drive, (s.file_arsip <> \'\' OR s.link_drive <> \'\') AS ada_file, s.dari_surat_id,
               (SELECT d.id FROM disposisi d WHERE d.surat_masuk_id = s.id OR (s.dari_surat_id IS NOT NULL AND d.surat_id = s.dari_surat_id) ORDER BY d.id LIMIT 1) AS disposisi_id,
               (SELECT d.no_disposisi FROM disposisi d WHERE d.surat_masuk_id = s.id OR (s.dari_surat_id IS NOT NULL AND d.surat_id = s.dari_surat_id) ORDER BY d.id LIMIT 1) AS no_disposisi,
               (SELECT d.status FROM disposisi d WHERE d.surat_masuk_id = s.id OR (s.dari_surat_id IS NOT NULL AND d.surat_id = s.dari_surat_id) ORDER BY d.id LIMIT 1) AS status_disposisi, (s.acc_masuk = 1 OR (SELECT d.status FROM disposisi d WHERE d.surat_masuk_id = s.id OR (s.dari_surat_id IS NOT NULL AND d.surat_id = s.dari_surat_id) ORDER BY d.id LIMIT 1) = \'disetujui\') AS acc_masuk,
               (SELECT d.tujuan_disposisi FROM disposisi d WHERE d.surat_masuk_id = s.id OR (s.dari_surat_id IS NOT NULL AND d.surat_id = s.dari_surat_id) ORDER BY d.id LIMIT 1) AS tujuan_disposisi';

try {
    $pdo = db();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        switch ($_GET['aksi'] ?? 'daftar') {
            case 'file':
                $r = ambil($pdo, (int) ($_GET['id'] ?? 0));
                $path = $r ? FOLDER_ARSIP . '/' . $r['file_arsip'] : '';
                if (!$r || !nama_aman($r['file_arsip']) || !is_file($path)) { http_response_code(404); exit('File tidak ditemukan.'); }
                $ext = pathinfo($r['file_arsip'], PATHINFO_EXTENSION);
                $mime = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png'][$ext];
                $unduh = ($_GET['unduh'] ?? '') === '1' ? 'attachment' : 'inline';
                $nama = preg_replace('/[^A-Za-z0-9._-]+/', '_', str_replace('/', '-', $r['nomor_agenda'])) . '.' . $ext;
                header('Content-Type: ' . $mime);
                header('Content-Length: ' . filesize($path));
                header('X-Content-Type-Options: nosniff');
                header("Content-Disposition: $unduh; filename=\"$nama\"");
                readfile($path);
                exit;

            case 'detail':
                $s = $pdo->prepare('SELECT ' . KOLOM . ' FROM surat_masuk s WHERE s.id = ?');
                $s->execute([(int) ($_GET['id'] ?? 0)]);
                $r = $s->fetch();
                if (!$r) keluar(['error' => 'Surat masuk tidak ditemukan.'], 404);
                $r['ada_file'] = (bool) $r['ada_file'];
                keluar(['surat_masuk' => $r]);

            case 'daftar':
                $where = []; $par = [];
                if (($_GET['acc'] ?? '') === 'sudah') $where[] = ACC_SQL . ' = \'disetujui\'';
                if (($_GET['acc'] ?? '') === 'belum') $where[] = '(' . ACC_SQL . ' IS NULL OR ' . ACC_SQL . ' <> \'disetujui\')';
                if (($_GET['siap_arsip'] ?? '') === '1') $where[] = ACC_SQL . ' = \'disetujui\' AND (s.file_arsip <> \'\' OR s.link_drive <> \'\')';
                if (in_array($_GET['arsip'] ?? '', ['belum', 'diarsipkan'], true)) { $where[] = 's.status_arsip = ?'; $par[] = $_GET['arsip']; }
                if (in_array($_GET['jenis'] ?? '', JENIS_PENGIRIM, true)) { $where[] = 's.jenis_pengirim = ?'; $par[] = $_GET['jenis']; }
                if (in_array($_GET['sifat'] ?? '', SIFAT_BOLEH, true)) { $where[] = 's.sifat = ?'; $par[] = $_GET['sifat']; }
                if (($q = teks($_GET['q'] ?? '', 80)) !== '') {
                    $where[] = '(s.nomor_agenda LIKE ? OR s.nomor_surat_asal LIKE ? OR s.pengirim LIKE ? OR s.perihal LIKE ? OR s.tujuan LIKE ? OR s.lokasi_arsip LIKE ?)';
                    array_push($par, ...array_fill(0, 6, "%$q%"));
                }
                if ((int) ($_GET['tahun'] ?? 0) > 0) { $where[] = 's.tahun = ?'; $par[] = (int) $_GET['tahun']; }
                if ((int) ($_GET['bulan'] ?? 0) > 0) { $where[] = 's.bulan = ?'; $par[] = (int) $_GET['bulan']; }
                $sql = 'SELECT ' . KOLOM . ' FROM surat_masuk s' . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
                     . ' ORDER BY s.tanggal_terima DESC, s.id DESC LIMIT 500';
                $s = $pdo->prepare($sql);
                $s->execute($par);
                $baris = $s->fetchAll();
                foreach ($baris as &$b) $b['ada_file'] = (bool) $b['ada_file'];
                unset($b);
                $r = $pdo->query('SELECT COUNT(*) AS total, (SELECT COUNT(*) FROM surat_masuk s WHERE ' . ACC_SQL . ' = \'disetujui\') AS selesai, COALESCE(SUM(status_arsip = \'diarsipkan\'), 0) AS diarsipkan FROM surat_masuk')->fetch();
                $tahun = array_map('intval', $pdo->query('SELECT DISTINCT tahun FROM surat_masuk ORDER BY tahun DESC')->fetchAll(PDO::FETCH_COLUMN));
                $siap = $pdo->query('SELECT COUNT(*) AS t, COALESCE(SUM(status_arsip = \'diarsipkan\'), 0) AS d FROM surat_masuk s WHERE ' . ACC_SQL . ' = \'disetujui\' AND (s.file_arsip <> \'\' OR s.link_drive <> \'\')')->fetch();
                keluar(['total_siap' => (int) $siap['t'], 'diarsipkan_siap' => (int) $siap['d'], 'surat_masuk' => $baris, 'total' => (int) $r['total'], 'ditindaklanjuti' => (int) $r['selesai'], 'diarsipkan' => (int) $r['diarsipkan'], 'daftar_tahun' => $tahun]);

            default:
                keluar(['error' => 'Aksi tidak dikenal.'], 400);
        }
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') keluar(['error' => 'Metode tidak didukung.'], 405);
    if (!$_POST && !$_FILES && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        keluar(['error' => 'Ukuran file melebihi batas server (post_max_size di php.ini).'], 413);
    }

    switch ($_POST['aksi'] ?? '') {
        case 'tambah':
            [$d, $err] = baca_form($_POST);
            if ($err) keluar(['error' => $err], 422);
            $tahun = (int) substr($d['tanggal_terima'], 0, 4);
            $bulan = (int) substr($d['tanggal_terima'], 5, 2);
            $berkas = simpan_unggahan();
            if (($link = link_drive_bersih($_POST['link'] ?? '')) === null) keluar(['error' => 'Link harus berupa tautan Google Drive (https://drive.google.com/...).'], 422);
            for ($coba = 0; $coba < 5; $coba++) {
                $s = $pdo->prepare('SELECT COALESCE(MAX(urut), 0) FROM surat_masuk WHERE tahun = ? AND bulan = ?');
                $s->execute([$tahun, $bulan]);
                $urut = (int) $s->fetchColumn() + 1;
                $agenda = sprintf('%03d/AGD/%s/%d', $urut, bulan_romawi($bulan), $tahun);
                try {
                    $pdo->prepare('INSERT INTO surat_masuk (tahun, bulan, urut, nomor_agenda, nomor_surat_asal, tanggal_surat, tanggal_terima, jenis_pengirim,
                            pengirim, perihal, sifat, tujuan, status, file_arsip, file_nama, link_drive) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                        ->execute([$tahun, $bulan, $urut, $agenda, $d['nomor_surat_asal'], $d['tanggal_surat'], $d['tanggal_terima'], $d['jenis_pengirim'],
                            $d['pengirim'], $d['perihal'], $d['sifat'], $d['tujuan'], 'diterima', $berkas[0] ?? '', $berkas[1] ?? '', $link]);
                    keluar(['ok' => true, 'id' => (int) $pdo->lastInsertId(), 'nomor_agenda' => $agenda], 201);
                } catch (PDOException $e) {
                    if ($e->getCode() !== '23000') { if ($berkas) hapus_file($berkas[0]); throw $e; }
                }
            }
            if ($berkas) hapus_file($berkas[0]);
            keluar(['error' => 'Gagal mendapatkan nomor agenda, silakan coba lagi.'], 409);

        case 'ubah':
            $id = (int) ($_POST['id'] ?? 0);
            $lama = ambil($pdo, $id);
            if (!$lama) keluar(['error' => 'Surat masuk tidak ditemukan.'], 404);
            [$d, $err] = baca_form($_POST);
            if ($err) keluar(['error' => $err], 422);
            $file = $lama['file_arsip']; $nama = $lama['file_nama']; $link = $lama['link_drive'];
            if (($_POST['hapus_file'] ?? '') === '1') { hapus_file($file); $file = ''; $nama = ''; $link = ''; }
            if (($lb = link_drive_bersih($_POST['link'] ?? '')) === null) keluar(['error' => 'Link harus berupa tautan Google Drive (https://drive.google.com/...).'], 422);
            if ($lb !== '') { hapus_file($file); $file = ''; $nama = ''; $link = $lb; }
            if ($berkas = simpan_unggahan()) { hapus_file($file); [$file, $nama] = $berkas; $link = ''; }
            $pdo->prepare('UPDATE surat_masuk SET nomor_surat_asal = ?, tanggal_surat = ?, tanggal_terima = ?, jenis_pengirim = ?, pengirim = ?, perihal = ?, sifat = ?,
                    tujuan = ?, status = ?, file_arsip = ?, file_nama = ?, link_drive = ? WHERE id = ?')
                ->execute([$d['nomor_surat_asal'], $d['tanggal_surat'], $d['tanggal_terima'], $d['jenis_pengirim'], $d['pengirim'], $d['perihal'], $d['sifat'],
                    $d['tujuan'], $d['status'], $file, $nama, $link, $id]);
            keluar(['ok' => true, 'nomor_agenda' => $lama['nomor_agenda']]);

        case 'hapus':
            $id = (int) ($_POST['id'] ?? 0);
            $r = ambil($pdo, $id);
            if (!$r) keluar(['error' => 'Surat masuk tidak ditemukan.'], 404);
            $c = $pdo->prepare('SELECT no_disposisi FROM disposisi WHERE surat_masuk_id = ? LIMIT 1');
            $c->execute([$id]);
            if ($no = $c->fetchColumn()) keluar(['error' => 'Surat masuk ini sudah punya disposisi ' . $no . '. Hapus disposisinya terlebih dahulu.'], 409);
            $pdo->prepare('DELETE FROM surat_masuk WHERE id = ?')->execute([$id]);
            hapus_file($r['file_arsip']);
            keluar(['ok' => true]);

        case 'acc':
            $id = (int) ($_POST['id'] ?? 0);
            if (!ambil($pdo, $id)) keluar(['error' => 'Surat masuk tidak ditemukan.'], 404);
            $pdo->prepare('UPDATE surat_masuk SET acc_masuk = ? WHERE id = ?')->execute([empty($_POST['nilai']) ? 0 : 1, $id]);
            keluar(['ok' => true]);

        case 'status':
            $id = (int) ($_POST['id'] ?? 0);
            if (!ambil($pdo, $id)) keluar(['error' => 'Surat masuk tidak ditemukan.'], 404);
            if (!in_array($_POST['status'] ?? '', STATUS_BOLEH, true)) keluar(['error' => 'Status tidak dikenal.'], 422);
            $pdo->prepare('UPDATE surat_masuk SET status = ? WHERE id = ?')->execute([$_POST['status'], $id]);
            keluar(['ok' => true]);

        case 'arsip_simpan':
            $id = (int) ($_POST['id'] ?? 0);
            $r = ambil($pdo, $id);
            if (!$r) keluar(['error' => 'Surat masuk tidak ditemukan.'], 404);
            $lokasi = teks($_POST['lokasi_arsip'] ?? '', 150);
            $ket = teks($_POST['keterangan_arsip'] ?? '', 255);
            $tgl = tanggal_valid($_POST['tanggal_arsip'] ?? '') ?: date('Y-m-d');
            $file = $r['file_arsip']; $nama = $r['file_nama']; $link = $r['link_drive'];
            if (($_POST['hapus_file'] ?? '') === '1') { hapus_file($file); $file = ''; $nama = ''; $link = ''; }
            if (($lb = link_drive_bersih($_POST['link'] ?? '')) === null) keluar(['error' => 'Link harus berupa tautan Google Drive (https://drive.google.com/...).'], 422);
            if ($lb !== '') { hapus_file($file); $file = ''; $nama = ''; $link = $lb; }
            if ($berkas = simpan_unggahan()) { hapus_file($file); [$file, $nama] = $berkas; $link = ''; }
            if ($lokasi === '' && $file === '' && $link === '') keluar(['error' => 'Isi lokasi arsip fisik atau unggah file scan (minimal salah satu).'], 422);
            $pdo->prepare('UPDATE surat_masuk SET status_arsip = \'diarsipkan\', tanggal_arsip = ?, lokasi_arsip = ?, keterangan_arsip = ?, file_arsip = ?, file_nama = ?, link_drive = ? WHERE id = ?')
                ->execute([$tgl, $lokasi, $ket, $file, $nama, $link, $id]);
            keluar(['ok' => true]);

        case 'arsip_batal':
            $id = (int) ($_POST['id'] ?? 0);
            if (!ambil($pdo, $id)) keluar(['error' => 'Surat masuk tidak ditemukan.'], 404);
            // file scan surat tetap disimpan; hanya status dan data arsip fisik yang direset
            $pdo->prepare('UPDATE surat_masuk SET status_arsip = \'belum\', tanggal_arsip = NULL, lokasi_arsip = \'\', keterangan_arsip = \'\' WHERE id = ?')->execute([$id]);
            keluar(['ok' => true]);

        default:
            keluar(['error' => 'Aksi tidak dikenal.'], 400);
    }
} catch (Throwable $e) {
    $pesan = $e instanceof PDOException && in_array($e->getCode(), [2002, 1045])
        ? 'Tidak dapat terhubung ke MySQL. Pastikan MySQL di Laragon sudah Start.'
        : 'Kesalahan server: ' . $e->getMessage();
    keluar(['error' => $pesan], 500);
}
