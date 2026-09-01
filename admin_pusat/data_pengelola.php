<?php
require '../config/koneksi.php';

// 1. PROTEKSI ROLE PUSAT
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'pusat') {
    header("Location: ../login");
    exit;
}

// Helper Functions
if (!function_exists('h')) {
    function h($s) {
        return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token() {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }
}

if (!function_exists('csrf_check')) {
    function csrf_check($t) {
        return hash_equals($_SESSION['csrf'] ?? '', $t);
    }
}

// Parameter Otomatis Modal dari Data Cabang
$open_modal_auto = isset($_GET['open_modal']) && $_GET['open_modal'] == '1';
$selected_id_cabang = isset($_GET['id_cabang']) ? (int)$_GET['id_cabang'] : 0;

// 2. FILTER SEARCH
$search = trim($_GET['search'] ?? '');
$where_sql = "WHERE 1=1";
$params = [];
$types = "";

if ($search !== '') {
    $where_sql .= " AND (p.nama_pengelola LIKE ? OR c.nama_cabang LIKE ? OR p.no_rekening_pengelola LIKE ?)";
    $search_param = "%$search%";
    $params = [$search_param, $search_param, $search_param];
    $types = "sss";
}

// Total Pengelola Overall
$total_pengelola_all = 0;
$res_total = $conn->query("SELECT COUNT(*) as total FROM pengelola");
if ($res_total) {
    $total_pengelola_all = $res_total->fetch_assoc()['total'] ?? 0;
}

// 3. TAMBAH PENGELOLA
if (isset($_POST['tambah'])) {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        $_SESSION['error'] = 'Token CSRF tidak valid!';
        header("Location: data_pengelola");
        exit;
    }

    $id_user            = !empty($_POST['id_user']) ? (int)$_POST['id_user'] : null;
    $id_cabang          = (int)($_POST['id_cabang'] ?? 0);
    $nama_pengelola     = trim($_POST['nama_pengelola'] ?? '');
    $tgl_mulai          = !empty($_POST['tgl_mulai']) ? $_POST['tgl_mulai'] : null;
    $tgl_selesai        = !empty($_POST['tgl_selesai']) ? $_POST['tgl_selesai'] : null;
    $no_rekening        = trim($_POST['no_rekening_pengelola'] ?? '');
    $nama_bank          = trim($_POST['nama_bank_pengelola'] ?? '');
    $atas_nama_rekening = trim($_POST['atas_nama_pengelola'] ?? '');
    $status             = $_POST['status'] ?? 'aktif';

    $stmt = $conn->prepare("INSERT INTO pengelola (id_user, id_cabang, nama_pengelola, tgl_mulai, tgl_selesai, no_rekening_pengelola, nama_bank_pengelola, atas_nama_pengelola, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("iisssssss", $id_user, $id_cabang, $nama_pengelola, $tgl_mulai, $tgl_selesai, $no_rekening, $nama_bank, $atas_nama_rekening, $status);

    if ($stmt->execute()) {
        $new_pengelola_id = $conn->insert_id;
        $stmt->close();
        audit($conn, 'pengelola_tambah', 'pengelola', $new_pengelola_id, ['nama' => $nama_pengelola, 'id_cabang' => $id_cabang]);

        // Alur berantai: kalau belum ada PIC yang dipilih, lanjut ke pembuatan akun User baru.
        if (empty($id_user) && !empty($_POST['lanjut_user'])) {
            $_SESSION['success'] = 'Pengelola tersimpan! Langkah terakhir: buat akun login (User) untuk pengelola ini.';
            header("Location: data_user?open_modal=1"
                . "&id_cabang=" . (int) $id_cabang
                . "&id_pengelola=" . (int) $new_pengelola_id
                . "&nama_pengelola=" . urlencode($nama_pengelola));
            exit;
        }

        $_SESSION['success'] = 'Pengelola berhasil ditambahkan!';
        header("Location: data_pengelola");
        exit;
    } else {
        $error_msg = $stmt->error;
        $stmt->close();
        $_SESSION['error'] = 'Gagal menambahkan data: ' . $error_msg;
        header("Location: data_pengelola");
        exit;
    }
}

// 4. EDIT PENGELOLA
if (isset($_POST['edit'])) {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        $_SESSION['error'] = 'Token CSRF tidak valid!';
        header("Location: data_pengelola");
        exit;
    }

    $id                 = (int)($_POST['id'] ?? 0);
    $id_user            = !empty($_POST['id_user']) ? (int)$_POST['id_user'] : null;
    $id_cabang          = (int)($_POST['id_cabang'] ?? 0);
    $nama_pengelola     = trim($_POST['nama_pengelola'] ?? '');
    $tgl_mulai          = !empty($_POST['tgl_mulai']) ? $_POST['tgl_mulai'] : null;
    $tgl_selesai        = !empty($_POST['tgl_selesai']) ? $_POST['tgl_selesai'] : null;
    $no_rekening        = trim($_POST['no_rekening_pengelola'] ?? '');
    $nama_bank          = trim($_POST['nama_bank_pengelola'] ?? '');
    $atas_nama_rekening = trim($_POST['atas_nama_pengelola'] ?? '');
    $status             = $_POST['status'] ?? 'aktif';

    // Cek dulu: apakah ini benar-benar PERGANTIAN ORANG (id_user/nama beda dari
    // yang tersimpan), bukan cuma koreksi data (typo rekening, dll)? Kalau ganti
    // orang, JANGAN timpa baris lama — itu akan merusak riwayat (laporan lama jadi
    // salah atribusi ke orang baru). Sebagai gantinya: tutup baris lama (kasih
    // tgl_selesai), lalu buat baris BARU untuk orang baru mulai dari tgl_mulai
    // yang diisi di form. Baris lama & riwayatnya tidak pernah disentuh.
    $lama = $conn->prepare("SELECT id_user, nama_pengelola FROM pengelola WHERE id = ?");
    $lama->bind_param("i", $id);
    $lama->execute();
    $data_lama = $lama->get_result()->fetch_assoc();
    $lama->close();

    $ganti_orang = $data_lama && (
        ($data_lama['id_user'] ?? null) != $id_user
        || (empty($data_lama['id_user']) && empty($id_user) && $data_lama['nama_pengelola'] !== $nama_pengelola)
    );

    if ($ganti_orang) {
        $tgl_mulai_baru = $tgl_mulai ?: date('Y-m-d');
        $tgl_selesai_lama = date('Y-m-d', strtotime($tgl_mulai_baru . ' -1 day'));

        $tutup = $conn->prepare("UPDATE pengelola SET tgl_selesai = ?, status = 'nonaktif' WHERE id = ? AND (tgl_selesai IS NULL OR tgl_selesai > ?)");
        $tutup->bind_param("sis", $tgl_selesai_lama, $id, $tgl_selesai_lama);
        $tutup->execute();
        $tutup->close();

        $baru = $conn->prepare("INSERT INTO pengelola (id_user, id_cabang, nama_pengelola, tgl_mulai, tgl_selesai, no_rekening_pengelola, nama_bank_pengelola, atas_nama_pengelola, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $baru->bind_param("iisssssss", $id_user, $id_cabang, $nama_pengelola, $tgl_mulai_baru, $tgl_selesai, $no_rekening, $nama_bank, $atas_nama_rekening, $status);

        if ($baru->execute()) {
            $new_id = $conn->insert_id;
            $baru->close();
            audit($conn, 'pengelola_ganti', 'pengelola', $new_id, ['nama_baru' => $nama_pengelola, 'id_cabang' => $id_cabang, 'menutup_id_lama' => $id, 'mulai' => $tgl_mulai_baru]);
            $_SESSION['success'] = 'Pengelola diganti — riwayat periode sebelumnya tetap tersimpan.';
        } else {
            $error_msg = $baru->error;
            $baru->close();
            $_SESSION['error'] = 'Gagal mengganti pengelola: ' . $error_msg;
        }
        header("Location: data_pengelola");
        exit;
    }

    $stmt = $conn->prepare("UPDATE pengelola SET id_user=?, id_cabang=?, nama_pengelola=?, tgl_mulai=?, tgl_selesai=?, no_rekening_pengelola=?, nama_bank_pengelola=?, atas_nama_pengelola=?, status=? WHERE id=?");
    $stmt->bind_param("iisssssssi", $id_user, $id_cabang, $nama_pengelola, $tgl_mulai, $tgl_selesai, $no_rekening, $nama_bank, $atas_nama_rekening, $status, $id);

    if ($stmt->execute()) {
        $stmt->close();
        audit($conn, 'pengelola_edit', 'pengelola', $id, ['nama' => $nama_pengelola, 'id_cabang' => $id_cabang, 'status' => $status]);
        $_SESSION['success'] = 'Data pengelola berhasil diperbarui!';
        header("Location: data_pengelola");
        exit;
    } else {
        $error_msg = $stmt->error;
        $stmt->close();
        $_SESSION['error'] = 'Gagal memperbarui data: ' . $error_msg;
        header("Location: data_pengelola");
        exit;
    }
}

// 5. HAPUS PENGELOLA
if (isset($_POST['hapus'])) {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        $_SESSION['error'] = 'Token CSRF tidak valid!';
        header("Location: data_pengelola");
        exit;
    }

    $id  = (int)($_POST['id'] ?? 0);
    $del = $conn->prepare("DELETE FROM pengelola WHERE id=?");
    $del->bind_param("i", $id);

    if ($del->execute()) {
        $del->close();
        audit($conn, 'pengelola_hapus', 'pengelola', $id);
        $_SESSION['success'] = 'Pengelola berhasil dihapus!';
        header("Location: data_pengelola");
        exit;
    } else {
        $error_msg = $del->error;
        $del->close();
        $_SESSION['error'] = 'Gagal menghapus data: ' . $error_msg;
        header("Location: data_pengelola");
        exit;
    }
}

