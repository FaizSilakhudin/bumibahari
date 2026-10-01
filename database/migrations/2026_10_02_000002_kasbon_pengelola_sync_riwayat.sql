-- =============================================================================
-- Migration: Sinkronisasi otomatis Kasbon Pengelola <-> Rekapitulasi (Dana
-- Pusat) + tabel riwayat pengembalian (dipakai tombol "Detail" baru).
--
-- 1) Kolom baru di kasbon_pengelola untuk menandai & melacak baris yang
--    dibuat OTOMATIS dari Rekapitulasi > Koreksi Dividen: Sisi Investor >
--    Kasbon Pengelola (sumber = "Dana Pusat" saja -- Dana Investor/Dana
--    Warung TIDAK disinkron, karena bukan piutang pusat ke pengelola).
--    (id_cabang_periode, tahun_periode, bulan_periode) jadi kunci pencarian
--    "baris mana yang harus di-update" tiap kali tombol Simpan Kasbon di
--    Rekapitulasi diklik lagi untuk cabang+periode yang sama.
--
-- 2) Tabel kasbon_pengelola_riwayat: log setiap kali ada pengembalian
--    dicatat (tombol "Bayar") atau koreksi manual lewat "Edit" -- dipakai
--    modal "Detail" untuk menampilkan riwayat pengembalian per kasbon.
--
-- Jalankan sekali:  mysql -u root db_bumi_bahari < database/migrations/2026_10_02_000002_kasbon_pengelola_sync_riwayat.sql
-- Aman diulang (ADD COLUMN/CREATE TABLE IF NOT EXISTS).
-- =============================================================================

ALTER TABLE `kasbon_pengelola`
  ADD COLUMN IF NOT EXISTS `asal_otomatis` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = dibuat otomatis dari Rekapitulasi (Dana Pusat)' AFTER `status`,
  ADD COLUMN IF NOT EXISTS `id_cabang_periode` INT(11) DEFAULT NULL COMMENT 'Cabang asal sinkronisasi (kalau asal_otomatis=1)' AFTER `asal_otomatis`,
  ADD COLUMN IF NOT EXISTS `tahun_periode` SMALLINT(4) DEFAULT NULL AFTER `id_cabang_periode`,
  ADD COLUMN IF NOT EXISTS `bulan_periode` TINYINT(2) DEFAULT NULL AFTER `tahun_periode`,
  ADD INDEX IF NOT EXISTS `idx_kasbon_auto_periode` (`asal_otomatis`, `id_cabang_periode`, `tahun_periode`, `bulan_periode`);

CREATE TABLE IF NOT EXISTS `kasbon_pengelola_riwayat` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `id_kasbon` INT(11) NOT NULL,
  `jumlah_bayar` DECIMAL(14,2) NOT NULL,
  `tanggal_bayar` DATE NOT NULL,
  `keterangan` VARCHAR(255) DEFAULT NULL,
  `created_by` INT(11) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_riwayat_kasbon` (`id_kasbon`),
  CONSTRAINT `fk_riwayat_kasbon` FOREIGN KEY (`id_kasbon`) REFERENCES `kasbon_pengelola` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
