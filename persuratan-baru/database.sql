-- Database Persuratan TSU (MySQL / MariaDB - Laragon)
-- Opsional: aplikasi membuat semua ini otomatis. Impor file ini hanya jika ingin menyiapkan database lewat HeidiSQL/phpMyAdmin.

CREATE DATABASE IF NOT EXISTS `persuratan` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `persuratan`;

CREATE TABLE IF NOT EXISTS jenis_surat (
    kode VARCHAR(10)  NOT NULL PRIMARY KEY,
    nama VARCHAR(80)  NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS unit (
    kode VARCHAR(20)  NOT NULL PRIMARY KEY,
    nama VARCHAR(100) NOT NULL,
    urutan INT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS surat (
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
    untuk            VARCHAR(255) NOT NULL DEFAULT '',
    jenis_ttd        VARCHAR(10)  NOT NULL DEFAULT 'basah',
    pejabat_ttd      VARCHAR(100) NOT NULL DEFAULT '',
    pembuat_surat    VARCHAR(100) NOT NULL DEFAULT '',
    pemberi_nomor    VARCHAR(100) NOT NULL DEFAULT '',
    status_arsip     VARCHAR(12)  NOT NULL DEFAULT 'belum',
    tanggal_arsip    DATE NULL,
    lokasi_arsip     VARCHAR(150) NOT NULL DEFAULT '',
    keterangan_arsip VARCHAR(255) NOT NULL DEFAULT '',
    file_arsip       VARCHAR(80)  NOT NULL DEFAULT '',
    file_nama        VARCHAR(200) NOT NULL DEFAULT '',
    tahap            VARCHAR(16)  NOT NULL DEFAULT 'bernomor',
    tanggal_kirim    DATE NULL,
    keterangan_kirim VARCHAR(255) NOT NULL DEFAULT '',
    dibuat_pada      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_nomor (tahun, bulan, nomor_urut),
    KEY idx_tanggal (tanggal_surat),
    KEY idx_arsip (status_arsip)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
    dari_surat_id    INT NULL,
    dibuat_pada      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_agenda (tahun, bulan, urut),
    UNIQUE KEY uq_sm_dari (dari_surat_id),
    KEY idx_sm_tanggal (tanggal_terima),
    KEY idx_sm_status (status),
    KEY idx_sm_arsip (status_arsip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Database lama: aplikasi menyesuaikan sendiri (kolom baru pada tabel surat, disposisi, dan surat_masuk) saat halaman pertama dibuka.

INSERT IGNORE INTO jenis_surat (kode, nama) VALUES
  ('SK', 'Surat keputusan'),
  ('ST', 'Surat tugas'),
  ('SU', 'Surat undangan'),
  ('SPm', 'Surat permohonan'),
  ('SPn', 'Surat pernyataan'),
  ('SE', 'Surat edaran'),
  ('SKet', 'Surat keterangan'),
  ('SPb', 'Surat pemberitahuan'),
  ('SB', 'Surat balasan'),
  ('SR', 'Surat rekomendasi'),
  ('SPPD', 'Surat perintah perjalanan dinas'),
  ('SPeng', 'Surat pengantar'),
  ('SPK', 'Surat Perjanjian kerja'),
  ('SPh', 'Surat Penghargaan'),
  ('PR', 'Peraturan rektor'),
  ('M', 'Memo internal'),
  ('K', 'Surat Kuasa'),
  ('THib', 'Surat penerimaan hibah'),
  ('SHib', 'Surat penyerahan hibah'),
  ('Spin', 'Surat peminjaman'),
  ('Shap', 'Surat penghapusan aset'),
  ('MOU', 'Nota kesepahaman'),
  ('MOA', 'Surat perjanjian'),
  ('STK', 'Surat ucapan terimakasih'),
  ('STF', 'Sertifikat'),
  ('BA', 'Berita Acara'),
  ('ASET', 'Form Serah Terima Aset'),
  ('IA', 'Implementation Arrangement');

INSERT IGNORE INTO unit (kode, nama, urutan) VALUES
  ('REK', 'Rektor', 1),
  ('SEK', 'Sekretaris universitas', 2),
  ('WR1', 'Wakil rektor 1', 3),
  ('WR2', 'Wakil rektor 2', 4),
  ('WR3', 'Wakil rektor 3', 5),
  ('D-TEK', 'Dekan Fak. Teknik', 6),
  ('D-SH', 'Dekan Fak. Sains dan humaniora', 7),
  ('D-SV', 'Dekan Fak. Sekolah Vokasi', 8),
  ('PS-SI.S1', 'Sistem informasi-S1', 9),
  ('PS-I', 'Informatika', 10),
  ('PS-RK', 'Rekayasa Komputer', 11),
  ('PS-M.S1', 'Manajemen', 12),
  ('PS-PGSD.S1', 'PGSD', 13),
  ('PS-Psi.S1', 'Psikologi', 14),
  ('PS-SI.D3', 'Sistem Informasi-D3', 15),
  ('PS-TI', 'Teknologi Informasi', 16),
  ('PS-DKV', 'Desain Komunikasi Visual', 17),
  ('PS-DPT', 'Desain Produk Tekstil', 18),
  ('PS-SIA', 'Sistem Informasi Akuntansi', 19),
  ('LPPM', 'LPPM', 20),
  ('LPM', 'LPM', 21),
  ('BAUK', 'BAUK', 22),
  ('BAAK', 'BAAK', 23),
  ('BAKPPU', 'BAKPU', 24),
  ('SEN', 'Senat universitas', 25),
  ('SPI', 'SPI', 26),
  ('Perp', 'UPT Perpustakaan', 27),
  ('Lab', 'UPT Laboratorium', 28),
  ('Marcom', 'UPT Marcom', 29),
  ('PMB', 'PMB', 30),
  ('KUI', 'UPT KUI', 31),
  ('UNIV', 'UNIVERSITAS', 32),
  ('PPsT', 'PUSAT PSIKOLOGI TERAPAN', 33),
  ('KUK', 'KANTOR URUSAN KEMAHASISWAAN', 34),
  ('CCDC', 'CHARACTER AND CAREER DEVELOPMENT CENTER', 35);