// 6. PAGINASI & SELECT DATA PENGELOLA
$limit   = 10;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $limit;

// Count Total Filtered Data
$sql_count = "SELECT COUNT(*) as total FROM pengelola p LEFT JOIN cabang c ON p.id_cabang=c.id_cabang $where_sql";
$stmt_count = $conn->prepare($sql_count);

if ($search !== '') {
    $stmt_count->bind_param($types, ...$params);
}
$stmt_count->execute();
$total_data  = $stmt_count->get_result()->fetch_assoc()['total'] ?? 0;
$stmt_count->close();
$total_pages = ceil($total_data / $limit);

// Main Query
$sql = "SELECT p.*, c.nama_cabang 
        FROM pengelola p 
        LEFT JOIN cabang c ON p.id_cabang = c.id_cabang 
        $where_sql 
        ORDER BY p.id DESC LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);

if ($search !== '') {
    $bind_types = $types . "ii";
    $bind_params = array_merge($params, [$limit, $offset]);
    $stmt->bind_param($bind_types, ...$bind_params);
} else {
    $stmt->bind_param("ii", $limit, $offset);
}
$stmt->execute();
$data = $stmt->get_result();

$rows_data = [];
while ($r = $data->fetch_assoc()) {
    $rows_data[] = $r;
}
$stmt->close();

