<?php
require '../config/koneksi.php';
include 'sidebar_pusat.php';

// Proteksi Role Pusat
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'pusat') {
    header("Location:../login");
    exit;
}

// Helper Functions
if (!function_exists('h')) {
    function h($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('csrf_token')) {
    function csrf_token() {
        if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
        return $_SESSION['csrf'];
    }
}
if (!function_exists('csrf_check')) {
    function csrf_check($t) { return hash_equals($_SESSION['csrf'] ?? '', $t); }
}

// Filter Search
$search = trim($_GET['search'] ?? '');
$where_sql = "";
$params = [];
$types = "";
if ($search !== '') {
    $where_sql = "WHERE c.nama_cabang LIKE ? ";
    $params[] = "%$search%";
    $types .= "s";
}

$total_cabang_all = $conn->query("SELECT COUNT(*) as total FROM cabang")->fetch_assoc()['total'] ?? 0;
$list_investor = $conn->query("SELECT id_investor, nama_investor FROM investor WHERE status='aktif' ORDER BY nama_investor ASC");

// 1. TAMBAH CABANG
if (isset($_POST['tambah'])) {
    if (!csrf_check($_POST['csrf'] ?? '')) die("<script>alert('Token tidak valid!'); history.back();</script>");
    
    $nama = trim($_POST['nama_cabang'] ?? '');
    $alamat = trim($_POST['alamat'] ?? '');
    $telp = trim($_POST['no_telp'] ?? '');
    $pengelola = trim($_POST['nama_pengelola'] ?? '');
    $no_rekening = trim($_POST['no_rekening'] ?? '');
    $nama_bank = trim($_POST['nama_bank'] ?? '');
    $atas_nama_rekening = trim($_POST['atas_nama_rekening'] ?? '');

    $cek = $conn->prepare("SELECT id_cabang FROM cabang WHERE nama_cabang=?");
    $cek->bind_param("s", $nama);
    $cek->execute();
    if ($cek->get_result()->num_rows > 0) {
        echo "<script>alert('Nama cabang sudah ada!');</script>";
    } else {
        $stmt = $conn->prepare("INSERT INTO cabang (nama_cabang, alamat, no_telp, nama_pengelola, no_rekening_cabang, nama_bank_cabang, atas_nama_cabang) VALUES (?,?,?,?,?,?,?)");
        $stmt->bind_param("sssssss", $nama, $alamat, $telp, $pengelola, $no_rekening, $nama_bank, $atas_nama_rekening);
        
        if ($stmt->execute()) {
            $id_cabang_baru = $stmt->insert_id;
            audit($conn, 'cabang_tambah', 'cabang', $id_cabang_baru, ['nama' => $nama]);
            echo "<script>
                alert('Cabang berhasil ditambah! Lanjut memasukkan data Pengelola.');
                window.location='data_pengelola?open_modal=1&id_cabang=" . $id_cabang_baru . "';
            </script>";
            exit;
        }
        $stmt->close();
    }
    $cek->close();
}

// 2. EDIT CABANG
if (isset($_POST['edit'])) {
    if (!csrf_check($_POST['csrf'] ?? '')) die("<script>alert('Token tidak valid!'); history.back();</script>");

    $id = (int)($_POST['id_cabang'] ?? 0);
    $nama = trim($_POST['nama_cabang'] ?? '');
    $alamat = trim($_POST['alamat'] ?? '');
    $telp = trim($_POST['no_telp'] ?? '');
    $pengelola = trim($_POST['nama_pengelola'] ?? '');
    $no_rekening = trim($_POST['no_rekening_cabang'] ?? '');
    $nama_bank = trim($_POST['nama_bank_cabang'] ?? '');
    $atas_nama_rekening = trim($_POST['atas_nama_cabang'] ?? '');

    $stmt = $conn->prepare("UPDATE cabang SET nama_cabang=?, alamat=?, no_telp=?, nama_pengelola=?, no_rekening_cabang=?, nama_bank_cabang=?, atas_nama_cabang=? WHERE id_cabang=?");
    $stmt->bind_param("sssssssi", $nama, $alamat, $telp, $pengelola, $no_rekening, $nama_bank, $atas_nama_rekening, $id);
    $stmt->execute();
    $stmt->close();
    audit($conn, 'cabang_edit', 'cabang', $id, ['nama' => $nama]);

    // Pergantian investor — tanggal diatur otomatis oleh sistem (mulai hari ini)
    $id_investor_baru = !empty($_POST['id_investor']) ? (int)$_POST['id_investor'] : null;

    if ($id_investor_baru) {
        // Siapa investor aktif cabang ini sekarang?
        $qcur = $conn->prepare("
            SELECT id, id_investor, tgl_mulai
            FROM cabang_investor
            WHERE id_cabang = ? AND (tgl_selesai IS NULL OR tgl_selesai >= CURDATE())
            ORDER BY tgl_mulai DESC, id DESC
            LIMIT 1
        ");
        $qcur->bind_param("i", $id);
        $qcur->execute();
        $cur = $qcur->get_result()->fetch_assoc();
        $qcur->close();

        // Hanya proses bila investornya memang berbeda dari yang aktif
        if (!$cur || (int)$cur['id_investor'] !== $id_investor_baru) {

            if ($cur && $cur['tgl_mulai'] === date('Y-m-d')) {
                // Koreksi kesalahan di hari yang sama: cukup ganti investornya,
                // jangan buat baris relasi 0 hari.
                $fix = $conn->prepare("UPDATE cabang_investor SET id_investor = ? WHERE id = ?");
                $fix->bind_param("ii", $id_investor_baru, $cur['id']);
                $fix->execute();
                $fix->close();
            } else {
                // Akhiri semua relasi yang masih aktif per hari ini
                $close = $conn->prepare("
                    UPDATE cabang_investor
                    SET tgl_selesai = CURDATE()
                    WHERE id_cabang = ? AND (tgl_selesai IS NULL OR tgl_selesai >= CURDATE())
                ");
                $close->bind_param("i", $id);
                $close->execute();
                $close->close();

                // Relasi investor baru: mulai hari ini, belum ada tanggal selesai
                $ins = $conn->prepare("
                    INSERT INTO cabang_investor (id_cabang, id_investor, tgl_mulai, tgl_selesai)
                    VALUES (?, ?, CURDATE(), NULL)
                ");
                $ins->bind_param("ii", $id, $id_investor_baru);
                $ins->execute();
                $ins->close();
            }
        }
    } elseif (isset($_POST['id_investor'])) {
        // Dipilih "Tanpa Investor" — akhiri relasi aktif yang ada (per hari ini)
        $close = $conn->prepare("
            UPDATE cabang_investor
            SET tgl_selesai = CURDATE()
            WHERE id_cabang = ? AND (tgl_selesai IS NULL OR tgl_selesai >= CURDATE())
        ");
        $close->bind_param("i", $id);
        $close->execute();
        $close->close();
    }

    if (!empty($id_investor_baru)) {
        audit($conn, 'cabang_ganti_investor', 'cabang', $id, ['id_investor_baru' => (int) $id_investor_baru]);
    }

    echo "<script>alert('Data cabang berhasil diupdate'); window.location='data_cabang';</script>";
    exit;
}

// 3. HAPUS CABANG
if (isset($_POST['hapus'])) {
    if (!csrf_check($_POST['csrf'] ?? '')) die("<script>alert('Token tidak valid!'); history.back();</script>");
    $id = (int)($_POST['id_cabang'] ?? 0);

    $cek = $conn->prepare("SELECT id_cabang FROM laporan_cabang WHERE id_cabang=? LIMIT 1");
    $cek->bind_param("i", $id);
    $cek->execute();
    if ($cek->get_result()->num_rows > 0) {
        echo "<script>alert('Tidak bisa hapus! Cabang ini sudah ada data laporan'); history.back();</script>";
    } else {
        $del = $conn->prepare("DELETE FROM cabang WHERE id_cabang=?");
        $del->bind_param("i", $id);
        $del->execute();
        $del->close();

        $del_inv = $conn->prepare("DELETE FROM cabang_investor WHERE id_cabang=?");
        $del_inv->bind_param("i", $id);
        $del_inv->execute();
        $del_inv->close();

        audit($conn, 'cabang_hapus', 'cabang', $id);

        echo "<script>alert('Cabang berhasil dihapus'); window.location='data_cabang';</script>";
        exit;
    }
    $cek->close();
}

// PAGINATION
$limit = 10;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

$sql_count = "SELECT COUNT(*) as total FROM cabang c $where_sql";
$stmt_count = $conn->prepare($sql_count);
if ($search !== '') {
    $stmt_count->bind_param($types, ...$params);
}
$stmt_count->execute();
$total_data = $stmt_count->get_result()->fetch_assoc()['total'] ?? 0;
$stmt_count->close();
$total_pages = ceil($total_data / $limit);

// SELECT DATA CABANG — investor aktif diambil lewat subquery (aman dari baris ganda)
$sql = "SELECT c.*,
        (SELECT ci.id_investor FROM cabang_investor ci
           WHERE ci.id_cabang = c.id_cabang
             AND (ci.tgl_selesai IS NULL OR ci.tgl_selesai >= CURDATE())
           ORDER BY ci.tgl_mulai DESC, ci.id DESC LIMIT 1) AS investor_aktif_id,
        (SELECT i.nama_investor FROM cabang_investor ci
           JOIN investor i ON i.id_investor = ci.id_investor
           WHERE ci.id_cabang = c.id_cabang
             AND (ci.tgl_selesai IS NULL OR ci.tgl_selesai >= CURDATE())
           ORDER BY ci.tgl_mulai DESC, ci.id DESC LIMIT 1) AS nama_investor
        FROM cabang c
        $where_sql ORDER BY c.id_cabang DESC LIMIT ? OFFSET ?";

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
$no = $offset + 1;
?>

<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<style>
    body { 
        background-color: #f8fafc !important; 
        font-family: 'Plus Jakarta Sans', sans-serif !important; 
        color: #334155;
    }
    .title-mark { 
        width: 4px; 
        height: 24px; 
        background: #4f46e5; 
        border-radius: 4px; 
        margin-right: 12px; 
        display: inline-block; 
    }
    .stat-card { 
        background: #ffffff; 
        padding: 1.25rem 1.5rem; 
        border-radius: 16px; 
        border: 1px solid #e2e8f0; 
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.02), 0 2px 4px -1px rgba(0, 0, 0, 0.02);
    }
    .form-control-premium { 
        border-radius: 10px; 
        border: 1px solid #cbd5e1; 
        padding: 0.6rem 1rem; 
        font-size: 0.9rem;
        transition: all 0.2s ease;
    }
    .form-control-premium:focus {
        border-color: #4f46e5;
        box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
    }
    .btn-premium { 
        background-color: #4f46e5 !important; 
        color: #fff !important; 
        border-radius: 10px; 
        padding: 0.6rem 1.25rem; 
        font-weight: 600;
        border: none;
        transition: all 0.2s ease;
    }
    .btn-premium:hover {
        background-color: #4338ca !important;
        box-shadow: 0 4px 12px rgba(79, 70, 229, 0.25);
    }
    .btn-premium-outline { 
        background-color: #fff !important; 
        color: #475569 !important; 
        border: 1px solid #cbd5e1 !important; 
        border-radius: 10px; 
        font-weight: 600;
        transition: all 0.2s ease;
    }
    .btn-premium-outline:hover {
        background-color: #f1f5f9 !important;
        border-color: #94a3b8 !important;
    }
    .table-saas thead th { 
        background-color: #f8fafc; 
        color: #64748b; 
        font-size: 0.75rem; 
        text-transform: uppercase; 
        letter-spacing: 0.05em;
        padding: 1rem 1.25rem; 
        border-bottom: 1px solid #e2e8f0;
    }
    .table-saas tbody td { 
        padding: 1rem 1.25rem; 
        border-bottom: 1px solid #f1f5f9; 
        font-size: 0.9rem;
    }
    .badge-soft-primary { background-color: #e0e7ff; color: #4338ca; }
    .badge-soft-secondary { background-color: #f1f5f9; color: #475569; }
    .badge-soft-info { background-color: #e0f2fe; color: #0369a1; }
    .badge-soft-warning { background-color: #fef3c7; color: #b45309; }


    @media (max-width: 767.98px) {
        .mobile-search-form {
            width: 100% !important;
        }
        .mobile-search-form .form-control-premium {
            width: 100% !important;
        }
        .table-saas, .table-saas thead, .table-saas tbody, .table-saas th, .table-saas td, .table-saas tr {
            display: block;
        }
        .table-saas thead {
            display: none;
        }
        .table-saas tbody tr {
            margin-bottom: 1rem;
            background: #fff;
            border-radius: 16px;
            padding: 1rem;
            border: 1px solid #e2e8f0;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }
        .table-saas tbody td {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.65rem 0;
            border-bottom: 1px dashed #f1f5f9;
            text-align: right;
        }
        .table-saas tbody td:last-child {
            border-bottom: none;
            padding-bottom: 0;
            margin-top: 0.5rem;
            justify-content: flex-end;
            gap: 0.5rem;
        }
        .table-saas tbody td::before {
            content: attr(data-label);
            font-weight: 600;
            font-size: 0.75rem;
            color: #64748b;
            text-transform: uppercase;
            text-align: left;
            padding-right: 1rem;
        }
    }
</style>

<div class="container-fluid py-4">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <div class="d-flex align-items-center">
                <span class="title-mark"></span>
                <h3 class="fw-bold mb-0">Data Cabang</h3>
            </div>
            <span class="text-muted small ms-4 d-block d-md-inline mt-1 mt-md-0">Kelola data operasional dan rekening investor per cabang</span>
        </div>
    </div>

    <!-- Stat Card -->
    <div class="row mb-4">
        <div class="col-12 col-md-4">
            <div class="stat-card d-flex align-items-center gap-3">
                <div class="bg-primary-subtle text-primary p-3 rounded-3 fs-3 d-flex align-items-center justify-content-center" style="width: 54px; height: 54px;">
                    <i class="bi bi-building"></i>
                </div>
                <div>
                    <div class="text-muted small fw-medium">Total Cabang</div>
                    <div class="fw-bold fs-3"><?= number_format($total_cabang_all) ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Action & Filter Bar -->
    <div class="d-flex flex-column flex-md-row justify-content-between gap-3 mb-4">
        <button class="btn btn-premium d-flex align-items-center justify-content-center gap-2" data-bs-toggle="modal" data-bs-target="#modalTambah">
            <i class="bi bi-plus-circle-fill"></i> Tambah Cabang
        </button>
        <form method="GET" class="d-flex gap-2 mobile-search-form">
            <input type="text" name="search" class="form-control form-control-premium" placeholder="Cari nama cabang..." value="<?= h($search) ?>" style="width: 280px;">
            <button type="submit" class="btn btn-premium-outline d-flex align-items-center gap-2"><i class="bi bi-funnel-fill"></i> Filter</button>
        </form>
    </div>

    <!-- Data Table / Card View -->
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-transparent bg-md-white">
        <div class="table-responsive-md">
            <table class="table table-saas align-middle mb-0">
                <thead>
                    <tr>
                        <th width="5%" class="text-center">No</th>
                        <th width="20%">Nama Cabang</th>
                        <th width="25%">Pengelola / Alamat</th>
                        <th width="25%">Rekening Pembayaran</th>
                        <th width="15%">Investor Aktif</th>
                        <th width="10%" class="text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($data->num_rows == 0): ?>
                        <tr><td colspan="6" class="text-center py-5 text-muted bg-white rounded-4">Data cabang belum tersedia.</td></tr>
                    <?php endif; ?>
                    <?php while ($row = $data->fetch_assoc()): ?>
                        <tr>
                            <td data-label="No" class="text-md-center text-muted fw-semibold"><?= $no++ ?></td>
                            <td data-label="Nama Cabang"><span class="fw-bold text-dark"><?= h($row['nama_cabang']) ?></span></td>
                            <td data-label="Pengelola / Telp">
                                <div>
                                    <div class="fw-semibold text-dark"><?= h($row['nama_pengelola']) ?></div>
                                    <small class="text-muted d-block"><i class="bi bi-telephone me-1"></i><?= h($row['no_telp']) ?></small>
                                </div>
                            </td>
                            <td data-label="Rekening Cabang">
                                <?php if (!empty($row['no_rekening_cabang'])): ?>
                                    <div>
                                        <div class="fw-bold text-primary mb-0" style="font-size: 0.85rem;"><?= h($row['nama_bank_cabang']) ?> - <?= h($row['no_rekening_cabang']) ?></div>
                                        <small class="text-muted d-block" style="font-size: 0.75rem;">a.n <?= h($row['atas_nama_cabang']) ?></small>
                                    </div>
                                <?php else: ?>
                                    <span class="badge badge-soft-secondary rounded-pill px-2.5 py-1.5 fw-medium">Belum Diatur</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Investor Aktif">
                                <?php if (!empty($row['nama_investor'])): ?>
                                    <span class="badge badge-soft-info rounded-pill px-2.5 py-1.5 fw-semibold"><?= h($row['nama_investor']) ?></span>
                                <?php else: ?>
                                    <span class="badge badge-soft-warning rounded-pill px-2.5 py-1.5 fw-medium">Tanpa Investor</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Aksi" class="text-md-center">
                                <button class="btn btn-sm btn-light border rounded-3 text-secondary" data-bs-toggle="modal" data-bs-target="#modalEdit<?= $row['id_cabang'] ?>" title="Edit Cabang">
                                <i class="bi bi-pencil-square"></i>
                            </button>
                            <form method="POST" class="d-inline m-0" onsubmit="return confirm('Hapus cabang ini?')">
                                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                                <input type="hidden" name="id_cabang" value="<?= $row['id_cabang'] ?>">
                                <button type="submit" name="hapus" class="btn btn-sm btn-outline-danger rounded-3" title="Hapus Cabang">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>

                        <!-- Modal Edit -->
                        <div class="modal fade" id="modalEdit<?= $row['id_cabang'] ?>" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog modal-dialog-centered modal-lg">
                                <form method="POST">
                                    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                                    <input type="hidden" name="id_cabang" value="<?= $row['id_cabang'] ?>">
                                    <div class="modal-content rounded-4 border-0 shadow">
                                        <div class="modal-header border-bottom-0 pb-0">
                                            <h5 class="modal-title fw-bold">Edit Cabang: <?= h($row['nama_cabang'] ?? '') ?></h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body py-4">
                                            <div class="row g-3">
                                                <div class="col-12 col-md-4">
                                                    <label class="form-label fw-medium text-secondary small">Nama Cabang</label>
                                                    <input type="text" name="nama_cabang" value="<?= h($row['nama_cabang'] ?? '') ?>" class="form-control form-control-premium" required>
                                                </div>
                                                <div class="col-12 col-md-4">
                                                    <label class="form-label fw-medium text-secondary small">No. Telepon</label>
                                                    <input type="text" name="no_telp" value="<?= h($row['no_telp'] ?? '') ?>" class="form-control form-control-premium" required>
                                                </div>
                                                <div class="col-12 col-md-4">
                                                    <label class="form-label fw-medium text-secondary small">Nama Pengelola</label>
                                                    <input type="text" name="nama_pengelola" value="<?= h($row['nama_pengelola'] ?? '') ?>" class="form-control form-control-premium" required>
                                                </div>
                                                <div class="col-12">
                                                    <label class="form-label fw-medium text-secondary small">Alamat Cabang</label>
                                                    <textarea name="alamat" class="form-control form-control-premium" rows="2" required><?= h($row['alamat'] ?? '') ?></textarea>
                                                </div>
                                                
                                                <!-- INFORMASI REKENING SPESIFIK CABANG -->
                                                <div class="col-12"><hr class="my-2 border-light"><strong class="text-primary small uppercase tracking-wider">Data Rekening Bank Cabang Ini</strong></div>
                                                <div class="col-12 col-md-4">
                                                    <label class="form-label fw-medium text-secondary small">Nama Bank</label>
                                                    <input type="text" name="nama_bank_cabang" value="<?= h($row['nama_bank_cabang'] ?? '') ?>" class="form-control form-control-premium" placeholder="BCA/Mandiri/BRI" required>
                                                </div>
                                                <div class="col-12 col-md-4">
                                                    <label class="form-label fw-medium text-secondary small">No. Rekening</label>
                                                    <input type="text" name="no_rekening_cabang" value="<?= h($row['no_rekening_cabang'] ?? '') ?>" class="form-control form-control-premium" required>
                                                </div>
                                                <div class="col-12 col-md-4">
                                                    <label class="form-label fw-medium text-secondary small">Atas Nama Rekening</label>
                                                    <input type="text" name="atas_nama_cabang" value="<?= h($row['atas_nama_cabang'] ?? '') ?>" class="form-control form-control-premium" required>
                                                </div>

                                                <div class="col-12"><hr class="my-2 border-light"></div>
                                                <div class="col-12">
                                                    <label class="form-label fw-medium text-secondary small">Investor Aktif</label>
                                                    <select name="id_investor" class="form-select form-control-premium">
                                                        <option value="">-- Tanpa Investor --</option>
                                                        <?php
                                                        $inv_aktif_id = $row['investor_aktif_id'] ?? null;
                                                        if (!empty($list_investor) && $list_investor->num_rows > 0) {
                                                            $list_investor->data_seek(0);
                                                            while ($inv = $list_investor->fetch_assoc()):
                                                        ?>
                                                            <option value="<?= $inv['id_investor'] ?>" <?= ((string)$inv_aktif_id === (string)$inv['id_investor']) ? 'selected' : '' ?>>
                                                                <?= h($inv['nama_investor'] ?? '') ?>
                                                            </option>
                                                        <?php
                                                            endwhile;
                                                        }
                                                        ?>
                                                    </select>
                                                    <small class="text-muted d-block mt-1">
                                                        <i class="bi bi-info-circle me-1"></i>
                                                        Jika investor diganti, tanggalnya diatur otomatis: relasi lama berakhir hari ini dan yang baru mulai hari ini.
                                                    </small>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="modal-footer border-top-0 pt-0">
                                            <button type="button" class="btn btn-premium-outline px-4" data-bs-dismiss="modal">Batal</button>
                                            <button type="submit" name="edit" class="btn btn-premium px-4">Simpan Perubahan</button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>

        <?php render_pagination($page, $total_pages, ['from' => min($offset + 1, $total_data), 'to' => min($offset + $limit, $total_data), 'total' => $total_data, 'label' => 'cabang']); ?>
    </div>
</div>

<!-- Modal Tambah Cabang -->
<div class="modal fade" id="modalTambah" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form method="POST">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <div class="modal-content rounded-4 border-0 shadow">
                <div class="modal-header border-bottom-0 pb-0">
                    <h5 class="modal-title fw-bold">Tambah Cabang Baru</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body py-4">
                    <div class="row g-3">
                        <div class="col-12 col-md-4"><label class="form-label fw-medium text-secondary small">Nama Cabang</label><input type="text" name="nama_cabang" class="form-control form-control-premium" required></div>
                        <div class="col-12 col-md-4"><label class="form-label fw-medium text-secondary small">No Telp</label><input type="text" name="no_telp" class="form-control form-control-premium" required></div>
                        <div class="col-12 col-md-4"><label class="form-label fw-medium text-secondary small">Nama Pengelola</label><input type="text" name="nama_pengelola" class="form-control form-control-premium" required></div>
                        <div class="col-12"><label class="form-label fw-medium text-secondary small">Alamat Lengkap</label><textarea name="alamat" class="form-control form-control-premium" rows="2" required></textarea></div>
                        
                        <!-- REKENING PER CABANG -->
                        <div class="col-12"><hr class="my-2 border-light"><strong class="text-primary small uppercase tracking-wider">Informasi Rekening Bank Cabang</strong></div>
                        <div class="col-12 col-md-4"><label class="form-label fw-medium text-secondary small">Nama Bank</label><input type="text" name="nama_bank" placeholder="BCA / Mandiri" class="form-control form-control-premium" required></div>
                        <div class="col-12 col-md-4"><label class="form-label fw-medium text-secondary small">No. Rekening</label><input type="text" name="no_rekening" class="form-control form-control-premium" required></div>
                        <div class="col-12 col-md-4"><label class="form-label fw-medium text-secondary small">Atas Nama Rekening</label><input type="text" name="atas_nama_rekening" class="form-control form-control-premium" required></div>
                    </div>
                </div>
                <div class="modal-footer border-top-0 pt-0">
                    <button type="button" class="btn btn-premium-outline px-4" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" name="tambah" class="btn btn-premium px-4">Lanjut ke Data Pengelola &rarr;</button>
                </div>
            </div>
        </form>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>