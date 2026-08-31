<?php
require '../config/koneksi.php';

// HELPER SANITASI & SECURITY
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

// 1. PROTEKSI ROLE PUSAT
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'pusat') {
    header("Location: ../login"); 
    exit;
}

// --- ENDPOINT FALLBACK INTERNAL AJAX UNTUK DETAIL CABANG INVESTOR ---
if (isset($_GET['ajax_detail_cabang'])) {
    header('Content-Type: application/json; charset=utf-8');
    $id_investor = (int)$_GET['ajax_detail_cabang'];
    
    $sql_cabang = "SELECT ci.*, c.nama_cabang 
                   FROM cabang_investor ci 
                   LEFT JOIN cabang c ON ci.id_cabang = c.id_cabang 
                   WHERE ci.id_investor = ?
                   ORDER BY ci.tgl_mulai DESC";
    
    $stmt_c = $conn->prepare($sql_cabang);
    if (!$stmt_c) {
        $sql_cabang = "SELECT * FROM cabang_investor WHERE id_investor = ?";
        $stmt_c = $conn->prepare($sql_cabang);
    }
    
    $stmt_c->bind_param("i", $id_investor);
    $stmt_c->execute();
    $res = $stmt_c->get_result();
    
    $data_cabang = [];
    $today = date('Y-m-d');

    while ($row = $res->fetch_assoc()) {
        $tgl_mulai   = !empty($row['tgl_mulai']) ? $row['tgl_mulai'] : null;
        $tgl_selesai = !empty($row['tgl_selesai']) ? $row['tgl_selesai'] : null;

        if (isset($row['status']) && !empty($row['status'])) {
            $status = ucfirst(strtolower($row['status']));
        } else {
            $status = (empty($tgl_selesai) || $tgl_selesai >= $today) ? 'Aktif' : 'Selesai';
        }

        $data_cabang[] = [
            'id_cabang'       => $row['id_cabang'] ?? null,
            'nama_cabang'     => $row['nama_cabang'] ?? ('Cabang ID #' . ($row['id_cabang'] ?? '-')),
            'tgl_mulai'       => $tgl_mulai,
            'tgl_selesai'     => $tgl_selesai,
            'tgl_mulai_fmt'   => $tgl_mulai ? date('d-m-Y', strtotime($tgl_mulai)) : '-',
            'tgl_selesai_fmt' => $tgl_selesai ? date('d-m-Y', strtotime($tgl_selesai)) : '-',
            'status'          => $status
        ];
    }
    $stmt_c->close();
    
    echo json_encode([
        'success' => true,
        'data'    => $data_cabang
    ]);
    exit;
}

// Total semua investor untuk statistik
$total_investor_all = 0;
$query_stat = $conn->query("SELECT COUNT(*) as total FROM investor");
if ($query_stat) {
    $total_investor_all = $query_stat->fetch_assoc()['total'];
}

// 2. PROSES TAMBAH / EDIT INVESTOR
if (isset($_POST['simpan'])) {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        $_SESSION['error'] = 'Token CSRF tidak valid!';
        header("Location: data_investor");
        exit;
    }
    
    $id     = !empty($_POST['id_investor']) ? (int)$_POST['id_investor'] : null;
    $nama   = trim($_POST['nama_investor'] ?? '');
    $hp     = trim($_POST['no_hp'] ?? '');
    $status = in_array($_POST['status'] ?? '', ['aktif', 'nonaktif']) ? $_POST['status'] : 'aktif';
    
    if (empty($nama)) {
        $_SESSION['error'] = 'Nama Investor wajib diisi!';
        header("Location: data_investor");
        exit;
    }

    if ($id) {
        $stmt = $conn->prepare("UPDATE investor SET nama_investor=?, no_hp=?, status=? WHERE id_investor=?");
        $stmt->bind_param("sssi", $nama, $hp, $status, $id);
    } else {
        $stmt = $conn->prepare("INSERT INTO investor (nama_investor, no_hp, status) VALUES (?,?,?)");
        $stmt->bind_param("sss", $nama, $hp, $status);
    }

    if ($stmt->execute()) {
        $rec = $id ?: $conn->insert_id;
        $stmt->close();
        audit($conn, $id ? 'investor_edit' : 'investor_tambah', 'investor', $rec, ['nama' => $nama, 'status' => $status]);
        $_SESSION['success'] = 'Data investor berhasil disimpan!';
        header("Location: data_investor");
        exit;
    } else {
        $error_msg = $stmt->error;
        $stmt->close();
        $_SESSION['error'] = 'Gagal menyimpan data: ' . $error_msg;
        header("Location: data_investor");
        exit;
    }
}

