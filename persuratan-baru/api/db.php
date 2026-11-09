<?php
/**
 * Koneksi MySQL (Laragon) + pembuatan database/tabel otomatis.
 *
 * Bawaan Laragon: host 127.0.0.1, port 3306, user root, tanpa kata sandi.
 * Database "persuratan" dan semua tabel dibuat otomatis saat halaman pertama dibuka.
 * Jika Anda mengimpor database.sql lewat phpMyAdmin/HeidiSQL, hasilnya sama saja.
 */
const MYSQL_HOST = '127.0.0.1';
const MYSQL_PORT = 3306;
const MYSQL_NAME = 'persuratan';
const MYSQL_USER = 'root';
const MYSQL_PASS = '';

const FOLDER_ARSIP = __DIR__ . '/../data/arsip';

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    $dsn = 'mysql:host=' . MYSQL_HOST . ';port=' . MYSQL_PORT . ';charset=utf8mb4';
    $pdo = new PDO($dsn, MYSQL_USER, MYSQL_PASS, $opts);
    $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . MYSQL_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo->exec('USE `' . MYSQL_NAME . '`');

    buat_tabel($pdo);
    isi_data_master($pdo);
    if (!is_dir(FOLDER_ARSIP)) mkdir(FOLDER_ARSIP, 0775, true);
    return $pdo;
}

