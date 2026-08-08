<?php

use App\Models\Cabang;

require '../bootstrap.php';
require '../config/koneksi.php';
include 'sidebar_pusat.php';

// 1. PROTEKSI ROLE PUSAT
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'pusat') {
    header("Location:../login");
    exit;
}

// Helper
if (!function_exists('h')) {
    function h($s)
    {
        return htmlspecialchars($s ?? '', ENT_QUOTES);
    }
}
if (!function_exists('csrf_token')) {
    function csrf_token()
    {
        if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
        return $_SESSION['csrf'];
    }
}
if (!function_exists('csrf_check')) {
    function csrf_check($t)
    {
        return hash_equals($_SESSION['csrf'] ?? '', $t);
    }
}

// Filter cabang
$search = $_GET['search'] ?? '';
$where_sql = "";
$params = [];
$types = "";
if ($search) {
    $where_sql = "WHERE c.nama_cabang LIKE ? ";
    $params[] = "%$search%";
    $types .= "s";
}

// BARU: Ambil total semua cabang untuk card di atas
$total_cabang_all = $conn->query("SELECT COUNT(*) as total FROM cabang")->fetch_assoc()['total'];

// Ambil data investor untuk dropdown
$list_investor = $conn->query("SELECT id_investor, nama_investor FROM investor WHERE status='aktif' ORDER BY nama_investor ASC");

// 1. Proses Tambah Cabang
if (isset($_POST['tambah'])) {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        die("<script>alert('Token tidak valid!'); history.back();</script>");
    }
    $nama = $_POST['nama_cabang'];
    $alamat = $_POST['alamat'];
    $telp = $_POST['no_telp'];
    $pengelola = $_POST['nama_pengelola'];

    $cek = $conn->prepare("SELECT * FROM cabang WHERE nama_cabang=?");
    $cek->bind_param("s", $nama);
    $cek->execute();
    if ($cek->get_result()->num_rows > 0) {
        echo "<script>alert('Nama cabang sudah ada!');</script>";
    } else {
        $stmt = $conn->prepare("INSERT INTO cabang (nama_cabang, alamat, no_telp, nama_pengelola) VALUES (?,?,?,?)");
        $stmt->bind_param("ssss", $nama, $alamat, $telp, $pengelola);
        $stmt->execute();
        echo "<script>alert('Cabang berhasil ditambah. Silakan daftarkan investor di menu edit'); window.location='data_cabang';</script>";
    }
}

// 2. Proses Edit Cabang
if (isset($_POST['edit'])) {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        die("<script>alert('Token tidak valid!'); history.back();</script>");
    }

    $id = $_POST['id_cabang'];
    $cabangModel = Cabang::find($id);
    $nama = $_POST['nama_cabang'];
    $alamat = $_POST['alamat'];
    $telp = $_POST['no_telp'];
    $pengelola = $_POST['nama_pengelola'];

    $stmt = $conn->prepare("UPDATE cabang SET nama_cabang=?, alamat=?, no_telp=?, nama_pengelola=? WHERE id_cabang=?");
    $stmt->bind_param("ssssi", $nama, $alamat, $telp, $pengelola, $id);
    $stmt->execute();

    // Update investor + periode
    $id_investor = $_POST['id_investor'] ?: null;
    $tgl_mulai_inv = $_POST['tgl_mulai_investor'];
    $tgl_selesai_inv = $_POST['tgl_selesai_investor'] ?: NULL;

    if ($id_investor) {
        if (count($cabangModel->investor) > 0) {
            $cabangModel->ChangeInvestor(
                $id_investor,
                $tgl_mulai_inv,
                $tgl_selesai_inv
            );
        } else {
            $close = $conn->prepare("UPDATE cabang_investor SET tgl_selesai=? WHERE id_cabang=? AND tgl_selesai IS NULL");
            $close->bind_param("si", $tgl_mulai_inv, $id);
            $close->execute();
            $ins = $conn->prepare("INSERT INTO cabang_investor (id_cabang, id_investor, tgl_mulai, tgl_selesai) VALUES (?,?,?,?)");
            $ins->bind_param("iiss", $id, $id_investor, $tgl_mulai_inv, $tgl_selesai_inv);
            $ins->execute();
        }
    } else {
        if (count($cabangModel->investor) > 0) {
            $prevInvestorId = $cabangModel->investor[0]->id_investor;
            $cabangModel->ChangeInvestor(
                $prevInvestorId,
                $tgl_mulai_inv,
                $tgl_selesai_inv
            );
        }
    }

    echo "<script>alert('Data berhasil diupdate'); window.location='data_cabang';</script>";
}