// 3. PROSES HAPUS INVESTOR
if (isset($_POST['hapus'])) {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        $_SESSION['error'] = 'Token CSRF tidak valid!';
        header("Location: data_investor");
        exit;
    }
    
    $id = (int)$_POST['id_investor'];

    $del_relasi = $conn->prepare("DELETE FROM cabang_investor WHERE id_investor=?");
    $del_relasi->bind_param("i", $id);
    $del_relasi->execute();
    $del_relasi->close();

    $del = $conn->prepare("DELETE FROM investor WHERE id_investor=?");
    $del->bind_param("i", $id);
    
    if ($del->execute()) {
        $del->close();
        audit($conn, 'investor_hapus', 'investor', $id);
        $_SESSION['success'] = 'Data investor berhasil dihapus!';
        header("Location: data_investor");
        exit;
    } else {
        $error_msg = $del->error;
        $del->close();
        $_SESSION['error'] = 'Gagal menghapus data: ' . $error_msg;
        header("Location: data_investor");
        exit;
    }
}

// 4. FILTER + PAGINATION
$search = trim($_GET['search'] ?? '');
$where_sql = "";
$params = [];
$types = "";

if ($search != '') {
    $where_sql = "WHERE nama_investor LIKE ? OR no_hp LIKE ?";
    $search_param = "%{$search}%";
    $params = [$search_param, $search_param];
    $types = "ss";
}

$limit  = 10;
$page   = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$page   = $page < 1 ? 1 : $page;
$offset = ($page - 1) * $limit;

$sql_count = "SELECT COUNT(*) as total FROM investor $where_sql";
$stmt_count = $conn->prepare($sql_count);
if ($search != '') {
    $stmt_count->bind_param($types, ...$params);
}
$stmt_count->execute();
$total_data  = $stmt_count->get_result()->fetch_assoc()['total'];
$stmt_count->close();
$total_pages = ceil($total_data / $limit);

$sql = "SELECT * FROM investor $where_sql ORDER BY id_investor DESC LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);

if ($search != '') {
    $fetch_types = $types . "ii";
    $fetch_params = array_merge($params, [$limit, $offset]);
    $stmt->bind_param($fetch_types, ...$fetch_params);
} else {
    $stmt->bind_param("ii", $limit, $offset);
}

$stmt->execute();
$investor_result = $stmt->get_result();

$investor_list = [];
while ($row = $investor_result->fetch_assoc()) {
    $investor_list[] = $row;
}
$stmt->close();

// Sidebar + <head>/<body> di-include SETELAH semua handler POST (yang pakai header redirect)
include 'sidebar_pusat.php';
?>

