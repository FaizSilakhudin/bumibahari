<?php
require '../config/koneksi.php';
include 'sidebar.php';

$id_cabang = (int) $_SESSION['id_cabang'];

$bulan = $_GET['bulan'] ?? date('m');
$tahun = $_GET['tahun'] ?? date('Y');
$tgl_awal  = date("$tahun-$bulan-01");
$tgl_akhir = date('Y-m-t', strtotime($tgl_awal));

$prev_time = strtotime('-1 month', strtotime($tgl_awal));
$next_time = strtotime('+1 month', strtotime($tgl_awal));
$prev_bulan = date('m', $prev_time); $prev_tahun = date('Y', $prev_time);
$next_bulan = date('m', $next_time); $next_tahun = date('Y', $next_time);

$stmt = $conn->prepare("
    SELECT tanggal, status_laporan, keterangan_nota, foto_nota1, foto_nota2, foto_nota3, foto_nota4
    FROM laporan_cabang
    WHERE id_cabang = ? AND tanggal BETWEEN ? AND ?
      AND (foto_nota1 IS NOT NULL OR foto_nota2 IS NOT NULL OR foto_nota3 IS NOT NULL OR foto_nota4 IS NOT NULL)
    ORDER BY tanggal DESC
");
$stmt->bind_param('iss', $id_cabang, $tgl_awal, $tgl_akhir);
$stmt->execute();
$data = $stmt->get_result();

$stmt2 = $conn->prepare("SELECT nama_cabang FROM cabang WHERE id_cabang = ?");
$stmt2->bind_param('i', $id_cabang);
$stmt2->execute();
$cabang = $stmt2->get_result()->fetch_assoc()['nama_cabang'] ?? '-';
?>

<style>
    :root { --primary-gradient: linear-gradient(135deg, #059669 0%, #10b981 100%); }
    .header-banner { background: var(--primary-gradient); color: #fff; border-radius: 16px; padding: 1.5rem; margin-bottom: 1.5rem; box-shadow: 0 4px 15px rgba(16,185,129,.2); }
    .custom-card { border: none; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,.03); background: #fff; overflow: hidden; }
    .btn-nav-bulan { background: #fff; border: 1px solid #bbf7d0; color: #059669; font-weight: 600; border-radius: 10px; padding: 8px 16px; white-space: nowrap; }
    .btn-nav-bulan:hover { background: #dcfce7; color: #047857; }
    .nota-day-card { border: 1px solid #ecfdf5; border-radius: 14px; padding: 18px; margin-bottom: 18px; background: #fff; }
    .nota-day-head { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; margin-bottom: 14px; padding-bottom: 12px; border-bottom: 1px dashed #d1fae5; }
    .nota-photo { width: 100%; aspect-ratio: 1/1; object-fit: cover; border-radius: 10px; border: 1px solid #e2e8f0; cursor: zoom-in; transition: box-shadow .15s ease; }
    .nota-photo:hover { box-shadow: 0 6px 16px rgba(15,23,42,.12); }
    .nota-photo-wrap { position: relative; }
    .nota-dl-btn { position: absolute; bottom: 6px; right: 6px; width: 30px; height: 30px; border-radius: 50%; background: rgba(15,23,42,.65); color: #fff; display: flex; align-items: center; justify-content: center; font-size: .9rem; text-decoration: none; }
    .nota-dl-btn:hover { background: #059669; color: #fff; }
    #zoomOverlay { position: fixed; inset: 0; z-index: 3000; background: rgba(2,6,23,.93); display: none; align-items: center; justify-content: center; padding: 16px; cursor: zoom-out; }
    #zoomOverlay.show { display: flex; }
    #zoomOverlay img { max-width: 100%; max-height: 100%; object-fit: contain; border-radius: 8px; }
    #zoomOverlay .zoom-x { position: absolute; top: 12px; right: 18px; color: #fff; font-size: 2.2rem; line-height: 1; background: none; border: 0; cursor: pointer; }
    #zoomOverlay .zoom-dl { position: absolute; top: 16px; right: 70px; color: #fff; background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.3); border-radius: 10px; padding: 8px 16px; text-decoration: none; display: flex; align-items: center; gap: 8px; font-weight: 600; }
    #zoomOverlay .zoom-dl:hover { background: rgba(255,255,255,.22); color: #fff; }
</style>

<div class="container-fluid py-4">
    <div class="header-banner d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h4 class="mb-1 fw-bold"><i class="bi bi-images me-2"></i> Riwayat Nota</h4>
            <p class="mb-0 opacity-90"><i class="bi bi-shop me-1"></i> <strong><?= h($cabang) ?></strong> &mdash; riwayat pengiriman foto nota harian</p>
        </div>
    </div>

    <div class="card custom-card mb-4">
        <div class="card-body p-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <a href="?bulan=<?= $prev_bulan ?>&tahun=<?= $prev_tahun ?>" class="btn btn-nav-bulan"><i class="bi bi-chevron-left"></i> Sebelumnya</a>
            <h5 class="fw-bold mb-0 text-center" style="color:#059669"><i class="bi bi-calendar3 me-2"></i><?= date('F Y', strtotime($tgl_awal)) ?></h5>
            <a href="?bulan=<?= $next_bulan ?>&tahun=<?= $next_tahun ?>" class="btn btn-nav-bulan">Berikutnya <i class="bi bi-chevron-right"></i></a>
        </div>
    </div>

    <?php if ($data->num_rows === 0): ?>
        <div class="card custom-card text-center py-5 text-muted">
            <i class="bi bi-folder-x fs-1 d-block mb-2 text-success opacity-50"></i>
            Belum ada nota terkirim pada <?= date('F Y', strtotime($tgl_awal)) ?>
        </div>
    <?php else: while ($row = $data->fetch_assoc()):
        $lengkap = ($row['status_laporan'] ?? 'lengkap') === 'lengkap';
    ?>
        <div class="nota-day-card">
            <div class="nota-day-head">
                <div>
                    <div class="fw-bold fs-6 text-dark"><i class="bi bi-calendar-event me-1 text-success"></i> <?= date('d F Y', strtotime($row['tanggal'])) ?></div>
                    <?php if (!empty($row['keterangan_nota'])): ?>
                        <div class="small text-muted mt-1"><i class="bi bi-chat-left-text me-1"></i> <?= h($row['keterangan_nota']) ?></div>
                    <?php endif; ?>
                </div>
                <?php if ($lengkap): ?>
                    <span class="badge bg-success-subtle text-success px-3 py-2 rounded-pill"><i class="bi bi-check-circle-fill me-1"></i>Laporan Selesai</span>
                <?php else: ?>
                    <span class="badge bg-warning-subtle text-warning px-3 py-2 rounded-pill"><i class="bi bi-hourglass-split me-1"></i>Menunggu Diproses PIC</span>
                <?php endif; ?>
            </div>
            <div class="row g-3">
                <?php for ($i = 1; $i <= 4; $i++): if (!empty($row["foto_nota$i"])): ?>
                    <div class="col-6 col-md-3">
                        <div class="nota-photo-wrap">
                            <img src="../uploads/nota/<?= h($row["foto_nota$i"]) ?>" class="nota-photo" alt="Nota <?= $i ?>"
                                 onclick="zoomNota('../uploads/nota/<?= h(rawurlencode($row["foto_nota$i"])) ?>')">
                            <a href="../uploads/nota/<?= h($row["foto_nota$i"]) ?>" download class="nota-dl-btn" title="Unduh Nota <?= $i ?>" onclick="event.stopPropagation()">
                                <i class="bi bi-download"></i>
                            </a>
                        </div>
                    </div>
                <?php endif; endfor; ?>
            </div>
        </div>
    <?php endwhile; endif; ?>
</div>

<div id="zoomOverlay" onclick="zoomClose()">
    <a id="zoomDownload" href="" download class="zoom-dl" title="Unduh foto ini" onclick="event.stopPropagation()"><i class="bi bi-download"></i> Unduh</a>
    <button type="button" class="zoom-x" onclick="zoomClose()">&times;</button>
    <img id="zoomImg" src="" alt="Nota">
</div>
<script>
function zoomNota(src) {
    document.getElementById('zoomImg').src = src;
    document.getElementById('zoomDownload').href = src;
    document.getElementById('zoomOverlay').classList.add('show');
}
function zoomClose() {
    document.getElementById('zoomOverlay').classList.remove('show');
}
document.addEventListener('keydown', function (e) { if (e.key === 'Escape') zoomClose(); });
</script>
