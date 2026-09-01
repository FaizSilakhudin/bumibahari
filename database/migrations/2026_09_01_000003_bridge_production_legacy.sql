-- =============================================================================
-- Migration: jembatani schema production LAMA (commit 6007d7d, sebelum tabel
-- `pengelola` terpisah dan sebelum penamaan kolom cabang disamakan) ke schema
-- yang dipakai kode saat ini.
--
-- HANYA untuk database yang masih di schema lama itu (mis. production).
-- Aman diulang (IF NOT EXISTS di semua tempat yang bisa). TIDAK PERNAH
-- menghapus kolom/data lama — kolom pengelola lama di `users` dibiarkan
-- dorman sebagai jaring pengaman, bisa dibersihkan belakangan.
--
-- Jalankan SEBELUM 4 migration lain di folder ini (2026_08_28_000001 dst),
-- karena migration itu mengasumsikan tabel `pengelola` dan kolom
-- `users.status`/`users.id_pengelola` SUDAH ADA.
--
--   mysql -u root db_bumi_bahari < database/migrations/2026_09_01_000003_bridge_production_legacy.sql
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. cabang: samakan nama kolom rekening dengan yang dipakai kode baru.
-- -----------------------------------------------------------------------------
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cabang' AND COLUMN_NAME = 'no_rekening'
);
SET @sql = IF(@col_exists > 0,
    'ALTER TABLE `cabang` CHANGE COLUMN `no_rekening` `no_rekening_cabang` VARCHAR(50) DEFAULT NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cabang' AND COLUMN_NAME = 'nama_bank'
);
SET @sql = IF(@col_exists > 0,
    'ALTER TABLE `cabang` CHANGE COLUMN `nama_bank` `nama_bank_cabang` VARCHAR(50) DEFAULT NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cabang' AND COLUMN_NAME = 'atas_nama_cabang'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `cabang` ADD COLUMN `atas_nama_cabang` VARCHAR(100) DEFAULT NULL AFTER `no_rekening_cabang`',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 2. users: kolom baru + perlebar enum role. Kolom pengelola lama DIBIARKAN.
-- -----------------------------------------------------------------------------
ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `id_pengelola` INT(11) DEFAULT NULL AFTER `id_cabang`,
    ADD COLUMN IF NOT EXISTS `status` ENUM('aktif','nonaktif') DEFAULT 'aktif' AFTER `id_pengelola`;

ALTER TABLE `users` MODIFY COLUMN `role` ENUM('pusat','pic','cabang','investor') NOT NULL DEFAULT 'cabang';

ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `id_investor` INT(11) DEFAULT NULL AFTER `id_pengelola`;
ALTER TABLE `users` ADD INDEX IF NOT EXISTS `idx_users_investor` (`id_investor`);

ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `last_login_at`       DATETIME    DEFAULT NULL AFTER `status`,
    ADD COLUMN IF NOT EXISTS `last_login_ip`       VARCHAR(45) DEFAULT NULL AFTER `last_login_at`,
    ADD COLUMN IF NOT EXISTS `password_changed_at` DATETIME    DEFAULT NULL AFTER `last_login_ip`,
    ADD COLUMN IF NOT EXISTS `totp_secret`         VARCHAR(32) DEFAULT NULL AFTER `password_changed_at`,
    ADD COLUMN IF NOT EXISTS `totp_enabled`        TINYINT(1)  NOT NULL DEFAULT 0 AFTER `totp_secret`,
    ADD COLUMN IF NOT EXISTS `totp_backup_codes`   TEXT        DEFAULT NULL AFTER `totp_enabled`;

-- -----------------------------------------------------------------------------
-- 3. laporan_cabang: kolom alur PIC.
-- -----------------------------------------------------------------------------
ALTER TABLE `laporan_cabang`
    ADD COLUMN IF NOT EXISTS `status_laporan` ENUM('menunggu','lengkap') NOT NULL DEFAULT 'lengkap' AFTER `foto_nota4`,
    ADD COLUMN IF NOT EXISTS `id_user_nota`    INT(11) DEFAULT NULL AFTER `status_laporan`,
    ADD COLUMN IF NOT EXISTS `id_user_laporan` INT(11) DEFAULT NULL AFTER `id_user_nota`,
    ADD COLUMN IF NOT EXISTS `keterangan_nota` TEXT DEFAULT NULL AFTER `keterangan`;

ALTER TABLE `laporan_cabang` ADD INDEX IF NOT EXISTS `idx_lc_status`   (`status_laporan`);
ALTER TABLE `laporan_cabang` ADD INDEX IF NOT EXISTS `idx_lc_tanggal` (`tanggal`);

-- -----------------------------------------------------------------------------
-- 4. Tabel pengelola (baru) + migrasi ISI dari kolom lama di users.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pengelola` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `id_user` INT(11) DEFAULT NULL,
    `id_cabang` INT(11) DEFAULT NULL,
    `nama_pengelola` VARCHAR(100) NOT NULL,
    `tgl_mulai` DATE NOT NULL DEFAULT '2024-01-01',
    `tgl_selesai` DATE DEFAULT NULL,
    `status` ENUM('aktif','nonaktif') NOT NULL DEFAULT 'aktif',
    `nama_bank_pengelola` VARCHAR(50) DEFAULT NULL,
    `no_rekening_pengelola` VARCHAR(50) DEFAULT NULL,
    `atas_nama_pengelola` VARCHAR(100) DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `fk_pengelola_users` (`id_user`),
    KEY `fk_pengelola_cabang` (`id_cabang`),
    KEY `idx_pengelola_cb_st` (`id_cabang`, `status`),
    CONSTRAINT `fk_pengelola_cabang` FOREIGN KEY (`id_cabang`) REFERENCES `cabang` (`id_cabang`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Pindahkan data pengelola yang selama ini nempel di users (role='cabang') ke
-- tabel baru. Idempotent: skip user yang sudah pernah dipindah (JOIN check).
INSERT INTO `pengelola` (id_user, id_cabang, nama_pengelola, tgl_mulai, tgl_selesai, status,
                          nama_bank_pengelola, no_rekening_pengelola, atas_nama_pengelola)
SELECT u.id, u.id_cabang, u.nama_pengelola,
       COALESCE(u.tgl_mulai, '2024-01-01'),
       u.tgl_selesai,
       CASE WHEN u.tgl_selesai IS NULL OR u.tgl_selesai >= CURDATE() THEN 'aktif' ELSE 'nonaktif' END,
       u.nama_bank, u.no_rekening, u.atas_nama_rekening
FROM `users` u
LEFT JOIN `pengelola` p ON p.id_user = u.id
WHERE u.role = 'cabang'
  AND u.id_cabang IS NOT NULL
  AND u.nama_pengelola IS NOT NULL AND u.nama_pengelola <> ''
  AND p.id IS NULL;

-- -----------------------------------------------------------------------------
-- 5. audit_log + laporan_cabang_arsip — struktur identik dengan migration
--    yang sudah ada, tidak ada masalah prasyarat, aman dibuat di sini juga.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `audit_log` (
    `id`          BIGINT(20)   NOT NULL AUTO_INCREMENT,
    `waktu`       DATETIME     NOT NULL DEFAULT current_timestamp(),
    `user_id`     INT(11)      DEFAULT NULL,
    `username`    VARCHAR(50)  DEFAULT NULL,
    `role`        VARCHAR(20)  DEFAULT NULL,
    `aksi`        VARCHAR(60)  NOT NULL,
    `tabel`       VARCHAR(40)  DEFAULT NULL,
    `record_id`   VARCHAR(60)  DEFAULT NULL,
    `detail`      TEXT         DEFAULT NULL,
    `ip`          VARCHAR(45)  DEFAULT NULL,
    `user_agent`  VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_audit_waktu` (`waktu`),
    KEY `idx_audit_user`  (`user_id`),
    KEY `idx_audit_tabel` (`tabel`, `record_id`),
    KEY `idx_audit_aksi`  (`aksi`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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

-- -----------------------------------------------------------------------------
-- 6. cabang_investor: tabel & isinya sudah ada di production, tambah index saja.
-- -----------------------------------------------------------------------------
ALTER TABLE `cabang_investor` ADD INDEX IF NOT EXISTS `idx_ci_cabang_selesai` (`id_cabang`, `tgl_selesai`);
