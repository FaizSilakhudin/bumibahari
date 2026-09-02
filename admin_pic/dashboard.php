<?php
require '../config/koneksi.php';
require_role('pic');
include 'sidebar.php';

$id_user = current_user_id();
$cabang_ids = pic_cabang_ids($conn, $id_user);

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
$periode_anchor_sql = anchor_periode(date('Y-m-t', strtotime("$periode_ini-01")));
$kemarin = date('Y-m-d', strtotime('-1 day'));

$kpi = ['omzet' => 0, 'laba' => 0, 'margin' => 0];
$kpi_hari_ini = ['omzet' => 0, 'laba' => 0];
$kpi_lalu = ['omzet' => 0];
$naik_turun = 0;
$cabang_aktif = 0;
$laporan_selesai = 0;
$admin_fee_bulan = 0;
$admin_fee_kemarin = 0;
$label_grafik = $data_omzet = $data_laba = [];
$ranking_cabang = [];
$peringatan = [];

// Paginasi peringatan dini — 10 per halaman, sama persis dengan admin_pusat.
$limit_peringatan  = 10;
$page_peringatan   = max(1, (int) ($_GET['page_peringatan'] ?? 1));
$offset_peringatan = ($page_peringatan - 1) * $limit_peringatan;
$total_peringatan  = 0;
$total_pages_peringatan = 1;