// 3. Proses Hapus Cabang
if (isset($_POST['hapus'])) {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        die("<script>alert('Token tidak valid!'); history.back();</script>");
    }

    $id = $_POST['id_cabang'];

    $cek = $conn->prepare("SELECT * FROM laporan_cabang WHERE id_cabang=?");
    $cek->bind_param("i", $id);
    $cek->execute();
    if ($cek->get_result()->num_rows > 0) {
        echo "<script>alert('Tidak bisa hapus! Cabang ini sudah ada data laporan');</script>";
    } else {
        $del = $conn->prepare("DELETE FROM cabang WHERE id_cabang=?");
        $del->bind_param("i", $id);
        $del->execute();
        $conn->query("DELETE FROM cabang_investor WHERE id_cabang=$id"); // hapus relasi juga
        echo "<script>alert('Cabang berhasil dihapus'); window.location='data_cabang';</script>";
    }
}

// 4. PAGINATION
$limit = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$page = $page < 1 ? 1 : $page;
$offset = ($page - 1) * $limit;

$sql_count = "SELECT COUNT(*) as total FROM cabang c $where_sql";
$stmt_count = $conn->prepare($sql_count);
if ($search) $stmt_count->bind_param($types, ...$params);
$stmt_count->execute();
$total_data = $stmt_count->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_data / $limit);

// 5. SELECT DATA + JOIN INVESTOR AKTIF
$sql = "SELECT c.*, i.nama_investor, ci.tgl_mulai as tgl_mulai_inv, ci.tgl_selesai as tgl_selesai_inv 
        FROM cabang c 
        LEFT JOIN cabang_investor ci ON c.id_cabang = ci.id_cabang AND ci.tgl_selesai IS NULL 
        LEFT JOIN investor i ON ci.id_investor = i.id_investor 
        $where_sql ORDER BY c.id_cabang DESC LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
if ($search) {
    $types .= "ii";
    $params[] = $limit;
    $params[] = $offset;
    $stmt->bind_param($types, ...$params);
} else {
    $stmt->bind_param("ii", $limit, $offset);
}
$stmt->execute();
$data = $stmt->get_result();
$no = $offset + 1;
?>

<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
    body {
        background-color: #f4f7fe !important;
        font-family: 'Plus Jakarta Sans', sans-serif !important;
        color: #1b2559;
    }

    .saas-card {
        background: #ffffff;
        border: none !important;
        border-radius: 20px !important;
        box-shadow: 0px 18px 40px rgba(112, 144, 176, 0.06) !important;
        padding: 24px;
    }

    .title-mark {
        width: 12px;
        height: 12px;
        background-color: #4318ff;
        border-radius: 4px;
        display: inline-block;
        margin-right: 10px;
    }

    .btn-premium {
        background-color: #4318ff !important;
        color: #ffffff !important;
        border: none !important;
        padding: 10px 20px;
        border-radius: 12px;
        font-weight: 600;
        font-size: 14px;
    }

    .btn-premium:hover {
        background-color: #3310cc !important;
    }

    .btn-premium-outline {
        background-color: #f4f7fe !important;
        color: #4318ff !important;
        border: 1px solid #e0e7ff !important;
        padding: 10px 20px;
        border-radius: 12px;
        font-weight: 600;
        font-size: 14px;
    }

    .form-control-premium {
        border-radius: 12px !important;
        border: 1px solid #e0e7ff !important;
        padding: 10px 16px;
    }

    .btn-action-edit {
        background-color: #fff3cd;
        color: #856404;
        border: none;
        padding: 8px 14px;
        border-radius: 10px;
        font-size: 14px;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }

    .btn-action-edit:hover {
        background-color: #ffe8a1;
    }

    .btn-action-delete {
        background-color: #fde8e8;
        color: #ef4444;
        border: none;
        padding: 8px 14px;
        border-radius: 10px;
        font-size: 14px;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }

    .btn-action-delete:hover {
        background-color: #fbd5d5;
    }

    .table-saas thead th {
        background-color: #f8f9fc !important;
        color: #8f9bba !important;
        font-weight: 600;
        font-size: 12px;
        text-transform: uppercase;
    }

    .modal-premium .modal-content {
        border-radius: 24px !important;
        border: none !important;
    }

    /* CSS Pagination Baru */
    .pagination .page-link {
        color: #4318ff;
        border: 1px solid #e0e7ff;
        font-weight: 600;
    }

    .pagination .page-link:hover {
        background-color: #f4f7fe;
        color: #3310cc;
    }

    .pagination .page-item.active .page-link {
        background-color: #4318ff;
        border-color: #4318ff;
        color: #fff;
    }

    .pagination .page-item.disabled .page-link {
        color: #a3aed0;
    }

    /* CSS Card Statistik Baru */
    .stat-card {
        border-radius: 16px;
        padding: 20px;
        background: #fff;
        border: 1px solid #e0e7ff;
    }

    .stat-card .icon {
        width: 48px;
        height: 48px;
        background: #f4f7fe;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
        color: #4318ff;
    }
