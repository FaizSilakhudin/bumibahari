<?php
require '../config/koneksi.php';
include 'sidebar_pusat.php';

// 1. PROTEKSI ROLE PUSAT
if(!isset($_SESSION['role']) || $_SESSION['role']!= 'pusat'){
    header("Location:../login"); exit;
}

$bulan_ini = date('Y-m');
$bulan_lalu = date('Y-m', strtotime('-1 month'));
$hari_ini = date('Y-m-d');

// Filter investor - PAKAI PREPARED
$filter_investor = $_GET['investor'] ?? '';
$where_filter = "";
$where_filter_cabang = "";
$params = [];
$types = "";

if($filter_investor){
    $where_filter = "AND l.id_cabang IN (SELECT c.id_cabang FROM cabang c WHERE c.id_investor=?)";
    $where_filter_cabang = "AND c.id_investor=?";
    $params[] = $filter_investor;
    $types .= "i";
}

// Ambil list investor
$list_investor = [];
$res_inv = $conn->query("SELECT id_investor, nama_investor FROM investor ORDER BY nama_investor ASC");
while($row = $res_inv->fetch_assoc()) $list_investor[] = $row;

$nama_filter = '';
if($filter_investor){
    $stmt = $conn->prepare("SELECT nama_investor FROM investor WHERE id_investor=?");
    $stmt->bind_param("i", $filter_investor);
    $stmt->execute();
    $nama_filter = $stmt->get_result()->fetch_assoc()['nama_investor']?? '';
}

// 1. KPI Bulan Ini
$sql_kpi = "SELECT COALESCE(SUM(l.total_omset),0) as omzet, COALESCE(SUM(l.net_profit),0) as laba, COALESCE(AVG(l.persentase),0) as margin FROM laporan_cabang l WHERE DATE_FORMAT(l.tanggal, '%Y-%m') = ? $where_filter";
$stmt = $conn->prepare($sql_kpi);
$bind_params = array_merge([$bulan_ini], $params);
$bind_types = "s".$types;
$stmt->bind_param($bind_types, ...$bind_params);
$stmt->execute();
$kpi = $stmt->get_result()->fetch_assoc();

// 1.1 KPI HARI INI
$sql_hari = "SELECT COALESCE(SUM(l.total_omset),0) as omzet, COALESCE(SUM(l.net_profit),0) as laba FROM laporan_cabang l WHERE l.tanggal = ? $where_filter";
$stmt = $conn->prepare($sql_hari);
$bind_params = array_merge([$hari_ini], $params);
$stmt->bind_param($bind_types, ...$bind_params);
$stmt->execute();
$kpi_hari_ini = $stmt->get_result()->fetch_assoc();

// 2. KPI Bulan Lalu
$sql_lalu = "SELECT COALESCE(SUM(l.total_omset),0) as omzet FROM laporan_cabang l WHERE DATE_FORMAT(l.tanggal, '%Y-%m') = ? $where_filter";
$stmt = $conn->prepare($sql_lalu);
$bind_params = array_merge([$bulan_lalu], $params);
$stmt->bind_param($bind_types, ...$bind_params);
$stmt->execute();
$kpi_lalu = $stmt->get_result()->fetch_assoc();
$naik_turun = $kpi_lalu['omzet'] > 0? (($kpi['omzet'] - $kpi_lalu['omzet']) / $kpi_lalu['omzet']) * 100 : 0;

// 3. Cabang aktif bulan ini
$sql_aktif = "SELECT COUNT(DISTINCT l.id_cabang) as total FROM laporan_cabang l WHERE DATE_FORMAT(l.tanggal, '%Y-%m') = ? $where_filter";
$stmt = $conn->prepare($sql_aktif);
$stmt->bind_param($bind_types, ...$bind_params);
$stmt->execute();
$cabang_aktif = $stmt->get_result()->fetch_assoc()['total'];

// 4. Total cabang
if($filter_investor){
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM cabang c WHERE c.id_investor=?");
    $stmt->bind_param("i", $filter_investor);
    $stmt->execute();
    $total_cabang = $stmt->get_result()->fetch_assoc()['total'];
} else {
    $total_cabang = $conn->query("SELECT COUNT(*) as total FROM cabang c")->fetch_assoc()['total'];
}

