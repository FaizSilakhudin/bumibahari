-- =============================================================================
-- Migration: tambah opsi 'pusat' pada klaim_bulanan.sumber_dana (sebelumnya
-- cuma 'investor'/'warung').
--
-- 'pusat' : nominal klaim ini tetap memotong Net Profit (sebelum admin fee
--           3%, sama seperti 'investor'/'warung'), TAPI otomatis masuk ke
--           "Admin Management Pusat" di "6. Rekapan Hasil Akhir Keuntungan
--           (Distribusi Payroll)" — bukan ke Pengembalian Dana Talangan
--           investor.
--
-- Jalankan sekali:  mysql -u root db_bumi_bahari < database/migrations/2026_09_22_000002_klaim_bulanan_sumber_dana_pusat.sql
-- Aman diulang (MODIFY COLUMN ke definisi yang sama tidak menimbulkan error).
-- =============================================================================

ALTER TABLE `klaim_bulanan`
    MODIFY COLUMN `sumber_dana` ENUM('investor','warung','pusat') NOT NULL DEFAULT 'warung';
