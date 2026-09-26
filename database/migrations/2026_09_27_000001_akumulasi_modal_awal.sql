-- =============================================================================
-- Migration: Tabel akumulasi_modal_awal — simpan input manual "Modal Awal" di
-- kartu "3. Matrik Akumulasi" (admin_pusat/rekapitulasi.php), supaya nilainya
-- tidak hilang ketika halaman di-refresh (tombol "Simpan Akumulasi").
--
-- Satu baris per cabang per periode (tahun+bulan) — TIDAK per segmen pengelola
-- (urutan_pengelola) seperti revenue_sharing, karena Modal Awal mengurangi Net
-- Profit 100% SEBELUM laba dibagi ke segmen pengelola manapun.
--
-- Jalankan sekali:  mysql -u root db_bumi_bahari < database/migrations/2026_09_27_000001_akumulasi_modal_awal.sql
-- Aman diulang (CREATE TABLE IF NOT EXISTS).
-- =============================================================================

CREATE TABLE IF NOT EXISTS `akumulasi_modal_awal` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `id_cabang` INT(11) NOT NULL,
  `tahun` SMALLINT(4) NOT NULL,
  `bulan` TINYINT(2) NOT NULL,
  `modal_awal` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` INT(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ama_cabang_periode` (`id_cabang`,`tahun`,`bulan`),
  KEY `idx_ama_periode` (`tahun`,`bulan`),
  CONSTRAINT `ama_ibfk_1` FOREIGN KEY (`id_cabang`) REFERENCES `cabang` (`id_cabang`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
