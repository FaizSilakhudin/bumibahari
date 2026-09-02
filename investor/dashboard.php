<?php
require '../config/koneksi.php';
include 'sidebar.php';

$id_investor = (int) ($_SESSION['id_investor'] ?? 0);

$stmt = $conn->prepare("SELECT nama_investor FROM investor WHERE id_investor = ?");
$stmt->bind_param("i", $id_investor);
$stmt->execute();
$nama_investor = $stmt->get_result()->fetch_assoc()['nama_investor'] ?? current_username();
$stmt->close();

$cabang_ids = investor_cabang_ids($conn, $id_investor);

// =====================================================================
// FILTER PERIODE
// =====================================================================
$sel_tahun = (int) ($_GET['tahun'] ?? date('Y'));
$sel_bulan = (int) ($_GET['bulan'] ?? date('m'));
if ($sel_tahun < tahun_data_paling_lama($conn) || $sel_tahun > ((int) date('Y') + 1)) $sel_tahun = (int) date('Y');
if ($sel_bulan < 1 || $sel_bulan > 12) $sel_bulan = (int) date('m');

$periode_ini  = sprintf('%04d-%02d', $sel_tahun, $sel_bulan);
$periode_lalu = date('Y-m', strtotime("$periode_ini-01 -1 month"));
$nama_periode = date('F Y', strtotime("$periode_ini-01"));
$tgl_awal     = date("$sel_tahun-$sel_bulan-01");
$tgl_akhir    = date('Y-m-t', strtotime($tgl_awal));
$kemarin      = date('Y-m-d', strtotime('-1 day'));

$granularitas_tren = $_GET['tren'] ?? 'bulanan';
if (!in_array($granularitas_tren, ['harian', 'mingguan', 'bulanan', 'tahunan'], true)) {
    $granularitas_tren = 'bulanan';
}
$label_periode_tren = [
    'harian'   => '30 Hari Terakhir',
    'mingguan' => '12 Minggu Terakhir',
    'bulanan'  => '6 Bulan Terakhir',
    'tahunan'  => '5 Tahun Terakhir',
][$granularitas_tren];

$kpi = ['omzet' => 0, 'laba' => 0, 'margin' => 0];
$kpi_hari_ini = ['omzet' => 0, 'laba' => 0];
$kpi_lalu = ['omzet' => 0];
$cabang_aktif = 0;
$ranking_cabang = [];
$label_grafik = $data_omzet = $data_laba = [];
$laporan_list = [];
$total_laporan = 0;

