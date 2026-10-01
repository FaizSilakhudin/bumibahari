<?php
/**
 * KASBON PENGELOLA — admin_pusat
 *
 * Buku catatan kasbon pengelola yang berdiri sendiri (riwayat per pengelola,
 * lintas periode) -- TIDAK otomatis terhubung ke kolom "Kasbon Pengelola" di
 * Rekapitulasi > Koreksi Dividen: Sisi Investor (itu per-bulan, mempengaruhi
 * payroll bulan tsb). Halaman ini murni pembukuan utang-piutang kasbon.
 *
 * sisa_kasbon = jumlah_kasbon - jumlah_dikembalikan, selalu dihitung saat
 * ditampilkan (tidak disimpan sebagai kolom terpisah).
 */

require '../config/koneksi.php';

// Role check SEBELUM sidebar_pusat.php di-include -- sidebar_pusat.php sendiri
// langsung mencetak HTML begitu di-include, jadi kalau include-nya duluan,
// header("Location: ...") di blok POST di bawah akan gagal ("headers already
// sent"). Pola ini SAMA PERSIS dengan data_pengelola.php.
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'pusat') {
    header("Location: ../login");
    exit;
}

// ----- Tambah Kasbon -----
if (isset($_POST['tambah'])) {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        $_SESSION['error'] = 'Token CSRF tidak valid!';
        header("Location: kasbon_pengelola");
        exit;
    }
    $id_pengelola   = (int) ($_POST['id_pengelola'] ?? 0);
    $jumlah_kasbon  = (float) str_replace(['.', ','], ['', '.'], $_POST['jumlah_kasbon'] ?? '0');
    $tanggal_kasbon = $_POST['tanggal_kasbon'] ?? '';
    $keterangan     = trim($_POST['keterangan'] ?? '');

    if ($id_pengelola <= 0 || $jumlah_kasbon <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal_kasbon)) {
        $_SESSION['error'] = 'Pengelola, jumlah kasbon, dan tanggal kasbon wajib diisi dengan benar!';
        header("Location: kasbon_pengelola");
        exit;
    }

    $stmt = $conn->prepare("INSERT INTO kasbon_pengelola (id_pengelola, jumlah_kasbon, tanggal_kasbon, keterangan, created_by) VALUES (?, ?, ?, ?, ?)");
    $uid = current_user_id();
    $stmt->bind_param('idssi', $id_pengelola, $jumlah_kasbon, $tanggal_kasbon, $keterangan, $uid);
    if ($stmt->execute()) {
        $new_id = $conn->insert_id;
        $stmt->close();
        audit($conn, 'kasbon_pengelola_tambah', 'kasbon_pengelola', $new_id, ['id_pengelola' => $id_pengelola, 'jumlah_kasbon' => $jumlah_kasbon, 'tanggal_kasbon' => $tanggal_kasbon]);
        $_SESSION['success'] = 'Kasbon baru berhasil dicatat!';
    } else {
        $err = $stmt->error;
        $stmt->close();
        $_SESSION['error'] = 'Gagal mencatat kasbon: ' . $err;
    }
    header("Location: kasbon_pengelola");
    exit;
}

