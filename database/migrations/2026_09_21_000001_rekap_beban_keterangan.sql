-- =============================================================================
-- Migration: Tabel beban_operasional_keterangan — persist "Keterangan Tambahan"
-- per baris Beban Operasional pada menu Rekapitulasi (admin_pusat & admin_pic).
-- Sebelumnya field ini murni client-side (tidak punya `name`, tidak pernah
-- tersimpan) — sekarang disimpan supaya tetap ada saat halaman dibuka ulang
-- dan supaya bisa ikut ke PDF export.
--
-- urutan_pengelola: diskriminator utk kasus 1 bulan dikelola 2 pengelola
-- berbeda (lihat juga migrasi klaim_bulanan & revenue_sharing) — default 1
-- untuk kasus normal (1 pengelola), TIDAK NULL supaya unique key tidak
-- pernah meloloskan duplikat lewat banyak NULL (perilaku unique index MySQL).
--
-- Jalankan sekali:  mysql -u root db_bumi_bahari < database/migrations/2026_09_21_000001_rekap_beban_keterangan.sql
-- Aman diulang (CREATE TABLE IF NOT EXISTS).
-- =============================================================================

CREATE TABLE IF NOT EXISTS `beban_operasional_keterangan` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `id_cabang` INT(11) NOT NULL,
  `tahun` SMALLINT(4) NOT NULL,
  `bulan` TINYINT(2) NOT NULL,
  `urutan_pengelola` TINYINT(2) NOT NULL DEFAULT 1,
  `uraian_key` VARCHAR(40) NOT NULL,
  `keterangan` VARCHAR(255) DEFAULT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` INT(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_bok_cabang_periode_uraian` (`id_cabang`,`tahun`,`bulan`,`urutan_pengelola`,`uraian_key`),
  CONSTRAINT `bok_ibfk_1` FOREIGN KEY (`id_cabang`) REFERENCES `cabang` (`id_cabang`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