<!-- CSS Assets -->
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
    body { background-color: #f4f7fe !important; font-family: 'Plus Jakarta Sans', sans-serif !important; color: #1b2559; }
    
    .saas-card { background: #ffffff; border: none !important; border-radius: 20px !important; box-shadow: 0px 18px 40px rgba(112, 144, 176, 0.06) !important; padding: 20px; }
    .title-mark { width: 12px; height: 12px; background-color: #4318ff; border-radius: 4px; display: inline-block; margin-right: 10px; }
    
    .btn-premium { background-color: #4318ff !important; color: #ffffff !important; border: none !important; padding: 10px 20px; border-radius: 12px; font-weight: 600; font-size: 14px; transition: all 0.2s ease; }
    .btn-premium:hover { background-color: #3310cc !important; transform: translateY(-1px); box-shadow: 0px 8px 20px rgba(67, 24, 255, 0.15); }
    .btn-premium-outline { background-color: #f4f7fe !important; color: #4318ff !important; border: 1px solid #e0e7ff !important; padding: 10px 16px; border-radius: 12px; font-weight: 600; font-size: 14px; }
    .btn-premium-outline:hover { background-color: #e0e7ff !important; }
    
    .form-control-premium, .form-select-premium { border-radius: 12px !important; border: 1px solid #e0e7ff !important; padding: 10px 16px; color: #1b2559; font-size: 14px; background-color: #ffffff; }
    .form-control-premium:focus, .form-select-premium:focus { border-color: #4318ff !important; box-shadow: 0 0 0 4px rgba(67, 24, 255, 0.1) !important; }
    
    .btn-action-info { background-color: #dbeafe; color: #2563eb; border: none; padding: 6px 12px; border-radius: 8px; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; cursor: pointer; }
    .btn-action-info:hover { background-color: #bfdbfe; color: #1d4ed8; }
    .btn-action-edit { background-color: #fff3cd; color: #856404; border: none; padding: 6px 12px; border-radius: 8px; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; cursor: pointer; }
    .btn-action-edit:hover { background-color: #ffe8a1; color: #856404; }
    .btn-action-delete { background-color: #fde8e8; color: #ef4444; border: none; padding: 6px 12px; border-radius: 8px; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; }
    .btn-action-delete:hover { background-color: #fbd5d5; }
    
    .stat-card { border-radius: 16px; padding: 20px; background: #fff; border: 1px solid #e0e7ff; box-shadow: 0px 18px 40px rgba(112, 144, 176, 0.04); }
    .stat-card .icon { width: 48px; height: 48px; background: #f4f7fe; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 24px; color: #4318ff; }
    
    .table-saas { margin-bottom: 0; width: 100% !important; }
    .table-saas thead th { background-color: #f8f9fc !important; color: #8f9bba !important; font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #eef2f9 !important; padding: 14px 12px; }
    .table-saas tbody td { padding: 14px 12px; border-bottom: 1px solid #f4f7fe !important; color: #2b3674; font-size: 14px; vertical-align: middle; }
    
    .modal-premium .modal-content { border-radius: 24px !important; border: none !important; box-shadow: 0px 24px 48px rgba(112, 144, 176, 0.15) !important; }

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
                <h3 class="fw-bold mb-0" style="color: #1b2559;">Data Investor</h3>
            </div>
            <span class="text-muted small ms-4">Manajemen data investor Warteg Bumi Bahari</span>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-12 col-md-4">
            <div class="stat-card d-flex align-items-center gap-3">
                <div class="icon"><i class="bi bi-people-fill"></i></div>
                <div>
                    <div class="text-muted small">Total Investor</div>
                    <div class="fw-bold fs-3" style="color: #1b2559;"><?= number_format($total_investor_all) ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-stretch align-items-sm-center gap-3 mb-4">
        <button type="button" class="btn btn-premium d-flex align-items-center gap-2" onclick="resetForm()">
            <i class="bi bi-person-plus-fill"></i> Tambah Investor Baru
        </button>
        
        <form method="GET" class="d-flex gap-2 search-container">
            <input type="text" name="search" class="form-control form-control-premium" placeholder="Cari nama atau No. HP..." value="<?= h($search) ?>">
            <button type="submit" class="btn btn-premium-outline"><i class="bi bi-search"></i></button>
            <?php if ($search !== ''): ?>
                <a href="data_investor" class="btn btn-premium-outline bg-white text-secondary"><i class="bi bi-arrow-clockwise"></i></a>
            <?php endif; ?>
        </form>
    </div>

    <div class="card saas-card p-0 overflow-hidden border-0">
        <!-- TAMPILAN TABLE DESKTOP -->
        <div class="table-responsive table-desktop">
            <table class="table table-saas align-middle mb-0">
                <thead>
                    <tr>
                        <th width="8%" class="text-center">No</th>
                        <th width="35%">Nama Investor</th>
                        <th width="22%">No. Telephone / HP</th>
                        <th width="12%" class="text-center">Status</th>
                        <th width="23%" class="text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($investor_list)): ?>
                    <tr>
                        <td colspan="5" class="text-center py-5 text-muted fw-semibold">
                            <i class="bi bi-inbox fs-2 d-block mb-2"></i> Belum ada data investor
                        </td>
                    </tr>
                    <?php else: $no = $offset + 1; foreach ($investor_list as $d): ?>
                    <tr>
                        <td class="text-center text-muted fw-semibold"><?= $no++ ?></td>
                        <td>
                            <span class="fw-bold text-dark d-block"><?= h($d['nama_investor']) ?></span>
                        </td>
                        <td>
                            <small class="d-block text-dark fw-semibold"><i class="bi bi-telephone me-1"></i><?= h($d['no_hp'] ?: '-') ?></small>
                        </td>
                        <td class="text-center">
                            <?php if (($d['status'] ?? 'aktif') == 'aktif'): ?>
                                <span class="badge bg-success-subtle text-success px-2 py-1">Aktif</span>
                            <?php else: ?>
                                <span class="badge bg-danger-subtle text-danger px-2 py-1">Nonaktif</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <div class="d-inline-flex gap-1 justify-content-center">
                                <button type="button" class="btn btn-action-info" onclick="showDetailInvestor(<?= (int)$d['id_investor'] ?>, '<?= h(addslashes($d['nama_investor'])) ?>')">
                                    <i class="bi bi-info-circle me-1"></i> Detail
                                </button>
                                <a href="data_user?open_modal=1&id_investor=<?= (int)$d['id_investor'] ?>&nama_investor=<?= urlencode($d['nama_investor']) ?>" class="btn btn-action-edit" title="Buat/atur akun login investor ini">
                                    <i class="bi bi-key-fill"></i>
                                </a>
                                <button type="button" class="btn btn-action-edit" onclick="editInvestor(<?= htmlspecialchars(json_encode($d), ENT_QUOTES, 'UTF-8') ?>)">
                                    <i class="bi bi-pencil-square"></i>
                                </button>
                                <form method="POST" class="d-inline" id="form-delete-<?= (int)$d['id_investor'] ?>">
                                    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                                    <input type="hidden" name="id_investor" value="<?= (int)$d['id_investor'] ?>">
                                    <input type="hidden" name="hapus" value="1">
                                    <button type="button" class="btn btn-action-delete" onclick="confirmDelete(<?= (int)$d['id_investor'] ?>, '<?= h(addslashes($d['nama_investor'])) ?>')">
                                        <i class="bi bi-trash-fill"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <!-- TAMPILAN MOBILE CARD -->
        <div class="mobile-card p-3">
            <?php if (empty($investor_list)): ?>
                <div class="text-center py-4 text-muted">
                    <i class="bi bi-inbox fs-2 d-block mb-2"></i> Belum ada data investor
                </div>
            <?php else: $no_m = $offset + 1; foreach ($investor_list as $d): ?>
                <div class="bg-light p-3 rounded-4 mb-3 border">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="badge bg-white text-dark border fw-bold">#<?= $no_m++ ?></span>
                        <div>
                            <?php if (($d['status'] ?? 'aktif') == 'aktif'): ?>
                                <span class="badge bg-success-subtle text-success me-1">Aktif</span>
                            <?php else: ?>
                                <span class="badge bg-danger-subtle text-danger me-1">Nonaktif</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="mb-2">
                        <div class="text-muted small">Nama Investor</div>
                        <div class="fw-bold text-dark fs-6"><?= h($d['nama_investor']) ?></div>
                    </div>
                    <div class="mb-2">
                        <div class="text-muted small">No HP / Telephone</div>
                        <div class="fw-semibold text-secondary small"><?= h($d['no_hp'] ?: '-') ?></div>
                    </div>
                    <div class="d-flex gap-2 mt-3">
                        <button type="button" class="btn btn-action-info flex-fill py-2" onclick="showDetailInvestor(<?= (int)$d['id_investor'] ?>, '<?= h(addslashes($d['nama_investor'])) ?>')">
                            <i class="bi bi-info-circle me-1"></i> Detail Cabang
                        </button>
                        <a href="data_user?open_modal=1&id_investor=<?= (int)$d['id_investor'] ?>&nama_investor=<?= urlencode($d['nama_investor']) ?>" class="btn btn-action-edit py-2" title="Buat/atur akun login investor ini">
                            <i class="bi bi-key-fill"></i>
                        </a>
                        <button type="button" class="btn btn-action-edit py-2" onclick="editInvestor(<?= htmlspecialchars(json_encode($d), ENT_QUOTES, 'UTF-8') ?>)">
                            <i class="bi bi-pencil-square"></i>
                        </button>
                        <form method="POST" class="d-inline" id="form-delete-m-<?= (int)$d['id_investor'] ?>">
                            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                            <input type="hidden" name="id_investor" value="<?= (int)$d['id_investor'] ?>">
                            <input type="hidden" name="hapus" value="1">
                            <button type="button" class="btn btn-action-delete py-2" onclick="confirmDelete(<?= (int)$d['id_investor'] ?>, '<?= h(addslashes($d['nama_investor'])) ?>', true)">
                                <i class="bi bi-trash-fill"></i>
                            </button>
                        </form>
                    </div>
                </div>
            <?php endforeach; endif; ?>
        </div>

        <?php render_pagination($page, $total_pages, ['from' => $offset + 1, 'to' => min($offset + $limit, $total_data), 'total' => $total_data, 'label' => 'investor']); ?>
    </div>
</div>

<!-- MODAL TAMBAH / EDIT INVESTOR -->
<div class="modal fade modal-premium" id="modalInvestor" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <div class="modal-header border-bottom-0 pb-0">
                    <div>
                        <h5 class="modal-title fw-bold" id="modalTitle" style="color: #1b2559;">Registrasi Investor Baru</h5>
                        <small class="text-muted">Kelola data profil investor</small>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id_investor" id="id_investor" value="">
                    
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted mb-1">Nama Investor *</label>
                        <input type="text" name="nama_investor" id="nama_investor" class="form-control form-control-premium" required autocomplete="off">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted mb-1">Telephone / No HP</label>
                        <input type="text" name="no_hp" id="no_hp" class="form-control form-control-premium" placeholder="08123456789">
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted mb-1">Status Investor</label>
                        <select name="status" id="status" class="form-select form-select-premium">
                            <option value="aktif">Aktif</option>
                            <option value="nonaktif">Nonaktif</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer border-top-0 pt-0">
                    <button type="button" class="btn btn-premium-outline text-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" name="simpan" class="btn btn-premium">Simpan Data</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL DETAIL CABANG INVESTOR -->
<div class="modal fade modal-premium" id="modalDetailInvestor" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header border-bottom-0 pb-0">
                <div>
                    <h5 class="modal-title fw-bold" style="color: #1b2559;">Riwayat Investor Cabang</h5>
                    <small class="text-muted" id="detailNamaInvestor">Daftar cabang tempat investor ini menanamkan modal</small>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="loadingDetail" class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="small text-muted mt-2">Mengambil data riwayat cabang...</p>
                </div>
                <div id="contentDetail" style="display: none;">
                    <div class="table-responsive">
                        <table class="table table-saas align-middle mb-0">
                            <thead>
                                <tr>
                                    <th width="6%" class="text-center">NO</th>
                                    <th width="28%">NAMA CABANG</th>
                                    <th width="22%">PENGELOLA</th>
                                    <th width="28%" class="text-center">PERIODE INVESTASI</th>
                                    <th width="16%" class="text-center">STATUS</th>
                                </tr>
                            </thead>
                            <tbody id="listCabangInvestor">
                                <!-- Data diisi via Javascript -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-top-0 pt-0">
                <button type="button" class="btn btn-premium-outline text-secondary" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
let investorModal;
let detailModal;

document.addEventListener('DOMContentLoaded', function() {
    investorModal = new bootstrap.Modal(document.getElementById('modalInvestor'));
    detailModal = new bootstrap.Modal(document.getElementById('modalDetailInvestor'));
});

function editInvestor(data) {
    document.getElementById('modalTitle').innerText = 'Edit Data Investor';
    document.getElementById('id_investor').value = data.id_investor || '';
    document.getElementById('nama_investor').value = data.nama_investor || '';
    document.getElementById('no_hp').value = data.no_hp || '';
    document.getElementById('status').value = data.status || 'aktif';
    
    investorModal.show();
}

function resetForm() {
    document.getElementById('modalTitle').innerText = 'Registrasi Investor Baru';
    document.getElementById('id_investor').value = '';
    document.getElementById('nama_investor').value = '';
    document.getElementById('no_hp').value = '';
    document.getElementById('status').value = 'aktif';
    
    investorModal.show();
}

function confirmDelete(id, nama, isMobile = false) {
    Swal.fire({
        title: 'Hapus Investor?',
        text: "Apakah Anda yakin ingin menghapus investor " + nama + "? Relasi cabang investor terkait juga akan terhapus.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#6b7280',
        confirmButtonText: 'Ya, Hapus!',
        cancelButtonText: 'Batal',
        customClass: { popup: 'rounded-4' }
    }).then((result) => {
        if (result.isConfirmed) {
            let formId = isMobile ? 'form-delete-m-' + id : 'form-delete-' + id;
            document.getElementById(formId).submit();
        }
    });
}

function showDetailInvestor(idInvestor, namaInvestor) {
    document.getElementById('detailNamaInvestor').innerText = 'Investor: ' + namaInvestor;
    document.getElementById('loadingDetail').style.display = 'block';
    document.getElementById('contentDetail').style.display = 'none';
    
    detailModal.show();
    
    // Panggil file get_riwayat_investor.php via AJAX
    fetch('get_riwayat_investor.php?id_investor=' + idInvestor)
        .then(response => {
            if (!response.ok) throw new Error("HTTP error " + response.status);
            return response.json();
        })
        .then(res => {
            let html = '';
            let dataCabang = (res && res.success) ? res.data : [];

            const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
            }[c]));

            if (dataCabang.length === 0) {
                html = '<tr><td colspan="5" class="text-center py-4 text-muted fw-semibold"><i class="bi bi-info-circle d-block fs-3 mb-1"></i>Investor ini belum terhubung ke cabang manapun.</td></tr>';
            } else {
                dataCabang.forEach((item, index) => {
                    let badgeClass = (item.status && item.status.toLowerCase() === 'aktif') ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary';
                    let tglMulaiFmt = item.tgl_mulai_fmt || item.tgl_mulai || '-';
                    let tglSelesaiFmt = item.tgl_selesai_fmt || item.tgl_selesai || '-';

                    html += `
                        <tr>
                            <td class="text-center fw-semibold text-muted">${index + 1}</td>
                            <td class="fw-bold text-dark">${esc(item.nama_cabang)}</td>
                            <td class="text-secondary">${esc(item.nama_pengelola || '-')}</td>
                            <td class="text-center small text-muted">${esc(tglMulaiFmt)} s/d ${esc(tglSelesaiFmt)}</td>
                            <td class="text-center"><span class="badge ${badgeClass} px-2 py-1">${esc(item.status)}</span></td>
                        </tr>
                    `;
                });
            }
            document.getElementById('listCabangInvestor').innerHTML = html;
            document.getElementById('loadingDetail').style.display = 'none';
            document.getElementById('contentDetail').style.display = 'block';
        })
        .catch(err => {
            console.error('AJAX Error:', err);
            document.getElementById('listCabangInvestor').innerHTML = '<tr><td colspan="5" class="text-center text-danger py-3">Gagal memuat data riwayat cabang.</td></tr>';
            document.getElementById('loadingDetail').style.display = 'none';
            document.getElementById('contentDetail').style.display = 'block';
        });
}

// Alert Notifikasi Session
<?php if (isset($_SESSION['success'])): ?>
    Swal.fire({
        icon: 'success',
        title: 'Berhasil!',
        text: '<?= addslashes($_SESSION['success']) ?>',
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
        text: '<?= addslashes($_SESSION['error']) ?>',
        customClass: { popup: 'rounded-4' }
    });
    <?php unset($_SESSION['error']); ?>
<?php endif; ?>
</script>