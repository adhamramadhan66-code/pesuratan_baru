<?php
/**
 * API Pengarsipan surat.
 *   GET  api/arsip.php?aksi=daftar&status=&q=&tahun=&bulan=
 *   GET  api/arsip.php?aksi=file&id=           -> menampilkan file scan (PDF/JPG/PNG)
 *   POST api/arsip.php (multipart/form-data)
 *        aksi=simpan  : id, lokasi_arsip, keterangan_arsip, tanggal_arsip, file (opsional), hapus_file (1 = buang file lama)
 *        aksi=batal   : id  (kembalikan ke "belum diarsipkan"; file surat tetap disimpan)
 */
require __DIR__ . '/db.php';

const MAKS_UKURAN = 10 * 1024 * 1024;                       // 10 MB
const TIPE_FILE = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];

function json_keluar($data, int $kode = 200): void
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

function nama_aman(string $f): bool { return (bool) preg_match('/^[a-f0-9]{32}\.(pdf|jpg|png)$/', $f); }

function hapus_file(string $f): void
{
    if ($f !== '' && nama_aman($f)) @unlink(FOLDER_ARSIP . '/' . $f);
}

function ambil_surat(PDO $pdo, int $id): ?array
{
    $s = $pdo->prepare('SELECT * FROM surat WHERE id = ?');
    $s->execute([$id]);
    return $s->fetch() ?: null;
}

