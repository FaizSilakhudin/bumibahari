-- =============================================================================
-- Migration: kolom sumber_dana pada klaim_bulanan — menggantikan dropdown
-- "Asal Dana Talangan" yang sebelumnya ada di Koreksi Dividen: Sisi Investor
-- (kini dipindah jadi properti PER-BARIS klaim, bukan 1 pilihan global).
--
-- 'investor' : nominal klaim ini dipotong dari Net Profit (sebelum admin fee 3%)
--              DAN otomatis masuk ke "Pengembalian Dana Talangan" (menambah
--              Total Bersih Investor).
-- 'warung'   : nominal klaim ini HANYA dipotong dari Net Profit, tidak ada
--              penggantian ke investor.
--
-- Default 'warung' (opsi paling "aman"/tanpa efek samping tambahan).
--
-- Jalankan sekali:  mysql -u root db_bumi_bahari < database/migrations/2026_09_22_000001_klaim_bulanan_sumber_dana.sql
-- Aman diulang (IF NOT EXISTS).
-- =============================================================================

ALTER TABLE `klaim_bulanan`
    ADD COLUMN IF NOT EXISTS `sumber_dana` ENUM('investor','warung') NOT NULL DEFAULT 'warung' AFTER `nominal`;