// 5. Top 5 cabang bulan ini
$sql_top = "SELECT c.nama_cabang, SUM(l.total_omset) as omzet FROM laporan_cabang l JOIN cabang c ON l.id_cabang = c.id_cabang WHERE DATE_FORMAT(l.tanggal, '%Y-%m') = ? $where_filter GROUP BY l.id_cabang ORDER BY omzet DESC LIMIT 5";
$stmt = $conn->prepare($sql_top);
$bind_params = array_merge([$bulan_ini], $params);
$stmt->bind_param($bind_types, ...$bind_params);
$stmt->execute();
$top_cabang = $stmt->get_result();

// 6. Data grafik 6 bulan
$sql_grafik = "SELECT DATE_FORMAT(l.tanggal, '%b %Y') as bulan, COALESCE(SUM(l.total_omset),0) as omzet, COALESCE(SUM(l.net_profit),0) as laba FROM laporan_cabang l WHERE l.tanggal >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) $where_filter GROUP BY DATE_FORMAT(l.tanggal, '%Y-%m') ORDER BY l.tanggal ASC";
$stmt = $conn->prepare($sql_grafik);
if($filter_investor) $stmt->bind_param($types, ...$params);
$stmt->execute();
$grafik = $stmt->get_result();
$label_grafik = $data_omzet = $data_laba = [];
while($g = $grafik->fetch_assoc()){
    $label_grafik[] = $g['bulan'];
    $data_omzet[] = $g['omzet'];
    $data_laba[] = $g['laba'];
}

// 7. Peringatan Dini
$sql_peringatan = "SELECT c.id_cabang, c.nama_cabang, c.nama_pengelola, MAX(l.tanggal) as input_terakhir, DATEDIFF(CURDATE(), MAX(l.tanggal)) as selisih_hari FROM cabang c LEFT JOIN laporan_cabang l ON c.id_cabang = l.id_cabang WHERE c.id_cabang NOT IN (SELECT id_cabang FROM laporan_cabang WHERE tanggal = CURDATE()) $where_filter_cabang GROUP BY c.id_cabang ORDER BY selisih_hari DESC, c.nama_cabang ASC";
$stmt = $conn->prepare($sql_peringatan);
if($filter_investor) $stmt->bind_param("i", $filter_investor);
$stmt->execute();
$peringatan = $stmt->get_result();

$share_pengelola_kotor = ($kpi['laba']?? 0) * 0.50; 
$admin_fee = $share_pengelola_kotor * 0.03;
?>

<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

