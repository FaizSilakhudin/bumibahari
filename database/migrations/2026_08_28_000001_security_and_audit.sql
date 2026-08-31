-- =============================================================================
-- Migration: keamanan & audit trail
-- Jalankan sekali:  mysql -u root db_bumi_bahari < database/migrations/2026_08_28_000001_security_and_audit.sql
-- Aman diulang (IF NOT EXISTS).
-- =============================================================================

-- 1. Tabel audit trail --------------------------------------------------------
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

-- 2. Kolom pelacakan login pada tabel users ---------------------------------
ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `last_login_at`       DATETIME    DEFAULT NULL AFTER `status`,
    ADD COLUMN IF NOT EXISTS `last_login_ip`       VARCHAR(45) DEFAULT NULL AFTER `last_login_at`,
    ADD COLUMN IF NOT EXISTS `password_changed_at` DATETIME    DEFAULT NULL AFTER `last_login_ip`;

-- 3. Index untuk performa query yang sering dipakai -------------------------
ALTER TABLE `laporan_cabang`  ADD INDEX IF NOT EXISTS `idx_lc_tanggal`        (`tanggal`);
ALTER TABLE `pengelola`       ADD INDEX IF NOT EXISTS `idx_pengelola_cb_st`   (`id_cabang`, `status`);
ALTER TABLE `cabang_investor` ADD INDEX IF NOT EXISTS `idx_ci_cabang_selesai` (`id_cabang`, `tgl_selesai`);
