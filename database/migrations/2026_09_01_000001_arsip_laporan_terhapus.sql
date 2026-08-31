-- =============================================================================
-- Migration: arsip snapshot laporan_cabang sebelum dihapus
-- Jalankan sekali:  mysql -u root db_bumi_bahari < database/migrations/2026_09_01_000001_arsip_laporan_terhapus.sql
-- Aman diulang (IF NOT EXISTS).
--
-- Sebelum ini, tombol "Hapus" di admin_pusat/laporan.php langsung DELETE
-- permanen tanpa jejak isi datanya (audit_log cuma mencatat "ada yang hapus").
-- Sekarang setiap penghapusan WAJIB menyimpan snapshot lengkap ke tabel ini
-- dulu sebelum baris aslinya dihapus — data lama tidak pernah benar-benar hilang,
-- dan bisa dipulihkan lewat admin_pusat/arsip_laporan.php.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `laporan_cabang_arsip` (
    `id`                   INT(11)      NOT NULL AUTO_INCREMENT,
    `id_laporan_asli`      INT(11)      NOT NULL,
    `id_cabang`            INT(11)      DEFAULT NULL,
    `nama_cabang`          VARCHAR(100) DEFAULT NULL,
    `tanggal`              DATE         DEFAULT NULL,
    `data_json`            LONGTEXT     NOT NULL,
    `dihapus_oleh_user_id` INT(11)      DEFAULT NULL,
    `dihapus_oleh_username` VARCHAR(50) DEFAULT NULL,
    `dihapus_pada`         DATETIME     NOT NULL DEFAULT current_timestamp(),
    `dipulihkan_pada`      DATETIME     DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_arsip_cabang_tgl` (`id_cabang`, `tanggal`),
    KEY `idx_arsip_laporan_asli` (`id_laporan_asli`),
    KEY `idx_arsip_dihapus_pada` (`dihapus_pada`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
