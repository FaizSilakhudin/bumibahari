-- =============================================================================
-- Migration: form Data Calon Pengelola dibuat LENGKAP 1 kolom per pertanyaan
-- (sesuai formulir interview asli), + no urut manual + upload dokumen/foto.
--
-- Tabel calon_pengelola baru dibuat sehari sebelumnya (migration
-- 2026_09_24_000001) dan belum ada data produksi sungguhan sama sekali --
-- aman drop kolom catatan ringkas per bagian, diganti kolom granular.
--
-- Bagian D (Komitmen & Jenjang Karier) dan E (Peluang Penempatan Outlet) di
-- formulir aslinya CUMA penjelasan/kebijakan sepihak ke calon (tidak ada
-- pertanyaan/jawaban tertulis) -- SENGAJA tidak dibuatkan kolom, supaya
-- kolom di database persis mengikuti apa yang benar-benar ditanyakan di form.
--
-- Jalankan sekali:  mysql -u root db_bumi_bahari < database/migrations/2026_09_25_000001_calon_pengelola_form_lengkap.sql
-- =============================================================================

ALTER TABLE `calon_pengelola`
    DROP COLUMN IF EXISTS `catatan_identitas`,
    DROP COLUMN IF EXISTS `catatan_pengetahuan_wbb`,
    DROP COLUMN IF EXISTS `catatan_kesiapan_sistem`,
    DROP COLUMN IF EXISTS `catatan_komitmen_karier`,
    DROP COLUMN IF EXISTS `catatan_komitmen_akhir`;

