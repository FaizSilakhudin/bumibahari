<?php
require '../config/koneksi.php';
include 'sidebar_pusat.php';

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

function user_relasi_label(array $d): string {
    switch ($d['role']) {
        case 'cabang':   return !empty($d['nama_cabang']) ? h($d['nama_cabang']) : '<span class="badge bg-light text-muted border">Belum dipilih</span>';
        case 'pic':      return '<span class="badge bg-light text-muted border">Multi-cabang &mdash; lihat Data Pengelola</span>';
        case 'investor': return !empty($d['nama_investor']) ? h($d['nama_investor']) : '<span class="badge bg-light text-muted border">Belum dipilih</span>';
        default:          return '<span class="badge bg-light text-muted border">Pusat</span>';
    }
}

function role_badge_class(string $role): string {
    switch ($role) {
        case 'pusat':    return 'bg-primary';
        case 'pic':      return 'bg-warning text-dark';
        case 'investor': return 'bg-purple text-white';
        default:          return 'bg-info text-dark';
    }
}

// 1. PROTEKSI ROLE PUSAT
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'pusat') {
    header("Location: ../login");
    exit;
}

$user_pk = 'id';

// Parameter alur berantai dari "Tambah Pengelola" (-> role pic) atau "Data Investor" (-> role investor)
$open_modal_auto        = isset($_GET['open_modal']) && $_GET['open_modal'] == '1';
$prefill_id_cabang      = isset($_GET['id_cabang']) ? (int) $_GET['id_cabang'] : 0;
$prefill_id_pengelola   = isset($_GET['id_pengelola']) ? (int) $_GET['id_pengelola'] : 0;
$prefill_nama_pengelola = trim($_GET['nama_pengelola'] ?? '');
$prefill_id_investor    = isset($_GET['id_investor']) ? (int) $_GET['id_investor'] : 0;
$prefill_nama_investor  = trim($_GET['nama_investor'] ?? '');
$prefill_nama_untuk_username = $prefill_nama_pengelola !== '' ? $prefill_nama_pengelola : $prefill_nama_investor;
$prefill_username       = $prefill_nama_untuk_username !== ''
    ? strtolower(preg_replace('/[^a-z0-9]/i', '', $prefill_nama_untuk_username))
    : '';
$prefill_role = $prefill_id_pengelola ? 'pic' : ($prefill_id_investor ? 'investor' : 'cabang');

// AMBIL DAFTAR CABANG & INVESTOR UNTUK DROPDOWN MODAL
$cabang_options = [];
$query_cabang = $conn->query("SELECT id_cabang, nama_cabang FROM cabang ORDER BY nama_cabang ASC");
if ($query_cabang) {
    while ($row_c = $query_cabang->fetch_assoc()) {
        $cabang_options[] = $row_c;
    }
}

$investor_options = [];
$query_investor = $conn->query("SELECT id_investor, nama_investor FROM investor ORDER BY nama_investor ASC");
if ($query_investor) {
    while ($row_i = $query_investor->fetch_assoc()) {
        $investor_options[] = $row_i;
    }
}

