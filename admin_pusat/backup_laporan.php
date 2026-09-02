<?php
require '../config/koneksi.php';
include 'sidebar_pusat.php';

// -----------------------------------------------------------------------------
// PROTEKSI ROLE PUSAT
// -----------------------------------------------------------------------------
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'pusat') {
    header('Location: ../login');
    exit;
}

// -----------------------------------------------------------------------------
// FILTER: tanggal (default kemarin, sama seperti alur cabang) + pencarian cabang
// -----------------------------------------------------------------------------
$tanggal = $_GET['tanggal'] ?? date('Y-m-d', strtotime('-1 day'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal) || $tanggal > date('Y-m-d')) {
    $tanggal = date('Y-m-d', strtotime('-1 day'));
}
$cari = trim($_GET['cari'] ?? '');
$id_cabang_pilih = (int) ($_GET['id_cabang'] ?? 0);

// -----------------------------------------------------------------------------
// PROSES: cabang yang dipilih untuk backup input (dari POST, divalidasi ulang —
// jangan percaya begitu saja pilihan dari GET/form tersembunyi).
// -----------------------------------------------------------------------------
function ambil_cabang_valid(mysqli $conn, int $id_cabang): ?array
{
    if ($id_cabang <= 0) return null;
    $stmt = $conn->prepare("SELECT id_cabang, nama_cabang FROM cabang WHERE id_cabang = ?");
    $stmt->bind_param("i", $id_cabang);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

if (isset($_POST['tandai_libur'], $_POST['id_cabang'], $_POST['tanggal'])) {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        die("<script>alert('Token tidak valid!'); history.back();</script>");
    }
    $p_id_cabang = (int) $_POST['id_cabang'];
    $p_tanggal   = $_POST['tanggal'];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $p_tanggal) || $p_tanggal > date('Y-m-d') || !ambil_cabang_valid($conn, $p_id_cabang)) {
        echo "<script>alert('Data tidak valid.'); history.back();</script>";
        exit;
    }

    $stmt = $conn->prepare("SELECT status_laporan FROM laporan_cabang WHERE id_cabang = ? AND tanggal = ?");
    $stmt->bind_param("is", $p_id_cabang, $p_tanggal);
    $stmt->execute();
    $status_now = $stmt->get_result()->fetch_assoc()['status_laporan'] ?? null;
    $stmt->close();

    if ($status_now === 'lengkap') {
        echo "<script>alert('Laporan tanggal ini sudah diproses PIC, tidak bisa ditandai libur.'); history.back();</script>";
        exit;
    }

    $nama_pengelola_p = pengelola_pada_tanggal($conn, $p_id_cabang, $p_tanggal);
    $id_user = current_user_id();
    $stmt = $conn->prepare("
        INSERT INTO laporan_cabang (id_cabang, nama_pengelola, tanggal, id_user_nota, status_laporan)
        VALUES (?, ?, ?, ?, 'libur')
        ON DUPLICATE KEY UPDATE
            nama_pengelola = VALUES(nama_pengelola),
            id_user_nota   = VALUES(id_user_nota),
            status_laporan = 'libur'
    ");
    $stmt->bind_param("issi", $p_id_cabang, $nama_pengelola_p, $p_tanggal, $id_user);
    $stmt->execute();
    audit($conn, 'tandai_libur_oleh_pusat', 'laporan_cabang', $p_id_cabang . '@' . $p_tanggal, [
        'id_cabang' => $p_id_cabang, 'tanggal' => $p_tanggal,
    ]);
    echo "<script>window.location.replace('backup_laporan.php?tanggal=$p_tanggal&id_cabang=$p_id_cabang');</script>";
    exit;
}

if (isset($_POST['batalkan_libur'], $_POST['id_cabang'], $_POST['tanggal'])) {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        die("<script>alert('Token tidak valid!'); history.back();</script>");
    }
    $p_id_cabang = (int) $_POST['id_cabang'];
    $p_tanggal   = $_POST['tanggal'];
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $p_tanggal) && ambil_cabang_valid($conn, $p_id_cabang)) {
        $stmt = $conn->prepare("DELETE FROM laporan_cabang WHERE id_cabang = ? AND tanggal = ? AND status_laporan = 'libur'");
        $stmt->bind_param("is", $p_id_cabang, $p_tanggal);
        $stmt->execute();
        audit($conn, 'batalkan_libur_oleh_pusat', 'laporan_cabang', $p_id_cabang . '@' . $p_tanggal, [
            'id_cabang' => $p_id_cabang, 'tanggal' => $p_tanggal,
        ]);
    }
    echo "<script>window.location.replace('backup_laporan.php?tanggal=$p_tanggal&id_cabang=$p_id_cabang');</script>";
    exit;
}

