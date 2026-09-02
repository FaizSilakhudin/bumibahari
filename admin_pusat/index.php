<?php
require '../config/koneksi.php';
include 'sidebar_pusat.php';

// 1. PROTEKSI ROLE PUSAT
if(!isset($_SESSION['role']) || $_SESSION['role']!= 'pusat'){
    header("Location:../login"); exit;
}

// =====================================================================
// FILTER DASHBOARD — periode (bulan/tahun) + investor.
// Semua isi dashboard mengikuti pilihan ini.
// =====================================================================
$sel_tahun = (int) ($_GET['tahun'] ?? date('Y'));
$sel_bulan = (int) ($_GET['bulan'] ?? date('m'));
if ($sel_tahun < tahun_data_paling_lama($conn) || $sel_tahun > ((int) date('Y') + 1)) $sel_tahun = (int) date('Y');
if ($sel_bulan < 1 || $sel_bulan > 12) $sel_bulan = (int) date('m');

$periode_ini   = sprintf('%04d-%02d', $sel_tahun, $sel_bulan);            // mis. 2026-08
$periode_lalu  = date('Y-m', strtotime("$periode_ini-01 -1 month"));      // bulan sebelum yang dipilih
$nama_periode  = date('F Y', strtotime("$periode_ini-01"));

// "Hari ini" pada KPI diambil 1 hari ke belakang: laporan tgl 28 = data tgl 27.
$hari_ini = date('Y-m-d');
$kemarin  = date('Y-m-d', strtotime('-1 day'));

// ---- Filter investor (lewat tabel relasi cabang_investor) ----
$filter_investor = ctype_digit((string) ($_GET['investor'] ?? '')) ? (int) $_GET['investor'] : 0;

// Dashboard pusat hanya menghitung laporan yang sudah difinalisasi PIC (status 'lengkap'),
// supaya baris nota-only (belum diisi PIC) tidak ikut ke angka KPI.
$where_filter        = "AND l.status_laporan = 'lengkap'";   // untuk query yang JOIN/pakai laporan_cabang l
$where_filter_cabang = "";   // untuk query yang pakai cabang c
$params = [];
$types  = "";

// Sub-query: id_cabang yang investor-nya = ? PADA PERIODE TERPILIH (bukan investor
// aktif hari ini) — supaya filter investor tetap benar untuk bulan-bulan lama
// walau sudah ada rotasi investor sesudahnya. $periode_ini sudah divalidasi
// int di atas, aman diinterpolasi langsung sebagai tanggal literal.
$periode_anchor_sql = $conn->real_escape_string(anchor_periode(date('Y-m-t', strtotime("$periode_ini-01"))));
$cabang_of_investor = "SELECT c2.id_cabang FROM cabang c2 WHERE (
        SELECT ci.id_investor FROM cabang_investor ci
        WHERE ci.id_cabang = c2.id_cabang
          AND ci.tgl_mulai <= '$periode_anchor_sql'
          AND (ci.tgl_selesai IS NULL OR ci.tgl_selesai >= '$periode_anchor_sql')
        ORDER BY ci.tgl_mulai DESC, ci.id DESC LIMIT 1
    ) = ?";

if ($filter_investor) {
    $where_filter        .= " AND l.id_cabang IN ($cabang_of_investor)";
    $where_filter_cabang = "AND c.id_cabang IN ($cabang_of_investor)";
    $params[] = $filter_investor;
    $types   .= "i";
}
$bind_types = "s" . $types; // 1 string (tanggal/periode) + param investor

// ---- Daftar & nama investor ----
$list_investor = [];
$res_inv = $conn->query("SELECT id_investor, nama_investor FROM investor ORDER BY nama_investor ASC");
while ($r = $res_inv->fetch_assoc()) $list_investor[] = $r;

