-- =============================================================================
-- Migration: Tabel revenue_sharing — admin fee & service fee per cabang per bulan.
-- Dipakai oleh admin_pusat/revenue_sharing.php (menu baru "Revenue Sharing"
-- di bawah Rekapitulasi).
--
-- Hanya menyimpan kolom yang EDITABLE lewat UI:
--   - persen_service_fee  (3% / 5% / 7.5%, default 5%)
--   - status_pembayaran   (pending / belum_lunas / lunas, default pending)
-- Admin fee TIDAK disimpan karena selalu 3% (hard-coded, sama dengan rumus
-- yang sudah dipakai di admin_pusat/index.php & admin_pusat/rekapitulasi.php).
--
-- Jalankan sekali:  mysql -u root db_bumi_bahari < database/migrations/2026_09_20_000001_revenue_sharing.sql
-- Aman diulang (CREATE TABLE IF NOT EXISTS).
-- =============================================================================

CREATE TABLE IF NOT EXISTS `revenue_sharing` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `id_cabang` INT(11) NOT NULL,
  `tahun` SMALLINT(4) NOT NULL,
  `bulan` TINYINT(2) NOT NULL,
  `persen_service_fee` DECIMAL(4,2) NOT NULL DEFAULT 5.00,
  `status_pembayaran` ENUM('pending','belum_lunas','lunas') NOT NULL DEFAULT 'pending',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` INT(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_rs_cabang_periode` (`id_cabang`,`tahun`,`bulan`),
  KEY `idx_rs_periode` (`tahun`,`bulan`),
  CONSTRAINT `rs_ibfk_1` FOREIGN KEY (`id_cabang`) REFERENCES `cabang` (`id_cabang`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;