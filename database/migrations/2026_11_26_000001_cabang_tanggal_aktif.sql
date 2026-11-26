-- Migration: 2026_11_26_000001_cabang_tanggal_aktif
-- Tambah kolom tanggal_aktif ke tabel cabang.
-- Cabang yang tidak diisi (NULL) = tidak ada batasan tanggal, semua hari dihitung.
-- Cabang yang diisi = laporan hanya dihitung mulai tanggal tersebut ke atas.

ALTER TABLE `cabang`
    ADD COLUMN `tanggal_aktif` DATE DEFAULT NULL AFTER `tipe_outlet`;

-- Setelah migration ini dijalankan, set tanggal_aktif untuk cabang-cabang baru
-- yang belum aktif dari awal. Contoh untuk Ciledug (ganti id_cabang sesuai data):
-- UPDATE `cabang` SET `tanggal_aktif` = '2026-11-25' WHERE nama_cabang LIKE '%CILEDUG%';
