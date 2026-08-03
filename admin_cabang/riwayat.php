<?php
require '../config/koneksi.php';
include 'sidebar.php';

if (!function_exists('h')) {
    function h($str){ return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8'); }
}

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'cabang'){
    header('Location: ../login');
    exit;
}

$id_cabang = $_SESSION['id_cabang'];
$nama_pengelola = $_SESSION['nama_pengelola'];

// 1. LOGIKA PER BULAN - SUDAH DIFIX
$bulan = $_GET['bulan'] ?? date('m');
$tahun = $_GET['tahun'] ?? date('Y');

$tgl_awal = date("$tahun-$bulan-01");
$tgl_akhir = date("Y-m-t", strtotime($tgl_awal)); // akhir bulan

// Bulan sebelumnya & sesudahnya untuk navigasi - FIX
$prev_time = strtotime('-1 month', strtotime($tgl_awal));
$next_time = strtotime('+1 month', strtotime($tgl_awal));

$prev_bulan = date('m', $prev_time);
$prev_tahun = date('Y', $prev_time);
$next_bulan = date('m', $next_time);
$next_tahun = date('Y', $next_time);

// 2. AMBIL DATA LAPORAN 1 BULAN
$stmt = $conn->prepare("SELECT * FROM laporan_cabang WHERE id_cabang=? AND tanggal BETWEEN ? AND ? ORDER BY tanggal DESC");
$stmt->bind_param("iss", $id_cabang, $tgl_awal, $tgl_akhir);
$stmt->execute();
$data = $stmt->get_result();

// Ambil Nama Cabang
$stmt2 = $conn->prepare("SELECT nama_cabang FROM cabang WHERE id_cabang=?");
$stmt2->bind_param("i", $id_cabang);
$stmt2->execute();
$cabang = $stmt2->get_result()->fetch_assoc()['nama_cabang'] ?? '-';
$no = 1;
?>