<style>
    body {
        background-color: #f8fafc!important;
        font-family: 'Plus Jakarta Sans', sans-serif!important;
        color: #1e293b;
    }
    
    /* Global Soft Glassmorphism Card Style */
    .saas-card {
        background: #ffffff;
        border: 1px solid #f1f5f9!important;
        border-radius: 18px!important;
        box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.02), 0 2px 4px -2px rgb(0 0 0 / 0.02), 0 10px 15px -3px rgb(0 0 0 / 0.01)!important;
        padding: 24px;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        position: relative;
    }
    .saas-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 20px 25px -5px rgb(0 0 0 / 0.05), 0 8px 10px -6px rgb(0 0 0 / 0.05)!important;
    }

    /* KPI Premium Card Refinement */
    .kpi-premium-card {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 18px;
        padding: 24px;
        position: relative;
        overflow: hidden;
        min-height: 160px;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.02);
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .kpi-premium-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 12px 20px -8px rgba(15, 23, 42, 0.08);
    }
    .kpi-premium-card::before {
        content: '';
        position: absolute;
        top: -20px; right: -20px;
        width: 140px; height: 140px;
        background: radial-gradient(circle, var(--kpi-glow) 0%, transparent 75%);
        opacity: 0.08;
        pointer-events: none;
    }
    .kpi-badge-icon {
        width: 46px; height: 46px;
        border-radius: 12px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;
        background-color: var(--badge-bg);
        color: var(--badge-color);
        box-shadow: 0 4px 10px -2px var(--badge-bg);
    }
    .kpi-meta {
        font-size: 11px;
        font-weight: 700;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 1px;
    }
    .kpi-value {
        font-size: 24px;
        font-weight: 800;
        color: #0f172a;
        letter-spacing: -0.5px;
        line-height: 1.2;
        margin-top: 4px;
    }
    .kpi-subvalue {
        font-size: 12px;
        font-weight: 500;
        color: #64748b;
        margin-top: 4px;
    }

    /* Modernized Badges */
    .badge-modern-danger {
        background-color: #fef2f2;
        color: #ef4444;
        font-weight: 600;
        padding: 5px 10px;
        border-radius: 8px;
        font-size: 11px;
        border: 1px solid #fee2e2;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }
    .badge-modern-warning {
        background-color: #fffbeb;
        color: #f59e0b;
        font-weight: 600;
        padding: 5px 10px;
        border-radius: 8px;
        font-size: 11px;
        border: 1px solid #fef3c7;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }
    .badge-modern-success {
        background-color: #f0fdf4;
        color: #22c55e;
        font-weight: 600;
        padding: 5px 10px;
        border-radius: 8px;
        font-size: 11px;
        border: 1px solid #dcfce7;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }

    /* Clean Form Elements */
    .form-select-filter {
        border-radius: 12px!important;
        border: 1px solid #e2e8f0!important;
        font-size: 14px;
        font-weight: 600;
        color: #334155;
        padding: 10px 16px;
        min-width: 240px;
        background-color: #ffffff;
        transition: all 0.2s ease;
    }
    .form-select-filter:focus {
        border-color: #4318ff!important;
        box-shadow: 0 0 0 4px rgba(67, 24, 255, 0.1)!important;
    }

    /* Elegant SaaS Tables */
    .table-saas-container {
        border-radius: 14px;
        overflow: hidden;
        border: 1px solid #e2e8f0;
    }
    .table-saas {
        margin-bottom: 0;
        background: #ffffff;
    }
    .table-saas thead th {
        background-color: #f8fafc;
        color: #475569;
        font-weight: 700;
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        border-bottom: 1px solid #e2e8f0;
        padding: 14px 20px;
    }
    .table-saas tbody tr {
        transition: background-color 0.2s ease;
    }
    .table-saas tbody tr:hover {
        background-color: #f8fafc;
    }
    .table-saas tbody td {
        padding: 16px 20px;
        border-bottom: 1px solid #f1f5f9;
        color: #334155;
        font-size: 14px;
    }
    
    /* Leaderboard Rank Badges */
    .rank-box {
        width: 26px;
        height: 26px;
        background: #f1f5f9;
        color: #475569;
        border-radius: 6px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 11px;
        font-weight: 700;
    }
    .d-flex:nth-child(1) .rank-box { background: rgba(67, 24, 255, 0.1); color: #4318ff; }
    .d-flex:nth-child(2) .rank-box { background: rgba(14, 165, 233, 0.1); color: #0ea5e9; }
    .d-flex:nth-child(3) .rank-box { background: rgba(16, 185, 129, 0.1); color: #10b981; }

    @media (max-width: 767.98px) {
        .table-saas thead { display: none; }
        .table-saas tbody tr { 
            display: block; 
            border: 1px solid #e2e8f0; 
            border-radius: 14px; 
            margin: 12px; 
            padding: 12px;
            background: #ffffff;
        }
        .table-saas tbody td { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            padding: 10px 0!important; 
            border-bottom: 1px dashed #e2e8f0!important;
            text-align: right;
        }
        .table-saas tbody td:last-child { border-bottom: none!important; }
        .table-saas tbody td::before {
            content: attr(data-label);
            font-weight: 700;
            color: #94a3b8;
            font-size: 11px;
            text-transform: uppercase;
            text-align: left;
        }
    }
</style>

<div class="container-fluid py-4 px-3 px-md-4">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-start align-items-lg-center mb-4 gap-3">
        <div>
            <span class="text-muted small fw-bold text-uppercase tracking-wider" style="font-size: 10px; letter-spacing: 1px; color:#94a3b8!important;">Main Administration</span>
            <h3 class="fw-bold mb-0 mt-1" style="color: #0f172a!important; font-size: 24px; letter-spacing: -0.5px;">
                Dashboard Pusat <?= $nama_filter? "<span style='color: #4318ff;'>• ".h($nama_filter)."</span>" : ""?>
            </h3>
        </div>
        
        <div class="d-flex flex-column flex-sm-row align-items-stretch align-items-sm-center gap-2 w-100 w-lg-auto">
            <form method="GET" class="d-flex align-items-center gap-2 flex-grow-1 flex-sm-grow-0">
                <select name="investor" class="form-select form-select-filter" onchange="this.form.submit()">
                    <option value="">Semua Investor</option>
                    <?php foreach($list_investor as $inv):?>
                    <option value="<?= $inv['id_investor']?>" <?= $filter_investor==$inv['id_investor']?'selected':''?>>
                        <?= h($inv['nama_investor'])?>
                    </option>
                    <?php endforeach;?>
                </select>
                <?php if($filter_investor):?>
                <a href="index" class="btn btn-light" style="border-radius: 12px; padding: 10px 14px; border: 1px solid #e2e8f0; background: #fff;" title="Reset Filter"> <!-- TANPA .PHP -->
                    <i class="bi bi-x-lg text-danger"></i>
                </a>
                <?php endif;?>
            </form>
            <div class="bg-white px-3 py-2 rounded-3 border d-flex align-items-center justify-content-center" style="border-radius: 12px!important; font-weight: 600; color: #475569!important; font-size: 14px; border-color: #e2e8f0!important;">
                <i class="bi bi-calendar4-event me-2 text-primary" style="color: #4318ff!important;"></i><?= date('F Y')?>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-3 col-md-6 col-12">
            <div class="kpi-premium-card" style="--kpi-glow: #4318ff; border-top: 3px solid #4318ff;">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="d-flex flex-column">
                        <span class="kpi-meta">Total Omzet</span>
                        <h4 class="kpi-value">Rp <?= number_format($kpi['omzet']?? 0,0,',','.')?></h4>
                        <span class="kpi-subvalue">Hari ini: <span class="fw-semibold text-dark">Rp <?= number_format($kpi_hari_ini['omzet']?? 0,0,',','.')?></span></span>
                    </div>
                    <div class="kpi-badge-icon" style="--badge-bg: rgba(67, 24, 255, 0.06); --badge-color: #4318ff;">
                        <i class="bi bi-graph-up"></i>
                    </div>
                </div>
                <div class="pt-2 mt-2 border-top" style="border-color: #f1f5f9!important;">
                    <span class="<?= $naik_turun >= 0? 'badge-modern-success' : 'badge-modern-danger'?>">
                        <i class="bi bi-<?= $naik_turun >= 0? 'arrow-up-short' : 'arrow-down-short'?> fs-6"></i> 
                        <?= number_format(abs($naik_turun),1)?>%
                    </span>
                    <span class="text-muted small ms-1" style="font-size: 12px;">vs bulan lalu</span>
                </div>
            </div>
        </div>

        <div class="col-lg-3 col-md-6 col-12">
            <div class="kpi-premium-card" style="--kpi-glow: #0ea5e9; border-top: 3px solid #0ea5e9;">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="d-flex flex-column">
                        <span class="kpi-meta">Laba Bersih</span>
                        <h4 class="kpi-value">Rp <?= number_format($kpi['laba']?? 0,0,',','.')?></h4>
                        <span class="kpi-subvalue">Hari ini: <span class="fw-semibold text-dark">Rp <?= number_format($kpi_hari_ini['laba']?? 0,0,',','.')?></span></span>
                    </div>
                    <div class="kpi-badge-icon" style="--badge-bg: rgba(14, 165, 233, 0.06); --badge-color: #0ea5e9;">
                        <i class="bi bi-wallet2"></i>
                    </div>
                </div>
                <div class="pt-2 mt-2 border-top" style="border-color: #f1f5f9!important;">
                    <span class="badge-modern-success" style="background-color: #f0f9ff; color: #0ea5e9; border-color: #e0f2fe;">
                        <i class="bi bi-pie-chart-fill me-1"></i> Margin <?= number_format($kpi['margin']?? 0,2)?>%
                    </span>
                </div>
            </div>
        </div>

        <div class="col-lg-3 col-md-6 col-12">
            <div class="kpi-premium-card" style="--kpi-glow: #10b981; border-top: 3px solid #10b981;">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="d-flex flex-column">
                        <span class="kpi-meta">Cabang Aktif</span>
                        <h4 class="kpi-value"><?= $cabang_aktif?> <span style="font-size: 14px; color:#94a3b8; font-weight: 500;">/ <?= $total_cabang?> Unit</span></h4>
                    </div>
                    <div class="kpi-badge-icon" style="--badge-bg: rgba(16, 185, 129, 0.06); --badge-color: #10b981;">
                        <i class="bi bi-building-check"></i>
                    </div>
                </div>
                <div class="pt-2">
                    <div class="progress" style="height: 6px; border-radius: 10px; background-color: #f1f5f9;">
                        <div class="progress-bar" style="width:<?= $total_cabang>0? ($cabang_aktif/$total_cabang)*100 : 0?>%; background: linear-gradient(90deg, #10b981, #34d399); border-radius: 10px;"></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-3 col-md-6 col-12">
            <div class="kpi-premium-card" style="--kpi-glow: #f59e0b; border-top: 3px solid #f59e0b;">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="d-flex flex-column">
                        <span class="kpi-meta">Admin Fee (3%)</span>
                        <h4 class="kpi-value" style="color: #0f172a;">Rp <?= number_format($admin_fee,0,',','.')?></h4>
                    </div>
                    <div class="kpi-badge-icon" style="--badge-bg: rgba(245, 158, 11, 0.06); --badge-color: #f59e0b;">
                        <i class="bi bi-shield-check"></i>
                    </div>
                </div>
                <div class="pt-2 mt-2 border-top" style="border-color: #f1f5f9!important; font-size: 12px; color: #64748b; font-weight: 500;">
                    <i class="bi bi-info-circle me-1 text-warning"></i> Estimasi bagi hasil pusat
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-8 col-12">
            <div class="card saas-card h-100">
                <div class="d-flex align-items-center mb-4">
                    <h6 class="fw-bold mb-0" style="color: #0f172a; font-size: 15px;">Trend Performa 6 Bulan Terakhir</h6>
                </div>
                <div style="position: relative; height: 280px; width: 100%;">
                    <canvas id="grafikTrend"></canvas>
                </div>
            </div>
        </div>

        <div class="col-lg-4 col-12">
            <div class="card saas-card h-100">
                <div class="d-flex align-items-center mb-4">
                    <h6 class="fw-bold mb-0" style="color: #0f172a; font-size: 15px;">Top 5 Cabang Terlaris</h6>
                </div>
                <div class="d-flex flex-column gap-2">
                    <?php $no=1; while($t=$top_cabang->fetch_assoc()):?>
                    <div class="d-flex justify-content-between align-items-center" style="background: #f8fafc; border: 1px solid #f1f5f9; border-radius: 12px!important; padding: 12px 14px;">
                        <div class="d-flex align-items-center">
                            <div class="rank-box me-3"><?= $no++?></div>
                            <span class="fw-semibold" style="font-size: 14px; color: #334155;"><?= h($t['nama_cabang'])?></span>
                        </div>
                        <span class="text-end" style="color: #4318ff; font-size: 14px; font-weight:700;">Rp <?= number_format($t['omzet'],0,',','.')?></span>
                    </div>
                    <?php endwhile;?>
                    
                    <?php if($top_cabang->num_rows==0):?>
                    <div class="text-muted text-center py-5" style="color: #94a3b8!important;">
                        <i class="bi bi-folder-x fs-2 d-block mb-2"></i> Tidak ada data penjualan
                    </div>
                    <?php endif;?>
                </div>
            </div>
        </div>
    </div>

    <div class="card saas-card p-0 overflow-hidden mb-4" style="border: 1px solid #e2e8f0!important;">
        <div class="px-4 pt-4 pb-3">
            <h6 class="fw-bold mb-0" style="color: #0f172a; font-size: 15px;">🚨 Peringatan Dini Operasional</h6>
            <p class="text-muted small mb-0 mt-1">Daftar cabang terdeteksi yang belum mengirimkan data transaksi hari ini.</p>
        </div>

        <div class="px-4 pb-4">
            <div class="table-saas-container">
                <table class="table table-saas align-middle">
                    <thead>
                        <tr>
                            <th>Status</th>
                            <th>Nama Cabang</th>
                            <th>Nama Pengelola</th>
                            <th>Masalah</th>
                            <th>Input Terakhir</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($peringatan->num_rows == 0):?>
                        <tr>
                            <td colspan="5" class="text-center text-success py-5 fw-bold" style="color: #16a34a!important; background: #ffffff;">
                                <div class="d-flex align-items-center justify-content-center gap-2 fs-6">
                                    <i class="bi bi-shield-check fs-4"></i> Luar Biasa! Semua pengelola cabang sudah menginput laporan hari ini.
                                </div>
                            </td>
                        </tr>
                        <?php else: while($p=$peringatan->fetch_assoc()): 
                            $hari_telat = $p['selisih_hari']?? 0;
                            
                            if ($hari_telat == 0 || $p['input_terakhir'] == NULL) {
                                $status_badge = '<span class="badge-modern-danger"><i class="bi bi-exclamation-circle-fill"></i> Terlambat</span>';
                                $masalah_text = '<span class="text-danger fw-semibold">Belum mengirim laporan hari ini</span>';
                                $txt_terakhir = '<span class="badge bg-light text-secondary border fw-bold" style="font-size:11px; padding:4px 8px; border-radius:6px;">Belum Pernah</span>';
                            } else {
                                $status_badge = '<span class="badge-modern-warning"><i class="bi bi-clock-history"></i> Peringatan</span>';
                                $masalah_text = '<span class="fw-semibold" style="color: #d97706;">Menunggak laporan selama ' . $hari_telat . ' hari</span>';
                                $txt_terakhir = '<span class="fw-semibold text-secondary">' . date('d M Y', strtotime($p['input_terakhir'])) . '</span>';
                            }
                        ?>
                        <tr>
                            <td data-label="Status"><?= $status_badge?></td>
                            <td data-label="Nama Cabang"><span class="fw-bold" style="color: #0f172a;"><?= h($p['nama_cabang'])?></span></td>
                            <td data-label="Nama Pengelola">
                                <div class="fw-semibold text-secondary" style="font-size: 13.5px;">
                                    <i class="bi bi-person-badge me-1 text-primary" style="color: #4318ff!important;"></i>
                                    <?= h($p['nama_pengelola']?? 'Belum Diatur')?>
                                </div>
                            </td>
                            <td data-label="Masalah"><?= $masalah_text?></td>
                            <td data-label="Input Terakhir" class="text-muted fw-medium"><?= $txt_terakhir?></td>
                        </tr>
                        <?php endwhile; endif;?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const ctx = document.getElementById('grafikTrend').getContext('2d');

const gradOmzet = ctx.createLinearGradient(0, 0, 0, 250);
gradOmzet.addColorStop(0, 'rgba(67, 24, 255, 0.12)');
gradOmzet.addColorStop(1, 'rgba(67, 24, 255, 0.0)');

const gradLaba = ctx.createLinearGradient(0, 0, 0, 250);
gradLaba.addColorStop(0, 'rgba(14, 165, 233, 0.12)');
gradLaba.addColorStop(1, 'rgba(14, 165, 233, 0.0)');

new Chart(ctx, {
    type: 'line',
    data: {
        labels: <?= json_encode($label_grafik)?>,
        datasets: [{
            label: 'Omzet',
            data: <?= json_encode($data_omzet)?>,
            borderColor: '#4318ff',
            backgroundColor: gradOmzet,
            borderWidth: 2.5,
            tension: 0.3,
            fill: true,
            pointRadius: 0,
            pointHoverRadius: 5,
            pointHoverBackgroundColor: '#4318ff',
            pointHoverBorderColor: '#fff',
            pointHoverBorderWidth: 2
        }, {
            label: 'Laba Bersih',
            data: <?= json_encode($data_laba)?>,
            borderColor: '#0ea5e9',
            backgroundColor: gradLaba,
            borderWidth: 2.5,
            tension: 0.3,
            fill: true,
            pointRadius: 0,
            pointHoverRadius: 5,
            pointHoverBackgroundColor: '#0ea5e9',
            pointHoverBorderColor: '#fff',
            pointHoverBorderWidth: 2
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: {
            mode: 'index',
            intersect: false,
        },
        plugins: { 
            legend: { 
                position: 'top',
                align: 'end',
                labels: {
                    boxWidth: 6,
                    boxHeight: 6,
                    usePointStyle: true,
                    font: { family: 'Plus Jakarta Sans', weight: '600', size: 12 },
                    padding: 15
                }
            },
            tooltip: {
                padding: 12,
                backgroundColor: '#0f172a',
                titleFont: { family: 'Plus Jakarta Sans', size: 13, weight: '700' },
                bodyFont: { family: 'Plus Jakarta Sans', size: 13 },
                borderRadius: 10,
                boxPadding: 6,
                callbacks: {
                    label: function(context) {
                        let label = context.dataset.label || '';
                        if (label) {
                            label += ': ';
                        }
                        if (context.parsed.y!== null) {
                            label += 'Rp ' + context.parsed.y.toLocaleString('id-ID');
                        }
                        return label;
                    }
                }
            }
        },
        scales: { 
            x: {
                grid: { display: false },
                ticks: { color: '#94a3b8', font: { family: 'Plus Jakarta Sans', size: 11, weight: '500' } }
            },
            y: { 
                grid: { color: '#f1f5f9' },
                border: { dash: [5, 5] },
                ticks: { 
                    color: '#94a3b8',
                    font: { family: 'Plus Jakarta Sans', size: 11, weight: '500' },
                    callback: function(v) { 
                        if(v >= 1000000) return 'Rp ' + (v/1000000) + ' Jt';
                        return 'Rp ' + v.toLocaleString('id-ID');
                    } 
                } 
            } 
        }
    }
});
</script>