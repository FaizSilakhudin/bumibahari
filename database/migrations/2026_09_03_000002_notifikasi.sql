-- =============================================================================
-- Migration: Tabel notifikasi in-app (bel notifikasi untuk PIC & pusat).
-- Dipakai untuk:
--   - Cabang/pusat kirim nota  -> notifikasi ke PIC yang pegang cabang itu.
--   - PIC input/finalisasi laporan -> notifikasi ke semua akun pusat.
-- Jalankan sekali:  mysql -u root db_bumi_bahari < database/migrations/2026_09_03_000002_notifikasi.sql
-- Aman diulang (CREATE TABLE IF NOT EXISTS).
-- =============================================================================

CREATE TABLE IF NOT EXISTS `notifikasi` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `id_user` INT(11) NOT NULL,
  `jenis` VARCHAR(30) NOT NULL,
  `judul` VARCHAR(150) NOT NULL,
  `pesan` VARCHAR(255) DEFAULT NULL,
  `link` VARCHAR(255) DEFAULT NULL,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notif_user_read` (`id_user`,`is_read`),
  KEY `idx_notif_user_created` (`id_user`,`created_at`),
  CONSTRAINT `notifikasi_ibfk_1` FOREIGN KEY (`id_user`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