if (!empty($cabang_ids)) {
    $ph = implode(',', array_fill(0, count($cabang_ids), '?'));
    $types_ids = str_repeat('i', count($cabang_ids));

    // 1. KPI periode terpilih
    $st = $conn->prepare("SELECT COALESCE(SUM(l.total_omset),0) omzet, COALESCE(SUM(l.net_profit),0) laba,
                                  COALESCE(AVG(l.persentase),0) margin
                           FROM laporan_cabang l
                           WHERE l.status_laporan='lengkap' AND DATE_FORMAT(l.tanggal,'%Y-%m') = ? AND l.id_cabang IN ($ph)");
    $st->bind_param('s' . $types_ids, $periode_ini, ...$cabang_ids);
    $st->execute();
    $kpi = $st->get_result()->fetch_assoc();

    // 1.1 KPI kemarin
    $st = $conn->prepare("SELECT COALESCE(SUM(l.total_omset),0) omzet, COALESCE(SUM(l.net_profit),0) laba
                           FROM laporan_cabang l WHERE l.status_laporan='lengkap' AND l.tanggal = ? AND l.id_cabang IN ($ph)");
    $st->bind_param('s' . $types_ids, $kemarin, ...$cabang_ids);
    $st->execute();
    $kpi_hari_ini = $st->get_result()->fetch_assoc();

    // 2. KPI bulan sebelumnya (perbandingan)
    $st = $conn->prepare("SELECT COALESCE(SUM(l.total_omset),0) omzet
                           FROM laporan_cabang l WHERE l.status_laporan='lengkap' AND DATE_FORMAT(l.tanggal,'%Y-%m') = ? AND l.id_cabang IN ($ph)");
    $st->bind_param('s' . $types_ids, $periode_lalu, ...$cabang_ids);
    $st->execute();
    $kpi_lalu = $st->get_result()->fetch_assoc();
    $naik_turun = $kpi_lalu['omzet'] > 0 ? (($kpi['omzet'] - $kpi_lalu['omzet']) / $kpi_lalu['omzet']) * 100 : 0;

    // 1.2 Admin Fee — rumus sama persis dengan admin_pusat/index.php: 3% dari net profit.
    $admin_fee_bulan   = $kpi['laba'] > 0 ? $kpi['laba'] * 3 / 100 : 0;
    $admin_fee_kemarin = $kpi_hari_ini['laba'] > 0 ? $kpi_hari_ini['laba'] * 3 / 100 : 0;

    // 3. Cabang yang sudah lapor lengkap periode ini
    $st = $conn->prepare("SELECT COUNT(DISTINCT l.id_cabang) total, COUNT(*) jml_laporan
                           FROM laporan_cabang l WHERE l.status_laporan='lengkap' AND DATE_FORMAT(l.tanggal,'%Y-%m') = ? AND l.id_cabang IN ($ph)");
    $st->bind_param('s' . $types_ids, $periode_ini, ...$cabang_ids);
    $st->execute();
    $row_aktif = $st->get_result()->fetch_assoc();
    $cabang_aktif = (int) $row_aktif['total'];
    $laporan_selesai = (int) $row_aktif['jml_laporan'];

    // 4. Grafik tren 6 bulan
    $g_start = date('Y-m-01', strtotime("$periode_ini-01 -5 month"));
    $g_end   = date('Y-m-t', strtotime("$periode_ini-01"));
    $st = $conn->prepare("SELECT DATE_FORMAT(l.tanggal,'%b %Y') bulan,
                                  COALESCE(SUM(l.total_omset),0) omzet,
                                  COALESCE(SUM(l.net_profit),0) laba
                           FROM laporan_cabang l
                           WHERE l.status_laporan='lengkap' AND l.tanggal BETWEEN ? AND ? AND l.id_cabang IN ($ph)
                           GROUP BY DATE_FORMAT(l.tanggal,'%Y-%m') ORDER BY l.tanggal ASC");
    $st->bind_param('ss' . $types_ids, $g_start, $g_end, ...$cabang_ids);
    $st->execute();
    $grafik = $st->get_result();
    while ($g = $grafik->fetch_assoc()) {
        $label_grafik[] = $g['bulan'];
        $data_omzet[]   = (float) $g['omzet'];
        $data_laba[]    = (float) $g['laba'];
    }

    // 5. Ranking cabang (di antara cabang yang Anda pegang)
    $st = $conn->prepare("SELECT c.id_cabang, c.nama_cabang,
                                  COALESCE(SUM(l.total_omset),0) total_omset,
                                  COALESCE(SUM(l.net_profit),0) total_net_profit
                           FROM cabang c
                           LEFT JOIN laporan_cabang l ON l.id_cabang = c.id_cabang
                                  AND DATE_FORMAT(l.tanggal,'%Y-%m') = ? AND l.status_laporan = 'lengkap'
                           WHERE c.id_cabang IN ($ph)
                           GROUP BY c.id_cabang ORDER BY total_omset DESC");
    $st->bind_param('s' . $types_ids, $periode_ini, ...$cabang_ids);
    $st->execute();
    $res_rank = $st->get_result();
    $no = 1;
    while ($row = $res_rank->fetch_assoc()) {
        $row['no'] = $no++;
        $row['nama_pengelola'] = pengelola_pada_tanggal($conn, (int) $row['id_cabang'], $periode_anchor_sql);
        $ranking_cabang[] = $row;
    }

    // 6. Peringatan dini — cabang Anda yang SAMA SEKALI belum kirim laporan/nota untuk
    // KEMARIN (bukan yang nota-nya sudah masuk tapi belum diisi PIC — itu urusan
    // "Antrian Laporan", bukan peringatan ke pusat).
    // Paginasi — 10 per halaman, sama persis dengan render_pagination() yang dipakai admin_pusat.
    $st = $conn->prepare("SELECT COUNT(*) total FROM cabang c
                           WHERE c.id_cabang IN ($ph)
                             AND c.id_cabang NOT IN (SELECT id_cabang FROM laporan_cabang WHERE tanggal = ?)");
    $st->bind_param($types_ids . 's', ...array_merge($cabang_ids, [$kemarin]));
    $st->execute();
    $total_peringatan = (int) $st->get_result()->fetch_assoc()['total'];
    $total_pages_peringatan = max(1, (int) ceil($total_peringatan / $limit_peringatan));

    $st = $conn->prepare("SELECT c.id_cabang, c.nama_cabang, MAX(l.tanggal) input_terakhir, DATEDIFF(?, MAX(l.tanggal)) selisih_hari
                           FROM cabang c LEFT JOIN laporan_cabang l ON c.id_cabang = l.id_cabang
                           WHERE c.id_cabang IN ($ph)
                             AND c.id_cabang NOT IN (SELECT id_cabang FROM laporan_cabang WHERE tanggal = ?)
                           GROUP BY c.id_cabang ORDER BY selisih_hari DESC, c.nama_cabang ASC
                           LIMIT ? OFFSET ?");
    $st->bind_param('s' . $types_ids . 's' . 'ii', $kemarin, ...array_merge($cabang_ids, [$kemarin, $limit_peringatan, $offset_peringatan]));
    $st->execute();
    $res_p = $st->get_result();
    while ($row = $res_p->fetch_assoc()) {
        $row['nama_pengelola'] = pengelola_pada_tanggal($conn, (int) $row['id_cabang'], date('Y-m-d'));
        $peringatan[] = $row;
    }
}
?>

<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>

<style>
    :root { --pic-primary: #2563eb; --pic-glow: rgba(37,99,235,.08); }
    body { background-color: #f6f8ff !important; font-family: 'Plus Jakarta Sans', sans-serif !important; color: #1e293b; }

    .saas-card { background: #fff; border: 1px solid #eef1fb !important; border-radius: 18px !important; box-shadow: 0 4px 6px -1px rgb(0 0 0 / .02), 0 10px 15px -3px rgb(0 0 0 / .01) !important; padding: 24px; transition: all .25s ease; }
    .saas-card:hover { transform: translateY(-2px); box-shadow: 0 20px 25px -5px rgb(0 0 0 / .05) !important; }

    .kpi-premium-card { background: #fff; border: 1px solid #eef1fb; border-radius: 18px; padding: 22px; position: relative; overflow: hidden; min-height: 150px; display: flex; flex-direction: column; justify-content: space-between; box-shadow: 0 4px 6px -1px rgb(0 0 0 / .02); transition: all .25s ease; }
    .kpi-premium-card:hover { transform: translateY(-2px); box-shadow: 0 12px 20px -8px rgba(30,58,95,.1); }
    .kpi-badge-icon { width: 44px; height: 44px; border-radius: 12px; display: inline-flex; align-items: center; justify-content: center; font-size: 19px; background: var(--badge-bg); color: var(--badge-color); }
    .kpi-meta { font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 1px; }
    .kpi-value { font-size: 22px; font-weight: 800; color: #0f172a; letter-spacing: -.5px; margin-top: 4px; }
    .kpi-subvalue { font-size: 12px; font-weight: 500; color: #64748b; margin-top: 4px; }

    .badge-modern-success, .badge-modern-danger, .badge-modern-warning { padding: 5px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; display: inline-flex; align-items: center; gap: 4px; }
    .badge-modern-success { background: #f0fdf4; color: #16a34a; border: 1px solid #dcfce7; }
    .badge-modern-danger { background: #fef2f2; color: #dc2626; border: 1px solid #fee2e2; }
    .badge-modern-warning { background: #fffbeb; color: #d97706; border: 1px solid #fde68a; }

    .form-select-filter { border-radius: 12px !important; border: 1px solid #dbe3fb !important; font-size: 14px; font-weight: 600; color: #1e3a5f; padding: 9px 16px; background: #fff; }
    .form-select-filter:focus { border-color: var(--pic-primary) !important; box-shadow: 0 0 0 4px rgba(37,99,235,.12) !important; }

    .kpi-card { background: #fff; border: 1px solid #eef1fb; border-radius: 16px; padding: 20px; box-shadow: 0 4px 20px rgba(0,0,0,.03); }
    .kpi-header { display: flex; align-items: center; gap: 12px; }
    .kpi-icon { width: 42px; height: 42px; border-radius: 12px; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 18px; background: linear-gradient(135deg, var(--pic-primary) 0%, #1e3a5f 100%); box-shadow: 0 4px 12px rgba(37,99,235,.3); }
    .kpi-label { font-size: 16px; font-weight: 700; color: #0f172a; }

    .table-modern thead th { background: #f6f8ff; color: #64748b; font-size: 11px; text-transform: uppercase; letter-spacing: .5px; font-weight: 700; padding: 14px 16px; border-bottom: 1px solid #eef1fb; white-space: nowrap; }
    .table-modern tbody td { padding: 16px; border-bottom: 1px solid #f6f8ff; font-size: 14px; white-space: nowrap; }

    @media (max-width: 767.98px) {
        .table-modern thead { display: none; }
        .table-modern tbody tr { display: block; border: 1px solid #eef1fb; border-radius: 14px; margin: 12px; padding: 12px; background: #fff; }
        .table-modern tbody td { display: flex; justify-content: space-between; align-items: center; padding: 10px 0 !important; border-bottom: 1px dashed #eef1fb !important; text-align: right; white-space: normal; }
        .table-modern tbody td::before { content: attr(data-label); font-weight: 700; color: #94a3b8; font-size: 11px; text-transform: uppercase; text-align: left; }
    }
</style>

<div class="container-fluid py-4 px-3 px-md-4">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-start align-items-lg-center mb-4 gap-3">
        <div>
            <span class="text-muted small fw-bold text-uppercase" style="font-size: 11px; letter-spacing: 1px; color:#94a3b8 !important;">DASHBOARD PIC &bull; <?= strtoupper($nama_periode) ?></span>
            <h3 class="fw-extrabold mb-0 mt-1" style="color: #0f172a; font-size: 24px; letter-spacing: -.5px;">Ringkasan Cabang Anda</h3>
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
            Anda belum ditugaskan ke cabang manapun. Hubungi Admin Pusat.
        </div>
    <?php else: ?>

    <div class="row g-3 mb-4">
        <div class="col-xl-3 col-lg-4 col-md-6 col-12">
            <div class="kpi-premium-card" style="border-top: 3px solid var(--pic-primary);">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="d-flex flex-column">
                        <span class="kpi-meta">Total Omzet</span>
                        <h4 class="kpi-value">Rp <?= number_format($kpi['omzet'] ?? 0, 0, ',', '.') ?></h4>
                        <span class="kpi-subvalue">Kemarin: <span class="fw-semibold text-dark">Rp <?= number_format($kpi_hari_ini['omzet'] ?? 0, 0, ',', '.') ?></span></span>
                    </div>
                    <div class="kpi-badge-icon" style="--badge-bg: var(--pic-glow); --badge-color: var(--pic-primary);"><i class="bi bi-graph-up-arrow"></i></div>
                </div>
                <div class="pt-3 mt-2 border-top d-flex align-items-center justify-content-between" style="border-color: #f6f8ff !important;">
                    <span class="<?= $naik_turun >= 0 ? 'badge-modern-success' : 'badge-modern-danger' ?>"><i class="bi bi-<?= $naik_turun >= 0 ? 'arrow-up-short' : 'arrow-down-short' ?>"></i> <?= number_format(abs($naik_turun), 1) ?>%</span>
                    <span class="text-muted small" style="font-size: 12px;">vs bulan lalu</span>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-lg-4 col-md-6 col-12">
            <div class="kpi-premium-card" style="border-top: 3px solid #16a34a;">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="d-flex flex-column">
                        <span class="kpi-meta">Laba Bersih</span>
                        <h4 class="kpi-value">Rp <?= number_format($kpi['laba'] ?? 0, 0, ',', '.') ?></h4>
                        <span class="kpi-subvalue">Kemarin: <span class="fw-semibold text-dark">Rp <?= number_format($kpi_hari_ini['laba'] ?? 0, 0, ',', '.') ?></span></span>
                    </div>
                    <div class="kpi-badge-icon" style="--badge-bg: rgba(22,163,74,.08); --badge-color: #16a34a;"><i class="bi bi-wallet2"></i></div>
                </div>
                <div class="pt-3 mt-2 border-top" style="border-color: #f6f8ff !important;">
                    <span class="badge-modern-success"><i class="bi bi-pie-chart-fill me-1"></i> Margin <?= number_format($kpi['margin'] ?? 0, 2) ?>%</span>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-lg-4 col-md-6 col-12">
            <div class="kpi-premium-card" style="border-top: 3px solid #d97706;">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="d-flex flex-column">
                        <span class="kpi-meta">Cabang Sudah Lapor</span>
                        <h4 class="kpi-value"><?= $cabang_aktif ?> <span style="font-size: 14px; color:#94a3b8; font-weight: 500;">/ <?= count($cabang_ids) ?> Unit</span></h4>
                    </div>
                    <div class="kpi-badge-icon" style="--badge-bg: rgba(217,119,6,.08); --badge-color: #d97706;"><i class="bi bi-building-check"></i></div>
                </div>
                <div class="pt-3 mt-2">
                    <div class="progress" style="height: 6px; border-radius: 10px; background: #f6f8ff;">
                        <div class="progress-bar" style="width: <?= count($cabang_ids) > 0 ? ($cabang_aktif / count($cabang_ids)) * 100 : 0 ?>%; background: linear-gradient(90deg, #d97706, #f59e0b); border-radius: 10px;"></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-lg-4 col-md-6 col-12">
            <div class="kpi-premium-card" style="border-top: 3px solid #7e22ce;">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="d-flex flex-column">
                        <span class="kpi-meta">Laporan Selesai</span>
                        <h4 class="kpi-value"><?= $laporan_selesai ?> <span style="font-size: 14px; color:#94a3b8; font-weight: 500;">laporan</span></h4>
                    </div>
                    <div class="kpi-badge-icon" style="--badge-bg: rgba(126,34,206,.08); --badge-color: #7e22ce;"><i class="bi bi-file-earmark-check"></i></div>
                </div>
                <div class="pt-3 mt-2 border-top" style="border-color: #f6f8ff !important; font-size: 12px; color: #64748b; font-weight: 500;">
                    <i class="bi bi-info-circle me-1 text-primary"></i> Sudah Anda input bulan ini
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-lg-4 col-md-6 col-12">
            <div class="kpi-premium-card" style="border-top: 3px solid #0f172a;">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="d-flex flex-column">
                        <span class="kpi-meta">Admin Fee (3%)</span>
                        <h4 class="kpi-value">Rp <?= number_format($admin_fee_bulan, 0, ',', '.') ?></h4>
                        <span class="kpi-subvalue">Kemarin: <span class="fw-semibold text-dark">Rp <?= number_format($admin_fee_kemarin, 0, ',', '.') ?></span></span>
                    </div>
                    <div class="kpi-badge-icon" style="--badge-bg: rgba(15,23,42,.06); --badge-color: #0f172a;"><i class="bi bi-cash-coin"></i></div>
                </div>
                <div class="pt-3 mt-2 border-top" style="border-color: #f6f8ff !important; font-size: 12px; color: #64748b; font-weight: 500;">
                    <i class="bi bi-calculator me-1 text-primary"></i> 3% dari total net profit bulan ini
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-8 col-12">
            <div class="saas-card h-100">
                <div class="d-flex align-items-center justify-content-between mb-4">
                    <div>
                        <h6 class="fw-bold mb-0" style="color: #0f172a; font-size: 16px;">Trend Performa 6 Bulan Terakhir</h6>
                        <span class="text-muted small">Omzet vs Laba &mdash; cabang yang Anda pegang</span>
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
                        <div style="font-size: 12px; color: #64748b;">Urut Omzet Tertinggi</div>
                    </div>
                </div>
                <div style="max-height: 290px; overflow-y: auto; margin-top: 15px; padding-right: 5px;">
                    <table class="table table-sm align-middle" style="font-size: 13px; margin-bottom: 0;">
                        <thead style="position: sticky; top: 0; background: #fff; z-index: 1;">
                            <tr style="border-bottom: 2px solid #eef1fb;">
                                <th style="width: 35px; color: #64748b; font-weight: 700;">#</th>
                                <th style="color: #64748b; font-weight: 700;">Cabang</th>
                                <th class="text-end" style="color: #64748b; font-weight: 700;">Omzet</th>
                                <th class="text-end" style="color: #64748b; font-weight: 700;">Net Profit</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($ranking_cabang)): ?>
                            <tr><td colspan="4" class="text-center text-muted py-3">Belum ada data</td></tr>
                        <?php else: foreach ($ranking_cabang as $rank): ?>
                            <tr style="border-bottom: 1px solid #f6f8ff;">
                                <td>
                                    <?php if ($rank['no'] == 1): ?><span class="badge rounded-pill bg-warning text-dark px-2">1</span>
                                    <?php elseif ($rank['no'] == 2): ?><span class="badge rounded-pill bg-secondary px-2">2</span>
                                    <?php elseif ($rank['no'] == 3): ?><span class="badge rounded-pill px-2" style="background:#cd7f32;color:#fff;">3</span>
                                    <?php else: ?><span class="fw-bold text-muted ps-1"><?= $rank['no'] ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="font-weight: 700; color: #0f172a;"><?= h($rank['nama_cabang']) ?></div>
                                    <div style="font-size: 11px; color: #64748b;"><?= h($rank['nama_pengelola'] ?? '-') ?></div>
                                </td>
                                <td class="text-end" style="font-weight: 700; color: var(--pic-primary);">Rp <?= number_format($rank['total_omset'], 0, ',', '.') ?></td>
                                <td class="text-end" style="font-weight: 700; color: <?= $rank['total_net_profit'] >= 0 ? '#16a34a' : '#dc2626' ?>;">Rp <?= number_format($rank['total_net_profit'], 0, ',', '.') ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="saas-card p-0 overflow-hidden mb-4">
        <div class="px-4 pt-4 pb-3 border-bottom" style="border-color: #f6f8ff !important;">
            <h6 class="fw-bold mb-0" style="color: #0f172a; font-size: 16px;">🚨 Peringatan Dini</h6>
            <p class="text-muted small mb-0 mt-1">Cabang Anda yang belum mengirim laporan untuk tanggal <?= date('d M Y', strtotime($kemarin)) ?>.</p>
        </div>
        <div class="table-responsive">
            <table class="table table-modern align-middle mb-0">
                <thead>
                    <tr><th>Nama Cabang</th><th>Nama Pengelola</th><th>Input Terakhir</th><th>Status</th></tr>
                </thead>
                <tbody>
                <?php if (empty($peringatan)): ?>
                    <tr><td colspan="4" class="text-center text-success py-5 fw-bold"><i class="bi bi-shield-check fs-4 d-block mb-2"></i> Semua cabang Anda sudah lapor lengkap untuk kemarin.</td></tr>
                <?php else: foreach ($peringatan as $p):
                    $hari_telat = $p['selisih_hari'] ?? 0;
                    $belum_pernah = $hari_telat == 0 || $p['input_terakhir'] === null;
                ?>
                    <tr>
                        <td data-label="Cabang" class="fw-bold" style="color:#0f172a;"><?= h($p['nama_cabang']) ?></td>
                        <td data-label="Pengelola"><i class="bi bi-person-badge me-1 text-primary"></i><?= h($p['nama_pengelola'] ?? 'Belum Diatur') ?></td>
                        <td data-label="Input Terakhir"><?= $p['input_terakhir'] ? date('d M Y', strtotime($p['input_terakhir'])) : '-' ?></td>
                        <td data-label="Status">
                            <?php if ($belum_pernah): ?>
                                <span class="badge-modern-danger"><i class="bi bi-exclamation-circle-fill me-1"></i> Belum Lapor</span>
                            <?php else: ?>
                                <span class="badge-modern-warning"><i class="bi bi-clock-history me-1"></i> Menunggak <?= $hari_telat ?> hari</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php if (!empty($peringatan)): ?>
        <div class="px-4">
            <?php render_pagination($page_peringatan, $total_pages_peringatan, ['from' => $offset_peringatan + 1, 'to' => min($offset_peringatan + $limit_peringatan, $total_peringatan), 'total' => $total_peringatan, 'label' => 'cabang'], 'page_peringatan'); ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php if (!empty($label_grafik)): ?>
<script>
const ctxPic = document.getElementById('grafikTrend').getContext('2d');
const gradOmzetPic = ctxPic.createLinearGradient(0, 0, 0, 280);
gradOmzetPic.addColorStop(0, 'rgba(37, 99, 235, 0.15)');
gradOmzetPic.addColorStop(1, 'rgba(37, 99, 235, 0.0)');
const gradLabaPic = ctxPic.createLinearGradient(0, 0, 0, 280);
gradLabaPic.addColorStop(0, 'rgba(22, 163, 74, 0.15)');
gradLabaPic.addColorStop(1, 'rgba(22, 163, 74, 0.0)');

new Chart(ctxPic, {
    type: 'line',
    data: {
        labels: <?= json_encode($label_grafik) ?>,
        datasets: [
            { label: 'Omzet', data: <?= json_encode($data_omzet) ?>, borderColor: '#2563eb', backgroundColor: gradOmzetPic, borderWidth: 3, tension: 0.4, fill: true, pointRadius: 2, pointHoverRadius: 6 },
            { label: 'Laba Bersih', data: <?= json_encode($data_laba) ?>, borderColor: '#16a34a', backgroundColor: gradLabaPic, borderWidth: 3, tension: 0.4, fill: true, pointRadius: 2, pointHoverRadius: 6 }
        ]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: { position: 'top', align: 'end', labels: { boxWidth: 8, boxHeight: 8, usePointStyle: true, font: { family: 'Plus Jakarta Sans', weight: '600', size: 12 }, padding: 15 } },
            tooltip: { padding: 12, backgroundColor: '#0f172a', borderRadius: 10, boxPadding: 6, callbacks: { label: (c) => (c.dataset.label ? c.dataset.label + ': ' : '') + (c.parsed.y !== null ? 'Rp ' + c.parsed.y.toLocaleString('id-ID') : '') } }
        },
        scales: {
            x: { grid: { display: false }, ticks: { color: '#94a3b8', font: { family: 'Plus Jakarta Sans', size: 11, weight: '500' } } },
            y: { grid: { color: '#f6f8ff' }, ticks: { color: '#94a3b8', font: { family: 'Plus Jakarta Sans', size: 11, weight: '500' }, callback: (v) => v >= 1000000 ? 'Rp ' + (v / 1000000) + ' Jt' : 'Rp ' + v.toLocaleString('id-ID') } }
        }
    }
});
</script>
<?php endif; ?>