if (isset($_POST['simpan'], $_POST['id_cabang'], $_POST['tanggal'])) {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        die("<script>alert('Token tidak valid!'); history.back();</script>");
    }
    $p_id_cabang = (int) $_POST['id_cabang'];
    $p_tanggal   = $_POST['tanggal'];
    $cabang_valid = ambil_cabang_valid($conn, $p_id_cabang);

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $p_tanggal) || $p_tanggal > date('Y-m-d') || !$cabang_valid) {
        echo "<script>alert('Data tidak valid.'); history.back();</script>";
        exit;
    }

    $stmt = $conn->prepare("SELECT foto_nota1, foto_nota2, foto_nota3, foto_nota4, status_laporan
                             FROM laporan_cabang WHERE id_cabang = ? AND tanggal = ?");
    $stmt->bind_param("is", $p_id_cabang, $p_tanggal);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (($existing['status_laporan'] ?? null) === 'libur') {
        echo "<script>alert('Tanggal ini sudah ditandai Libur/Tutup untuk cabang ini. Batalkan tanda libur dulu untuk kirim nota.'); history.back();</script>";
        exit;
    }

    $ket_nota = trim($_POST['keterangan_nota'] ?? '');

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
                    $nama_file = date('Ymd') . "_" . $p_id_cabang . "_" . uniqid() . "_" . $i . "." . $ext;
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

    $ada_foto_baru = ($foto[0] !== '' || $foto[1] !== '' || $foto[2] !== '' || $foto[3] !== '');
    $ada_foto_lama = !empty($existing['foto_nota1']) || !empty($existing['foto_nota2'])
        || !empty($existing['foto_nota3']) || !empty($existing['foto_nota4']);

    if (!$ada_error_upload && !$ada_foto_baru && !$ada_foto_lama) {
        echo "<script>alert('Unggah minimal 1 foto nota.'); history.back();</script>";
        exit;
    }

    $nama_pengelola_p = pengelola_pada_tanggal($conn, $p_id_cabang, $p_tanggal);
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
    $stmt->bind_param(
        "isssssssi",
        $p_id_cabang, $nama_pengelola_p, $p_tanggal,
        $foto[0], $foto[1], $foto[2], $foto[3],
        $ket_nota, $id_user_nota
    );

    if ($stmt->execute()) {
        audit($conn, 'nota_kirim_oleh_pusat', 'laporan_cabang', $p_id_cabang . '@' . $p_tanggal, [
            'id_cabang' => $p_id_cabang, 'tanggal' => $p_tanggal,
        ]);
        echo "<script>
                alert('Nota berhasil disimpan atas nama cabang " . addslashes($cabang_valid['nama_cabang']) . ". Terima kasih, PIC akan segera memproses laporan.');
                window.location.replace('backup_laporan.php?tanggal=$p_tanggal&id_cabang=$p_id_cabang');
              </script>";
        exit;
    } else {
        echo "<script>alert('Gagal simpan: " . addslashes($stmt->error) . "'); history.back();</script>";
        exit;
    }
}

// -----------------------------------------------------------------------------
// DAFTAR CABANG + STATUS PADA TANGGAL TERPILIH (dengan pencarian & pagination)
// -----------------------------------------------------------------------------
$limit  = 10;
$page   = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

$where_cari  = '';
$cari_params = [];
$cari_types  = '';
if ($cari !== '') {
    $where_cari  = ' AND c.nama_cabang LIKE ?';
    $cari_params = ['%' . $cari . '%'];
    $cari_types  = 's';
}

$stmt = $conn->prepare("SELECT COUNT(*) total FROM cabang c WHERE 1=1 $where_cari");
if ($cari_types) $stmt->bind_param($cari_types, ...$cari_params);
$stmt->execute();
$total_cabang = (int) $stmt->get_result()->fetch_assoc()['total'];
$total_pages  = max(1, (int) ceil($total_cabang / $limit));

