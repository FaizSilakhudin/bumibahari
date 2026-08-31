<?php
require '../config/koneksi.php';
require_role('pusat');

// =====================================================================
// PULIHKAN LAPORAN DARI ARSIP
// (Sidebar & <head>/<body> di-include SETELAH handler POST — handler ini
//  pakai header('Location: ...') yang gagal kalau HTML sudah terkirim duluan.)
// =====================================================================
if (isset($_POST['pulihkan'])) {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        $_SESSION['error'] = 'Token CSRF tidak valid!';
        header('Location: arsip_laporan');
        exit;
    }

    $arsip_id = (int) ($_POST['arsip_id'] ?? 0);

    $st = $conn->prepare("SELECT * FROM laporan_cabang_arsip WHERE id = ? AND dipulihkan_pada IS NULL");
    $st->bind_param('i', $arsip_id);
    $st->execute();
    $arsip_row = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$arsip_row) {
        $_SESSION['error'] = 'Data arsip tidak ditemukan atau sudah pernah dipulihkan.';
        header('Location: arsip_laporan');
        exit;
    }

    $data = json_decode($arsip_row['data_json'], true);
    if (!is_array($data)) {
        $_SESSION['error'] = 'Snapshot arsip rusak, tidak bisa dipulihkan.';
        header('Location: arsip_laporan');
        exit;
    }

    // Cek bentrok: sudah ada laporan baru untuk cabang+tanggal yang sama?
    $st = $conn->prepare("SELECT id FROM laporan_cabang WHERE id_cabang = ? AND tanggal = ?");
    $st->bind_param('is', $data['id_cabang'], $data['tanggal']);
    $st->execute();
    if ($st->get_result()->fetch_assoc()) {
        $_SESSION['error'] = 'Tidak bisa dipulihkan: sudah ada laporan baru untuk cabang & tanggal yang sama. Selesaikan manual dulu.';
        header('Location: arsip_laporan');
        exit;
    }
    $st->close();

    // Kolom yang dipulihkan: semua kecuali id lama (biar auto_increment kasih id baru)
    // dan nama_cabang (itu ikut ke-snapshot dari JOIN tampilan, bukan kolom asli laporan_cabang).
    unset($data['id'], $data['nama_cabang']);
    $kolom = array_keys($data);
    $placeholders = implode(',', array_fill(0, count($kolom), '?'));
    $sql = 'INSERT INTO laporan_cabang (`' . implode('`,`', $kolom) . '`) VALUES (' . $placeholders . ')';
    $types = str_repeat('s', count($kolom));
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        $_SESSION['error'] = 'Gagal menyiapkan pemulihan: ' . $conn->error;
        header('Location: arsip_laporan');
        exit;
    }
    $values = array_values($data);
    $stmt->bind_param($types, ...$values);

    if ($stmt->execute()) {
        $new_id = $conn->insert_id;
        $stmt->close();

        $upd = $conn->prepare("UPDATE laporan_cabang_arsip SET dipulihkan_pada = NOW() WHERE id = ?");
        $upd->bind_param('i', $arsip_id);
        $upd->execute();
        $upd->close();

        audit($conn, 'laporan_pulihkan', 'laporan_cabang', $new_id, [
            'dari_arsip_id' => $arsip_id, 'id_cabang' => $data['id_cabang'] ?? null, 'tanggal' => $data['tanggal'] ?? null,
        ]);

        $_SESSION['success'] = 'Laporan berhasil dipulihkan.';
    } else {
        $_SESSION['error'] = 'Gagal memulihkan: ' . $stmt->error;
    }
    header('Location: arsip_laporan');
    exit;
}

include 'sidebar_pusat.php';

// =====================================================================
// FILTER + PAGINATION
// =====================================================================
$search = trim($_GET['search'] ?? '');
$where_sql = '';
$params = [];
$types = '';
if ($search !== '') {
    $where_sql = 'WHERE nama_cabang LIKE ?';
    $params[] = "%{$search}%";
    $types = 's';
}

$limit  = 15;
$page   = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

