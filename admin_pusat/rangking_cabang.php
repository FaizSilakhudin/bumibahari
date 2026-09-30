<?php
/**
 * RANGKING CABANG — admin_pusat
 *
 * Peringkat semua cabang per periode (bulan/tahun) berdasarkan Poin Performa
 * = %share Omzet + %share Net Profit terhadap total seluruh cabang. Rumus
 * SAMA PERSIS dengan kartu "Ranking Cabang" di dashboard (index.php) --
 * dipakai lewat helper bersama hitung_poin_ranking_cabang() di koneksi.php.
 *
 * Beda dengan kartu dashboard (yang ringkas & terbatas tinggi scroll-nya),
 * halaman ini menampilkan SEMUA cabang tanpa filter cabang/investor, dengan
 * kolom Omzet, Point Omzet, Net Profit, Point Net Profit, Jumlah Poin secara
 * eksplisit, plus Cetak PDF & Kirim ke WA (pola sama dengan revenue_sharing.php).
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

// Anchor untuk pengelola_pada_tanggal — akhir bulan dibatasi hari ini.
$akhir_periode   = date('Y-m-t', strtotime("$sel_tahun-$sel_bulan-01"));
$periode_anchor  = anchor_periode($akhir_periode);

// ----- Query: 1 baris per cabang, agregat omzet + net profit periode terpilih -----
$ranking_cabang = [];
$st = $conn->prepare("SELECT c.id_cabang, c.nama_cabang,
        COALESCE(SUM(l.total_omset), 0) total_omset,
        COALESCE(SUM(l.net_profit), 0) total_net_profit
    FROM cabang c
    LEFT JOIN laporan_cabang l ON l.id_cabang = c.id_cabang
        AND YEAR(l.tanggal) = ? AND MONTH(l.tanggal) = ? AND l.status_laporan = 'lengkap'
    GROUP BY c.id_cabang, c.nama_cabang
    ORDER BY c.nama_cabang ASC");
$st->bind_param('ii', $sel_tahun, $sel_bulan);
$st->execute();
$res = $st->get_result();
while ($row = $res->fetch_assoc()) {
    // Pengelola PADA PERIODE terpilih, bukan kolom statis cabang.nama_pengelola.
    $row['nama_pengelola'] = pengelola_pada_tanggal($conn, (int) $row['id_cabang'], $periode_anchor);
    $ranking_cabang[] = $row;
}
$st->close();

// Poin performa = %share omzet + %share net profit thd total seluruh cabang
// (helper bersama dengan dashboard, lihat config/koneksi.php).
$ranking_cabang = hitung_poin_ranking_cabang($ranking_cabang);

$total_omzet_all      = 0.0;
$total_net_profit_all = 0.0;
foreach ($ranking_cabang as $r) {
    $total_omzet_all      += (float) $r['total_omset'];
    $total_net_profit_all += (float) $r['total_net_profit'];
}
$jml_cabang   = count($ranking_cabang);
$juara_satu   = $ranking_cabang[0]['nama_cabang'] ?? '-';
?>

<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

<style>
  body { font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif; background-color: #f1f5f9; color: #0f172a; }

  /* ===== Header strip — nuansa emas/trofi, beda dari Revenue Sharing (biru) ===== */
  .rk-hero {
      background: linear-gradient(135deg, #78350f 0%, #b45309 55%, #d97706 100%);
      color: #fff; border-radius: 16px; padding: 22px 26px;
      box-shadow: 0 12px 32px -10px rgba(120, 53, 15, .4);
      position: relative; overflow: hidden;
  }
  .rk-hero::before {
      content: ""; position: absolute; top: -40px; right: -40px;
      width: 180px; height: 180px; border-radius: 50%;
      background: radial-gradient(circle, rgba(255,255,255,.18) 0%, transparent 70%);
  }
  .rk-hero .eyebrow { font-size: 11px; letter-spacing: 1.5px; text-transform: uppercase; color: rgba(255,255,255,.75); font-weight: 700; }
  .rk-hero .title    { font-size: 24px; font-weight: 800; letter-spacing: -.5px; margin-top: 4px; }
  .rk-hero .desc     { font-size: 12.5px; color: rgba(255,255,255,.8); font-weight: 500; margin-top: 6px; max-width: 620px; }

  .form-select-filter {
      border-radius: 10px; border: 1px solid rgba(255,255,255,.25);
      padding: 8px 14px; font-size: 13.5px; font-weight: 600; color: #fff;
      background-color: rgba(255,255,255,.12); backdrop-filter: blur(4px);
  }
  .form-select-filter option { color: #0f172a; background: #fff; }
  .form-select-filter:focus  { border-color: rgba(255,255,255,.6); box-shadow: 0 0 0 3px rgba(255,255,255,.15); }

  /* ===== Tabel compact fixed-width ===== */
  .rk-table {
      table-layout: fixed; border-collapse: separate; border-spacing: 0;
      width: 100%; max-width: 100%;
  }
  .rk-table thead th {
      background: #f8fafc !important;
      color: #475569 !important;
      font-size: 10px !important; text-transform: uppercase; letter-spacing: 0.6px;
      font-weight: 800; padding: 12px 10px !important;
      border-bottom: 2px solid #e2e8f0 !important; white-space: nowrap;
      position: sticky; top: 0; z-index: 2;
  }
  .rk-table tbody td {
      padding: 10px !important;
      border-bottom: 1px solid #f1f5f9 !important;
      font-size: 12px !important;
      vertical-align: middle;
      white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  }
  .rk-table tbody tr:hover td { background: #fffbeb !important; }
  .rk-table tbody tr:last-child td { border-bottom: none !important; }
  .rk-table tbody tr.rk-top1 td { background: #fffbeb !important; }
  .rk-table tbody tr.rk-top1:hover td { background: #fef3c7 !important; }

  .rk-col-no   { width: 44px;  text-align: center; }
  .rk-col-cbg  { width: 150px; }
  .rk-col-pgl  { width: 130px; }
  .rk-col-omz  { width: 115px; text-align: right; }
  .rk-col-pomz { width: 95px;  text-align: center; }
  .rk-col-np   { width: 115px; text-align: right; }
  .rk-col-pnp  { width: 95px;  text-align: center; }
  .rk-col-poin { width: 100px; text-align: center; }

  .rk-medal { display: inline-flex; align-items: center; justify-content: center; width: 26px; height: 26px; border-radius: 50%; font-weight: 800; font-size: 12px; }
  .rk-medal-1 { background: #fde68a; color: #92400e; }
  .rk-medal-2 { background: #e2e8f0; color: #475569; }
  .rk-medal-3 { background: #fed7aa; color: #9a3412; }
  .rk-medal-n { color: #94a3b8; font-weight: 700; }

  .rk-pct-badge {
      display: inline-block; padding: 2px 9px; border-radius: 20px; font-size: 11px;
      font-weight: 700; background: #eff6ff; color: #1d4ed8;
  }
  .rk-pct-badge.tone-np { background: #f0fdf4; color: #15803d; }
  .rk-poin-total { font-weight: 800; font-size: 14px; color: #7c3aed; }

  /* ===== Footer ringkasan ===== */
  .rk-summary { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; }
  @media (max-width: 767.98px) { .rk-summary { grid-template-columns: 1fr; } }

  .rk-summary-card {
      background: #fff; border: 1px solid #e2e8f0; border-radius: 14px;
      padding: 16px 18px; position: relative; overflow: hidden;
      transition: transform .2s ease, box-shadow .2s ease;
  }
  .rk-summary-card:hover { transform: translateY(-2px); box-shadow: 0 12px 24px -10px rgba(15,23,42,.15); }
  .rk-summary-card .label { font-size: 10.5px; letter-spacing: 0.8px; text-transform: uppercase; color: #64748b; font-weight: 800; }
  .rk-summary-card .value { font-size: 20px; font-weight: 800; color: #0f172a; letter-spacing: -.5px; margin-top: 6px; }
  .rk-summary-card .icon  {
      position: absolute; right: 16px; top: 50%; transform: translateY(-50%);
      width: 42px; height: 42px; border-radius: 12px; display: flex; align-items: center;
      justify-content: center; color: #fff; font-size: 20px;
  }
  .rk-summary-card.tone-omzet  .icon { background: linear-gradient(135deg, #0ea5e9, #0284c7); }
  .rk-summary-card.tone-omzet  .value { color: #0369a1; }
  .rk-summary-card.tone-profit .icon { background: linear-gradient(135deg, #16a34a, #15803d); }
  .rk-summary-card.tone-profit .value { color: #15803d; }
  .rk-summary-card.tone-juara  .icon { background: linear-gradient(135deg, #d97706, #92400e); }
  .rk-summary-card.tone-juara  .value { color: #92400e; font-size: 16px; }

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

  .rk-scroll-hint {
      display: none;
      font-size: 11px; color: #64748b; padding: 6px 12px;
      background: #f1f5f9; border-bottom: 1px solid #e2e8f0;
      align-items: center; gap: 6px;
  }
  @media (max-width: 1279.98px) { .rk-scroll-hint { display: flex; } }

  @media (max-width: 767.98px) {
      .rk-table { min-width: 0; }
      .rk-table thead { display: none; }
      .rk-table tbody tr { display: block; border: 1px solid #e2e8f0; border-radius: 12px; margin: 10px 0; padding: 12px; background: #fff; }
      .rk-table tbody td {
          display: flex; justify-content: space-between; align-items: center;
          padding: 7px 0 !important; border-bottom: 1px dashed #f1f5f9 !important;
          white-space: normal; text-align: left !important;
          overflow: visible; text-overflow: clip;
      }
      .rk-table tbody td::before {
          content: attr(data-label); font-weight: 700; color: #64748b;
          font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.5px;
          flex-shrink: 0; margin-right: 12px;
      }
      .rk-table tbody td:last-child { border-bottom: none !important; }
      .rk-col-no, .rk-col-cbg, .rk-col-pgl, .rk-col-omz, .rk-col-pomz, .rk-col-np, .rk-col-pnp, .rk-col-poin { width: auto; }
  }
</style>

<div class="content">
<div class="container-fluid py-4" style="padding-left: 0; padding-right: 0;">

    <!-- ===== HERO STRIP ===== -->
    <div class="rk-hero mb-4">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-start align-items-lg-center gap-3" style="position: relative; z-index: 1;">
            <div>
                <div class="eyebrow">Rangking Cabang &bull; <?= strtoupper($nama_periode) ?></div>
                <div class="title"><i class="bi bi-trophy-fill me-1"></i> Peringkat Performa Cabang</div>
                <div class="desc">
                    <i class="bi bi-info-circle me-1"></i>
                    Poin performa = <strong>%share Omzet</strong> + <strong>%share Net Profit</strong> cabang tersebut terhadap total seluruh cabang pada periode ini. Cabang dengan Jumlah Poin tertinggi ada di urutan pertama.
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
    <div class="card border-0 mb-4" style="border-radius: 14px; box-shadow: 0 4px 24px rgba(0,0,0,0.05);">
        <div class="rk-scroll-hint">
            <i class="bi bi-arrow-left-right"></i>
            Geser ke samping untuk melihat semua kolom
        </div>
        <div class="table-responsive" style="border-radius: 14px;">
            <table class="table rk-table align-middle mb-0" id="tabelRangkingCabang">
                <colgroup>
                    <col class="rk-col-no">
                    <col class="rk-col-cbg">
                    <col class="rk-col-pgl">
                    <col class="rk-col-omz">
                    <col class="rk-col-pomz">
                    <col class="rk-col-np">
                    <col class="rk-col-pnp">
                    <col class="rk-col-poin">
                </colgroup>
                <thead>
                    <tr>
                        <th class="rk-col-no">No</th>
                        <th class="rk-col-cbg">Cabang</th>
                        <th class="rk-col-pgl">Pengelola</th>
                        <th class="rk-col-omz">Omzet</th>
                        <th class="rk-col-pomz">Point Omzet</th>
                        <th class="rk-col-np">Net Profit</th>
                        <th class="rk-col-pnp">Point Net Profit</th>
                        <th class="rk-col-poin">Jumlah Poin</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($ranking_cabang)): ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted py-5">
                            <i class="bi bi-inbox fs-2 d-block mb-2 opacity-50"></i>
                            Belum ada data untuk periode ini.
                        </td>
                    </tr>
                    <?php else: foreach ($ranking_cabang as $r): ?>
                    <tr class="<?= $r['no'] === 1 ? 'rk-top1' : '' ?>">
                        <td class="rk-col-no" data-label="No">
                            <?php if ($r['no'] === 1): ?>
                                <span class="rk-medal rk-medal-1">1</span>
                            <?php elseif ($r['no'] === 2): ?>
                                <span class="rk-medal rk-medal-2">2</span>
                            <?php elseif ($r['no'] === 3): ?>
                                <span class="rk-medal rk-medal-3">3</span>
                            <?php else: ?>
                                <span class="rk-medal-n"><?= $r['no'] ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="rk-col-cbg fw-bold" style="color:#0f172a;" data-label="Cabang" title="<?= h($r['nama_cabang']) ?>"><?= h($r['nama_cabang']) ?></td>
                        <td class="rk-col-pgl text-muted" data-label="Pengelola" title="<?= h($r['nama_pengelola']) ?>"><?= h($r['nama_pengelola']) ?></td>
                        <td class="rk-col-omz" data-label="Omzet" style="color:#0369a1; font-weight:700;">Rp <?= number_format($r['total_omset'], 0, ',', '.') ?></td>
                        <td class="rk-col-pomz" data-label="Point Omzet"><span class="rk-pct-badge"><?= number_format($r['pct_omzet'], 1, ',', '.') ?></span></td>
                        <td class="rk-col-np" data-label="Net Profit" style="color:<?= $r['total_net_profit'] >= 0 ? '#15803d' : '#dc2626' ?>; font-weight:700;">Rp <?= number_format($r['total_net_profit'], 0, ',', '.') ?></td>
                        <td class="rk-col-pnp" data-label="Point Net Profit"><span class="rk-pct-badge tone-np"><?= number_format($r['pct_net_profit'], 1, ',', '.') ?></span></td>
                        <td class="rk-col-poin" data-label="Jumlah Poin"><span class="rk-poin-total"><?= number_format($r['poin'], 1, ',', '.') ?></span></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ===== FOOTER RINGKASAN ===== -->
    <div class="rk-summary mb-4">
        <div class="rk-summary-card tone-omzet">
            <div class="label">Total Omzet Seluruh Cabang</div>
            <div class="value">Rp <?= number_format($total_omzet_all, 0, ',', '.') ?></div>
            <div class="icon"><i class="bi bi-graph-up-arrow"></i></div>
        </div>
        <div class="rk-summary-card tone-profit">
            <div class="label">Total Net Profit Seluruh Cabang</div>
            <div class="value">Rp <?= number_format($total_net_profit_all, 0, ',', '.') ?></div>
            <div class="icon"><i class="bi bi-cash-stack"></i></div>
        </div>
        <div class="rk-summary-card tone-juara">
            <div class="label">Juara 1 Periode Ini</div>
            <div class="value"><i class="bi bi-trophy-fill me-1"></i><?= h($juara_satu) ?></div>
            <div class="icon"><i class="bi bi-award-fill"></i></div>
        </div>
    </div>

    <!-- ===== TOMBOL EXPORT ===== -->
    <div class="card border-0" style="border-radius: 14px; box-shadow: 0 4px 24px rgba(0,0,0,0.05);">
        <div class="card-body p-3">
            <div class="d-flex flex-column flex-md-row align-items-stretch align-items-md-center justify-content-between gap-3">
                <div class="text-muted small">
                    <i class="bi bi-info-circle me-1"></i>
                    <strong class="text-dark"><?= $jml_cabang ?></strong> cabang dirangking untuk periode <strong><?= h($nama_periode) ?></strong>
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

<!-- Library PDF (jsPDF + autoTable) — konsisten dengan revenue_sharing.php/rekapitulasi.php -->
<script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jspdf-autotable@3.8.2/dist/jspdf.plugin.autotable.min.js"></script>

<script>
// Pola sama dengan revenue_sharing.php: jsPDF + autoTable untuk render tabel HTML.
window.sharePdfToWA = async function (doc, filename) {
    const teks  = filename.replace(/\.pdf$/i, '');
    const blob  = doc.output('blob');
    const file  = new File([blob], filename, { type: 'application/pdf' });
    if (navigator.canShare && navigator.canShare({ files: [file] })) {
        try { await navigator.share({ files: [file], title: 'Rangking Cabang WBB', text: teks }); return; }
        catch (e) { if (e && e.name === 'AbortError') return; }
    }
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob); a.download = filename; a.click();
    window.open('https://wa.me/?text=' + encodeURIComponent(teks + ' (PDF terlampir, silakan unggah manual)'), '_blank');
};

// Pewarnaan sel khusus saat export ke PDF: medali No.1-3, badge Point, Jumlah Poin ungu bold.
window.rkDidParseCell = function (data) {
    if (data.section === 'head') {
        data.cell.styles.fillColor = [120, 53, 15];
        data.cell.styles.textColor = [255, 255, 255];
        return;
    }
    const idx = data.column.index;
    const raw = data.cell.raw;

    if (idx === 0 && raw && typeof raw.querySelector === 'function') {
        const medal = raw.querySelector('.rk-medal, .rk-medal-n');
        if (medal) {
            data.cell.text = [medal.textContent.trim()];
            data.cell.styles.halign = 'center';
            data.cell.styles.fontStyle = 'bold';
            if (medal.classList.contains('rk-medal-1')) { data.cell.styles.fillColor = [253, 230, 138]; data.cell.styles.textColor = [146, 64, 14]; }
            else if (medal.classList.contains('rk-medal-2')) { data.cell.styles.fillColor = [226, 232, 240]; data.cell.styles.textColor = [71, 85, 105]; }
            else if (medal.classList.contains('rk-medal-3')) { data.cell.styles.fillColor = [254, 215, 170]; data.cell.styles.textColor = [154, 52, 18]; }
        }
    }
    if (idx === 3) { data.cell.styles.textColor = [3, 105, 161]; data.cell.styles.fontStyle = 'bold'; }
    if (idx === 4 || idx === 6) {
        if (raw && typeof raw.querySelector === 'function') {
            const badge = raw.querySelector('.rk-pct-badge');
            if (badge) data.cell.text = [badge.textContent.trim()];
        }
        data.cell.styles.halign = 'center';
        data.cell.styles.textColor = idx === 4 ? [29, 78, 216] : [21, 128, 61];
        data.cell.styles.fontStyle = 'bold';
    }
    if (idx === 5) {
        const teks = (data.cell.text || []).join(' ');
        data.cell.styles.textColor = teks.indexOf('-') !== -1 ? [220, 53, 69] : [21, 128, 61];
        data.cell.styles.fontStyle = 'bold';
    }
    if (idx === 7) {
        if (raw && typeof raw.querySelector === 'function') {
            const total = raw.querySelector('.rk-poin-total');
            if (total) data.cell.text = [total.textContent.trim()];
        }
        data.cell.styles.halign = 'center';
        data.cell.styles.textColor = [124, 58, 237];
        data.cell.styles.fontStyle = 'bold';
        data.cell.styles.fontSize = 9;
    }
};

function buildPDFDoc() {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF('landscape', 'mm', 'a4');
    const filename = 'Rangking Cabang ' + <?= json_encode($nama_periode) ?> + '.pdf';

    doc.setFont('helvetica', 'bold'); doc.setFontSize(14); doc.setTextColor(15, 23, 42);
    doc.text('WARTEG BUMI BAHARI', 14, 14);
    doc.setFont('helvetica', 'normal'); doc.setFontSize(9); doc.setTextColor(100, 116, 139);
    doc.text('Rangking Cabang - ' + <?= json_encode($nama_periode) ?>, 14, 19);

    doc.autoTable({
        html: '#tabelRangkingCabang',
        startY: 24,
        theme: 'grid',
        styles: { fontSize: 8, cellPadding: 1.6, overflow: 'linebreak', halign: 'left', valign: 'middle' },
        headStyles: { fillColor: [120, 53, 15], textColor: 255, halign: 'center', fontSize: 8.5, fontStyle: 'bold' },
        columnStyles: {
            0: { halign: 'center', cellWidth: 12 },
            3: { halign: 'right' },
            4: { halign: 'center' },
            5: { halign: 'right' },
            6: { halign: 'center' },
            7: { halign: 'center' },
        },
        didParseCell: window.rkDidParseCell,
        includeHiddenHtml: true,
    });

    const finalY = doc.lastAutoTable.finalY || 24;

    doc.autoTable({
        body: [
            ['Total Omzet Seluruh Cabang',      <?= json_encode('Rp ' . number_format($total_omzet_all, 0, ',', '.')) ?>],
            ['Total Net Profit Seluruh Cabang', <?= json_encode('Rp ' . number_format($total_net_profit_all, 0, ',', '.')) ?>],
            ['Juara 1 Periode Ini',             <?= json_encode($juara_satu) ?>],
        ],
        startY: finalY + 6,
        theme: 'plain',
        styles: { fontSize: 10, cellPadding: 2, fontStyle: 'bold' },
        columnStyles: { 0: { textColor: [100, 116, 139] }, 1: { halign: 'right', textColor: [146, 64, 14] } },
        tableWidth: 130,
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
        const rows = [['No', 'Cabang', 'Pengelola', 'Omzet', 'Point Omzet', 'Net Profit', 'Point Net Profit', 'Jumlah Poin']];
        const trs = document.querySelectorAll('#tabelRangkingCabang tbody tr');
        trs.forEach((tr) => {
            const cells = tr.querySelectorAll('td');
            if (cells.length < 8) return;
            rows.push([
                cells[0].textContent.trim(),
                cells[1].textContent.trim(),
                cells[2].textContent.trim(),
                cells[3].textContent.trim(),
                cells[4].textContent.trim(),
                cells[5].textContent.trim(),
                cells[6].textContent.trim(),
                cells[7].textContent.trim(),
            ]);
        });
        rows.push([]);
        rows.push(['', '', '', 'TOTAL OMZET',      <?= json_encode('Rp ' . number_format($total_omzet_all, 0, ',', '.')) ?>, '', '', '']);
        rows.push(['', '', '', 'TOTAL NET PROFIT', <?= json_encode('Rp ' . number_format($total_net_profit_all, 0, ',', '.')) ?>, '', '', '']);
        rows.push(['', '', '', 'JUARA 1',          <?= json_encode($juara_satu) ?>, '', '', '']);

        const csv = '﻿' + rows.map((r) => r.map((c) => {
            const s = String(c).replace(/"/g, '""');
            return /[",\n]/.test(s) ? '"' + s + '"' : s;
        }).join(',')).join('\r\n');

        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'Rangking Cabang ' + <?= json_encode($nama_periode) ?> + '.csv';
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