// ----- Catat Pengembalian (tambah ke jumlah_dikembalikan) -----
if (isset($_POST['catat_pengembalian'])) {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        $_SESSION['error'] = 'Token CSRF tidak valid!';
        header("Location: kasbon_pengelola");
        exit;
    }
    $id = (int) ($_POST['id'] ?? 0);
    $jumlah_bayar = (float) str_replace(['.', ','], ['', '.'], $_POST['jumlah_pengembalian'] ?? '0');
    $tgl_bayar = $_POST['tanggal_pengembalian'] ?? '';

    if ($id <= 0 || $jumlah_bayar <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl_bayar)) {
        $_SESSION['error'] = 'Jumlah pengembalian dan tanggal wajib diisi dengan benar!';
        header("Location: kasbon_pengelola");
        exit;
    }

    $cek = $conn->prepare("SELECT jumlah_kasbon, jumlah_dikembalikan FROM kasbon_pengelola WHERE id = ?");
    $cek->bind_param('i', $id);
    $cek->execute();
    $row = $cek->get_result()->fetch_assoc();
    $cek->close();

    if (!$row) {
        $_SESSION['error'] = 'Data kasbon tidak ditemukan!';
        header("Location: kasbon_pengelola");
        exit;
    }

    // Jumlah dikembalikan dibatasi (clamp) maksimal sebesar jumlah_kasbon --
    // tidak mungkin mengembalikan lebih dari yang dipinjam.
    $dikembalikan_baru = min((float) $row['jumlah_kasbon'], (float) $row['jumlah_dikembalikan'] + $jumlah_bayar);
    $status_baru = $dikembalikan_baru >= (float) $row['jumlah_kasbon'] ? 'lunas' : 'berjalan';

    $up = $conn->prepare("UPDATE kasbon_pengelola SET jumlah_dikembalikan = ?, tanggal_pengembalian = ?, status = ? WHERE id = ?");
    $up->bind_param('dssi', $dikembalikan_baru, $tgl_bayar, $status_baru, $id);
    if ($up->execute()) {
        $up->close();
        audit($conn, 'kasbon_pengelola_pengembalian', 'kasbon_pengelola', $id, ['jumlah_dibayar' => $jumlah_bayar, 'jumlah_dikembalikan_baru' => $dikembalikan_baru, 'status' => $status_baru]);
        $_SESSION['success'] = $status_baru === 'lunas' ? 'Pengembalian dicatat — kasbon LUNAS!' : 'Pengembalian berhasil dicatat!';
    } else {
        $err = $up->error;
        $up->close();
        $_SESSION['error'] = 'Gagal mencatat pengembalian: ' . $err;
    }
    header("Location: kasbon_pengelola");
    exit;
}

// ----- Edit (koreksi manual, termasuk jumlah_dikembalikan kalau perlu) -----
if (isset($_POST['edit'])) {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        $_SESSION['error'] = 'Token CSRF tidak valid!';
        header("Location: kasbon_pengelola");
        exit;
    }
    $id                  = (int) ($_POST['id'] ?? 0);
    $id_pengelola        = (int) ($_POST['id_pengelola'] ?? 0);
    $jumlah_kasbon       = (float) str_replace(['.', ','], ['', '.'], $_POST['jumlah_kasbon'] ?? '0');
    $tanggal_kasbon      = $_POST['tanggal_kasbon'] ?? '';
    $jumlah_dikembalikan = (float) str_replace(['.', ','], ['', '.'], $_POST['jumlah_dikembalikan'] ?? '0');
    $tanggal_pengembalian = !empty($_POST['tanggal_pengembalian']) ? $_POST['tanggal_pengembalian'] : null;
    $keterangan          = trim($_POST['keterangan'] ?? '');

    if ($id <= 0 || $id_pengelola <= 0 || $jumlah_kasbon <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal_kasbon)) {
        $_SESSION['error'] = 'Pengelola, jumlah kasbon, dan tanggal kasbon wajib diisi dengan benar!';
        header("Location: kasbon_pengelola");
        exit;
    }
    $jumlah_dikembalikan = min($jumlah_dikembalikan, $jumlah_kasbon);
    $status = $jumlah_dikembalikan >= $jumlah_kasbon ? 'lunas' : 'berjalan';

    $stmt = $conn->prepare("UPDATE kasbon_pengelola SET id_pengelola=?, jumlah_kasbon=?, tanggal_kasbon=?, jumlah_dikembalikan=?, tanggal_pengembalian=?, keterangan=?, status=? WHERE id=?");
    $stmt->bind_param('idsdsssi', $id_pengelola, $jumlah_kasbon, $tanggal_kasbon, $jumlah_dikembalikan, $tanggal_pengembalian, $keterangan, $status, $id);
    if ($stmt->execute()) {
        $stmt->close();
        audit($conn, 'kasbon_pengelola_edit', 'kasbon_pengelola', $id, ['id_pengelola' => $id_pengelola, 'jumlah_kasbon' => $jumlah_kasbon, 'jumlah_dikembalikan' => $jumlah_dikembalikan, 'status' => $status]);
        $_SESSION['success'] = 'Data kasbon berhasil diperbarui!';
    } else {
        $err = $stmt->error;
        $stmt->close();
        $_SESSION['error'] = 'Gagal memperbarui data: ' . $err;
    }
    header("Location: kasbon_pengelola");
    exit;
}

