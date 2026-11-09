<?php
/**
 * API Disposisi: menyalurkan surat ke tujuan yang benar (misalnya Rektor) dan menunggu persetujuan (ACC).
 * Alur: surat dinomori di Penomoran -> file surat diunggah di Disposisi (disposisi otomatis dibuat, belum di-ACC)
 *       -> di-ACC. Setelah di-ACC surat tercatat sebagai Surat Keluar; bila internal (A), otomatis tercatat juga
 *       sebagai Surat Masuk bagi penerimanya. Surat Keluar/Masuk kemudian bisa diarsipkan di Pengarsipan.
 *   GET  api/disposisi.php?aksi=daftar&status=&q=&tahun=&bulan=
 *   GET  api/disposisi.php?aksi=detail&id=
 *   GET  api/disposisi.php?aksi=file&id=&unduh=1   -> file surat milik disposisi (PDF/JPG/PNG)
 *   GET  api/disposisi.php?aksi=sumber        -> surat (dari Penomoran dan Surat Masuk) yang belum punya disposisi
 *   POST api/disposisi.php (multipart/form-data) aksi=unggah : surat_id | surat_masuk_id, file
 *        -> menyimpan file surat dan membuat disposisi (belum di-ACC) bila surat itu belum punya
 *   POST api/disposisi.php {aksi: tambah | ubah | hapus | setujui | batal_setuju, ...}  (JSON)
 * Status: belum (merah) -> disetujui (hijau).
 */
require __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const SIFAT_BOLEH = ['penting', 'segera', 'rahasia', 'biasa'];
const JUMLAH_ISI = 12;       // 12 butir "Isi disposisi" pada lembar
const MAKS_ALUR = 20;        // baris alur disposisi
const MAKS_UKURAN = 10 * 1024 * 1024;                       // 10 MB
const TIPE_FILE = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];