ALTER TABLE `calon_pengelola`
    ADD COLUMN IF NOT EXISTS `no_urut` VARCHAR(30) DEFAULT NULL COMMENT 'Nomor urut manual, bebas format' AFTER `id`,

    -- Bagian A: Identitas & Pengalaman Kerja
    ADD COLUMN IF NOT EXISTS `a_perkenalan` TEXT DEFAULT NULL COMMENT 'A1: perkenalan diri & pengalaman kerja' AFTER `interviewer`,
    ADD COLUMN IF NOT EXISTS `a_nama_tempat_usaha` VARCHAR(150) DEFAULT NULL COMMENT 'A2: nama tempat/usaha sebelumnya' AFTER `a_perkenalan`,
    ADD COLUMN IF NOT EXISTS `a_posisi_jabatan` VARCHAR(100) DEFAULT NULL COMMENT 'A2: posisi/jabatan sebelumnya' AFTER `a_nama_tempat_usaha`,
    ADD COLUMN IF NOT EXISTS `a_lama_bekerja` VARCHAR(50) DEFAULT NULL COMMENT 'A2: lama bekerja' AFTER `a_posisi_jabatan`,
    ADD COLUMN IF NOT EXISTS `a_pernah_kelola_warteg` ENUM('ya','tidak') DEFAULT NULL COMMENT 'A3: pernah mengelola warteg?' AFTER `a_lama_bekerja`,
    ADD COLUMN IF NOT EXISTS `a_lama_kelola_warteg` VARCHAR(50) DEFAULT NULL COMMENT 'A3: kalau pernah, berapa lama' AFTER `a_pernah_kelola_warteg`,
    ADD COLUMN IF NOT EXISTS `a_omzet_rata_rata` VARCHAR(100) DEFAULT NULL COMMENT 'A3: omzet rata-rata per hari/bulan' AFTER `a_lama_kelola_warteg`,
    ADD COLUMN IF NOT EXISTS `a_omzet_tertinggi` VARCHAR(100) DEFAULT NULL COMMENT 'A3: omzet tertinggi pernah dicapai' AFTER `a_omzet_rata_rata`,
    ADD COLUMN IF NOT EXISTS `a_alasan_berhenti` TEXT DEFAULT NULL COMMENT 'A4: alasan berhenti/keluar' AFTER `a_omzet_tertinggi`,
    ADD COLUMN IF NOT EXISTS `a_video_masakan` ENUM('ya','tidak') DEFAULT NULL COMMENT 'A4: punya video hasil masakan?' AFTER `a_alasan_berhenti`,
    ADD COLUMN IF NOT EXISTS `a_menu_dikuasai` TEXT DEFAULT NULL COMMENT 'A4: menu-menu yang dikuasai' AFTER `a_video_masakan`,
    ADD COLUMN IF NOT EXISTS `a_catatan_interviewer` TEXT DEFAULT NULL COMMENT 'Catatan interviewer bagian A' AFTER `a_menu_dikuasai`,

    -- Bagian B: Pengetahuan tentang WBB
    ADD COLUMN IF NOT EXISTS `b_tahu_dari_mana` TEXT DEFAULT NULL COMMENT 'B1: tahu WBB dari mana' AFTER `a_catatan_interviewer`,
    ADD COLUMN IF NOT EXISTS `b_alasan_tertarik` TEXT DEFAULT NULL COMMENT 'B2: alasan tertarik bergabung' AFTER `b_tahu_dari_mana`,
    ADD COLUMN IF NOT EXISTS `b_pernah_kunjungi_outlet` ENUM('ya','tidak') DEFAULT NULL COMMENT 'B3: pernah lihat/kunjungi outlet WBB?' AFTER `b_alasan_tertarik`,
    ADD COLUMN IF NOT EXISTS `b_outlet_mana` VARCHAR(150) DEFAULT NULL COMMENT 'B3: outlet mana' AFTER `b_pernah_kunjungi_outlet`,
    ADD COLUMN IF NOT EXISTS `b_yang_diperhatikan` TEXT DEFAULT NULL COMMENT 'B3: yang diperhatikan dari outlet tsb' AFTER `b_outlet_mana`,
    ADD COLUMN IF NOT EXISTS `b_pendapat_penjualan_baik` TEXT DEFAULT NULL COMMENT 'B4: pendapat agar penjualan outlet baik' AFTER `b_yang_diperhatikan`,
    ADD COLUMN IF NOT EXISTS `b_catatan_interviewer` TEXT DEFAULT NULL COMMENT 'Catatan interviewer bagian B' AFTER `b_pendapat_penjualan_baik`,

    -- Bagian C: kesiapan ikuti sistem (satu jawaban saja di formulir asli)
    ADD COLUMN IF NOT EXISTS `c_jawaban_kesiapan` TEXT DEFAULT NULL COMMENT 'C: jawaban kesiapan ikuti sistem & kebijakan WBB' AFTER `b_catatan_interviewer`,

    -- Bagian F: Pertanyaan Komitmen Akhir
    ADD COLUMN IF NOT EXISTS `f_siap_sop` ENUM('ya','tidak') DEFAULT NULL COMMENT 'F1: siap ikuti SOP & arahan manajemen?' AFTER `c_jawaban_kesiapan`,
    ADD COLUMN IF NOT EXISTS `f_siap_evaluasi` ENUM('ya','tidak') DEFAULT NULL COMMENT 'F2: siap dievaluasi berkala?' AFTER `f_siap_sop`,
    ADD COLUMN IF NOT EXISTS `f_siap_dipindah` ENUM('ya','tidak') DEFAULT NULL COMMENT 'F3: siap ditempatkan/dipindah outlet?' AFTER `f_siap_evaluasi`,
    ADD COLUMN IF NOT EXISTS `f_siap_jaga_kualitas` ENUM('ya','tidak') DEFAULT NULL COMMENT 'F4: siap jaga kualitas masakan/pelayanan/kebersihan/laporan?' AFTER `f_siap_dipindah`,
    ADD COLUMN IF NOT EXISTS `f_rencana_tingkatkan_penjualan` TEXT DEFAULT NULL COMMENT 'F5: rencana tingkatkan penjualan outlet omzet besar' AFTER `f_siap_jaga_kualitas`,
    ADD COLUMN IF NOT EXISTS `f_target_bergabung` TEXT DEFAULT NULL COMMENT 'F6: target kalau bergabung dgn WBB' AFTER `f_rencana_tingkatkan_penjualan`,

    -- Dokumen & foto (nama file di uploads/calon_pengelola/, bukan path penuh)
    ADD COLUMN IF NOT EXISTS `foto_ktp` VARCHAR(255) DEFAULT NULL AFTER `catatan_kesimpulan`,
    ADD COLUMN IF NOT EXISTS `foto_kk` VARCHAR(255) DEFAULT NULL AFTER `foto_ktp`,
    ADD COLUMN IF NOT EXISTS `foto_buku_nikah` VARCHAR(255) DEFAULT NULL AFTER `foto_kk`,
    ADD COLUMN IF NOT EXISTS `foto_masakan1` VARCHAR(255) DEFAULT NULL AFTER `foto_buku_nikah`,
    ADD COLUMN IF NOT EXISTS `foto_masakan2` VARCHAR(255) DEFAULT NULL AFTER `foto_masakan1`,
    ADD COLUMN IF NOT EXISTS `foto_masakan3` VARCHAR(255) DEFAULT NULL AFTER `foto_masakan2`;
