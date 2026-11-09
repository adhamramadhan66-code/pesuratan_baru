<?php
/**
 * API Data Surat.
 *   GET  api/surat.php?aksi=opsi                      -> daftar jenis surat & unit
 *   GET  api/surat.php?aksi=preview&tanggal=Y-m-d     -> nomor urut berikutnya pada bulan itu
 *   GET  api/surat.php?aksi=daftar&q=&tahun=&bulan=&limit=&acc=1   (acc=1: hanya surat yang disposisinya sudah di-ACC)
 *   GET  api/surat.php?aksi=statistik&dari=Y-m-d&sampai=Y-m-d
 *   POST api/surat.php {aksi: tambah | ubah | hapus, ...}
 */
require __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

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

function nomor_berikutnya(PDO $pdo, int $tahun, int $bulan): int
{
    $s = $pdo->prepare('SELECT COALESCE(MAX(nomor_urut), 0) FROM surat WHERE tahun = ? AND bulan = ?');
    $s->execute([$tahun, $bulan]);
    return (int) $s->fetchColumn() + 1;
}

function format_nomor(int $urut, string $tujuan, string $jenis, string $unit, int $bulan, int $tahun): string
{
    return sprintf('%03d.%s/%s/%s/%s/%d', $urut, $tujuan, $jenis, $unit, bulan_romawi($bulan), $tahun);
}

/** Validasi isian form; mengembalikan [data, pesan_error]. */
function baca_form(PDO $pdo, array $in, bool $baru): array
{
    $d = ['perihal' => teks($in['perihal'] ?? '')];
    if ($d['perihal'] === '') return [null, 'Perihal wajib diisi.'];

    // Kolom opsional: hanya diproses bila dikirim oleh form (form Penomoran tidak lagi mengirimnya).
    // Bila tidak dikirim, INSERT memakai default database dan UPDATE membiarkan nilai lama.
    if (array_key_exists('untuk', $in)) {
        $d['untuk'] = teks($in['untuk']);
        if ($d['untuk'] === '') return [null, 'Kolom "Untuk" wajib diisi.'];
    }
    if (array_key_exists('jenis_ttd', $in))   $d['jenis_ttd'] = $in['jenis_ttd'] === 'scan' ? 'scan' : 'basah';
    if (array_key_exists('pejabat_ttd', $in)) {
        $d['pejabat_ttd'] = teks($in['pejabat_ttd'], 100);
        if ($d['pejabat_ttd'] === '') return [null, 'Pejabat penandatangan wajib diisi.'];
    }
    if (array_key_exists('pembuat_surat', $in)) {
        $d['pembuat_surat'] = teks($in['pembuat_surat'], 100);
        if ($d['pembuat_surat'] === '') return [null, 'Pembuat surat wajib diisi.'];
    }
    if (array_key_exists('pemberi_nomor', $in)) $d['pemberi_nomor'] = teks($in['pemberi_nomor'], 100);

    if ($baru) {
        $d['tanggal_surat'] = tanggal_valid($in['tanggal_surat'] ?? '');
        if (!$d['tanggal_surat']) return [null, 'Tanggal surat tidak valid.'];
        $d['jalur'] = ($in['jalur'] ?? '') === 'keluar' ? 'keluar' : 'disposisi';
        $d['kode_tujuan'] = ($in['kode_tujuan'] ?? '') === 'B' ? 'B' : (($in['kode_tujuan'] ?? '') === 'A' ? 'A' : '');
        if ($d['kode_tujuan'] === '') return [null, 'Pilih tujuan surat (internal / eksternal).'];

        $d['jenis_kode'] = (string) ($in['jenis_kode'] ?? '');
        $d['unit_kode']  = (string) ($in['unit_kode'] ?? '');
        $c = $pdo->prepare('SELECT COUNT(*) FROM jenis_surat WHERE kode = ?');
        $c->execute([$d['jenis_kode']]);
        if (!$c->fetchColumn()) return [null, 'Jenis surat tidak dikenal.'];
        $c = $pdo->prepare('SELECT COUNT(*) FROM unit WHERE kode = ?');
        $c->execute([$d['unit_kode']]);
        if (!$c->fetchColumn()) return [null, 'Unit pembuat surat tidak dikenal.'];
    }
    return [$d, null];
}

const TAHAP_BOLEH = ['bernomor', 'diperiksa', 'ditandatangani', 'terkirim'];