$nama_filter = '';
if ($filter_investor) {
    $st = $conn->prepare("SELECT nama_investor FROM investor WHERE id_investor = ?");
    $st->bind_param("i", $filter_investor);
    $st->execute();
    $nama_filter = $st->get_result()->fetch_assoc()['nama_investor'] ?? '';
}

// =====================================================================
// 1. KPI — periode terpilih
// =====================================================================
$st = $conn->prepare("SELECT COALESCE(SUM(l.total_omset),0) omzet, COALESCE(SUM(l.net_profit),0) laba,
                             COALESCE(AVG(l.persentase),0) margin
                      FROM laporan_cabang l
                      WHERE DATE_FORMAT(l.tanggal,'%Y-%m') = ? $where_filter");
$st->bind_param($bind_types, ...array_merge([$periode_ini], $params));
$st->execute();
$kpi = $st->get_result()->fetch_assoc();

// 1.1 KPI "Hari ini" (= kemarin)
$st = $conn->prepare("SELECT COALESCE(SUM(l.total_omset),0) omzet, COALESCE(SUM(l.net_profit),0) laba
                      FROM laporan_cabang l WHERE l.tanggal = ? $where_filter");
$st->bind_param($bind_types, ...array_merge([$kemarin], $params));
$st->execute();
$kpi_hari_ini = $st->get_result()->fetch_assoc();

// 2. KPI bulan sebelum periode terpilih (untuk perbandingan)
$st = $conn->prepare("SELECT COALESCE(SUM(l.total_omset),0) omzet
                      FROM laporan_cabang l WHERE DATE_FORMAT(l.tanggal,'%Y-%m') = ? $where_filter");
$st->bind_param($bind_types, ...array_merge([$periode_lalu], $params));
$st->execute();
$kpi_lalu = $st->get_result()->fetch_assoc();
$naik_turun = $kpi_lalu['omzet'] > 0 ? (($kpi['omzet'] - $kpi_lalu['omzet']) / $kpi_lalu['omzet']) * 100 : 0;

// 3. Cabang aktif pada periode terpilih
$st = $conn->prepare("SELECT COUNT(DISTINCT l.id_cabang) total
                      FROM laporan_cabang l WHERE DATE_FORMAT(l.tanggal,'%Y-%m') = ? $where_filter");
$st->bind_param($bind_types, ...array_merge([$periode_ini], $params));
$st->execute();
$cabang_aktif = (int) $st->get_result()->fetch_assoc()['total'];

// 4. Total cabang (ikut filter investor)
if ($filter_investor) {
    $st = $conn->prepare("SELECT COUNT(DISTINCT c.id_cabang) total FROM cabang c WHERE 1=1 $where_filter_cabang");
    $st->bind_param("i", $filter_investor);
    $st->execute();
    $total_cabang = (int) $st->get_result()->fetch_assoc()['total'];
} else {
    $total_cabang = (int) $conn->query("SELECT COUNT(*) total FROM cabang")->fetch_assoc()['total'];
}

// =====================================================================
// 5. Grafik tren — granularitas bisa dipilih (harian/mingguan/bulanan/tahunan),
//    berakhir di periode terpilih.
// =====================================================================
$granularitas_tren = $_GET['tren'] ?? 'bulanan';
if (!in_array($granularitas_tren, ['harian', 'mingguan', 'bulanan', 'tahunan'], true)) {
    $granularitas_tren = 'bulanan';
}
$g_end = anchor_periode(date('Y-m-t', strtotime("$periode_ini-01")));
$tren  = ambil_tren_performa($conn, $granularitas_tren, $g_end, $where_filter, $params, $types);
$label_grafik = $tren['label'];
$data_omzet   = $tren['omzet'];
$data_laba    = $tren['laba'];
$label_periode_tren = [
    'harian'   => '30 Hari Terakhir',
    'mingguan' => '12 Minggu Terakhir',
    'bulanan'  => '6 Bulan Terakhir',
    'tahunan'  => '5 Tahun Terakhir',
][$granularitas_tren];

// =====================================================================
// 6. Peringatan dini — cabang yang SAMA SEKALI belum kirim laporan/nota untuk
// KEMARIN (bukan yang notanya sudah masuk tapi belum diisi PIC — itu bukan
// "belum mengirim", jadi tidak masuk peringatan ini).
// =====================================================================
$limit_peringatan  = 10;
$page_peringatan   = max(1, (int) ($_GET['page_peringatan'] ?? 1));
$offset_peringatan = ($page_peringatan - 1) * $limit_peringatan;

$st = $conn->prepare("SELECT COUNT(*) total FROM cabang c
    WHERE c.id_cabang NOT IN (SELECT id_cabang FROM laporan_cabang WHERE tanggal = ?) $where_filter_cabang");
$st->bind_param("s" . $types, ...array_merge([$kemarin], $params));
$st->execute();
$total_peringatan = (int) $st->get_result()->fetch_assoc()['total'];
$total_pages_peringatan = (int) ceil($total_peringatan / $limit_peringatan);

$st = $conn->prepare("SELECT c.id_cabang, c.nama_cabang, c.nama_pengelola,
        MAX(l.tanggal) input_terakhir, DATEDIFF(?, MAX(l.tanggal)) selisih_hari
    FROM cabang c LEFT JOIN laporan_cabang l ON c.id_cabang = l.id_cabang
    WHERE c.id_cabang NOT IN (SELECT id_cabang FROM laporan_cabang WHERE tanggal = ?) $where_filter_cabang
    GROUP BY c.id_cabang ORDER BY selisih_hari DESC, c.nama_cabang ASC
    LIMIT ? OFFSET ?");
$st->bind_param("ss" . $types . "ii", ...array_merge([$kemarin, $kemarin], $params, [$limit_peringatan, $offset_peringatan]));
$st->execute();
$peringatan = $st->get_result();

// =====================================================================
// 7. Admin Fee Pusat = 3% dari total net profit (periode terpilih, ikut investor)
// =====================================================================
$filter_cabang_by_inv = $filter_investor ? "AND c.id_cabang IN ($cabang_of_investor)" : "";

$st = $conn->prepare("SELECT COALESCE(SUM(l.net_profit),0) tot
    FROM cabang c
    LEFT JOIN laporan_cabang l ON l.id_cabang = c.id_cabang
        AND YEAR(l.tanggal) = $sel_tahun AND MONTH(l.tanggal) = $sel_bulan AND l.status_laporan = 'lengkap'
    WHERE 1=1 $filter_cabang_by_inv");
if ($filter_investor) $st->bind_param("i", $filter_investor);
$st->execute();
$total_net_profit = (float) $st->get_result()->fetch_assoc()['tot'];
$admin_fee = $total_net_profit > 0 ? $total_net_profit * 3 / 100 : 0;

// =====================================================================
// 8. Ranking cabang (periode terpilih, ikut investor)
// =====================================================================
$ranking_cabang = [];
$st = $conn->prepare("SELECT c.id_cabang, c.nama_cabang, c.nama_pengelola,
        COALESCE(SUM(l.total_omset),0) total_omset,
        COALESCE(SUM(l.net_profit),0) total_net_profit
    FROM cabang c
    LEFT JOIN laporan_cabang l ON l.id_cabang = c.id_cabang
        AND YEAR(l.tanggal) = $sel_tahun AND MONTH(l.tanggal) = $sel_bulan AND l.status_laporan = 'lengkap'
    WHERE 1=1 $filter_cabang_by_inv
    GROUP BY c.id_cabang
    ORDER BY total_omset DESC");
if ($filter_investor) $st->bind_param("i", $filter_investor);
$st->execute();
$res_rank = $st->get_result();
$no = 1;
while ($row = $res_rank->fetch_assoc()) {
    $row['no'] = $no++;
    // Pengelola PADA PERIODE terpilih, bukan kolom statis cabang.nama_pengelola
    // (yang tidak pernah ikut ter-update walau ada rotasi pengelola).
    $row['nama_pengelola'] = pengelola_pada_tanggal($conn, (int) $row['id_cabang'], $periode_anchor_sql);
    $ranking_cabang[] = $row;
}
?>

<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

<!-- TARUH DISINI -->
<audio id="notifSound" src="../assets/sound/notif.wav" preload="auto"></audio>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<!-- SCRIPT NOTIFIKASI SUARA -->
<script>
let lastCheck = '<?= $hari_ini ?> 00:00:00'; 
const notifSound = document.getElementById('notifSound');

function cekLaporanBaru() {
    fetch('../ajax/cek_laporan_baru.php?last=' + lastCheck)
    .then(res => res.json())
    .then(data => {
        if(data.status === 'baru'){
            notifSound.play().catch(e => console.log("Browser blokir autoplay"));
            showToast('Laporan Baru!', `Cabang ${data.nama_cabang} baru saja input laporan.`);
            setTimeout(() => location.reload(), 1500); // reload biar tabel peringatan update
        }
        lastCheck = data.waktu_sekarang;
    });
}

setInterval(cekLaporanBaru, 10000); // cek tiap 10 detik

function showToast(title, message){
    const toast = document.createElement('div');
    toast.className = 'position-fixed top-0 end-0 p-3';
    toast.style.zIndex = '9999';
    toast.innerHTML = `
    <div class="toast show align-items-center text-white bg-success border-0" role="alert">
      <div class="d-flex">
        <div class="toast-body">
          <i class="bi bi-bell-fill me-2"></i><b>${title}</b><br>${message}
        </div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
      </div>
    </div>`;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 5000);
}

// biar bisa autoplay, browser harus ada interaksi 1x
document.addEventListener('click', () => notifSound.play().then(()=>notifSound.pause()), {once: true});
</script>

<style>
  :root {
    --primary-color: #4318ff;
    --primary-hover: #3311db;
    --bg-body: #f8fafc;
    --text-dark: #0f172a;
    --text-muted: #64748b;
    --border-color: #e2e8f0;
  }

  body {
    font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
    background-color: var(--bg-body);
    color: var(--text-dark);
  }

  /* Card Base Styling */
  .saas-card {
    background: #ffffff;
    border: 1px solid var(--border-color);
    border-radius: 16px;
    padding: 20px;
    box-shadow: 0px 4px 20px rgba(0, 0, 0, 0.03);
    transition: all 0.2s ease-in-out;
  }

  /* KPI Card Custom */
  .kpi-premium-card {
    background: #ffffff;
    border: 1px solid var(--border-color);
    border-radius: 16px;
    padding: 20px;
    box-shadow: 0px 4px 20px rgba(0, 0, 0, 0.03);
    position: relative;
    overflow: hidden;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
  }
  .kpi-premium-card:hover {
    transform: translateY(-2px);
    box-shadow: 0px 10px 25px rgba(0, 0, 0, 0.06);
  }
  .kpi-meta {
    font-size: 13px;
    font-weight: 600;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
  }
  .kpi-value {
    font-size: 22px;
    font-weight: 800;
    color: var(--text-dark);
    margin-top: 6px;
    margin-bottom: 2px;
  }
  .kpi-subvalue {
    font-size: 12.5px;
    color: var(--text-muted);
  }
  .kpi-badge-icon {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    background: var(--badge-bg);
    color: var(--badge-color);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
  }

  /* Custom Badges */
  .badge-modern-success {
    background: #ecfdf5;
    color: #10b981;
    border: 1px solid #a7f3d0;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
  }
  .badge-modern-danger {
    background: #fef2f2;
    color: #ef4444;
    border: 1px solid #fecaca;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
  }
  .badge-modern-warning {
    background: #fffbeb;
    color: #f59e0b;
    border: 1px solid #fde68a;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
  }

  /* Filter Select */
  .form-select-filter {
    border-radius: 12px;
    border: 1px solid var(--border-color);
    padding: 9px 16px;
    font-size: 14px;
    font-weight: 600;
    color: var(--text-dark);
    background-color: #fff;
    box-shadow: 0px 2px 6px rgba(0,0,0,0.02);
  }
  .form-select-filter:focus {
    border-color: var(--primary-color);
    box-shadow: 0 0 0 3px rgba(67, 24, 255, 0.15);
  }

  /* Ranking Card Header Styling */
  .kpi-card {
    background: #ffffff;
    border: 1px solid var(--border-color);
    border-radius: 16px;
    padding: 20px;
    box-shadow: 0px 4px 20px rgba(0, 0, 0, 0.03);
  }
  .kpi-header {
    display: flex;
    align-items: center;
    gap: 12px;
  }
  .kpi-icon {
    width: 42px;
    height: 42px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #ffffff;
    font-size: 18px;
    box-shadow: 0 4px 12px rgba(14, 165, 233, 0.3);
  }
  .kpi-label {
    font-size: 16px;
    font-weight: 700;
    color: var(--text-dark);
  }

  /* Table Standar Modern (Samping Scroll) */
  .table-modern thead th {
    background: #f8fafc;
    color: var(--text-muted);
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-weight: 700;
    padding: 14px 16px;
    border-bottom: 1px solid var(--border-color);
    white-space: nowrap;
  }
  .table-modern tbody td {
    padding: 16px;
    border-bottom: 1px solid #f1f5f9;
    font-size: 14px;
    white-space: nowrap;
  }
</style>

<div class="container-fluid py-4 px-3 px-md-4">
    <!-- HEADER & FILTER -->
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-start align-items-lg-center mb-4 gap-3">
        <div>
            <span class="text-muted small fw-bold text-uppercase tracking-wider" style="font-size: 11px; letter-spacing: 1px; color:#94a3b8!important;">RINGKASAN BISNIS &bull; <?= strtoupper($nama_periode) ?></span>
            <h3 class="fw-extrabold mb-0 mt-1" style="color: #0f172a!important; font-size: 24px; letter-spacing: -0.5px; font-weight: 800;">
                Dashboard Pusat <?= $nama_filter? "<span style='color: #4318ff;'>• ".h($nama_filter)."</span>" : ""?>
            </h3>
        </div>
        
        <div class="d-flex flex-column flex-sm-row align-items-stretch align-items-sm-center gap-2 w-100 w-lg-auto">
            <form method="GET" class="d-flex flex-wrap align-items-center gap-2 flex-grow-1 flex-sm-grow-0">
                <select name="investor" class="form-select form-select-filter" onchange="this.form.submit()">
                    <option value="">Semua Investor</option>
                    <?php foreach($list_investor as $inv):?>
                    <option value="<?= $inv['id_investor']?>" <?= $filter_investor==$inv['id_investor']?'selected':''?>>
                        <?= h($inv['nama_investor'])?>
                    </option>
                    <?php endforeach;?>
                </select>

                <select name="bulan" class="form-select form-select-filter" style="min-width:auto;" onchange="this.form.submit()">
                    <?php for($m=1;$m<=12;$m++):?>
                    <option value="<?= $m ?>" <?= $sel_bulan==$m?'selected':''?>><?= date('F', mktime(0,0,0,$m,1)) ?></option>
                    <?php endfor;?>
                </select>

                <select name="tahun" class="form-select form-select-filter" style="min-width:auto;" onchange="this.form.submit()">
                    <?php for($y=(int)date('Y')+1; $y>=tahun_data_paling_lama($conn); $y--):?>
                    <option value="<?= $y ?>" <?= $sel_tahun==$y?'selected':''?>><?= $y ?></option>
                    <?php endfor;?>
                </select>

                <?php if($filter_investor):?>
                <a href="?bulan=<?= $sel_bulan ?>&tahun=<?= $sel_tahun ?>" class="btn btn-light d-flex align-items-center justify-content-center" style="border-radius: 12px; padding: 9px 14px; border: 1px solid #e2e8f0; background: #fff;" title="Reset Filter Investor">
                    <i class="bi bi-x-lg text-danger"></i>
                </a>
                <?php endif;?>
            </form>
        </div>
    </div>

    <!-- ROW KPI CARDS -->
    <div class="row g-3 mb-4">
        <div class="col-lg-3 col-md-6 col-12">
            <div class="kpi-premium-card" style="--kpi-glow: #4318ff; border-top: 3px solid #4318ff;">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="d-flex flex-column">
                        <span class="kpi-meta">Total Omzet</span>
                        <h4 class="kpi-value">Rp <?= number_format($kpi['omzet']?? 0,0,',','.')?></h4>
                        <span class="kpi-subvalue">Hari ini: <span class="fw-semibold text-dark">Rp <?= number_format($kpi_hari_ini['omzet']?? 0,0,',','.')?></span></span>
                    </div>
                    <div class="kpi-badge-icon" style="--badge-bg: rgba(67, 24, 255, 0.08); --badge-color: #4318ff;">
                        <i class="bi bi-graph-up-arrow"></i>
                    </div>
                </div>
                <div class="pt-3 mt-2 border-top d-flex align-items-center justify-content-between" style="border-color: #f1f5f9!important;">
                    <span class="<?= $naik_turun >= 0? 'badge-modern-success' : 'badge-modern-danger'?>">
                        <i class="bi bi-<?= $naik_turun >= 0? 'arrow-up-short' : 'arrow-down-short'?> fs-6 me-1"></i> 
                        <?= number_format(abs($naik_turun),1)?>%
                    </span>
                    <span class="text-muted small" style="font-size: 12px;">vs bulan lalu</span>
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
                    <div class="kpi-badge-icon" style="--badge-bg: rgba(14, 165, 233, 0.08); --badge-color: #0ea5e9;">
                        <i class="bi bi-wallet2"></i>
                    </div>
                </div>
                <div class="pt-3 mt-2 border-top" style="border-color: #f1f5f9!important;">
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
                    <div class="kpi-badge-icon" style="--badge-bg: rgba(16, 185, 129, 0.08); --badge-color: #10b981;">
                        <i class="bi bi-building-check"></i>
                    </div>
                </div>
                <div class="pt-3 mt-2">
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
                    <div class="kpi-badge-icon" style="--badge-bg: rgba(245, 158, 11, 0.08); --badge-color: #f59e0b;">
                        <i class="bi bi-shield-check"></i>
                    </div>
                </div>
                <div class="pt-3 mt-2 border-top" style="border-color: #f1f5f9!important; font-size: 12px; color: #64748b; font-weight: 500;">
                    <i class="bi bi-info-circle me-1 text-warning"></i> Estimasi bagi hasil pusat
                </div>
            </div>
        </div>
    </div>

    <!-- ROW GRAFIK & RANKING CABANG (BERJEJERAN) -->
    <div class="row g-3 mb-4">
        <!-- GRAFIK -->
        <div class="col-lg-8 col-12">
            <div class="saas-card h-100">
                <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
                    <div>
                        <h6 class="fw-bold mb-0" style="color: #0f172a; font-size: 16px;">Trend Performa <?= h($label_periode_tren) ?></h6>
                        <span class="text-muted small">Perbandingan Grafik Omzet vs Laba</span>
                    </div>
                    <select class="form-select form-select-sm" style="width:auto;" onchange="gantiTren(this.value)">
                        <option value="harian" <?= $granularitas_tren === 'harian' ? 'selected' : '' ?>>Harian</option>
                        <option value="mingguan" <?= $granularitas_tren === 'mingguan' ? 'selected' : '' ?>>Mingguan</option>
                        <option value="bulanan" <?= $granularitas_tren === 'bulanan' ? 'selected' : '' ?>>Bulanan</option>
                        <option value="tahunan" <?= $granularitas_tren === 'tahunan' ? 'selected' : '' ?>>Tahunan</option>
                    </select>
                </div>
                <div style="position: relative; height: 300px; width: 100%;">
                    <canvas id="grafikTrend"></canvas>
                </div>
            </div>
        </div>

        <!-- RANKING CABANG -->
        <div class="col-lg-4 col-12">
            <div class="kpi-card h-100">
                <div class="kpi-header">
                    <div class="kpi-icon" style="background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%);">
                        <i class="bi bi-trophy-fill"></i>
                    </div>
                    <div>
                        <div class="kpi-label">Ranking Cabang</div>
                        <div style="font-size: 12px; color: #64748b;">Urut Omzet Tertinggi</div>
                    </div>
                </div>
                
                <div style="max-height: 290px; overflow-y: auto; margin-top: 15px; padding-right: 5px;">
                    <table class="table table-sm align-middle" style="font-size: 13px; margin-bottom: 0;">
                        <thead style="position: sticky; top: 0; background: #fff; z-index: 1;">
                            <tr style="border-bottom: 2px solid #e2e8f0;">
                                <th style="width: 35px; color: #64748b; font-weight: 700;">#</th>
                                <th style="color: #64748b; font-weight: 700;">Cabang</th>
                                <th class="text-end" style="color: #64748b; font-weight: 700;">Omzet</th>
                                <th class="text-end" style="color: #64748b; font-weight: 700;">Net Profit</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(empty($ranking_cabang)): ?>
                            <tr>
                                <td colspan="4" class="text-center text-muted py-3">Belum ada data</td>
                            </tr>
                            <?php else: ?>
                            <?php foreach($ranking_cabang as $rank): ?>
                            <tr style="border-bottom: 1px solid #f1f5f9;">
                                <td>
                                    <?php if($rank['no'] == 1): ?>
                                        <span class="badge rounded-pill bg-warning text-dark px-2">1</span>
                                    <?php elseif($rank['no'] == 2): ?>
                                        <span class="badge rounded-pill bg-secondary px-2">2</span>
                                    <?php elseif($rank['no'] == 3): ?>
                                        <span class="badge rounded-pill px-2" style="background: #cd7f32; color: #fff;">3</span>
                                    <?php else: ?>
                                        <span class="fw-bold text-muted ps-1"><?= $rank['no'] ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="font-weight: 700; color: #0f172a;"><?= $rank['nama_cabang'] ?></div>
                                    <div style="font-size: 11px; color: #64748b;"><?= $rank['nama_pengelola'] ?></div>
                                </td>
                                <td class="text-end" style="font-weight: 700; color: #0ea5e9;">
                                    Rp <?= number_format($rank['total_omset'],0,',','.')?>
                                </td>
                                <td class="text-end" style="font-weight: 700; color: <?= $rank['total_net_profit'] >= 0 ? '#10b981' : '#ef4444' ?>;">
                                    Rp <?= number_format($rank['total_net_profit'],0,',','.')?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- TABEL PERINGATAN DINI (DENGAN SCROLL SAMPING STANDARD) -->
    <div class="saas-card p-0 overflow-hidden mb-4">
        <div class="px-4 pt-4 pb-3 border-bottom" style="border-color: #f1f5f9!important;">
            <h6 class="fw-bold mb-0" style="color: #0f172a; font-size: 16px;">🚨 Peringatan Dini Operasional</h6>
            <p class="text-muted small mb-0 mt-1">Daftar cabang yang belum mengirimkan laporan untuk tanggal <?= date('d M Y', strtotime($kemarin)) ?><?= $nama_filter ? ' &bull; Investor: ' . h($nama_filter) : '' ?>.</p>
        </div>

        <div class="p-0">
            <div class="table-responsive">
                <table class="table table-modern align-middle mb-0">
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
                                    <i class="bi bi-shield-check fs-4"></i> Luar Biasa! Semua cabang sudah mengirim laporan untuk tanggal <?= date('d M Y', strtotime($kemarin)) ?>.
                                </div>
                            </td>
                        </tr>
                        <?php else: while($p=$peringatan->fetch_assoc()):
                            // Pengelola yang MENJABAT SEKARANG (bukan kolom statis cabang.nama_pengelola
                            // yang tidak pernah ikut ter-update) — ini widget kontak operasional hari ini.
                            $p['nama_pengelola'] = pengelola_pada_tanggal($conn, (int) $p['id_cabang'], date('Y-m-d'));
                            $hari_telat = $p['selisih_hari']?? 0;
                            
                            if ($hari_telat == 0 || $p['input_terakhir'] == NULL) {
                                $status_badge = '<span class="badge-modern-danger"><i class="bi bi-exclamation-circle-fill me-1"></i> Terlambat</span>';
                                $masalah_text = '<span class="text-danger fw-semibold">Belum mengirim laporan hari ini</span>';
                                $txt_terakhir = '<span class="badge bg-light text-secondary border fw-bold" style="font-size:11px; padding:4px 8px; border-radius:6px;">Belum Pernah</span>';
                            } else {
                                $status_badge = '<span class="badge-modern-warning"><i class="bi bi-clock-history me-1"></i> Peringatan</span>';
                                $masalah_text = '<span class="fw-semibold" style="color: #d97706;">Menunggak laporan selama ' . $hari_telat . ' hari</span>';
                                $txt_terakhir = '<span class="fw-semibold text-secondary">' . date('d M Y', strtotime($p['input_terakhir'])) . '</span>';
                            }
                        ?>
                        <tr>
                            <td><?= $status_badge?></td>
                            <td><span class="fw-bold" style="color: #0f172a;"><?= h($p['nama_cabang'])?></span></td>
                            <td>
                                <div class="fw-semibold text-secondary" style="font-size: 13.5px;">
                                    <i class="bi bi-person-badge me-1 text-primary" style="color: #4318ff!important;"></i>
                                    <?= h($p['nama_pengelola']?? 'Belum Diatur')?>
                                </div>
                            </td>
                            <td><?= $masalah_text?></td>
                            <td class="text-muted fw-medium"><?= $txt_terakhir?></td>
                        </tr>
                        <?php endwhile; endif;?>
                    </tbody>
                </table>
            </div>

            <?php render_pagination($page_peringatan, $total_pages_peringatan, ['from' => $offset_peringatan + 1, 'to' => min($offset_peringatan + $limit_peringatan, $total_peringatan), 'total' => $total_peringatan, 'label' => 'cabang'], 'page_peringatan'); ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
function gantiTren(val) {
    const url = new URL(window.location.href);
    url.searchParams.set('tren', val);
    window.location.href = url.toString();
}

const ctx = document.getElementById('grafikTrend').getContext('2d');

const gradOmzet = ctx.createLinearGradient(0, 0, 0, 280);
gradOmzet.addColorStop(0, 'rgba(67, 24, 255, 0.15)');
gradOmzet.addColorStop(1, 'rgba(67, 24, 255, 0.0)');

const gradLaba = ctx.createLinearGradient(0, 0, 0, 280);
gradLaba.addColorStop(0, 'rgba(14, 165, 233, 0.15)');
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
            borderWidth: 3,
            tension: 0.4,
            fill: true,
            pointRadius: 2,
            pointHoverRadius: 6,
            pointHoverBackgroundColor: '#4318ff',
            pointHoverBorderColor: '#fff',
            pointHoverBorderWidth: 2
        }, {
            label: 'Laba Bersih',
            data: <?= json_encode($data_laba)?>,
            borderColor: '#0ea5e9',
            backgroundColor: gradLaba,
            borderWidth: 3,
            tension: 0.4,
            fill: true,
            pointRadius: 2,
            pointHoverRadius: 6,
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
                    boxWidth: 8,
                    boxHeight: 8,
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
                        if (context.parsed.y !== null) {
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