function keluar($data, int $kode = 200): void
{
    http_response_code($kode);
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

/** "1, 3 ,x,3,15" -> "1,3" (hanya angka 1..12, tanpa duplikat, urut). */
function nomor_isi($v): string
{
    $arr = is_array($v) ? $v : preg_split('/[^0-9]+/', (string) $v);
    $ok = [];
    foreach ($arr as $x) {
        $n = (int) $x;
        if ($n >= 1 && $n <= JUMLAH_ISI) $ok[$n] = $n;
    }
    ksort($ok);
    return implode(',', $ok);
}

function format_no(int $urut, int $bulan, int $tahun): string
{
    return sprintf('%03d/DSP/%s/%d', $urut, bulan_romawi($bulan), $tahun);
}

function urut_berikutnya(PDO $pdo, int $tahun, int $bulan): int
{
    $s = $pdo->prepare('SELECT COALESCE(MAX(urut), 0) FROM disposisi WHERE tahun = ? AND bulan = ?');
    $s->execute([$tahun, $bulan]);
    return (int) $s->fetchColumn() + 1;
}

function satu_baris(PDO $pdo, string $sql, array $par): ?array
{
    $s = $pdo->prepare($sql);
    $s->execute($par);
    return $s->fetch() ?: null;
}

/**
 * Validasi isian form; mengembalikan [data, alur, pesan_error].
 * Saat membuat disposisi baru ($baru = true), surat yang disalurkan wajib dipilih (surat dari Penomoran atau Surat Masuk);
 * jenis/unit/tujuan diambil dari surat tersebut.
 */
function baca_form(PDO $pdo, array $in, bool $baru): array
{
    $d = [
        'tanggal_terima'   => tanggal_valid($in['tanggal_terima'] ?? ''),
        'hal'              => teks($in['hal'] ?? ''),
        'pemohon'          => teks($in['pemohon'] ?? '', 100),
        'tujuan_disposisi' => teks($in['tujuan_disposisi'] ?? '', 100),
        'isi'              => nomor_isi($in['isi'] ?? []),
    ];
    $sifat = array_slice(array_values(array_intersect(SIFAT_BOLEH, array_map('strval', (array) ($in['sifat'] ?? [])))), 0, 1);   // satu sifat saja
    $d['sifat'] = implode(',', $sifat);

    if (!$d['tanggal_terima'])         return [null, null, 'Tanggal disposisi tidak valid.'];
    if ($d['tujuan_disposisi'] === '') return [null, null, 'Isi tujuan penyaluran disposisi (misalnya Rektor).'];
    if ($d['sifat'] === '')            return [null, null, 'Pilih sifat disposisi (Penting, Segera, Rahasia, atau Biasa).'];
    if ($d['hal'] === '')              return [null, null, 'Hal (perihal) wajib diisi.'];
    if ($d['pemohon'] === '')          return [null, null, 'Nama pemohon wajib diisi.'];

    if ($baru) {
        $sid = (int) ($in['surat_id'] ?? 0);
        $mid = (int) ($in['surat_masuk_id'] ?? 0);
        if ($sid) {
            $s = satu_baris($pdo, 'SELECT jenis_kode, unit_kode, kode_tujuan, untuk FROM surat WHERE id = ?', [$sid]);
            if (!$s) return [null, null, 'Surat yang dipilih tidak ditemukan.'];
            if (satu_baris($pdo, 'SELECT id FROM disposisi WHERE surat_id = ?', [$sid])) return [null, null, 'Surat ini sudah punya disposisi.'];
            $d += ['surat_id' => $sid, 'surat_masuk_id' => null, 'kode_jenis' => $s['jenis_kode'], 'unit_kode' => $s['unit_kode'],
                   'kode_tujuan' => $s['kode_tujuan'], 'untuk' => $s['untuk']];
        } elseif ($mid) {
            $m = satu_baris($pdo, 'SELECT tujuan, jenis_pengirim FROM surat_masuk WHERE id = ?', [$mid]);
            if (!$m) return [null, null, 'Surat masuk yang dipilih tidak ditemukan.'];
            if (satu_baris($pdo, 'SELECT id FROM disposisi WHERE surat_masuk_id = ?', [$mid])) return [null, null, 'Surat masuk ini sudah punya disposisi.'];
            $d += ['surat_id' => null, 'surat_masuk_id' => $mid, 'kode_jenis' => '', 'unit_kode' => '',
                   'kode_tujuan' => $m['jenis_pengirim'] === 'internal' ? 'A' : 'B', 'untuk' => teks($m['tujuan'])];
        } else {
            return [null, null, 'Pilih surat yang akan disalurkan (surat dari Penomoran atau Surat Masuk).'];
        }
    }

    $alur = [];
    foreach (array_slice((array) ($in['alur'] ?? []), 0, MAKS_ALUR) as $r) {
        if (!is_array($r)) continue;
        $b = [
            'tanggal'   => tanggal_valid($r['tanggal'] ?? ''),
            'kepada'    => teks($r['kepada'] ?? '', 100),
            'isi_nomor' => nomor_isi($r['isi_nomor'] ?? ''),
            'dari'      => teks($r['dari'] ?? '', 100),
            'paraf'     => teks($r['paraf'] ?? '', 40),
        ];
        if (!$b['tanggal'] && $b['kepada'] === '' && $b['isi_nomor'] === '' && $b['dari'] === '' && $b['paraf'] === '') continue;
        $alur[] = $b;
    }
    return [$d, $alur, null];
}

function simpan_alur(PDO $pdo, int $id, array $alur): void
{
    $pdo->prepare('DELETE FROM disposisi_alur WHERE disposisi_id = ?')->execute([$id]);
    $s = $pdo->prepare('INSERT INTO disposisi_alur (disposisi_id, urutan, tanggal, kepada, isi_nomor, dari, paraf) VALUES (?, ?, ?, ?, ?, ?, ?)');
    foreach ($alur as $i => $b) $s->execute([$id, $i + 1, $b['tanggal'], $b['kepada'], $b['isi_nomor'], $b['dari'], $b['paraf']]);
}

/** Kolom daftar + nomor surat yang disalurkan. */
const KOLOM = 'd.id, d.surat_id, d.surat_masuk_id,
               (SELECT s.nomor_surat FROM surat s WHERE s.id = d.surat_id) AS nomor_surat,
               (SELECT m.nomor_agenda FROM surat_masuk m WHERE m.id = d.surat_masuk_id) AS nomor_agenda,
               d.no_disposisi, d.tanggal_terima, d.sifat, d.hal, d.kode_jenis, d.unit_kode, d.kode_tujuan, d.untuk, d.pemohon,
               d.tujuan_disposisi, d.isi, d.status, d.tanggal_setuju, d.disetujui_oleh,
               COALESCE((SELECT s.file_nama FROM surat s WHERE s.id = d.surat_id),
                        (SELECT m.file_nama FROM surat_masuk m WHERE m.id = d.surat_masuk_id), \'\') AS file_nama,
               COALESCE((SELECT s.link_drive FROM surat s WHERE s.id = d.surat_id),
                        (SELECT m.link_drive FROM surat_masuk m WHERE m.id = d.surat_masuk_id), \'\') AS link_drive';

function ambil(PDO $pdo, int $id): ?array
{
    return satu_baris($pdo, 'SELECT ' . KOLOM . ' FROM disposisi d WHERE d.id = ?', [$id]);
}

/** Buat baris disposisi baru dengan nomor otomatis. Melempar RuntimeException bila gagal. Mengembalikan [id, no_disposisi]. */
function sisip_disposisi(PDO $pdo, array $d): array
{
    $tahun = (int) substr($d['tanggal_terima'], 0, 4);
    $bulan = (int) substr($d['tanggal_terima'], 5, 2);
    for ($coba = 0; $coba < 5; $coba++) {
        $urut = urut_berikutnya($pdo, $tahun, $bulan);
        $no = format_no($urut, $bulan, $tahun);
        try {
            $pdo->prepare('INSERT INTO disposisi (tahun, bulan, urut, no_disposisi, tanggal_terima, sifat, hal, kode_jenis, unit_kode,
                    kode_tujuan, untuk, pemohon, tujuan_disposisi, isi, surat_id, surat_masuk_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$tahun, $bulan, $urut, $no, $d['tanggal_terima'], $d['sifat'], $d['hal'], $d['kode_jenis'], $d['unit_kode'],
                    $d['kode_tujuan'], $d['untuk'], $d['pemohon'], $d['tujuan_disposisi'], $d['isi'], $d['surat_id'], $d['surat_masuk_id']]);
            $id = (int) $pdo->lastInsertId();
            if ($d['surat_masuk_id']) {
                $pdo->prepare('UPDATE surat_masuk SET status = \'didisposisikan\' WHERE id = ? AND status = \'diterima\'')->execute([$d['surat_masuk_id']]);
            }
            return [$id, $no];
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') throw $e;
            if (strpos($e->getMessage(), 'uq_dsp_surat') !== false) throw new RuntimeException('Surat ini sudah punya disposisi.');
            // selain itu: nomor disposisi bentrok -> coba lagi dengan nomor berikutnya
        }
    }
    throw new RuntimeException('Gagal mendapatkan nomor disposisi, silakan coba lagi.');
}

/**
 * Unggah file surat. Surat yang belum punya disposisi otomatis dibuatkan disposisi (belum di-ACC) dengan isian awal dari surat;
 * "Disalurkan kepada", sifat, dan isi disposisi dilengkapi kemudian lewat tombol Ubah.
 */
function unggah_surat(PDO $pdo): void
{
    $sid = (int) ($_POST['surat_id'] ?? 0);
    $mid = (int) ($_POST['surat_masuk_id'] ?? 0);
    if ($sid) {
        $s = satu_baris($pdo, 'SELECT * FROM surat WHERE id = ?', [$sid]);
        if (!$s) keluar(['error' => 'Surat yang dipilih tidak ditemukan.'], 404);
        $lama = satu_baris($pdo, 'SELECT id, status FROM disposisi WHERE surat_id = ?', [$sid]);
        $d = ['surat_id' => $sid, 'surat_masuk_id' => null, 'kode_jenis' => $s['jenis_kode'], 'unit_kode' => $s['unit_kode'],
              'kode_tujuan' => $s['kode_tujuan'], 'untuk' => $s['untuk'], 'hal' => $s['perihal'], 'pemohon' => $s['pembuat_surat'], 'sifat' => 'biasa'];
        $tabel = 'surat'; $kid = $sid;
    } elseif ($mid) {
        $s = satu_baris($pdo, 'SELECT * FROM surat_masuk WHERE id = ?', [$mid]);
        if (!$s) keluar(['error' => 'Surat masuk yang dipilih tidak ditemukan.'], 404);
        $lama = satu_baris($pdo, 'SELECT id, status FROM disposisi WHERE surat_masuk_id = ?', [$mid]);
        $d = ['surat_id' => null, 'surat_masuk_id' => $mid, 'kode_jenis' => '', 'unit_kode' => '',
              'kode_tujuan' => $s['jenis_pengirim'] === 'internal' ? 'A' : 'B', 'untuk' => $s['tujuan'], 'hal' => $s['perihal'],
              'pemohon' => $s['pengirim'], 'sifat' => in_array($s['sifat'], SIFAT_BOLEH, true) ? $s['sifat'] : 'biasa'];
        $tabel = 'surat_masuk'; $kid = $mid;
    } else {
        keluar(['error' => 'Pilih surat yang akan diunggah filenya.'], 422);
    }
    if ($lama && $lama['status'] === 'disetujui') keluar(['error' => 'Disposisi surat ini sudah di-ACC. Batalkan ACC terlebih dahulu bila ingin mengganti file.'], 409);

    $link = link_drive_bersih($_POST['link'] ?? '');
    if ($link === null) keluar(['error' => 'Link harus berupa tautan Google Drive (https://drive.google.com/...).'], 422);
    $berkas = $link === '' ? simpan_unggahan() : null;
    if (!$berkas && $link === '') keluar(['error' => 'Tempel link Google Drive surat.'], 422);

    try {
        $no = null;
        if ($lama) {
            $id = (int) $lama['id'];
        } else {
            $d += ['tanggal_terima' => date('Y-m-d'), 'tujuan_disposisi' => '', 'isi' => ''];
            [$id, $no] = sisip_disposisi($pdo, $d);
        }
        $pdo->prepare("UPDATE $tabel SET file_arsip = ?, file_nama = ?, link_drive = ? WHERE id = ?")->execute([$berkas[0] ?? '', $berkas[1] ?? '', $link, $kid]);
    } catch (RuntimeException $e) {
        if ($berkas) hapus_file($berkas[0]);
        keluar(['error' => $e->getMessage()], 409);
    } catch (Throwable $e) {
        if ($berkas) hapus_file($berkas[0]);
        throw $e;
    }
    hapus_file($s['file_arsip']);          // buang file lama bila diganti
    keluar(['ok' => true, 'id' => $id, 'no_disposisi' => $no ?? (string) ambil($pdo, $id)['no_disposisi'], 'baru' => !$lama], $lama ? 200 : 201);
}

/**
 * Surat internal (kode A) yang di-ACC otomatis tercatat juga sebagai Surat Masuk bagi penerimanya.
 * Surat eksternal (B) hanya tercatat sebagai Surat Keluar. Mengembalikan nomor agenda, atau null bila tidak ada yang dicatat.
 */
function catat_surat_masuk_otomatis(PDO $pdo, array $dsp): ?string
{
    if (!$dsp['surat_id']) return null;
    $s = satu_baris($pdo, 'SELECT * FROM surat WHERE id = ?', [$dsp['surat_id']]);
    if (!$s || $s['kode_tujuan'] !== 'A') return null;
    if (satu_baris($pdo, 'SELECT id FROM surat_masuk WHERE dari_surat_id = ?', [$s['id']])) return null;   // sudah tercatat

    $unit = satu_baris($pdo, 'SELECT nama FROM unit WHERE kode = ?', [$s['unit_kode']]);
    $pengirim = teks($unit['nama'] ?? $s['unit_kode'], 150);
    $sifat = explode(',', (string) $dsp['sifat'])[0];
    if (!in_array($sifat, SIFAT_BOLEH, true)) $sifat = 'biasa';

    // File surat disalin (bukan dibagi) supaya menghapus salah satunya tidak merusak yang lain.
    $file = ''; $nama = '';
    if ($s['file_arsip'] !== '' && nama_aman($s['file_arsip']) && is_file(FOLDER_ARSIP . '/' . $s['file_arsip'])) {
        $salinan = bin2hex(random_bytes(16)) . '.' . pathinfo($s['file_arsip'], PATHINFO_EXTENSION);
        if (@copy(FOLDER_ARSIP . '/' . $s['file_arsip'], FOLDER_ARSIP . '/' . $salinan)) { $file = $salinan; $nama = $s['file_nama']; }
    }

    $hariIni = date('Y-m-d');
    $tahun = (int) date('Y'); $bulan = (int) date('n');
    for ($coba = 0; $coba < 5; $coba++) {
        $q = $pdo->prepare('SELECT COALESCE(MAX(urut), 0) FROM surat_masuk WHERE tahun = ? AND bulan = ?');
        $q->execute([$tahun, $bulan]);
        $urut = (int) $q->fetchColumn() + 1;
        $agenda = sprintf('%03d/AGD/%s/%d', $urut, bulan_romawi($bulan), $tahun);
        try {
            $pdo->prepare('INSERT INTO surat_masuk (tahun, bulan, urut, nomor_agenda, nomor_surat_asal, tanggal_surat, tanggal_terima, jenis_pengirim,
                    pengirim, perihal, sifat, tujuan, status, file_arsip, file_nama, dari_surat_id, link_drive)
                    VALUES (?, ?, ?, ?, ?, ?, ?, \'internal\', ?, ?, ?, ?, \'didisposisikan\', ?, ?, ?, ?)')
                ->execute([$tahun, $bulan, $urut, $agenda, $s['nomor_surat'], $s['tanggal_surat'], $hariIni,
                    $pengirim, $s['perihal'], $sifat, $dsp['tujuan_disposisi'], $file, $nama, $s['id'], (string) $s['link_drive']]);
            return $agenda;
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') { hapus_file($file); throw $e; }
            if (strpos($e->getMessage(), 'uq_sm_dari') !== false) { hapus_file($file); return null; }
            // nomor agenda bentrok -> coba lagi
        }
    }
    hapus_file($file);
    return null;
}

/**
 * Menarik kembali Surat Masuk otomatis saat ACC dibatalkan atau disposisi dihapus.
 * null = tidak ada Surat Masuk otomatis; true = dihapus; false = dipertahankan karena sudah diproses/diarsipkan.
 */
function tarik_surat_masuk_otomatis(PDO $pdo, int $suratId): ?bool
{
    if (!$suratId) return null;
    $m = satu_baris($pdo, 'SELECT id, status, status_arsip, file_arsip FROM surat_masuk WHERE dari_surat_id = ?', [$suratId]);
    if (!$m) return null;
    if ($m['status'] === 'ditindaklanjuti' || $m['status_arsip'] === 'diarsipkan') return false;
    if (satu_baris($pdo, 'SELECT id FROM disposisi WHERE surat_masuk_id = ?', [$m['id']])) return false;
    $pdo->prepare('DELETE FROM surat_masuk WHERE id = ?')->execute([$m['id']]);
    hapus_file($m['file_arsip']);
    return true;
}

/** Hapus surat bernomor (k) atau surat masuk (m) beserta filenya. */
function hapus_induk(PDO $pdo, string $t, int $id): void
{
    if (!$id) return;
    $tabel = $t === 'k' ? 'surat' : 'surat_masuk';
    $r = satu_baris($pdo, "SELECT file_arsip FROM $tabel WHERE id = ?", [$id]);
    if (!$r) return;
    if ($t === 'k') tarik_surat_masuk_otomatis($pdo, $id);
    $pdo->prepare("DELETE FROM $tabel WHERE id = ?")->execute([$id]);
    hapus_file((string) $r['file_arsip']);
}

try {
    $pdo = db();
    $metode = $_SERVER['REQUEST_METHOD'];

    if ($metode === 'GET') {
        switch ($_GET['aksi'] ?? 'daftar') {
            case 'daftar':
                $where = []; $par = [];
                $st = $_GET['status'] ?? '';
                if (in_array($st, ['belum', 'disetujui'], true)) { $where[] = 'd.status = ?'; $par[] = $st; }
                if (($q = teks($_GET['q'] ?? '', 80)) !== '') {
                    $where[] = '(d.no_disposisi LIKE ? OR d.hal LIKE ? OR d.pemohon LIKE ? OR d.tujuan_disposisi LIKE ? OR d.untuk LIKE ?
                                 OR d.surat_id IN (SELECT id FROM surat WHERE nomor_surat LIKE ?))';
                    array_push($par, ...array_fill(0, 6, "%$q%"));
                }
                if ((int) ($_GET['tahun'] ?? 0) > 0) { $where[] = 'd.tahun = ?'; $par[] = (int) $_GET['tahun']; }
                if ((int) ($_GET['bulan'] ?? 0) > 0) { $where[] = 'd.bulan = ?'; $par[] = (int) $_GET['bulan']; }
                $sql = 'SELECT ' . KOLOM . ' FROM disposisi d' . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
                     . ' ORDER BY d.tanggal_terima DESC, d.id DESC LIMIT 500';
                $s = $pdo->prepare($sql);
                $s->execute($par);
                $baris = $s->fetchAll();
                $r = $pdo->query('SELECT COUNT(*) AS total, COALESCE(SUM(status = \'disetujui\'), 0) AS disetujui FROM disposisi')->fetch();
                $tahun = array_map('intval', $pdo->query('SELECT DISTINCT tahun FROM disposisi ORDER BY tahun DESC')->fetchAll(PDO::FETCH_COLUMN));
                keluar(['disposisi' => $baris, 'total' => (int) $r['total'], 'disetujui' => (int) $r['disetujui'], 'daftar_tahun' => $tahun]);

            case 'file':
                $d = ambil($pdo, (int) ($_GET['id'] ?? 0));
                $r = !$d ? null : ($d['surat_id']
                    ? satu_baris($pdo, 'SELECT file_arsip, nomor_surat AS nama FROM surat WHERE id = ?', [$d['surat_id']])
                    : satu_baris($pdo, 'SELECT file_arsip, nomor_agenda AS nama FROM surat_masuk WHERE id = ?', [$d['surat_masuk_id']]));
                $path = $r ? FOLDER_ARSIP . '/' . $r['file_arsip'] : '';
                if (!$r || !nama_aman($r['file_arsip']) || !is_file($path)) { http_response_code(404); exit('File tidak ditemukan.'); }
                $ext = pathinfo($r['file_arsip'], PATHINFO_EXTENSION);
                $mime = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png'][$ext];
                $unduh = ($_GET['unduh'] ?? '') === '1' ? 'attachment' : 'inline';
                $nama = preg_replace('/[^A-Za-z0-9._-]+/', '_', str_replace('/', '-', $r['nama'])) . '.' . $ext;
                header('Content-Type: ' . $mime);
                header('Content-Length: ' . filesize($path));
                header('X-Content-Type-Options: nosniff');
                header("Content-Disposition: $unduh; filename=\"$nama\"");
                readfile($path);
                exit;

            case 'detail':
                $d = ambil($pdo, (int) ($_GET['id'] ?? 0));
                if (!$d) keluar(['error' => 'Disposisi tidak ditemukan.'], 404);
                $s = $pdo->prepare('SELECT tanggal, kepada, isi_nomor, dari, paraf FROM disposisi_alur WHERE disposisi_id = ? ORDER BY urutan');
                $s->execute([$d['id']]);
                $d['alur'] = $s->fetchAll();
                $d['jenis_nama'] = (string) (satu_baris($pdo, 'SELECT nama FROM jenis_surat WHERE kode = ?', [$d['kode_jenis']])['nama'] ?? '');
                $d['unit_nama'] = (string) (satu_baris($pdo, 'SELECT nama FROM unit WHERE kode = ?', [$d['unit_kode']])['nama'] ?? '');
                keluar(['disposisi' => $d]);

            case 'sumber':
                $surat = $pdo->query('SELECT s.id, s.nomor_surat, s.tanggal_surat, s.perihal, s.untuk, s.jenis_kode, s.unit_kode, s.kode_tujuan, s.pembuat_surat
                                      FROM surat s WHERE s.jalur = \'disposisi\' AND NOT EXISTS (SELECT 1 FROM disposisi d WHERE d.surat_id = s.id)
                                      ORDER BY s.tanggal_surat DESC, s.id DESC LIMIT 300')->fetchAll();
                $masuk = $pdo->query('SELECT m.id, m.nomor_agenda, m.tanggal_terima, m.perihal, m.pengirim, m.tujuan, m.sifat
                                      FROM surat_masuk m WHERE m.dari_surat_id IS NULL AND NOT EXISTS (SELECT 1 FROM disposisi d WHERE d.surat_masuk_id = m.id)
                                      ORDER BY m.tanggal_terima DESC, m.id DESC LIMIT 300')->fetchAll();
                keluar(['surat' => $surat, 'surat_masuk' => $masuk]);

            default:
                keluar(['error' => 'Aksi tidak dikenal.'], 400);
        }
    }

    if ($metode !== 'POST') keluar(['error' => 'Metode tidak didukung.'], 405);

    // Unggah file surat memakai multipart/form-data; aksi lainnya memakai JSON.
    if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data') === 0) {
        if (!$_POST && !$_FILES) keluar(['error' => 'Ukuran file melebihi batas server (post_max_size di php.ini).'], 413);
        if (($_POST['aksi'] ?? '') !== 'unggah') keluar(['error' => 'Aksi tidak dikenal.'], 400);
        unggah_surat($pdo);
    }

    $in = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($in)) keluar(['error' => 'Data JSON tidak valid.'], 400);

    switch ($in['aksi'] ?? '') {
        case 'tambah':
            [$d, $alur, $err] = baca_form($pdo, $in, true);
            if ($err) keluar(['error' => $err], 422);
            $tahun = (int) substr($d['tanggal_terima'], 0, 4);
            $bulan = (int) substr($d['tanggal_terima'], 5, 2);
            for ($coba = 0; $coba < 5; $coba++) {
                $urut = urut_berikutnya($pdo, $tahun, $bulan);
                $no = format_no($urut, $bulan, $tahun);
                $pdo->beginTransaction();
                try {
                    $pdo->prepare('INSERT INTO disposisi (tahun, bulan, urut, no_disposisi, tanggal_terima, sifat, hal, kode_jenis, unit_kode,
                            kode_tujuan, untuk, pemohon, tujuan_disposisi, isi, surat_id, surat_masuk_id)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                        ->execute([$tahun, $bulan, $urut, $no, $d['tanggal_terima'], $d['sifat'], $d['hal'], $d['kode_jenis'], $d['unit_kode'],
                            $d['kode_tujuan'], $d['untuk'], $d['pemohon'], $d['tujuan_disposisi'], $d['isi'], $d['surat_id'], $d['surat_masuk_id']]);
                    $id = (int) $pdo->lastInsertId();
                    simpan_alur($pdo, $id, $alur);
                    // Surat masuk yang didisposisikan otomatis naik ke tahap "didisposisikan".
                    if ($d['surat_masuk_id']) {
                        $pdo->prepare('UPDATE surat_masuk SET status = \'didisposisikan\' WHERE id = ? AND status = \'diterima\'')->execute([$d['surat_masuk_id']]);
                    }
                    $pdo->commit();
                    keluar(['ok' => true, 'id' => $id, 'no_disposisi' => $no], 201);
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    if ($e->getCode() !== '23000') throw $e;
                    if (strpos($e->getMessage(), 'uq_dsp_surat') !== false) keluar(['error' => 'Surat ini sudah punya disposisi.'], 409);
                    // selain itu: nomor disposisi bentrok -> coba lagi dengan nomor berikutnya
                }
            }
            keluar(['error' => 'Gagal mendapatkan nomor disposisi, silakan coba lagi.'], 409);

        case 'ubah':
            $id = (int) ($in['id'] ?? 0);
            $lama = ambil($pdo, $id);
            if (!$lama) keluar(['error' => 'Disposisi tidak ditemukan.'], 404);
            if ($lama['status'] === 'disetujui') keluar(['error' => 'Disposisi yang sudah disetujui tidak bisa diubah. Batalkan persetujuan terlebih dahulu.'], 409);
            [$d, $alur, $err] = baca_form($pdo, $in, false);
            if ($err) keluar(['error' => $err], 422);
            $pdo->beginTransaction();
            try {
                $pdo->prepare('UPDATE disposisi SET tanggal_terima = ?, sifat = ?, hal = ?, pemohon = ?, tujuan_disposisi = ? WHERE id = ?')
                    ->execute([$d['tanggal_terima'], $d['sifat'], $d['hal'], $d['pemohon'], $d['tujuan_disposisi'], $id]);
                if (array_key_exists('alur', $in)) simpan_alur($pdo, $id, $alur);   // form tidak lagi mengirim alur: data lama dibiarkan
                $pdo->commit();
            } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
            keluar(['ok' => true, 'no_disposisi' => $lama['no_disposisi']]);

        case 'hapus':
            $id = (int) ($in['id'] ?? 0);
            $d = ambil($pdo, $id);
            if (!$d) keluar(['error' => 'Disposisi tidak ditemukan.'], 404);
            $tarik = tarik_surat_masuk_otomatis($pdo, (int) $d['surat_id']);
            $pdo->prepare('DELETE FROM disposisi_alur WHERE disposisi_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM disposisi WHERE id = ?')->execute([$id]);
            hapus_induk($pdo, $d['surat_id'] ? 'k' : 'm', (int) ($d['surat_id'] ?: $d['surat_masuk_id']));   // satu kotak: surat ikut terhapus
            keluar(['ok' => true, 'surat_masuk_dihapus' => $tarik]);

        case 'hapus_surat':   // kotak "belum diunggah": surat bernomor / surat masuk tanpa disposisi
            $t = ($in['t'] ?? '') === 'm' ? 'm' : 'k'; $sid = (int) ($in['id'] ?? 0);
            $kolom = $t === 'k' ? 'surat_id' : 'surat_masuk_id';
            if (satu_baris($pdo, "SELECT id FROM disposisi WHERE $kolom = ?", [$sid])) keluar(['error' => 'Surat ini sudah punya disposisi. Hapus lewat kotak disposisinya.'], 409);
            hapus_induk($pdo, $t, $sid);
            keluar(['ok' => true]);

        case 'setujui':
            $id = (int) ($in['id'] ?? 0);
            $d = ambil($pdo, $id);
            if (!$d) keluar(['error' => 'Disposisi tidak ditemukan.'], 404);
            if ($d['status'] !== 'disetujui' && $d['tujuan_disposisi'] === '') {
                keluar(['error' => 'Isi dulu "Disalurkan kepada" lewat tombol Ubah sebelum meng-ACC disposisi.'], 422);
            }
            $oleh = teks($in['oleh'] ?? '', 100) ?: 'Admin';
            $pdo->prepare('UPDATE disposisi SET status = \'disetujui\', tanggal_setuju = ?, disetujui_oleh = ? WHERE id = ? AND status = \'belum\'')
                ->execute([date('Y-m-d'), $oleh, $id]);
            // Surat yang di-ACC: tercatat sebagai Surat Keluar; bila internal, otomatis jadi Surat Masuk bagi penerimanya.
            keluar(['ok' => true, 'surat_masuk' => catat_surat_masuk_otomatis($pdo, $d)]);

        case 'batal_setuju':
            $id = (int) ($in['id'] ?? 0);
            $d = ambil($pdo, $id);
            if (!$d) keluar(['error' => 'Disposisi tidak ditemukan.'], 404);
            $pdo->prepare('UPDATE disposisi SET status = \'belum\', tanggal_setuju = NULL, disetujui_oleh = \'\' WHERE id = ?')->execute([$id]);
            keluar(['ok' => true, 'surat_masuk_dihapus' => tarik_surat_masuk_otomatis($pdo, (int) $d['surat_id'])]);

        default:
            keluar(['error' => 'Aksi tidak dikenal.'], 400);
    }
} catch (Throwable $e) {
    $pesan = $e instanceof PDOException && in_array($e->getCode(), [2002, 1045])
        ? 'Tidak dapat terhubung ke MySQL. Pastikan MySQL di Laragon sudah Start.'
        : 'Kesalahan server: ' . $e->getMessage();
    keluar(['error' => $pesan], 500);
}
