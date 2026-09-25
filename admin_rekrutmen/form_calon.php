<?php
require '../config/koneksi.php';

// Cegah browser/proxy nge-cache halaman ini -- kalau ada perbaikan JS di
// halaman ini (mis. logika cetak PDF), user harus selalu dapat versi
// terbaru, bukan versi lama yang ke-cache dari kunjungan sebelumnya.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

include 'sidebar.php';

// Catatan: sidebar.php di atas SUDAH mengirim output HTML (<!DOCTYPE>, dst),
// jadi header('Location: ...') tidak bisa dipakai lagi di bawah sini --
// pakai redirect JS (pola yang sama dipakai data_user.php dkk).
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'rekrutmen') {
    echo "<script>window.location='../login';</script>";
    exit;
}

$id_calon = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$id_user  = current_user_id();
$data     = null;

if ($id_calon) {
    $stmt = $conn->prepare("SELECT cp.*, u.username AS nama_input FROM calon_pengelola cp LEFT JOIN users u ON u.id = cp.id_user_input WHERE cp.id = ?");
    $stmt->bind_param("i", $id_calon);
    $stmt->execute();
    $data = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$data) {
        echo "<script>window.location='index';</script>";
        exit;
    }
}

$UPLOAD_DIR = '../uploads/calon_pengelola/';
$UPLOAD_FIELDS = ['foto_ktp', 'foto_kk', 'foto_buku_nikah', 'foto_masakan1', 'foto_masakan2', 'foto_masakan3'];
$UPLOAD_LABEL = [
    'foto_ktp' => 'KTP', 'foto_kk' => 'Kartu Keluarga', 'foto_buku_nikah' => 'Buku Nikah',
    'foto_masakan1' => 'Foto Masakan 1', 'foto_masakan2' => 'Foto Masakan 2', 'foto_masakan3' => 'Foto Masakan 3',
];

// Upload 1 berkas dokumen/foto -- validasi MIME sungguhan, ukuran, nama file
// aman, lalu dikompres. Kalau tidak ada file baru dikirim, kembalikan nama
// file LAMA (supaya tidak ke-null-kan waktu edit tanpa ganti foto).
function upload_dokumen_calon(string $field, string $upload_dir, ?string $lama): array
{
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        return [$lama, null];
    }
    $tmp_name  = $_FILES[$field]['tmp_name'];
    $file_size = $_FILES[$field]['size'];

    if (!is_uploaded_file($tmp_name)) {
        return [$lama, null];
    }

    $cek = @getimagesize($tmp_name);
    $mime_map = ['image/jpeg' => 'jpg', 'image/pjpeg' => 'jpg', 'image/png' => 'png'];
    $real_mime = is_array($cek) ? ($cek['mime'] ?? '') : '';
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) {
            $real_mime = finfo_file($fi, $tmp_name) ?: $real_mime;
            finfo_close($fi);
        }
    }
    $ext = $mime_map[$real_mime] ?? '';

    if ($cek === false || $ext === '') {
        return [$lama, "Format $field harus JPG/PNG."];
    }
    if ($file_size > 3000000) {
        return [$lama, "Ukuran $field maksimal 3MB."];
    }

    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    $nama_file = date('Ymd') . '_' . $field . '_' . uniqid() . '.' . $ext;
    if (!move_uploaded_file($tmp_name, $upload_dir . $nama_file)) {
        return [$lama, "Gagal menyimpan berkas $field."];
    }
    kompres_gambar_upload($upload_dir . $nama_file);

    return [$nama_file, null];
}

$YA_TIDAK_FIELDS = ['a_pernah_kelola_warteg', 'a_video_masakan', 'b_pernah_kunjungi_outlet', 'f_siap_sop', 'f_siap_evaluasi', 'f_siap_dipindah', 'f_siap_jaga_kualitas'];
$TEXT_FIELDS = [
    'no_urut', 'nama_calon', 'usia', 'alamat', 'no_hp', 'tanggal_interview', 'interviewer',
    'a_perkenalan', 'a_nama_tempat_usaha', 'a_posisi_jabatan', 'a_lama_bekerja',
    'a_lama_kelola_warteg', 'a_omzet_rata_rata', 'a_omzet_tertinggi', 'a_alasan_berhenti', 'a_menu_dikuasai', 'a_catatan_interviewer',
    'b_tahu_dari_mana', 'b_alasan_tertarik', 'b_outlet_mana', 'b_yang_diperhatikan', 'b_pendapat_penjualan_baik', 'b_catatan_interviewer',
    'c_jawaban_kesiapan',
    'f_rencana_tingkatkan_penjualan', 'f_target_bergabung',
    'catatan_kesimpulan',
];

