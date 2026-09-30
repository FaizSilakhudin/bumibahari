<?php
require '../config/koneksi.php';
include 'sidebar.php';

// Catatan: sidebar.php SUDAH mengirim output HTML, jadi header('Location: ...')
// tidak bisa dipakai lagi di bawah sini -- pakai redirect JS (pola yang sama
// dipakai form_calon.php).
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'rekrutmen') {
    echo "<script>window.location='../login';</script>";
    exit;
}

$id_calon = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if (!$id_calon) {
    echo "<script>window.location='index';</script>";
    exit;
}

$stmt = $conn->prepare("SELECT cp.*, u.username AS nama_input FROM calon_pengelola cp LEFT JOIN users u ON u.id = cp.id_user_input WHERE cp.id = ?");
$stmt->bind_param("i", $id_calon);
$stmt->execute();
$d = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$d) {
    echo "<script>window.location='index';</script>";
    exit;
}

$UPLOAD_DIR = '../uploads/calon_pengelola/';
$UPLOAD_FIELDS = ['foto_ktp', 'foto_kk', 'foto_buku_nikah', 'foto_masakan1', 'foto_masakan2', 'foto_masakan3'];
$UPLOAD_LABEL = [
    'foto_ktp' => 'KTP', 'foto_kk' => 'Kartu Keluarga', 'foto_buku_nikah' => 'Buku Nikah',
    'foto_masakan1' => 'Foto Masakan 1', 'foto_masakan2' => 'Foto Masakan 2', 'foto_masakan3' => 'Foto Masakan 3',
];

function kesimpulan_badge_class(string $k): string {
    switch ($k) {
        case 'direkomendasikan':       return 'bg-success-subtle text-success';
        case 'tes_memasak':            return 'bg-info-subtle text-info';
        case 'belum_direkomendasikan': return 'bg-danger-subtle text-danger';
        default:                       return 'bg-warning-subtle text-warning';
    }
}
function kesimpulan_label(string $k): string {
    switch ($k) {
        case 'direkomendasikan':       return 'Direkomendasikan';
        case 'tes_memasak':            return 'Tes Memasak';
        case 'belum_direkomendasikan': return 'Belum Direkomendasikan';
        default:                       return 'Dipertimbangkan';
    }
}
function status_badge_class(string $s): string {
    switch ($s) {
        case 'diterima': return 'bg-success-subtle text-success';
        case 'ditolak':  return 'bg-danger-subtle text-danger';
        default:         return 'bg-secondary-subtle text-secondary';
    }
}
function status_label(string $s): string {
    switch ($s) {
        case 'diterima': return 'Diterima';
        case 'ditolak':  return 'Ditolak';
        default:         return 'Menunggu';
    }
}
function isi($val) {
    $val = trim((string) $val);
    return $val !== '' ? nl2br(h($val)) : '<span class="lv-kosong">Tidak diisi</span>';
}
function yt($val) {
    if ($val === 'ya') return '<span class="lv-yt lv-ya"><i class="bi bi-check-circle-fill"></i> Ya</span>';
    if ($val === 'tidak') return '<span class="lv-yt lv-tidak"><i class="bi bi-x-circle-fill"></i> Tidak</span>';
    return '<span class="lv-yt lv-na">-</span>';
}

