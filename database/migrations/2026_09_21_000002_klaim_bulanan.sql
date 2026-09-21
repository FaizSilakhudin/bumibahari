-- =============================================================================
-- Migration: Tabel klaim_bulanan — daftar baris klaim manual ("10. Klaim
-- Bulanan") pada menu Rekapitulasi (admin_pusat & admin_pic). Baris bebas
-- ditambah/dihapus oleh user (bukan daftar tetap seperti Beban Operasional),
-- jadi TANPA unique key selain PK — tiap Simpan menghapus semua baris lama
-- utk (id_cabang, tahun, bulan, urutan_pengelola) lalu insert ulang set baru.
--
-- Nominal setiap baris otomatis mengurangi Net Profit SETELAH admin fee 3%
-- dipotong, SEBELUM split 50/50 investor-pengelola (lihat rekapitulasi.php).
--
-- urutan_pengelola: sama seperti beban_operasional_keterangan & revenue_sharing
-- — segmen pengelola saat 1 bulan dikelola 2 pengelola berbeda.
--
-- Jalankan sekali:  mysql -u root db_bumi_bahari < database/migrations/2026_09_21_000002_klaim_bulanan.sql
-- Aman diulang (CREATE TABLE IF NOT EXISTS).
-- =============================================================================

CREATE TABLE IF NOT EXISTS `klaim_bulanan` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `id_cabang` INT(11) NOT NULL,
  `tahun` SMALLINT(4) NOT NULL,
  `bulan` TINYINT(2) NOT NULL,
  `urutan_pengelola` TINYINT(2) NOT NULL DEFAULT 1,
  `urutan` INT(11) NOT NULL DEFAULT 0,
  `uraian` VARCHAR(255) NOT NULL,
  `nominal` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `keterangan` VARCHAR(255) DEFAULT NULL,
  `created_by` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_kb_cabang_periode` (`id_cabang`,`tahun`,`bulan`,`urutan_pengelola`),
  CONSTRAINT `kb_ibfk_1` FOREIGN KEY (`id_cabang`) REFERENCES `cabang` (`id_cabang`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
