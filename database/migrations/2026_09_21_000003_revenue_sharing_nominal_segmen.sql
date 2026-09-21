-- =============================================================================
-- Migration: revenue_sharing — simpan admin_fee & nominal_service_fee (bukan
-- cuma persen_service_fee + status_pembayaran seperti sekarang), plus kolom
-- urutan_pengelola utk kasus 1 bulan dikelola 2 pengelola berbeda.
--
-- Sebelum migrasi ini, admin_fee & nominal_service_fee SELALU dihitung LIVE
-- (3% & 50/50 hardcoded) di admin_pusat/revenue_sharing.php, tidak pernah
-- disimpan. Sesudahnya, nilai itu dipersist lewat tombol "Simpan Revenue
-- Sharing" di menu Rekapitulasi — revenue_sharing.php jadi read-only utk
-- kedua kolom baru ini (hanya status_pembayaran yang masih bisa diedit di
-- situ).
--
-- urutan_pengelola jadi bagian unique key (BUKAN kolom nullable) supaya kasus
-- normal (1 pengelola, urutan_pengelola=1) tidak pernah kebobolan duplikat —
-- unique index MySQL/MariaDB memperlakukan banyak NULL sebagai berbeda,
-- makanya kolom ini NOT NULL DEFAULT 1 (baris lama otomatis jadi "segmen 1").
--
-- Jalankan sekali:  mysql -u root db_bumi_bahari < database/migrations/2026_09_21_000003_revenue_sharing_nominal_segmen.sql
-- Aman diulang (IF NOT EXISTS / IF EXISTS).
-- =============================================================================

ALTER TABLE `revenue_sharing`
    ADD COLUMN IF NOT EXISTS `urutan_pengelola`    TINYINT(2)     NOT NULL DEFAULT 1 AFTER `bulan`,
    ADD COLUMN IF NOT EXISTS `admin_fee`            DECIMAL(14,2) DEFAULT NULL AFTER `urutan_pengelola`,
    ADD COLUMN IF NOT EXISTS `nominal_service_fee`  DECIMAL(14,2) DEFAULT NULL AFTER `persen_service_fee`;

-- Urutan penting: tambah unique key BARU dulu, baru hapus yang LAMA — supaya
-- selalu ada index yang menopang FK `id_cabang` di setiap titik waktu
-- (MariaDB menolak DROP INDEX kalau itu satu-satunya index penopang FK).
ALTER TABLE `revenue_sharing` ADD UNIQUE KEY IF NOT EXISTS `uk_rs_cabang_periode_segmen` (`id_cabang`,`tahun`,`bulan`,`urutan_pengelola`);
ALTER TABLE `revenue_sharing` DROP INDEX IF EXISTS `uk_rs_cabang_periode`;