// ---- "Cetak Redaksi": ringkasan teks polos (bukan PDF) untuk dibagikan
// langsung ke WA bareng foto dokumen. Dipakai juga di form_calon.php.
function redaksi_yt($val) {
    return $val === 'ya' ? 'Ya' : ($val === 'tidak' ? 'Tidak' : '-');
}
function redaksi_isi($val) {
    $val = trim((string) $val);
    return $val !== '' ? $val : '-';
}
function bangun_redaksi_teks(array $d): string {
    $opsi_kesimpulan = [
        'direkomendasikan' => 'Direkomendasikan',
        'dipertimbangkan' => 'Dipertimbangkan / Tes Lanjutan',
        'tes_memasak' => 'Tes Memasak',
        'belum_direkomendasikan' => 'Belum Direkomendasikan',
    ];
    $garis = str_repeat('-', 30);
    $baris = [];
    $baris[] = "*FORMULIR INTERVIEW CALON PENGELOLA*";
    $baris[] = "*WARTEG BUMI BAHARI (WBB)*";
    $baris[] = $garis;
    $baris[] = "No. Urut : " . redaksi_isi($d['no_urut'] ?? '');
    $baris[] = "Nama : " . $d['nama_calon'];
    $baris[] = "Usia : " . ($d['usia'] ? (int) $d['usia'] . " Tahun" : '-');
    $baris[] = "Alamat : " . redaksi_isi($d['alamat'] ?? '');
    $baris[] = "No. HP : " . redaksi_isi($d['no_hp'] ?? '');
    $baris[] = "Tanggal Interview : " . date('d F Y', strtotime($d['tanggal_interview']));
    $baris[] = "Interviewer : " . redaksi_isi($d['interviewer'] ?? '');
    $baris[] = $garis;
    $baris[] = "*A. Identitas & Pengalaman Kerja*";
    $baris[] = "1. Perkenalan & pengalaman kerja";
    $baris[] = "   " . redaksi_isi($d['a_perkenalan']);
    $baris[] = "";
    $baris[] = "2. Tempat kerja sebelumnya";
    $baris[] = "   • Nama tempat/usaha : " . redaksi_isi($d['a_nama_tempat_usaha']);
    $baris[] = "   • Posisi/jabatan : " . redaksi_isi($d['a_posisi_jabatan']);
    $baris[] = "   • Lama bekerja : " . redaksi_isi($d['a_lama_bekerja']);
    $baris[] = "";
    $baris[] = "3. Pernah kelola warteg : " . redaksi_yt($d['a_pernah_kelola_warteg']);
    $baris[] = "   • Lama kelola : " . redaksi_isi($d['a_lama_kelola_warteg']);
    $baris[] = "   • Omzet rata-rata : " . redaksi_isi($d['a_omzet_rata_rata']);
    $baris[] = "   • Omzet tertinggi : " . redaksi_isi($d['a_omzet_tertinggi']);
    $baris[] = "";
    $baris[] = "4. Alasan berhenti";
    $baris[] = "   " . redaksi_isi($d['a_alasan_berhenti']);
    $baris[] = "";
    $baris[] = "Video hasil masakan : " . redaksi_yt($d['a_video_masakan']);
    $baris[] = "Menu dikuasai : " . redaksi_isi($d['a_menu_dikuasai']);
    $baris[] = "Catatan interviewer : " . redaksi_isi($d['a_catatan_interviewer']);
    $baris[] = $garis;
    $baris[] = "*B. Pengetahuan tentang Warteg Bumi Bahari*";
    $baris[] = "1. Tahu WBB dari mana";
    $baris[] = "   " . redaksi_isi($d['b_tahu_dari_mana']);
    $baris[] = "";
    $baris[] = "2. Alasan tertarik bergabung";
    $baris[] = "   " . redaksi_isi($d['b_alasan_tertarik']);
    $baris[] = "";
    $baris[] = "3. Pernah kunjungi outlet : " . redaksi_yt($d['b_pernah_kunjungi_outlet']);
    $baris[] = "   • Outlet : " . redaksi_isi($d['b_outlet_mana']);
    $baris[] = "   • Yang diperhatikan : " . redaksi_isi($d['b_yang_diperhatikan']);
    $baris[] = "";
    $baris[] = "4. Pendapat agar penjualan baik";
    $baris[] = "   " . redaksi_isi($d['b_pendapat_penjualan_baik']);
    $baris[] = "";
    $baris[] = "Catatan interviewer : " . redaksi_isi($d['b_catatan_interviewer']);
    $baris[] = $garis;
    $baris[] = "*C. Kesiapan Ikuti Sistem*";
    $baris[] = redaksi_isi($d['c_jawaban_kesiapan']);
    $baris[] = $garis;
    $baris[] = "*F. Pertanyaan Komitmen Akhir*";
    $baris[] = "1. Siap ikuti SOP : " . redaksi_yt($d['f_siap_sop']);
    $baris[] = "2. Siap dievaluasi berkala : " . redaksi_yt($d['f_siap_evaluasi']);
    $baris[] = "3. Siap dipindah tugas : " . redaksi_yt($d['f_siap_dipindah']);
    $baris[] = "4. Siap jaga kualitas : " . redaksi_yt($d['f_siap_jaga_kualitas']);
    $baris[] = "";
    $baris[] = "5. Rencana tingkatkan penjualan";
    $baris[] = "   " . redaksi_isi($d['f_rencana_tingkatkan_penjualan']);
    $baris[] = "";
    $baris[] = "6. Target bergabung dengan WBB";
    $baris[] = "   " . redaksi_isi($d['f_target_bergabung']);
    $baris[] = $garis;
    $baris[] = "*Kesimpulan Interviewer* : " . ($opsi_kesimpulan[$d['kesimpulan_interviewer']] ?? 'Dipertimbangkan / Tes Lanjutan');
    $baris[] = "Catatan : " . redaksi_isi($d['catatan_kesimpulan']);
    $baris[] = "";
    $baris[] = "_WARTEG BUMI BAHARI MANAGEMENT_";
    return implode("\n", $baris);
}

