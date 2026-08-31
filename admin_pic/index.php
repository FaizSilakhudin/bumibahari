<?php
require '../config/koneksi.php';
include 'sidebar.php';

$id_user = current_user_id();
$cabang_ids = pic_cabang_ids($conn, $id_user);

// Tanggal yang sedang dilihat (default: kemarin, sesuai konvensi laporan harian).
$tanggal = $_GET['tanggal'] ?? date('Y-m-d', strtotime('-1 day'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
    $tanggal = date('Y-m-d', strtotime('-1 day'));
}
$prev_tgl = date('Y-m-d', strtotime($tanggal . ' -1 day'));
$next_tgl = date('Y-m-d', strtotime($tanggal . ' +1 day'));

$antrian = [];
$ringkasan = ['total' => 0, 'lengkap' => 0, 'menunggu' => 0, 'belum_nota' => 0];

if (!empty($cabang_ids)) {
    $placeholders = implode(',', array_fill(0, count($cabang_ids), '?'));
    $sql = "SELECT c.id_cabang, c.nama_cabang,
                   lc.status_laporan, lc.foto_nota1, lc.foto_nota2, lc.foto_nota3, lc.keterangan_nota
            FROM cabang c
            LEFT JOIN laporan_cabang lc ON lc.id_cabang = c.id_cabang AND lc.tanggal = ?
            WHERE c.id_cabang IN ($placeholders)
            ORDER BY c.nama_cabang ASC";
    $stmt = $conn->prepare($sql);
    $types = 's' . str_repeat('i', count($cabang_ids));
    $stmt->bind_param($types, $tanggal, ...$cabang_ids);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $punya_nota = !empty($row['foto_nota1']) || !empty($row['foto_nota2']) || !empty($row['foto_nota3']);
        $status = $row['status_laporan'] ?? ($punya_nota ? 'menunggu' : null);
        $row['punya_nota'] = $punya_nota;
        $row['status_efektif'] = $status;
        $antrian[] = $row;

        $ringkasan['total']++;
        if ($status === 'lengkap') $ringkasan['lengkap']++;
        elseif ($status === 'menunggu') $ringkasan['menunggu']++;
        else $ringkasan['belum_nota']++;
    }
    $stmt->close();
}
?>

<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">