// 2. PROSES TAMBAH / EDIT USER (HANYA UNTUK TABEL USERS)
if (isset($_POST['simpan'])) {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        echo "<script>alert('Token CSRF tidak valid!'); history.back();</script>";
        exit;
    }

    $id_user   = !empty($_POST['id_user']) ? (int)$_POST['id_user'] : null;
    $username  = trim($_POST['username'] ?? '');
    $password  = $_POST['password'] ?? '';
    $role      = in_array($_POST['role'] ?? '', ['pusat', 'pic', 'cabang', 'investor'], true) ? $_POST['role'] : 'cabang';
    $id_cabang   = ($role === 'cabang' && !empty($_POST['id_cabang'])) ? (int)$_POST['id_cabang'] : null;
    $id_investor = ($role === 'investor' && !empty($_POST['id_investor'])) ? (int)$_POST['id_investor'] : null;
    $status    = $_POST['status'] ?? 'aktif';
    $id_pengelola = !empty($_POST['id_pengelola']) ? (int)$_POST['id_pengelola'] : null;
    $is_chain_complete = false;

    if (empty($username)) {
        echo "<script>alert('Username wajib diisi!'); history.back();</script>";
        exit;
    }

    if ($role === 'cabang' && empty($id_cabang)) {
        echo "<script>alert('Cabang wajib dipilih untuk pengguna dengan role Cabang!'); history.back();</script>";
        exit;
    }

    if ($role === 'investor' && empty($id_investor)) {
        echo "<script>alert('Investor wajib dipilih untuk pengguna dengan role Investor!'); history.back();</script>";
        exit;
    }

    // Kebijakan password: minimal 8 karakter (berlaku saat dibuat / diganti).
    if ($password !== '' && strlen($password) < 8) {
        echo "<script>alert('Password minimal 8 karakter.'); history.back();</script>";
        exit;
    }

    if ($id_user) {
        // UPDATE USER EXISTING (HANYA TABEL USERS)
        $check_user = $conn->prepare("SELECT {$user_pk} FROM users WHERE username = ? AND {$user_pk} != ?");
        $check_user->bind_param("si", $username, $id_user);
        $check_user->execute();
        if ($check_user->get_result()->num_rows > 0) {
            echo "<script>alert('Username sudah digunakan! Pilih username lain.'); history.back();</script>";
            exit;
        }
        $check_user->close();

        if (!empty($password)) {
            $pass_hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $conn->prepare("UPDATE users SET username=?, password=?, role=?, id_cabang=?, id_investor=?, status=? WHERE {$user_pk}=?");
            $stmt->bind_param("sssiisi", $username, $pass_hash, $role, $id_cabang, $id_investor, $status, $id_user);
        } else {
            $stmt = $conn->prepare("UPDATE users SET username=?, role=?, id_cabang=?, id_investor=?, status=? WHERE {$user_pk}=?");
            $stmt->bind_param("ssiisi", $username, $role, $id_cabang, $id_investor, $status, $id_user);
        }
        $stmt->execute();
        $stmt->close();
        audit($conn, 'user_edit', 'users', $id_user, [
            'username' => $username, 'role' => $role, 'status' => $status,
            'id_cabang' => $id_cabang, 'id_investor' => $id_investor, 'password_diubah' => !empty($password),
        ]);
    } else {
        // TAMBAH USER BARU (HANYA TABEL USERS)
        if (empty($password)) {
            echo "<script>alert('Password wajib diisi untuk pengguna baru!'); history.back();</script>";
            exit;
        }

        $check_user = $conn->prepare("SELECT {$user_pk} FROM users WHERE username = ?");
        $check_user->bind_param("s", $username);
        $check_user->execute();
        if ($check_user->get_result()->num_rows > 0) {
            echo "<script>alert('Username sudah digunakan! Pilih username lain.'); history.back();</script>";
            exit;
        }
        $check_user->close();

        $pass_hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $conn->prepare("INSERT INTO users (username, password, role, id_cabang, id_investor, status, id_pengelola) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssiisi", $username, $pass_hash, $role, $id_cabang, $id_investor, $status, $id_pengelola);
        $stmt->execute();
        $new_user_id = $conn->insert_id;
        $stmt->close();
        audit($conn, 'user_tambah', 'users', $new_user_id, [
            'username' => $username, 'role' => $role, 'id_cabang' => $id_cabang, 'id_investor' => $id_investor,
        ]);

        // Alur berantai: tautkan balik pengelola -> user yang baru dibuat
        if ($id_pengelola) {
            $lnk = $conn->prepare("UPDATE pengelola SET id_user = ? WHERE id = ? AND id_user IS NULL");
            $lnk->bind_param("ii", $new_user_id, $id_pengelola);
            $lnk->execute();
            $lnk->close();
            $is_chain_complete = true;
        }
    }

    if ($is_chain_complete) {
        echo "<script>alert('Selesai! Data Cabang, Pengelola, dan Akun User sudah lengkap dibuat.'); window.location='data_user';</script>";
    } else {
        echo "<script>alert('Data User berhasil disimpan'); window.location='data_user';</script>";
    }
    exit;
}

