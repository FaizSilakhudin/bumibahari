-- =============================================================================
-- Migration: 2FA (TOTP) — opsional, untuk akun role pusat dulu.
-- Jalankan sekali:  mysql -u root db_bumi_bahari < database/migrations/2026_09_01_000002_2fa_pusat.sql
-- Aman diulang (IF NOT EXISTS).
-- =============================================================================

ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `totp_secret`        VARCHAR(32) DEFAULT NULL AFTER `password_changed_at`,
    ADD COLUMN IF NOT EXISTS `totp_enabled`        TINYINT(1)  NOT NULL DEFAULT 0 AFTER `totp_secret`,
    ADD COLUMN IF NOT EXISTS `totp_backup_codes`   TEXT        DEFAULT NULL AFTER `totp_enabled`;
