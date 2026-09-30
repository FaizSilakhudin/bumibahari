-- =============================================================================
-- Migration: Tambah kolom foto_calon di calon_pengelola.
--
-- Foto diri calon pengelola (bukan dokumen KTP/KK/Buku Nikah/masakan) --
-- ditampilkan PALING BESAR di antara lampiran lain (PDF cetak_pdf.php,
-- halaman lihat_calon.php, dan modal detail admin_pusat/data_calon_pengelola.php)
-- supaya langsung jelas ini foto siapa.
--
-- Jalankan sekali:  mysql -u root db_bumi_bahari < database/migrations/2026_10_01_000002_calon_pengelola_foto_calon.sql
-- Aman diulang (ADD COLUMN IF NOT EXISTS -- MariaDB/MySQL 8+ mendukung sintaks ini).
-- =============================================================================

ALTER TABLE `calon_pengelola`
  ADD COLUMN IF NOT EXISTS `foto_calon` VARCHAR(255) DEFAULT NULL AFTER `catatan_kesimpulan`;