<style>
    body { background-color: #f6f8ff !important; font-family: 'Plus Jakarta Sans', sans-serif !important; }
    .saas-card { background: #ffffff; border: 1px solid #eef1fb !important; border-radius: 20px !important; box-shadow: 0 4px 6px -1px rgb(0 0 0 / .02), 0 10px 15px -3px rgb(0 0 0 / .01) !important; padding: 22px; transition: all .25s ease; }
    .saas-card:hover { transform: translateY(-2px); box-shadow: 0 20px 25px -5px rgb(0 0 0 / .05) !important; }
    .stat-card { border-radius: 18px; padding: 22px; background: #fff; border: 1px solid #eef1fb; box-shadow: 0 4px 6px -1px rgb(0 0 0 / .02); border-top: 3px solid var(--sc-accent, #2563eb); transition: all .25s ease; }
    .stat-card:hover { transform: translateY(-2px); box-shadow: 0 12px 20px -8px rgba(30,58,95,.1); }
    .stat-card .icon { width: 50px; height: 50px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 22px; flex-shrink: 0; }
    .stat-card .fs-4 { font-size: 1.4rem !important; font-weight: 800; letter-spacing: -.5px; }
    .table-saas { margin-bottom: 0; width: 100% !important; }
    .table-saas thead th { background-color: #f8f9fc !important; color: #8f9bba !important; font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #eef2f9 !important; padding: 14px 12px; }
    .table-saas tbody td { padding: 14px 12px; border-bottom: 1px solid #f4f7fe !important; color: #2b3674; font-size: 14px; vertical-align: middle; }
    .btn-nav-tanggal { background: #ffffff; border: 1px solid #e0e7ff; color: #4318ff; font-weight: 600; border-radius: 10px; padding: 8px 16px; white-space: nowrap; }
    .btn-nav-tanggal:hover { background: #e0e7ff; color: #3310cc; }
</style>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold mb-0" style="color: #1b2559;">Antrian Laporan</h3>
            <span class="text-muted small">Cabang yang Anda pegang &mdash; input laporan keuangan harian</span>
        </div>
    </div>

    <div class="card saas-card mb-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <a href="?tanggal=<?= $prev_tgl ?>" class="btn btn-nav-tanggal"><i class="bi bi-chevron-left"></i> Sebelumnya</a>
            <h5 class="fw-bold mb-0" style="color:#1b2559"><i class="bi bi-calendar3 me-2"></i><?= date('d F Y', strtotime($tanggal)) ?></h5>
            <a href="?tanggal=<?= $next_tgl ?>" class="btn btn-nav-tanggal">Berikutnya <i class="bi bi-chevron-right"></i></a>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="stat-card d-flex align-items-center gap-3" style="--sc-accent:#2563eb;">
                <div class="icon" style="background:#eef2ff;color:#2563eb;"><i class="bi bi-shop"></i></div>
                <div><div class="text-muted small fw-semibold">Cabang Dipegang</div><div class="fw-bold fs-4 text-dark"><?= $ringkasan['total'] ?></div></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card d-flex align-items-center gap-3" style="--sc-accent:#15803d;">
                <div class="icon" style="background:#dcfce7;color:#15803d;"><i class="bi bi-check-circle"></i></div>
                <div><div class="text-muted small fw-semibold">Laporan Selesai</div><div class="fw-bold fs-4 text-dark"><?= $ringkasan['lengkap'] ?></div></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card d-flex align-items-center gap-3" style="--sc-accent:#b45309;">
                <div class="icon" style="background:#fef3c7;color:#b45309;"><i class="bi bi-hourglass-split"></i></div>
                <div><div class="text-muted small fw-semibold">Menunggu Diinput</div><div class="fw-bold fs-4 text-dark"><?= $ringkasan['menunggu'] ?></div></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card d-flex align-items-center gap-3" style="--sc-accent:#b91c1c;">
                <div class="icon" style="background:#fee2e2;color:#b91c1c;"><i class="bi bi-camera"></i></div>
                <div><div class="text-muted small fw-semibold">Nota Belum Masuk</div><div class="fw-bold fs-4 text-dark"><?= $ringkasan['belum_nota'] ?></div></div>
            </div>
        </div>
    </div>

    <div class="card saas-card p-0 overflow-hidden border-0">
        <div class="table-responsive">
            <table class="table table-saas align-middle mb-0">
                <thead>
                    <tr>
                        <th width="5%" class="text-center">No</th>
                        <th width="30%">Cabang</th>
                        <th width="15%" class="text-center">Nota</th>
                        <th width="20%" class="text-center">Status Laporan</th>
                        <th width="30%" class="text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($cabang_ids)): ?>
                    <tr><td colspan="5" class="text-center py-5 text-muted fw-semibold">
                        <i class="bi bi-inbox fs-2 d-block mb-2"></i> Anda belum ditugaskan ke cabang manapun. Hubungi Admin Pusat.
                    </td></tr>
                <?php elseif (empty($antrian)): ?>
                    <tr><td colspan="5" class="text-center py-5 text-muted fw-semibold">
                        <i class="bi bi-inbox fs-2 d-block mb-2"></i> Tidak ada data untuk tanggal ini.
                    </td></tr>
                <?php else: $no = 1; foreach ($antrian as $row): ?>
                    <tr>
                        <td class="text-center text-muted fw-semibold"><?= $no++ ?></td>
                        <td><span class="fw-bold text-dark"><?= h($row['nama_cabang']) ?></span></td>
                        <td class="text-center">
                            <?php if ($row['punya_nota']): ?>
                                <span class="badge bg-success-subtle text-success"><i class="bi bi-check-circle-fill me-1"></i>Masuk</span>
                            <?php else: ?>
                                <span class="badge bg-secondary-subtle text-secondary">Belum ada</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($row['status_efektif'] === 'lengkap'): ?>
                                <span class="badge bg-success-subtle text-success px-3 py-2 rounded-pill"><i class="bi bi-check-circle-fill me-1"></i>Selesai</span>
                            <?php elseif ($row['status_efektif'] === 'menunggu'): ?>
                                <span class="badge bg-warning-subtle text-warning px-3 py-2 rounded-pill"><i class="bi bi-hourglass-split me-1"></i>Menunggu Input</span>
                            <?php else: ?>
                                <span class="badge bg-danger-subtle text-danger px-3 py-2 rounded-pill"><i class="bi bi-camera me-1"></i>Nota Belum Ada</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <a href="input_laporan.php?id_cabang=<?= (int)$row['id_cabang'] ?>&tanggal=<?= h($tanggal) ?>"
                               class="btn btn-sm <?= $row['status_efektif'] === 'lengkap' ? 'btn-outline-secondary' : 'btn-primary' ?>">
                                <i class="bi bi-<?= $row['status_efektif'] === 'lengkap' ? 'eye' : 'pencil-square' ?> me-1"></i>
                                <?= $row['status_efektif'] === 'lengkap' ? 'Lihat / Edit' : 'Isi Laporan' ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
