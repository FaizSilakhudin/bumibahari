-- =============================================================================
-- Migration: role baru 'rekrutmen' + tabel calon_pengelola
-- Jalankan sekali:  mysql -u root db_bumi_bahari < database/migrations/2026_09_24_000001_calon_pengelola.sql
-- Aman diulang (IF NOT EXISTS / MODIFY COLUMN ke definisi yang sama).
--
-- Alur baru:
--   - Admin Rekrutmen -> input hasil interview calon pengelola (form ringkas
--     per bagian A-F, bukan 1 kolom per pertanyaan) via admin_rekrutmen/.
--     Semua akun rekrutmen berbagi satu daftar yang sama (tidak di-scope
--     per akun, mirip admin pusat melihat semua data).
--   - Setiap data baru otomatis memicu notifikasi bel ke semua user role
--     'pusat' (lihat kirim_notifikasi()/semua_user_pusat() di config/koneksi.php).
--   - Admin Pusat -> lihat semua data calon pengelola + ubah status tindak
--     lanjut (menunggu/diterima/ditolak) + catatan pusat. Tidak mengubah isi
--     interview aslinya.
-- =============================================================================

-- 1. Role baru pada tabel users -----------------------------------------------
ALTER TABLE `users`
    MODIFY COLUMN `role` ENUM('pusat','pic','cabang','investor','rekrutmen') NOT NULL DEFAULT 'cabang';

-- 2. Tabel calon_pengelola -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `calon_pengelola` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `nama_calon` VARCHAR(150) NOT NULL,
    `usia` INT(11) DEFAULT NULL,
    `alamat` TEXT DEFAULT NULL,
    `no_hp` VARCHAR(30) DEFAULT NULL,
    `tanggal_interview` DATE NOT NULL,
    `interviewer` VARCHAR(150) DEFAULT NULL,
    -- Catatan naratif per bagian formulir interview (A, B, C, D, F — bagian E
    -- di formulir cuma penjelasan sepihak ke calon, tidak ada jawaban dicatat).
    `catatan_identitas` TEXT DEFAULT NULL COMMENT 'Bagian A: identitas & pengalaman kerja',
    `catatan_pengetahuan_wbb` TEXT DEFAULT NULL COMMENT 'Bagian B: pengetahuan tentang WBB',
    `catatan_kesiapan_sistem` TEXT DEFAULT NULL COMMENT 'Bagian C: kesiapan ikuti sistem & kebijakan',
    `catatan_komitmen_karier` TEXT DEFAULT NULL COMMENT 'Bagian D: evaluasi komunikasi/masak/disiplin/integritas',
    `catatan_komitmen_akhir` TEXT DEFAULT NULL COMMENT 'Bagian F: pertanyaan komitmen akhir & target',
    `kesimpulan_interviewer` ENUM('direkomendasikan','dipertimbangkan','tes_memasak','belum_direkomendasikan') NOT NULL DEFAULT 'dipertimbangkan',
    `catatan_kesimpulan` TEXT DEFAULT NULL,
    -- Tindak lanjut oleh admin pusat -- terpisah dari kesimpulan interviewer di atas.
    `status_tindak_lanjut` ENUM('menunggu','diterima','ditolak') NOT NULL DEFAULT 'menunggu',
    `catatan_pusat` TEXT DEFAULT NULL,
    `id_user_input` INT(11) DEFAULT NULL COMMENT 'Akun admin rekrutmen yang input data ini',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_calon_pengelola_status` (`status_tindak_lanjut`),
    KEY `idx_calon_pengelola_user_input` (`id_user_input`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
