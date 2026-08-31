-- =============================================================================
-- Migration: alur PIC (Person In Charge) & login investor
-- Jalankan sekali:  mysql -u root db_bumi_bahari < database/migrations/2026_08_31_000001_pic_investor_login.sql
-- Aman diulang (IF NOT EXISTS). Tidak mengubah rumus/nilai laporan yang sudah ada.
--
-- Alur baru:
--   - Cabang    -> hanya kirim foto nota (3 foto) via admin_cabang/input_data.php
--   - PIC       -> memegang beberapa cabang (lihat tabel `pengelola`), mengisi
--                  laporan keuangan harian per cabang via admin_pic/
--   - Investor  -> login read-only, lihat ringkasan cabang yang diinvestasikan
-- =============================================================================

-- 1. Role baru pada tabel users -----------------------------------------------
ALTER TABLE `users`
    MODIFY COLUMN `role` ENUM('pusat','pic','cabang','investor') NOT NULL DEFAULT 'cabang';

ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `id_investor` INT(11) DEFAULT NULL AFTER `id_pengelola`;

ALTER TABLE `users` ADD INDEX IF NOT EXISTS `idx_users_investor` (`id_investor`);

-- 2. Status alur pengisian laporan --------------------------------------------
-- 'menunggu' = nota sudah/belum masuk, laporan keuangan belum diisi PIC.
-- 'lengkap'  = laporan keuangan sudah diisi PIC (baris lama dianggap lengkap).
ALTER TABLE `laporan_cabang`
    ADD COLUMN IF NOT EXISTS `status_laporan` ENUM('menunggu','lengkap') NOT NULL DEFAULT 'lengkap' AFTER `foto_nota4`,
    ADD COLUMN IF NOT EXISTS `id_user_nota`    INT(11) DEFAULT NULL AFTER `status_laporan`,
    ADD COLUMN IF NOT EXISTS `id_user_laporan` INT(11) DEFAULT NULL AFTER `id_user_nota`,
    ADD COLUMN IF NOT EXISTS `keterangan_nota` TEXT DEFAULT NULL AFTER `keterangan`;

ALTER TABLE `laporan_cabang` ADD INDEX IF NOT EXISTS `idx_lc_status` (`status_laporan`);