// ----- Hapus -----
if (isset($_POST['hapus'])) {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        $_SESSION['error'] = 'Token CSRF tidak valid!';
        header("Location: kasbon_pengelola");
        exit;
    }
    $id = (int) ($_POST['id'] ?? 0);
    $del = $conn->prepare("DELETE FROM kasbon_pengelola WHERE id=?");
    $del->bind_param('i', $id);
    if ($del->execute()) {
        $del->close();
        audit($conn, 'kasbon_pengelola_hapus', 'kasbon_pengelola', $id);
        $_SESSION['success'] = 'Catatan kasbon berhasil dihapus!';
    } else {
        $err = $del->error;
        $del->close();
        $_SESSION['error'] = 'Gagal menghapus data: ' . $err;
    }
    header("Location: kasbon_pengelola");
    exit;
}

// ----- Filter & Search -----
$search     = trim($_GET['search'] ?? '');
$sel_status = $_GET['status'] ?? '';
if (!in_array($sel_status, ['berjalan', 'lunas'], true)) $sel_status = '';

$where = "WHERE 1=1";
$params = [];
$types = '';
if ($search !== '') {
    $where .= " AND (p.nama_pengelola LIKE ? OR c.nama_cabang LIKE ? OR k.keterangan LIKE ?)";
    $s = "%$search%";
    $params = array_merge($params, [$s, $s, $s]);
    $types .= 'sss';
}
if ($sel_status !== '') {
    $where .= " AND k.status = ?";
    $params[] = $sel_status;
    $types .= 's';
}

