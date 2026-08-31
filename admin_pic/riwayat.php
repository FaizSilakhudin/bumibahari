<?php
require '../config/koneksi.php';
include 'sidebar.php';

$id_user = current_user_id();
$cabang_ids = pic_cabang_ids($conn, $id_user);

$bulan = $_GET['bulan'] ?? date('m');
$tahun = $_GET['tahun'] ?? date('Y');
$id_cabang_filter = isset($_GET['id_cabang']) ? (int) $_GET['id_cabang'] : 0;

$tgl_awal  = date("$tahun-$bulan-01");
$tgl_akhir = date("Y-m-t", strtotime($tgl_awal));

$prev_time = strtotime('-1 month', strtotime($tgl_awal));
$next_time = strtotime('+1 month', strtotime($tgl_awal));
$prev_bulan = date('m', $prev_time); $prev_tahun = date('Y', $prev_time);
$next_bulan = date('m', $next_time); $next_tahun = date('Y', $next_time);

$cabang_list = [];
$rows = [];

if (!empty($cabang_ids)) {
    $placeholders = implode(',', array_fill(0, count($cabang_ids), '?'));
    $stmt = $conn->prepare("SELECT id_cabang, nama_cabang FROM cabang WHERE id_cabang IN ($placeholders) ORDER BY nama_cabang ASC");
    $stmt->bind_param(str_repeat('i', count($cabang_ids)), ...$cabang_ids);
    $stmt->execute();
    $cabang_list = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $scope_ids = ($id_cabang_filter && in_array($id_cabang_filter, $cabang_ids, true)) ? [$id_cabang_filter] : $cabang_ids;
    $ph2 = implode(',', array_fill(0, count($scope_ids), '?'));
    $sql = "SELECT lc.*, c.nama_cabang
            FROM laporan_cabang lc
            JOIN cabang c ON c.id_cabang = lc.id_cabang
            WHERE lc.id_cabang IN ($ph2) AND lc.tanggal BETWEEN ? AND ?
            ORDER BY lc.tanggal DESC, c.nama_cabang ASC";
    $stmt = $conn->prepare($sql);
    $types = str_repeat('i', count($scope_ids)) . 'ss';
    $stmt->bind_param($types, ...array_merge($scope_ids, [$tgl_awal, $tgl_akhir]));
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
?>

<style>
    :root { --primary-gradient: linear-gradient(135deg, #1e3a5f 0%, #2563eb 100%); }
    .custom-card { border: none; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.03); background: #ffffff; overflow: hidden; }
    .header-banner { background: var(--primary-gradient); color: white; border-radius: 16px; padding: 1.5rem; margin-bottom: 1.5rem; box-shadow: 0 4px 15px rgba(37, 99, 235, 0.2); }
    .table-custom th { background-color: #eff6ff !important; color: #1d4ed8; font-weight: 600; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.5px; border-bottom: 2px solid #bfdbfe; white-space: nowrap; }
    .table-custom td { font-size: 0.9rem; color: #1f2937; border-bottom: 1px solid #eff6ff; padding: 14px 12px; white-space: nowrap; }
    .badge-modern { padding: 0.5em 0.85em; border-radius: 8px; font-weight: 600; font-size: 0.8rem; }
    .btn-nav-bulan { background: #ffffff; border: 1px solid #bfdbfe; color: #2563eb; font-weight: 600; border-radius: 10px; padding: 8px 16px; white-space: nowrap; }
    .btn-nav-bulan:hover { background: #dbeafe; color: #1d4ed8; }
    .form-select-filter { border-radius: 10px; border: 1px solid #bfdbfe; padding: 8px 14px; font-weight: 600; color: #1d4ed8; }
</style>

<div class="container-fluid py-4">
    <div class="header-banner d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h4 class="mb-1 fw-bold"><i class="bi bi-clock-history me-2"></i> Riwayat Laporan</h4>
            <p class="mb-0 opacity-90">Seluruh cabang yang Anda pegang</p>
        </div>
    </div>

    <div class="card custom-card mb-4">
        <div class="card-body p-3 d-flex justify-content-between align-items-center flex-wrap gap-3">
            <a href="?bulan=<?= $prev_bulan ?>&tahun=<?= $prev_tahun ?>&id_cabang=<?= $id_cabang_filter ?>" class="btn btn-nav-bulan"><i class="bi bi-chevron-left"></i> Sebelumnya</a>
            <h5 class="fw-bold mb-0 text-center" style="color:#2563eb"><i class="bi bi-calendar3 me-2"></i><?= date('F Y', strtotime($tgl_awal)) ?></h5>
            <a href="?bulan=<?= $next_bulan ?>&tahun=<?= $next_tahun ?>&id_cabang=<?= $id_cabang_filter ?>" class="btn btn-nav-bulan">Berikutnya <i class="bi bi-chevron-right"></i></a>
        </div>
        <div class="card-body pt-0">
            <form method="GET" class="d-flex align-items-center gap-2">
                <input type="hidden" name="bulan" value="<?= h($bulan) ?>">
                <input type="hidden" name="tahun" value="<?= h($tahun) ?>">
                <label class="small fw-semibold text-muted mb-0">Cabang:</label>
                <select name="id_cabang" class="form-select form-select-filter" onchange="this.form.submit()" style="max-width:280px;">
                    <option value="0">Semua Cabang</option>
                    <?php foreach ($cabang_list as $c): ?>
                        <option value="<?= $c['id_cabang'] ?>" <?= $id_cabang_filter == $c['id_cabang'] ? 'selected' : '' ?>><?= h($c['nama_cabang']) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
    </div>

    <div class="card custom-card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-custom table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="ps-4" style="width: 60px;">No</th>
                            <th>Tanggal Laporan</th>
                            <th>Cabang</th>
                            <th>Status</th>
                            <th>Omzet</th>
                            <th>Pencairan QRIS</th>
                            <th>Belanja Rutin</th>
                            <th>Operasional</th>
                            <th>Total Pengeluaran</th>
                            <th>Sisa Tunai</th>
                            <th>Sisa QRIS</th>
                            <th>Net Profit</th>
                            <th>Margin</th>
                            <th class="text-center pe-4" style="width: 150px;">Nota Transaksi</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="14" class="text-center py-5 text-muted">
                                <i class="bi bi-folder-x fs-1 d-block mb-2 text-primary opacity-50"></i>
                                Belum ada data pada <?= date('F Y', strtotime($tgl_awal)) ?>
                            </td>
                        </tr>
                    <?php else: $no = 1; foreach ($rows as $row): $lengkap = ($row['status_laporan'] ?? 'lengkap') === 'lengkap'; ?>
                        <tr>
                            <td class="ps-4 fw-semibold text-muted"><?= $no++ ?></td>
                            <td class="fw-bold text-secondary"><?= date("d M Y", strtotime($row['tanggal'])) ?></td>
                            <td class="fw-semibold text-dark"><?= h($row['nama_cabang']) ?></td>
                            <td>
                                <?php if ($lengkap): ?>
                                    <span class="badge badge-modern bg-success-subtle text-success border-success-subtle"><i class="bi bi-check-circle-fill me-1"></i>Selesai</span>
                                <?php else: ?>
                                    <span class="badge badge-modern bg-warning-subtle text-warning border-warning-subtle"><i class="bi bi-hourglass-split me-1"></i>Menunggu Input</span>
                                <?php endif; ?>
                            </td>
                            <?php if (!$lengkap): ?>
                                <td colspan="9" class="text-muted small fst-italic">Laporan keuangan belum diinput</td>
                            <?php else: ?>
                            <td><span class="text-success fw-bold">Rp <?= number_format($row['total_omset'], 0, ',', '.') ?></span></td>
                            <td class="fw-semibold text-dark">Rp <?= number_format($row['pencairan_qris'] ?? 0, 0, ',', '.') ?></td>
                            <td class="fw-semibold text-danger">Rp <?= number_format($row['total_rutin'] ?? 0, 0, ',', '.') ?></td>
                            <td class="fw-semibold text-primary">Rp <?= number_format($row['total_operasional'] ?? 0, 0, ',', '.') ?></td>
                            <td class="fw-semibold text-dark">Rp <?= number_format($row['total_pengeluaran'] ?? 0, 0, ',', '.') ?></td>
                            <td class="fw-semibold <?= $row['sisa_tunai'] < 0 ? 'text-danger' : 'text-primary' ?>">Rp <?= number_format($row['sisa_tunai'], 0, ',', '.') ?></td>
                            <td class="fw-semibold <?= $row['sisa_qris'] < 0 ? 'text-danger' : 'text-warning' ?>">Rp <?= number_format($row['sisa_qris'], 0, ',', '.') ?></td>
                            <td><span class="fw-bold" style="color:#047857 !important;">Rp <?= number_format($row['net_profit'], 0, ',', '.') ?></span></td>
                            <td><span class="badge badge-modern bg-success-subtle text-success border-success-subtle"><i class="bi bi-graph-up-arrow me-1"></i><?= number_format($row['persentase'], 2) ?>%</span></td>
                            <?php endif; ?>
                            <td class="text-center pe-4">
                                <div class="d-flex justify-content-center gap-1">
                                    <?php
                                    $has_nota = false;
                                    for ($i = 1; $i <= 4; $i++):
                                        if (!empty($row["foto_nota$i"])):
                                            $has_nota = true;
                                    ?>
                                            <img src="../uploads/nota/<?= h($row["foto_nota$i"]) ?>" alt="Nota <?= $i ?>"
                                                 class="btn btn-sm btn-outline-primary rounded-circle p-0"
                                                 style="width:32px;height:32px;object-fit:cover;cursor:zoom-in;"
                                                 title="Nota <?= $i ?> &mdash; ketuk untuk perbesar"
                                                 onclick="picZoom('../uploads/nota/<?= h(rawurlencode($row["foto_nota$i"])) ?>')">
                                    <?php
                                        endif;
                                    endfor;
                                    if (!$has_nota) echo '<span class="text-muted small">- Tidak ada nota -</span>';
                                    ?>
                                </div>
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

<div id="picZoomOverlay" onclick="picZoomClose()" style="position:fixed;inset:0;z-index:3000;background:rgba(2,6,23,.93);display:none;align-items:center;justify-content:center;padding:16px;cursor:zoom-out;">
    <a id="picZoomDownload" href="" download title="Unduh foto ini" onclick="event.stopPropagation()"
       style="position:absolute;top:16px;right:70px;color:#fff;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.3);border-radius:10px;padding:8px 16px;text-decoration:none;display:flex;align-items:center;gap:8px;font-size:.95rem;font-weight:600;">
        <i class="bi bi-download"></i> Unduh
    </a>
    <button type="button" onclick="picZoomClose()" style="position:absolute;top:12px;right:18px;color:#fff;font-size:2.2rem;line-height:1;background:none;border:0;cursor:pointer;">&times;</button>
    <img id="picZoomImg" src="" alt="Nota" style="max-width:100%;max-height:100%;object-fit:contain;border-radius:8px;">
</div>
<script>
function picZoom(src) {
    document.getElementById('picZoomImg').src = src;
    document.getElementById('picZoomDownload').href = src;
    document.getElementById('picZoomOverlay').style.display = 'flex';
}
function picZoomClose() {
    document.getElementById('picZoomOverlay').style.display = 'none';
}
document.addEventListener('keydown', function (e) { if (e.key === 'Escape') picZoomClose(); });
</script>