// 3. PROSES HAPUS USER (HANYA UNTUK TABEL USERS)
if (isset($_POST['hapus'])) {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        echo "<script>alert('Token CSRF tidak valid!'); history.back();</script>";
        exit;
    }

    $id_user = (int)$_POST['id_user'];

    if (isset($_SESSION['user_id']) && (int) $_SESSION['user_id'] === $id_user) {
        echo "<script>alert('Anda tidak dapat menghapus akun Anda sendiri yang sedang aktif!'); history.back();</script>";
        exit;
    }

    $uname_dihapus = $conn->query("SELECT username FROM users WHERE {$user_pk}=" . (int) $id_user)->fetch_assoc()['username'] ?? null;

    $del = $conn->prepare("DELETE FROM users WHERE {$user_pk}=?");
    $del->bind_param("i", $id_user);
    $del->execute();
    $del->close();

    audit($conn, 'user_hapus', 'users', $id_user, ['username' => $uname_dihapus]);

    echo "<script>alert('User berhasil dihapus'); window.location='data_user';</script>";
    exit;
}

// --- FILTER & PAGINATION DATA USER ---
$search = trim($_GET['search'] ?? '');
$where_sql = "";
$params = [];
$types = "";

if ($search != '') {
    $where_sql = " WHERE u.username LIKE ? OR u.role LIKE ? OR u.status LIKE ? OR c.nama_cabang LIKE ? OR i.nama_investor LIKE ? ";
    $search_param = "%{$search}%";
    $params = [$search_param, $search_param, $search_param, $search_param, $search_param];
    $types = "sssss";
}

$limit  = 10;
$page   = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$page   = $page < 1 ? 1 : $page;
$offset = ($page - 1) * $limit;

// 1. QUERY HITUNG TOTAL DATA
$sql_count = "SELECT COUNT(*) as total FROM users u
              LEFT JOIN cabang c ON u.id_cabang = c.id_cabang
              LEFT JOIN investor i ON u.id_investor = i.id_investor
              $where_sql";
$stmt_count = $conn->prepare($sql_count);

if ($search != '') {
    $stmt_count->bind_param($types, ...$params);
}
$stmt_count->execute();
$total_data  = $stmt_count->get_result()->fetch_assoc()['total'];
$stmt_count->close();

$total_pages = ceil($total_data / $limit);

// 2. QUERY AMBIL DATA USER
$sql = "SELECT u.*, c.nama_cabang, i.nama_investor
        FROM users u
        LEFT JOIN cabang c ON u.id_cabang = c.id_cabang
        LEFT JOIN investor i ON u.id_investor = i.id_investor
        $where_sql
        ORDER BY u.id DESC LIMIT ? OFFSET ?";

$stmt = $conn->prepare($sql);

if ($search != '') {
    $fetch_types = $types . "ii";
    $fetch_params = array_merge($params, [$limit, $offset]);
    $stmt->bind_param($fetch_types, ...$fetch_params);
} else {
    $stmt->bind_param("ii", $limit, $offset);
}

$stmt->execute();
$user_result = $stmt->get_result();

$user_list = [];
while ($row = $user_result->fetch_assoc()) {
    $user_list[] = $row;
}
$stmt->close();
?>