$sql = "SELECT c.id_cabang, c.nama_cabang, lc.status_laporan, lc.foto_nota1, lc.foto_nota2, lc.foto_nota3, lc.foto_nota4
        FROM cabang c
        LEFT JOIN laporan_cabang lc ON lc.id_cabang = c.id_cabang AND lc.tanggal = ?
        WHERE 1=1 $where_cari
        ORDER BY c.nama_cabang ASC
        LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
$params_list = array_merge([$tanggal], $cari_params, [$limit, $offset]);
$types_list  = 's' . $cari_types . 'ii';
$stmt->bind_param($types_list, ...$params_list);
$stmt->execute();
$res = $stmt->get_result();

$daftar_cabang = [];
while ($row = $res->fetch_assoc()) {
    $punya_nota = !empty($row['foto_nota1']) || !empty($row['foto_nota2']) || !empty($row['foto_nota3']) || !empty($row['foto_nota4']);
    $row['punya_nota']     = $punya_nota;
    $row['status_efektif'] = $row['status_laporan'] ?? ($punya_nota ? 'menunggu' : null);
    $daftar_cabang[] = $row;
}

// -----------------------------------------------------------------------------
// DETAIL CABANG TERPILIH (untuk form backup input)
// -----------------------------------------------------------------------------
$cabang_terpilih = $id_cabang_pilih ? ambil_cabang_valid($conn, $id_cabang_pilih) : null;
$laporan_terpilih = null;
$nama_pengelola_terpilih = '-';
if ($cabang_terpilih) {
    $nama_pengelola_terpilih = pengelola_pada_tanggal($conn, $id_cabang_pilih, $tanggal);
    $stmt = $conn->prepare("SELECT foto_nota1, foto_nota2, foto_nota3, foto_nota4, status_laporan, keterangan_nota
                             FROM laporan_cabang WHERE id_cabang = ? AND tanggal = ?");
    $stmt->bind_param("is", $id_cabang_pilih, $tanggal);
    $stmt->execute();
    $laporan_terpilih = $stmt->get_result()->fetch_assoc();
}
$status_terpilih = $laporan_terpilih['status_laporan'] ?? null;
?>

<style>
body { background-color: #f6f8fa; }
.card-custom { background: #ffffff; border: none; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.04); margin-bottom: 24px; overflow: hidden; }
.card-custom-header { padding: 16px 24px; font-weight: 600; font-size: 1.05rem; border-bottom: 1px solid rgba(0,0,0,0.05); display: flex; align-items: center; gap: 10px; }
.form-label { font-weight: 500; font-size: 0.85rem; color: #4A5568; margin-bottom: 6px; }
.form-control-custom { border-color: #E2E8F0; font-size: 0.95rem; padding: 10px 14px; border-radius: 8px; }
.form-control-custom:focus { border-color: #4A5568; box-shadow: 0 0 0 3px rgba(74, 85, 104, 0.1); }
.bg-header-secondary { background: #F7FAFC; color: #4A5568; }
.bl-row-picked { background: #eef2ff !important; }
@media (max-width: 576px) { .card-custom-header { padding: 12px 16px; } .p-mobile-custom { padding: 16px!important; } }
</style>

<div class="container-fluid py-3 px-md-4">

    <div class="d-flex align-items-center justify-content-between mb-4">
        <h3 class="mb-0 fw-bold text-dark">
            <i class="bi bi-life-preserver text-secondary me-2"></i> Backup Laporan Cabang
        </h3>
    </div>

    <div class="alert alert-info rounded-4 mb-4">
        <i class="bi bi-info-circle-fill me-2"></i>
        Kalau ada cabang yang lupa kirim nota harian, Admin Pusat bisa mengirimkan nota atas nama cabang tersebut di sini.
        Laporan tetap masuk ke antrian Admin PIC untuk diperiksa &amp; diproses seperti biasa.
    </div>

    <div class="card card-custom mb-4">
        <div class="card-custom-header bg-header-secondary">
            <i class="bi bi-calendar3 fs-5"></i> Pilih Tanggal &amp; Cabang
        </div>
        <div class="card-body p-4 p-mobile-custom">
            <form method="GET" class="row g-3 align-items-end mb-3">
                <div class="col-md-3">
                    <label class="form-label">Tanggal Laporan</label>
                    <input type="date" name="tanggal" value="<?= h($tanggal) ?>" max="<?= date('Y-m-d') ?>" class="form-control form-control-custom">
                </div>
                <div class="col-md-5">
                    <label class="form-label">Cari Nama Cabang</label>
                    <input type="text" name="cari" value="<?= h($cari) ?>" placeholder="Ketik nama cabang..." class="form-control form-control-custom">
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-dark w-100"><i class="bi bi-search me-1"></i> Tampilkan</button>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Cabang</th>
                            <th class="text-center">Status Tanggal <?= date('d/m/Y', strtotime($tanggal)) ?></th>
                            <th class="text-center" width="15%">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($daftar_cabang)): ?>
                            <tr><td colspan="3" class="text-center py-4 text-muted">Tidak ada cabang ditemukan.</td></tr>
                        <?php else: foreach ($daftar_cabang as $row): $dipilih = ($row['id_cabang'] == $id_cabang_pilih); ?>
                            <tr class="<?= $dipilih ? 'bl-row-picked' : '' ?>">
                                <td class="fw-semibold"><?= h($row['nama_cabang']) ?></td>
                                <td class="text-center">
                                    <?php if ($row['status_efektif'] === 'lengkap'): ?>
                                        <span class="badge bg-success-subtle text-success px-3 py-2 rounded-pill"><i class="bi bi-check-circle-fill me-1"></i>Selesai</span>
                                    <?php elseif ($row['status_efektif'] === 'menunggu'): ?>
                                        <span class="badge bg-warning-subtle text-warning px-3 py-2 rounded-pill"><i class="bi bi-hourglass-split me-1"></i>Menunggu PIC</span>
                                    <?php elseif ($row['status_efektif'] === 'libur'): ?>
                                        <span class="badge bg-secondary-subtle text-secondary px-3 py-2 rounded-pill"><i class="bi bi-moon-stars-fill me-1"></i>Libur/Tutup</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger-subtle text-danger px-3 py-2 rounded-pill"><i class="bi bi-exclamation-circle-fill me-1"></i>Belum Lapor</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <a href="?tanggal=<?= h($tanggal) ?>&cari=<?= h(rawurlencode($cari)) ?>&page=<?= $page ?>&id_cabang=<?= (int) $row['id_cabang'] ?>#form-backup"
                                       class="btn btn-sm <?= $dipilih ? 'btn-dark' : 'btn-outline-dark' ?>">
                                        <?= $dipilih ? 'Dipilih' : 'Pilih' ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
            <?php render_pagination($page, $total_pages, ['from' => $offset + 1, 'to' => min($offset + $limit, $total_cabang), 'total' => $total_cabang, 'label' => 'cabang'], 'page', ['tanggal' => $tanggal, 'cari' => $cari, 'id_cabang' => $id_cabang_pilih]); ?>
        </div>
    </div>

    <div id="form-backup"></div>

    <?php if (!$cabang_terpilih): ?>
        <div class="alert alert-light border rounded-4 text-center text-muted py-5">
            <i class="bi bi-hand-index-thumb fs-2 d-block mb-2"></i>
            Pilih salah satu cabang di atas untuk mulai mengirim nota atas nama cabang tersebut.
        </div>
    <?php else: ?>

    <div class="badge-info-cabang d-flex align-items-center mb-4 p-3 rounded-4" style="background: linear-gradient(135deg, #2D3748 0%, #1A202C 100%); color:#fff;">
        <div class="bg-light text-dark rounded-circle d-flex align-items-center justify-content-center me-3" style="width:48px;height:48px;flex-shrink:0;">
            <i class="bi bi-shop fs-4 text-dark"></i>
        </div>
        <div>
            <h5 class="mb-0 fw-bold"><?= h($cabang_terpilih['nama_cabang']) ?></h5>
            <small class="opacity-75">Pengelola: <strong><?= h($nama_pengelola_terpilih) ?></strong> &middot; Tanggal: <strong><?= date('d M Y', strtotime($tanggal)) ?></strong></small>
        </div>
    </div>

    <?php if ($status_terpilih === 'lengkap'): ?>
        <div class="alert alert-success d-flex align-items-center rounded-4 mb-4">
            <i class="bi bi-check-circle-fill fs-4 me-3"></i>
            <div>
                <strong>Laporan tanggal <?= date('d M Y', strtotime($tanggal)) ?> sudah diproses PIC.</strong>
                Anda tetap boleh mengirim ulang foto nota jika ada koreksi.
            </div>
        </div>
    <?php elseif ($status_terpilih === 'menunggu'): ?>
        <div class="alert alert-warning d-flex align-items-center rounded-4 mb-4">
            <i class="bi bi-hourglass-split fs-4 me-3"></i>
            <div>Nota tanggal <?= date('d M Y', strtotime($tanggal)) ?> sudah terkirim, menunggu diproses PIC.</div>
        </div>
    <?php elseif ($status_terpilih === 'libur'): ?>
        <div class="alert alert-secondary d-flex align-items-center justify-content-between flex-wrap gap-2 rounded-4 mb-4">
            <div class="d-flex align-items-center">
                <i class="bi bi-moon-stars-fill fs-4 me-3"></i>
                <div>Tanggal <?= date('d M Y', strtotime($tanggal)) ?> ditandai <strong>Libur / Tutup</strong>. Tidak perlu kirim nota.</div>
            </div>
            <form method="POST" onsubmit="return confirm('Batalkan tanda libur cabang ini dan kembali ke pengiriman nota biasa?')">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="id_cabang" value="<?= (int) $id_cabang_pilih ?>">
                <input type="hidden" name="tanggal" value="<?= h($tanggal) ?>">
                <button type="submit" name="batalkan_libur" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-arrow-counterclockwise me-1"></i> Batalkan, Kirim Nota
                </button>
            </form>
        </div>
    <?php else: ?>
        <div class="alert alert-danger d-flex align-items-center rounded-4 mb-4">
            <i class="bi bi-exclamation-circle-fill fs-4 me-3"></i>
            <div>Cabang ini belum mengirim laporan/nota untuk tanggal <?= date('d M Y', strtotime($tanggal)) ?>.</div>
        </div>
    <?php endif; ?>

    <?php if ($status_terpilih !== 'libur' && $status_terpilih !== 'lengkap'): ?>
        <div class="d-flex justify-content-end mb-4">
            <form method="POST" onsubmit="return confirm('Tandai warung <?= h($cabang_terpilih['nama_cabang']) ?> LIBUR/TUTUP untuk tanggal <?= date('d M Y', strtotime($tanggal)) ?>?')">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="id_cabang" value="<?= (int) $id_cabang_pilih ?>">
                <input type="hidden" name="tanggal" value="<?= h($tanggal) ?>">
                <button type="submit" name="tandai_libur" class="btn btn-outline-dark btn-sm rounded-pill px-3">
                    <i class="bi bi-moon-stars-fill me-1"></i> Tandai Libur / Tutup
                </button>
            </form>
        </div>
    <?php endif; ?>

    <?php if ($status_terpilih !== 'libur'): ?>
    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="id_cabang" value="<?= (int) $id_cabang_pilih ?>">
        <input type="hidden" name="tanggal" value="<?= h($tanggal) ?>">

        <div class="card card-custom mb-4">
            <div class="card-custom-header bg-header-secondary">
                <i class="bi bi-camera fs-5"></i> Foto Nota &amp; Struk &mdash; atas nama <?= h($cabang_terpilih['nama_cabang']) ?>
            </div>
            <div class="card-body p-4 p-mobile-custom">
                <div class="row g-3">
                    <?php for ($i = 1; $i <= 4; $i++):
                        $sudah_ada = !empty($laporan_terpilih["foto_nota$i"]);
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
                </div>

                <div class="mt-3">
                    <label class="form-label"><i class="bi bi-chat-left-text me-1"></i> Catatan untuk PIC (opsional)</label>
                    <textarea name="keterangan_nota" class="form-control form-control-custom" rows="3" placeholder="Mis. diinput pusat karena cabang lupa lapor."><?= h($laporan_terpilih['keterangan_nota'] ?? '') ?></textarea>
                </div>
            </div>
        </div>

        <div class="mb-5">
            <button type="submit" name="simpan" class="btn btn-success btn-lg w-100 py-3 shadow-sm fw-bold rounded-3">
                <i class="bi bi-send-check-fill me-2"></i> Kirim Nota Atas Nama Cabang
            </button>
        </div>
    </form>
    <?php endif; ?>

    <?php endif; ?>
</div>