try {
    $pdo = db();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $aksi = $_GET['aksi'] ?? 'daftar';

        if ($aksi === 'file') {
            $r = ambil_surat($pdo, (int) ($_GET['id'] ?? 0));
            $path = $r ? FOLDER_ARSIP . '/' . $r['file_arsip'] : '';
            if (!$r || !nama_aman($r['file_arsip']) || !is_file($path)) { http_response_code(404); exit('File tidak ditemukan.'); }
            $ext = pathinfo($r['file_arsip'], PATHINFO_EXTENSION);
            $mime = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png'][$ext];
            $unduh = ($_GET['unduh'] ?? '') === '1' ? 'attachment' : 'inline';
            $nama = preg_replace('/[^A-Za-z0-9._-]+/', '_', str_replace('/', '-', $r['nomor_surat'])) . '.' . $ext;
            header('Content-Type: ' . $mime);
            header('Content-Length: ' . filesize($path));
            header('X-Content-Type-Options: nosniff');
            header("Content-Disposition: $unduh; filename=\"$nama\"");
            readfile($path);
            exit;
        }

        if ($aksi !== 'daftar') json_keluar(['error' => 'Aksi tidak dikenal.'], 400);

        // Pengarsipan hanya untuk surat keluar yang sudah diunggah (link Drive) dan sudah di-ACC
        $syarat = 's.jalur = \'keluar\' AND s.acc_keluar = 1 AND (s.file_arsip <> \'\' OR s.link_drive <> \'\')';
        $where = [$syarat]; $par = [];
        $status = $_GET['status'] ?? '';
        if (in_array($status, ['belum', 'diarsipkan'], true)) { $where[] = 's.status_arsip = ?'; $par[] = $status; }
        if (($q = teks($_GET['q'] ?? '', 80)) !== '') {
            $where[] = '(s.nomor_surat LIKE ? OR s.perihal LIKE ? OR s.untuk LIKE ? OR s.lokasi_arsip LIKE ? OR s.pembuat_surat LIKE ?)';
            array_push($par, ...array_fill(0, 5, "%$q%"));
        }
        if (($j = teks($_GET['jenis'] ?? '', 10)) !== '') { $where[] = 's.jenis_kode = ?'; $par[] = $j; }
        if (($pb = teks($_GET['pembuat'] ?? '', 100)) !== '') { $where[] = 's.pembuat_surat = ?'; $par[] = $pb; }
        if ((int) ($_GET['tahun'] ?? 0) > 0) { $where[] = 's.tahun = ?'; $par[] = (int) $_GET['tahun']; }
        if ((int) ($_GET['bulan'] ?? 0) > 0) { $where[] = 's.bulan = ?'; $par[] = (int) $_GET['bulan']; }

        $sql = 'SELECT s.id, s.nomor_surat, s.tanggal_surat, s.perihal, s.untuk, s.jenis_ttd, s.pejabat_ttd, s.pembuat_surat,
                       s.jenis_kode, COALESCE(j.nama, s.jenis_kode) AS jenis_nama, s.status_arsip, s.tanggal_arsip,
                       s.lokasi_arsip, s.keterangan_arsip, s.file_nama, s.link_drive, (s.file_arsip <> \'\' OR s.link_drive <> \'\') AS ada_file
                FROM surat s LEFT JOIN jenis_surat j ON j.kode = s.jenis_kode' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY s.tanggal_surat DESC, s.id DESC LIMIT 500';
        $s = $pdo->prepare($sql);
        $s->execute($par);
        $baris = $s->fetchAll();
        foreach ($baris as &$b) $b['ada_file'] = (bool) $b['ada_file'];

        $ringkas = $pdo->query('SELECT COUNT(*) AS total, COALESCE(SUM(status_arsip = \'diarsipkan\'), 0) AS diarsipkan FROM surat s WHERE ' . $syarat)->fetch();
        $tahun = $pdo->query('SELECT DISTINCT tahun FROM surat ORDER BY tahun DESC')->fetchAll(PDO::FETCH_COLUMN);
        $jenis = $pdo->query('SELECT DISTINCT s.jenis_kode AS kode, COALESCE(j.nama, s.jenis_kode) AS nama FROM surat s
                              LEFT JOIN jenis_surat j ON j.kode = s.jenis_kode ORDER BY nama')->fetchAll();
        $pembuat = $pdo->query('SELECT DISTINCT pembuat_surat FROM surat WHERE pembuat_surat <> \'\' ORDER BY pembuat_surat')->fetchAll(PDO::FETCH_COLUMN);
        json_keluar(['surat' => $baris, 'daftar_jenis' => $jenis, 'daftar_pembuat' => $pembuat, 'total' => (int) $ringkas['total'], 'diarsipkan' => (int) $ringkas['diarsipkan'],
                     'daftar_tahun' => array_map('intval', $tahun)]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_keluar(['error' => 'Metode tidak didukung.'], 405);

    // Jika file terlalu besar untuk php.ini, $_POST dan $_FILES kosong sama sekali.
    if (!$_POST && !$_FILES && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        json_keluar(['error' => 'Ukuran file melebihi batas server (post_max_size di php.ini).'], 413);
    }

    $id = (int) ($_POST['id'] ?? 0);
    $surat = ambil_surat($pdo, $id);
    if (!$surat) json_keluar(['error' => 'Surat tidak ditemukan.'], 404);

    switch ($_POST['aksi'] ?? '') {
        case 'simpan':
            $lokasi = teks($_POST['lokasi_arsip'] ?? '', 150);
            $ket = teks($_POST['keterangan_arsip'] ?? '', 255);
            $tgl = DateTime::createFromFormat('Y-m-d', (string) ($_POST['tanggal_arsip'] ?? ''));
            $tglArsip = ($tgl && $tgl->format('Y-m-d') === $_POST['tanggal_arsip']) ? $_POST['tanggal_arsip'] : date('Y-m-d');

            $fileBaru = $surat['file_arsip']; $namaBaru = $surat['file_nama']; $linkBaru = $surat['link_drive'];
            if (($_POST['hapus_file'] ?? '') === '1') { hapus_file($fileBaru); $fileBaru = ''; $namaBaru = ''; $linkBaru = ''; }
            if (($lb = link_drive_bersih($_POST['link'] ?? '')) === null) json_keluar(['error' => 'Link harus berupa tautan Google Drive (https://drive.google.com/...).'], 422);
            if ($lb !== '') { hapus_file($fileBaru); $fileBaru = ''; $namaBaru = ''; $linkBaru = $lb; }

            $up = $_FILES['file'] ?? null;
            if ($up && $up['error'] !== UPLOAD_ERR_NO_FILE) {
                if ($up['error'] === UPLOAD_ERR_INI_SIZE || $up['error'] === UPLOAD_ERR_FORM_SIZE) json_keluar(['error' => 'Ukuran file melebihi batas server (upload_max_filesize di php.ini).'], 413);
                if ($up['error'] !== UPLOAD_ERR_OK) json_keluar(['error' => 'Upload file gagal (kode ' . $up['error'] . ').'], 400);
                if ($up['size'] > MAKS_UKURAN) json_keluar(['error' => 'Ukuran file maksimal 10 MB.'], 422);

                $mime = function_exists('finfo_open') ? finfo_file(finfo_open(FILEINFO_MIME_TYPE), $up['tmp_name']) : '';
                if (!isset(TIPE_FILE[$mime])) json_keluar(['error' => 'Format file harus PDF, JPG, atau PNG.'], 422);

                $nama = bin2hex(random_bytes(16)) . '.' . TIPE_FILE[$mime];
                if (!move_uploaded_file($up['tmp_name'], FOLDER_ARSIP . '/' . $nama)) json_keluar(['error' => 'Gagal menyimpan file ke folder data/arsip.'], 500);
                hapus_file($fileBaru);                       // buang file lama bila diganti
                $fileBaru = $nama;
                $namaBaru = teks(basename((string) $up['name']), 200);
            }

            if ($lokasi === '' && $fileBaru === '' && $linkBaru === '') json_keluar(['error' => 'Isi lokasi arsip fisik atau unggah file scan (minimal salah satu).'], 422);

            $pdo->prepare('UPDATE surat SET status_arsip = \'diarsipkan\', tanggal_arsip = ?, lokasi_arsip = ?, keterangan_arsip = ?, file_arsip = ?, file_nama = ?, link_drive = ? WHERE id = ?')
                ->execute([$tglArsip, $lokasi, $ket, $fileBaru, $namaBaru, $linkBaru, $id]);
            json_keluar(['ok' => true]);

        case 'unggah': {
            // Hanya mengisi/mengganti link surat (dari kartu di Surat Keluar); status arsip tidak berubah.
            if (array_key_exists('link', $_POST)) {
                $lb = link_drive_bersih($_POST['link']);
                if ($lb === null || $lb === '') json_keluar(['error' => 'Tempel link Google Drive surat (https://drive.google.com/...).'], 422);
                hapus_file($surat['file_arsip']);
                $pdo->prepare('UPDATE surat SET link_drive = ?, file_arsip = \'\', file_nama = \'\' WHERE id = ?')->execute([$lb, $id]);
                json_keluar(['ok' => true]);
            }
            $up = $_FILES['file'] ?? null;
            if (!$up || $up['error'] === UPLOAD_ERR_NO_FILE) json_keluar(['error' => 'Pilih file surat (PDF/JPG/PNG).'], 422);
            if ($up['error'] === UPLOAD_ERR_INI_SIZE || $up['error'] === UPLOAD_ERR_FORM_SIZE) json_keluar(['error' => 'Ukuran file melebihi batas server (upload_max_filesize di php.ini).'], 413);
            if ($up['error'] !== UPLOAD_ERR_OK) json_keluar(['error' => 'Upload file gagal (kode ' . $up['error'] . ').'], 400);
            if ($up['size'] > MAKS_UKURAN) json_keluar(['error' => 'Ukuran file maksimal 10 MB.'], 422);
            $mime = function_exists('finfo_open') ? finfo_file(finfo_open(FILEINFO_MIME_TYPE), $up['tmp_name']) : '';
            if (!isset(TIPE_FILE[$mime])) json_keluar(['error' => 'Format file harus PDF, JPG, atau PNG.'], 422);
            $nama = bin2hex(random_bytes(16)) . '.' . TIPE_FILE[$mime];
            if (!move_uploaded_file($up['tmp_name'], FOLDER_ARSIP . '/' . $nama)) json_keluar(['error' => 'Gagal menyimpan file ke folder data/arsip.'], 500);
            hapus_file($surat['file_arsip']);
            $pdo->prepare('UPDATE surat SET file_arsip = ?, file_nama = ? WHERE id = ?')
                ->execute([$nama, teks(basename((string) $up['name']), 200), $id]);
            json_keluar(['ok' => true]);
        }

        case 'batal':
            // File surat tetap disimpan (juga dipakai di Disposisi); hanya status dan data arsip fisik yang direset.
            $pdo->prepare('UPDATE surat SET status_arsip = \'belum\', tanggal_arsip = NULL, lokasi_arsip = \'\', keterangan_arsip = \'\' WHERE id = ?')
                ->execute([$id]);
            json_keluar(['ok' => true]);

        default:
            json_keluar(['error' => 'Aksi tidak dikenal.'], 400);
    }
} catch (Throwable $e) {
    $pesan = $e instanceof PDOException && in_array($e->getCode(), [2002, 1045])
        ? 'Tidak dapat terhubung ke MySQL. Pastikan MySQL di Laragon sudah Start.'
        : 'Kesalahan server: ' . $e->getMessage();
    json_keluar(['error' => $pesan], 500);
}