function buat_tabel(PDO $pdo): void
{
    // Database sisa versi e-Office (login per peran) tidak cocok dengan versi ini: tabelnya dicadangkan
    // dengan akhiran _eoffice (data tidak dihapus), lalu tabel versi ini dibuat baru.
    try { $eoffice = (bool) $pdo->query("SHOW COLUMNS FROM `surat_masuk` LIKE 'dibuat_oleh'")->fetch(); } catch (PDOException $e) { $eoffice = false; }
    if ($eoffice) {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['disposisi', 'surat_masuk', 'surat_keluar', 'pengguna'] as $t) {
            $baru = $t . '_eoffice';
            try { $pdo->exec("DROP TABLE IF EXISTS `$baru`"); $pdo->exec("RENAME TABLE `$t` TO `$baru`"); } catch (PDOException $e) { /* tabel tidak ada */ }
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    $pdo->exec('CREATE TABLE IF NOT EXISTS jenis_surat (
        kode VARCHAR(10)  NOT NULL PRIMARY KEY,
        nama VARCHAR(80)  NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $pdo->exec('CREATE TABLE IF NOT EXISTS unit (
        kode VARCHAR(20)  NOT NULL PRIMARY KEY,
        nama VARCHAR(100) NOT NULL,
        urutan INT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    // Satu baris = satu surat keluar (sama seperti sheet "Contoh" di Panduan_Persuratan.xlsx).
    // Nomor urut dimulai dari 001 setiap awal bulan -> UNIQUE (tahun, bulan, nomor_urut).
    $pdo->exec('CREATE TABLE IF NOT EXISTS surat (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        tahun            SMALLINT     NOT NULL,
        bulan            TINYINT      NOT NULL,
        nomor_urut       INT          NOT NULL,
        kode_tujuan      CHAR(1)      NOT NULL,
        jenis_kode       VARCHAR(10)  NOT NULL,
        unit_kode        VARCHAR(20)  NOT NULL,
        nomor_surat      VARCHAR(60)  NOT NULL,
        tanggal_surat    DATE         NOT NULL,
        perihal          VARCHAR(255) NOT NULL,
        untuk            VARCHAR(255) NOT NULL DEFAULT \'\',
        jenis_ttd        VARCHAR(10)  NOT NULL DEFAULT \'basah\',
        pejabat_ttd      VARCHAR(100) NOT NULL DEFAULT \'\',
        pembuat_surat    VARCHAR(100) NOT NULL DEFAULT \'\',
        pemberi_nomor    VARCHAR(100) NOT NULL DEFAULT \'\',
        status_arsip     VARCHAR(12)  NOT NULL DEFAULT \'belum\',
        tanggal_arsip    DATE NULL,
        lokasi_arsip     VARCHAR(150) NOT NULL DEFAULT \'\',
        keterangan_arsip VARCHAR(255) NOT NULL DEFAULT \'\',
        file_arsip       VARCHAR(80)  NOT NULL DEFAULT \'\',
        file_nama        VARCHAR(200) NOT NULL DEFAULT \'\',
        link_drive       VARCHAR(500) NOT NULL DEFAULT \'\',
        tahap            VARCHAR(16)  NOT NULL DEFAULT \'bernomor\',
        jalur            VARCHAR(10)  NOT NULL DEFAULT \'disposisi\',
        tanggal_kirim    DATE NULL,
        keterangan_kirim VARCHAR(255) NOT NULL DEFAULT \'\',
        dibuat_pada      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_nomor (tahun, bulan, nomor_urut),
        KEY idx_tanggal (tanggal_surat),
        KEY idx_arsip (status_arsip)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    // Lembar disposisi: permohonan surat yang harus disetujui sebelum surat dibuat di Tambah Data.
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS disposisi (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    surat_id       INT NULL,
    surat_masuk_id INT NULL,
    tahun          SMALLINT     NOT NULL,
    bulan          TINYINT      NOT NULL,
    urut           INT          NOT NULL,
    no_disposisi   VARCHAR(40)  NOT NULL,
    tanggal_terima DATE         NOT NULL,
    sifat          VARCHAR(60)  NOT NULL DEFAULT '',
    hal            VARCHAR(255) NOT NULL,
    kode_jenis     VARCHAR(10)  NOT NULL,
    unit_kode      VARCHAR(20)  NOT NULL,
    kode_tujuan    CHAR(1)      NOT NULL DEFAULT 'A',
    untuk          VARCHAR(255) NOT NULL DEFAULT '',
    pemohon        VARCHAR(100) NOT NULL,
    tujuan_disposisi VARCHAR(100) NOT NULL DEFAULT '',
    isi            VARCHAR(60)  NOT NULL DEFAULT '',
    status         VARCHAR(12)  NOT NULL DEFAULT 'belum',
    tanggal_setuju DATE NULL,
    disetujui_oleh VARCHAR(100) NOT NULL DEFAULT '',
    dibuat_pada    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_disposisi_no (tahun, bulan, urut),
    UNIQUE KEY uq_dsp_surat (surat_id),
    KEY idx_dsp_status (status),
    KEY idx_dsp_tanggal (tanggal_terima)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS disposisi_alur (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    disposisi_id INT          NOT NULL,
    urutan       INT          NOT NULL,
    tanggal      DATE NULL,
    kepada       VARCHAR(100) NOT NULL DEFAULT '',
    isi_nomor    VARCHAR(40)  NOT NULL DEFAULT '',
    dari         VARCHAR(100) NOT NULL DEFAULT '',
    paraf        VARCHAR(40)  NOT NULL DEFAULT '',
    KEY idx_alur_disposisi (disposisi_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);

    // Database lama (disposisi sebagai syarat sebelum surat dibuat): sesuaikan dengan alur baru
    // (surat dinomori dulu di Penomoran, lalu disalurkan lewat disposisi).
    if (!$pdo->query("SHOW COLUMNS FROM disposisi LIKE 'surat_id'")->fetch()) {
        $pdo->exec('ALTER TABLE disposisi ADD COLUMN surat_id INT NULL AFTER id, ADD COLUMN tujuan_disposisi VARCHAR(100) NOT NULL DEFAULT \'\' AFTER pemohon, ADD UNIQUE KEY uq_dsp_surat (surat_id)');
        // Disposisi lama yang sudah dipakai membuat surat tetap terhubung ke suratnya.
        if ($pdo->query("SHOW COLUMNS FROM surat LIKE 'disposisi_id'")->fetch()) {
            $pdo->exec('UPDATE disposisi d JOIN surat s ON s.disposisi_id = d.id SET d.surat_id = s.id WHERE d.surat_id IS NULL');
        }
    }

    // Surat masuk: surat yang diterima TSU (dari pihak luar maupun unit lain di dalam kampus).
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS surat_masuk (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    tahun            SMALLINT     NOT NULL,
    bulan            TINYINT      NOT NULL,
    urut             INT          NOT NULL,
    nomor_agenda     VARCHAR(40)  NOT NULL,
    nomor_surat_asal VARCHAR(100) NOT NULL DEFAULT '',
    tanggal_surat    DATE NULL,
    tanggal_terima   DATE         NOT NULL,
    jenis_pengirim   VARCHAR(10)  NOT NULL DEFAULT 'eksternal',
    pengirim         VARCHAR(150) NOT NULL,
    perihal          VARCHAR(255) NOT NULL,
    sifat            VARCHAR(12)  NOT NULL DEFAULT 'biasa',
    tujuan           VARCHAR(150) NOT NULL DEFAULT '',
    status           VARCHAR(16)  NOT NULL DEFAULT 'diterima',
    status_arsip     VARCHAR(12)  NOT NULL DEFAULT 'belum',
    tanggal_arsip    DATE NULL,
    lokasi_arsip     VARCHAR(150) NOT NULL DEFAULT '',
    keterangan_arsip VARCHAR(255) NOT NULL DEFAULT '',
    file_arsip       VARCHAR(80)  NOT NULL DEFAULT '',
    file_nama        VARCHAR(200) NOT NULL DEFAULT '',
    link_drive       VARCHAR(500) NOT NULL DEFAULT '',
    dari_surat_id    INT NULL,
    dibuat_pada      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_agenda (tahun, bulan, urut),
    UNIQUE KEY uq_sm_dari (dari_surat_id),
    KEY idx_sm_tanggal (tanggal_terima),
    KEY idx_sm_status (status),
    KEY idx_sm_arsip (status_arsip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);

    // Database lama: tambahkan kolom/ubah nilai yang berubah pada versi ini.
    if (!$pdo->query("SHOW COLUMNS FROM surat LIKE 'tahap'")->fetch()) {
        $pdo->exec('ALTER TABLE surat ADD COLUMN tahap VARCHAR(16) NOT NULL DEFAULT \'bernomor\', ADD COLUMN tanggal_kirim DATE NULL, ADD COLUMN keterangan_kirim VARCHAR(255) NOT NULL DEFAULT \'\'');
    }
    // Jalur surat dipilih di Penomoran: 'disposisi' (lewat Disposisi) atau 'keluar' (langsung ke Surat Keluar).
    if (!$pdo->query("SHOW COLUMNS FROM surat LIKE 'jalur'")->fetch()) {
        $pdo->exec('ALTER TABLE surat ADD COLUMN jalur VARCHAR(10) NOT NULL DEFAULT \'disposisi\' AFTER tahap');
    }
    // ACC khusus Surat Keluar (terpisah dari ACC Disposisi)
    if (!$pdo->query("SHOW COLUMNS FROM surat LIKE 'acc_keluar'")->fetch()) {
        $pdo->exec('ALTER TABLE surat ADD COLUMN acc_keluar TINYINT(1) NOT NULL DEFAULT 0 AFTER jalur');
    }
    // ACC khusus Surat Masuk (dilakukan di halaman Surat Masuk)
    if (!$pdo->query("SHOW COLUMNS FROM surat_masuk LIKE 'acc_masuk'")->fetch()) {
        $pdo->exec('ALTER TABLE surat_masuk ADD COLUMN acc_masuk TINYINT(1) NOT NULL DEFAULT 0');
    }
    // Surat disimpan sebagai link Google Drive (bukan unggah file ke server). File unggahan lama tetap bisa dibuka.
    foreach (['surat', 'surat_masuk'] as $t) {
        if (!$pdo->query("SHOW COLUMNS FROM $t LIKE 'link_drive'")->fetch()) {
            $pdo->exec("ALTER TABLE $t ADD COLUMN link_drive VARCHAR(500) NOT NULL DEFAULT '' AFTER file_nama");
        }
    }
    if (!$pdo->query("SHOW COLUMNS FROM disposisi LIKE 'surat_masuk_id'")->fetch()) {
        $pdo->exec('ALTER TABLE disposisi ADD COLUMN surat_masuk_id INT NULL AFTER surat_id');
    }
    // Surat Masuk otomatis: surat internal yang di-ACC di Disposisi tercatat juga sebagai Surat Masuk bagi penerimanya.
    if (!$pdo->query("SHOW COLUMNS FROM surat_masuk LIKE 'dari_surat_id'")->fetch()) {
        $pdo->exec('ALTER TABLE surat_masuk ADD COLUMN dari_surat_id INT NULL, ADD UNIQUE KEY uq_sm_dari (dari_surat_id)');
    }
    if (!$pdo->query("SHOW COLUMNS FROM surat_masuk LIKE 'jenis_pengirim'")->fetch()) {
        $pdo->exec('ALTER TABLE surat_masuk ADD COLUMN jenis_pengirim VARCHAR(10) NOT NULL DEFAULT \'eksternal\' AFTER tanggal_terima, MODIFY status VARCHAR(16) NOT NULL DEFAULT \'diterima\'');
        $pdo->exec('UPDATE surat_masuk SET status = \'didisposisikan\' WHERE status = \'diproses\'');
        $pdo->exec('UPDATE surat_masuk SET status = \'ditindaklanjuti\' WHERE status = \'selesai\'');
    }
}

/** Isi jenis surat & unit dari Panduan (hanya jika tabel masih kosong). */
function isi_data_master(PDO $pdo): void
{
    $master = require __DIR__ . '/master.php';

    if ((int) $pdo->query('SELECT COUNT(*) FROM jenis_surat')->fetchColumn() === 0) {
        $s = $pdo->prepare('INSERT INTO jenis_surat (kode, nama) VALUES (?, ?)');
        foreach ($master['jenis'] as [$kode, $nama]) $s->execute([$kode, $nama]);
    }
    if ((int) $pdo->query('SELECT COUNT(*) FROM unit')->fetchColumn() === 0) {
        $s = $pdo->prepare('INSERT INTO unit (kode, nama, urutan) VALUES (?, ?, ?)');
        foreach ($master['unit'] as $i => [$kode, $nama]) $s->execute([$kode, $nama, $i + 1]);
    }
}

/**
 * Rapikan link Google Drive/Docs. '' bila kosong, null bila bukan tautan drive.google.com / docs.google.com (https).
 */
function link_drive_bersih($v): ?string
{
    $v = trim((string) $v);
    if ($v === '') return '';
    if (strlen($v) > 500) return null;
    $p = parse_url($v);
    if (!$p || strtolower($p['scheme'] ?? '') !== 'https' || !in_array(strtolower($p['host'] ?? ''), ['drive.google.com', 'docs.google.com'], true)) return null;
    return $v;
}

/** Angka bulan -> romawi (1 => I ... 12 => XII), sesuai Panduan poin 5. */
function bulan_romawi(int $b): string
{
    return ['', 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'][$b] ?? '';
}