</style>

<!-- Styling Tambahan untuk Menyempurnakan Tampilan Premium SaaS -->
<style>
    :root {
        --primary-color: #4f46e5;
        --primary-hover: #4338ca;
        --bg-glass: rgba(255, 255, 255, 0.9);
    }

    body {
        background-color: #f8fafc;
        color: #1e293b;
        font-family: 'Inter', system-ui, -apple-system, sans-serif;
    }

    /* Title Styling */
    .title-mark {
        width: 4px;
        height: 24px;
        background: var(--primary-color);
        border-radius: 4px;
        margin-right: 12px;
        display: inline-block;
    }

    /* Stat Card Modern */
    .stat-card {
        background: #ffffff;
        padding: 1.25rem 1.5rem;
        border-radius: 16px;
        border: 1px solid #e2e8f0;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -2px rgba(0, 0, 0, 0.05);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .stat-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.08);
    }
    .stat-card .icon {
        width: 48px;
        height: 48px;
        background: #e0e7ff;
        color: var(--primary-color);
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
    }

    /* Custom Form Control & Buttons */
    .form-control-premium {
        border-radius: 10px;
        border: 1px solid #cbd5e1;
        padding: 0.6rem 1rem;
        transition: all 0.2s;
    }
    .form-control-premium:focus {
        border-color: var(--primary-color);
        box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1);
    }

    .btn-premium {
        background-color: var(--primary-color);
        color: #fff;
        border-radius: 10px;
        padding: 0.6rem 1.25rem;
        font-weight: 500;
        border: none;
        transition: all 0.2s;
    }
    .btn-premium:hover {
        background-color: var(--primary-hover);
        color: #fff;
        box-shadow: 0 4px 12px rgba(79, 70, 229, 0.25);
    }

    .btn-premium-outline {
        background-color: #fff;
        color: #475569;
        border: 1px solid #cbd5e1;
        border-radius: 10px;
        padding: 0.6rem 1.25rem;
        font-weight: 500;
        transition: all 0.2s;
    }
    .btn-premium-outline:hover {
        background-color: #f1f5f9;
        color: #0f172a;
    }

    /* Table SaaS Card Styling */
    .saas-card {
        border-radius: 16px !important;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -2px rgba(0, 0, 0, 0.05);
        background: #ffffff;
    }

    .table-saas thead th {
        background-color: #f8fafc;
        color: #64748b;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        font-weight: 700;
        padding: 1rem 1.25rem;
        border-bottom: 1px solid #e2e8f0;
    }

    .table-saas tbody td {
        padding: 1rem 1.25rem;
        border-bottom: 1px solid #f1f5f9;
        color: #334155;
    }

    .table-saas tbody tr:last-child td {
        border-bottom: none;
    }

    .table-saas tbody tr:hover {
        background-color: #f8fafc;
    }

    /* Action Buttons */
    .btn-action-edit {
        background-color: #f1f5f9;
        color: #0f172a;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        padding: 0.4rem 0.8rem;
        font-size: 0.875rem;
        font-weight: 500;
    }
    .btn-action-edit:hover {
        background-color: #e2e8f0;
    }

    .btn-action-delete {
        background-color: #fef2f2;
        color: #dc2626;
        border: 1px solid #fecaca;
        border-radius: 8px;
        padding: 0.4rem 0.8rem;
        font-size: 0.875rem;
        font-weight: 500;
    }
    .btn-action-delete:hover {
        background-color: #fee2e2;
    }

    /* Modals Styling */
    .modal-premium .modal-content {
        border-radius: 20px;
        border: none;
        box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
    }
    .modal-premium .modal-header {
        border-bottom: 1px solid #f1f5f9;
        padding: 1.5rem;
    }
    .modal-premium .modal-body {
        padding: 1.5rem;
    }
    .modal-premium .modal-footer {
        border-top: 1px solid #f1f5f9;
        padding: 1.25rem 1.5rem;
    }