const KOLOM_LIST = 'id, nomor_surat, tahap, jalur, acc_keluar, tanggal_kirim, keterangan_kirim, tanggal_surat, perihal, untuk, jenis_ttd, pejabat_ttd, pembuat_surat, pemberi_nomor,
                    kode_tujuan, jenis_kode, unit_kode, tahun, bulan, nomor_urut, status_arsip, file_nama, link_drive, (file_arsip <> \'\' OR link_drive <> \'\') AS ada_file,
                    (SELECT d.id FROM disposisi d WHERE d.surat_id = surat.id LIMIT 1) AS disposisi_id,
                    (SELECT d.no_disposisi FROM disposisi d WHERE d.surat_id = surat.id LIMIT 1) AS no_disposisi,
                    (SELECT d.status FROM disposisi d WHERE d.surat_id = surat.id LIMIT 1) AS status_disposisi,
                    (SELECT d.tujuan_disposisi FROM disposisi d WHERE d.surat_id = surat.id LIMIT 1) AS tujuan_disposisi';

try {
    $pdo = db();
    $metode = $_SERVER['REQUEST_METHOD'];

    if ($metode === 'GET') {
        switch ($_GET['aksi'] ?? 'daftar') {
            case 'opsi':
                $tahun = array_map('intval', $pdo->query('SELECT DISTINCT tahun FROM surat ORDER BY tahun DESC')->fetchAll(PDO::FETCH_COLUMN));
                if (!in_array((int) date('Y'), $tahun, true)) array_unshift($tahun, (int) date('Y'));
                keluar([
                    'jenis' => $pdo->query('SELECT kode, nama FROM jenis_surat ORDER BY nama')->fetchAll(),
                    'unit'  => $pdo->query('SELECT kode, nama FROM unit ORDER BY urutan')->fetchAll(),
                    'tahun' => $tahun,
                ]);

            case 'preview':
                $tgl = tanggal_valid($_GET['tanggal'] ?? '') ?? date('Y-m-d');
                [$th, $bl] = [(int) substr($tgl, 0, 4), (int) substr($tgl, 5, 2)];
                keluar(['nomor_urut' => nomor_berikutnya($pdo, $th, $bl), 'bulan' => $bl, 'tahun' => $th,
                        'romawi' => bulan_romawi($bl)]);

            case 'daftar':
                $where = []; $par = [];
                if (($q = teks($_GET['q'] ?? '', 80)) !== '') {
                    $where[] = '(nomor_surat LIKE ? OR perihal LIKE ? OR untuk LIKE ? OR pejabat_ttd LIKE ? OR pembuat_surat LIKE ?)';
                    array_push($par, ...array_fill(0, 5, "%$q%"));
                }
                if (in_array($_GET['tahap'] ?? '', TAHAP_BOLEH, true)) { $where[] = 'tahap = ?'; $par[] = $_GET['tahap']; }
                if (in_array($_GET['arsip'] ?? '', ['belum', 'diarsipkan'], true)) { $where[] = 'status_arsip = ?'; $par[] = $_GET['arsip']; }
                if ((int) ($_GET['tahun'] ?? 0) > 0) { $where[] = 'tahun = ?'; $par[] = (int) $_GET['tahun']; }
                if ((int) ($_GET['bulan'] ?? 0) > 0) { $where[] = 'bulan = ?'; $par[] = (int) $_GET['bulan']; }
                // acc=1: hanya surat yang disposisinya sudah di-ACC (dipakai halaman Surat Keluar)
                $sudahAcc = 'EXISTS (SELECT 1 FROM disposisi dx WHERE dx.surat_id = surat.id AND dx.status = \'disetujui\')';
                $hanyaAcc = ($_GET['acc'] ?? '') === '1';
                if ($hanyaAcc) $where[] = 'jalur = \'keluar\'';
                $limit = max(1, min(500, (int) ($_GET['limit'] ?? 100)));
                $sql = 'SELECT ' . KOLOM_LIST . ' FROM surat' . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
                     . ' ORDER BY tanggal_surat DESC, id DESC LIMIT ' . $limit;
                $s = $pdo->prepare($sql);
                $s->execute($par);
                $r = $pdo->query('SELECT COUNT(*) AS total, COALESCE(SUM(acc_keluar = 1), 0) AS acc, COALESCE(SUM(status_arsip = \'diarsipkan\'), 0) AS diarsipkan FROM surat'
                                 . ($hanyaAcc ? ' WHERE jalur = \'keluar\'' : ''))->fetch();
                $thn = array_map('intval', $pdo->query('SELECT DISTINCT tahun FROM surat ORDER BY tahun DESC')->fetchAll(PDO::FETCH_COLUMN));
                keluar(['surat' => $s->fetchAll(), 'ringkas' => array_map('intval', $r), 'daftar_tahun' => $thn]);

            case 'terbaru':
                // Surat terbaru pada rentang kalender, dengan filter lengkap (dipakai kotak "Surat terbaru" di dashboard).
                $dari   = tanggal_valid($_GET['dari'] ?? '')   ?? date('Y-m-01');
                $sampai = tanggal_valid($_GET['sampai'] ?? '') ?? date('Y-m-t');
                $w = ['tanggal_surat BETWEEN ? AND ?']; $p = [$dari, $sampai];
                if (($q = teks($_GET['q'] ?? '', 80)) !== '') {
                    $w[] = '(nomor_surat LIKE ? OR perihal LIKE ? OR untuk LIKE ? OR pembuat_surat LIKE ?)';
                    array_push($p, "%$q%", "%$q%", "%$q%", "%$q%");
                }
                if (($v = (string) ($_GET['jenis'] ?? '')) !== '') { $w[] = 'jenis_kode = ?'; $p[] = $v; }
                if (($v = (string) ($_GET['unit'] ?? '')) !== '') { $w[] = 'unit_kode = ?'; $p[] = $v; }
                if (in_array($_GET['tujuan'] ?? '', ['A', 'B'], true)) { $w[] = 'kode_tujuan = ?'; $p[] = $_GET['tujuan']; }
                if (in_array($_GET['jalur'] ?? '', ['disposisi', 'keluar'], true)) { $w[] = 'jalur = ?'; $p[] = $_GET['jalur']; }
                $w[] = 'status_arsip = \'diarsipkan\'';   // dashboard hanya menampilkan surat yang sudah diarsipkan
                if (in_array($_GET['tahap'] ?? '', TAHAP_BOLEH, true)) { $w[] = 'tahap = ?'; $p[] = $_GET['tahap']; }
                $urut = ($_GET['urut'] ?? '') === 'lama' ? 'ASC' : 'DESC';
                $batas = max(1, min(50, (int) ($_GET['limit'] ?? 12)));
                $s = $pdo->prepare('SELECT ' . KOLOM_LIST . ' FROM surat WHERE ' . implode(' AND ', $w) . " ORDER BY tanggal_surat $urut, id $urut LIMIT $batas");
                $s->execute($p);
                $c = $pdo->prepare('SELECT COUNT(*) FROM surat WHERE ' . implode(' AND ', $w));
                $c->execute($p);
                keluar(['surat' => $s->fetchAll(), 'total' => (int) $c->fetchColumn()]);

            case 'statistik':
                $dari   = tanggal_valid($_GET['dari'] ?? '')   ?? date('Y-m-01');
                $sampai = tanggal_valid($_GET['sampai'] ?? '') ?? date('Y-m-t');
                $s = $pdo->prepare('SELECT COUNT(*) AS total,
                        COALESCE(SUM(kode_tujuan = \'A\'), 0) AS internal,
                        COALESCE(SUM(kode_tujuan = \'B\'), 0) AS eksternal,
                        COALESCE(SUM(status_arsip = \'diarsipkan\'), 0) AS diarsipkan
                    FROM surat WHERE status_arsip = \'diarsipkan\' AND tanggal_surat BETWEEN ? AND ?');
                $s->execute([$dari, $sampai]);
                $tot = array_map('intval', $s->fetch());

                $tahun = (int) substr($dari, 0, 4);
                // Grafik bisa disaring per jenis surat (parameter jenis = kode jenis).
                $jenisGrafik = (string) ($_GET['jenis'] ?? '');
                $s = $pdo->prepare('SELECT bulan, COUNT(*) AS n FROM surat WHERE status_arsip = \'diarsipkan\' AND tahun = ?' . ($jenisGrafik !== '' ? ' AND jenis_kode = ?' : '') . ' GROUP BY bulan');
                $s->execute($jenisGrafik !== '' ? [$tahun, $jenisGrafik] : [$tahun]);
                $perBulan = array_fill(1, 12, 0);
                foreach ($s->fetchAll() as $r) $perBulan[(int) $r['bulan']] = (int) $r['n'];

                $s = $pdo->prepare('SELECT ' . KOLOM_LIST . ' FROM surat WHERE status_arsip = \'diarsipkan\' AND tanggal_surat BETWEEN ? AND ?
                                    ORDER BY tanggal_surat DESC, id DESC LIMIT 8');
                $s->execute([$dari, $sampai]);
                // Jumlah surat per jenis (seluruh waktu) untuk kotak Jenis surat di dashboard; yang terbanyak di depan.
                // Jumlah per jenis mengikuti rentang tanggal yang dipilih di kalender.
                $q = $pdo->prepare('SELECT j.kode, j.nama, COUNT(s.id) AS n FROM jenis_surat j
                                    LEFT JOIN surat s ON s.jenis_kode = j.kode AND s.status_arsip = \'diarsipkan\' AND s.tanggal_surat BETWEEN ? AND ?
                                    GROUP BY j.kode, j.nama ORDER BY n DESC, j.nama');
                $q->execute([$dari, $sampai]);
                $perJenis = $q->fetchAll();

                // Per hari pada bulan dari tanggal awal (untuk grafik saat kalender memilih hari/bulan).
                $awalBulan = substr($dari, 0, 7) . '-01';
                $akhirBulan = date('Y-m-t', strtotime($awalBulan));
                $q = $pdo->prepare('SELECT DAY(tanggal_surat) AS h, COUNT(*) AS n FROM surat WHERE status_arsip = \'diarsipkan\' AND tanggal_surat BETWEEN ? AND ?'
                                   . ($jenisGrafik !== '' ? ' AND jenis_kode = ?' : '') . ' GROUP BY h');
                $q->execute($jenisGrafik !== '' ? [$awalBulan, $akhirBulan, $jenisGrafik] : [$awalBulan, $akhirBulan]);
                $perHari = array_fill(1, (int) date('t', strtotime($awalBulan)), 0);
                foreach ($q->fetchAll() as $r) $perHari[(int) $r['h']] = (int) $r['n'];
                foreach ($perJenis as &$pj) $pj['n'] = (int) $pj['n'];
                unset($pj);
                keluar(['per_hari' => array_values($perHari), 'per_jenis' => $perJenis, 'total' => $tot, 'tahun' => $tahun, 'per_bulan' => array_values($perBulan), 'terbaru' => $s->fetchAll()]);

            default:
                keluar(['error' => 'Aksi tidak dikenal.'], 400);
        }
    }

    if ($metode !== 'POST') keluar(['error' => 'Metode tidak didukung.'], 405);
    $in = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($in)) keluar(['error' => 'Data JSON tidak valid.'], 400);

    switch ($in['aksi'] ?? '') {
        case 'tambah':
            [$d, $err] = baca_form($pdo, $in, true);
            if ($err) keluar(['error' => $err], 422);
            $tahun = (int) substr($d['tanggal_surat'], 0, 4);
            $bulan = (int) substr($d['tanggal_surat'], 5, 2);

            // Ambil nomor urut berikutnya; bila bentrok (dua orang menyimpan bersamaan) coba lagi.
            for ($coba = 0; $coba < 5; $coba++) {
                $urut = nomor_berikutnya($pdo, $tahun, $bulan);
                $nomor = format_nomor($urut, $d['kode_tujuan'], $d['jenis_kode'], $d['unit_kode'], $bulan, $tahun);
                try {
                    $pdo->prepare('INSERT INTO surat (tahun, bulan, nomor_urut, kode_tujuan, jenis_kode, unit_kode, nomor_surat,
                            tanggal_surat, perihal, untuk, jenis_ttd, pejabat_ttd, pembuat_surat, pemberi_nomor, jalur)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                        ->execute([$tahun, $bulan, $urut, $d['kode_tujuan'], $d['jenis_kode'], $d['unit_kode'], $nomor,
                            $d['tanggal_surat'], $d['perihal'], $d['untuk'] ?? '', $d['jenis_ttd'] ?? 'basah', $d['pejabat_ttd'] ?? '',
                            $d['pembuat_surat'] ?? '', $d['pemberi_nomor'] ?? '', $d['jalur']]);
                    keluar(['ok' => true, 'id' => (int) $pdo->lastInsertId(), 'nomor_surat' => $nomor], 201);
                } catch (PDOException $e) {
                    if ($e->getCode() !== '23000') throw $e;   // selain duplikat nomor -> lempar
                }
            }
            keluar(['error' => 'Gagal mendapatkan nomor surat, silakan coba lagi.'], 409);

        case 'ubah':
            $id = (int) ($in['id'] ?? 0);
            [$d, $err] = baca_form($pdo, $in, true);
            if ($err) keluar(['error' => $err], 422);
            $c = $pdo->prepare('SELECT tahun, bulan, nomor_urut FROM surat WHERE id = ?');
            $c->execute([$id]);
            $lama = $c->fetch();
            if (!$lama) keluar(['error' => 'Surat tidak ditemukan.'], 404);
            $tahun = (int) substr($d['tanggal_surat'], 0, 4);
            $bulan = (int) substr($d['tanggal_surat'], 5, 2);
            // Bulan & tahun sama -> nomor urut dipertahankan. Pindah bulan -> ambil nomor urut berikutnya di bulan baru.
            $sama = ((int) $lama['tahun'] === $tahun && (int) $lama['bulan'] === $bulan);

            for ($coba = 0; $coba < 5; $coba++) {
                $urut = $sama ? (int) $lama['nomor_urut'] : nomor_berikutnya($pdo, $tahun, $bulan);
                $nomor = format_nomor($urut, $d['kode_tujuan'], $d['jenis_kode'], $d['unit_kode'], $bulan, $tahun);
                try {
                    $set = ['tahun = ?', 'bulan = ?', 'nomor_urut = ?', 'kode_tujuan = ?', 'jenis_kode = ?', 'unit_kode = ?', 'nomor_surat = ?',
                            'tanggal_surat = ?', 'perihal = ?'];
                    $par = [$tahun, $bulan, $urut, $d['kode_tujuan'], $d['jenis_kode'], $d['unit_kode'], $nomor, $d['tanggal_surat'], $d['perihal']];
                    foreach (['untuk', 'jenis_ttd', 'pejabat_ttd', 'pembuat_surat', 'pemberi_nomor'] as $kol) {
                        if (array_key_exists($kol, $d)) { $set[] = "$kol = ?"; $par[] = $d[$kol]; }
                    }
                    if (!$pdo->query('SELECT 1 FROM disposisi WHERE surat_id = ' . $id)->fetch()) { $set[] = 'jalur = ?'; $par[] = $d['jalur']; }
                    $par[] = $id;
                    $pdo->prepare('UPDATE surat SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($par);
                    keluar(['ok' => true, 'nomor_surat' => $nomor]);
                } catch (PDOException $e) {
                    if ($e->getCode() !== '23000' || $sama) throw $e;
                }
            }
            keluar(['error' => 'Gagal mendapatkan nomor surat, silakan coba lagi.'], 409);

        case 'tahap':
            // Tahap proses surat keluar: bernomor -> diperiksa -> ditandatangani -> terkirim
            $id = (int) ($in['id'] ?? 0);
            $tahap = (string) ($in['tahap'] ?? '');
            if (!in_array($tahap, TAHAP_BOLEH, true)) keluar(['error' => 'Tahap surat tidak dikenal.'], 422);
            $c = $pdo->prepare('SELECT COUNT(*) FROM surat WHERE id = ?');
            $c->execute([$id]);
            if (!$c->fetchColumn()) keluar(['error' => 'Surat tidak ditemukan.'], 404);
            $kirim = $tahap === 'terkirim';
            $tgl = $kirim ? (tanggal_valid($in['tanggal_kirim'] ?? '') ?: date('Y-m-d')) : null;
            $ket = $kirim ? teks($in['keterangan_kirim'] ?? '', 255) : '';
            $pdo->prepare('UPDATE surat SET tahap = ?, tanggal_kirim = ?, keterangan_kirim = ? WHERE id = ?')->execute([$tahap, $tgl, $ket, $id]);
            keluar(['ok' => true]);

        case 'acc_keluar':
            $id = (int) ($in['id'] ?? 0);
            $c = $pdo->prepare('UPDATE surat SET acc_keluar = ? WHERE id = ?');
            $c->execute([empty($in['nilai']) ? 0 : 1, $id]);
            if (!$c->rowCount() && !$pdo->query('SELECT 1 FROM surat WHERE id = ' . $id)->fetch()) keluar(['error' => 'Surat tidak ditemukan.'], 404);
            keluar(['ok' => true]);

        case 'hapus':
            $id = (int) ($in['id'] ?? 0);
            $c = $pdo->prepare('SELECT file_arsip FROM surat WHERE id = ?');
            $c->execute([$id]);
            $file = $c->fetchColumn();
            if ($file === false) keluar(['error' => 'Surat tidak ditemukan.'], 404);
            $c = $pdo->prepare('SELECT no_disposisi FROM disposisi WHERE surat_id = ? LIMIT 1');
            $c->execute([$id]);
            if ($no = $c->fetchColumn()) keluar(['error' => 'Surat ini sudah punya disposisi ' . $no . '. Hapus disposisinya terlebih dahulu.'], 409);
            $pdo->prepare('DELETE FROM surat WHERE id = ?')->execute([$id]);
            if ($file !== '' && preg_match('/^[a-f0-9]{32}\.(pdf|jpg|png)$/', $file)) @unlink(FOLDER_ARSIP . '/' . $file);
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
