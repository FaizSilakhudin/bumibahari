<?php
/**
 * REVENUE SHARING — admin_pusat
 *
 * Laporan bulanan admin fee (3% dari net profit keseluruhan, fixed) + service fee
 * (3/5/7,5% dari sisi pengelola, bisa dipilih per cabang per bulan) +
 * status pembayaran (pending / belum lunas / lunas, editable inline).
 *
 * Tampilan: 1 halaman = semua cabang (tanpa pagination), layout fixed-width
 * supaya 8 kolom muat di desktop standar. Mobile: stack jadi kartu.
 * Footer: Total Admin Fee + Total Service Fee + Total Keseluruhan + counter.
 * Bawah: Export PDF / Excel / Share ke WhatsApp (pola sama dengan rekapitulasi).
 */

require '../config/koneksi.php';
include 'sidebar_pusat.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'pusat') {
    header("Location: ../login");
    exit;
}

// ----- Filter periode -----
$sel_tahun = (int) ($_GET['tahun'] ?? date('Y'));
$sel_bulan = (int) ($_GET['bulan'] ?? date('n'));
if ($sel_tahun < 2000 || $sel_tahun > ((int) date('Y') + 1)) $sel_tahun = (int) date('Y');
if ($sel_bulan < 1 || $sel_bulan > 12) $sel_bulan = (int) date('n');
$nama_periode = nama_bulan_id($sel_bulan) . ' ' . $sel_tahun;
$nama_periode_en = date('F Y', strtotime("$sel_tahun-$sel_bulan-01"));

// Anchor untuk pengelola_pada_tanggal — akhir bulan dibatasi hari ini.
$akhir_periode  = date('Y-m-t', strtotime("$sel_tahun-$sel_bulan-01"));
$periode_anchor = anchor_periode($akhir_periode);

// ----- Query: 1 baris per cabang, agregat net_profit + status/presentase -----
$sql = "
    SELECT
        c.id_cabang,
        c.nama_cabang,
        COALESCE(SUM(l.net_profit), 0) AS net_profit,
        COALESCE(rs.persen_service_fee, 5.00) AS persen_service_fee,
        COALESCE(rs.status_pembayaran, 'pending') AS status_pembayaran
    FROM cabang c
    LEFT JOIN laporan_cabang l
        ON l.id_cabang = c.id_cabang
        AND YEAR(l.tanggal) = ? AND MONTH(l.tanggal) = ?
        AND l.status_laporan = 'lengkap'
    LEFT JOIN revenue_sharing rs
        ON rs.id_cabang = c.id_cabang AND rs.tahun = ? AND rs.bulan = ?
    GROUP BY c.id_cabang, c.nama_cabang, rs.persen_service_fee, rs.status_pembayaran
    ORDER BY c.nama_cabang ASC
";
$st = $conn->prepare($sql);
$st->bind_param('iiii', $sel_tahun, $sel_bulan, $sel_tahun, $sel_bulan);
$st->execute();
$rs_rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

// ----- Hitung nominal & kumpulkan baris -----
$total_admin_fee_all   = 0.0;
$total_service_fee_all = 0.0;
$baris = [];
$jml_lunas = 0;
foreach ($rs_rows as $r) {
    $net_profit   = (float) $r['net_profit'];
    $admin_fee    = $net_profit > 0 ? $net_profit * 3 / 100 : 0;
    $laba_setelah = $net_profit - $admin_fee;
    $share_pgl    = $laba_setelah * 50 / 100;
    $persen       = (float) $r['persen_service_fee'];
    $service_fee  = $share_pgl * $persen / 100;

    $total_admin_fee_all   += $admin_fee;
    $total_service_fee_all += $service_fee;
    if ($r['status_pembayaran'] === 'lunas') $jml_lunas++;

    $baris[] = [
        'id_cabang'           => (int) $r['id_cabang'],
        'nama_cabang'         => $r['nama_cabang'],
        'nama_pengelola'      => pengelola_pada_tanggal($conn, (int) $r['id_cabang'], $periode_anchor),
        'net_profit'          => $net_profit,
        'admin_fee'           => $admin_fee,
        'persen_service_fee'  => $persen,
        'nominal_service_fee' => $service_fee,
        'status_pembayaran'   => $r['status_pembayaran'],
    ];
}
$total_keseluruhan = $total_admin_fee_all + $total_service_fee_all;
$jml_total         = count($baris);
$jml_belum_lunas   = $jml_total - $jml_lunas;

