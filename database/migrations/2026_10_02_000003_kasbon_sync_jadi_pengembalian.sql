-- =============================================================================
-- Migration: Perbaikan model sinkronisasi Kasbon Pengelola <-> Rekapitulasi.
--
-- Desain SEBELUMNYA (migration 2026_10_02_000002) keliru: nominal "Kasbon
-- Pengelola" di Rekapitulasi (sumber Dana Pusat) diperlakukan sebagai KASBON
-- BARU (menambah jumlah_kasbon). YANG BENAR: nominal itu adalah POTONGAN
-- PEMBAYARAN/PENGEMBALIAN dari kasbon yang SUDAH ADA -- sama seperti efek
-- tombol "Bayar", bukan "Catat Kasbon Baru".
--
-- Jadi: kolom penanda sinkronisasi otomatis dipindah dari kasbon_pengelola
-- (yang sekarang SELALU dibuat manual lewat "Catat Kasbon Baru") ke
-- kasbon_pengelola_riwayat (karena yang disinkron sekarang adalah baris
-- RIWAYAT PEMBAYARAN, bukan baris kasbon itu sendiri).
--
-- Baris kasbon_pengelola ber-asal_otomatis=1 TANPA pengembalian apa pun
-- (jumlah_dikembalikan=0) adalah peninggalan desain lama yang salah model --
-- dibersihkan di sini (aman, karena fitur ini baru saja dirilis & belum
-- dipakai nyata selain untuk pengujian).
--
-- Jalankan sekali:  mysql -u root db_bumi_bahari < database/migrations/2026_10_02_000003_kasbon_sync_jadi_pengembalian.sql
-- Aman diulang (DROP/ADD ... IF EXISTS/IF NOT EXISTS).
-- =============================================================================

DELETE FROM `kasbon_pengelola` WHERE `asal_otomatis` = 1 AND `jumlah_dikembalikan` <= 0;

ALTER TABLE `kasbon_pengelola` DROP INDEX IF EXISTS `idx_kasbon_auto_periode`;
ALTER TABLE `kasbon_pengelola`
  DROP COLUMN IF EXISTS `asal_otomatis`,
  DROP COLUMN IF EXISTS `id_cabang_periode`,
  DROP COLUMN IF EXISTS `tahun_periode`,
  DROP COLUMN IF EXISTS `bulan_periode`;

ALTER TABLE `kasbon_pengelola_riwayat`
  ADD COLUMN IF NOT EXISTS `asal_otomatis` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = potongan otomatis dari Rekapitulasi (Dana Pusat)' AFTER `keterangan`,
  ADD COLUMN IF NOT EXISTS `id_cabang_periode` INT(11) DEFAULT NULL COMMENT 'Cabang+periode asal potongan (kalau asal_otomatis=1)' AFTER `asal_otomatis`,
  ADD COLUMN IF NOT EXISTS `tahun_periode` SMALLINT(4) DEFAULT NULL AFTER `id_cabang_periode`,
  ADD COLUMN IF NOT EXISTS `bulan_periode` TINYINT(2) DEFAULT NULL AFTER `tahun_periode`,
  ADD INDEX IF NOT EXISTS `idx_riwayat_auto_periode` (`asal_otomatis`, `id_cabang_periode`, `tahun_periode`, `bulan_periode`);