if (isset($_POST['simpan'])) {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        echo "<script>alert('Token CSRF tidak valid!'); history.back();</script>";
        exit;
    }

    $nama_calon = trim($_POST['nama_calon'] ?? '');
    $tanggal_interview = $_POST['tanggal_interview'] ?? '';
    if ($nama_calon === '') {
        echo "<script>alert('Nama calon pengelola wajib diisi!'); history.back();</script>";
        exit;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal_interview)) {
        echo "<script>alert('Tanggal interview wajib diisi!'); history.back();</script>";
        exit;
    }

    $vals = [];
    foreach ($TEXT_FIELDS as $f) {
        $vals[$f] = trim($_POST[$f] ?? '');
    }
    $vals['usia'] = $vals['usia'] !== '' ? (int) $vals['usia'] : null;
    foreach ($YA_TIDAK_FIELDS as $f) {
        $vals[$f] = in_array($_POST[$f] ?? '', ['ya', 'tidak'], true) ? $_POST[$f] : null;
    }
    $vals['kesimpulan_interviewer'] = in_array($_POST['kesimpulan_interviewer'] ?? '', ['direkomendasikan', 'dipertimbangkan', 'tes_memasak', 'belum_direkomendasikan'], true)
        ? $_POST['kesimpulan_interviewer'] : 'dipertimbangkan';

    $upload_errors = [];
    foreach ($UPLOAD_FIELDS as $f) {
        $lama = $data[$f] ?? null;
        [$vals[$f], $err] = upload_dokumen_calon($f, $UPLOAD_DIR, $lama);
        if ($err) $upload_errors[] = $err;
    }
    if ($upload_errors) {
        echo "<script>alert(" . json_encode(implode("\n", $upload_errors)) . "); history.back();</script>";
        exit;
    }

    $all_fields = array_merge($TEXT_FIELDS, $YA_TIDAK_FIELDS, ['kesimpulan_interviewer'], $UPLOAD_FIELDS);
    // usia bukan string, keluarkan dari daftar "s" generik
    $col_types = [];
    $col_values = [];
    foreach ($all_fields as $f) {
        $col_types[] = ($f === 'usia') ? 'i' : 's';
        $col_values[] = $vals[$f];
    }

    if ($id_calon) {
        $set_sql = implode(', ', array_map(fn($f) => "`$f` = ?", $all_fields));
        $stmt = $conn->prepare("UPDATE calon_pengelola SET $set_sql WHERE id = ?");
        $stmt->bind_param(implode('', $col_types) . 'i', ...array_merge($col_values, [$id_calon]));
        $stmt->execute();
        $stmt->close();
        audit($conn, 'calon_pengelola_edit', 'calon_pengelola', $id_calon, ['nama_calon' => $nama_calon]);
        echo "<script>window.location='form_calon.php?id=$id_calon&saved=1';</script>";
        exit;
    }

    $col_list = implode(', ', array_map(fn($f) => "`$f`", $all_fields)) . ', id_user_input';
    $placeholders = implode(', ', array_fill(0, count($all_fields), '?')) . ', ?';
    $stmt = $conn->prepare("INSERT INTO calon_pengelola ($col_list) VALUES ($placeholders)");
    $stmt->bind_param(implode('', $col_types) . 'i', ...array_merge($col_values, [$id_user]));
    $stmt->execute();
    $new_id = $conn->insert_id;
    $stmt->close();

    audit($conn, 'calon_pengelola_tambah', 'calon_pengelola', $new_id, ['nama_calon' => $nama_calon]);
    kirim_notifikasi(
        $conn,
        semua_user_pusat($conn),
        'calon_pengelola_baru',
        'Calon Pengelola Baru: ' . $nama_calon,
        'Diinput oleh ' . current_username() . ' pada ' . date('d M Y', strtotime($tanggal_interview)) . '.',
        'data_calon_pengelola'
    );
    echo "<script>window.location='form_calon.php?id=$new_id&saved=1';</script>";
    exit;
}

function v($data, $key, $default = '') {
    return h($data[$key] ?? $default);
}
function yt_checked($data, $key, $val) {
    return (($data[$key] ?? '') === $val) ? 'checked' : '';
}
$d = $data ?? [];
?>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">

