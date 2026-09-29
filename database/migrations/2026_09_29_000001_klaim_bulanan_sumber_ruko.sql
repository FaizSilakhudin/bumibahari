-- =============================================================================
-- Migration: Tambah 'ruko' ke enum klaim_bulanan.sumber_dana.
--
-- Baris klaim bulanan ber-sumber_dana='ruko' ("Dana Ruko") otomatis memotong
-- Total Bersih Investor di "Koreksi Dividen: Sisi Investor" (rekapitulasi.php)
-- -- kebalikan dari 'investor' yang justru MENAMBAH (Pengembalian Dana
-- Talangan). Semua baris (apapun sumber dananya) tetap memotong Net Profit
-- sebelum admin fee 3%, seperti sumber_dana lain.
--
-- Jalankan sekali:  mysql -u root db_bumi_bahari < database/migrations/2026_09_29_000001_klaim_bulanan_sumber_ruko.sql
-- Aman diulang (MODIFY COLUMN idempoten -- enum yang sudah ada 'ruko' tidak error).
-- =============================================================================

ALTER TABLE `klaim_bulanan`
  MODIFY COLUMN `sumber_dana` ENUM('investor','warung','pusat','ruko') NOT NULL DEFAULT 'warung';
