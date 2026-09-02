<?php
require '../config/koneksi.php';
include 'sidebar.php';

$id_cabang = (int) $_SESSION['id_cabang'];

// Tanggal laporan = 1 hari sebelum hari input (cabang tidak bisa memilih tanggal).
$tgl = date('Y-m-d', strtotime('-1 day'));

// =====================================================
// IDENTITAS CABANG + PENGELOLA PADA TANGGAL LAPORAN
// (bukan pengelola aktif sekarang — supaya rotasi pengelola tidak menimpa
//  atribusi laporan; lihat config/koneksi.php: pengelola_pada_tanggal()).
// =====================================================
$stmt = $conn->prepare("SELECT nama_cabang FROM cabang WHERE id_cabang = ?");
$stmt->bind_param("i", $id_cabang);
$stmt->execute();
$data_cabang = $stmt->get_result()->fetch_assoc();
$stmt->close();

$cabang = $data_cabang['nama_cabang'] ?? '-';
$nama_pengelola = pengelola_pada_tanggal($conn, $id_cabang, $tgl);
$_SESSION['nama_pengelola'] = $nama_pengelola;

// Status nota untuk tanggal ini (kalau sudah pernah kirim / sudah diproses PIC).
$stmt = $conn->prepare("SELECT foto_nota1, foto_nota2, foto_nota3, foto_nota4, status_laporan, keterangan_nota
                         FROM laporan_cabang WHERE id_cabang = ? AND tanggal = ?");
$stmt->bind_param("is", $id_cabang, $tgl);
$stmt->execute();
$laporan_hari_ini = $stmt->get_result()->fetch_assoc();
$stmt->close();

$status_saat_ini = $laporan_hari_ini['status_laporan'] ?? null;

// =====================================================
// PROSES TANDAI LIBUR / TUTUP
// =====================================================
if (isset($_POST['tandai_libur'])) {

    if (!csrf_check($_POST['csrf'] ?? '')) {
        die("<script>alert('Token tidak valid!'); history.back();</script>");
    }

    if ($status_saat_ini === 'lengkap') {
        echo "<script>alert('Laporan tanggal ini sudah diproses PIC, tidak bisa ditandai libur.'); history.back();</script>";
        exit;
    }

    $id_user_nota = current_user_id();
    $stmt = $conn->prepare("
        INSERT INTO laporan_cabang (id_cabang, nama_pengelola, tanggal, id_user_nota, status_laporan)
        VALUES (?, ?, ?, ?, 'libur')
        ON DUPLICATE KEY UPDATE
            nama_pengelola = VALUES(nama_pengelola),
            id_user_nota   = VALUES(id_user_nota),
            status_laporan = 'libur'
    ");
    $stmt->bind_param("issi", $id_cabang, $nama_pengelola, $tgl, $id_user_nota);

    if ($stmt->execute()) {
        audit($conn, 'tandai_libur', 'laporan_cabang', $id_cabang . '@' . $tgl, [
            'id_cabang' => $id_cabang, 'tanggal' => $tgl,
        ]);
        echo "<script>
                alert('Tanggal " . $tgl . " ditandai Libur/Tutup.');
                window.location.replace('input_data.php');
              </script>";
        exit;
    } else {
        echo "<script>alert('Gagal simpan: " . addslashes($stmt->error) . "'); history.back();</script>";
        exit;
    }
}

// =====================================================
// PROSES BATALKAN TANDA LIBUR (kembali ke alur kirim nota biasa)
// =====================================================
if (isset($_POST['batalkan_libur'])) {

    if (!csrf_check($_POST['csrf'] ?? '')) {
        die("<script>alert('Token tidak valid!'); history.back();</script>");
    }

    if ($status_saat_ini === 'libur') {
        $stmt = $conn->prepare("DELETE FROM laporan_cabang WHERE id_cabang = ? AND tanggal = ? AND status_laporan = 'libur'");
        $stmt->bind_param("is", $id_cabang, $tgl);
        $stmt->execute();
        audit($conn, 'batalkan_libur', 'laporan_cabang', $id_cabang . '@' . $tgl, [
            'id_cabang' => $id_cabang, 'tanggal' => $tgl,
        ]);
    }

    echo "<script>window.location.replace('input_data.php');</script>";
    exit;
}

// =====================================================
// PROSES SIMPAN (UPLOAD NOTA)
// =====================================================
if (isset($_POST['simpan'])) {

    if ($status_saat_ini === 'libur') {
        echo "<script>alert('Tanggal ini sudah ditandai Libur/Tutup. Batalkan tanda libur dulu untuk mengirim nota.'); history.back();</script>";
        exit;
    }


    if (!csrf_check($_POST['csrf'] ?? '')) {
        die("<script>alert('Token tidak valid!'); history.back();</script>");
    }

    $ket_nota = trim($_POST['keterangan_nota'] ?? '');

    // =================================================
    // UPLOAD AMAN — 4 foto nota
    // =================================================
    $foto = ['', '', '', ''];
    $upload_dir = "../uploads/nota/";

    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    $ada_error_upload = false;

    for ($i = 1; $i <= 4; $i++) {
        if (isset($_FILES["foto_nota$i"]) && $_FILES["foto_nota$i"]['error'] == 0) {

            $tmp_name  = $_FILES["foto_nota$i"]["tmp_name"];
            $file_size = $_FILES["foto_nota$i"]["size"];

            // Cek isi berkas (bukan sekadar nama), MIME sebenarnya dari isi berkas.
            $cek = is_uploaded_file($tmp_name) ? @getimagesize($tmp_name) : false;

            $mime_map = [
                'image/jpeg'  => 'jpg',
                'image/pjpeg' => 'jpg',
                'image/png'   => 'png',
            ];
            $real_mime = is_array($cek) ? ($cek['mime'] ?? '') : '';
            if (function_exists('finfo_open')) {
                $fi = finfo_open(FILEINFO_MIME_TYPE);
                if ($fi) {
                    $real_mime = finfo_file($fi, $tmp_name) ?: $real_mime;
                    finfo_close($fi);
                }
            }
            $ext = $mime_map[$real_mime] ?? '';

            if ($cek !== false && $ext !== '') {
                if ($file_size <= 2000000) {
                    $nama_file = date('Ymd') . "_" . $id_cabang . "_" . uniqid() . "_" . $i . "." . $ext;

                    if (move_uploaded_file($tmp_name, $upload_dir . $nama_file)) {
                        kompres_gambar_upload($upload_dir . $nama_file);
                        $foto[$i - 1] = $nama_file;
                    } else {
                        $ada_error_upload = true;
                        echo "<script>alert('Gagal upload foto nota $i');</script>";
                    }
                } else {
                    $ada_error_upload = true;
                    echo "<script>alert('Foto nota $i terlalu besar. Max 2MB');</script>";
                }
            } else {
                $ada_error_upload = true;
                echo "<script>alert('Format foto nota $i salah. Harus JPG/JPEG/PNG');</script>";
            }
        }
    }

    // Minimal satu foto (baru atau sudah ada sebelumnya) supaya tidak kirim kosong total.
    $ada_foto_baru = ($foto[0] !== '' || $foto[1] !== '' || $foto[2] !== '' || $foto[3] !== '');
    $ada_foto_lama = !empty($laporan_hari_ini['foto_nota1']) || !empty($laporan_hari_ini['foto_nota2'])
        || !empty($laporan_hari_ini['foto_nota3']) || !empty($laporan_hari_ini['foto_nota4']);

    if (!$ada_error_upload && !$ada_foto_baru && !$ada_foto_lama) {
        echo "<script>alert('Unggah minimal 1 foto nota.'); history.back();</script>";
        exit;
    }

    $id_user_nota = current_user_id();

    $sql = "
        INSERT INTO laporan_cabang
            (id_cabang, nama_pengelola, tanggal, foto_nota1, foto_nota2, foto_nota3, foto_nota4,
             keterangan_nota, id_user_nota, status_laporan)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, 'menunggu')
        ON DUPLICATE KEY UPDATE
            nama_pengelola   = VALUES(nama_pengelola),
            foto_nota1       = IF(VALUES(foto_nota1) = '', foto_nota1, VALUES(foto_nota1)),
            foto_nota2       = IF(VALUES(foto_nota2) = '', foto_nota2, VALUES(foto_nota2)),
            foto_nota3       = IF(VALUES(foto_nota3) = '', foto_nota3, VALUES(foto_nota3)),
            foto_nota4       = IF(VALUES(foto_nota4) = '', foto_nota4, VALUES(foto_nota4)),
            keterangan_nota  = VALUES(keterangan_nota),
            id_user_nota     = VALUES(id_user_nota),
            status_laporan   = IF(status_laporan = 'lengkap', 'lengkap', 'menunggu')
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        die("Prepare gagal: " . $conn->error);
    }
    $stmt->bind_param(
        "isssssssi",
        $id_cabang, $nama_pengelola, $tgl,
        $foto[0], $foto[1], $foto[2], $foto[3],
        $ket_nota, $id_user_nota
    );

    if ($stmt->execute()) {
        audit($conn, 'nota_kirim', 'laporan_cabang', $id_cabang . '@' . $tgl, [
            'id_cabang' => $id_cabang, 'tanggal' => $tgl,
        ]);

        echo "<script>
                alert('Nota berhasil dikirim. Terima kasih, PIC akan segera memproses laporan.');
                window.location.replace('input_data.php');
              </script>";
        exit;
    } else {
        echo "<script>alert('Gagal simpan: " . addslashes($stmt->error) . "'); history.back();</script>";
        exit;
    }
}
?>