<!-- CSS Assets (Bootstrap sudah dimuat di sidebar_pusat.php — jangan dimuat ulang
     supaya style sidebar tidak tertimpa) -->
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<style>
    body { background-color: #f4f7fe !important; font-family: 'Plus Jakarta Sans', sans-serif !important; }
    .bg-purple { background-color: #7e22ce !important; }

    .saas-card { background: #ffffff; border: none !important; border-radius: 20px !important; box-shadow: 0px 18px 40px rgba(112, 144, 176, 0.06) !important; padding: 20px; }
    .title-mark { width: 12px; height: 12px; background-color: #4318ff; border-radius: 4px; display: inline-block; margin-right: 10px; }
    
    .btn-premium { background-color: #4318ff !important; color: #ffffff !important; border: none !important; padding: 10px 20px; border-radius: 12px; font-weight: 600; font-size: 14px; transition: all 0.2s ease; }
    .btn-premium:hover { background-color: #3310cc !important; transform: translateY(-1px); box-shadow: 0px 8px 20px rgba(67, 24, 255, 0.15); }
    .btn-premium-outline { background-color: #f4f7fe !important; color: #4318ff !important; border: 1px solid #e0e7ff !important; padding: 10px 16px; border-radius: 12px; font-weight: 600; font-size: 14px; }
    .btn-premium-outline:hover { background-color: #e0e7ff !important; }
    
    .form-control-premium, .form-select-premium { border-radius: 12px !important; border: 1px solid #e0e7ff !important; padding: 10px 16px; color: #1b2559; font-size: 14px; background-color: #ffffff; }
    .form-control-premium:focus, .form-select-premium:focus { border-color: #4318ff !important; box-shadow: 0 0 0 4px rgba(67, 24, 255, 0.1) !important; }
    
    .btn-action-edit { background-color: #fff3cd; color: #856404; border: none; padding: 6px 12px; border-radius: 8px; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; cursor: pointer; }
    .btn-action-edit:hover { background-color: #ffe8a1; color: #856404; }
    .btn-action-delete { background-color: #fde8e8; color: #ef4444; border: none; padding: 6px 12px; border-radius: 8px; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; }
    .btn-action-delete:hover { background-color: #fbd5d5; }
    
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
                <h3 class="fw-bold mb-0" style="color: #1b2559;">Data User</h3>
            </div>
            <span class="text-muted small ms-4">Kelola otentikasi login dan peran sistem</span>
        </div>
    </div>

    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-stretch align-items-sm-center gap-3 mb-4">
        <button type="button" class="btn btn-premium d-flex align-items-center gap-2" onclick="resetForm()">
            <i class="bi bi-person-plus-fill"></i> Tambah User Baru
        </button>
        
        <form method="GET" class="d-flex gap-2 search-container">
            <input type="text" name="search" class="form-control form-control-premium" placeholder="Cari username, cabang, role..." value="<?= h($search)?>">
            <button type="submit" class="btn btn-premium-outline"><i class="bi bi-search"></i></button>
            <?php if ($search !== ''): ?>
                <a href="data_user" class="btn btn-premium-outline bg-white text-secondary"><i class="bi bi-arrow-clockwise"></i></a>
            <?php endif; ?>
        </form>
    </div>

    <div class="card saas-card p-0 overflow-hidden border-0">
        <!-- TAMPILAN TABLE DESKTOP -->
        <div class="table-responsive table-desktop">
            <table class="table table-saas align-middle mb-0">
                <thead>
                    <tr>
                        <th width="5%" class="text-center">No</th>
                        <th width="25%">Cabang / Relasi</th>
                        <th width="25%">Username</th>
                        <th width="15%">Role</th>
                        <th width="15%">Status</th>
                        <th width="15%" class="text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($user_list)): ?>
                    <tr>
                        <td colspan="6" class="text-center py-5 text-muted fw-semibold">
                            <i class="bi bi-inbox fs-2 d-block mb-2"></i> Belum ada data user
                        </td>
                    </tr>
                    <?php else: $no = $offset + 1; foreach ($user_list as $d): 
                        $cur_id = $d['id'];
                        $user_status = strtolower($d['status'] ?? 'aktif');
                    ?>
                    <tr>
                        <td class="text-center text-muted fw-semibold"><?= $no++ ?></td>
                        <td>
                            <span class="fw-semibold text-dark"><?= user_relasi_label($d) ?></span>
                        </td>
                        <td><span class="fw-bold text-dark"><?= h($d['username']) ?></span></td>
                        <td>
                            <span class="badge <?= role_badge_class($d['role']) ?> px-3 py-2 rounded-pill text-uppercase" style="font-size: 11px;">
                                <?= h($d['role']) ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($user_status === 'aktif'): ?>
                                <span class="badge bg-success-subtle text-success px-3 py-2 rounded-pill"><i class="bi bi-check-circle-fill me-1"></i> Aktif</span>
                            <?php else: ?>
                                <span class="badge bg-danger-subtle text-danger px-3 py-2 rounded-pill"><i class="bi bi-x-circle-fill me-1"></i> Non-Aktif</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <div class="d-inline-flex gap-2 justify-content-center">
                                <button type="button" class="btn btn-action-edit" onclick='editUser(<?= json_encode($d, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                                    <i class="bi bi-pencil-square me-1"></i> Edit
                                </button>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Yakin menghapus user <?= h($d['username']) ?>?')">
                                    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                                    <input type="hidden" name="id_user" value="<?= (int)$cur_id ?>">
                                    <button type="submit" name="hapus" class="btn btn-action-delete">
                                        <i class="bi bi-trash-fill me-1"></i> Hapus
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
            <?php if (empty($user_list)): ?>
                <div class="text-center py-4 text-muted">
                    <i class="bi bi-inbox fs-2 d-block mb-2"></i> Belum ada data user
                </div>
            <?php else: $no_m = $offset + 1; foreach ($user_list as $d): 
                $cur_id = $d['id'];
                $user_status = strtolower($d['status'] ?? 'aktif');
            ?>
                <div class="bg-light p-3 rounded-4 mb-3 border">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="badge bg-white text-dark border fw-bold">#<?= $no_m++ ?></span>
                        <span class="badge <?= role_badge_class($d['role']) ?> text-uppercase" style="font-size: 10px;">
                            <?= h($d['role']) ?>
                        </span>
                    </div>
                    <div class="mb-2">
                        <div class="text-muted small">Username</div>
                        <div class="fw-bold text-dark fs-6"><?= h($d['username']) ?></div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <div class="text-muted small">Cabang / Relasi</div>
                            <div class="fw-semibold text-secondary small"><?= user_relasi_label($d) ?></div>
                        </div>
                        <div class="col-6">
                            <div class="text-muted small">Status</div>
                            <div>
                                <?php if ($user_status === 'aktif'): ?>
                                    <span class="badge bg-success-subtle text-success small">Aktif</span>
                                <?php else: ?>
                                    <span class="badge bg-danger-subtle text-danger small">Non-Aktif</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-action-edit w-50 py-2" onclick='editUser(<?= json_encode($d, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                            <i class="bi bi-pencil-square me-1"></i> Edit
                        </button>
                        <form method="POST" class="w-50" onsubmit="return confirm('Yakin menghapus user <?= h($d['username']) ?>?')">
                            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                            <input type="hidden" name="id_user" value="<?= (int)$cur_id ?>">
                            <button type="submit" name="hapus" class="btn btn-action-delete w-100 py-2">
                                <i class="bi bi-trash-fill me-1"></i> Hapus
                            </button>
                        </form>
                    </div>
                </div>
            <?php endforeach; endif; ?>
        </div>

        <?php render_pagination($page, $total_pages, ['from' => $offset + 1, 'to' => min($offset + $limit, $total_data), 'total' => $total_data, 'label' => 'user']); ?>
    </div>
</div>

<!-- MODAL TAMBAH / EDIT USER -->
<div class="modal fade modal-premium" id="modalUser" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="id_pengelola" id="id_pengelola_hidden" value="">
                <div class="modal-header border-bottom-0 pb-0">
                    <div>
                        <h5 class="modal-title fw-bold" id="modalTitle" style="color: #1b2559;">Tambah User Baru</h5>
                        <small class="text-muted">Kredensial login sistem</small>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id_user" id="id_user" value="">
                    
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">Username</label>
                        <input type="text" name="username" id="username" class="form-control form-control-premium" required autocomplete="off">
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">Password</label>
                        <input type="password" name="password" id="password" class="form-control form-control-premium" placeholder="Masukkan password baru" minlength="8">
                        <small class="text-muted" id="pass_help" style="font-size: 11px;"></small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">Role</label>
                        <select name="role" id="role" class="form-select form-select-premium" onchange="toggleRoleFields()" required>
                            <option value="cabang">Cabang (kirim nota)</option>
                            <option value="pic">PIC (isi laporan, pegang cabang)</option>
                            <option value="investor">Investor (lihat dashboard)</option>
                            <option value="pusat">Pusat</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">Status</label>
                        <select name="status" id="status" class="form-select form-select-premium" required>
                            <option value="aktif">Aktif</option>
                            <option value="nonaktif">Non-Aktif</option>
                        </select>
                    </div>

                    <!-- DROPDOWN PILIH CABANG (role: cabang) -->
                    <div class="mb-3" id="container_cabang">
                        <label class="form-label small fw-bold text-muted">Pilih Cabang</label>
                        <select name="id_cabang" id="id_cabang" class="form-select form-select-premium">
                            <option value="">-- Pilih Cabang --</option>
                            <?php foreach ($cabang_options as $c): ?>
                                <option value="<?= $c['id_cabang'] ?>"><?= h($c['nama_cabang']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- DROPDOWN PILIH INVESTOR (role: investor) -->
                    <div class="mb-3" id="container_investor" style="display:none;">
                        <label class="form-label small fw-bold text-muted">Pilih Investor</label>
                        <select name="id_investor" id="id_investor" class="form-select form-select-premium">
                            <option value="">-- Pilih Investor --</option>
                            <?php foreach ($investor_options as $inv): ?>
                                <option value="<?= $inv['id_investor'] ?>"><?= h($inv['nama_investor']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php if ($prefill_role === 'pic'): ?>
                        <div class="alert alert-info small mb-0"><i class="bi bi-info-circle me-1"></i> Akun ini akan dikaitkan ke pengelola yang baru dibuat di Data Pengelola. Beri PIC ini cabang lain lewat Data Pengelola bila perlu.</div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer border-top-0 pt-0">
                    <button type="button" class="btn btn-premium-outline text-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" name="simpan" class="btn btn-premium">Simpan Data</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
function getModalInstance() {
    const modalEl = document.getElementById('modalUser');
    return bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
}

function toggleRoleFields() {
    const roleSelect = document.getElementById('role').value;
    const cabangContainer = document.getElementById('container_cabang');
    const idCabangSelect = document.getElementById('id_cabang');
    const investorContainer = document.getElementById('container_investor');
    const idInvestorSelect = document.getElementById('id_investor');

    if (roleSelect === 'cabang') {
        cabangContainer.style.display = 'block';
        idCabangSelect.setAttribute('required', 'required');
    } else {
        cabangContainer.style.display = 'none';
        idCabangSelect.removeAttribute('required');
        idCabangSelect.value = '';
    }

    if (roleSelect === 'investor') {
        investorContainer.style.display = 'block';
        idInvestorSelect.setAttribute('required', 'required');
    } else {
        investorContainer.style.display = 'none';
        idInvestorSelect.removeAttribute('required');
        idInvestorSelect.value = '';
    }
}

function editUser(data) {
    document.getElementById('modalTitle').innerText = 'Edit Data User';
    document.getElementById('id_user').value = data.id || '';
    document.getElementById('username').value = data.username || '';
    document.getElementById('password').value = '';
    document.getElementById('role').value = data.role || 'cabang';
    document.getElementById('status').value = data.status || 'aktif';
    document.getElementById('id_cabang').value = data.id_cabang || '';
    document.getElementById('id_investor').value = data.id_investor || '';
    document.getElementById('id_pengelola_hidden').value = data.id_pengelola || '';
    document.getElementById('pass_help').innerText = 'Kosongkan jika tidak ingin mengubah password (minimal 8 karakter)';

    toggleRoleFields();
    getModalInstance().show();
}

function resetForm() {
    document.getElementById('modalTitle').innerText = 'Tambah User Baru';
    document.getElementById('id_user').value = '';
    document.getElementById('username').value = '';
    document.getElementById('password').value = '';
    document.getElementById('role').value = 'cabang';
    document.getElementById('status').value = 'aktif';
    document.getElementById('id_cabang').value = '';
    document.getElementById('id_investor').value = '';
    document.getElementById('id_pengelola_hidden').value = '';
    document.getElementById('pass_help').innerText = 'Wajib diisi untuk user baru (minimal 8 karakter)';

    toggleRoleFields();
    getModalInstance().show();
}

<?php if ($open_modal_auto): ?>
// Alur berantai dari "Tambah Pengelola" (-> pic) atau "Data Investor" (-> investor)
document.addEventListener('DOMContentLoaded', function () {
    resetForm();
    document.getElementById('modalTitle').innerText = <?= json_encode($prefill_role === 'investor' ? 'Buat Akun Login untuk Investor' : 'Buat Akun User untuk Pengelola') ?>;
    document.getElementById('role').value = <?= json_encode($prefill_role) ?>;
    <?php if ($prefill_id_cabang): ?>
    document.getElementById('id_cabang').value = '<?= (int) $prefill_id_cabang ?>';
    <?php endif; ?>
    <?php if ($prefill_id_pengelola): ?>
    document.getElementById('id_pengelola_hidden').value = '<?= (int) $prefill_id_pengelola ?>';
    <?php endif; ?>
    <?php if ($prefill_id_investor): ?>
    document.getElementById('id_investor').value = '<?= (int) $prefill_id_investor ?>';
    <?php endif; ?>
    <?php if ($prefill_username !== ''): ?>
    document.getElementById('username').value = <?= json_encode($prefill_username) ?>;
    <?php endif; ?>
    document.getElementById('pass_help').innerText = 'Buat password untuk akun ini';
    toggleRoleFields();
});
<?php endif; ?>
</script>