if (!empty($cabang_ids)) {
    $ph = implode(',', array_fill(0, count($cabang_ids), '?'));

    // 1. KPI periode terpilih
    $st = $conn->prepare("SELECT COALESCE(SUM(l.total_omset),0) omzet, COALESCE(SUM(l.net_profit),0) laba,
                                  COALESCE(AVG(l.persentase),0) margin
                           FROM laporan_cabang l
                           WHERE l.status_laporan = 'lengkap' AND DATE_FORMAT(l.tanggal,'%Y-%m') = ? AND l.id_cabang IN ($ph)");
    $st->bind_param('s' . str_repeat('i', count($cabang_ids)), $periode_ini, ...$cabang_ids);
    $st->execute();
    $kpi = $st->get_result()->fetch_assoc();

    // 1.1 KPI kemarin
    $st = $conn->prepare("SELECT COALESCE(SUM(l.total_omset),0) omzet, COALESCE(SUM(l.net_profit),0) laba
                           FROM laporan_cabang l
                           WHERE l.status_laporan = 'lengkap' AND l.tanggal = ? AND l.id_cabang IN ($ph)");
    $st->bind_param('s' . str_repeat('i', count($cabang_ids)), $kemarin, ...$cabang_ids);
    $st->execute();
    $kpi_hari_ini = $st->get_result()->fetch_assoc();

    // 2. KPI bulan sebelumnya (perbandingan)
    $st = $conn->prepare("SELECT COALESCE(SUM(l.total_omset),0) omzet
                           FROM laporan_cabang l
                           WHERE l.status_laporan = 'lengkap' AND DATE_FORMAT(l.tanggal,'%Y-%m') = ? AND l.id_cabang IN ($ph)");
    $st->bind_param('s' . str_repeat('i', count($cabang_ids)), $periode_lalu, ...$cabang_ids);
    $st->execute();
    $kpi_lalu = $st->get_result()->fetch_assoc();
    $naik_turun = $kpi_lalu['omzet'] > 0 ? (($kpi['omzet'] - $kpi_lalu['omzet']) / $kpi_lalu['omzet']) * 100 : 0;

    // 3. Cabang aktif (sudah lapor lengkap) periode ini
    $st = $conn->prepare("SELECT COUNT(DISTINCT l.id_cabang) total
                           FROM laporan_cabang l
                           WHERE l.status_laporan = 'lengkap' AND DATE_FORMAT(l.tanggal,'%Y-%m') = ? AND l.id_cabang IN ($ph)");
    $st->bind_param('s' . str_repeat('i', count($cabang_ids)), $periode_ini, ...$cabang_ids);
    $st->execute();
    $cabang_aktif = (int) $st->get_result()->fetch_assoc()['total'];

    // 4. Grafik trend — granularitas bisa dipilih (harian/mingguan/bulanan/tahunan)
    $where_filter_tren = "AND l.status_laporan = 'lengkap' AND l.id_cabang IN ($ph)";
    $g_end = anchor_periode($tgl_akhir);
    $tren  = ambil_tren_performa($conn, $granularitas_tren, $g_end, $where_filter_tren, $cabang_ids, str_repeat('i', count($cabang_ids)));
    $label_grafik = $tren['label'];
    $data_omzet   = $tren['omzet'];
    $data_laba    = $tren['laba'];

    // 5. Ranking cabang periode ini
    $st = $conn->prepare("SELECT c.id_cabang, c.nama_cabang, c.nama_pengelola,
                                  COALESCE(SUM(l.total_omset),0) total_omset,
                                  COALESCE(SUM(l.net_profit),0) total_net_profit
                           FROM cabang c
                           LEFT JOIN laporan_cabang l ON l.id_cabang = c.id_cabang
                                  AND DATE_FORMAT(l.tanggal,'%Y-%m') = ? AND l.status_laporan = 'lengkap'
                           WHERE c.id_cabang IN ($ph)
                           GROUP BY c.id_cabang ORDER BY total_omset DESC");
    $st->bind_param('s' . str_repeat('i', count($cabang_ids)), $periode_ini, ...$cabang_ids);
    $st->execute();
    $res_rank = $st->get_result();
    $no = 1;
    while ($row = $res_rank->fetch_assoc()) {
        $row['no'] = $no++;
        // Pengelola PADA PERIODE terpilih, bukan kolom statis cabang.nama_pengelola.
        $row['nama_pengelola'] = pengelola_pada_tanggal($conn, (int) $row['id_cabang'], anchor_periode($tgl_akhir));
        $ranking_cabang[] = $row;
    }

    // 6. Daftar laporan yang sudah diinput PIC (pengganti "peringatan dini") — dipaginasi
    $limit_laporan  = 10;
    $page_laporan   = max(1, (int) ($_GET['page_laporan'] ?? 1));
    $offset_laporan = ($page_laporan - 1) * $limit_laporan;

    $st = $conn->prepare("SELECT COUNT(*) total FROM laporan_cabang l
                           WHERE l.status_laporan = 'lengkap' AND l.tanggal BETWEEN ? AND ? AND l.id_cabang IN ($ph)");
    $st->bind_param('ss' . str_repeat('i', count($cabang_ids)), $tgl_awal, $tgl_akhir, ...$cabang_ids);
    $st->execute();
    $total_laporan = (int) $st->get_result()->fetch_assoc()['total'];
    $total_pages_laporan = max(1, (int) ceil($total_laporan / $limit_laporan));

    $st = $conn->prepare("SELECT l.*, c.nama_cabang, u.username AS nama_pic
                           FROM laporan_cabang l
                           JOIN cabang c ON c.id_cabang = l.id_cabang
                           LEFT JOIN users u ON u.id = l.id_user_laporan
                           WHERE l.status_laporan = 'lengkap' AND l.tanggal BETWEEN ? AND ? AND l.id_cabang IN ($ph)
                           ORDER BY l.tanggal DESC, c.nama_cabang ASC
                           LIMIT ? OFFSET ?");
    $st->bind_param('ss' . str_repeat('i', count($cabang_ids)) . 'ii', $tgl_awal, $tgl_akhir, ...array_merge($cabang_ids, [$limit_laporan, $offset_laporan]));
    $st->execute();
    $laporan_list = $st->get_result()->fetch_all(MYSQLI_ASSOC);
} else {
    $total_pages_laporan = 1;
    $page_laporan = 1;
    $limit_laporan = 10;
    $offset_laporan = 0;
}
?>

<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<style>
    :root { --inv-primary: #7e22ce; --inv-primary-2: #a855f7; --inv-glow: rgba(126,34,206,.08); --inv-gold: #d4af37; }
    body { background-color: #faf7ff !important; font-family: 'Plus Jakarta Sans', sans-serif !important; color: #1e1b2e; }

    .inv-hero {
        position: relative; overflow: hidden; border-radius: 24px; padding: 32px 30px;
        background: linear-gradient(135deg, #4a1d5c 0%, #2e0f3a 100%);
        box-shadow: 0 20px 40px -12px rgba(74,29,92,.45);
        color: #fff;
    }
    .inv-hero::before {
        content: ""; position: absolute; top: -60px; right: -60px; width: 260px; height: 260px;
        border-radius: 50%; background: radial-gradient(circle, rgba(212,175,55,.18) 0%, rgba(212,175,55,0) 70%);
    }
    .inv-hero::after {
        content: ""; position: absolute; bottom: -80px; left: -40px; width: 220px; height: 220px;
        border-radius: 50%; background: radial-gradient(circle, rgba(168,85,247,.18) 0%, rgba(168,85,247,0) 70%);
    }
    .inv-hero-avatar {
        width: 56px; height: 56px; border-radius: 16px; flex-shrink: 0;
        background: linear-gradient(135deg, var(--inv-gold) 0%, #b8860b 100%);
        display: flex; align-items: center; justify-content: center;
        font-weight: 800; font-size: 22px; color: #2e0f3a;
        box-shadow: 0 8px 20px rgba(212,175,55,.35);
    }
    .inv-hero-badge {
        display: inline-flex; align-items: center; gap: 6px; font-size: 11px; font-weight: 700;
        letter-spacing: 1px; text-transform: uppercase; color: #e9d5ff;
        background: rgba(255,255,255,.08); border: 1px solid rgba(255,255,255,.14);
        padding: 5px 12px; border-radius: 20px;
    }
    .inv-hero-since {
        font-size: 12px; color: rgba(255,255,255,.65); font-weight: 500; margin-top: 6px;
    }

    .saas-card { background: #fff; border: 1px solid #f3e8ff !important; border-radius: 18px !important; box-shadow: 0 4px 6px -1px rgb(0 0 0 / .02), 0 10px 15px -3px rgb(0 0 0 / .01) !important; padding: 24px; transition: all .25s ease; }
    .saas-card:hover { transform: translateY(-2px); box-shadow: 0 20px 25px -5px rgb(0 0 0 / .05) !important; }

    .kpi-premium-card { background: #fff; border: 1px solid #f3e8ff; border-radius: 18px; padding: 22px; position: relative; overflow: hidden; min-height: 150px; display: flex; flex-direction: column; justify-content: space-between; box-shadow: 0 4px 6px -1px rgb(0 0 0 / .02); transition: all .25s ease; }
    .kpi-premium-card:hover { transform: translateY(-4px); box-shadow: 0 16px 28px -10px rgba(126,34,206,.15); }
    .kpi-badge-icon { width: 46px; height: 46px; border-radius: 13px; display: inline-flex; align-items: center; justify-content: center; font-size: 20px; background: var(--badge-bg); color: #fff; box-shadow: 0 6px 14px var(--badge-shadow, rgba(0,0,0,.12)); }
    .rank-avatar { width: 34px; height: 34px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 800; color: #fff; background: linear-gradient(135deg, var(--inv-primary) 0%, var(--inv-primary-2) 100%); flex-shrink: 0; }
    .kpi-meta { font-size: 11px; font-weight: 700; color: #8b7aa0; text-transform: uppercase; letter-spacing: 1px; }
    .kpi-value { font-size: 22px; font-weight: 800; color: #1e1b2e; letter-spacing: -.5px; margin-top: 4px; }
    .kpi-subvalue { font-size: 12px; font-weight: 500; color: #8b7aa0; margin-top: 4px; }

    .badge-modern-success, .badge-modern-danger, .badge-modern-warning { padding: 5px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; display: inline-flex; align-items: center; gap: 4px; }
    .badge-modern-success { background: #f0fdf4; color: #16a34a; border: 1px solid #dcfce7; }
    .badge-modern-danger { background: #fef2f2; color: #dc2626; border: 1px solid #fee2e2; }
    .badge-modern-warning { background: #fffbeb; color: #d97706; border: 1px solid #fde68a; }

    .form-select-filter { border-radius: 12px !important; border: 1px solid #e9d5ff !important; font-size: 14px; font-weight: 600; color: #4c1d95; padding: 9px 16px; background: #fff; }
    .form-select-filter:focus { border-color: var(--inv-primary) !important; box-shadow: 0 0 0 4px rgba(126,34,206,.12) !important; }

    .kpi-card { background: #fff; border: 1px solid #f3e8ff; border-radius: 16px; padding: 20px; box-shadow: 0 4px 20px rgba(0,0,0,.03); }
    .kpi-header { display: flex; align-items: center; gap: 12px; }
    .kpi-icon { width: 42px; height: 42px; border-radius: 12px; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 18px; background: linear-gradient(135deg, var(--inv-primary) 0%, #a855f7 100%); box-shadow: 0 4px 12px rgba(126,34,206,.3); }
    .kpi-label { font-size: 16px; font-weight: 700; color: #1e1b2e; }
    .rank-box { width: 26px; height: 26px; background: #f5f0fb; color: #6b21a8; border-radius: 6px; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; }

    .table-modern thead th, .table-saas thead th { background: #faf5ff; color: #8b7aa0; font-size: 11px; text-transform: uppercase; letter-spacing: .5px; font-weight: 700; padding: 14px 16px; border-bottom: 1px solid #f3e8ff; white-space: nowrap; }
    .table-modern tbody td, .table-saas tbody td { padding: 14px 16px; border-bottom: 1px solid #faf5ff; font-size: 13.5px; color: #2b2140; white-space: nowrap; }

    .btn-nav-bulan { background: #fff; border: 1px solid #e9d5ff; color: #7e22ce; font-weight: 600; border-radius: 10px; padding: 8px 16px; white-space: nowrap; }
    .btn-nav-bulan:hover { background: #f3e8ff; }

    @media (max-width: 767.98px) {
        .table-modern thead, .table-saas thead { display: none; }
        .table-modern tbody tr, .table-saas tbody tr { display: block; border: 1px solid #f3e8ff; border-radius: 14px; margin: 12px; padding: 12px; background: #fff; }
        .table-modern tbody td, .table-saas tbody td { display: flex; justify-content: space-between; align-items: center; padding: 10px 0 !important; border-bottom: 1px dashed #f3e8ff !important; text-align: right; white-space: normal; }
        .table-modern tbody td::before, .table-saas tbody td::before { content: attr(data-label); font-weight: 700; color: #a78bc4; font-size: 11px; text-transform: uppercase; text-align: left; }
    }

    /* Modal Detail Laporan (read-only) */
    .inv-detail-content { border: 0; border-radius: 14px; overflow: hidden; }
    .inv-detail-head { background: linear-gradient(135deg, #4a1d5c 0%, #2e0f3a 100%); color: #fff; padding: 14px 18px; align-items: flex-start; }
    .inv-detail-head .modal-title { color: #fff; }
    .inv-detail-head-sub { display: flex; flex-wrap: wrap; gap: 3px 14px; font-size: .78rem; color: #e9d5ff; }
    .inv-detail-body { background: #faf7ff; padding: 16px; }
    .inv-detail-sec { background: #fff; border: 1px solid #f3e8ff; border-radius: 12px; padding: 14px; }
    .inv-detail-sec-h { font-weight: 700; font-size: .78rem; letter-spacing: .02em; text-transform: uppercase; color: #4c1d95; margin-bottom: 8px; display: flex; align-items: center; gap: 8px; }
    .inv-detail-badge { margin-left: auto; font-weight: 600; font-size: .68rem; background: #f3e8ff; color: #6b21a8; padding: 2px 8px; border-radius: 999px; text-transform: none; letter-spacing: 0; }
    .inv-detail-row { display: flex; justify-content: space-between; align-items: center; padding: 6px 2px; border-bottom: 1px dashed #f3e8ff; font-size: .85rem; }
    .inv-detail-row:last-child { border-bottom: none; }
    .inv-detail-row span { color: #64748b; } .inv-detail-row b { color: #1e1b2e; font-weight: 700; }
    .inv-detail-hi { background: #faf5ff; margin: 0 -14px; padding: 8px 14px; }
    .inv-detail-hi b { color: #7e22ce; }
    .inv-detail-notas { display: flex; flex-direction: column; gap: 10px; max-height: 340px; overflow-y: auto; padding-right: 2px; }
    .inv-detail-nota-img { width: 100%; height: auto; max-height: 260px; object-fit: contain; background: #0f172a; border-radius: 10px; border: 1px solid #e2e8f0; cursor: zoom-in; display: block; }
    .inv-detail-foot { background: #fff; border-top: 1px solid #f3e8ff; padding: 12px 16px; }
</style>

<div class="container-fluid py-4 px-3 px-md-4">
    <div class="inv-hero mb-4">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-start align-items-lg-center gap-3" style="position: relative; z-index: 1;">
            <div class="d-flex align-items-center gap-3">
                <div class="inv-hero-avatar"><?= h(mb_strtoupper(mb_substr($nama_investor, 0, 1))) ?></div>
                <div>
                    <span class="inv-hero-badge"><i class="bi bi-gem"></i> Portal Investor</span>
                    <h3 class="fw-extrabold mb-0 mt-2 text-white" style="font-size: 24px; letter-spacing: -.5px;">
                        Selamat datang, <?= h($nama_investor) ?>
                    </h3>
                    <div class="inv-hero-since"><i class="bi bi-calendar-week me-1"></i> Periode <?= h($nama_periode) ?></div>
                </div>
            </div>
            <form method="GET" class="d-flex flex-wrap align-items-center gap-2">
                <select name="bulan" class="form-select form-select-filter" style="min-width:auto;" onchange="this.form.submit()">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= $m ?>" <?= $sel_bulan == $m ? 'selected' : '' ?>><?= date('F', mktime(0, 0, 0, $m, 1)) ?></option>
                    <?php endfor; ?>
                </select>
                <select name="tahun" class="form-select form-select-filter" style="min-width:auto;" onchange="this.form.submit()">
                    <?php for ($y = (int) date('Y') + 1; $y >= tahun_data_paling_lama($conn); $y--): ?>
                        <option value="<?= $y ?>" <?= $sel_tahun == $y ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </form>
        </div>
    </div>

    <?php if (empty($cabang_ids)): ?>
        <div class="card saas-card text-center py-5 text-muted">
            <i class="bi bi-inbox fs-1 d-block mb-2 opacity-50"></i>
            Anda belum terhubung ke cabang manapun. Hubungi Admin Pusat.
        </div>
    <?php else: ?>

    <div class="row g-3 mb-4">
        <div class="col-lg-3 col-md-6 col-12">
            <div class="kpi-premium-card" style="border-top: 3px solid var(--inv-primary);">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="d-flex flex-column">
                        <span class="kpi-meta">Total Omzet</span>
                        <h4 class="kpi-value kpi-countup" data-value="<?= (float) ($kpi['omzet'] ?? 0) ?>">Rp 0</h4>
                        <span class="kpi-subvalue">Kemarin: <span class="fw-semibold text-dark">Rp <?= number_format($kpi_hari_ini['omzet'] ?? 0, 0, ',', '.') ?></span></span>
                    </div>
                    <div class="kpi-badge-icon" style="--badge-bg: linear-gradient(135deg, var(--inv-primary) 0%, var(--inv-primary-2) 100%); --badge-shadow: rgba(126,34,206,.35);"><i class="bi bi-graph-up-arrow"></i></div>
                </div>
                <div class="pt-3 mt-2 border-top d-flex align-items-center justify-content-between" style="border-color: #f5f0fb !important;">
                    <span class="<?= $naik_turun >= 0 ? 'badge-modern-success' : 'badge-modern-danger' ?>">
                        <i class="bi bi-<?= $naik_turun >= 0 ? 'arrow-up-short' : 'arrow-down-short' ?>"></i> <?= number_format(abs($naik_turun), 1) ?>%
                    </span>
                    <span class="text-muted small" style="font-size: 12px;">vs bulan lalu</span>
                </div>
            </div>
        </div>

        <div class="col-lg-3 col-md-6 col-12">
            <div class="kpi-premium-card" style="border-top: 3px solid #a855f7;">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="d-flex flex-column">
                        <span class="kpi-meta">Net Profit</span>
                        <h4 class="kpi-value kpi-countup" data-value="<?= (float) ($kpi['laba'] ?? 0) ?>">Rp 0</h4>
                        <span class="kpi-subvalue">Kemarin: <span class="fw-semibold text-dark">Rp <?= number_format($kpi_hari_ini['laba'] ?? 0, 0, ',', '.') ?></span></span>
                    </div>
                    <div class="kpi-badge-icon" style="--badge-bg: linear-gradient(135deg, #a855f7 0%, #d946ef 100%); --badge-shadow: rgba(168,85,247,.35);"><i class="bi bi-wallet2"></i></div>
                </div>
                <div class="pt-3 mt-2 border-top" style="border-color: #f5f0fb !important;">
                    <span class="badge-modern-success" style="background:#faf5ff;color:#a855f7;border-color:#f3e8ff;"><i class="bi bi-pie-chart-fill me-1"></i> Margin <?= number_format($kpi['margin'] ?? 0, 2) ?>%</span>
                </div>
            </div>
        </div>

        <div class="col-lg-3 col-md-6 col-12">
            <div class="kpi-premium-card" style="border-top: 3px solid #d97706;">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="d-flex flex-column">
                        <span class="kpi-meta">Cabang Sudah Lapor</span>
                        <h4 class="kpi-value"><?= $cabang_aktif ?> <span style="font-size: 14px; color:#a78bc4; font-weight: 500;">/ <?= count($cabang_ids) ?> Unit</span></h4>
                    </div>
                    <div class="kpi-badge-icon" style="--badge-bg: linear-gradient(135deg, #d97706 0%, #f59e0b 100%); --badge-shadow: rgba(217,119,6,.35);"><i class="bi bi-building-check"></i></div>
                </div>
                <div class="pt-3 mt-2">
                    <div class="progress" style="height: 6px; border-radius: 10px; background: #f5f0fb;">
                        <div class="progress-bar" style="width: <?= count($cabang_ids) > 0 ? ($cabang_aktif / count($cabang_ids)) * 100 : 0 ?>%; background: linear-gradient(90deg, #d97706, #f59e0b); border-radius: 10px;"></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-3 col-md-6 col-12">
            <div class="kpi-premium-card" style="border-top: 3px solid #16a34a;">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="d-flex flex-column">
                        <span class="kpi-meta">Laporan Final Periode Ini</span>
                        <h4 class="kpi-value"><?= $total_laporan ?> <span style="font-size: 14px; color:#a78bc4; font-weight: 500;">laporan</span></h4>
                    </div>
                    <div class="kpi-badge-icon" style="--badge-bg: linear-gradient(135deg, #16a34a 0%, #22c55e 100%); --badge-shadow: rgba(22,163,74,.35);"><i class="bi bi-file-earmark-check"></i></div>
                </div>
                <div class="pt-3 mt-2 border-top" style="border-color: #f5f0fb !important; font-size: 12px; color: #8b7aa0; font-weight: 500;">
                    <i class="bi bi-info-circle me-1 text-success"></i> Sudah diinput &amp; diverifikasi PIC
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-8 col-12">
            <div class="saas-card h-100">
                <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
                    <div>
                        <h6 class="fw-bold mb-0" style="color: #1e1b2e; font-size: 16px;">Trend Performa <?= h($label_periode_tren) ?></h6>
                        <span class="text-muted small">Omzet vs Net Profit &mdash; cabang Anda</span>
                    </div>
                    <select class="form-select form-select-filter" style="width:auto; min-width:auto;" onchange="gantiTren(this.value)">
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

        <div class="col-lg-4 col-12">
            <div class="kpi-card h-100">
                <div class="kpi-header">
                    <div class="kpi-icon"><i class="bi bi-trophy-fill"></i></div>
                    <div>
                        <div class="kpi-label">Ranking Cabang</div>
                        <div style="font-size: 12px; color: #8b7aa0;">Urut Omzet Tertinggi &mdash; <?= h($nama_periode) ?></div>
                    </div>
                </div>
                <div style="max-height: 290px; overflow-y: auto; margin-top: 15px; padding-right: 5px;">
                    <table class="table table-sm align-middle" style="font-size: 13px; margin-bottom: 0;">
                        <thead style="position: sticky; top: 0; background: #fff; z-index: 1;">
                            <tr style="border-bottom: 2px solid #f3e8ff;">
                                <th style="width: 35px; color: #8b7aa0; font-weight: 700;">#</th>
                                <th style="color: #8b7aa0; font-weight: 700;">Cabang</th>
                                <th class="text-end" style="color: #8b7aa0; font-weight: 700;">Omzet</th>
                                <th class="text-end" style="color: #8b7aa0; font-weight: 700;">Net Profit</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($ranking_cabang)): ?>
                            <tr><td colspan="4" class="text-center text-muted py-3">Belum ada data</td></tr>
                        <?php else: foreach ($ranking_cabang as $rank): ?>
                            <tr style="border-bottom: 1px solid #faf5ff;">
                                <td>
                                    <?php if ($rank['no'] == 1): ?><span class="badge rounded-pill bg-warning text-dark px-2">1</span>
                                    <?php elseif ($rank['no'] == 2): ?><span class="badge rounded-pill bg-secondary px-2">2</span>
                                    <?php elseif ($rank['no'] == 3): ?><span class="badge rounded-pill px-2" style="background:#cd7f32;color:#fff;">3</span>
                                    <?php else: ?><span class="fw-bold text-muted ps-1"><?= $rank['no'] ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="rank-avatar"><?= h(mb_strtoupper(mb_substr($rank['nama_cabang'], 0, 2))) ?></div>
                                        <div>
                                            <div style="font-weight: 700; color: #1e1b2e;"><?= h($rank['nama_cabang']) ?></div>
                                            <div style="font-size: 11px; color: #8b7aa0;"><?= h($rank['nama_pengelola'] ?? '-') ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-end" style="font-weight: 700; color: var(--inv-primary);">Rp <?= number_format($rank['total_omset'], 0, ',', '.') ?></td>
                                <td class="text-end" style="font-weight: 700; color: <?= $rank['total_net_profit'] >= 0 ? '#16a34a' : '#dc2626' ?>;">Rp <?= number_format($rank['total_net_profit'], 0, ',', '.') ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- LAPORAN CABANG YANG SUDAH DIINPUT PIC -->
    <div class="saas-card p-0 overflow-hidden mb-4">
        <div class="px-4 pt-4 pb-3 border-bottom" style="border-color: #f5f0fb !important;">
            <h6 class="fw-bold mb-0" style="color: #1e1b2e; font-size: 16px;"><i class="bi bi-file-earmark-check-fill text-success me-1"></i> Laporan Cabang Sudah Diinput PIC</h6>
            <p class="text-muted small mb-0 mt-1">Laporan harian yang sudah diverifikasi &amp; difinalisasi PIC pada <?= h($nama_periode) ?>.</p>
        </div>

        <div class="table-responsive">
            <table class="table table-modern align-middle mb-0">
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Cabang</th>
                        <th>Omzet</th>
                        <th>Total Pengeluaran</th>
                        <th>Net Profit</th>
                        <th>Margin</th>
                        <th>Diinput Oleh (PIC)</th>
                        <th class="text-center">Detail</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($laporan_list)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-5"><i class="bi bi-inbox fs-2 d-block mb-2 opacity-50"></i>Belum ada laporan final pada periode ini.</td></tr>
                <?php else: foreach ($laporan_list as $r): ?>
                    <tr>
                        <td data-label="Tanggal" class="fw-semibold"><?= date('d M Y', strtotime($r['tanggal'])) ?></td>
                        <td data-label="Cabang" class="fw-bold" style="color:#1e1b2e;"><?= h($r['nama_cabang']) ?></td>
                        <td data-label="Omzet" class="fw-semibold" style="color: var(--inv-primary);">Rp <?= number_format($r['total_omset'], 0, ',', '.') ?></td>
                        <td data-label="Total Pengeluaran">Rp <?= number_format($r['total_pengeluaran'], 0, ',', '.') ?></td>
                        <td data-label="Net Profit" class="fw-bold" style="color:#16a34a;">Rp <?= number_format($r['net_profit'], 0, ',', '.') ?></td>
                        <td data-label="Margin"><span class="badge badge-modern-success"><?= number_format($r['persentase'], 2) ?>%</span></td>
                        <td data-label="Diinput Oleh"><i class="bi bi-person-badge me-1 text-muted"></i><?= h($r['nama_pic'] ?? 'Pusat') ?></td>
                        <td data-label="Detail" class="text-center">
                            <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#invDetail<?= (int) $r['id'] ?>">
                                <i class="bi bi-eye me-1"></i> Detail
                            </button>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <div class="px-4">
            <?php render_pagination($page_laporan, $total_pages_laporan, ['from' => $offset_laporan + 1, 'to' => min($offset_laporan + $limit_laporan, $total_laporan), 'total' => $total_laporan, 'label' => 'laporan'], 'page_laporan'); ?>
        </div>
    </div>

    <!-- ============ MODAL DETAIL LAPORAN (READ-ONLY, milik PIC/pusat) ============ -->
    <?php foreach ($laporan_list as $r):
        $inv_id = (int) $r['id'];
        $inv_notas = [];
        for ($n = 1; $n <= 4; $n++) {
            if (!empty($r["foto_nota{$n}"])) $inv_notas[$n] = $r["foto_nota{$n}"];
        }
        $inv_rp = static fn ($v) => 'Rp ' . number_format((float) ($v ?? 0), 0, ',', '.');
        $inv_row = static function (string $label, $val) use ($inv_rp) {
            echo '<div class="inv-detail-row"><span>' . h($label) . '</span><b>' . $inv_rp($val) . '</b></div>';
        };
    ?>
    <div class="modal fade" id="invDetail<?= $inv_id ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content inv-detail-content">
                <div class="modal-header inv-detail-head">
                    <div>
                        <h5 class="modal-title fw-bold mb-1">Detail Laporan Harian</h5>
                        <div class="inv-detail-head-sub">
                            <span><i class="bi bi-shop me-1"></i><?= h($r['nama_cabang']) ?></span>
                            <span><i class="bi bi-calendar3 me-1"></i><?= date('d M Y', strtotime($r['tanggal'])) ?></span>
                            <span><i class="bi bi-person-badge me-1"></i><?= h($r['nama_pic'] ?? 'Pusat') ?></span>
                        </div>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <div class="modal-body inv-detail-body">
                    <div class="alert alert-secondary small rounded-3 mb-3"><i class="bi bi-eye-fill me-1"></i> Tampilan lihat-saja &mdash; laporan ini milik cabang/PIC, investor tidak dapat mengubahnya.</div>

                    <div class="row g-3">
                        <div class="col-lg-7">
                            <div class="d-flex flex-column gap-3">
                                <section class="inv-detail-sec">
                                    <div class="inv-detail-sec-h" style="color:#059669;"><i class="bi bi-cash-coin"></i> Pendapatan</div>
                                    <?php $inv_row('Tunai', $r['tunai']); $inv_row('QRIS', $r['qris']); $inv_row('Grab Food', $r['grab_food']); $inv_row('Go Food', $r['go_food']); $inv_row('Pencairan QRIS', $r['pencairan_qris']); ?>
                                </section>
                                <section class="inv-detail-sec">
                                    <div class="inv-detail-sec-h" style="color:#dc2626;"><i class="bi bi-basket"></i> Belanja Rutin</div>
                                    <?php $inv_row('Pasar', $r['belanja_pasar']); $inv_row('Sembako', $r['belanja_sembako']); $inv_row('Beras', $r['belanja_beras']); $inv_row('Toko', $r['belanja_toko']); ?>
                                </section>
                                <section class="inv-detail-sec">
                                    <div class="inv-detail-sec-h" style="color:#d97706;"><i class="bi bi-receipt"></i> Beban Operasional</div>
                                    <?php foreach ([
                                        'sewa' => 'Sewa', 'gaji' => 'Gaji', 'listrik' => 'Listrik', 'air' => 'Air',
                                        'sampah' => 'Sampah', 'keamanan' => 'Keamanan', 'internet' => 'Internet', 'gas' => 'Gas',
                                        'mingguan_karyawan' => 'Mingguan Karyawan', 'es_batu' => 'Es Batu',
                                        'bensin' => 'Bensin', 'lain_lain' => 'Lain-lain',
                                    ] as $fn => $fl) $inv_row($fl, $r[$fn]); ?>
                                </section>
                                <?php if (!empty($r['keterangan'])): ?>
                                <section class="inv-detail-sec">
                                    <div class="inv-detail-sec-h"><i class="bi bi-chat-left-text"></i> Catatan</div>
                                    <div class="small text-muted"><?= nl2br(h($r['keterangan'])) ?></div>
                                </section>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="col-lg-5">
                            <div class="d-flex flex-column gap-3">
                                <section class="inv-detail-sec inv-detail-sum">
                                    <div class="inv-detail-sec-h"><i class="bi bi-calculator"></i> Ringkasan</div>
                                    <?php $inv_row('Total Omzet', $r['total_omset']); $inv_row('Belanja Rutin', $r['total_rutin']); $inv_row('Operasional', $r['total_operasional']); $inv_row('Total Pengeluaran', $r['total_pengeluaran']); $inv_row('Sisa Tunai', $r['sisa_tunai']); $inv_row('Sisa QRIS', $r['sisa_qris']); ?>
                                    <div class="inv-detail-row inv-detail-hi"><span>Net Profit</span><b><?= $inv_rp($r['net_profit']) ?></b></div>
                                    <div class="inv-detail-row inv-detail-hi"><span>Margin</span><b><?= number_format((float) $r['persentase'], 2) ?>%</b></div>
                                </section>

                                <section class="inv-detail-sec">
                                    <div class="inv-detail-sec-h"><i class="bi bi-images"></i> Foto Nota <span class="inv-detail-badge"><?= count($inv_notas) ?> file</span></div>
                                    <?php if ($inv_notas): ?>
                                        <div class="inv-detail-notas">
                                            <?php foreach ($inv_notas as $n => $file): ?>
                                                <img src="../uploads/nota/<?= h($file) ?>" alt="Nota <?= $n ?>" loading="lazy" class="inv-detail-nota-img" onclick="invZoom(this.src)">
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="text-center text-muted small py-3"><i class="bi bi-image d-block fs-4 mb-1 opacity-50"></i>Tidak ada foto nota</div>
                                    <?php endif; ?>
                                </section>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer inv-detail-foot">
                    <button type="button" class="btn btn-light border fw-semibold" data-bs-dismiss="modal">Tutup</button>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <?php endif; ?>
</div>

<div id="invZoomOverlay" onclick="invZoomClose()" style="position:fixed;inset:0;z-index:3000;background:rgba(2,6,23,.93);display:none;align-items:center;justify-content:center;padding:16px;cursor:zoom-out;">
    <button type="button" onclick="invZoomClose()" style="position:absolute;top:12px;right:18px;color:#fff;font-size:2.2rem;line-height:1;background:none;border:0;cursor:pointer;">&times;</button>
    <img id="invZoomImg" src="" alt="Nota" style="max-width:100%;max-height:100%;object-fit:contain;border-radius:8px;">
</div>

<script>
function invZoom(src) {
    document.getElementById('invZoomImg').src = src;
    document.getElementById('invZoomOverlay').style.display = 'flex';
}
function invZoomClose() {
    document.getElementById('invZoomOverlay').style.display = 'none';
}
document.addEventListener('keydown', function (e) { if (e.key === 'Escape') invZoomClose(); });
</script>

<script>
// Animasi hitung naik untuk angka KPI utama — murni kosmetik.
document.querySelectorAll('.kpi-countup').forEach(function (el) {
    const target = parseFloat(el.dataset.value) || 0;
    const durasi = 900;
    const mulai = performance.now();
    function frame(now) {
        const progres = Math.min(1, (now - mulai) / durasi);
        const halus = 1 - Math.pow(1 - progres, 3);
        el.textContent = 'Rp ' + Math.round(target * halus).toLocaleString('id-ID');
        if (progres < 1) requestAnimationFrame(frame);
    }
    requestAnimationFrame(frame);
});

function gantiTren(val) {
    const url = new URL(window.location.href);
    url.searchParams.set('tren', val);
    window.location.href = url.toString();
}
</script>

<?php if (!empty($label_grafik)): ?>
<script>
const ctxInv = document.getElementById('grafikTrend').getContext('2d');
const gradOmzetInv = ctxInv.createLinearGradient(0, 0, 0, 280);
gradOmzetInv.addColorStop(0, 'rgba(126, 34, 206, 0.15)');
gradOmzetInv.addColorStop(1, 'rgba(126, 34, 206, 0.0)');
const gradLabaInv = ctxInv.createLinearGradient(0, 0, 0, 280);
gradLabaInv.addColorStop(0, 'rgba(217, 119, 6, 0.15)');
gradLabaInv.addColorStop(1, 'rgba(217, 119, 6, 0.0)');

new Chart(ctxInv, {
    type: 'line',
    data: {
        labels: <?= json_encode($label_grafik) ?>,
        datasets: [
            { label: 'Omzet', data: <?= json_encode($data_omzet) ?>, borderColor: '#7e22ce', backgroundColor: gradOmzetInv, borderWidth: 3, tension: 0.4, fill: true, pointRadius: 2, pointHoverRadius: 6 },
            { label: 'Net Profit', data: <?= json_encode($data_laba) ?>, borderColor: '#d97706', backgroundColor: gradLabaInv, borderWidth: 3, tension: 0.4, fill: true, pointRadius: 2, pointHoverRadius: 6 }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: { position: 'top', align: 'end', labels: { boxWidth: 8, boxHeight: 8, usePointStyle: true, font: { family: 'Plus Jakarta Sans', weight: '600', size: 12 }, padding: 15 } },
            tooltip: {
                padding: 12, backgroundColor: '#1e1b2e', borderRadius: 10, boxPadding: 6,
                titleFont: { family: 'Plus Jakarta Sans', size: 13, weight: '700' }, bodyFont: { family: 'Plus Jakarta Sans', size: 13 },
                callbacks: { label: (c) => (c.dataset.label ? c.dataset.label + ': ' : '') + (c.parsed.y !== null ? 'Rp ' + c.parsed.y.toLocaleString('id-ID') : '') }
            }
        },
        scales: {
            x: { grid: { display: false }, ticks: { color: '#a78bc4', font: { family: 'Plus Jakarta Sans', size: 11, weight: '500' } } },
            y: { grid: { color: '#faf5ff' }, ticks: { color: '#a78bc4', font: { family: 'Plus Jakarta Sans', size: 11, weight: '500' }, callback: (v) => v >= 1000000 ? 'Rp ' + (v / 1000000) + ' Jt' : 'Rp ' + v.toLocaleString('id-ID') } }
        }
    }
});
</script>
<?php endif; ?>
