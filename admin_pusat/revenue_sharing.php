<?php
/**
 * REVENUE SHARING — admin_pusat
 *
 * Laporan bulanan admin fee (3% dari net profit keseluruhan, fixed) + service fee
 * (3/5/7,5% dari sisi pengelola, bisa dipilih per cabang per bulan) +
 * status pembayaran (pending / belum lunas / lunas, editable inline).
 *
 * Tampilan: 1 halaman = semua cabang (tanpa pagination), compact columns,
 * footer Total Admin Fee + Total Service Fee + Total Keseluruhan, dan
 * tombol bawah: Export PDF / Excel / Share WA (pola sama dengan rekapitulasi).
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
$jml_belum_lunas   = 0;
foreach ($baris as $b) if ($b['status_pembayaran'] !== 'lunas') $jml_belum_lunas++;

// ----- Token CSRF untuk FormData AJAX -----
$csrf_token = csrf_token();
?>

<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

<style>
  body { font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif; background-color: #f8fafc; color: #0f172a; }

  /* Hero ringkas — bukan full-bleed, hanya sebagai strip informasi */
  .rs-hero {
      background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
      color: #fff;
      border-radius: 14px;
      padding: 18px 22px;
      box-shadow: 0 10px 30px -10px rgba(15, 23, 42, .35);
  }
  .rs-hero .label { font-size: 11px; letter-spacing: 1px; text-transform: uppercase; color: rgba(255,255,255,.55); font-weight: 700; }
  .rs-hero .value { font-size: 22px; font-weight: 800; letter-spacing: -.5px; }
  .rs-hero .sub   { font-size: 12px; color: rgba(255,255,255,.65); font-weight: 500; }

  /* KPI ringkas untuk footer (Total Admin Fee / Service Fee / Keseluruhan) */
  .rs-summary {
      display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px;
  }
  @media (max-width: 767.98px) { .rs-summary { grid-template-columns: 1fr; } }
  .rs-summary-card {
      background: #fff; border: 1px solid #e2e8f0; border-radius: 12px;
      padding: 14px 16px; position: relative; overflow: hidden;
  }
  .rs-summary-card .label { font-size: 11px; letter-spacing: 0.5px; text-transform: uppercase; color: #64748b; font-weight: 700; }
  .rs-summary-card .value { font-size: 20px; font-weight: 800; color: #0f172a; letter-spacing: -.5px; margin-top: 4px; }
  .rs-summary-card .icon  {
      position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
      width: 38px; height: 38px; border-radius: 10px; display: flex; align-items: center;
      justify-content: center; color: #fff; font-size: 18px;
  }
  .rs-summary-card.tone-admin .icon { background: linear-gradient(135deg, #f59e0b, #d97706); }
  .rs-summary-card.tone-service .icon { background: linear-gradient(135deg, #0ea5e9, #0284c7); }
  .rs-summary-card.tone-total .icon { background: linear-gradient(135deg, #16a34a, #15803d); }
  .rs-summary-card.tone-total .value { color: #15803d; }

  /* Filter dropdown — disamakan dengan halaman dashboard pusat */
  .form-select-filter {
      border-radius: 10px; border: 1px solid #e2e8f0;
      padding: 8px 14px; font-size: 13.5px; font-weight: 600; color: #0f172a;
      background-color: #fff; box-shadow: 0 2px 6px rgba(0,0,0,.02);
  }
  .form-select-filter:focus { border-color: #4318ff; box-shadow: 0 0 0 3px rgba(67,24,255,.15); }

  /* Tabel compact — 100+ cabang muat dalam 1 halaman tanpa ngelag */
  .rs-table thead th {
      background: #0f172a !important;
      color: #fff !important;
      font-size: 10.5px !important;
      text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700;
      padding: 10px 12px !important;
      border-bottom: 1px solid #1e293b !important;
      white-space: nowrap;
  }
  .rs-table tbody td {
      padding: 8px 12px !important;
      border-bottom: 1px solid #f1f5f9 !important;
      font-size: 12.5px !important;
      white-space: nowrap;
      vertical-align: middle;
  }
  .rs-table tbody tr:hover td { background: #f8fafc !important; }

  /* Tombol presentase (3/5/7,5) & status — radio-button look inline */
  .rs-btn-group { display: inline-flex; gap: 3px; }
  .rs-btn {
      border: 1px solid #cbd5e1; background: #fff; color: #475569;
      font-weight: 700; font-size: 11px;
      padding: 4px 9px; border-radius: 6px; cursor: pointer; transition: all .15s ease;
      min-width: 36px; text-align: center;
  }
  .rs-btn:hover { background: #f1f5f9; border-color: #94a3b8; }
  .rs-btn.is-active { background: #4318ff; border-color: #4318ff; color: #fff; box-shadow: 0 3px 8px -2px rgba(67,24,255,.4); }
  .rs-btn.is-active:hover { background: #3311db; }
  .rs-btn[disabled] { opacity: .55; cursor: wait; }

  .status-pending     { background: #fffbeb; color: #d97706; border-color: #fde68a; }
  .status-pending.is-active { background: #d97706; border-color: #d97706; color: #fff; box-shadow: 0 3px 8px -2px rgba(217,119,6,.4); }
  .status-belum_lunas { background: #fef2f2; color: #dc2626; border-color: #fecaca; }
  .status-belum_lunas.is-active { background: #dc2626; border-color: #dc2626; color: #fff; box-shadow: 0 3px 8px -2px rgba(220,38,38,.4); }
  .status-lunas       { background: #f0fdf4; color: #16a34a; border-color: #bbf7d0; }
  .status-lunas.is-active { background: #16a34a; border-color: #16a34a; color: #fff; box-shadow: 0 3px 8px -2px rgba(22,163,74,.4); }

  tr.rs-flash { animation: rsFlash 600ms ease-out; }
  @keyframes rsFlash {
      0%   { background: #dcfce7; }
      100% { background: transparent; }
  }

  /* Tombol export di bawah tabel */
  .btn-export {
      border-radius: 10px; padding: 10px 18px; font-weight: 700;
      font-size: 13px; display: inline-flex; align-items: center; gap: 8px;
      transition: all .15s ease; box-shadow: 0 2px 6px rgba(0,0,0,.04);
  }
  .btn-export:hover { transform: translateY(-1px); box-shadow: 0 6px 14px rgba(0,0,0,.08); }

  /* Mobile: tumpuk jadi kartu */
  @media (max-width: 767.98px) {
      .rs-table thead { display: none; }
      .rs-table tbody tr { display: block; border: 1px solid #e2e8f0; border-radius: 12px; margin: 10px 0; padding: 12px; background: #fff; }
      .rs-table tbody td { display: flex; justify-content: space-between; align-items: center; padding: 7px 0 !important; border-bottom: 1px dashed #f1f5f9 !important; white-space: normal; }
      .rs-table tbody td::before { content: attr(data-label); font-weight: 700; color: #64748b; font-size: 10.5px; text-transform: uppercase; }
      .rs-table tbody td:last-child { border-bottom: none !important; }
  }
</style>

<div class="content">
<div class="container-fluid py-4 px-3 px-md-4">

    <!-- HERO RINGKAS + FILTER SEJAJAR -->
    <div class="rs-hero mb-3 d-flex flex-column flex-lg-row justify-content-between align-items-start align-items-lg-center gap-3">
        <div>
            <div class="label">Revenue Sharing &bull; <?= strtoupper($nama_periode) ?></div>
            <div class="value mt-1">Admin Fee &amp; Service Fee Bulanan</div>
            <div class="sub mt-1"><i class="bi bi-info-circle me-1"></i> Admin Fee <strong>3%</strong> otomatis dari net profit. Service Fee dari <strong>50% sisi pengelola</strong>, presentase dipilih per cabang.</div>
        </div>
        <form method="GET" class="d-flex flex-wrap gap-2" style="min-width: 280px;">
            <select name="bulan" class="form-select form-select-filter" style="flex:1; min-width: 120px;" onchange="this.form.submit()">
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

    <!-- TABEL -->
    <div class="card border-0 mb-3" style="overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.04);">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table rs-table align-middle mb-0" id="tabelRevenueSharing">
                    <thead>
                        <tr>
                            <th style="width: 40px;">No</th>
                            <th>Nama Pengelola</th>
                            <th>Cabang</th>
                            <th class="text-end">Net Profit</th>
                            <th class="text-end">Admin Fee (3%)</th>
                            <th class="text-center" style="width: 170px;">Presentase</th>
                            <th class="text-end">Nominal Service Fee</th>
                            <th class="text-center" style="width: 290px;">Status Pembayaran</th>
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
                            <td data-label="No" class="fw-semibold text-muted"><?= $no++ ?></td>
                            <td data-label="Pengelola" class="fw-bold" style="color:#0f172a;">
                                <i class="bi bi-person-badge me-1 text-primary" style="color:#4318ff!important;"></i>
                                <?= h($b['nama_pengelola']) ?>
                            </td>
                            <td data-label="Cabang"><?= h($b['nama_cabang']) ?></td>
                            <td data-label="Net Profit" class="text-end text-muted">
                                Rp <?= number_format($b['net_profit'], 0, ',', '.') ?>
                            </td>
                            <td data-label="Admin Fee" class="text-end fw-bold" style="color:#0f172a;">
                                Rp <?= number_format($b['admin_fee'], 0, ',', '.') ?>
                            </td>
                            <td data-label="Presentase" class="text-center">
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
                            <td data-label="Service Fee" id="<?= $nom_id ?>" class="text-end fw-bold" style="color:#0ea5e9;">
                                Rp <?= number_format($b['nominal_service_fee'], 0, ',', '.') ?>
                            </td>
                            <td data-label="Status" class="text-center">
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
    </div>

    <!-- FOOTER RINGKASAN TOTAL -->
    <div class="rs-summary mb-3">
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

    <!-- TOMBOL EXPORT DI BAWAH (sama gaya dengan rekapitulasi.php) -->
    <div class="card border-0" style="box-shadow: 0 4px 20px rgba(0,0,0,0.04);">
        <div class="card-body p-3">
            <div class="d-flex flex-column flex-md-row align-items-stretch align-items-md-center justify-content-between gap-3">
                <div class="text-muted small">
                    <i class="bi bi-info-circle me-1"></i>
                    <?= $jml_total ?> cabang &mdash;
                    <span class="text-success fw-semibold"><?= $jml_lunas ?> lunas</span>,
                    <span class="text-warning fw-semibold"><?= $jml_total - $jml_lunas ?> belum lunas</span>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <button type="button" onclick="exportExcel()" class="btn btn-outline-success btn-export">
                        <i class="bi bi-file-earmark-excel"></i> Export Excel
                    </button>
                    <button type="button" onclick="exportPDF('share')" class="btn btn-outline-danger btn-export" title="Cetak PDF lalu bagikan via WhatsApp">
                        <i class="bi bi-whatsapp"></i> Share ke WhatsApp
                    </button>
                    <button type="button" onclick="exportPDF('save')" class="btn btn-danger btn-export">
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
    const csrfToken  = <?= json_encode($csrf_token) ?>;
    const handlerUrl = 'revenue_sharing_handler.php';
    const fmtRp      = (n) => 'Rp ' + Math.round(n).toLocaleString('id-ID');

    // ---- Inline edit presentase/status ----
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

    // ---- Helper: ambil nama file export ----
    const namaPeriode = <?= json_encode($nama_periode) ?>;

    // ---- Helper: sharePdfToWA — pola sama dengan rekapitulasi.php ----
    async function sharePdfToWA(doc, filename) {
        const teks = filename.replace(/\.pdf$/i, '');
        const blob = doc.output('blob');
        const file = new File([blob], filename, { type: 'application/pdf' });
        if (navigator.canShare && navigator.canShare({ files: [file] })) {
            try { await navigator.share({ files: [file], title: 'Laporan WBB', text: teks }); return; }
            catch (e) { if (e && e.name === 'AbortError') return; }
        }
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob); a.download = filename; a.click();
        window.open('https://wa.me/?text=' + encodeURIComponent(teks + ' (PDF terlampir, silakan unggah manual)'), '_blank');
    }

    // ---- Pewarnaan sel tabel khusus saat di-export PDF ----
    // - Baris JUMLAH (tfoot, kalau ada) → hijau, semua tulisan putih
    // - Kolom Net Profit → abu, Admin Fee → hitam tebal
    // - Service Fee → biru (merah kalau minus)
    // - Baris dengan status 'lunas' → badge hijau
    function rsDidParseCell(data) {
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
        const idx = data.column.index;
        const teks = (data.cell.text || []).join(' ');
        if (idx === 6) {
            // Service Fee (kolom 0-based idx=6): merah kalau minus, default biru
            data.cell.styles.textColor = teks.indexOf('-') !== -1 ? [220, 53, 69] : [14, 165, 233];
        }
    }

    // ---- EXPORT PDF ----
    async function exportPDF(mode) {
        const { jsPDF } = window.jspdf;
        const doc = new jsPDF('landscape', 'mm', 'a4');
        const filename = 'Revenue Sharing ' + namaPeriode + '.pdf';

        // Kop sederhana (tanpa logo untuk ringkas; user bisa tambah watermark nanti)
        doc.setFont('helvetica', 'bold'); doc.setFontSize(14); doc.setTextColor(15, 23, 42);
        doc.text('WARTEG BUMI BAHARI', 14, 14);
        doc.setFont('helvetica', 'normal'); doc.setFontSize(9); doc.setTextColor(100, 100, 100);
        doc.text('Revenue Sharing - ' + namaPeriode, 14, 19);

        doc.autoTable({
            html: '#tabelRevenueSharing',
            startY: 24,
            theme: 'grid',
            styles: { fontSize: 8, cellPadding: 1.5, overflow: 'linebreak', halign: 'left', valign: 'middle' },
            headStyles: { fillColor: [15, 23, 42], textColor: 255, halign: 'center', fontSize: 8.5, fontStyle: 'bold' },
            columnStyles: {
                0: { halign: 'center', cellWidth: 10 },
                3: { halign: 'right' },
                4: { halign: 'right' },
                5: { halign: 'center' },
                6: { halign: 'right' },
                7: { halign: 'center' },
            },
            didParseCell: rsDidParseCell,
            includeHiddenHtml: true,
        });

        const finalY = doc.lastAutoTable.finalY || 24;

        // Ringkasan total di bawah tabel
        const totals = [
            ['Total Admin Fee', <?= json_encode('Rp ' . number_format($total_admin_fee_all, 0, ',', '.')) ?>],
            ['Total Service Fee', <?= json_encode('Rp ' . number_format($total_service_fee_all, 0, ',', '.')) ?>],
            ['Total Keseluruhan', <?= json_encode('Rp ' . number_format($total_keseluruhan, 0, ',', '.')) ?>],
        ];
        doc.autoTable({
            body: totals,
            startY: finalY + 6,
            theme: 'plain',
            styles: { fontSize: 10, cellPadding: 2, fontStyle: 'bold' },
            columnStyles: { 0: { textColor: [100, 116, 139] }, 1: { halign: 'right', textColor: [15, 23, 42] } },
            tableWidth: 100,
        });

        if (mode === 'share') {
            await sharePdfToWA(doc, filename);
        } else {
            doc.save(filename);
        }
    }

    // ---- EXPORT EXCEL (CSV sederhana, buka langsung di Excel; tidak butuh library) ----
    function exportExcel() {
        const rows = [['No', 'Nama Pengelola', 'Cabang', 'Net Profit', 'Admin Fee (3%)', 'Presentase Service Fee', 'Nominal Service Fee', 'Status Pembayaran']];
        const trs = document.querySelectorAll('#tabelRevenueSharing tbody tr');
        trs.forEach((tr) => {
            const cells = tr.querySelectorAll('td');
            if (cells.length < 8) return;
            const statusBtn = tr.querySelector('.rs-btn-group[data-field="status"] .rs-btn.is-active');
            const status = statusBtn ? statusBtn.textContent.trim() : '-';
            rows.push([
                cells[0].textContent.trim(),
                cells[1].textContent.replace(/\s+/g, ' ').trim(),
                cells[2].textContent.trim(),
                cells[3].textContent.trim(),
                cells[4].textContent.trim(),
                cells[5].textContent.replace(/\s+/g, ' ').trim(),
                cells[6].textContent.trim(),
                status,
            ]);
        });
        // Baris total
        rows.push([]);
        rows.push(['', '', '', '', 'TOTAL ADMIN FEE', <?= json_encode('Rp ' . number_format($total_admin_fee_all, 0, ',', '.')) ?>, '', '']);
        rows.push(['', '', '', '', 'TOTAL SERVICE FEE', <?= json_encode('Rp ' . number_format($total_service_fee_all, 0, ',', '.')) ?>, '', '']);
        rows.push(['', '', '', '', 'TOTAL KESELURUHAN', <?= json_encode('Rp ' . number_format($total_keseluruhan, 0, ',', '.')) ?>, '', '']);

        // Build CSV dengan BOM supaya Excel Indonesia auto-detect UTF-8
        const csv = '﻿' + rows.map((r) => r.map((c) => {
            const s = String(c).replace(/"/g, '""');
            return /[",\n]/.test(s) ? '"' + s + '"' : s;
        }).join(',')).join('\r\n');

        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'Revenue Sharing ' + namaPeriode + '.csv';
        a.click();
        URL.revokeObjectURL(a.href);
    }
})();
</script>

<?php include '../config/notifikasi_bell.php'; ?>