// AMBIL MASTER DATA CABANG UNTUK OPTION SELECT
$cabang_array = [];
$res_cabang = $conn->query("SELECT id_cabang, nama_cabang FROM cabang ORDER BY nama_cabang ASC");
if ($res_cabang) {
    $cabang_array = $res_cabang->fetch_all(MYSQLI_ASSOC);
}

// AMBIL DAFTAR AKUN PIC YANG SUDAH ADA (untuk assign cabang tambahan ke PIC yang sama)
$pic_array = [];
$res_pic = $conn->query("SELECT id, username FROM users WHERE role = 'pic' AND status = 'aktif' ORDER BY username ASC");
if ($res_pic) {
    $pic_array = $res_pic->fetch_all(MYSQLI_ASSOC);
}

$no = $offset + 1;

include 'sidebar_pusat.php';
?>

<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
    body { background-color: #f4f7fe !important; font-family: 'Plus Jakarta Sans', sans-serif !important; color: #1b2559; }
    .saas-card { background: #ffffff; border: none !important; border-radius: 20px !important; box-shadow: 0px 18px 40px rgba(112, 144, 176, 0.06) !important; padding: 24px; }
    .title-mark { width: 12px; height: 12px; background-color: #4318ff; border-radius: 4px; display: inline-block; margin-right: 10px; }
    .btn-premium { background-color: #4318ff !important; color: #ffffff !important; border: none !important; padding: 10px 20px; border-radius: 12px; font-weight: 600; font-size: 14px; transition: all 0.2s ease; }
    .btn-premium:hover { background-color: #3310cc !important; transform: translateY(-1px); box-shadow: 0px 8px 20px rgba(67, 24, 255, 0.15); }
    .btn-premium-outline { background-color: #ffffff !important; color: #4318ff !important; border: 1px solid #e0e7ff !important; padding: 10px 16px; border-radius: 12px; font-weight: 600; font-size: 14px; transition: all 0.2s ease; }
    .btn-premium-outline:hover { background-color: #e0e7ff !important; }
    .form-control-premium, .form-select-premium { border-radius: 12px !important; border: 1px solid #e0e7ff !important; padding: 10px 16px; color: #1b2559; font-size: 14px; background-color: #ffffff; }
    .form-control-premium:focus, .form-select-premium:focus { border-color: #4318ff !important; box-shadow: 0 0 0 4px rgba(67, 24, 255, 0.1) !important; }
    
    .btn-action-edit { background-color: #fff3cd; color: #856404; border: none; padding: 8px 14px; border-radius: 10px; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; justify-content: center; }
    .btn-action-edit:hover { background-color: #ffe8a1; }
    .btn-action-delete { background-color: #fde8e8; color: #ef4444; border: none; padding: 8px 14px; border-radius: 10px; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; justify-content: center; }
    .btn-action-delete:hover { background-color: #fbd5d5; }
    
    .stat-card { border-radius: 16px; padding: 20px; background: #fff; border: 1px solid #e0e7ff; }
    .stat-card .icon { width: 48px; height: 48px; background: #f4f7fe; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 24px; color: #4318ff; }
    
    .table-saas { margin-bottom: 0; width: 100% !important; }
    .table-saas thead th { background-color: #f8f9fc !important; color: #8f9bba !important; font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #eef2f9 !important; padding: 16px 12px; border-top: none !important; }
    .table-saas tbody td { padding: 16px 12px; border-bottom: 1px solid #f4f7fe !important; color: #1b2559; font-size: 14px; vertical-align: middle; }
    .table-saas tbody tr:hover { background-color: rgba(244, 247, 254, 0.5); }
    
    @media (max-width: 767.98px) {
        .table-saas thead { display: none; }
        .table-saas, .table-saas tbody, .table-saas tr, .table-saas td { display: block; width: 100%; }
        .table-saas tr { margin-bottom: 16px; background: #ffffff; border: 1px solid #e0e7ff !important; border-radius: 16px; padding: 12px 16px; box-shadow: 0px 4px 12px rgba(0,0,0,0.02); }
        .table-saas tr:hover { background-color: #ffffff; }
        .table-saas td { display: flex; justify-content: space-between; align-items: center; padding: 10px 0 !important; border-bottom: 1px dashed #eef2f9 !important; text-align: right; }
        .table-saas td:last-child { border-bottom: none !important; padding-bottom: 0 !important; }
        .table-saas td::before { content: attr(data-label); font-weight: 700; font-size: 12px; color: #8f9bba; text-transform: uppercase; text-align: left; padding-right: 15px; }
        .table-saas td[data-label="Aksi"] { justify-content: flex-end; margin-top: 8px; }
        .action-container { flex-direction: column; align-items: stretch !important; }
        .search-form { width: 100% !important; flex-direction: column; }
        .search-input-group { width: 100% !important; }
    }
</style>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center header-container mb-4">
        <div>
            <div class="d-flex align-items-center">
                <span class="title-mark"></span>
                <h3 class="fw-bold mb-0" style="color: #1b2559; font-size: calc(1.3rem + 0.6vw);">Data Pengelola</h3>
            </div>
            <span class="text-muted small ms-sm-4 d-block mt-1 mt-sm-0">Manajemen profil pengelola cabang</span>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-12 col-sm-6 col-md-4">
            <div class="stat-card d-flex align-items-center gap-3">
                <div class="icon"><i class="bi bi-person-badge"></i></div>
                <div>
                    <div class="text-muted small">Total Pengelola</div>
                    <div class="fw-bold fs-3"><?= number_format($total_pengelola_all) ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-between align-items-center action-container gap-3 mb-4">
        <div class="w-100 w-md-auto">
            <button type="button" class="btn btn-premium d-flex align-items-center gap-2 w-100 justify-content-center" data-bs-toggle="modal" data-bs-target="#modalTambah">
                <i class="bi bi-plus-circle-fill"></i> Tambah Pengelola
            </button>
        </div>
        
        <form method="GET" class="d-flex gap-2 search-form">
            <div class="search-input-group flex-grow-1">
                <input type="text" name="search" class="form-control form-control-premium w-100" placeholder="Cari nama, cabang, no rek..." value="<?= h($search) ?>">
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-premium-outline d-flex align-items-center gap-2 justify-content-center flex-grow-1">
                    <i class="bi bi-search"></i> Cari
                </button>
                <?php if ($search !== ''): ?>
                    <a href="data_pengelola" class="btn btn-premium-outline bg-white text-secondary d-flex align-items-center justify-content-center" title="Reset Search">
                        <i class="bi bi-arrow-clockwise"></i>
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="card saas-card p-0 p-md-3 border-0 bg-transparent bg-md-white shadow-none shadow-md">
        <div class="table-responsive-md">
            <table class="table table-saas align-middle mb-0">
                <thead>
                    <tr>
                        <th width="5%" class="text-center">No</th>
                        <th width="25%">Pengelola</th>
                        <th width="15%">Cabang</th>
                        <th width="20%">Periode</th>
                        <th width="20%">Informasi Bank</th>
                        <th width="10%">Status</th>
                        <th width="10%" class="text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows_data)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted py-5 fw-semibold bg-white rounded-4">
                                <i class="bi bi-inbox fs-2 d-block mb-2"></i> Data pengelola tidak ditemukan
                            </td>
                        </tr>
                    <?php endif; ?>
                    
                    <?php foreach ($rows_data as $row): ?>
                        <tr>
                            <td data-label="No" class="text-center text-muted fw-semibold"><?= $no++ ?></td>
                            <td data-label="Pengelola">
                                <span class="fw-bold d-block" style="color: #1b2559;"><?= h($row['nama_pengelola'] ?? '-') ?></span>
                            </td>
                            <td data-label="Cabang">
                                <span class="fw-semibold text-primary d-block"><?= h($row['nama_cabang'] ?? 'Tanpa Cabang') ?></span>
                            </td>
                            <td data-label="Periode">
                                <small>
                                    <?= !empty($row['tgl_mulai']) ? date('d M Y', strtotime($row['tgl_mulai'])) : '-' ?>
                                    <i class="bi bi-arrow-right"></i>
                                    <?= !empty($row['tgl_selesai']) ? date('d M Y', strtotime($row['tgl_selesai'])) : '<span class="text-success fw-bold">Sekarang</span>' ?>
                                </small>
                            </td>
                            <td data-label="Informasi Bank">
                                <div><small class="fw-semibold"><?= h($row['nama_bank_pengelola'] ?? '-') ?></small> - <small><?= h($row['no_rekening_pengelola'] ?? '-') ?></small></div>
                                <small class="text-muted d-block">a.n. <?= h($row['atas_nama_pengelola'] ?? '-') ?></small>
                            </td>
                            <td data-label="Status">
                                <?php if (strtolower($row['status'] ?? '') == 'aktif'): ?>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">Aktif</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle px-2 py-1"><?= h(ucfirst($row['status'] ?? 'Nonaktif')) ?></span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Aksi" class="text-center">
                                <div class="d-inline-flex gap-2 justify-content-end justify-content-md-center">
                                    <button type="button" class="btn btn-action-edit" data-bs-toggle="modal" data-bs-target="#modalEdit<?= $row['id'] ?>" title="Edit">
                                        <i class="bi bi-pencil-square me-1"></i> Edit
                                    </button>
                                    
                                    <form method="POST" class="d-inline" id="form-delete-<?= $row['id'] ?>">
                                        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                                        <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                        <input type="hidden" name="hapus" value="1">
                                        <button type="button" class="btn btn-action-delete" title="Hapus" onclick="confirmDelete(<?= $row['id'] ?>, '<?= h($row['nama_pengelola']) ?>')">
                                            <i class="bi bi-trash-fill me-1"></i> Hapus
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php render_pagination($page, $total_pages, ['from' => $offset + 1, 'to' => min($offset + $limit, $total_data), 'total' => $total_data, 'label' => 'pengelola']); ?>
    </div>
</div>

<!-- MODAL EDIT PENGELOLA -->
<?php foreach ($rows_data as $row): ?>
    <div class="modal fade modal-premium" id="modalEdit<?= $row['id'] ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <form method="POST">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="id" value="<?= $row['id'] ?>">
                <div class="modal-content">
                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title fw-bold" style="color: #1b2559;">Edit Data Pengelola</h5>
                            <small class="text-muted">Perbarui informasi profil dan rekening pengelola</small>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-muted mb-1">Nama Pengelola</label>
                                <input type="text" name="nama_pengelola" value="<?= h($row['nama_pengelola']) ?>" class="form-control form-control-premium" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-muted mb-1">Akun PIC</label>
                                <select name="id_user" class="form-select-premium w-100">
                                    <option value="">-- Belum ada akun login --</option>
                                    <?php foreach ($pic_array as $pu): ?>
                                        <option value="<?= $pu['id'] ?>" <?= ($row['id_user'] ?? null) == $pu['id'] ? 'selected' : '' ?>><?= h($pu['username']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (!empty($row['id_user']) && !in_array($row['id_user'], array_column($pic_array, 'id'))): ?>
                                    <small class="text-danger">Akun terkait saat ini bukan role PIC atau nonaktif &mdash; cek Data User.</small>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-muted mb-1">Cabang</label>
                                <select name="id_cabang" class="form-select-premium w-100" required>
                                    <option value="">Pilih Cabang</option>
                                    <?php foreach ($cabang_array as $c): ?>
                                        <option value="<?= $c['id_cabang'] ?>" <?= $c['id_cabang'] == $row['id_cabang'] ? 'selected' : '' ?>>
                                            <?= h($c['nama_cabang']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-muted mb-1">Status</label>
                                <select name="status" class="form-select-premium w-100" required>
                                    <option value="aktif" <?= strtolower($row['status'] ?? '') == 'aktif' ? 'selected' : '' ?>>Aktif</option>
                                    <option value="nonaktif" <?= strtolower($row['status'] ?? '') == 'nonaktif' ? 'selected' : '' ?>>Nonaktif</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-muted mb-1">Tanggal Mulai</label>
                                <input type="date" name="tgl_mulai" value="<?= h($row['tgl_mulai']) ?>" class="form-control form-control-premium">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-muted mb-1">Tanggal Selesai</label>
                                <input type="date" name="tgl_selesai" value="<?= h($row['tgl_selesai']) ?>" class="form-control form-control-premium">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-muted mb-1">Nama Bank</label>
                                <input type="text" name="nama_bank_pengelola" value="<?= h($row['nama_bank_pengelola']) ?>" class="form-control form-control-premium" placeholder="BCA / Mandiri / dll">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-muted mb-1">No Rekening</label>
                                <input type="text" name="no_rekening_pengelola" value="<?= h($row['no_rekening_pengelola']) ?>" class="form-control form-control-premium">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-muted mb-1">Atas Nama Rekening</label>
                                <input type="text" name="atas_nama_pengelola" value="<?= h($row['atas_nama_pengelola']) ?>" class="form-control form-control-premium">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-premium-outline text-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="edit" class="btn btn-premium">Simpan Perubahan</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
<?php endforeach; ?>

<!-- MODAL TAMBAH PENGELOLA -->
<div class="modal fade modal-premium" id="modalTambah" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form method="POST">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title fw-bold" style="color: #1b2559;">Tambah Pengelola Baru</h5>
                        <small class="text-muted">Isi profil lengkap pengelola baru &mdash; pengelola = penugasan PIC ke satu cabang</small>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted mb-1">Nama Pengelola</label>
                            <input type="text" name="nama_pengelola" class="form-control form-control-premium" placeholder="Nama lengkap" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted mb-1">Akun PIC</label>
                            <select name="id_user" id="tambah_id_user" class="form-select-premium w-100" onchange="toggleLanjutUser()">
                                <option value="">-- Buat akun PIC baru --</option>
                                <?php foreach ($pic_array as $pu): ?>
                                    <option value="<?= $pu['id'] ?>"><?= h($pu['username']) ?> (PIC sudah ada)</option>
                                <?php endforeach; ?>
                            </select>
                            <input type="hidden" name="lanjut_user" id="tambah_lanjut_user" value="<?= $open_modal_auto ? '1' : '0' ?>">
                            <small class="text-muted">Pilih PIC yang sudah ada untuk menambah cabang ke orang yang sama, atau biarkan untuk membuat akun baru.</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted mb-1">Cabang</label>
                            <select name="id_cabang" class="form-select-premium w-100" required>
                                <option value="">Pilih Cabang</option>
                                <?php foreach ($cabang_array as $c): ?>
                                    <option value="<?= $c['id_cabang'] ?>" <?= ($selected_id_cabang == $c['id_cabang']) ? 'selected' : '' ?>>
                                        <?= h($c['nama_cabang']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted mb-1">Status</label>
                            <select name="status" class="form-select-premium w-100" required>
                                <option value="aktif">Aktif</option>
                                <option value="nonaktif">Nonaktif</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted mb-1">Tanggal Mulai</label>
                            <input type="date" name="tgl_mulai" value="<?= date('Y-m-d') ?>" class="form-control form-control-premium">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted mb-1">Tanggal Selesai</label>
                            <input type="date" name="tgl_selesai" class="form-control form-control-premium">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted mb-1">Nama Bank</label>
                            <input type="text" name="nama_bank_pengelola" class="form-control form-control-premium" placeholder="BCA / Mandiri / dll">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted mb-1">No Rekening</label>
                            <input type="text" name="no_rekening_pengelola" class="form-control form-control-premium" placeholder="Nomor rekening">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted mb-1">Atas Nama Rekening</label>
                            <input type="text" name="atas_nama_pengelola" class="form-control form-control-premium" placeholder="Atas nama">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-premium-outline text-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" name="tambah" class="btn btn-premium">Simpan Pengelola</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
    // Kalau admin memilih PIC yang sudah ada, tidak perlu lanjut ke pembuatan akun baru.
    function toggleLanjutUser() {
        const select = document.getElementById('tambah_id_user');
        const hidden = document.getElementById('tambah_lanjut_user');
        hidden.value = (select.value === '' && <?= $open_modal_auto ? 'true' : 'false' ?>) ? '1' : '0';
    }

    // Konfirmasi Hapus SweetAlert2
    function confirmDelete(id, nama) {
        Swal.fire({
            title: 'Hapus Pengelola?',
            text: "Apakah Anda yakin ingin menghapus " + nama + "? Data yang dihapus tidak dapat dikembalikan.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#6b7280',
            confirmButtonText: 'Ya, Hapus!',
            cancelButtonText: 'Batal',
            customClass: {
                popup: 'rounded-4'
            }
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('form-delete-' + id).submit();
            }
        });
    }

    // Modal Otomatis Ditampilkan
    <?php if ($open_modal_auto): ?>
    document.addEventListener("DOMContentLoaded", function () {
        var modalElement = document.getElementById('modalTambah');
        if (modalElement) {
            var modalTambah = new bootstrap.Modal(modalElement);
            modalTambah.show();
        }
    });
    <?php endif; ?>

    // Alert Notifikasi Session
    <?php if (isset($_SESSION['success'])): ?>
        Swal.fire({
            icon: 'success',
            title: 'Berhasil!',
            text: <?= json_encode($_SESSION['success']) ?>,
            timer: 2500,
            showConfirmButton: false,
            customClass: { popup: 'rounded-4' }
        });
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        Swal.fire({
            icon: 'error',
            title: 'Gagal!',
            text: <?= json_encode($_SESSION['error']) ?>,
            customClass: { popup: 'rounded-4' }
        });
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>
</script>