// ----- Statistik ringkas (global, tidak terpengaruh filter/paginasi) -----
$stat = $conn->query("SELECT
        COUNT(*) AS jml_aktif,
        COALESCE(SUM(jumlah_kasbon - jumlah_dikembalikan), 0) AS sisa_berjalan
    FROM kasbon_pengelola WHERE status = 'berjalan'")->fetch_assoc();
$jml_kasbon_aktif  = (int) $stat['jml_aktif'];
$total_sisa_berjalan = (float) $stat['sisa_berjalan'];
$total_dikembalikan_all = (float) ($conn->query("SELECT COALESCE(SUM(jumlah_dikembalikan), 0) AS t FROM kasbon_pengelola")->fetch_assoc()['t'] ?? 0);

// ----- Paginasi & Query utama -----
$limit  = 20;
$page   = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

$sql_count = "SELECT COUNT(*) AS total FROM kasbon_pengelola k
    JOIN pengelola p ON p.id = k.id_pengelola
    LEFT JOIN cabang c ON c.id_cabang = p.id_cabang $where";
$stc = $conn->prepare($sql_count);
if ($types !== '') $stc->bind_param($types, ...$params);
$stc->execute();
$total_data = (int) ($stc->get_result()->fetch_assoc()['total'] ?? 0);
$stc->close();
$total_pages = max(1, (int) ceil($total_data / $limit));

$sql = "SELECT k.*, p.nama_pengelola, p.id_cabang, c.nama_cabang
    FROM kasbon_pengelola k
    JOIN pengelola p ON p.id = k.id_pengelola
    LEFT JOIN cabang c ON c.id_cabang = p.id_cabang
    $where
    ORDER BY k.tanggal_kasbon DESC, k.id DESC
    LIMIT ? OFFSET ?";
$st = $conn->prepare($sql);
$bind_types = $types . 'ii';
$bind_params = array_merge($params, [$limit, $offset]);
$st->bind_param($bind_types, ...$bind_params);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

// ----- Master data pengelola aktif (untuk dropdown Tambah Kasbon) -----
$pengelola_array = $conn->query("SELECT p.id, p.nama_pengelola, c.nama_cabang
    FROM pengelola p LEFT JOIN cabang c ON c.id_cabang = p.id_cabang
    WHERE p.status = 'aktif' ORDER BY p.nama_pengelola ASC")->fetch_all(MYSQLI_ASSOC);

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

    .btn-action-pay { background-color: #dcfce7; color: #15803d; border: none; padding: 8px 14px; border-radius: 10px; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; justify-content: center; }
    .btn-action-pay:hover { background-color: #bbf7d0; }
    .btn-action-edit { background-color: #fff3cd; color: #856404; border: none; padding: 8px 14px; border-radius: 10px; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; justify-content: center; }
    .btn-action-edit:hover { background-color: #ffe8a1; }
    .btn-action-delete { background-color: #fde8e8; color: #ef4444; border: none; padding: 8px 14px; border-radius: 10px; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; justify-content: center; }
    .btn-action-delete:hover { background-color: #fbd5d5; }

    .stat-card { border-radius: 16px; padding: 20px; background: #fff; border: 1px solid #e0e7ff; height: 100%; }
    .stat-card .icon { width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 22px; color: #fff; flex-shrink: 0; }
    .stat-card.tone-aktif .icon { background: linear-gradient(135deg, #f59e0b, #d97706); }
    .stat-card.tone-sisa .icon { background: linear-gradient(135deg, #ef4444, #b91c1c); }
    .stat-card.tone-kembali .icon { background: linear-gradient(135deg, #16a34a, #15803d); }
    .stat-card .value { font-size: 1.5rem; }

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
        .table-saas td:last-child { border-bottom: none !important; }
        .table-saas td::before { content: attr(data-label); font-weight: 700; font-size: 12px; color: #8f9bba; text-transform: uppercase; text-align: left; padding-right: 15px; }
        .table-saas td[data-label="Aksi"] { justify-content: flex-end; margin-top: 8px; flex-wrap: wrap; gap: 6px; }
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
                <h3 class="fw-bold mb-0" style="color: #1b2559; font-size: calc(1.3rem + 0.6vw);">Kasbon Pengelola</h3>
            </div>
            <span class="text-muted small ms-sm-4 d-block mt-1 mt-sm-0">Catatan kasbon pengelola &mdash; pinjaman, pengembalian, dan sisa saldo</span>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-12 col-sm-6 col-md-4">
            <div class="stat-card tone-aktif d-flex align-items-center gap-3">
                <div class="icon"><i class="bi bi-hourglass-split"></i></div>
                <div>
                    <div class="text-muted small">Kasbon Masih Berjalan</div>
                    <div class="fw-bold value"><?= number_format($jml_kasbon_aktif) ?></div>
                </div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-md-4">
            <div class="stat-card tone-sisa d-flex align-items-center gap-3">
                <div class="icon"><i class="bi bi-wallet2"></i></div>
                <div>
                    <div class="text-muted small">Total Sisa Kasbon Berjalan</div>
                    <div class="fw-bold value">Rp <?= number_format($total_sisa_berjalan, 0, ',', '.') ?></div>
                </div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-md-4">
            <div class="stat-card tone-kembali d-flex align-items-center gap-3">
                <div class="icon"><i class="bi bi-check2-circle"></i></div>
                <div>
                    <div class="text-muted small">Total Sudah Dikembalikan (semua waktu)</div>
                    <div class="fw-bold value">Rp <?= number_format($total_dikembalikan_all, 0, ',', '.') ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-between align-items-center action-container gap-3 mb-4">
        <div class="w-100 w-md-auto">
            <button type="button" class="btn btn-premium d-flex align-items-center gap-2 w-100 justify-content-center" data-bs-toggle="modal" data-bs-target="#modalTambah">
                <i class="bi bi-plus-circle-fill"></i> Catat Kasbon Baru
            </button>
        </div>

        <form method="GET" class="d-flex gap-2 search-form flex-wrap">
            <select name="status" class="form-select form-select-premium" style="min-width: 150px;" onchange="this.form.submit()">
                <option value="">Semua Status</option>
                <option value="berjalan" <?= $sel_status === 'berjalan' ? 'selected' : '' ?>>Berjalan</option>
                <option value="lunas" <?= $sel_status === 'lunas' ? 'selected' : '' ?>>Lunas</option>
            </select>
            <div class="search-input-group flex-grow-1">
                <input type="text" name="search" class="form-control form-control-premium w-100" placeholder="Cari nama pengelola, cabang, keterangan..." value="<?= h($search) ?>">
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-premium-outline d-flex align-items-center gap-2 justify-content-center flex-grow-1">
                    <i class="bi bi-search"></i> Cari
                </button>
                <?php if ($search !== '' || $sel_status !== ''): ?>
                    <a href="kasbon_pengelola" class="btn btn-premium-outline bg-white text-secondary d-flex align-items-center justify-content-center" title="Reset Filter">
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
                        <th width="4%" class="text-center">No</th>
                        <th width="18%">Pengelola</th>
                        <th width="10%">Tanggal Kasbon</th>
                        <th width="12%" class="text-end">Jumlah Kasbon</th>
                        <th width="12%" class="text-end">Total Dikembalikan</th>
                        <th width="12%" class="text-end">Sisa Kasbon</th>
                        <th width="10%">Tgl Pengembalian</th>
                        <th width="12%">Keterangan</th>
                        <th width="7%" class="text-center">Status</th>
                        <th width="13%" class="text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="10" class="text-center text-muted py-5 fw-semibold bg-white rounded-4">
                                <i class="bi bi-inbox fs-2 d-block mb-2"></i> Belum ada catatan kasbon
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($rows as $row):
                        $sisa = max(0, (float) $row['jumlah_kasbon'] - (float) $row['jumlah_dikembalikan']);
                    ?>
                        <tr>
                            <td data-label="No" class="text-center text-muted fw-semibold"><?= $no++ ?></td>
                            <td data-label="Pengelola">
                                <span class="fw-bold d-block" style="color: #1b2559;"><?= h($row['nama_pengelola']) ?></span>
                                <small class="text-primary fw-semibold"><?= h($row['nama_cabang'] ?? 'Tanpa Cabang') ?></small>
                            </td>
                            <td data-label="Tanggal Kasbon"><?= date('d M Y', strtotime($row['tanggal_kasbon'])) ?></td>
                            <td data-label="Jumlah Kasbon" class="text-end fw-semibold">Rp <?= number_format($row['jumlah_kasbon'], 0, ',', '.') ?></td>
                            <td data-label="Total Dikembalikan" class="text-end text-success fw-semibold">Rp <?= number_format($row['jumlah_dikembalikan'], 0, ',', '.') ?></td>
                            <td data-label="Sisa Kasbon" class="text-end fw-bold <?= $sisa > 0 ? 'text-danger' : 'text-muted' ?>">Rp <?= number_format($sisa, 0, ',', '.') ?></td>
                            <td data-label="Tgl Pengembalian"><?= !empty($row['tanggal_pengembalian']) ? date('d M Y', strtotime($row['tanggal_pengembalian'])) : '-' ?></td>
                            <td data-label="Keterangan"><small><?= $row['keterangan'] !== null && $row['keterangan'] !== '' ? h($row['keterangan']) : '<span class="text-muted fst-italic">-</span>' ?></small></td>
                            <td data-label="Status" class="text-center">
                                <?php if ($row['status'] === 'lunas'): ?>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">Lunas</span>
                                <?php else: ?>
                                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-2 py-1">Berjalan</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Aksi" class="text-center">
                                <div class="d-inline-flex gap-2 justify-content-end justify-content-md-center flex-wrap">
                                    <?php if ($row['status'] !== 'lunas'): ?>
                                    <button type="button" class="btn btn-action-pay" data-bs-toggle="modal" data-bs-target="#modalBayar<?= $row['id'] ?>" title="Catat Pengembalian">
                                        <i class="bi bi-cash-coin me-1"></i> Bayar
                                    </button>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-action-edit" data-bs-toggle="modal" data-bs-target="#modalEdit<?= $row['id'] ?>" title="Edit">
                                        <i class="bi bi-pencil-square me-1"></i> Edit
                                    </button>
                                    <form method="POST" class="d-inline" id="form-delete-<?= $row['id'] ?>">
                                        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                                        <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                        <input type="hidden" name="hapus" value="1">
                                        <button type="button" class="btn btn-action-delete" title="Hapus" onclick="confirmDelete(<?= $row['id'] ?>, <?= json_encode($row['nama_pengelola']) ?>)">
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

        <?php render_pagination($page, $total_pages, ['from' => $total_data ? $offset + 1 : 0, 'to' => min($offset + $limit, $total_data), 'total' => $total_data, 'label' => 'catatan kasbon']); ?>
    </div>
</div>

<!-- MODAL TAMBAH KASBON -->
<div class="modal fade" id="modalTambah" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form method="POST">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title fw-bold" style="color: #1b2559;">Catat Kasbon Baru</h5>
                        <small class="text-muted">Input pinjaman kasbon untuk seorang pengelola</small>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted mb-1">Pengelola</label>
                            <select name="id_pengelola" class="form-select-premium w-100" required>
                                <option value="">Pilih Pengelola</option>
                                <?php foreach ($pengelola_array as $p): ?>
                                    <option value="<?= $p['id'] ?>"><?= h($p['nama_pengelola']) ?> &mdash; <?= h($p['nama_cabang'] ?? 'Tanpa Cabang') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted mb-1">Tanggal Kasbon</label>
                            <input type="date" name="tanggal_kasbon" value="<?= date('Y-m-d') ?>" class="form-control form-control-premium" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted mb-1">Jumlah Kasbon</label>
                            <input type="text" inputmode="numeric" name="jumlah_kasbon" class="form-control form-control-premium mask-ribuan-titik-kasbon" placeholder="0" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted mb-1">Keterangan <span class="fw-normal">(opsional)</span></label>
                            <input type="text" name="keterangan" class="form-control form-control-premium" placeholder="Contoh: kasbon servis motor, keperluan keluarga, dll." maxlength="255">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-premium-outline text-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" name="tambah" class="btn btn-premium">Simpan Kasbon</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- MODAL BAYAR / EDIT per baris -->
<?php foreach ($rows as $row):
    $sisa = max(0, (float) $row['jumlah_kasbon'] - (float) $row['jumlah_dikembalikan']);
?>
<div class="modal fade" id="modalBayar<?= $row['id'] ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="id" value="<?= $row['id'] ?>">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title fw-bold" style="color: #1b2559;">Catat Pengembalian</h5>
                        <small class="text-muted"><?= h($row['nama_pengelola']) ?> &mdash; sisa kasbon saat ini: <strong>Rp <?= number_format($sisa, 0, ',', '.') ?></strong></small>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted mb-1">Jumlah Pengembalian</label>
                            <input type="text" inputmode="numeric" name="jumlah_pengembalian" class="form-control form-control-premium mask-ribuan-titik-kasbon" placeholder="0" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted mb-1">Tanggal Pengembalian</label>
                            <input type="date" name="tanggal_pengembalian" value="<?= date('Y-m-d') ?>" class="form-control form-control-premium" required>
                        </div>
                    </div>
                    <div class="form-text mt-2">Jumlah ini akan <strong>ditambahkan</strong> ke total yang sudah dikembalikan sebelumnya (Rp <?= number_format($row['jumlah_dikembalikan'], 0, ',', '.') ?>). Kalau totalnya sudah mencapai jumlah kasbon, status otomatis berubah jadi Lunas.</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-premium-outline text-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" name="catat_pengembalian" class="btn btn-premium">Simpan Pengembalian</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- MODAL EDIT -->
<div class="modal fade" id="modalEdit<?= $row['id'] ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form method="POST">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="id" value="<?= $row['id'] ?>">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title fw-bold" style="color: #1b2559;">Edit Catatan Kasbon</h5>
                        <small class="text-muted">Koreksi data kasbon &mdash; termasuk total dikembalikan kalau perlu diperbaiki</small>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted mb-1">Pengelola</label>
                            <select name="id_pengelola" class="form-select-premium w-100" required>
                                <?php foreach ($pengelola_array as $p): ?>
                                    <option value="<?= $p['id'] ?>" <?= $p['id'] == $row['id_pengelola'] ? 'selected' : '' ?>><?= h($p['nama_pengelola']) ?> &mdash; <?= h($p['nama_cabang'] ?? 'Tanpa Cabang') ?></option>
                                <?php endforeach; ?>
                                <?php if (!in_array($row['id_pengelola'], array_column($pengelola_array, 'id'))): ?>
                                    <option value="<?= $row['id_pengelola'] ?>" selected><?= h($row['nama_pengelola']) ?> (nonaktif)</option>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted mb-1">Tanggal Kasbon</label>
                            <input type="date" name="tanggal_kasbon" value="<?= h($row['tanggal_kasbon']) ?>" class="form-control form-control-premium" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted mb-1">Jumlah Kasbon</label>
                            <input type="text" inputmode="numeric" name="jumlah_kasbon" value="<?= number_format($row['jumlah_kasbon'], 0, ',', '.') ?>" class="form-control form-control-premium mask-ribuan-titik-kasbon" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted mb-1">Total Sudah Dikembalikan</label>
                            <input type="text" inputmode="numeric" name="jumlah_dikembalikan" value="<?= number_format($row['jumlah_dikembalikan'], 0, ',', '.') ?>" class="form-control form-control-premium mask-ribuan-titik-kasbon">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted mb-1">Tanggal Pengembalian Terakhir</label>
                            <input type="date" name="tanggal_pengembalian" value="<?= h($row['tanggal_pengembalian'] ?? '') ?>" class="form-control form-control-premium">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted mb-1">Keterangan</label>
                            <input type="text" name="keterangan" value="<?= h($row['keterangan'] ?? '') ?>" class="form-control form-control-premium" maxlength="255">
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
    // Pemisah ribuan "." saat mengetik nominal (gaya sama dengan Rekapitulasi).
    function formatRibuanTitikKasbon(str) {
        if (str === '' || str === null || str === undefined) return '';
        const bersih = str.toString().replace(/[^0-9]/g, '');
        if (bersih === '') return '';
        return parseInt(bersih, 10).toLocaleString('id-ID');
    }
    document.querySelectorAll('.mask-ribuan-titik-kasbon').forEach(function (el) {
        el.addEventListener('input', function () {
            let cursorPosition = this.selectionStart;
            let oldLength = this.value.length;
            this.value = formatRibuanTitikKasbon(this.value);
            let newLength = this.value.length;
            cursorPosition += (newLength - oldLength);
            this.setSelectionRange(cursorPosition, cursorPosition);
        });
    });
    // Form di-submit sebagai teks berpemisah titik -- server membersihkannya
    // sendiri (str_replace '.'/',' sebelum cast ke float), jadi tidak perlu
    // dibersihkan lagi di sisi klien sebelum submit.

    function confirmDelete(id, nama) {
        Swal.fire({
            title: 'Hapus Catatan Kasbon?',
            text: "Apakah Anda yakin ingin menghapus catatan kasbon " + nama + "? Data yang dihapus tidak dapat dikembalikan.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#6b7280',
            confirmButtonText: 'Ya, Hapus!',
            cancelButtonText: 'Batal',
            customClass: { popup: 'rounded-4' }
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('form-delete-' + id).submit();
            }
        });
    }

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

<?php include '../config/notifikasi_bell.php'; ?>