// ----- Token CSRF untuk FormData AJAX -----
$csrf_token = csrf_token();

// Stats untuk header strip
$net_profit_total = 0.0;
foreach ($baris as $b) $net_profit_total += $b['net_profit'];
?>

<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

<style>
  body { font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif; background-color: #f1f5f9; color: #0f172a; }

  /* ===== Header strip — gradient halus, copy informatif ===== */
  .rs-hero {
      background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 100%);
      color: #fff; border-radius: 16px; padding: 22px 26px;
      box-shadow: 0 12px 32px -10px rgba(15, 23, 42, .35);
      position: relative; overflow: hidden;
  }
  .rs-hero::before {
      content: ""; position: absolute; top: -40px; right: -40px;
      width: 180px; height: 180px; border-radius: 50%;
      background: radial-gradient(circle, rgba(99,102,241,.18) 0%, transparent 70%);
  }
  .rs-hero .eyebrow { font-size: 11px; letter-spacing: 1.5px; text-transform: uppercase; color: rgba(255,255,255,.65); font-weight: 700; }
  .rs-hero .title    { font-size: 24px; font-weight: 800; letter-spacing: -.5px; margin-top: 4px; }
  .rs-hero .desc     { font-size: 12.5px; color: rgba(255,255,255,.7); font-weight: 500; margin-top: 6px; max-width: 600px; }

  /* ===== Filter dropdown ===== */
  .form-select-filter {
      border-radius: 10px; border: 1px solid rgba(255,255,255,.18);
      padding: 8px 14px; font-size: 13.5px; font-weight: 600; color: #fff;
      background-color: rgba(255,255,255,.08); backdrop-filter: blur(4px);
  }
  .form-select-filter option { color: #0f172a; background: #fff; }
  .form-select-filter:focus  { border-color: rgba(255,255,255,.5); box-shadow: 0 0 0 3px rgba(255,255,255,.12); }

  /* Halaman ini pakai padding default .content dari sidebar_pusat.php
     (padding: 32px, margin-left: 260px) — box konsisten dengan halaman lain. */

  /* ===== Tabel compact fixed-width (8 kolom muat di desktop) ===== */
  /* Lebar kolom total = 956px dari colgroup. table-layout:fixed memastikan
     browser尊重 lebar kolom — JANGAN pasang min-width di sini atau tabel
     dipaksa lebih lebar dari colgroup dan overflow ke scroll horizontal. */
  .rs-table {
      table-layout: fixed; border-collapse: separate; border-spacing: 0;
      width: 100%; max-width: 100%;
  }
  .rs-table thead th {
      background: #f8fafc !important;
      color: #475569 !important;
      font-size: 10px !important; text-transform: uppercase; letter-spacing: 0.6px;
      font-weight: 800; padding: 12px 10px !important;
      border-bottom: 2px solid #e2e8f0 !important; white-space: nowrap;
      position: sticky; top: 0; z-index: 2;
  }
  .rs-table tbody td {
      padding: 10px !important;
      border-bottom: 1px solid #f1f5f9 !important;
      font-size: 12px !important;
      vertical-align: middle;
      white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  }
  .rs-table tbody tr:hover td { background: #f8fafc !important; }
  .rs-table tbody tr:last-child td { border-bottom: none !important; }

  /* Lebar kolom sesuai colgroup — total 892px agar 8 kolom muat utuh di viewport
     ≥1280px TANPA scroll horizontal. (sidebar 260 + .content padding 64 + tabel
     892 = 1216, sisa 64px di viewport 1280. Header teks pendek supaya pas.) */
  .rs-col-no    { width: 32px;  text-align: center; }
  .rs-col-pgl   { width: 110px; }
  .rs-col-cbg   { width: 130px; }
  .rs-col-np    { width: 95px;  text-align: right; }
  .rs-col-af    { width: 85px;  text-align: right; font-weight: 700; }
  .rs-col-prs   { width: 125px; text-align: center; }
  .rs-col-sf    { width: 100px; text-align: right; font-weight: 700; color: #0ea5e9; }
  .rs-col-stt   { width: 215px; text-align: center; }

  /* Tombol presentase (3/5/7,5) & status — compact pills */
  .rs-btn-group { display: inline-flex; gap: 3px; }
  .rs-btn {
      border: 1px solid #cbd5e1; background: #fff; color: #475569;
      font-weight: 700; font-size: 10.5px;
      padding: 4px 8px; border-radius: 6px; cursor: pointer;
      transition: all .15s ease; min-width: 32px; text-align: center; line-height: 1.2;
  }
  .rs-btn:hover { background: #f1f5f9; border-color: #94a3b8; }
  .rs-btn.is-active { background: #4f46e5; border-color: #4f46e5; color: #fff; box-shadow: 0 2px 6px -1px rgba(79,70,229,.45); }
  .rs-btn.is-active:hover { background: #4338ca; }
  .rs-btn[disabled] { opacity: .55; cursor: wait; }

  .status-pending     { background: #fffbeb; color: #b45309; border-color: #fde68a; }
  .status-pending.is-active { background: #b45309; border-color: #b45309; color: #fff; box-shadow: 0 2px 6px -1px rgba(180,83,9,.4); }
  .status-belum_lunas { background: #fef2f2; color: #b91c1c; border-color: #fecaca; }
  .status-belum_lunas.is-active { background: #b91c1c; border-color: #b91c1c; color: #fff; box-shadow: 0 2px 6px -1px rgba(185,28,28,.4); }
  .status-lunas       { background: #f0fdf4; color: #15803d; border-color: #bbf7d0; }
  .status-lunas.is-active { background: #15803d; border-color: #15803d; color: #fff; box-shadow: 0 2px 6px -1px rgba(21,128,61,.4); }

  tr.rs-flash { animation: rsFlash 600ms ease-out; }
  @keyframes rsFlash {
      0%   { background: #dcfce7; }
      100% { background: transparent; }
  }

  /* ===== Footer ringkasan ===== */
  .rs-summary { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; }
  @media (max-width: 767.98px) { .rs-summary { grid-template-columns: 1fr; } }

  .rs-summary-card {
      background: #fff; border: 1px solid #e2e8f0; border-radius: 14px;
      padding: 16px 18px; position: relative; overflow: hidden;
      transition: transform .2s ease, box-shadow .2s ease;
  }
  .rs-summary-card:hover { transform: translateY(-2px); box-shadow: 0 12px 24px -10px rgba(15,23,42,.15); }
  .rs-summary-card .label { font-size: 10.5px; letter-spacing: 0.8px; text-transform: uppercase; color: #64748b; font-weight: 800; }
  .rs-summary-card .value { font-size: 22px; font-weight: 800; color: #0f172a; letter-spacing: -.5px; margin-top: 6px; }
  .rs-summary-card .icon  {
      position: absolute; right: 16px; top: 50%; transform: translateY(-50%);
      width: 42px; height: 42px; border-radius: 12px; display: flex; align-items: center;
      justify-content: center; color: #fff; font-size: 20px;
  }
  .rs-summary-card.tone-admin   .icon { background: linear-gradient(135deg, #f59e0b, #d97706); }
  .rs-summary-card.tone-service .icon { background: linear-gradient(135deg, #0ea5e9, #0284c7); }
  .rs-summary-card.tone-total   .icon { background: linear-gradient(135deg, #16a34a, #15803d); }
  .rs-summary-card.tone-total   .value { color: #15803d; }
  .rs-summary-card.tone-service .value { color: #0369a1; }
  .rs-summary-card.tone-admin   .value { color: #b45309; }

  /* ===== Tombol export ===== */
  .btn-export {
      border-radius: 10px; padding: 10px 18px; font-weight: 700;
      font-size: 13px; display: inline-flex; align-items: center; gap: 8px;
      transition: all .15s ease; box-shadow: 0 2px 6px rgba(0,0,0,.04);
      border: none;
  }
  .btn-export:hover { transform: translateY(-1px); box-shadow: 0 8px 18px rgba(0,0,0,.1); }
  .btn-export-pdf    { background: #dc2626; color: #fff; }
  .btn-export-pdf:hover    { background: #b91c1c; color: #fff; }
  .btn-export-wa     { background: #16a34a; color: #fff; }
  .btn-export-wa:hover     { background: #15803d; color: #fff; }
  .btn-export-excel  { background: #fff; color: #16a34a; border: 1.5px solid #16a34a !important; }
  .btn-export-excel:hover  { background: #f0fdf4; color: #15803d; }

  /* Hint scroll horizontal di mobile / layar sempit */
  .rs-scroll-hint {
      display: none;
      font-size: 11px; color: #64748b; padding: 6px 12px;
      background: #f1f5f9; border-bottom: 1px solid #e2e8f0;
      align-items: center; gap: 6px;
  }
  @media (max-width: 1279.98px) { .rs-scroll-hint { display: flex; } }

  /* Mobile: tumpuk jadi kartu */
  @media (max-width: 767.98px) {
      .rs-table { min-width: 0; }
      .rs-table thead { display: none; }
      .rs-table tbody tr { display: block; border: 1px solid #e2e8f0; border-radius: 12px; margin: 10px 0; padding: 12px; background: #fff; }
      .rs-table tbody td {
          display: flex; justify-content: space-between; align-items: center;
          padding: 7px 0 !important; border-bottom: 1px dashed #f1f5f9 !important;
          white-space: normal; text-align: left !important;
          overflow: visible; text-overflow: clip;
      }
      .rs-table tbody td::before {
          content: attr(data-label); font-weight: 700; color: #64748b;
          font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.5px;
          flex-shrink: 0; margin-right: 12px;
      }
      .rs-table tbody td:last-child { border-bottom: none !important; }
      .rs-col-no, .rs-col-pgl, .rs-col-cbg, .rs-col-np, .rs-col-af, .rs-col-prs, .rs-col-sf, .rs-col-stt { width: auto; }
  }
</style>

<div class="content">
<!-- container-fluid tanpa padding horizontal: .content sudah punya padding 32px dari sidebar_pusat.php.
     Padding ekstra dari px-3 px-md-4 memakan ~48px yang bikin kolom terpotong di viewport standar. -->
<div class="container-fluid py-4" style="padding-left: 0; padding-right: 0;">

    <!-- ===== HERO STRIP ===== -->
    <div class="rs-hero mb-4">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-start align-items-lg-center gap-3" style="position: relative; z-index: 1;">
            <div>
                <div class="eyebrow">Revenue Sharing &bull; <?= strtoupper($nama_periode) ?></div>
                <div class="title">Admin Fee &amp; Service Fee Bulanan</div>
                <div class="desc">
                    <i class="bi bi-info-circle me-1"></i>
                    Admin Fee <strong>3%</strong> otomatis dari net profit keseluruhan. Service Fee dihitung dari <strong>50% sisi pengelola</strong> dengan presentase yang bisa dipilih per cabang.
                </div>
            </div>
            <form method="GET" class="d-flex flex-wrap gap-2" style="min-width: 280px;">
                <select name="bulan" class="form-select form-select-filter" style="flex:1; min-width: 130px;" onchange="this.form.submit()">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= $sel_bulan == $m ? 'selected' : '' ?>><?= nama_bulan_id($m) ?></option>
                    <?php endfor; ?>
                </select>
                <select name="tahun" class="form-select form-select-filter" style="flex:1; min-width: 100px;" onchange="this.form.submit()">
                    <?php for ($y = (int) date('Y') + 1; $y >= tahun_data_paling_lama($conn); $y--): ?>
                    <option value="<?= $y ?>" <?= $sel_tahun == $y ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </form>
        </div>
    </div>

    <!-- ===== TABEL ===== -->
    <!-- Wrapper HANYA untuk scroll — tanpa overflow:hidden supaya tidak motong kolom -->
    <div class="card border-0 mb-4" style="border-radius: 14px; box-shadow: 0 4px 24px rgba(0,0,0,0.05);">
        <div class="rs-scroll-hint">
            <i class="bi bi-arrow-left-right"></i>
            Geser ke samping untuk melihat kolom Presentase &amp; Status
        </div>
        <div class="table-responsive" style="border-radius: 14px;">
            <table class="table rs-table align-middle mb-0" id="tabelRevenueSharing">
                <colgroup>
                    <col class="rs-col-no">
                    <col class="rs-col-pgl">
                    <col class="rs-col-cbg">
                    <col class="rs-col-np">
                    <col class="rs-col-af">
                    <col class="rs-col-prs">
                    <col class="rs-col-sf">
                    <col class="rs-col-stt">
                </colgroup>
                <thead>
                    <tr>
                        <th class="rs-col-no">No</th>
                        <th class="rs-col-pgl">Pengelola</th>
                        <th class="rs-col-cbg">Cabang</th>
                        <th class="rs-col-np">Net Profit</th>
                        <th class="rs-col-af">Admin Fee</th>
                        <th class="rs-col-prs">Presentase</th>
                        <th class="rs-col-sf">Service Fee</th>
                        <th class="rs-col-stt">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($baris)): ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted py-5">
                            <i class="bi bi-inbox fs-2 d-block mb-2 opacity-50"></i>
                            Belum ada data untuk periode ini.
                        </td>
                    </tr>
                    <?php else:
                        $no = 1;
                        foreach ($baris as $b):
                            $row_id = 'rs-row-' . $b['id_cabang'];
                            $nom_id = 'rs-nom-'  . $b['id_cabang'];
                    ?>
                    <tr id="<?= $row_id ?>"
                        data-id-cabang="<?= $b['id_cabang'] ?>"
                        data-tahun="<?= $sel_tahun ?>"
                        data-bulan="<?= $sel_bulan ?>">
                        <td class="rs-col-no text-muted fw-semibold" data-label="No"><?= $no++ ?></td>
                        <td class="rs-col-pgl fw-bold" style="color:#0f172a;" data-label="Pengelola" title="<?= h($b['nama_pengelola']) ?>">
                            <i class="bi bi-person-badge me-1" style="color:#4f46e5;"></i><?= h($b['nama_pengelola']) ?>
                        </td>
                        <td class="rs-col-cbg" data-label="Cabang" title="<?= h($b['nama_cabang']) ?>"><?= h($b['nama_cabang']) ?></td>
                        <td class="rs-col-np text-muted" data-label="Net Profit">Rp <?= number_format($b['net_profit'], 0, ',', '.') ?></td>
                        <td class="rs-col-af" data-label="Admin Fee" style="color:#0f172a;">Rp <?= number_format($b['admin_fee'], 0, ',', '.') ?></td>
                        <td class="rs-col-prs" data-label="Presentase">
                            <div class="rs-btn-group" data-field="persen" role="group">
                                <?php foreach ([3.0, 5.0, 7.5] as $p):
                                    $active = abs($b['persen_service_fee'] - $p) < 0.01;
                                ?>
                                <button type="button"
                                    class="rs-btn <?= $active ? 'is-active' : '' ?>"
                                    data-value="<?= $p ?>"
                                    title="Service Fee <?= rtrim(rtrim(number_format($p, 2, ',', '.'), '0'), ',') ?>%">
                                    <?= rtrim(rtrim(number_format($p, 2, ',', '.'), '0'), ',') ?>%
                                </button>
                                <?php endforeach; ?>
                            </div>
                        </td>
                        <td class="rs-col-sf" id="<?= $nom_id ?>" data-label="Service Fee">Rp <?= number_format($b['nominal_service_fee'], 0, ',', '.') ?></td>
                        <td class="rs-col-stt" data-label="Status">
                            <div class="rs-btn-group" data-field="status" role="group">
                                <?php
                                $statuses = [
                                    'pending'      => 'Pending',
                                    'belum_lunas'  => 'Belum Lunas',
                                    'lunas'        => 'Lunas',
                                ];
                                foreach ($statuses as $key => $label):
                                    $active = $b['status_pembayaran'] === $key;
                                ?>
                                <button type="button"
                                    class="rs-btn status-<?= $key ?> <?= $active ? 'is-active' : '' ?>"
                                    data-value="<?= $key ?>">
                                    <?= $label ?>
                                </button>
                                <?php endforeach; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ===== FOOTER RINGKASAN ===== -->
    <div class="rs-summary mb-4">
        <div class="rs-summary-card tone-admin">
            <div class="label">Total Admin Fee</div>
            <div class="value">Rp <?= number_format($total_admin_fee_all, 0, ',', '.') ?></div>
            <div class="icon"><i class="bi bi-shield-check"></i></div>
        </div>
        <div class="rs-summary-card tone-service">
            <div class="label">Total Service Fee</div>
            <div class="value">Rp <?= number_format($total_service_fee_all, 0, ',', '.') ?></div>
            <div class="icon"><i class="bi bi-percent"></i></div>
        </div>
        <div class="rs-summary-card tone-total">
            <div class="label">Total Keseluruhan (Admin + Service)</div>
            <div class="value">Rp <?= number_format($total_keseluruhan, 0, ',', '.') ?></div>
            <div class="icon"><i class="bi bi-cash-stack"></i></div>
        </div>
    </div>

    <!-- ===== TOMBOL EXPORT ===== -->
    <div class="card border-0" style="border-radius: 14px; box-shadow: 0 4px 24px rgba(0,0,0,0.05);">
        <div class="card-body p-3">
            <div class="d-flex flex-column flex-md-row align-items-stretch align-items-md-center justify-content-between gap-3">
                <div class="text-muted small">
                    <i class="bi bi-info-circle me-1"></i>
                    <strong class="text-dark"><?= $jml_total ?></strong> cabang &mdash;
                    <span class="text-success fw-semibold"><?= $jml_lunas ?> lunas</span>,
                    <span class="text-danger fw-semibold"><?= $jml_belum_lunas ?> belum lunas</span>
                    <span class="ms-2 text-muted">| Net profit agregat: <strong>Rp <?= number_format($net_profit_total, 0, ',', '.') ?></strong></span>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <button type="button" onclick="exportExcel()" class="btn btn-export btn-export-excel" id="btnExportExcel">
                        <i class="bi bi-file-earmark-excel"></i> Export Excel
                    </button>
                    <button type="button" onclick="shareToWA()" class="btn btn-export btn-export-wa" id="btnShareWA">
                        <i class="bi bi-whatsapp"></i> Share ke WhatsApp
                    </button>
                    <button type="button" onclick="exportPDF()" class="btn btn-export btn-export-pdf" id="btnExportPDF">
                        <i class="bi bi-file-earmark-pdf"></i> Cetak PDF
                    </button>
                </div>
            </div>
        </div>
    </div>

</div>
</div>

<!-- Library PDF (jsPDF + autoTable) — konsisten dengan rekapitulasi.php -->
<script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jspdf-autotable@3.8.2/dist/jspdf.plugin.autotable.min.js"></script>

<script>
(function () {
    // ===== Inline edit presentase/status (delegation; tidak butuh global) =====
    const csrfToken  = <?= json_encode($csrf_token) ?>;
    const handlerUrl = 'revenue_sharing_handler.php';
    const fmtRp      = (n) => 'Rp ' + Math.round(n).toLocaleString('id-ID');

    document.querySelectorAll('.rs-btn-group').forEach((grp) => {
        grp.addEventListener('click', (ev) => {
            const btn = ev.target.closest('.rs-btn');
            if (!btn || btn.disabled) return;
            const tr     = btn.closest('tr');
            const field  = grp.dataset.field;
            const value  = btn.dataset.value;
            const idCab  = tr.dataset.idCabang;
            const tahun  = tr.dataset.tahun;
            const bulan  = tr.dataset.bulan;
            const aksi   = (field === 'persen') ? 'update_persen' : 'update_status';

            grp.querySelectorAll('.rs-btn').forEach((b) => b.disabled = true);

            const fd = new FormData();
            fd.append('csrf', csrfToken);
            fd.append('aksi', aksi);
            fd.append('id_cabang', idCab);
            fd.append('tahun', tahun);
            fd.append('bulan', bulan);
            if (field === 'persen') fd.append('persen_service_fee', value);
            else                    fd.append('status_pembayaran', value);

            fetch(handlerUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then((r) => r.json())
                .then((data) => {
                    grp.querySelectorAll('.rs-btn').forEach((b) => b.disabled = false);
                    if (!data.ok) { alert('Gagal: ' + (data.msg || 'unknown')); return; }
                    grp.querySelectorAll('.rs-btn').forEach((b) => {
                        b.classList.toggle('is-active', b.dataset.value === value);
                    });
                    const nomCell = document.getElementById('rs-nom-' + idCab);
                    if (nomCell && typeof data.nominal_service_fee === 'number') {
                        nomCell.textContent = fmtRp(data.nominal_service_fee);
                    }
                    tr.classList.remove('rs-flash');
                    void tr.offsetWidth;
                    tr.classList.add('rs-flash');
                })
                .catch((err) => {
                    grp.querySelectorAll('.rs-btn').forEach((b) => b.disabled = false);
                    alert('Gagal mengirim request: ' + err);
                });
        });
    });
})();

// ===== Export functions — di window scope supaya onclick= bisa reach =====
// Pola sama dengan rekapitulasi.php: jsPDF + autoTable untuk render tabel HTML.
window.sharePdfToWA = async function (doc, filename) {
    const teks  = filename.replace(/\.pdf$/i, '');
    const blob  = doc.output('blob');
    const file  = new File([blob], filename, { type: 'application/pdf' });
    if (navigator.canShare && navigator.canShare({ files: [file] })) {
        try { await navigator.share({ files: [file], title: 'Laporan WBB', text: teks }); return; }
        catch (e) { if (e && e.name === 'AbortError') return; }
    }
    // Fallback desktop: download PDF + buka wa.me dengan teks
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob); a.download = filename; a.click();
    window.open('https://wa.me/?text=' + encodeURIComponent(teks + ' (PDF terlampir, silakan unggah manual)'), '_blank');
};

// Pewarnaan sel khusus saat di-export ke PDF.
// Untuk kolom Presentase (idx 5) dan Status Pembayaran (idx 7), sel HTML berisi
// 3 tombol (rs-btn) di mana hanya 1 yang aktif (.is-active). Untuk PDF, kita:
//   1. Hanya render TEKS tombol aktif saja (override data.cell.text) — bukan semua
//      "3% 5% 7,5%" atau "Pending Belum Lunas Lunas" yang akan menyulitkan pembaca.
//   2. Warnai background sel sesuai warna sistem di UI agar visualnya konsisten:
//        - Presentase aktif  → bold, biru, teks hitam
//        - Status pending   → bold, oranye (amber-700), teks putih
//        - Status belum lunas → bold, merah (red-700), teks putih
//        - Status lunas     → bold, hijau (green-700), teks putih
window.rsDidParseCell = function (data) {
    if (data.section === 'head') {
        data.cell.styles.fillColor = [15, 23, 42];
        data.cell.styles.textColor = [255, 255, 255];
        return;
    }
    const tr = data.row && data.row.raw;
    const isFoot = data.section === 'foot' || !!(tr && typeof tr.closest === 'function' && tr.closest('tfoot'));
    if (isFoot) {
        data.cell.styles.fillColor = [22, 163, 74];
        data.cell.styles.textColor = [255, 255, 255];
        return;
    }
    const raw = data.cell.raw;
    const idx = data.column.index;

    // Kolom Service Fee (idx 6): merah kalau minus, default biru.
    if (idx === 6) {
        const teks = (data.cell.text || []).join(' ');
        data.cell.styles.textColor = teks.indexOf('-') !== -1 ? [220, 53, 69] : [14, 165, 233];
    }

    // Kolom Presentase (idx 5) & Status (idx 7): render hanya tombol aktif dengan style tombol.
    if ((idx === 5 || idx === 7) && raw && typeof raw.querySelector === 'function') {
        const active = raw.querySelector('.rs-btn.is-active');
        if (active) {
            data.cell.text = [active.textContent.trim()];
            data.cell.styles.fontStyle = 'bold';
            data.cell.styles.halign = 'center';

            // Presentase aktif: TANPA background warna (sesuai permintaan),
            // cuma teks tebal menampilkan nilai yang dipilih (3% / 5% / 7,5%).
            // Warna blok HANYA dipakai di kolom Status Pembayaran di bawah.
            if (idx === 7) {
                const status = active.dataset.value;
                if (status === 'pending') {
                    data.cell.styles.fillColor = [180, 83, 9];    // amber-700
                    data.cell.styles.textColor = [255, 255, 255]; // putih
                } else if (status === 'belum_lunas') {
                    data.cell.styles.fillColor = [185, 28, 28];   // red-700
                    data.cell.styles.textColor = [255, 255, 255]; // putih
                } else if (status === 'lunas') {
                    data.cell.styles.fillColor = [21, 128, 61];   // green-700
                    data.cell.styles.textColor = [255, 255, 255]; // putih
                }
            }
        }
    }
};

// Bangun dokumen PDF — dipakai oleh Cetak PDF & Share WA.
function buildPDFDoc() {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF('landscape', 'mm', 'a4');
    const filename = 'Revenue Sharing ' + <?= json_encode($nama_periode) ?> + '.pdf';

    // Kop
    doc.setFont('helvetica', 'bold'); doc.setFontSize(14); doc.setTextColor(15, 23, 42);
    doc.text('WARTEG BUMI BAHARI', 14, 14);
    doc.setFont('helvetica', 'normal'); doc.setFontSize(9); doc.setTextColor(100, 116, 139);
    doc.text('Revenue Sharing - ' + <?= json_encode($nama_periode) ?>, 14, 19);

    // Tabel utama (lebar kolom di-scale supaya muat di A4 landscape)
    doc.autoTable({
        html: '#tabelRevenueSharing',
        startY: 24,
        theme: 'grid',
        styles: { fontSize: 7.5, cellPadding: 1.4, overflow: 'linebreak', halign: 'left', valign: 'middle' },
        headStyles: { fillColor: [15, 23, 42], textColor: 255, halign: 'center', fontSize: 8, fontStyle: 'bold' },
        columnStyles: {
            0: { halign: 'center', cellWidth: 12 },
            3: { halign: 'right' },
            4: { halign: 'right' },
            5: { halign: 'center' },
            6: { halign: 'right' },
            7: { halign: 'center' },
        },
        didParseCell: window.rsDidParseCell,
        includeHiddenHtml: true,
    });

    const finalY = doc.lastAutoTable.finalY || 24;

    // Ringkasan total di bawah tabel
    doc.autoTable({
        body: [
            ['Total Admin Fee',         <?= json_encode('Rp ' . number_format($total_admin_fee_all, 0, ',', '.')) ?>],
            ['Total Service Fee',       <?= json_encode('Rp ' . number_format($total_service_fee_all, 0, ',', '.')) ?>],
            ['Total Keseluruhan',       <?= json_encode('Rp ' . number_format($total_keseluruhan, 0, ',', '.')) ?>],
        ],
        startY: finalY + 6,
        theme: 'plain',
        styles: { fontSize: 10, cellPadding: 2, fontStyle: 'bold' },
        columnStyles: { 0: { textColor: [100, 116, 139] }, 1: { halign: 'right', textColor: [21, 128, 61] } },
        tableWidth: 110,
    });

    return { doc, filename };
}

window.exportPDF = function () {
    try {
        const { doc, filename } = buildPDFDoc();
        doc.save(filename);
    } catch (e) {
        console.error('exportPDF error:', e);
        alert('Gagal membuat PDF: ' + e.message);
    }
};

window.shareToWA = async function () {
    try {
        const { doc, filename } = buildPDFDoc();
        await window.sharePdfToWA(doc, filename);
    } catch (e) {
        console.error('shareToWA error:', e);
        alert('Gagal membagikan ke WhatsApp: ' + e.message);
    }
};

window.exportExcel = function () {
    try {
        const rows = [['No', 'Nama Pengelola', 'Cabang', 'Net Profit', 'Admin Fee (3%)', 'Presentase Service Fee', 'Nominal Service Fee', 'Status Pembayaran']];
        const trs = document.querySelectorAll('#tabelRevenueSharing tbody tr');
        trs.forEach((tr) => {
            const cells = tr.querySelectorAll('td');
            if (cells.length < 8) return;
            const statusBtn = tr.querySelector('.rs-btn-group[data-field="status"] .rs-btn.is-active');
            const status = statusBtn ? statusBtn.textContent.trim() : '-';
            const persenBtn = tr.querySelector('.rs-btn-group[data-field="persen"] .rs-btn.is-active');
            const persen = persenBtn ? persenBtn.textContent.trim() : '-';
            rows.push([
                cells[0].textContent.trim(),
                cells[1].textContent.replace(/\s+/g, ' ').trim(),
                cells[2].textContent.trim(),
                cells[3].textContent.trim(),
                cells[4].textContent.trim(),
                persen,
                cells[6].textContent.trim(),
                status,
            ]);
        });
        // Baris total
        rows.push([]);
        rows.push(['', '', '', '', 'TOTAL ADMIN FEE',         <?= json_encode('Rp ' . number_format($total_admin_fee_all, 0, ',', '.')) ?>, '', '']);
        rows.push(['', '', '', '', 'TOTAL SERVICE FEE',       <?= json_encode('Rp ' . number_format($total_service_fee_all, 0, ',', '.')) ?>, '', '']);
        rows.push(['', '', '', '', 'TOTAL KESELURUHAN',       <?= json_encode('Rp ' . number_format($total_keseluruhan, 0, ',', '.')) ?>, '', '']);

        // BOM supaya Excel Indonesia auto-detect UTF-8
        const csv = '﻿' + rows.map((r) => r.map((c) => {
            const s = String(c).replace(/"/g, '""');
            return /[",\n]/.test(s) ? '"' + s + '"' : s;
        }).join(',')).join('\r\n');

        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'Revenue Sharing ' + <?= json_encode($nama_periode) ?> + '.csv';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        setTimeout(() => URL.revokeObjectURL(a.href), 1000);
    } catch (e) {
        console.error('exportExcel error:', e);
        alert('Gagal membuat Excel: ' + e.message);
    }
};
</script>

<?php include '../config/notifikasi_bell.php'; ?>