<style>
    body { background-color: #f4f7fe !important; font-family: 'Plus Jakarta Sans', sans-serif !important; }
    .saas-card { background: #ffffff; border: none !important; border-radius: 20px !important; box-shadow: 0px 18px 40px rgba(112, 144, 176, 0.06) !important; padding: 24px; }
    .title-mark { width: 12px; height: 12px; background-color: #0d9488; border-radius: 4px; display: inline-block; margin-right: 10px; }

    .btn-premium { background-color: #0d9488 !important; color: #fff !important; border: none !important; padding: 10px 20px; border-radius: 12px; font-weight: 600; font-size: 14px; }
    .btn-premium:hover { background-color: #0f766e !important; }
    .btn-premium-outline { background-color: #f0fdfa !important; color: #0d9488 !important; border: 1px solid #99f6e4 !important; padding: 10px 20px; border-radius: 12px; font-weight: 600; font-size: 14px; }
    .btn-premium-outline:hover { background-color: #ccfbf1 !important; }
    .btn-wa { background-color: #25d366 !important; color: #fff !important; border: none !important; padding: 10px 20px; border-radius: 12px; font-weight: 600; font-size: 14px; }
    .btn-wa:hover { background-color: #1ebe5b !important; }

    .form-control-premium, .form-select-premium { border-radius: 10px !important; border: 1px solid #e0e7ff !important; padding: 9px 14px; color: #1b2559; font-size: 14px; background-color: #fff; width: 100%; }
    .form-control-premium:focus, .form-select-premium:focus { border-color: #0d9488 !important; box-shadow: 0 0 0 4px rgba(13, 148, 136, 0.1) !important; outline: none; }
    textarea.form-control-premium { resize: vertical; }

    .sect-head { background: #0d9488; color: #fff; font-weight: 700; padding: 12px 18px; border-radius: 12px; margin: 28px 0 16px; font-size: 14px; letter-spacing: .3px; }
    .sect-head:first-of-type { margin-top: 0; }
    .sect-desc { background: #f0fdfa; border: 1px solid #99f6e4; border-radius: 12px; padding: 14px 18px; font-size: 13px; color: #134e4a; margin-bottom: 16px; line-height: 1.6; }
    .q-label { font-weight: 600; color: #1b2559; font-size: 14px; margin-bottom: 6px; display: block; }
    .q-block { margin-bottom: 18px; }
    .yt-group { display: flex; gap: 18px; margin-top: 4px; }
    .yt-group label { font-weight: 500; font-size: 14px; color: #475569; display: flex; align-items: center; gap: 6px; cursor: pointer; }

    .upload-box { border: 2px dashed #99f6e4; border-radius: 12px; padding: 16px; text-align: center; background: #f0fdfa; }
    .upload-box img { max-width: 100%; max-height: 140px; border-radius: 8px; margin-bottom: 8px; object-fit: cover; }
    .upload-box label.upl-title { font-weight: 700; color: #0f766e; font-size: 13px; display: block; margin-bottom: 8px; }

    @media print { .no-print { display: none !important; } }
</style>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <div class="d-flex align-items-center">
                <span class="title-mark"></span>
                <h3 class="fw-bold mb-0" style="color: #1b2559;"><?= $id_calon ? 'Edit Calon Pengelola' : 'Tambah Calon Pengelola' ?></h3>
            </div>
            <span class="text-muted small ms-4">Formulir interview calon pengelola Warteg Bumi Bahari</span>
        </div>
        <a href="index" class="btn btn-premium-outline"><i class="bi bi-arrow-left me-1"></i> Kembali ke Daftar</a>
    </div>

    <div class="saas-card">
        <form method="POST" enctype="multipart/form-data" id="formCalon">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

            <div class="sect-head"><i class="bi bi-person-vcard me-1"></i> Identitas Calon Pengelola</div>
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="q-label">No. Urut</label>
                    <input type="text" name="no_urut" class="form-control-premium" value="<?= v($d, 'no_urut') ?>" placeholder="Misal: 001/HRD/IX">
                </div>
                <div class="col-md-6">
                    <label class="q-label">Nama Calon Pengelola</label>
                    <input type="text" name="nama_calon" class="form-control-premium" value="<?= v($d, 'nama_calon') ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="q-label">Usia</label>
                    <input type="number" name="usia" class="form-control-premium" value="<?= v($d, 'usia') ?>" min="0" max="100">
                </div>
                <div class="col-md-6">
                    <label class="q-label">Alamat</label>
                    <input type="text" name="alamat" class="form-control-premium" value="<?= v($d, 'alamat') ?>">
                </div>
                <div class="col-md-3">
                    <label class="q-label">No. HP</label>
                    <input type="text" name="no_hp" class="form-control-premium" value="<?= v($d, 'no_hp') ?>">
                </div>
                <div class="col-md-3">
                    <label class="q-label">Tanggal Interview</label>
                    <input type="date" name="tanggal_interview" class="form-control-premium" value="<?= v($d, 'tanggal_interview') ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="q-label">Interviewer</label>
                    <input type="text" name="interviewer" class="form-control-premium" value="<?= v($d, 'interviewer') ?>">
                </div>
            </div>

            <div class="sect-head">A. Identitas &amp; Pengalaman Kerja</div>
            <div class="q-block">
                <label class="q-label">1. Perkenalkan diri &amp; ceritakan pengalaman kerja sebelumnya</label>
                <textarea name="a_perkenalan" rows="3" class="form-control-premium"><?= v($d, 'a_perkenalan') ?></textarea>
            </div>
            <div class="q-block">
                <label class="q-label">2. Sebelumnya bekerja/mengelola usaha di mana?</label>
                <div class="row g-2">
                    <div class="col-md-4"><input type="text" name="a_nama_tempat_usaha" class="form-control-premium" placeholder="Nama tempat/usaha" value="<?= v($d, 'a_nama_tempat_usaha') ?>"></div>
                    <div class="col-md-4"><input type="text" name="a_posisi_jabatan" class="form-control-premium" placeholder="Posisi/jabatan" value="<?= v($d, 'a_posisi_jabatan') ?>"></div>
                    <div class="col-md-4"><input type="text" name="a_lama_bekerja" class="form-control-premium" placeholder="Lama bekerja" value="<?= v($d, 'a_lama_bekerja') ?>"></div>
                </div>
            </div>
            <div class="q-block">
                <label class="q-label">3. Apakah pernah mengelola warteg?</label>
                <div class="yt-group mb-2">
                    <label><input type="radio" name="a_pernah_kelola_warteg" value="ya" <?= yt_checked($d, 'a_pernah_kelola_warteg', 'ya') ?>> Ya</label>
                    <label><input type="radio" name="a_pernah_kelola_warteg" value="tidak" <?= yt_checked($d, 'a_pernah_kelola_warteg', 'tidak') ?>> Tidak</label>
                </div>
                <div class="row g-2">
                    <div class="col-md-4"><input type="text" name="a_lama_kelola_warteg" class="form-control-premium" placeholder="Jika pernah, berapa lama" value="<?= v($d, 'a_lama_kelola_warteg') ?>"></div>
                    <div class="col-md-4"><input type="text" name="a_omzet_rata_rata" class="form-control-premium" placeholder="Omzet rata-rata/hari/bulan" value="<?= v($d, 'a_omzet_rata_rata') ?>"></div>
                    <div class="col-md-4"><input type="text" name="a_omzet_tertinggi" class="form-control-premium" placeholder="Omzet tertinggi pernah dicapai" value="<?= v($d, 'a_omzet_tertinggi') ?>"></div>
                </div>
            </div>
            <div class="q-block">
                <label class="q-label">4. Mengapa berhenti/keluar dari tempat kerja sebelumnya?</label>
                <textarea name="a_alasan_berhenti" rows="2" class="form-control-premium"><?= v($d, 'a_alasan_berhenti') ?></textarea>
            </div>
            <div class="q-block">
                <label class="q-label">Apakah memiliki video hasil memasak? (minta ditunjukkan saat interview)</label>
                <div class="yt-group">
                    <label><input type="radio" name="a_video_masakan" value="ya" <?= yt_checked($d, 'a_video_masakan', 'ya') ?>> Ya</label>
                    <label><input type="radio" name="a_video_masakan" value="tidak" <?= yt_checked($d, 'a_video_masakan', 'tidak') ?>> Tidak</label>
                </div>
            </div>
            <div class="q-block">
                <label class="q-label">Menu-menu yang benar-benar dikuasai</label>
                <textarea name="a_menu_dikuasai" rows="2" class="form-control-premium"><?= v($d, 'a_menu_dikuasai') ?></textarea>
            </div>
            <div class="q-block">
                <label class="q-label">Catatan Interviewer (Bagian A)</label>
                <textarea name="a_catatan_interviewer" rows="2" class="form-control-premium"><?= v($d, 'a_catatan_interviewer') ?></textarea>
            </div>

            <div class="sect-head">B. Pengetahuan tentang Warteg Bumi Bahari</div>
            <div class="q-block">
                <label class="q-label">1. Anda mengetahui Warteg Bumi Bahari dari mana?</label>
                <textarea name="b_tahu_dari_mana" rows="2" class="form-control-premium"><?= v($d, 'b_tahu_dari_mana') ?></textarea>
            </div>
            <div class="q-block">
                <label class="q-label">2. Apa yang membuat Anda tertarik bergabung menjadi pengelola WBB?</label>
                <textarea name="b_alasan_tertarik" rows="2" class="form-control-premium"><?= v($d, 'b_alasan_tertarik') ?></textarea>
            </div>
            <div class="q-block">
                <label class="q-label">3. Apakah sebelumnya sudah pernah melihat/mengunjungi outlet WBB?</label>
                <div class="yt-group mb-2">
                    <label><input type="radio" name="b_pernah_kunjungi_outlet" value="ya" <?= yt_checked($d, 'b_pernah_kunjungi_outlet', 'ya') ?>> Ya</label>
                    <label><input type="radio" name="b_pernah_kunjungi_outlet" value="tidak" <?= yt_checked($d, 'b_pernah_kunjungi_outlet', 'tidak') ?>> Tidak</label>
                </div>
                <div class="row g-2">
                    <div class="col-md-5"><input type="text" name="b_outlet_mana" class="form-control-premium" placeholder="Outlet mana" value="<?= v($d, 'b_outlet_mana') ?>"></div>
                    <div class="col-md-7"><input type="text" name="b_yang_diperhatikan" class="form-control-premium" placeholder="Yang diperhatikan dari outlet tsb" value="<?= v($d, 'b_yang_diperhatikan') ?>"></div>
                </div>
            </div>
            <div class="q-block">
                <label class="q-label">4. Menurut Anda, apa yang harus dilakukan pengelola agar outlet penjualannya baik?</label>
                <textarea name="b_pendapat_penjualan_baik" rows="2" class="form-control-premium"><?= v($d, 'b_pendapat_penjualan_baik') ?></textarea>
            </div>
            <div class="q-block">
                <label class="q-label">Catatan Interviewer (Bagian B)</label>
                <textarea name="b_catatan_interviewer" rows="2" class="form-control-premium"><?= v($d, 'b_catatan_interviewer') ?></textarea>
            </div>

            <div class="sect-head">C. Penjelasan Sistem &amp; Karakter WBB</div>
            <div class="sect-desc">
                WBB tidak hanya berorientasi membuka warung, tetapi membangun perusahaan dan jaringan usaha yang kuat &amp; berkelanjutan. Lokasi outlet dipilih selektif berdasarkan potensi pasar, kepadatan konsumen, lingkungan, akses, dan peluang omzet &mdash; biaya sewa di lokasi tertentu bisa relatif tinggi namun keputusan lokasi tetap berdasar kelayakan usaha. Pengelola harus siap mengikuti sistem, SOP, evaluasi, dan arahan manajemen; keberhasilan outlet butuh kerja sama pengelola &amp; manajemen.
            </div>
            <div class="q-block">
                <label class="q-label">Setelah memahami sistem tersebut, apakah calon siap mengikuti standar &amp; kebijakan Manajemen WBB?</label>
                <textarea name="c_jawaban_kesiapan" rows="2" class="form-control-premium"><?= v($d, 'c_jawaban_kesiapan') ?></textarea>
            </div>

            <div class="sect-head">D. Komitmen &amp; Jenjang Karier Pengelola</div>
            <div class="sect-desc">
                WBB memberi kesempatan berkembang berdasarkan kinerja, kemampuan, kedisiplinan, komunikasi, dan kepatuhan SOP/arahan manajemen. Aspek yang dievaluasi: <strong>1) Komunikatif</strong> &mdash; mampu berkomunikasi baik dengan pelanggan/karyawan/sesama pengelola/manajemen. <strong>2) Kemampuan memasak</strong> &mdash; kualitas masakan baik, konsisten, bersih, sesuai standar WBB. <strong>3) Disiplin &amp; dapat diarahkan</strong> &mdash; bersedia ikuti SOP/evaluasi/kebijakan/arahan manajemen secara profesional. <strong>4) Kemampuan mengelola outlet</strong> &mdash; mampu atur bahan baku, kebersihan, pelayanan, karyawan, operasional, penjualan. <strong>5) Integritas &amp; tanggung jawab</strong> &mdash; jujur dalam laporan, bertanggung jawab, menjaga nama baik WBB.
            </div>

            <div class="sect-head">E. Peluang Penempatan Outlet</div>
            <div class="sect-desc">
                Pengelola berkinerja baik, komunikatif, masakan berkualitas, disiplin, mampu ikuti sistem manajemen, serta bisa mengembangkan penjualan akan mendapat kesempatan pengembangan karier. Berdasarkan evaluasi manajemen, pengelola dapat dipertimbangkan dipindahkan/dipercaya mengelola outlet berpotensi omzet lebih tinggi &mdash; penempatan bukan semata berdasar lama bergabung, tapi kinerja, kesiapan, kemampuan, dan kebutuhan operasional perusahaan.
            </div>

            <div class="sect-head">F. Pertanyaan Komitmen Akhir</div>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="q-label">1. Siap ikuti SOP &amp; arahan Manajemen WBB?</label>
                    <div class="yt-group">
                        <label><input type="radio" name="f_siap_sop" value="ya" <?= yt_checked($d, 'f_siap_sop', 'ya') ?>> Ya</label>
                        <label><input type="radio" name="f_siap_sop" value="tidak" <?= yt_checked($d, 'f_siap_sop', 'tidak') ?>> Tidak</label>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="q-label">2. Siap dievaluasi secara berkala?</label>
                    <div class="yt-group">
                        <label><input type="radio" name="f_siap_evaluasi" value="ya" <?= yt_checked($d, 'f_siap_evaluasi', 'ya') ?>> Ya</label>
                        <label><input type="radio" name="f_siap_evaluasi" value="tidak" <?= yt_checked($d, 'f_siap_evaluasi', 'tidak') ?>> Tidak</label>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="q-label">3. Siap ditempatkan/dipindahkan ke outlet sesuai kebutuhan?</label>
                    <div class="yt-group">
                        <label><input type="radio" name="f_siap_dipindah" value="ya" <?= yt_checked($d, 'f_siap_dipindah', 'ya') ?>> Ya</label>
                        <label><input type="radio" name="f_siap_dipindah" value="tidak" <?= yt_checked($d, 'f_siap_dipindah', 'tidak') ?>> Tidak</label>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="q-label">4. Siap jaga kualitas masakan, pelayanan, kebersihan &amp; laporan operasional?</label>
                    <div class="yt-group">
                        <label><input type="radio" name="f_siap_jaga_kualitas" value="ya" <?= yt_checked($d, 'f_siap_jaga_kualitas', 'ya') ?>> Ya</label>
                        <label><input type="radio" name="f_siap_jaga_kualitas" value="tidak" <?= yt_checked($d, 'f_siap_jaga_kualitas', 'tidak') ?>> Tidak</label>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="q-label">5. Jika diberi outlet berpotensi omzet lebih besar, apa yang akan dilakukan untuk tingkatkan penjualan?</label>
                    <textarea name="f_rencana_tingkatkan_penjualan" rows="2" class="form-control-premium"><?= v($d, 'f_rencana_tingkatkan_penjualan') ?></textarea>
                </div>
                <div class="col-md-6">
                    <label class="q-label">6. Apa target Anda jika bergabung dengan Warteg Bumi Bahari?</label>
                    <textarea name="f_target_bergabung" rows="2" class="form-control-premium"><?= v($d, 'f_target_bergabung') ?></textarea>
                </div>
            </div>

            <div class="sect-head"><i class="bi bi-clipboard-check me-1"></i> Kesimpulan Interviewer</div>
            <div class="row g-3">
                <div class="col-md-6">
                    <select name="kesimpulan_interviewer" class="form-select-premium">
                        <option value="direkomendasikan" <?= ($d['kesimpulan_interviewer'] ?? '') === 'direkomendasikan' ? 'selected' : '' ?>>Direkomendasikan</option>
                        <option value="dipertimbangkan" <?= ($d['kesimpulan_interviewer'] ?? 'dipertimbangkan') === 'dipertimbangkan' ? 'selected' : '' ?>>Dipertimbangkan / Tes Lanjutan</option>
                        <option value="tes_memasak" <?= ($d['kesimpulan_interviewer'] ?? '') === 'tes_memasak' ? 'selected' : '' ?>>Tes Memasak</option>
                        <option value="belum_direkomendasikan" <?= ($d['kesimpulan_interviewer'] ?? '') === 'belum_direkomendasikan' ? 'selected' : '' ?>>Belum Direkomendasikan</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <input type="text" name="catatan_kesimpulan" class="form-control-premium" placeholder="Catatan tambahan (opsional)" value="<?= v($d, 'catatan_kesimpulan') ?>">
                </div>
            </div>

            <div class="sect-head"><i class="bi bi-file-earmark-image me-1"></i> Dokumen &amp; Foto</div>
            <div class="row g-3">
                <?php foreach ($UPLOAD_FIELDS as $f): ?>
                <div class="col-md-4">
                    <div class="upload-box">
                        <label class="upl-title"><?= h($UPLOAD_LABEL[$f]) ?></label>
                        <?php if (!empty($d[$f])): ?>
                            <img src="<?= h($UPLOAD_DIR . $d[$f]) ?>" alt="<?= h($UPLOAD_LABEL[$f]) ?>">
                            <div class="small text-muted mb-2">Sudah ada &mdash; pilih file baru untuk ganti</div>
                        <?php endif; ?>
                        <input type="file" name="<?= $f ?>" accept="image/jpeg,image/png" class="form-control form-control-sm">
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="d-flex justify-content-end gap-2 mt-4">
                <a href="index" class="btn btn-premium-outline">Batal</a>
                <button type="submit" name="simpan" class="btn btn-premium"><i class="bi bi-save me-1"></i> Simpan Data</button>
            </div>
        </form>

        <?php if ($id_calon): ?>
        <div class="d-flex justify-content-end gap-2 mt-3 pt-3 border-top no-print">
            <button type="button" class="btn btn-premium-outline" onclick="cetakPDF(this)"><i class="bi bi-file-earmark-pdf me-1"></i> Cetak PDF</button>
            <button type="button" class="btn btn-wa" onclick="bagikanWA(this)"><i class="bi bi-whatsapp me-1"></i> Kirim ke WA</button>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($id_calon): ?>
<!-- ================= AREA CETAK PDF (replika formulir, disembunyikan dari layar) ================= -->
<?php
function cetak_yt($val) {
    $ya = $val === 'ya';
    $tidak = $val === 'tidak';
    return '<span class="chk' . ($ya ? ' checked' : '') . '"></span> Ya&nbsp;&nbsp;&nbsp;'
         . '<span class="chk' . ($tidak ? ' checked' : '') . '"></span> Tidak';
}
function cetak_isi($val) {
    $val = trim((string) $val);
    return $val !== '' ? nl2br(h($val)) : '<span class="kosong">-</span>';
}
?>
<div id="area-cetak" style="display: none; width: 190mm; background: #fff; padding: 10mm; font-family: 'Times New Roman', serif; color: #111; font-size: 11px; line-height: 1.5;">
    <style>
        #area-cetak h1 { text-align: center; font-size: 16px; margin: 0 0 2px; }
        #area-cetak h2 { text-align: center; font-size: 13px; margin: 0 0 14px; font-weight: normal; }
        #area-cetak .f-head { display: grid; grid-template-columns: 140px 1fr; gap: 4px 8px; margin-bottom: 14px; }
        #area-cetak .f-head b { font-weight: bold; }
        #area-cetak .sect { background: #e5e5e5; font-weight: bold; padding: 5px 8px; margin: 14px 0 8px; font-size: 12px; }
        #area-cetak .q { margin-bottom: 8px; }
        #area-cetak .q .no { font-weight: bold; }
        #area-cetak .jawab { border-bottom: 1px dotted #999; padding: 2px 0 3px 4px; min-height: 14px; }
        #area-cetak .kosong { color: #999; }
        #area-cetak .desc { font-size: 10px; color: #333; text-align: justify; margin-bottom: 8px; }
        #area-cetak .chk { display: inline-block; width: 10px; height: 10px; border: 1.4px solid #111; margin-right: 4px; vertical-align: middle; }
        #area-cetak .chk.checked { background: #111; }
        #area-cetak .kesimpulan-item { margin-bottom: 4px; }
        #area-cetak .ttd { display: flex; justify-content: space-between; margin-top: 40px; text-align: center; }
        #area-cetak .ttd .kolom { width: 45%; }
        #area-cetak .ttd .garis { margin-top: 50px; border-top: 1px solid #111; padding-top: 4px; }
        #area-cetak .dokumen-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-top: 8px; }
        #area-cetak .dokumen-grid div { text-align: center; font-size: 10px; }
        #area-cetak .dokumen-grid img { width: 100%; height: 80px; object-fit: cover; border: 1px solid #ccc; }
        #area-cetak .footer-wm { text-align: center; font-size: 10px; color: #555; margin-top: 20px; letter-spacing: 1px; }
    </style>

    <h1>INTERVIEW CALON PENGELOLA</h1>
    <h2>WARTEG BUMI BAHARI (WBB)</h2>

    <div class="f-head">
        <b>No. Urut</b><span><?= cetak_isi($d['no_urut'] ?? '') ?></span>
        <b>Nama Calon Pengelola</b><span><?= h($d['nama_calon']) ?></span>
        <b>Usia</b><span><?= $d['usia'] ? (int) $d['usia'] . ' Tahun' : '-' ?></span>
        <b>Alamat</b><span><?= cetak_isi($d['alamat'] ?? '') ?></span>
        <b>No. HP</b><span><?= cetak_isi($d['no_hp'] ?? '') ?></span>
        <b>Tanggal Interview</b><span><?= date('d F Y', strtotime($d['tanggal_interview'])) ?></span>
        <b>Interviewer</b><span><?= cetak_isi($d['interviewer'] ?? '') ?></span>
    </div>

    <div class="sect">A. IDENTITAS &amp; PENGALAMAN KERJA</div>
    <div class="q"><div class="no">1. Perkenalan &amp; pengalaman kerja sebelumnya</div><div class="jawab"><?= cetak_isi($d['a_perkenalan']) ?></div></div>
    <div class="q"><div class="no">2. Bekerja/mengelola usaha sebelumnya</div>
        <div class="jawab">Nama tempat/usaha: <?= cetak_isi($d['a_nama_tempat_usaha']) ?> &mdash; Posisi/jabatan: <?= cetak_isi($d['a_posisi_jabatan']) ?> &mdash; Lama bekerja: <?= cetak_isi($d['a_lama_bekerja']) ?></div>
    </div>
    <div class="q"><div class="no">3. Pernah mengelola warteg?</div>
        <div class="jawab"><?= cetak_yt($d['a_pernah_kelola_warteg']) ?><br>
        Lama: <?= cetak_isi($d['a_lama_kelola_warteg']) ?> &mdash; Omzet rata-rata: <?= cetak_isi($d['a_omzet_rata_rata']) ?> &mdash; Omzet tertinggi: <?= cetak_isi($d['a_omzet_tertinggi']) ?></div>
    </div>
    <div class="q"><div class="no">4. Alasan berhenti/keluar</div><div class="jawab"><?= cetak_isi($d['a_alasan_berhenti']) ?></div></div>
    <div class="q"><div class="no">Punya video hasil masakan?</div><div class="jawab"><?= cetak_yt($d['a_video_masakan']) ?></div></div>
    <div class="q"><div class="no">Menu yang dikuasai</div><div class="jawab"><?= cetak_isi($d['a_menu_dikuasai']) ?></div></div>
    <div class="q"><div class="no">Catatan Interviewer</div><div class="jawab"><?= cetak_isi($d['a_catatan_interviewer']) ?></div></div>

    <div class="sect">B. PENGETAHUAN TENTANG WARTEG BUMI BAHARI</div>
    <div class="q"><div class="no">1. Tahu WBB dari mana</div><div class="jawab"><?= cetak_isi($d['b_tahu_dari_mana']) ?></div></div>
    <div class="q"><div class="no">2. Alasan tertarik bergabung</div><div class="jawab"><?= cetak_isi($d['b_alasan_tertarik']) ?></div></div>
    <div class="q"><div class="no">3. Pernah lihat/kunjungi outlet WBB?</div>
        <div class="jawab"><?= cetak_yt($d['b_pernah_kunjungi_outlet']) ?><br>Outlet: <?= cetak_isi($d['b_outlet_mana']) ?> &mdash; Yang diperhatikan: <?= cetak_isi($d['b_yang_diperhatikan']) ?></div>
    </div>
    <div class="q"><div class="no">4. Pendapat agar penjualan outlet baik</div><div class="jawab"><?= cetak_isi($d['b_pendapat_penjualan_baik']) ?></div></div>
    <div class="q"><div class="no">Catatan Interviewer</div><div class="jawab"><?= cetak_isi($d['b_catatan_interviewer']) ?></div></div>

    <div class="sect">C. PENJELASAN SISTEM &amp; KARAKTER WBB</div>
    <div class="desc">WBB tidak hanya berorientasi membuka warung, tetapi membangun perusahaan dan jaringan usaha yang kuat serta berkelanjutan. Lokasi outlet dipilih selektif berdasarkan potensi pasar, kepadatan konsumen, lingkungan, akses, dan peluang omzet. Pengelola harus siap mengikuti sistem, SOP, evaluasi, dan arahan manajemen.</div>
    <div class="q"><div class="no">Jawaban kesiapan ikuti standar &amp; kebijakan Manajemen WBB</div><div class="jawab"><?= cetak_isi($d['c_jawaban_kesiapan']) ?></div></div>

    <div class="sect">D. KOMITMEN &amp; JENJANG KARIER PENGELOLA</div>
    <div class="desc">Evaluasi berdasarkan: 1) Komunikatif, 2) Kemampuan memasak, 3) Disiplin &amp; dapat diarahkan, 4) Kemampuan mengelola outlet, 5) Integritas &amp; tanggung jawab.</div>

    <div class="sect">E. PELUANG PENEMPATAN OUTLET</div>
    <div class="desc">Pengelola berkinerja baik dapat dipertimbangkan mengelola outlet berpotensi omzet lebih tinggi, berdasarkan kinerja, kesiapan, kemampuan, dan kebutuhan operasional perusahaan.</div>

    <div class="sect">F. PERTANYAAN KOMITMEN AKHIR</div>
    <div class="q"><div class="no">1. Siap ikuti SOP &amp; arahan manajemen?</div><div class="jawab"><?= cetak_yt($d['f_siap_sop']) ?></div></div>
    <div class="q"><div class="no">2. Siap dievaluasi berkala?</div><div class="jawab"><?= cetak_yt($d['f_siap_evaluasi']) ?></div></div>
    <div class="q"><div class="no">3. Siap ditempatkan/dipindahkan?</div><div class="jawab"><?= cetak_yt($d['f_siap_dipindah']) ?></div></div>
    <div class="q"><div class="no">4. Siap jaga kualitas masakan/pelayanan/kebersihan/laporan?</div><div class="jawab"><?= cetak_yt($d['f_siap_jaga_kualitas']) ?></div></div>
    <div class="q"><div class="no">5. Rencana tingkatkan penjualan (outlet omzet besar)</div><div class="jawab"><?= cetak_isi($d['f_rencana_tingkatkan_penjualan']) ?></div></div>
    <div class="q"><div class="no">6. Target bergabung dengan WBB</div><div class="jawab"><?= cetak_isi($d['f_target_bergabung']) ?></div></div>

    <div class="sect">KESIMPULAN INTERVIEWER</div>
    <?php
    $opsi_kesimpulan = [
        'direkomendasikan' => 'Direkomendasikan',
        'dipertimbangkan' => 'Dipertimbangkan / Tes Lanjutan',
        'tes_memasak' => 'Tes Memasak',
        'belum_direkomendasikan' => 'Belum Direkomendasikan',
    ];
    foreach ($opsi_kesimpulan as $key => $label):
        $checked = ($d['kesimpulan_interviewer'] ?? '') === $key;
    ?>
        <div class="kesimpulan-item"><span class="chk<?= $checked ? ' checked' : '' ?>"></span> <?= h($label) ?></div>
    <?php endforeach; ?>
    <div class="q" style="margin-top:8px;"><div class="no">Catatan</div><div class="jawab"><?= cetak_isi($d['catatan_kesimpulan']) ?></div></div>

    <div class="ttd">
        <div class="kolom"><div class="garis"><?= h($d['interviewer'] ?: '.....................') ?></div>Interviewer</div>
        <div class="kolom"><div class="garis"><?= h($d['nama_calon']) ?></div>Calon Pengelola</div>
    </div>

    <?php
    $ada_dokumen = false;
    foreach ($UPLOAD_FIELDS as $f) { if (!empty($d[$f])) { $ada_dokumen = true; break; } }
    if ($ada_dokumen):
    ?>
    <div class="sect" style="margin-top:20px;">LAMPIRAN DOKUMEN</div>
    <div class="dokumen-grid">
        <?php foreach ($UPLOAD_FIELDS as $f): if (empty($d[$f])) continue; ?>
            <div>
                <img src="<?= h($UPLOAD_DIR . $d[$f]) ?>" crossorigin="anonymous">
                <div><?= h($UPLOAD_LABEL[$f]) ?></div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="footer-wm">WARTEG BUMI BAHARI MANAGEMENT</div>
</div>
<?php endif; ?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script>
<?php if ($id_calon):
    // Nama file: "No Urut - Nama Pengelola - Tanggal Interview". No_urut sering
    // berisi "/" (mis. "001/HRD/IX") yang tidak boleh ada di nama file -> ganti "-".
    function nama_file_aman(string $s): string {
        $s = str_replace(['/', '\\'], '-', $s);
        return preg_replace('/[<>:"|?*]/', '', $s);
    }
    $bagian_no_urut = $d['no_urut'] !== null && $d['no_urut'] !== '' ? nama_file_aman($d['no_urut']) : '-';
    $bagian_nama    = nama_file_aman($d['nama_calon']);
    $bagian_tanggal = date('d-m-Y', strtotime($d['tanggal_interview']));
    $nama_file_cetak = "$bagian_no_urut - $bagian_nama - $bagian_tanggal.pdf";
?>
const CETAK_FILENAME = <?= json_encode($nama_file_cetak) ?>;

function cetakPdfOpt() {
    return {
        margin: [10, 10, 10, 10],
        filename: CETAK_FILENAME,
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: { scale: 2, useCORS: true, logging: false, backgroundColor: '#ffffff' },
        jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
        pagebreak: { mode: ['avoid-all', 'css', 'legacy'] }
    };
}

// Tunggu SEMUA <img> di area cetak selesai dimuat & di-decode sebelum
// html2canvas membaca DOM-nya -- foto dokumen (KTP/KK/dst) ukurannya bisa
// ratusan KB, kalau belum selesai dimuat saat capture, hasilnya bisa
// kosong/blank (terutama di koneksi HP yang lebih lambat dari server).
async function tungguGambarSiap(area) {
    const imgs = Array.from(area.querySelectorAll('img'));
    await Promise.all(imgs.map(function (img) {
        if (img.complete && img.naturalWidth > 0) return Promise.resolve();
        return new Promise(function (resolve) {
            img.addEventListener('load', resolve, { once: true });
            img.addEventListener('error', resolve, { once: true }); // jangan sampai macet gara-gara 1 foto rusak
            setTimeout(resolve, 8000); // jaring pengaman kalau event tidak pernah muncul
        });
    }));
}

async function cetakPDF(btn) {
    const area = document.getElementById('area-cetak');
    if (btn) { btn.disabled = true; }
    area.style.display = 'block';
    try {
        await tungguGambarSiap(area);
        await html2pdf().set(cetakPdfOpt()).from(area).save();
    } catch (e) {
        alert('Gagal membuat PDF. Coba lagi.');
    } finally {
        area.style.display = 'none';
        if (btn) btn.disabled = false;
    }
}

async function bagikanWA(btn) {
    const area = document.getElementById('area-cetak');
    if (btn) { btn.disabled = true; }
    area.style.display = 'block';

    const teks = CETAK_FILENAME.replace(/\.pdf$/i, '');
    try {
        await tungguGambarSiap(area);
        const blob = await html2pdf().set(cetakPdfOpt()).from(area).outputPdf('blob');
        area.style.display = 'none';
        if (btn) btn.disabled = false;

        const file = new File([blob], CETAK_FILENAME, { type: 'application/pdf' });
        if (navigator.canShare && navigator.canShare({ files: [file] })) {
            try {
                await navigator.share({ files: [file], title: 'Interview Calon Pengelola', text: teks });
                return;
            } catch (e) {
                if (e && e.name === 'AbortError') return;
            }
        }
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = CETAK_FILENAME;
        a.click();
        window.open('https://wa.me/?text=' + encodeURIComponent(teks + ' (PDF terlampir, silakan unggah manual)'), '_blank');
    } catch (e) {
        area.style.display = 'none';
        if (btn) btn.disabled = false;
        alert('Gagal membuat PDF. Coba lagi.');
    }
}

<?php if (isset($_GET['saved'])): ?>
document.addEventListener('DOMContentLoaded', function () {
    alert('Data berhasil disimpan!');
});
<?php endif; ?>
<?php endif; ?>
</script>