$stc = $conn->prepare("SELECT COUNT(*) total FROM laporan_cabang_arsip $where_sql");
if ($search !== '') {
    $stc->bind_param($types, ...$params);
}
$stc->execute();
$total_data = (int) $stc->get_result()->fetch_assoc()['total'];
$total_pages = max(1, (int) ceil($total_data / $limit));

$sql = "SELECT * FROM laporan_cabang_arsip $where_sql ORDER BY dihapus_pada DESC LIMIT ? OFFSET ?";
$st = $conn->prepare($sql);
if ($search !== '') {
    $st->bind_param($types . 'ii', ...array_merge($params, [$limit, $offset]));
} else {
    $st->bind_param('ii', $limit, $offset);
}
$st->execute();
$arsip_list = $st->get_result()->fetch_all(MYSQLI_ASSOC);
?>

<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
    body { background-color: #f4f7fe !important; font-family: 'Plus Jakarta Sans', sans-serif !important; color: #1b2559; }
    .saas-card { background: #fff; border: none !important; border-radius: 20px !important; box-shadow: 0 18px 40px rgba(112,144,176,.06) !important; padding: 20px; }
    .title-mark { width: 12px; height: 12px; background: #dc2626; border-radius: 4px; display: inline-block; margin-right: 10px; }
    .btn-premium { background: #4318ff !important; color: #fff !important; border: none !important; padding: 10px 20px; border-radius: 12px; font-weight: 600; font-size: 14px; }
    .btn-restore { background: #dcfce7; color: #15803d; border: none; padding: 6px 14px; border-radius: 8px; font-size: 13px; font-weight: 600; }
    .btn-restore:hover { background: #bbf7d0; }
    .btn-detail { background: #eef2ff; color: #4318ff; border: none; padding: 6px 14px; border-radius: 8px; font-size: 13px; font-weight: 600; }
    .btn-detail:hover { background: #e0e7ff; }
    .table-saas { margin-bottom: 0; width: 100% !important; }
    .table-saas thead th { background: #f8f9fc !important; color: #8f9bba !important; font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: .5px; border-bottom: 1px solid #eef2f9 !important; padding: 14px 12px; }
    .table-saas tbody td { padding: 14px 12px; border-bottom: 1px solid #f4f7fe !important; color: #2b3674; font-size: 14px; vertical-align: middle; }
</style>

<div class="container-fluid py-4">
    <div class="d-flex align-items-center mb-1">
        <span class="title-mark"></span>
        <h3 class="fw-bold mb-0">Arsip Laporan Terhapus</h3>
    </div>
    <span class="text-muted small ms-4 d-block mb-4">Snapshot laporan yang pernah dihapus dari Laporan Harian — bisa dipulihkan kapan saja &mdash; <?= number_format($total_data) ?> entri</span>

    <div class="card saas-card mb-4">
        <form method="GET" class="d-flex gap-2">
            <input type="text" name="search" class="form-control" placeholder="Cari nama cabang..." value="<?= h($search) ?>" style="border-radius:12px;border:1px solid #e0e7ff;">
            <button type="submit" class="btn btn-premium"><i class="bi bi-search"></i></button>
            <?php if ($search !== ''): ?><a href="arsip_laporan" class="btn btn-light border">Reset</a><?php endif; ?>
        </form>
    </div>

    <div class="card saas-card p-0 overflow-hidden border-0">
        <div class="table-responsive">
            <table class="table table-saas align-middle mb-0">
                <thead>
                    <tr>
                        <th>Tanggal Laporan</th>
                        <th>Cabang</th>
                        <th>Dihapus Oleh</th>
                        <th>Dihapus Pada</th>
                        <th>Status</th>
                        <th class="text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($arsip_list)): ?>
                    <tr><td colspan="6" class="text-center py-5 text-muted fw-semibold"><i class="bi bi-inbox fs-2 d-block mb-2"></i>Belum ada laporan yang dihapus.</td></tr>
                <?php else: foreach ($arsip_list as $a):
                    $data = json_decode($a['data_json'], true) ?: [];
                ?>
                    <tr>
                        <td class="fw-semibold"><?= !empty($a['tanggal']) ? date('d M Y', strtotime($a['tanggal'])) : '-' ?></td>
                        <td class="fw-bold text-dark"><?= h($a['nama_cabang'] ?? '-') ?></td>
                        <td><i class="bi bi-person-badge me-1 text-muted"></i><?= h($a['dihapus_oleh_username'] ?? '-') ?></td>
                        <td class="text-muted small"><?= date('d M Y H:i', strtotime($a['dihapus_pada'])) ?></td>
                        <td>
                            <?php if ($a['dipulihkan_pada']): ?>
                                <span class="badge bg-success-subtle text-success">Sudah dipulihkan</span>
                            <?php else: ?>
                                <span class="badge bg-danger-subtle text-danger">Terhapus</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <div class="d-inline-flex gap-2">
                                <button type="button" class="btn-detail" onclick='arsipDetail(<?= htmlspecialchars(json_encode($data), ENT_QUOTES, "UTF-8") ?>)'>
                                    <i class="bi bi-eye"></i> Detail
                                </button>
                                <?php if (!$a['dipulihkan_pada']): ?>
                                <form method="POST" onsubmit="return confirm('Pulihkan laporan ini ke Laporan Harian?')">
                                    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                                    <input type="hidden" name="arsip_id" value="<?= (int) $a['id'] ?>">
                                    <button type="submit" name="pulihkan" class="btn-restore"><i class="bi bi-arrow-counterclockwise"></i> Pulihkan</button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <div class="px-3">
            <?php render_pagination($page, $total_pages, ['from' => $offset + 1, 'to' => min($offset + $limit, $total_data), 'total' => $total_data, 'label' => 'arsip']); ?>
        </div>
    </div>
</div>

<!-- MODAL DETAIL ARSIP -->
<div class="modal fade" id="modalDetailArsip" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content" style="border-radius:16px;">
            <div class="modal-header"><h5 class="modal-title fw-bold">Detail Laporan Terhapus</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <table class="table table-sm" id="tabelDetailArsip"><tbody></tbody></table>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const LABEL_ARSIP = {
    tanggal: 'Tanggal', nama_pengelola: 'Nama Pengelola',
    tunai: 'Tunai', qris: 'QRIS', grab_food: 'Grab Food', go_food: 'Go Food', pencairan_qris: 'Pencairan QRIS', total_omset: 'Total Omzet',
    belanja_pasar: 'Belanja Pasar', belanja_sembako: 'Belanja Sembako', belanja_beras: 'Belanja Beras', belanja_toko: 'Belanja Toko', total_rutin: 'Total Belanja Rutin',
    sewa: 'Sewa', gaji: 'Gaji', listrik: 'Listrik', air: 'Air', sampah: 'Sampah', keamanan: 'Keamanan', internet: 'Internet', gas: 'Gas',
    mingguan_karyawan: 'Mingguan Karyawan', es_batu: 'Es Batu', bensin: 'Bensin', lain_lain: 'Lain-lain', total_operasional: 'Total Operasional',
    total_pengeluaran: 'Total Pengeluaran', sisa_tunai: 'Sisa Tunai', sisa_qris: 'Sisa QRIS', net_profit: 'Net Profit', persentase: 'Margin (%)',
    keterangan: 'Keterangan', status_laporan: 'Status Laporan',
};

function arsipDetail(data) {
    const tbody = document.querySelector('#tabelDetailArsip tbody');
    let html = '';
    for (const [key, label] of Object.entries(LABEL_ARSIP)) {
        if (!(key in data)) continue;
        let val = data[key];
        if (val === null || val === '') val = '-';
        html += `<tr><td class="fw-semibold text-muted" style="width:45%;">${label}</td><td>${val}</td></tr>`;
    }
    tbody.innerHTML = html;
    new bootstrap.Modal(document.getElementById('modalDetailArsip')).show();
}

<?php if (isset($_SESSION['success'])): ?>
Swal.fire({ icon: 'success', title: 'Berhasil!', text: <?= json_encode($_SESSION['success']) ?>, timer: 2500, showConfirmButton: false });
<?php unset($_SESSION['success']); endif; ?>
<?php if (isset($_SESSION['error'])): ?>
Swal.fire({ icon: 'error', title: 'Gagal!', text: <?= json_encode($_SESSION['error']) ?> });
<?php unset($_SESSION['error']); endif; ?>
</script>
