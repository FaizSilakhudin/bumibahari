<?php
require '../config/koneksi.php';
include 'sidebar_pusat.php';

// ==========================================================
// HELPER
// ==========================================================

if(!function_exists('h')){
    function h($s){
        return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
    }
}

if(!function_exists('csrf_token')){
    function csrf_token(){

        if(empty($_SESSION['csrf'])){
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf'];
    }
}

if(!function_exists('csrf_check')){
    function csrf_check($t){

        return !empty($_SESSION['csrf'])
            && !empty($t)
            && hash_equals($_SESSION['csrf'], $t);
    }
}

// ==========================================================
// PROTEKSI ROLE PUSAT
// ==========================================================

if(
    !isset($_SESSION['role']) ||
    $_SESSION['role'] != 'pusat'
){
    header("Location: ../login");
    exit;
}

// ==========================================================
// FILTER
// ==========================================================

$filter = $_GET['filter'] ?? 'harian';

$tgl_awal = $_GET['tgl_awal']
    ?? date('Y-m-01');

$tgl_akhir = $_GET['tgl_akhir']
    ?? date('Y-m-d');

$id_cabang = $_GET['id_cabang'] ?? '';

$page = isset($_GET['page'])
    ? (int)$_GET['page']
    : 1;

if($page < 1){
    $page = 1;
}

$limit = 10;

$offset = ($page - 1) * $limit;


// ==========================================================
// FUNGSI REDIRECT SETELAH PROSES
// ==========================================================

function redirectLaporan(
    $filter,
    $tgl_awal,
    $tgl_akhir,
    $id_cabang,
    $page
){

    $param = http_build_query([
        'filter'    => $filter,
        'tgl_awal'  => $tgl_awal,
        'tgl_akhir' => $tgl_akhir,
        'id_cabang' => $id_cabang,
        'page'      => $page
    ]);

    echo "<script>
        window.location.href = 'laporan?$param';
    </script>";

    exit;
}



// Handler POST (hapus + update laporan) — dipisah ke file sendiri, lihat _laporan_handlers.php
require __DIR__ . '/_laporan_handlers.php';



// ==========================================================
// QUERY DATA
// FILTER TANGGAL + CABANG
// ==========================================================

$where_sql = "
    WHERE l.tanggal BETWEEN ? AND ?
";

$params = [
    $tgl_awal,
    $tgl_akhir
];

$types = "ss";


if($id_cabang != ''){

    $where_sql .= "
        AND l.id_cabang = ?
    ";

    $params[] = (int)$id_cabang;

    $types .= "i";
}



// ==========================================================
// HITUNG TOTAL DATA
// ==========================================================

$sql_count = "
    SELECT COUNT(*) AS total
    FROM laporan_cabang l
    $where_sql
";

$stmt_count = $conn->prepare($sql_count);

if(!$stmt_count){

    die(
        "Prepare COUNT gagal: " .
        h($conn->error)
    );
}

$stmt_count->bind_param(
    $types,
    ...$params
);

$stmt_count->execute();

$result_count =
    $stmt_count->get_result();

$row_count =
    $result_count->fetch_assoc();

$total_data =
    (int)($row_count['total'] ?? 0);

$total_pages =
    $limit > 0
        ? (int)ceil($total_data / $limit)
        : 1;



// ==========================================================
// QUERY UTAMA
// ==========================================================

$query = "
    SELECT
        l.*,
        c.nama_cabang

    FROM laporan_cabang l

    JOIN cabang c
        ON l.id_cabang = c.id_cabang

    $where_sql

    ORDER BY l.tanggal DESC

    LIMIT ? OFFSET ?
";

$stmt = $conn->prepare($query);

if(!$stmt){

    die(
        "Prepare query utama gagal: " .
        h($conn->error)
    );
}


$types_limit =
    $types . "ii";


$params_limit = array_merge(
    $params,
    [
        (int)$limit,
        (int)$offset
    ]
);


$stmt->bind_param(
    $types_limit,
    ...$params_limit
);


$stmt->execute();

$data =
    $stmt->get_result();



// ==========================================================
// TOTAL OMZET
// ==========================================================

$sql_omset = "
    SELECT
        COALESCE(SUM(l.total_omset), 0) AS total

    FROM laporan_cabang l

    $where_sql
";

$stmt_omset =
    $conn->prepare($sql_omset);

if(!$stmt_omset){

    die(
        "Prepare total omzet gagal: " .
        h($conn->error)
    );
}

$stmt_omset->bind_param(
    $types,
    ...$params
);

$stmt_omset->execute();

$result_omset =
    $stmt_omset->get_result();

$row_omset =
    $result_omset->fetch_assoc();

$total_omset =
    $row_omset['total'] ?? 0;



// ==========================================================
// TOTAL NET PROFIT
// ==========================================================

$sql_net_profit = "
    SELECT
        COALESCE(SUM(l.net_profit), 0) AS total

    FROM laporan_cabang l

    $where_sql
";

$stmt_net =
    $conn->prepare($sql_net_profit);

if(!$stmt_net){

    die(
        "Prepare total net profit gagal: " .
        h($conn->error)
    );
}

$stmt_net->bind_param(
    $types,
    ...$params
);

$stmt_net->execute();

$result_net =
    $stmt_net->get_result();

$row_net =
    $result_net->fetch_assoc();

$net_profit =
    $row_net['total'] ?? 0;



// ==========================================================
// DATA CABANG
// ==========================================================

$cabang = $conn->query("
    SELECT *
    FROM cabang
    ORDER BY nama_cabang
");

if(!$cabang){

    die(
        "Query cabang gagal: " .
        h($conn->error)
    );
}



// ==========================================
// PARAMETER QUERY UTAMA
// ==========================================

$types_limit = $types . "ii";

$params_limit = array_merge(
    $params,
    [
        (int)$limit,
        (int)$offset
    ]
);

$stmt->bind_param(
    $types_limit,
    ...$params_limit
);

$stmt->execute();

$data = $stmt->get_result();

// Total Omzet - AMBIL DARI KOLOM DB
$sql_omset = "SELECT SUM(l.total_omset) as total FROM laporan_cabang l $where_sql";
$stmt = $conn->prepare($sql_omset);
$stmt->bind_param($types,...$params);
$stmt->execute();
$total_omset = $stmt->get_result()->fetch_assoc()['total']?? 0;

// Total Net Profit - AMBIL DARI KOLOM DB
$sql_net_profit = "SELECT SUM(l.net_profit) as total FROM laporan_cabang l $where_sql";
$stmt = $conn->prepare($sql_net_profit);
$stmt->bind_param($types,...$params);
$stmt->execute();
$net_profit = $stmt->get_result()->fetch_assoc()['total']?? 0;

$cabang = $conn->query("SELECT * FROM cabang ORDER BY nama_cabang");
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
.pagination.page-link {border-radius: 10px!important; margin: 0 3px; border: 1px solid #dee2e6; color: #0d6efd; font-weight: 600;}
.pagination.page-item.active.page-link {background-color: #0d6efd; border-color: #0d6efd; color: #fff;}
.pagination.page-link:hover {background-color: #e7f1ff;}
.btn-action-delete {background-color: #fde8e8; color: #ef4444; border: none; padding: 6px 12px; border-radius: 8px; font-size: 12px; font-weight: 600;}
.btn-action-delete:hover {background-color: #fbd5d5;}
</style>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold mb-1 text-dark">Laporan Semua Cabang</h3>
            <p class="text-muted small mb-0">Pantau perkembangan omzet, pengeluaran, dan net profit secara berkala.</p>
        </div>
        <a href="export_laporan.php?<?= http_build_query(['tgl_awal' => $tgl_awal, 'tgl_akhir' => $tgl_akhir, 'id_cabang' => $id_cabang]) ?>"
           class="btn btn-outline-success fw-semibold" style="border-radius: 10px;">
            <i class="bi bi-file-earmark-spreadsheet me-1"></i> Export CSV
        </a>
    </div>

    <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px;">
        <div class="card-body p-4">
            <form method="GET" class="row g-3 align-items-end" id="filterForm">
                <div class="col-lg-2 col-md-4">
                    <label class="form-label fw-semibold text-secondary small">Mode Filter</label>
                    <select name="filter" id="filterMode" class="form-select form-select-md border-2 bg-light">
                        <option value="harian" <?= ($filter ?? '') == 'harian' ? 'selected' : '' ?>>Harian</option>
                        <option value="mingguan" <?= ($filter ?? '') == 'mingguan' ? 'selected' : '' ?>>Mingguan</option>
                        <option value="bulanan" <?= ($filter ?? '') == 'bulanan' ? 'selected' : '' ?>>Bulanan</option>
                    </select>
                </div>

                <div class="col-lg-3 col-md-6 filter-input" id="input-harian">
                    <label class="form-label fw-semibold text-secondary small">Pilih Tanggal</label>
                    <input type="date" name="tgl" value="<?= h($_GET['tgl'] ?? date('Y-m-d')) ?>" class="form-control form-control-md border-2 bg-light">
                </div>

                <div class="col-lg-3 col-md-6 filter-input d-none" id="input-mingguan">
                    <label class="form-label fw-semibold text-secondary small">Pilih Minggu</label>
                    <input type="week" name="minggu" value="<?= h($_GET['minggu'] ?? date('Y-\WW')) ?>" class="form-control form-control-md border-2 bg-light">
                </div>

                <div class="col-lg-3 col-md-6 filter-input d-none" id="input-bulanan">
                    <label class="form-label fw-semibold text-secondary small">Pilih Bulan</label>
                    <input type="month" name="bulan" value="<?= h($_GET['bulan'] ?? date('Y-m')) ?>" class="form-control form-control-md border-2 bg-light">
                </div>

                <input type="hidden" name="tgl_awal" id="tgl_awal" value="<?= h($tgl_awal ?? '') ?>">
                <input type="hidden" name="tgl_akhir" id="tgl_akhir" value="<?= h($tgl_akhir ?? '') ?>">

                <div class="col-lg-4 col-md-8">
                    <label class="form-label fw-semibold text-secondary small">Pilih Cabang</label>
                    <select name="id_cabang" class="form-select form-select-md border-2 bg-light">
                        <option value="">Semua Cabang</option>
                        <?php if (isset($cabang) && $cabang->num_rows > 0): 
                            $cabang->data_seek(0); 
                            while($c = $cabang->fetch_assoc()): ?>
                                <option value="<?= $c['id_cabang'] ?>" <?= ($id_cabang ?? '') == $c['id_cabang'] ? 'selected' : '' ?>>
                                    <?= h($c['nama_cabang']) ?>
                                </option>
                        <?php endwhile; endif; ?>
                    </select>
                </div>
                <div class="col-lg-2 col-md-4 d-grid">
                    <button type="submit" class="btn btn-primary btn-md fw-bold">
                        <i class="bi bi-funnel-fill me-1"></i> Filter
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="card border-0 shadow-sm border-start border-4 border-primary h-100" style="border-radius: 8px;">
                <div class="card-body d-flex align-items-center justify-content-between p-4">
                    <div>
                        <span class="text-muted small text-uppercase fw-bold tracking-wider">Total Omzet Bersih</span>
                        <h3 class="text-primary fw-bold mb-0 mt-1">Rp <?= number_format($total_omset ?? 0, 0, ',', '.') ?></h3>
                        <small class="text-muted">Sudah dipotong Pencairan QRIS</small>
                    </div>
                    <div class="bg-primary bg-opacity-10 text-primary p-3 rounded-3">
                        <i class="bi bi-wallet2 fs-3"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card border-0 shadow-sm border-start border-4 border-success h-100" style="border-radius: 8px;">
                <div class="card-body d-flex align-items-center justify-content-between p-4">
                    <div>
                        <span class="text-muted small text-uppercase fw-bold tracking-wider">Net Profit</span>
                        <h3 class="text-success fw-bold mb-0 mt-1">Rp <?= number_format($net_profit ?? 0, 0, ',', '.') ?></h3>
                        <small class="text-muted">Sisa Tunai + Sisa QRIS + Go + Grab</small>
                    </div>
                    <div class="bg-success bg-opacity-10 text-success p-3 rounded-3">
                        <i class="bi bi-graph-up-arrow fs-3"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm" style="border-radius: 12px; overflow: hidden;">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 text-nowrap">
                    <thead class="table-light text-secondary border-bottom">
                        <tr>
                            <th class="py-3 px-4 text-center" width="5%">No</th>
                            <th class="py-3">Tanggal</th>
                            <th class="py-3">Cabang</th>
                            <th class="py-3">Pengelola</th>
                            <th class="py-3">Omzet Bersih</th>
                            <th class="py-3">Pengeluaran</th>
                            <th class="py-3">Operasional</th>
                            <th class="py-3">Sisa Tunai</th>
                            <th class="py-3">Pencairan QRIS</th>
                            <th class="py-3">Sisa QRIS</th>
                            <th class="py-3">Net Profit</th>
                            <th class="py-3">Margin</th>
                            <th class="py-3 text-center" width="15%">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(!isset($data) || $data->num_rows == 0): ?>
                        <tr>
                            <td colspan="13" class="text-center py-5 text-muted">Belum ada data laporan pada periode ini</td>
                        </tr>
                        <?php else: ?>
                        <?php 
                        $no = ($offset ?? 0) + 1; 
                        while($row = $data->fetch_assoc()):
                            $margin = $row['persentase'] ?? 0; // AMBIL DARI KOLOM DB
                            $id = $row['id'];
                            $is_libur = ($row['status_laporan'] ?? '') === 'libur';
                        ?>
                        <tr>
                            <tr>
                            <td class="text-center px-4 text-muted fw-bold">
                                <?= $no++ ?>
                            </td>

                            <td>
                                <span class="badge bg-light text-dark border p-2 d-block mb-1">
                                    <i class="bi bi-calendar3 me-1 text-muted"></i>
                                    <?= date('d/m/Y', strtotime($row['tanggal'])) ?>
                                </span>
                                <?php if ($is_libur): ?>
                                    <span class="badge bg-secondary-subtle text-secondary"><i class="bi bi-moon-stars-fill me-1"></i>Libur/Tutup</span>
                                <?php elseif (($row['status_laporan'] ?? 'lengkap') === 'lengkap'): ?>
                                    <span class="badge bg-success-subtle text-success">Selesai</span>
                                <?php else: ?>
                                    <span class="badge bg-warning-subtle text-warning">Menunggu PIC</span>
                                <?php endif; ?>
                            </td>

                            <td class="fw-semibold text-dark">
                                <?= h($row['nama_cabang']) ?>
                            </td>

                            <td class="text-muted">
                                <?= h($row['nama_pengelola']) ?>
                            </td>

                            <?php if ($is_libur): ?>
                            <td colspan="8" class="text-center text-muted fst-italic">
                                <i class="bi bi-moon-stars-fill me-1"></i> Warung Libur / Tutup — tidak ada laporan keuangan
                            </td>
                            <?php else: ?>
                            <!-- OMZET BERSIH -->
                            <td>
                                <span class="text-dark fw-bold">
                                    Rp <?= number_format($row['total_omset'] ?? 0, 0, ',', '.') ?>
                                </span>
                            </td>

                            <!-- TOTAL PENGELUARAN -->
                            <td>
                                <span class="text-secondary fw-semibold">
                                    Rp <?= number_format($row['total_pengeluaran'] ?? 0, 0, ',', '.') ?>
                                </span>
                            </td>

                            <!-- OPERASIONAL -->
                            <td>
                                <span class="text-danger fw-semibold">
                                    Rp <?= number_format($row['total_operasional'] ?? 0, 0, ',', '.') ?>
                                </span>
                            </td>

                            <!-- SISA TUNAI -->
                            <td>
                                <span class="fw-bold text-success">
                                    Rp <?= number_format($row['sisa_tunai'] ?? 0, 0, ',', '.') ?>
                                </span>
                            </td>

                            <!-- PENCAIRAN QRIS -->
                            <td>
                                <span class="fw-semibold text-primary">
                                    Rp <?= number_format($row['pencairan_qris'] ?? 0, 0, ',', '.') ?>
                                </span>
                            </td>

                            <!-- SISA QRIS -->
                            <td>
                                <span class="fw-bold text-info">
                                    Rp <?= number_format($row['sisa_qris'] ?? 0, 0, ',', '.') ?>
                                </span>
                            </td>

                            <!-- NET PROFIT -->
                            <td>
                                <span class="fw-bold <?= ($row['net_profit'] ?? 0) >= 0 ? 'text-success' : 'text-danger' ?>">
                                    Rp <?= number_format($row['net_profit'] ?? 0, 0, ',', '.') ?>
                                </span>
                            </td>

                            <!-- MARGIN -->
                            <td>
                                <span class="badge <?= $margin >= 20 ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning' ?> px-2 py-1 rounded">
                                    <?= number_format($margin, 2) ?>%
                                </span>
                            </td>
                            <?php endif; ?>

                            <!-- AKSI -->
                            <td class="text-center px-4">
                                <div class="d-inline-flex gap-2 justify-content-center">

                                    <button
                                        class="btn btn-sm btn-outline-primary px-3 rounded-pill fw-medium"
                                        data-bs-toggle="modal"
                                        data-bs-target="#detailModal<?= $id ?>">
                                        <i class="bi bi-pencil-square me-1"></i>
                                        Edit
                                    </button>

                                    <form
                                        method="POST"
                                        class="d-inline"
                                        onsubmit="return confirm('Yakin hapus laporan tanggal <?= date('d/m/Y', strtotime($row['tanggal'])) ?> cabang <?= h($row['nama_cabang']) ?>?')">

                                        <input
                                            type="hidden"
                                            name="csrf"
                                            value="<?= csrf_token() ?>">

                                        <input
                                            type="hidden"
                                            name="id"
                                            value="<?= $id ?>">

                                        <button
                                            type="submit"
                                            name="hapus_laporan"
                                            class="btn btn-sm btn-action-delete">

                                            <i class="bi bi-trash-fill me-1"></i>
                                            Hapus
                                        </button>

                                    </form>

                                </div>

                                <?php include __DIR__ . '/_edit_laporan_modal.php'; ?>
                            </td>
                        </tr>
                        <?php endwhile; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
            
            <?php render_pagination($page, $total_pages, ['from' => $offset + 1, 'to' => min($offset + $limit, $total_data), 'total' => $total_data, 'label' => 'laporan']); ?>
        </div>
    </div>
</div>

<!-- ===== Aset modal "Koreksi Laporan Harian" (sekali pakai) ===== -->
<style>
.ed-content{border:0;border-radius:14px;overflow:hidden}
.ed-modal .modal-dialog:not(.modal-fullscreen){max-width:1160px}
.ed-head{background:#1e293b;color:#fff;padding:14px 18px;align-items:flex-start}
.ed-head .modal-title{font-size:1rem;color:#fff}
.ed-head-sub{display:flex;flex-wrap:wrap;gap:3px 14px;font-size:.78rem;color:#cbd5e1}
.ed-body{background:#f1f5f9;padding:16px}
.ed-sec{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:14px}
.ed-sec-h{font-weight:700;font-size:.8rem;letter-spacing:.02em;text-transform:uppercase;color:#334155;margin-bottom:12px;display:flex;align-items:center;gap:8px}
.ed-sec-h i{font-size:1rem}
.ed-h-green{color:#059669}.ed-h-red{color:#dc2626}.ed-h-amber{color:#d97706}.ed-h-blue{color:#2563eb}
.ed-auto{margin-left:auto;font-weight:600;font-size:.68rem;background:#eef2ff;color:#4338ca;padding:2px 8px;border-radius:999px;text-transform:none;letter-spacing:0}
.ed-lbl{font-size:.72rem;font-weight:600;color:#64748b;margin-bottom:3px;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ed-ig .input-group-text{background:#f8fafc;border-color:#e2e8f0;color:#94a3b8;font-size:.75rem;padding:.2rem .5rem}
.ed-num{border-color:#e2e8f0;font-weight:600;text-align:right;font-size:.9rem}
.ed-num:focus{border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.15)}
.ed-side{position:sticky;top:0;display:flex;flex-direction:column;gap:16px}
.ed-sum-list{display:flex;flex-direction:column;gap:1px;background:#e2e8f0;border-radius:10px;overflow:hidden}
.ed-sum-list>div{display:flex;justify-content:space-between;align-items:center;gap:10px;background:#fff;padding:9px 12px;font-size:.85rem}
.ed-sum-list>div span{color:#64748b}
.ed-sum-list>div b{color:#0f172a;font-weight:700}
.ed-sum-hi{background:#f0fdf4!important}
.ed-sum-hi b{color:#047857!important;font-size:.95rem}
.ed-notas{display:flex;flex-direction:column;gap:14px}
.ed-notas-scroll{max-height:calc(100vh - 260px);min-height:260px;overflow-y:auto;padding-right:4px}
.ed-nota{margin:0}
.ed-nota-img{width:100%;height:auto;max-height:60vh;object-fit:contain;background:#0f172a;border-radius:10px;border:1px solid #e2e8f0;cursor:zoom-in;display:block}
.ed-nota figcaption{text-align:center;font-size:.72rem;color:#94a3b8;margin-top:5px;font-weight:600}
.ed-nota-empty{text-align:center;color:#94a3b8;padding:26px 0}
.ed-nota-empty i{font-size:2rem;display:block;margin-bottom:6px}
.ed-foot{background:#fff;border-top:1px solid #e2e8f0;padding:12px 16px}
@media (max-width:1199.98px){.ed-side{position:static}}
@media (max-width:575.98px){.ed-body{padding:12px}.ed-sec{padding:12px}.ed-nota-img{max-height:60vh}}
#edZoomOverlay{position:fixed;inset:0;z-index:3000;background:rgba(2,6,23,.93);display:none;align-items:center;justify-content:center;padding:16px;cursor:zoom-out}
#edZoomOverlay.show{display:flex}
#edZoomOverlay img{max-width:100%;max-height:100%;object-fit:contain;border-radius:8px}
#edZoomOverlay .ed-zoom-x{position:absolute;top:12px;right:18px;color:#fff;font-size:2.2rem;line-height:1;background:none;border:0;cursor:pointer}
#edZoomOverlay .ed-zoom-dl{position:absolute;top:16px;right:70px;color:#fff;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.3);border-radius:10px;padding:8px 16px;text-decoration:none;display:flex;align-items:center;gap:8px;font-size:.95rem;font-weight:600}
#edZoomOverlay .ed-zoom-dl:hover{background:rgba(255,255,255,.22);color:#fff}
</style>
<div id="edZoomOverlay" onclick="edZoomClose()">
  <a id="edZoomDownload" href="" download class="ed-zoom-dl" title="Unduh foto ini" onclick="event.stopPropagation()"><i class="bi bi-download"></i> Unduh</a>
  <button type="button" class="ed-zoom-x" aria-label="Tutup">&times;</button>
  <img id="edZoomImg" src="" alt="Nota">
</div>
<script>
function edZoom(src){
  document.getElementById('edZoomImg').src = src;
  document.getElementById('edZoomDownload').href = src;
  document.getElementById('edZoomOverlay').classList.add('show');
}
function edZoomClose(){
  document.getElementById('edZoomOverlay').classList.remove('show');
}
document.addEventListener('keydown', function(e){ if(e.key === 'Escape') edZoomClose(); });
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function(){
    const mode = document.getElementById('filterMode');
    const form = document.getElementById('filterForm');
    
    function toggleInput(){
        document.querySelectorAll('.filter-input').forEach(el => el.classList.add('d-none'));
        document.getElementById('input-'+mode.value).classList.remove('d-none');
    }
    toggleInput();
    mode.addEventListener('change', toggleInput);

    // Sebelum submit, convert ke tgl_awal & tgl_akhir
    form.addEventListener('submit', function(e){
        const val = mode.value;
        let awal = '', akhir = '';

        if(val == 'harian'){
            awal = akhir = document.querySelector('input[name="tgl"]').value;
        }
        if(val == 'mingguan'){
            const valWeek = document.querySelector('input[name="minggu"]').value;
            const [year, week] = valWeek.split('-W');
            const d = new Date(year, 0, 1 + (week-1)*7);
            const day = d.getDay();
            const diff = d.getDate() - day + (day == 0 ? -6:1); // Senin
            d.setDate(diff);
            awal = d.toISOString().split('T')[0];
            d.setDate(d.getDate() + 6); // Minggu
            akhir = d.toISOString().split('T')[0];
        }
        if(val == 'bulanan'){
            const [year, month] = document.querySelector('input[name="bulan"]').value.split('-');
            awal = `${year}-${month}-01`;
            akhir = new Date(year, month, 0).toISOString().split('T')[0]; // hari terakhir
        }
        document.getElementById('tgl_awal').value = awal;
        document.getElementById('tgl_akhir').value = akhir;
    });
});
</script>
<script>
document.addEventListener('DOMContentLoaded', function () {

    function formatRupiah(angka) {
        return 'Rp ' + Number(angka || 0).toLocaleString('id-ID');
    }

    function angka(input) {
        if (!input) return 0;
        return parseFloat(String(input.value || '0').replace(/\./g, '')) || 0;
    }

    // Mask ribuan sambil mengetik (mis. "1.000.000"), boleh diawali minus untuk koreksi manual.
    function maskRupiahEd(el) {
        const neg = el.value.trim().charAt(0) === '-';
        let digits = el.value.replace(/[^0-9]/g, '').replace(/^0+(?=\d)/, '');
        const formatted = digits.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        el.value = (neg && digits !== '' ? '-' : '') + formatted;
    }

    document.querySelectorAll('[id^="detailModal"]').forEach(function(modal) {

        const id = modal.id.replace('detailModal', '');

        function hitungLaporan() {

            // =========================
            // PENDAPATAN
            // =========================

            const tunai = angka(modal.querySelector('[name="tunai"]'));
            const qris = angka(modal.querySelector('[name="qris"]'));
            const grabFood = angka(modal.querySelector('[name="grab_food"]'));
            const goFood = angka(modal.querySelector('[name="go_food"]'));
            const pencairanQris = angka(modal.querySelector('[name="pencairan_qris"]'));


            // =========================
            // BELANJA RUTIN
            // =========================

            const belanjaPasar = angka(modal.querySelector('[name="belanja_pasar"]'));
            const belanjaSembako = angka(modal.querySelector('[name="belanja_sembako"]'));
            const belanjaBeras = angka(modal.querySelector('[name="belanja_beras"]'));
            const belanjaToko = angka(modal.querySelector('[name="belanja_toko"]'));

            const totalRutin =
                belanjaPasar +
                belanjaSembako +
                belanjaBeras +
                belanjaToko;


            // =========================
            // OPERASIONAL
            // =========================

            const sewa = angka(modal.querySelector('[name="sewa"]'));
            const gaji = angka(modal.querySelector('[name="gaji"]'));
            const listrik = angka(modal.querySelector('[name="listrik"]'));
            const air = angka(modal.querySelector('[name="air"]'));
            const sampah = angka(modal.querySelector('[name="sampah"]'));
            const keamanan = angka(modal.querySelector('[name="keamanan"]'));
            const internet = angka(modal.querySelector('[name="internet"]'));
            const gas = angka(modal.querySelector('[name="gas"]'));
            const mingguanKaryawan = angka(modal.querySelector('[name="mingguan_karyawan"]'));
            const esBatu = angka(modal.querySelector('[name="es_batu"]'));
            const bensin = angka(modal.querySelector('[name="bensin"]'));
            const lainLain = angka(modal.querySelector('[name="lain_lain"]'));

            const totalOperasional =
                sewa +
                gaji +
                listrik +
                air +
                sampah +
                keamanan +
                internet +
                gas +
                mingguanKaryawan +
                esBatu +
                bensin +
                lainLain;


            // =========================
            // TOTAL PENGELUARAN
            // =========================

            const totalPengeluaran =
                totalRutin +
                totalOperasional;


            // =========================
            // TOTAL OMZET
            // =========================

            const totalOmzet =
                tunai +
                qris +
                grabFood +
                goFood -
                pencairanQris;


            // =========================
            // SISA TUNAI
            // =========================

            const sisaTunai =
                tunai - totalPengeluaran;


            // =========================
            // SISA QRIS
            // =========================

            const sisaQris =
                qris - pencairanQris;


            // =========================
            // UPDATE TAMPILAN
            // =========================

            // NET PROFIT + MARGIN — rumus sama dengan handler PHP (update_laporan)
            const netProfit = sisaTunai + sisaQris + goFood + grabFood;
            const margin = totalOmzet > 0 ? (netProfit / totalOmzet) * 100 : 0;

            const setTxt = function (sel, val) {
                const el = modal.querySelector(sel + id);
                if (el) el.textContent = val;
            };

            setTxt('#summaryOmzet', formatRupiah(totalOmzet));
            setTxt('#summaryRutin', formatRupiah(totalRutin));
            setTxt('#summaryOperasional', formatRupiah(totalOperasional));
            setTxt('#summaryPengeluaran', formatRupiah(totalPengeluaran));
            setTxt('#summaryTunai', formatRupiah(sisaTunai));
            setTxt('#summaryQris', formatRupiah(sisaQris));
            setTxt('#summaryNet', formatRupiah(netProfit));
            setTxt('#summaryMargin', margin.toFixed(2) + '%');
        }


        // Jalankan ketika modal pertama kali dibuka
        modal.addEventListener('shown.bs.modal', function () {
            hitungLaporan();
        });


        // Jalankan setiap input berubah
        modal.querySelectorAll('input.ed-num').forEach(function(input) {

            input.addEventListener('input', function() {
                hitungLaporan();
            });

        });

    });

});
</script>