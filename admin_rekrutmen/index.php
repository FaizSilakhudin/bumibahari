<?php
require '../config/koneksi.php';
include 'sidebar.php';

// 1. PROTEKSI ROLE REKRUTMEN
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'rekrutmen') {
    header("Location: ../login");
    exit;
}

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

// 2. PROSES HAPUS (tambah & edit sekarang di form_calon.php, halaman penuh)
if (isset($_POST['hapus'])) {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        echo "<script>alert('Token CSRF tidak valid!'); history.back();</script>";
        exit;
    }
    $id_calon = (int) $_POST['id_calon'];
    $nama_dihapus = $conn->query("SELECT nama_calon FROM calon_pengelola WHERE id=" . (int) $id_calon)->fetch_assoc()['nama_calon'] ?? null;

    $del = $conn->prepare("DELETE FROM calon_pengelola WHERE id = ?");
    $del->bind_param("i", $id_calon);
    $del->execute();
    $del->close();

    audit($conn, 'calon_pengelola_hapus', 'calon_pengelola', $id_calon, ['nama_calon' => $nama_dihapus]);

    echo "<script>alert('Data calon pengelola berhasil dihapus'); window.location='index';</script>";
    exit;
}

// --- FILTER & PAGINATION ---
$search = trim($_GET['search'] ?? '');
$where_sql = "";
$params = [];
$types = "";

if ($search !== '') {
    $where_sql = " WHERE cp.nama_calon LIKE ? OR cp.interviewer LIKE ? OR cp.no_hp LIKE ? OR cp.no_urut LIKE ? ";
    $search_param = "%{$search}%";
    $params = [$search_param, $search_param, $search_param, $search_param];
    $types = "ssss";
}

$limit  = 20;
$page   = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

$sql_count = "SELECT COUNT(*) total FROM calon_pengelola cp $where_sql";
$stmt_count = $conn->prepare($sql_count);
if ($search !== '') $stmt_count->bind_param($types, ...$params);
$stmt_count->execute();
$total_data = (int) $stmt_count->get_result()->fetch_assoc()['total'];
$stmt_count->close();
$total_pages = max(1, (int) ceil($total_data / $limit));

$sql = "SELECT cp.*, u.username AS nama_input
        FROM calon_pengelola cp
        LEFT JOIN users u ON u.id = cp.id_user_input
        $where_sql
        ORDER BY cp.created_at DESC LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
if ($search !== '') {
    $fetch_types = $types . "ii";
    $fetch_params = array_merge($params, [$limit, $offset]);
    $stmt->bind_param($fetch_types, ...$fetch_params);
} else {
    $stmt->bind_param("ii", $limit, $offset);
}
$stmt->execute();
$calon_list = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>

<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">