<!-- Custom CSS Tambahan untuk UI Premium & Modern Hijau -->
<style>
    :root {
        --primary-gradient: linear-gradient(135deg, #059669 0%, #10b981 100%);
        --success-gradient: linear-gradient(135deg, #10b981 0%, #059669 100%);
        --info-gradient: linear-gradient(135deg, #0d9488 0%, #14b8a6 100%);
    }
    .custom-card {
        border: none;
        border-radius: 16px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.03);
        background: #ffffff;
        overflow: hidden;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .custom-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 25px rgba(5, 150, 105, 0.08);
    }
    .header-banner {
        background: var(--primary-gradient);
        color: white;
        border-radius: 16px;
        padding: 1.5rem;
        margin-bottom: 1.5rem;
        box-shadow: 0 4px 15px rgba(16, 185, 129, 0.2);
    }
    .table-custom th {
        background-color: #f0fdf4 !important;
        color: #15803d;
        font-weight: 600;
        text-transform: uppercase;
        font-size: 0.75rem;
        letter-spacing: 0.5px;
        border-bottom: 2px solid #bbf7d0;
    }
    .table-custom td {
        font-size: 0.9rem;
        color: #1f2937;
        border-bottom: 1px solid #f0fdf4;
        padding: 14px 12px;
    }
    .badge-modern {
        padding: 0.5em 0.85em;
        border-radius: 8px;
        font-weight: 600;
        font-size: 0.8rem;
    }
    .btn-green-filter {
        background: var(--primary-gradient);
        border: none;
        color: white;
        transition: opacity 0.2s;
    }
    .btn-green-filter:hover {
        opacity: 0.9;
        color: white;
    }
    .btn-nav-bulan {
        background: #ffffff;
        border: 1px solid #bbf7d0;
        color: #059669;
        font-weight: 600;
        border-radius: 10px;
        padding: 8px 16px;
    }
    .btn-nav-bulan:hover {
        background: #dcfce7;
        color: #047857;
    }
</style>

<div class="container-fluid py-4">

    <!-- Header Banner -->
    <div class="header-banner d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h4 class="mb-1 fw-bold"><i class="bi bi-clock-history me-2"></i> Riwayat Input Data</h4>
            <p class="mb-0 opacity-90"><i class="bi bi-shop me-1"></i> <strong><?= h($cabang) ?></strong> &nbsp;|&nbsp; Pengelola: <?= h($nama_pengelola) ?></p>
        </div>
        <div class="bg-white bg-opacity-20 rounded-pill px-4 py-2 text-white border-white border-opacity-25">
            <span class="small fw-semibold"><i class="bi bi-circle-fill me-1 text-warning small"></i> Sistem Cabang Aktif</span>
        </div>
    </div>

    <!-- Navigasi Bulan -->
    <div class="card custom-card mb-4">
        <div class="card-body p-3 d-flex justify-content-between align-items-center">
            <a href="?bulan=<?= $prev_bulan ?>&tahun=<?= $prev_tahun ?>" class="btn btn-nav-bulan">
                <i class="bi bi-chevron-left"></i> Bulan Sebelumnya
            </a>
            
            <h5 class="fw-bold mb-0 text-center" style="color:#059669">
                <i class="bi bi-calendar3 me-2"></i> <?= date('F Y', strtotime($tgl_awal)) ?>
            </h5>

            <a href="?bulan=<?= $next_bulan ?>&tahun=<?= $next_tahun ?>" class="btn btn-nav-bulan">
                Bulan Berikutnya <i class="bi bi-chevron-right"></i>
            </a>
        </div>
    </div>

    <!-- Tabel Riwayat Card -->
    <div class="card custom-card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-custom table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="ps-4" style="width: 70px;">No</th>
                            <th>Tanggal</th>
                            <th>Omzet</th>
                            <th>Pengeluaran</th>
                            <th>Net Profit</th>
                            <th>Margin</th>
                            <th class="text-center pe-4" style="width: 150px;">Nota Transaksi</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if($data->num_rows == 0): ?>
                        <tr>
                            <td colspan="7" class="text-center py-5 text-muted">
                                <i class="bi bi-folder-x fs-1 d-block mb-2 text-success opacity-50"></i>
                                Belum ada data pada bulan <?= date('F Y', strtotime($tgl_awal)) ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php while($row=$data->fetch_assoc()): ?>
                        <tr>
                            <td class="ps-4 fw-semibold text-muted"><?= $no++ ?></td>
                            <td class="fw-bold text-secondary"><?= date("d M Y", strtotime($row['tanggal'])) ?></td>
                            <td><span class="text-success fw-bold">Rp <?= number_format($row['total_omset'],0,',','.') ?></span></td>
                            <td class="text-muted">Rp <?= number_format($row['total_pengeluaran'],0,',','.') ?></td>
                            <td><span class="fw-bold" style="color: #047857 !important;">Rp <?= number_format($row['net_profit'],0,',','.') ?></span></td>
                            <td>
                                <span class="badge badge-modern bg-success-subtle text-success border-success-subtle">
                                    <i class="bi bi-graph-up-arrow me-1"></i> <?= number_format($row['persentase'],2) ?>%
                                </span>
                            </td>
                            <td class="text-center pe-4">
                                <div class="d-flex justify-content-center gap-1">
                                    <?php 
                                    $has_nota = false;
                                    for($i=1; $i<=4; $i++):
                                        if(!empty($row["foto_nota$i"])):
                                            $has_nota = true;
                                    ?>
                                            <a href="../uploads/nota/<?= h($row["foto_nota$i"]) ?>" target="_blank" class="btn btn-sm btn-outline-success rounded-circle" style="width: 32px; height: 32px; padding: 0; display: inline-flex; align-items: center; justify-content: center;" title="Nota <?= $i ?>">
                                                <i class="bi bi-file-earmark-image" style="font-size: 0.85rem;"></i>
                                            </a>
                                    <?php 
                                        endif; 
                                    endfor;
                                    if(!$has_nota) echo '<span class="text-muted small">- Tidak ada nota -</span>';
                                    ?>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>