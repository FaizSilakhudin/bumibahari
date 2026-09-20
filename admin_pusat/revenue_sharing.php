<?php
/**
 * REVENUE SHARING — admin_pusat
 *
 * Laporan bulanan admin fee (3% dari net profit keseluruhan, fixed) + service fee
 * (3/5/7,5% dari sisi pengelola, bisa dipilih per cabang per bulan) +
 * status pembayaran (pending / belum lunas / lunas, editable inline).
 *
 * Sumber kalkulasi: agregat `laporan_cabang.net_profit` WHERE status='lengkap'
 * untuk (id_cabang, tahun, bulan). Rumus service fee sama persis dengan
 * admin_pusat/rekapitulasi.php:183-190.
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
$akhir_periode  = date('Y-m-t', strtotime("$sel_tahun-$sel_bulan-01"));
$periode_anchor = anchor_periode($akhir_periode);

// ----- Pagination (100+ cabang) -----
$limit_rs = 25;
$page_rs  = max(1, (int) ($_GET['page'] ?? 1));
$offset_rs = ($page_rs - 1) * $limit_rs;

// ----- Query utama: 1 baris per cabang, agregat net_profit + status/presentase -----
// LEFT JOIN supaya cabang yang BELUM PERNAH ada di revenue_sharing tetap muncul
// (dengan persen default 5% & status pending).
$sql_count = "SELECT COUNT(*) total FROM cabang c";
$res_count = $conn->query($sql_count);
$total_rs  = (int) $res_count->fetch_assoc()['total'];
$total_pages_rs = max(1, (int) ceil($total_rs / $limit_rs));
if ($page_rs > $total_pages_rs) { $page_rs = $total_pages_rs; $offset_rs = ($page_rs - 1) * $limit_rs; }

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
    LIMIT ? OFFSET ?
";
$st = $conn->prepare($sql);
$st->bind_param('iiiiii', $sel_tahun, $sel_bulan, $sel_tahun, $sel_bulan, $limit_rs, $offset_rs);
$st->execute();
$rs_rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

// ----- Hitung nominal & kumpulkan baris -----
$total_admin_fee_all   = 0.0;
$total_service_fee_all = 0.0;
$baris = [];
foreach ($rs_rows as $r) {
    $net_profit   = (float) $r['net_profit'];
    $admin_fee    = $net_profit > 0 ? $net_profit * 3 / 100 : 0;            // 3% fixed
    $laba_setelah = $net_profit - $admin_fee;
    $share_pgl    = $laba_setelah * 50 / 100;                              // 50% sisi pengelola
    $persen       = (float) $r['persen_service_fee'];
    $service_fee  = $share_pgl * $persen / 100;                            // service fee

    $total_admin_fee_all   += $admin_fee;
    $total_service_fee_all += $service_fee;

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

// ----- Token CSRF untuk FormData di JS -----
$csrf_token = csrf_token();
?>

<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

<style>
  body { font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif; background-color: #f8fafc; color: #0f172a; }
  .rs-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 20px; box-shadow: 0px 4px 20px rgba(0,0,0,0.03); }
  .form-select-filter { border-radius: 12px; border: 1px solid #e2e8f0; padding: 9px 16px; font-size: 14px; font-weight: 600; color: #0f172a; background-color: #fff; box-shadow: 0px 2px 6px rgba(0,0,0,0.02); }
  .form-select-filter:focus { border-color: #4318ff; box-shadow: 0 0 0 3px rgba(67,24,255,0.15); }
  .table-modern thead th { background: #f8fafc; color: #64748b; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700; padding: 14px 16px; border-bottom: 1px solid #e2e8f0; white-space: nowrap; }
  .table-modern tbody td { padding: 14px 16px; border-bottom: 1px solid #f1f5f9; font-size: 14px; white-space: nowrap; }

  /* Tombol presentase (3/5/7,5) & status — radio-button look inline */
  .rs-btn-group { display: inline-flex; gap: 4px; }
  .rs-btn {
    border: 1px solid #cbd5e1; background: #fff; color: #475569; font-weight: 700; font-size: 12.5px;
    padding: 6px 12px; border-radius: 8px; cursor: pointer; transition: all .15s ease;
    min-width: 44px; text-align: center;
  }
  .rs-btn:hover { background: #f1f5f9; border-color: #94a3b8; }
  .rs-btn.is-active { background: #4318ff; border-color: #4318ff; color: #fff; box-shadow: 0 4px 10px -2px rgba(67,24,255,.4); }
  .rs-btn.is-active:hover { background: #3311db; }
  .rs-btn[disabled] { opacity: .55; cursor: wait; }

  .status-pending     { background: #fffbeb; color: #d97706; border-color: #fde68a; }
  .status-pending.is-active { background: #d97706; border-color: #d97706; color: #fff; box-shadow: 0 4px 10px -2px rgba(217,119,6,.4); }
  .status-belum_lunas { background: #fef2f2; color: #dc2626; border-color: #fecaca; }
  .status-belum_lunas.is-active { background: #dc2626; border-color: #dc2626; color: #fff; box-shadow: 0 4px 10px -2px rgba(220,38,38,.4); }
  .status-lunas       { background: #f0fdf4; color: #16a34a; border-color: #bbf7d0; }
  .status-lunas.is-active { background: #16a34a; border-color: #16a34a; color: #fff; box-shadow: 0 4px 10px -2px rgba(22,163,74,.4); }

  /* Flash hijau saat baris baru saja diupdate */
  tr.rs-flash { animation: rsFlash 600ms ease-out; }
  @keyframes rsFlash {
      0%   { background: #dcfce7; }
      100% { background: transparent; }
  }

  /* Footer JUMLAH — sama gaya dengan baris JUMLAH di PDF rekap (komit 44f6102):
     putih di atas hijau. */
  .table-modern tfoot td {
      background: #16a34a !important;
      color: #fff !important;
      font-weight: 800;
      font-size: 14px;
      padding: 16px !important;
      border-top: 2px solid #15803d;
  }
  .table-modern tfoot td .rs-total-nom {
      color: #fff !important;
      font-weight: 800;
  }

  /* Mobile: tumpuk jadi kartu */
  @media (max-width: 767.98px) {
      .table-modern thead { display: none; }
      .table-modern tbody tr { display: block; border: 1px solid #e2e8f0; border-radius: 12px; margin: 12px 0; padding: 14px; background: #fff; }
      .table-modern tbody td { display: flex; justify-content: space-between; align-items: center; padding: 8px 0 !important; border-bottom: 1px dashed #f1f5f9 !important; white-space: normal; }
      .table-modern tbody td::before { content: attr(data-label); font-weight: 700; color: #64748b; font-size: 11px; text-transform: uppercase; }
      .table-modern tfoot tr { display: block; margin-top: 12px; }
      .table-modern tfoot td { display: flex; justify-content: space-between; align-items: center; padding: 10px 14px !important; border-bottom: 1px solid rgba(255,255,255,.25); }
      .table-modern tfoot td::before { content: attr(data-label); color: #fff; opacity: .85; }
      .table-modern tfoot td:last-child { border-bottom: none; }
  }
</style>

<div class="content">
<div class="container-fluid py-4 px-3 px-md-4">

    <!-- HEADER + FILTER -->
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-start align-items-lg-center mb-4 gap-3">
        <div>
            <span class="text-muted small fw-bold text-uppercase" style="font-size: 11px; letter-spacing: 1px; color:#94a3b8!important;">REVENUE SHARING &bull; <?= strtoupper($nama_periode) ?></span>
            <h3 class="fw-extrabold mb-0 mt-1" style="color: #0f172a!important; font-size: 24px; letter-spacing: -0.5px; font-weight: 800;">
                Admin Fee &amp; Service Fee Bulanan
            </h3>
            <p class="text-muted mb-0 mt-1" style="font-size: 13px;">
                <i class="bi bi-info-circle me-1"></i>
                Admin Fee <strong>3%</strong> otomatis dari net profit. Service Fee dihitung dari <strong>50% sisi pengelola</strong> dengan presentase yang bisa dipilih per cabang.
            </p>
        </div>

        <form method="GET" class="d-flex flex-wrap align-items-center gap-2">
            <select name="bulan" class="form-select form-select-filter" style="min-width:auto;" onchange="this.form.submit()">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                <option value="<?= $m ?>" <?= $sel_bulan == $m ? 'selected' : '' ?>><?= nama_bulan_id($m) ?></option>
                <?php endfor; ?>
            </select>
            <select name="tahun" class="form-select form-select-filter" style="min-width:auto;" onchange="this.form.submit()">
                <?php for ($y = (int) date('Y') + 1; $y >= tahun_data_paling_lama($conn); $y--): ?>
                <option value="<?= $y ?>" <?= $sel_tahun == $y ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
        </form>
    </div>

    <!-- TABEL -->
    <div class="rs-card p-0 overflow-hidden">
        <div class="px-4 pt-4 pb-3 border-bottom" style="border-color: #f1f5f9!important;">
            <h6 class="fw-bold mb-0" style="color: #0f172a; font-size: 16px;">
                <i class="bi bi-cash-stack me-1 text-success"></i> Daftar Revenue Sharing — <?= h($nama_periode) ?>
            </h6>
            <p class="text-muted small mb-0 mt-1">
                Klik tombol presentase / status untuk update langsung. Perubahan tersimpan otomatis dan tercatat di audit log.
            </p>
        </div>

        <div class="table-responsive">
            <table class="table table-modern align-middle mb-0">
                <thead>
                    <tr>
                        <th style="width: 50px;">No</th>
                        <th>Nama Pengelola</th>
                        <th>Cabang</th>
                        <th class="text-end">Admin Fee (3%)</th>
                        <th class="text-center" style="width: 200px;">Presentase</th>
                        <th class="text-end">Nominal Service Fee</th>
                        <th class="text-center" style="width: 320px;">Status Pembayaran</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($baris)): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-5">
                            <i class="bi bi-inbox fs-2 d-block mb-2 opacity-50"></i>
                            Belum ada data untuk periode ini.
                        </td>
                    </tr>
                    <?php else:
                        $no = $offset_rs + 1;
                        foreach ($baris as $b):
                            $row_id = 'rs-row-' . $b['id_cabang'];
                            $nom_id = 'rs-nom-'  . $b['id_cabang'];
                            $stt_id = 'rs-stt-'  . $b['id_cabang'];
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
                                    data-value="<?= $p ?>">
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
                <?php if (!empty($baris)): ?>
                <tfoot>
                    <tr>
                        <td colspan="3" data-label="" class="text-end" style="background:#16a34a!important;">
                            <i class="bi bi-calculator-fill me-2"></i>TOTAL
                        </td>
                        <td data-label="Total Admin Fee" class="text-end rs-total-nom">
                            Rp <?= number_format($total_admin_fee_all, 0, ',', '.') ?>
                        </td>
                        <td class="text-center" style="background:#16a34a!important; font-size:12px; color:#fff!important;">
                            <?= count($baris) ?> cabang
                        </td>
                        <td data-label="Total Service Fee" class="text-end rs-total-nom">
                            Rp <?= number_format($total_service_fee_all, 0, ',', '.') ?>
                        </td>
                        <td data-label="Total Keseluruhan" class="text-end">
                            <span class="d-block" style="font-size:11px; opacity:.85; font-weight:600;">Admin + Service</span>
                            <span class="rs-total-nom">Rp <?= number_format($total_keseluruhan, 0, ',', '.') ?></span>
                        </td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>

        <?php if (!empty($baris)): ?>
        <div class="px-4">
            <?php render_pagination($page_rs, $total_pages_rs, [
                'from'  => $offset_rs + 1,
                'to'    => min($offset_rs + $limit_rs, $total_rs),
                'total' => $total_rs,
                'label' => 'cabang',
            ], 'page'); ?>
        </div>
        <?php endif; ?>
    </div>

</div>
</div>

<script>
(function () {
    // CSRF token tersedia global dari session server-side — pakai meta tag inline
    // di bawah supaya JS tidak perlu round-trip ke server dulu.
    const csrfToken = <?= json_encode($csrf_token) ?>;
    const handlerUrl = 'revenue_sharing_handler.php';
    const fmtRp = (n) => 'Rp ' + Math.round(n).toLocaleString('id-ID');

    // Klik tombol di .rs-btn-group → kirim AJAX, update state baris itu saja.
    document.querySelectorAll('.rs-btn-group').forEach((grp) => {
        grp.addEventListener('click', (ev) => {
            const btn = ev.target.closest('.rs-btn');
            if (!btn || btn.disabled) return;
            const tr = btn.closest('tr');
            const field = grp.dataset.field;                // 'persen' | 'status'
            const value = btn.dataset.value;
            const idCabang = tr.dataset.idCabang;
            const tahun    = tr.dataset.tahun;
            const bulan    = tr.dataset.bulan;
            const aksi     = (field === 'persen') ? 'update_persen' : 'update_status';

            // Disable semua tombol di grup itu selama request biar tidak double-klik
            grp.querySelectorAll('.rs-btn').forEach((b) => b.disabled = true);

            const fd = new FormData();
            fd.append('csrf', csrfToken);
            fd.append('aksi', aksi);
            fd.append('id_cabang', idCabang);
            fd.append('tahun', tahun);
            fd.append('bulan', bulan);
            if (field === 'persen') fd.append('persen_service_fee', value);
            else                    fd.append('status_pembayaran', value);

            fetch(handlerUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then((r) => r.json())
                .then((data) => {
                    grp.querySelectorAll('.rs-btn').forEach((b) => b.disabled = false);
                    if (!data.ok) {
                        alert('Gagal: ' + (data.msg || 'unknown'));
                        return;
                    }
                    // Update status aktif di grup ini
                    grp.querySelectorAll('.rs-btn').forEach((b) => {
                        b.classList.toggle('is-active', b.dataset.value === value);
                    });
                    // Refresh kolom Nominal Service Fee (server sudah hitung ulang)
                    const nomCell = document.getElementById('rs-nom-' + idCabang);
                    if (nomCell && typeof data.nominal_service_fee === 'number') {
                        nomCell.textContent = fmtRp(data.nominal_service_fee);
                    }
                    // Flash hijau supaya user tahu baris ini baru saja diupdate
                    tr.classList.remove('rs-flash');
                    void tr.offsetWidth;       // restart animation
                    tr.classList.add('rs-flash');
                })
                .catch((err) => {
                    grp.querySelectorAll('.rs-btn').forEach((b) => b.disabled = false);
                    alert('Gagal mengirim request: ' + err);
                });
        });
    });
})();
</script>

<?php include '../config/notifikasi_bell.php'; ?>