<style>
body { background-color: #f6f8fa; }
.card-custom { background: #ffffff; border: none; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.04); margin-bottom: 24px; overflow: hidden; }
.card-custom-header { padding: 16px 24px; font-weight: 600; font-size: 1.05rem; border-bottom: 1px solid rgba(0,0,0,0.05); display: flex; align-items: center; gap: 10px; }
.form-label { font-weight: 500; font-size: 0.85rem; color: #4A5568; margin-bottom: 6px; }
.form-control-custom { border-color: #E2E8F0; font-size: 0.95rem; padding: 10px 14px; border-radius: 8px; }
.form-control-custom:focus { border-color: #4A5568; box-shadow: 0 0 0 3px rgba(74, 85, 104, 0.1); }
.bg-header-secondary { background: #F7FAFC; color: #4A5568; }
.badge-info-cabang { background: linear-gradient(135deg, #2D3748 0%, #1A202C 100%); border-radius: 12px; padding: 16px 20px; color: #ffffff; box-shadow: 0 4px 15px rgba(26, 32, 44, 0.1); }
.status-pill { border-radius: 999px; padding: 6px 16px; font-weight: 600; font-size: 0.85rem; display: inline-flex; align-items: center; gap: 6px; }
@media (max-width: 576px) { .card-custom-header { padding: 12px 16px; } .p-mobile-custom { padding: 16px!important; } }
</style>

<div class="container-fluid py-3 px-md-4">

    <div class="d-flex align-items-center justify-content-between mb-4">
        <h3 class="mb-0 fw-bold text-dark">
            <i class="bi bi-camera text-secondary me-2"></i> Kirim Nota Harian
        </h3>
    </div>

    <div class="badge-info-cabang d-flex align-items-center mb-4">
        <div class="bg-light text-dark rounded-circle d-flex align-items-center justify-content-center me-3" style="width:48px;height:48px;">
            <i class="bi bi-shop fs-4 text-dark"></i>
        </div>
        <div>
            <h5 class="mb-0 fw-bold"><?= h($cabang) ?></h5>
            <small class="opacity-75">Nama Pengelola: <strong><?= h($nama_pengelola) ?></strong></small>
        </div>
    </div>

    <?php if ($status_saat_ini === 'lengkap'): ?>
        <div class="alert alert-success d-flex align-items-center rounded-4 mb-4">
            <i class="bi bi-check-circle-fill fs-4 me-3"></i>
            <div>
                <strong>Laporan tanggal <?= date('d M Y', strtotime($tgl)) ?> sudah diproses PIC.</strong>
                Anda tetap boleh mengirim ulang foto nota jika ada koreksi.
            </div>
        </div>
    <?php elseif ($status_saat_ini === 'menunggu'): ?>
        <div class="alert alert-warning d-flex align-items-center rounded-4 mb-4">
            <i class="bi bi-hourglass-split fs-4 me-3"></i>
            <div>Nota tanggal <?= date('d M Y', strtotime($tgl)) ?> sudah terkirim, menunggu diproses PIC.</div>
        </div>
    <?php elseif ($status_saat_ini === 'libur'): ?>
        <div class="alert alert-secondary d-flex align-items-center justify-content-between flex-wrap gap-2 rounded-4 mb-4">
            <div class="d-flex align-items-center">
                <i class="bi bi-moon-stars-fill fs-4 me-3"></i>
                <div>Tanggal <?= date('d M Y', strtotime($tgl)) ?> ditandai <strong>Libur / Tutup</strong>. Tidak perlu kirim nota.</div>
            </div>
            <form method="POST" onsubmit="return confirm('Batalkan tanda libur dan kembali ke pengiriman nota biasa?')">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <button type="submit" name="batalkan_libur" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-arrow-counterclockwise me-1"></i> Batalkan, Kirim Nota
                </button>
            </form>
        </div>
    <?php endif; ?>

    <?php if ($status_saat_ini !== 'libur' && $status_saat_ini !== 'lengkap'): ?>
        <div class="d-flex justify-content-end mb-4">
            <form method="POST" onsubmit="return confirm('Tandai warung LIBUR/TUTUP untuk tanggal <?= date('d M Y', strtotime($tgl)) ?>? Anda tidak perlu kirim nota untuk hari ini.')">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <button type="submit" name="tandai_libur" class="btn btn-outline-dark btn-sm rounded-pill px-3">
                    <i class="bi bi-moon-stars-fill me-1"></i> Warung Libur / Tutup
                </button>
            </form>
        </div>
    <?php endif; ?>

    <?php if ($status_saat_ini !== 'libur'): ?>
    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

        <div class="card card-custom mb-4">
            <div class="card-body p-4 p-mobile-custom">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label"><i class="bi bi-person me-1"></i> Nama Pengelola</label>
                        <input type="text" value="<?= h($nama_pengelola) ?>" class="form-control form-control-custom bg-light" readonly>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label"><i class="bi bi-shop me-1"></i> Nama Cabang</label>
                        <input type="text" value="<?= h($cabang) ?>" class="form-control form-control-custom bg-light" readonly>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label"><i class="bi bi-calendar me-1"></i> Tanggal Laporan</label>
                        <input type="date" value="<?= h($tgl) ?>" class="form-control form-control-custom bg-light" readonly>
                    </div>
                </div>
            </div>
        </div>

        <div class="card card-custom mb-4">
            <div class="card-custom-header bg-header-secondary">
                <i class="bi bi-camera fs-5"></i> Foto Nota &amp; Struk Hari Ini
            </div>
            <div class="card-body p-4 p-mobile-custom">
                <div class="row g-3">
                    <?php for ($i = 1; $i <= 4; $i++):
                        $sudah_ada = !empty($laporan_hari_ini["foto_nota$i"]);
                    ?>
                        <div class="col-6 col-md-3">
                            <div class="p-2 border rounded-3 text-center bg-light">
                                <label class="form-label fw-bold d-block mb-2">
                                    Foto Nota <?= $i ?>
                                    <?php if ($sudah_ada): ?><i class="bi bi-check-circle-fill text-success ms-1" title="Sudah terkirim"></i><?php endif; ?>
                                </label>
                                <input type="file" name="foto_nota<?= $i ?>" class="form-control form-control-sm" accept="image/jpeg,image/png">
                            </div>
                        </div>
                    <?php endfor; ?>
                </div>
                <div class="mt-3 text-muted" style="font-size: 0.8rem;">
                    <i class="bi bi-info-circle me-1"></i>
                    Format JPG/PNG, maksimal 2MB per berkas. Gambar di atas 2MB akan dikompres otomatis. Kosongkan kolom yang tidak ingin diganti.
                    Nota yang sudah pernah terkirim bisa dilihat di menu <a href="riwayat_nota" class="fw-semibold">Riwayat Nota</a>.
                </div>

                <div class="mt-3">
                    <label class="form-label"><i class="bi bi-chat-left-text me-1"></i> Catatan untuk PIC (opsional)</label>
                    <textarea name="keterangan_nota" class="form-control form-control-custom" rows="3" placeholder="Mis. nota rusak, ada retur, dsb."><?= h($laporan_hari_ini['keterangan_nota'] ?? '') ?></textarea>
                </div>
            </div>
        </div>

        <div class="mb-5">
            <button type="submit" name="simpan" class="btn btn-success btn-lg w-100 py-3 shadow-sm fw-bold rounded-3">
                <i class="bi bi-send-check-fill me-2"></i> Kirim Nota ke PIC
            </button>
        </div>
    </form>
    <?php endif; ?>
</div>

<script>
function kompresGambar(file, maxMB = 2, quality = 0.7) {
    return new Promise((resolve) => {
        if (file.size <= maxMB * 1024 * 1024) { resolve(file); return; }
        const reader = new FileReader();
        reader.readAsDataURL(file);
        reader.onload = function (event) {
            const img = new Image();
            img.src = event.target.result;
            img.onload = function () {
                const canvas = document.createElement('canvas');
                let width = img.width, height = img.height;
                const maxDimension = 1920;
                if (width > maxDimension || height > maxDimension) {
                    if (width > height) { height = Math.round((height * maxDimension) / width); width = maxDimension; }
                    else { width = Math.round((width * maxDimension) / height); height = maxDimension; }
                }
                canvas.width = width; canvas.height = height;
                canvas.getContext('2d').drawImage(img, 0, 0, width, height);
                canvas.toBlob((blob) => {
                    resolve(new File([blob], file.name, { type: 'image/jpeg', lastModified: Date.now() }));
                }, 'image/jpeg', quality);
            };
        };
    });
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('input[type="file"][name^="foto_nota"]').forEach(input => {
        input.addEventListener('change', async function(e) {
            const file = e.target.files[0];
            if (!file) return;
            if (file.size > 2 * 1024 * 1024) {
                const fileHasilKompres = await kompresGambar(file, 2, 0.7);
                const dataTransfer = new DataTransfer();
                dataTransfer.items.add(fileHasilKompres);
                e.target.files = dataTransfer.files;
            }
        });
    });
});
</script>
