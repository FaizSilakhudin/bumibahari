-- =============================================================================
-- Migration: Tambah kolom kasbon (nominal, sumber, keterangan) di revenue_sharing.
--
-- Dipakai tombol "Simpan Kasbon" baru di admin_pusat/rekapitulasi.php bagian
-- "6. Koreksi Dividen: Sisi Investor" -- sebelumnya nilai Kasbon Pengelola
-- (nominal, sumber dana penalangan, keterangan) hanya dihitung di JS dan
-- selalu balik ke 0/default tiap halaman di-refresh, karena tidak pernah
-- disimpan ke database. Disimpan pada baris (id_cabang, tahun, bulan,
-- urutan_pengelola) yang sama dengan admin_fee/service_fee (unique key
-- uk_rs_cabang_periode_segmen sudah ada).
--
-- Jalankan sekali:  mysql -u root db_bumi_bahari < database/migrations/2026_10_01_000001_revenue_sharing_kasbon.sql
-- Aman diulang (ADD COLUMN IF NOT EXISTS -- MariaDB/MySQL 8+ mendukung sintaks ini).
-- =============================================================================

ALTER TABLE `revenue_sharing`
  ADD COLUMN IF NOT EXISTS `kasbon_nominal` DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER `nominal_service_fee`,
  ADD COLUMN IF NOT EXISTS `kasbon_sumber` ENUM('investor','pusat','warung') NOT NULL DEFAULT 'investor' AFTER `kasbon_nominal`,
  ADD COLUMN IF NOT EXISTS `kasbon_keterangan` VARCHAR(255) DEFAULT NULL AFTER `kasbon_sumber`;
