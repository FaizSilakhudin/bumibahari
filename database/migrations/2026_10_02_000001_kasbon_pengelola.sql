-- =============================================================================
-- Migration: Tabel kasbon_pengelola -- menu baru "Kasbon Pengelola" (admin_pusat,
-- bagian LAPORAN, di bawah "Data Calon Pengelola").
--
-- Ini adalah BUKU CATATAN KASBON yang berdiri sendiri (riwayat per pengelola,
-- lintas periode) -- TIDAK terhubung otomatis ke kolom "Kasbon Pengelola" di
-- Rekapitulasi > Koreksi Dividen: Sisi Investor (yang per-bulan, tersimpan di
-- tabel revenue_sharing). Keduanya sengaja terpisah: yang di Rekapitulasi
-- mempengaruhi perhitungan payroll bulan itu, yang ini murni pembukuan/tracking
-- utang-piutang kasbon pengelola oleh admin pusat.
--
-- sisa_kasbon TIDAK disimpan sebagai kolom -- selalu dihitung
-- (jumlah_kasbon - jumlah_dikembalikan) saat ditampilkan, supaya tidak pernah
-- ada risiko angka tersimpan tidak sinkron dengan 2 komponen sumbernya.
--
-- Jalankan sekali:  mysql -u root db_bumi_bahari < database/migrations/2026_10_02_000001_kasbon_pengelola.sql
-- Aman diulang (CREATE TABLE IF NOT EXISTS).
-- =============================================================================

CREATE TABLE IF NOT EXISTS `kasbon_pengelola` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `id_pengelola` INT(11) NOT NULL,
  `jumlah_kasbon` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `tanggal_kasbon` DATE NOT NULL,
  `jumlah_dikembalikan` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `tanggal_pengembalian` DATE DEFAULT NULL COMMENT 'Tanggal pengembalian terakhir / pelunasan',
  `keterangan` VARCHAR(255) DEFAULT NULL,
  `status` ENUM('berjalan','lunas') NOT NULL DEFAULT 'berjalan',
  `created_by` INT(11) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_kasbon_pengelola_pengelola` (`id_pengelola`),
  KEY `idx_kasbon_pengelola_status` (`status`),
  CONSTRAINT `fk_kasbon_pengelola_pengelola` FOREIGN KEY (`id_pengelola`) REFERENCES `pengelola` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