function nama_file_aman(string $s): string {
    $s = str_replace(['/', '\\'], '-', $s);
    return preg_replace('/[<>:"|?*]/', '', $s);
}
$bagian_no_urut = ($d['no_urut'] ?? '') !== '' ? nama_file_aman($d['no_urut']) : '-';
$bagian_nama    = nama_file_aman($d['nama_calon']);
$bagian_tanggal = date('d-m-Y', strtotime($d['tanggal_interview']));
$nama_file_cetak = "$bagian_no_urut - $bagian_nama - $bagian_tanggal.pdf";
?>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">

<style>
    body { background-color: #f4f7fe !important; font-family: 'Plus Jakarta Sans', sans-serif !important; }
    .btn-premium { background-color: #0d9488 !important; color: #fff !important; border: none !important; padding: 10px 20px; border-radius: 12px; font-weight: 600; font-size: 14px; text-decoration: none; display: inline-flex; align-items: center; }
    .btn-premium:hover { background-color: #0f766e !important; color: #fff !important; }
    .btn-premium-outline { background-color: #f0fdfa !important; color: #0d9488 !important; border: 1px solid #99f6e4 !important; padding: 10px 20px; border-radius: 12px; font-weight: 600; font-size: 14px; text-decoration: none; display: inline-flex; align-items: center; }
    .btn-premium-outline:hover { background-color: #ccfbf1 !important; color: #0d9488 !important; }
    .btn-wa { background-color: #25d366 !important; color: #fff !important; border: none !important; padding: 10px 20px; border-radius: 12px; font-weight: 600; font-size: 14px; display: inline-flex; align-items: center; }
    .btn-wa:hover { background-color: #1ebe5b !important; color: #fff !important; }
    .btn-wa:disabled { opacity: .65; }

    /* ===== Kartu profil (header) ===== */
    .lv-hero { background: linear-gradient(135deg, #0d9488 0%, #0f766e 100%); border-radius: 24px; padding: 28px 30px; color: #fff; box-shadow: 0px 18px 40px rgba(13, 148, 136, 0.18); position: relative; overflow: hidden; margin-bottom: 22px; }
    .lv-hero::after { content: ""; position: absolute; right: -40px; top: -40px; width: 180px; height: 180px; border-radius: 50%; background: rgba(255,255,255,0.08); }
    .lv-hero::before { content: ""; position: absolute; left: -30px; bottom: -60px; width: 140px; height: 140px; border-radius: 50%; background: rgba(255,255,255,0.06); }
    .lv-hero-name { font-size: 22px; font-weight: 700; margin: 0; position: relative; z-index: 1; }
    .lv-hero-meta { font-size: 13px; color: rgba(255,255,255,0.85); margin-top: 6px; position: relative; z-index: 1; }
    .lv-hero-badges { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 14px; position: relative; z-index: 1; }
    .lv-hero-badges .badge { font-weight: 600; padding: 7px 14px; font-size: 12.5px; }

    /* ===== Kartu section ===== */
    .lv-sect { background: #fff; border-radius: 20px; box-shadow: 0px 12px 30px rgba(112, 144, 176, 0.07); padding: 22px 24px; margin-bottom: 18px; }
    .lv-sect-head { display: flex; align-items: center; gap: 12px; font-weight: 700; color: #1b2559; font-size: 15px; margin-bottom: 18px; }
    .lv-sect-head .lv-ico { width: 36px; height: 36px; border-radius: 11px; background: #f0fdfa; color: #0d9488; display: inline-flex; align-items: center; justify-content: center; font-size: 16px; flex-shrink: 0; }
    .lv-desc { background: #f0fdfa; border: 1px solid #99f6e4; border-radius: 12px; padding: 14px 18px; font-size: 13px; color: #134e4a; margin-bottom: 16px; line-height: 1.6; }

    .lv-item { margin-bottom: 16px; }
    .lv-item:last-child { margin-bottom: 0; }
    .lv-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: #94a3b8; margin-bottom: 4px; }
    .lv-value { font-size: 14.5px; color: #1b2559; line-height: 1.6; white-space: pre-line; }
    .lv-kosong { color: #cbd5e1; font-style: italic; }

    .lv-yt { display: inline-flex; align-items: center; gap: 5px; padding: 4px 13px; border-radius: 999px; font-size: 12.5px; font-weight: 700; }
    .lv-yt.lv-ya { background: #dcfce7; color: #15803d; }
    .lv-yt.lv-tidak { background: #fee2e2; color: #b91c1c; }
    .lv-yt.lv-na { background: #f1f5f9; color: #94a3b8; }

    .lv-doc-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; }
    .lv-doc { text-align: center; cursor: pointer; }
    .lv-doc .lv-doc-frame { border: 2px dashed #99f6e4; border-radius: 14px; padding: 8px; background: #f0fdfa; transition: transform .15s ease, box-shadow .15s ease; }
    .lv-doc:hover .lv-doc-frame { transform: translateY(-3px); box-shadow: 0 10px 24px rgba(13, 148, 136, 0.18); border-color: #0d9488; }
    .lv-doc img { width: 100%; height: 110px; object-fit: cover; border-radius: 8px; }
    .lv-doc .lv-doc-label { font-size: 12px; color: #0f766e; margin-top: 8px; font-weight: 700; }
    .lv-empty-note { color: #94a3b8; font-size: 13px; font-style: italic; }

    @media (max-width: 576px) {
        .lv-hero { padding: 22px 20px; border-radius: 18px; }
        .lv-sect { padding: 18px; border-radius: 16px; }
        .lv-doc-grid { grid-template-columns: repeat(2, 1fr); }
    }
</style>

<div class="container-fluid py-4" style="max-width: 980px;">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <a href="index" class="btn btn-premium-outline"><i class="bi bi-arrow-left me-1"></i> Kembali ke Daftar</a>
        <div class="d-flex gap-2 flex-wrap">
            <a href="form_calon.php?id=<?= $id_calon ?>" class="btn btn-premium-outline"><i class="bi bi-pencil-square me-1"></i> Edit Data</a>
            <a href="cetak_pdf.php?id=<?= $id_calon ?>" target="_blank" class="btn btn-premium-outline"><i class="bi bi-file-earmark-pdf me-1"></i> Cetak PDF</a>
            <button type="button" class="btn btn-wa" id="btnKirimPdfWa" onclick="kirimPdfWA(this)"><i class="bi bi-whatsapp me-1"></i> Cetak PDF (Kirim ke WA)</button>
            <button type="button" class="btn btn-wa" id="btnCetakRedaksi" onclick="cetakRedaksi(this)"><i class="bi bi-whatsapp me-1"></i> Cetak Redaksi</button>
        </div>
    </div>

    <!-- HERO -->
    <div class="lv-hero">
        <h4 class="lv-hero-name"><?= $d['no_urut'] ? 'No. ' . h($d['no_urut']) . ' — ' : '' ?><?= h($d['nama_calon']) ?></h4>
        <div class="lv-hero-meta">
            <i class="bi bi-calendar-event me-1"></i><?= date('d F Y', strtotime($d['tanggal_interview'])) ?>
            &nbsp;·&nbsp; <i class="bi bi-person-badge me-1"></i>Interviewer: <?= h($d['interviewer'] ?: '-') ?>
            &nbsp;·&nbsp; <i class="bi bi-pencil-square me-1"></i>Diinput: <?= h($d['nama_input'] ?? '-') ?>
        </div>
        <div class="lv-hero-badges">
            <span class="badge rounded-pill <?= kesimpulan_badge_class($d['kesimpulan_interviewer']) ?>"><i class="bi bi-clipboard-check me-1"></i><?= kesimpulan_label($d['kesimpulan_interviewer']) ?></span>
            <span class="badge rounded-pill <?= status_badge_class($d['status_tindak_lanjut']) ?>"><i class="bi bi-flag-fill me-1"></i><?= status_label($d['status_tindak_lanjut']) ?></span>
        </div>
    </div>

    <!-- IDENTITAS -->
    <div class="lv-sect">
        <div class="lv-sect-head"><span class="lv-ico"><i class="bi bi-person-vcard"></i></span> Identitas</div>
        <div class="row">
            <div class="col-md-4 lv-item"><div class="lv-label">Usia</div><div class="lv-value"><?= $d['usia'] ? (int) $d['usia'] . ' tahun' : '<span class="lv-kosong">Tidak diisi</span>' ?></div></div>
            <div class="col-md-4 lv-item"><div class="lv-label">No. HP</div><div class="lv-value"><?= isi($d['no_hp']) ?></div></div>
            <div class="col-md-4 lv-item"><div class="lv-label">Tanggal Interview</div><div class="lv-value"><?= date('d F Y', strtotime($d['tanggal_interview'])) ?></div></div>
            <div class="col-12 lv-item"><div class="lv-label">Alamat</div><div class="lv-value"><?= isi($d['alamat']) ?></div></div>
        </div>
    </div>

    <!-- A -->
    <div class="lv-sect">
        <div class="lv-sect-head"><span class="lv-ico"><i class="bi bi-briefcase"></i></span> A. Identitas &amp; Pengalaman Kerja</div>
        <div class="row">
            <div class="col-12 lv-item"><div class="lv-label">1. Perkenalan &amp; pengalaman kerja sebelumnya</div><div class="lv-value"><?= isi($d['a_perkenalan']) ?></div></div>
            <div class="col-12 lv-item"><div class="lv-label">2. Tempat kerja sebelumnya</div><div class="lv-value"><?= isi(implode(' — ', array_filter([$d['a_nama_tempat_usaha'], $d['a_posisi_jabatan'], $d['a_lama_bekerja']]))) ?></div></div>
            <div class="col-md-6 lv-item"><div class="lv-label">3. Pernah kelola warteg?</div><div class="lv-value"><?= yt($d['a_pernah_kelola_warteg']) ?></div></div>
            <div class="col-md-6 lv-item"><div class="lv-label">Lama / omzet rata-rata / tertinggi</div><div class="lv-value"><?= isi(implode(' / ', array_filter([$d['a_lama_kelola_warteg'], $d['a_omzet_rata_rata'], $d['a_omzet_tertinggi']]))) ?></div></div>
            <div class="col-12 lv-item"><div class="lv-label">4. Alasan berhenti/keluar</div><div class="lv-value"><?= isi($d['a_alasan_berhenti']) ?></div></div>
            <div class="col-md-6 lv-item"><div class="lv-label">Punya video hasil masakan?</div><div class="lv-value"><?= yt($d['a_video_masakan']) ?></div></div>
            <div class="col-md-6 lv-item"><div class="lv-label">Menu yang dikuasai</div><div class="lv-value"><?= isi($d['a_menu_dikuasai']) ?></div></div>
            <div class="col-12 lv-item"><div class="lv-label">Catatan Interviewer</div><div class="lv-value"><?= isi($d['a_catatan_interviewer']) ?></div></div>
        </div>
    </div>

    <!-- B -->
    <div class="lv-sect">
        <div class="lv-sect-head"><span class="lv-ico"><i class="bi bi-lightbulb"></i></span> B. Pengetahuan tentang Warteg Bumi Bahari</div>
        <div class="row">
            <div class="col-12 lv-item"><div class="lv-label">1. Tahu WBB dari mana</div><div class="lv-value"><?= isi($d['b_tahu_dari_mana']) ?></div></div>
            <div class="col-12 lv-item"><div class="lv-label">2. Alasan tertarik bergabung</div><div class="lv-value"><?= isi($d['b_alasan_tertarik']) ?></div></div>
            <div class="col-md-6 lv-item"><div class="lv-label">3. Pernah kunjungi outlet WBB?</div><div class="lv-value"><?= yt($d['b_pernah_kunjungi_outlet']) ?></div></div>
            <div class="col-md-6 lv-item"><div class="lv-label">Outlet / yang diperhatikan</div><div class="lv-value"><?= isi(implode(' — ', array_filter([$d['b_outlet_mana'], $d['b_yang_diperhatikan']]))) ?></div></div>
            <div class="col-12 lv-item"><div class="lv-label">4. Pendapat agar penjualan outlet baik</div><div class="lv-value"><?= isi($d['b_pendapat_penjualan_baik']) ?></div></div>
            <div class="col-12 lv-item"><div class="lv-label">Catatan Interviewer</div><div class="lv-value"><?= isi($d['b_catatan_interviewer']) ?></div></div>
        </div>
    </div>

    <!-- C -->
    <div class="lv-sect">
        <div class="lv-sect-head"><span class="lv-ico"><i class="bi bi-shield-check"></i></span> C. Kesiapan Ikuti Sistem</div>
        <div class="lv-desc">WBB tidak hanya berorientasi membuka warung, tetapi membangun perusahaan dan jaringan usaha yang kuat &amp; berkelanjutan. Pengelola harus siap mengikuti sistem, SOP, evaluasi, dan arahan manajemen.</div>
        <div class="lv-item"><div class="lv-label">Jawaban kesiapan calon</div><div class="lv-value"><?= isi($d['c_jawaban_kesiapan']) ?></div></div>
    </div>

    <!-- F -->
    <div class="lv-sect">
        <div class="lv-sect-head"><span class="lv-ico"><i class="bi bi-clipboard2-check"></i></span> F. Pertanyaan Komitmen Akhir</div>
        <div class="row">
            <div class="col-md-6 lv-item"><div class="lv-label">1. Siap ikuti SOP?</div><div class="lv-value"><?= yt($d['f_siap_sop']) ?></div></div>
            <div class="col-md-6 lv-item"><div class="lv-label">2. Siap dievaluasi berkala?</div><div class="lv-value"><?= yt($d['f_siap_evaluasi']) ?></div></div>
            <div class="col-md-6 lv-item"><div class="lv-label">3. Siap dipindah tugas?</div><div class="lv-value"><?= yt($d['f_siap_dipindah']) ?></div></div>
            <div class="col-md-6 lv-item"><div class="lv-label">4. Siap jaga kualitas?</div><div class="lv-value"><?= yt($d['f_siap_jaga_kualitas']) ?></div></div>
            <div class="col-12 lv-item"><div class="lv-label">5. Rencana tingkatkan penjualan</div><div class="lv-value"><?= isi($d['f_rencana_tingkatkan_penjualan']) ?></div></div>
            <div class="col-12 lv-item"><div class="lv-label">6. Target bergabung dengan WBB</div><div class="lv-value"><?= isi($d['f_target_bergabung']) ?></div></div>
        </div>
    </div>

    <!-- DOKUMEN -->
    <div class="lv-sect">
        <div class="lv-sect-head"><span class="lv-ico"><i class="bi bi-images"></i></span> Dokumen &amp; Foto</div>
        <?php
        $foto_ada = array_values(array_filter($UPLOAD_FIELDS, fn($f) => !empty($d[$f])));
        if ($foto_ada):
        ?>
        <div class="lv-doc-grid">
            <?php foreach ($foto_ada as $f):
                $src = $UPLOAD_DIR . $d[$f];
                $label = $UPLOAD_LABEL[$f];
            ?>
            <div class="lv-doc" data-src="<?= h($src) ?>" data-label="<?= h($label) ?>">
                <div class="lv-doc-frame"><img src="<?= h($src) ?>" alt="<?= h($label) ?>"></div>
                <div class="lv-doc-label"><?= h($label) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
            <div class="lv-empty-note">Belum ada dokumen/foto yang diunggah.</div>
        <?php endif; ?>
    </div>

    <!-- KESIMPULAN -->
    <div class="lv-sect mb-4">
        <div class="lv-sect-head"><span class="lv-ico"><i class="bi bi-flag"></i></span> Kesimpulan &amp; Tindak Lanjut</div>
        <div class="row">
            <div class="col-md-6 lv-item"><div class="lv-label">Kesimpulan Interviewer</div><div class="lv-value"><span class="badge rounded-pill <?= kesimpulan_badge_class($d['kesimpulan_interviewer']) ?>"><?= kesimpulan_label($d['kesimpulan_interviewer']) ?></span></div></div>
            <div class="col-md-6 lv-item"><div class="lv-label">Status Tindak Lanjut (Admin Pusat)</div><div class="lv-value"><span class="badge rounded-pill <?= status_badge_class($d['status_tindak_lanjut']) ?>"><?= status_label($d['status_tindak_lanjut']) ?></span></div></div>
            <div class="col-12 lv-item"><div class="lv-label">Catatan Kesimpulan</div><div class="lv-value"><?= isi($d['catatan_kesimpulan']) ?></div></div>
            <?php if (!empty($d['catatan_pusat'])): ?>
            <div class="col-12 lv-item"><div class="lv-label">Catatan Admin Pusat</div><div class="lv-value"><?= isi($d['catatan_pusat']) ?></div></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- LIGHTBOX FOTO -->
<div class="modal fade" id="lvModalFoto" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content bg-transparent border-0">
            <button type="button" class="btn-close btn-close-white ms-auto mb-2" data-bs-dismiss="modal" aria-label="Close"></button>
            <img id="lvFotoImg" src="" alt="" class="img-fluid rounded-4 shadow" style="max-height: 80vh; object-fit: contain; width: 100%; background:#000;">
            <div class="text-center text-white small mt-2 fw-semibold" id="lvFotoLabel"></div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.querySelectorAll('.lv-doc').forEach(function (el) {
    el.addEventListener('click', function () {
        document.getElementById('lvFotoImg').src = el.dataset.src;
        document.getElementById('lvFotoLabel').innerText = el.dataset.label;
        (bootstrap.Modal.getInstance(document.getElementById('lvModalFoto')) || new bootstrap.Modal(document.getElementById('lvModalFoto'))).show();
    });
});

const CETAK_FILENAME = <?= json_encode($nama_file_cetak) ?>;
const CETAK_URL = <?= json_encode('cetak_pdf.php?id=' . $id_calon) ?>;
const REDAKSI_TEXT = <?= json_encode(bangun_redaksi_teks($d)) ?>;
const REDAKSI_FOTO = <?= json_encode(array_values(array_filter(array_map(function ($f) use ($d, $UPLOAD_LABEL) {
    return empty($d[$f]) ? null : ['src' => $d[$f], 'label' => $UPLOAD_LABEL[$f]];
}, $UPLOAD_FIELDS)))) ?>;
const REDAKSI_UPLOAD_DIR = <?= json_encode($UPLOAD_DIR) ?>;

async function kirimPdfWA(btn) {
    if (btn) { btn.disabled = true; }
    const teks = CETAK_FILENAME.replace(/\.pdf$/i, '');
    try {
        const resp = await fetch(CETAK_URL);
        if (!resp.ok) throw new Error('Gagal mengambil PDF');
        const blob = await resp.blob();
        const file = new File([blob], CETAK_FILENAME, { type: 'application/pdf' });
        if (navigator.canShare && navigator.canShare({ files: [file] })) {
            try {
                await navigator.share({ files: [file], title: 'Interview Calon Pengelola', text: teks });
                return;
            } catch (e) { if (e && e.name === 'AbortError') return; }
        }
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = CETAK_FILENAME;
        a.click();
        window.open('https://wa.me/?text=' + encodeURIComponent(teks + ' (PDF terlampir, silakan unggah manual)'), '_blank');
    } catch (e) {
        alert('Gagal membuat PDF. Coba lagi.');
    } finally {
        if (btn) btn.disabled = false;
    }
}

async function ambilFotoUntukRedaksi() {
    const files = [];
    for (const f of REDAKSI_FOTO) {
        try {
            const resp = await fetch(REDAKSI_UPLOAD_DIR + f.src);
            if (!resp.ok) continue;
            const blob = await resp.blob();
            const ext = (f.src.split('.').pop() || 'jpg').toLowerCase();
            files.push(new File([blob], f.label + '.' + ext, { type: blob.type || 'image/jpeg' }));
        } catch (e) { /* lewati foto yang gagal diambil */ }
    }
    return files;
}

async function kirimTeksRedaksi() {
    if (navigator.share) {
        try {
            await navigator.share({ title: 'Interview Calon Pengelola', text: REDAKSI_TEXT });
            return;
        } catch (e) { if (e && e.name === 'AbortError') return; }
    }
    window.open('https://wa.me/?text=' + encodeURIComponent(REDAKSI_TEXT), '_blank');
}

function resetTombolRedaksi(btn) {
    if (!btn) return;
    delete btn.dataset.tahap;
    btn.innerHTML = '<i class="bi bi-whatsapp me-1"></i> Cetak Redaksi';
}

// Web Share API cuma boleh dipanggil sekali per klik (memakai lalu menghabiskan
// user-activation dari klik itu), jadi tidak bisa kirim foto+teks sebagai 2 share
// beruntun dalam 1x klik. Supaya urutan yang diterima di WA pasti foto dulu baru
// redaksi teks, prosesnya dipecah jadi 2 klik: klik pertama kirim foto lalu
// tombol berubah jadi "Lanjut: Kirim Redaksi", klik kedua baru kirim teksnya.
async function cetakRedaksi(btn) {
    if (btn) { btn.disabled = true; }
    try {
        if (btn && btn.dataset.tahap === 'teks') {
            await kirimTeksRedaksi();
            resetTombolRedaksi(btn);
            return;
        }

        const files = await ambilFotoUntukRedaksi();
        if (!files.length) {
            await kirimTeksRedaksi();
            return;
        }

        if (navigator.canShare && navigator.canShare({ files })) {
            try {
                await navigator.share({ files, title: 'Interview Calon Pengelola' });
            } catch (e) {
                if (e && e.name === 'AbortError') return;
                throw e;
            }
            if (btn) {
                btn.dataset.tahap = 'teks';
                btn.innerHTML = '<i class="bi bi-whatsapp me-1"></i> Lanjut: Kirim Redaksi';
            }
            return;
        }

        window.open('https://wa.me/?text=' + encodeURIComponent(REDAKSI_TEXT), '_blank');
        alert('Browser tidak mendukung kirim foto otomatis. Teks sudah dibuka di WA, silakan lampirkan foto secara manual.');
    } catch (e) {
        alert('Gagal menyiapkan redaksi. Coba lagi.');
    } finally {
        if (btn) btn.disabled = false;
    }
}
</script>