</style>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <div class="d-flex align-items-center">
                <span class="title-mark"></span>
                <h3 class="fw-bold mb-0">Data Cabang</h3>
            </div>
            <span class="text-muted small ms-4">Manajemen data operasional seluruh cabang resmi</span>
        </div>
    </div>

    <!-- CARD TOTAL CABANG BARU -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="stat-card d-flex align-items-center gap-3">
                <div class="icon"><i class="bi bi-building"></i></div>
                <div>
                    <div class="text-muted small">Total Cabang</div>
                    <div class="fw-bold fs-3"><?= number_format($total_cabang_all) ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex flex-column flex-md-row justify-content-between gap-3 mb-4">
        <button class="btn btn-premium" data-bs-toggle="modal" data-bs-target="#modalTambah">
            <i class="bi bi-plus-circle-fill me-2"></i> Tambah Cabang Baru
        </button>
        <form method="GET" class="d-flex gap-2">
            <input type="text" name="search" class="form-control form-control-premium" placeholder="Cari nama cabang..." value="<?= h($search) ?>" style="width: 280px;">
            <button type="submit" class="btn btn-premium-outline"><i class="bi bi-funnel-fill"></i> Filter</button>
        </form>
    </div>

    <div class="card saas-card p-0 overflow-hidden border-0">
        <div class="table-responsive-md">
            <table class="table table-saas align-middle mb-0">
                <thead>
                    <tr>
                        <th width="5%" class="text-center">No</th>
                        <th width="15%">Nama Cabang</th>
                        <th width="20%">Alamat Lengkap</th>
                        <th width="12%">Telephone</th>
                        <th width="14%">Pengelola</th>
                        <th width="14%">Investor Aktif</th>
                        <th width="20%" class="text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($data->num_rows == 0): ?>
                        <tr>
                            <td colspan="7" class="text-center py-5 text-muted">Data tidak ditemukan</td>
                        </tr>
                    <?php endif; ?>
                    <?php while ($row = $data->fetch_assoc()): ?>
                        <?php $rowCabang = Cabang::find($row['id_cabang']); ?>
                        <tr>
                            <td data-label="No" class="text-center text-muted fw-semibold"><?= $no++ ?></td>
                            <td data-label="Nama Cabang"><span class="fw-bold"><?= h($row['nama_cabang']) ?></span></td>
                            <td data-label="Alamat Lengkap" class="text-secondary"><?= h($row['alamat']) ?></td>
                            <td data-label="No Telp" class="text-secondary"><?= h($row['no_telp']) ?></td>
                            <td data-label="Pengelola"><span class="fw-semibold"><?= h($row['nama_pengelola']) ?></span></td>
                            <td data-label="Investor Aktif">
                                <?php if ($rowCabang->ActiveInvestor()): ?>
                                    <span class="fw-semibold d-block"><?= h($rowCabang->ActiveInvestor()->nama_investor) ?></span>
                                    <small class="text-muted">
                                        <?= date('d M Y', strtotime($rowCabang->ActiveInvestor()->pivot->tgl_mulai)) ?> -
                                        <?= $rowCabang->ActiveInvestor()->pivot->tgl_selesai ? date('d M Y', strtotime($rowCabang->ActiveInvestor()->pivot->tgl_selesai)) : 'Sekarang' ?>
                                    </small>
                                <?php else: ?>
                                    <span class="badge bg-warning-subtle text-warning">Belum ada</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Aksi" class="text-center">
                                <div class="d-inline-flex gap-2 w-100 justify-content-end justify-content-md-center">
                                    <button class="btn btn-action-edit" data-bs-toggle="modal" data-bs-target="#modalEdit<?= $row['id_cabang'] ?>" title="Edit">
                                        <i class="bi bi-pencil-square me-1 d-md-none"></i> Edit
                                    </button>

                                    <form method="POST" class="d-inline" onsubmit="return confirm('Yakin hapus cabang <?= h($row['nama_cabang']) ?>? Data riwayat investor & laporan tidak akan terhapus')">
                                        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                                        <input type="hidden" name="id_cabang" value="<?= $row['id_cabang'] ?>">
                                        <button type="submit" name="hapus" class="btn btn-action-delete" title="Hapus">
                                            <i class="bi bi-trash-fill me-1 d-md-none"></i> Hapus
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>

                        <!-- Modal Edit -->
                        <div class="modal fade modal-premium" id="modalEdit<?= $row['id_cabang'] ?>" tabindex="-1">
                            <div class="modal-dialog modal-dialog-centered modal-lg">
                                <form method="POST">
                                    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title fw-bold">Modifikasi Data Cabang</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <input type="hidden" name="id_cabang" value="<?= $row['id_cabang'] ?>">
                                            <div class="row g-3">
                                                <div class="col-md-6"><label class="form-label">Nama Cabang</label><input type="text" name="nama_cabang" value="<?= h($row['nama_cabang']) ?>" class="form-control form-control-premium" required></div>
                                                <div class="col-md-6"><label class="form-label">Telephone</label><input type="text" name="no_telp" value="<?= h($row['no_telp']) ?>" class="form-control form-control-premium" required></div>
                                                <div class="col-md-12"><label class="form-label">Alamat Lengkap</label><textarea name="alamat" class="form-control form-control-premium" rows="2" required><?= h($row['alamat']) ?></textarea></div>
                                                <div class="col-md-6"><label class="form-label">Nama Pengelola</label><input type="text" name="nama_pengelola" value="<?= h($row['nama_pengelola']) ?>" class="form-control form-control-premium" required></div>
                                                <div class="col-md-6">
                                                    <label class="form-label">Ganti Investor</label>
                                                    <select name="id_investor" class="form-control form-control-premium">
                                                        <option value="">-- Tetap / Tidak Ganti --</option>
                                                        <?php $list_investor->data_seek(0);
                                                        while ($inv = $list_investor->fetch_assoc()): ?>
                                                            <option value="<?= $inv['id_investor'] ?>"><?= h($inv['nama_investor']) ?></option>
                                                        <?php endwhile; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-6"><label class="form-label">Tgl Mulai Investasi</label><input type="date" name="tgl_mulai_investor" value="<?= date('Y-m-d') ?>" class="form-control form-control-premium"></div>
                                                <div class="col-md-6"><label class="form-label">Tgl Selesai Investasi</label><input type="date" name="tgl_selesai_investor" class="form-control form-control-premium"></div>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-premium-outline" data-bs-dismiss="modal">Batal</button>
                                            <button type="submit" name="edit" class="btn btn-premium">Simpan Perubahan</button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>

        <!-- PAGINATION BARU -->
        <?php if ($total_pages > 1): ?>
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-center p-4 border-top">
                <div class="text-muted small mb-3 mb-md-0">
                    Menampilkan <?= $offset + 1 ?> - <?= min($offset + $limit, $total_data) ?> dari <?= $total_data ?> data
                </div>
                <nav>
                    <ul class="pagination pagination-sm mb-0" style="--bs-pagination-border-radius: 10px;">
                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>" style="border-radius: 10px 0 0 10px;">
                                <i class="bi bi-chevron-left"></i>
                            </a>
                        </li>
                        <?php
                        $range = 2;
                        $start = max(1, $page - $range);
                        $end = min($total_pages, $page + $range);
                        if ($start > 1) {
                            echo '<li class="page-item"><a class="page-link" href="?page=1&search=' . urlencode($search) . '">1</a></li>';
                            if ($start > 2) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                        }
                        for ($i = $start; $i <= $end; $i++):
                        ?>
                            <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>">
                                    <?= $i ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                        <?php
                        if ($end < $total_pages) {
                            if ($end < $total_pages - 1) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                            echo '<li class="page-item"><a class="page-link" href="?page=' . $total_pages . '&search=' . urlencode($search) . '">' . $total_pages . '</a></li>';
                        }
                        ?>
                        <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                            <a class="page-link" href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>" style="border-radius: 0 10px 10px 0;">
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        </li>
                    </ul>
                </nav>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal Tambah -->
<div class="modal fade modal-premium" id="modalTambah" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form method="POST">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Registrasi Cabang Baru</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label">Nama Cabang</label><input type="text" name="nama_cabang" class="form-control form-control-premium" required></div>
                        <div class="col-md-6"><label class="form-label">No Telp</label><input type="text" name="no_telp" class="form-control form-control-premium" required></div>
                        <div class="col-md-12"><label class="form-label">Alamat Lengkap</label><textarea name="alamat" class="form-control form-control-premium" rows="2" required></textarea></div>
                        <div class="col-md-12"><label class="form-label">Nama Pengelola</label><input type="text" name="nama_pengelola" class="form-control form-control-premium" required></div>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-premium-outline" data-bs-dismiss="modal">Batal</button><button type="submit" name="tambah" class="btn btn-premium">Simpan Unit Cabang</button></div>
            </div>
        </form>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>