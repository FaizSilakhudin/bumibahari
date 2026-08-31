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

    // 4. Grafik trend 6 bulan
    $g_start = date('Y-m-01', strtotime("$periode_ini-01 -5 month"));
    $g_end   = $tgl_akhir;
    $st = $conn->prepare("SELECT DATE_FORMAT(l.tanggal,'%b %Y') bulan,
                                  COALESCE(SUM(l.total_omset),0) omzet,
                                  COALESCE(SUM(l.net_profit),0) laba
                           FROM laporan_cabang l
                           WHERE l.status_laporan = 'lengkap' AND l.tanggal BETWEEN ? AND ? AND l.id_cabang IN ($ph)
                           GROUP BY DATE_FORMAT(l.tanggal,'%Y-%m') ORDER BY l.tanggal ASC");
    $st->bind_param('ss' . str_repeat('i', count($cabang_ids)), $g_start, $g_end, ...$cabang_ids);
    $st->execute();
    $grafik = $st->get_result();
    while ($g = $grafik->fetch_assoc()) {
        $label_grafik[] = $g['bulan'];
        $data_omzet[]   = (float) $g['omzet'];
        $data_laba[]    = (float) $g['laba'];
    }

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

<style>
    :root { --inv-primary: #7e22ce; --inv-primary-2: #a855f7; --inv-glow: rgba(126,34,206,.08); }
    body { background-color: #faf7ff !important; font-family: 'Plus Jakarta Sans', sans-serif !important; color: #1e1b2e; }

    .saas-card { background: #fff; border: 1px solid #f3e8ff !important; border-radius: 18px !important; box-shadow: 0 4px 6px -1px rgb(0 0 0 / .02), 0 10px 15px -3px rgb(0 0 0 / .01) !important; padding: 24px; transition: all .25s ease; }
    .saas-card:hover { transform: translateY(-2px); box-shadow: 0 20px 25px -5px rgb(0 0 0 / .05) !important; }

    .kpi-premium-card { background: #fff; border: 1px solid #f3e8ff; border-radius: 18px; padding: 22px; position: relative; overflow: hidden; min-height: 150px; display: flex; flex-direction: column; justify-content: space-between; box-shadow: 0 4px 6px -1px rgb(0 0 0 / .02); transition: all .25s ease; }
    .kpi-premium-card:hover { transform: translateY(-2px); box-shadow: 0 12px 20px -8px rgba(126,34,206,.1); }
    .kpi-badge-icon { width: 44px; height: 44px; border-radius: 12px; display: inline-flex; align-items: center; justify-content: center; font-size: 19px; background: var(--badge-bg); color: var(--badge-color); }
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
</style>

<div class="container-fluid py-4 px-3 px-md-4">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-start align-items-lg-center mb-4 gap-3">
        <div>
            <span class="text-muted small fw-bold text-uppercase" style="font-size: 11px; letter-spacing: 1px; color: #a78bc4 !important;">PORTAL INVESTOR &bull; <?= strtoupper($nama_periode) ?></span>
            <h3 class="fw-extrabold mb-0 mt-1" style="color: #1e1b2e; font-size: 24px; letter-spacing: -.5px;">
                Selamat datang, <span style="color: var(--inv-primary);"><?= h($nama_investor) ?></span>
            </h3>
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
                        <h4 class="kpi-value">Rp <?= number_format($kpi['omzet'] ?? 0, 0, ',', '.') ?></h4>
                        <span class="kpi-subvalue">Kemarin: <span class="fw-semibold text-dark">Rp <?= number_format($kpi_hari_ini['omzet'] ?? 0, 0, ',', '.') ?></span></span>
                    </div>
                    <div class="kpi-badge-icon" style="--badge-bg: var(--inv-glow); --badge-color: var(--inv-primary);"><i class="bi bi-graph-up-arrow"></i></div>
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
                        <h4 class="kpi-value">Rp <?= number_format($kpi['laba'] ?? 0, 0, ',', '.') ?></h4>
                        <span class="kpi-subvalue">Kemarin: <span class="fw-semibold text-dark">Rp <?= number_format($kpi_hari_ini['laba'] ?? 0, 0, ',', '.') ?></span></span>
                    </div>
                    <div class="kpi-badge-icon" style="--badge-bg: rgba(168,85,247,.08); --badge-color: #a855f7;"><i class="bi bi-wallet2"></i></div>
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
                    <div class="kpi-badge-icon" style="--badge-bg: rgba(217,119,6,.08); --badge-color: #d97706;"><i class="bi bi-building-check"></i></div>
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
                    <div class="kpi-badge-icon" style="--badge-bg: rgba(22,163,74,.08); --badge-color: #16a34a;"><i class="bi bi-file-earmark-check"></i></div>
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
                <div class="d-flex align-items-center justify-content-between mb-4">
                    <div>
                        <h6 class="fw-bold mb-0" style="color: #1e1b2e; font-size: 16px;">Trend Performa 6 Bulan Terakhir</h6>
                        <span class="text-muted small">Omzet vs Net Profit &mdash; cabang Anda</span>
                    </div>
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
                                    <div style="font-weight: 700; color: #1e1b2e;"><?= h($rank['nama_cabang']) ?></div>
                                    <div style="font-size: 11px; color: #8b7aa0;"><?= h($rank['nama_pengelola'] ?? '-') ?></div>
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
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($laporan_list)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-5"><i class="bi bi-inbox fs-2 d-block mb-2 opacity-50"></i>Belum ada laporan final pada periode ini.</td></tr>
                <?php else: foreach ($laporan_list as $r): ?>
                    <tr>
                        <td data-label="Tanggal" class="fw-semibold"><?= date('d M Y', strtotime($r['tanggal'])) ?></td>
                        <td data-label="Cabang" class="fw-bold" style="color:#1e1b2e;"><?= h($r['nama_cabang']) ?></td>
                        <td data-label="Omzet" class="fw-semibold" style="color: var(--inv-primary);">Rp <?= number_format($r['total_omset'], 0, ',', '.') ?></td>
                        <td data-label="Total Pengeluaran">Rp <?= number_format($r['total_pengeluaran'], 0, ',', '.') ?></td>
                        <td data-label="Net Profit" class="fw-bold" style="color:#16a34a;">Rp <?= number_format($r['net_profit'], 0, ',', '.') ?></td>
                        <td data-label="Margin"><span class="badge badge-modern-success"><?= number_format($r['persentase'], 2) ?>%</span></td>
                        <td data-label="Diinput Oleh"><i class="bi bi-person-badge me-1 text-muted"></i><?= h($r['nama_pic'] ?? 'Pusat') ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <div class="px-4">
            <?php render_pagination($page_laporan, $total_pages_laporan, ['from' => $offset_laporan + 1, 'to' => min($offset_laporan + $limit_laporan, $total_laporan), 'total' => $total_laporan, 'label' => 'laporan'], 'page_laporan'); ?>
        </div>
    </div>
    <?php endif; ?>
</div>

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
