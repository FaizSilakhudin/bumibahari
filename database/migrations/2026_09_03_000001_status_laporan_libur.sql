-- =============================================================================
-- Migration: Tambah status 'libur' pada laporan_cabang.status_laporan.
-- Dipakai saat admin cabang menandai warung libur/tutup di suatu tanggal —
-- tidak ada nota/laporan keuangan untuk hari itu, tapi tetap tercatat (bukan
-- "belum lapor").
-- Jalankan sekali:  mysql -u root db_bumi_bahari < database/migrations/2026_09_03_000001_status_laporan_libur.sql
-- Aman diulang (MODIFY COLUMN ke definisi yang sama tidak menimbulkan error).
-- =============================================================================

ALTER TABLE `laporan_cabang`
    MODIFY COLUMN `status_laporan` ENUM('menunggu','lengkap','libur') NOT NULL DEFAULT 'lengkap';