<style>
    body { background-color: #f4f7fe !important; font-family: 'Plus Jakarta Sans', sans-serif !important; }

    .saas-card { background: #ffffff; border: none !important; border-radius: 20px !important; box-shadow: 0px 18px 40px rgba(112, 144, 176, 0.06) !important; padding: 20px; }
    .title-mark { width: 12px; height: 12px; background-color: #0d9488; border-radius: 4px; display: inline-block; margin-right: 10px; }

    .btn-premium { background-color: #0d9488 !important; color: #ffffff !important; border: none !important; padding: 10px 20px; border-radius: 12px; font-weight: 600; font-size: 14px; transition: all 0.2s ease; text-decoration: none; display: inline-flex; align-items: center; }
    .btn-premium:hover { background-color: #0f766e !important; color: #fff !important; transform: translateY(-1px); box-shadow: 0px 8px 20px rgba(13, 148, 136, 0.2); }
    .btn-premium-outline { background-color: #f0fdfa !important; color: #0d9488 !important; border: 1px solid #99f6e4 !important; padding: 10px 16px; border-radius: 12px; font-weight: 600; font-size: 14px; }
    .btn-premium-outline:hover { background-color: #ccfbf1 !important; }

    .form-control-premium, .form-select-premium { border-radius: 12px !important; border: 1px solid #e0e7ff !important; padding: 10px 16px; color: #1b2559; font-size: 14px; background-color: #ffffff; }
    .form-control-premium:focus, .form-select-premium:focus { border-color: #0d9488 !important; box-shadow: 0 0 0 4px rgba(13, 148, 136, 0.1) !important; }

    .btn-action-edit { background-color: #fff3cd; color: #856404; border: none; padding: 6px 12px; border-radius: 8px; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; cursor: pointer; }
    .btn-action-edit:hover { background-color: #ffe8a1; color: #856404; }
    .btn-action-delete { background-color: #fde8e8; color: #ef4444; border: none; padding: 6px 12px; border-radius: 8px; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; }
    .btn-action-delete:hover { background-color: #fbd5d5; }

    .table-saas { margin-bottom: 0; width: 100% !important; }
    .table-saas thead th { background-color: #f8f9fc !important; color: #8f9bba !important; font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #eef2f9 !important; padding: 14px 12px; }
    .table-saas tbody td { padding: 14px 12px; border-bottom: 1px solid #f4f7fe !important; color: #2b3674; font-size: 14px; vertical-align: middle; }

    .mobile-card { display: none; }
    @media (max-width: 768px) {
        .table-desktop { display: none; }
        .mobile-card { display: block; }
        .search-container { width: 100% !important; }
        .search-container input { width: 100% !important; }
        .btn-premium { width: 100%; justify-content: center; }
        .saas-card { padding: 12px; }
    }
</style>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <div class="d-flex align-items-center">
                <span class="title-mark"></span>
                <h3 class="fw-bold mb-0" style="color: #1b2559;">Data Calon Pengelola</h3>
            </div>
            <span class="text-muted small ms-4">Hasil interview calon pengelola Warteg Bumi Bahari</span>
        </div>
    </div>

    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-stretch align-items-sm-center gap-3 mb-4">
        <a href="form_calon" class="btn btn-premium d-flex align-items-center gap-2">
            <i class="bi bi-person-plus-fill"></i> Tambah Calon Pengelola
        </a>

        <form method="GET" class="d-flex gap-2 search-container">
            <input type="text" name="search" class="form-control form-control-premium" placeholder="Cari no urut, nama, interviewer, no HP..." value="<?= h($search) ?>">
            <button type="submit" class="btn btn-premium-outline"><i class="bi bi-search"></i></button>
            <?php if ($search !== ''): ?>
                <a href="index" class="btn btn-premium-outline bg-white text-secondary"><i class="bi bi-arrow-clockwise"></i></a>
            <?php endif; ?>
        </form>
    </div>

    <div class="card saas-card p-0 overflow-hidden border-0">
        <div class="table-responsive table-desktop">
            <table class="table table-saas align-middle mb-0">
                <thead>
                    <tr>
                        <th width="4%" class="text-center">No</th>
                        <th width="8%">No. Urut</th>
                        <th width="16%">Nama Calon</th>
                        <th width="9%">Tgl Interview</th>
                        <th width="12%">Interviewer</th>
                        <th width="15%">Kesimpulan Interviewer</th>
                        <th width="13%">Status Tindak Lanjut</th>
                        <th width="10%">Diinput Oleh</th>
                        <th width="13%" class="text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($calon_list)): ?>
                    <tr>
                        <td colspan="9" class="text-center py-5 text-muted fw-semibold">
                            <i class="bi bi-inbox fs-2 d-block mb-2"></i> Belum ada data calon pengelola
                        </td>
                    </tr>
                    <?php else: $no = $offset + 1; foreach ($calon_list as $d): ?>
                    <tr>
                        <td class="text-center text-muted fw-semibold"><?= $no++ ?></td>
                        <td><?= h($d['no_urut'] ?: '-') ?></td>
                        <td><span class="fw-bold text-dark"><?= h($d['nama_calon']) ?></span></td>
                        <td><?= date('d/m/Y', strtotime($d['tanggal_interview'])) ?></td>
                        <td><?= h($d['interviewer'] ?: '-') ?></td>
                        <td><span class="badge <?= kesimpulan_badge_class($d['kesimpulan_interviewer']) ?> px-3 py-2 rounded-pill"><?= kesimpulan_label($d['kesimpulan_interviewer']) ?></span></td>
                        <td><span class="badge <?= status_badge_class($d['status_tindak_lanjut']) ?> px-3 py-2 rounded-pill"><?= status_label($d['status_tindak_lanjut']) ?></span></td>
                        <td class="text-muted small"><?= h($d['nama_input'] ?? '-') ?></td>
                        <td class="text-center">
                            <div class="d-inline-flex gap-2 justify-content-center">
                                <a href="form_calon?id=<?= (int) $d['id'] ?>" class="btn-action-edit"><i class="bi bi-eye-fill"></i></a>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Yakin menghapus data <?= h($d['nama_calon']) ?>?')">
                                    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                                    <input type="hidden" name="id_calon" value="<?= (int) $d['id'] ?>">
                                    <button type="submit" name="hapus" class="btn btn-action-delete"><i class="bi bi-trash-fill"></i></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <div class="mobile-card p-3">
            <?php if (empty($calon_list)): ?>
                <div class="text-center py-4 text-muted"><i class="bi bi-inbox fs-2 d-block mb-2"></i> Belum ada data calon pengelola</div>
            <?php else: foreach ($calon_list as $d): ?>
                <div class="bg-light p-3 rounded-4 mb-3 border">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="fw-bold text-dark"><?= h($d['nama_calon']) ?></span>
                        <span class="badge <?= status_badge_class($d['status_tindak_lanjut']) ?>"><?= status_label($d['status_tindak_lanjut']) ?></span>
                    </div>
                    <div class="text-muted small mb-1">No. <?= h($d['no_urut'] ?: '-') ?> &middot; <?= date('d/m/Y', strtotime($d['tanggal_interview'])) ?> &middot; <?= h($d['interviewer'] ?: '-') ?></div>
                    <div class="mb-3"><span class="badge <?= kesimpulan_badge_class($d['kesimpulan_interviewer']) ?>"><?= kesimpulan_label($d['kesimpulan_interviewer']) ?></span></div>
                    <div class="d-flex gap-2">
                        <a href="form_calon?id=<?= (int) $d['id'] ?>" class="btn btn-action-edit flex-fill py-2"><i class="bi bi-eye-fill me-1"></i> Lihat / Edit</a>
                        <form method="POST" class="flex-fill" onsubmit="return confirm('Yakin menghapus data <?= h($d['nama_calon']) ?>?')">
                            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                            <input type="hidden" name="id_calon" value="<?= (int) $d['id'] ?>">
                            <button type="submit" name="hapus" class="btn btn-action-delete w-100 py-2"><i class="bi bi-trash-fill"></i></button>
                        </form>
                    </div>
                </div>
            <?php endforeach; endif; ?>
        </div>

        <?php render_pagination($page, $total_pages, ['from' => $offset + 1, 'to' => min($offset + $limit, $total_data), 'total' => $total_data, 'label' => 'calon pengelola']); ?>
    </div>
</div>
