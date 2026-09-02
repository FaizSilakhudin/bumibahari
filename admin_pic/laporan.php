<?php
require '../config/koneksi.php';
require_role('pic');
include 'sidebar.php';

$id_user = current_user_id();
$cabang_ids_pic = pic_cabang_ids($conn, $id_user);

// ==========================================================
// FILTER
// ==========================================================
$filter    = $_GET['filter'] ?? 'harian';
$tgl_awal  = $_GET['tgl_awal']  ?? date('Y-m-01');
$tgl_akhir = $_GET['tgl_akhir'] ?? date('Y-m-d');
$id_cabang = $_GET['id_cabang'] ?? '';

// Wajib: cabang yang difilter harus salah satu yang dipegang PIC ini.
if ($id_cabang !== '' && !in_array((int) $id_cabang, $cabang_ids_pic, true)) {
    $id_cabang = '';
}

$page  = max(1, (int) ($_GET['page'] ?? 1));
$limit = 10;
$offset = ($page - 1) * $limit;

$total_data = 0;
$total_pages = 1;
$total_omset = 0;
$net_profit = 0;
$data = null;
$cabang_list = [];

if (!empty($cabang_ids_pic)) {
    $ph_pic = implode(',', array_fill(0, count($cabang_ids_pic), '?'));

    $where_sql = "WHERE l.tanggal BETWEEN ? AND ? AND l.id_cabang IN ($ph_pic)";
    $params = array_merge([$tgl_awal, $tgl_akhir], $cabang_ids_pic);
    $types  = 'ss' . str_repeat('i', count($cabang_ids_pic));

    if ($id_cabang !== '') {
        $where_sql .= ' AND l.id_cabang = ?';
        $params[] = (int) $id_cabang;
        $types .= 'i';
    }

    $stmt_count = $conn->prepare("SELECT COUNT(*) AS total FROM laporan_cabang l $where_sql");
    $stmt_count->bind_param($types, ...$params);
    $stmt_count->execute();
    $total_data = (int) $stmt_count->get_result()->fetch_assoc()['total'];
    $total_pages = max(1, (int) ceil($total_data / $limit));
    $stmt_count->close();

    $stmt = $conn->prepare("
        SELECT l.*, c.nama_cabang
        FROM laporan_cabang l
        JOIN cabang c ON l.id_cabang = c.id_cabang
        $where_sql
        ORDER BY l.tanggal DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->bind_param($types . 'ii', ...array_merge($params, [$limit, $offset]));
    $stmt->execute();
    $data = $stmt->get_result();

    $stmt_sum = $conn->prepare("SELECT COALESCE(SUM(l.total_omset),0) omzet, COALESCE(SUM(l.net_profit),0) laba FROM laporan_cabang l $where_sql");
    $stmt_sum->bind_param($types, ...$params);
    $stmt_sum->execute();
    $sum_row = $stmt_sum->get_result()->fetch_assoc();
    $total_omset = $sum_row['omzet'];
    $net_profit  = $sum_row['laba'];
    $stmt_sum->close();

    $ph2 = implode(',', array_fill(0, count($cabang_ids_pic), '?'));
    $stmt_c = $conn->prepare("SELECT id_cabang, nama_cabang FROM cabang WHERE id_cabang IN ($ph2) ORDER BY nama_cabang");
    $stmt_c->bind_param(str_repeat('i', count($cabang_ids_pic)), ...$cabang_ids_pic);
    $stmt_c->execute();
    $cabang_list = $stmt_c->get_result()->fetch_all(MYSQLI_ASSOC);
}
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold mb-1 text-dark">Laporan Harian</h3>
            <p class="text-muted small mb-0">Omzet, pengeluaran, dan net profit &mdash; cabang yang Anda pegang.</p>
        </div>
        <?php if (!empty($cabang_ids_pic)): ?>
        <a href="export_laporan.php?<?= http_build_query(['tgl_awal' => $tgl_awal, 'tgl_akhir' => $tgl_akhir, 'id_cabang' => $id_cabang]) ?>"
           class="btn btn-outline-success fw-semibold" style="border-radius: 10px;">
            <i class="bi bi-file-earmark-spreadsheet me-1"></i> Export CSV
        </a>
        <?php endif; ?>
    </div>

    <?php if (empty($cabang_ids_pic)): ?>
        <div class="alert alert-warning rounded-4"><i class="bi bi-exclamation-triangle-fill me-2"></i> Anda belum ditugaskan ke cabang manapun. Hubungi Admin Pusat.</div>
    <?php else: ?>

    <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px;">
        <div class="card-body p-4">
            <form method="GET" class="row g-3 align-items-end" id="filterForm">
                <div class="col-lg-2 col-md-4">
                    <label class="form-label fw-semibold text-secondary small">Mode Filter</label>
                    <select name="filter" id="filterMode" class="form-select form-select-md border-2 bg-light">
                        <option value="harian" <?= $filter == 'harian' ? 'selected' : '' ?>>Harian</option>
                        <option value="mingguan" <?= $filter == 'mingguan' ? 'selected' : '' ?>>Mingguan</option>
                        <option value="bulanan" <?= $filter == 'bulanan' ? 'selected' : '' ?>>Bulanan</option>
                    </select>
                </div>

                <div class="col-lg-3 col-md-6 filter-input" id="input-harian">
                    <label class="form-label fw-semibold text-secondary small">Pilih Tanggal</label>
                    <input type="date" name="tgl" value="<?= h($_GET['tgl'] ?? date('Y-m-d')) ?>" class="form-control form-control-md border-2 bg-light">
                </div>
                <div class="col-lg-3 col-md-6 filter-input d-none" id="input-mingguan">
                    <label class="form-label fw-semibold text-secondary small">Pilih Minggu</label>
                    <input type="week" name="minggu" value="<?= h($_GET['minggu'] ?? date('Y-\WW')) ?>" class="form-control form-control-md border-2 bg-light">
                </div>
                <div class="col-lg-3 col-md-6 filter-input d-none" id="input-bulanan">
                    <label class="form-label fw-semibold text-secondary small">Pilih Bulan</label>
                    <input type="month" name="bulan" value="<?= h($_GET['bulan'] ?? date('Y-m')) ?>" class="form-control form-control-md border-2 bg-light">
                </div>

                <input type="hidden" name="tgl_awal" id="tgl_awal" value="<?= h($tgl_awal) ?>">
                <input type="hidden" name="tgl_akhir" id="tgl_akhir" value="<?= h($tgl_akhir) ?>">

                <div class="col-lg-4 col-md-8">
                    <label class="form-label fw-semibold text-secondary small">Pilih Cabang</label>
                    <select name="id_cabang" class="form-select form-select-md border-2 bg-light">
                        <option value="">Semua Cabang Saya</option>
                        <?php foreach ($cabang_list as $c): ?>
                            <option value="<?= $c['id_cabang'] ?>" <?= (string) $id_cabang === (string) $c['id_cabang'] ? 'selected' : '' ?>><?= h($c['nama_cabang']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-lg-2 col-md-4 d-grid">
                    <button type="submit" class="btn btn-primary btn-md fw-bold"><i class="bi bi-funnel-fill me-1"></i> Filter</button>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="card border-0 shadow-sm border-start border-4 border-primary h-100" style="border-radius: 8px;">
                <div class="card-body d-flex align-items-center justify-content-between p-4">
                    <div>
                        <span class="text-muted small text-uppercase fw-bold">Total Omzet Bersih</span>
                        <h3 class="text-primary fw-bold mb-0 mt-1">Rp <?= number_format($total_omset, 0, ',', '.') ?></h3>
                    </div>
                    <div class="bg-primary bg-opacity-10 text-primary p-3 rounded-3"><i class="bi bi-wallet2 fs-3"></i></div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card border-0 shadow-sm border-start border-4 border-success h-100" style="border-radius: 8px;">
                <div class="card-body d-flex align-items-center justify-content-between p-4">
                    <div>
                        <span class="text-muted small text-uppercase fw-bold">Net Profit</span>
                        <h3 class="text-success fw-bold mb-0 mt-1">Rp <?= number_format($net_profit, 0, ',', '.') ?></h3>
                    </div>
                    <div class="bg-success bg-opacity-10 text-success p-3 rounded-3"><i class="bi bi-graph-up-arrow fs-3"></i></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm" style="border-radius: 12px; overflow: hidden;">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 text-nowrap">
                    <thead class="table-light text-secondary border-bottom">
                        <tr>
                            <th class="py-3 px-4 text-center" width="5%">No</th>
                            <th class="py-3">Tanggal</th>
                            <th class="py-3">Cabang</th>
                            <th class="py-3">Omzet Bersih</th>
                            <th class="py-3">Pengeluaran</th>
                            <th class="py-3">Operasional</th>
                            <th class="py-3">Sisa Tunai</th>
                            <th class="py-3">Pencairan QRIS</th>
                            <th class="py-3">Sisa QRIS</th>
                            <th class="py-3">Net Profit</th>
                            <th class="py-3">Margin</th>
                            <th class="py-3 text-center" width="10%">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$data || $data->num_rows === 0): ?>
                        <tr><td colspan="12" class="text-center py-5 text-muted">Belum ada data laporan pada periode ini</td></tr>
                    <?php else: $no = $offset + 1; while ($row = $data->fetch_assoc()):
                        $lengkap = ($row['status_laporan'] ?? 'lengkap') === 'lengkap';
                        $libur   = ($row['status_laporan'] ?? '') === 'libur';
                        $margin = $row['persentase'] ?? 0;
                    ?>
                        <tr>
                            <td class="text-center px-4 text-muted fw-bold"><?= $no++ ?></td>
                            <td>
                                <span class="badge bg-light text-dark border p-2 d-block mb-1"><i class="bi bi-calendar3 me-1 text-muted"></i><?= date('d/m/Y', strtotime($row['tanggal'])) ?></span>
                                <?php if ($libur): ?><span class="badge bg-secondary-subtle text-secondary"><i class="bi bi-moon-stars-fill me-1"></i>Libur/Tutup</span>
                                <?php elseif ($lengkap): ?><span class="badge bg-success-subtle text-success">Selesai</span>
                                <?php else: ?><span class="badge bg-warning-subtle text-warning">Menunggu Input</span><?php endif; ?>
                            </td>
                            <td class="fw-semibold text-dark"><?= h($row['nama_cabang']) ?></td>
                            <?php if ($libur): ?>
                                <td colspan="8" class="text-center text-muted fst-italic"><i class="bi bi-moon-stars-fill me-1"></i> Warung Libur / Tutup — tidak ada laporan keuangan</td>
                            <?php elseif (!$lengkap): ?>
                                <td colspan="8" class="text-muted small fst-italic">Laporan keuangan belum diinput</td>
                            <?php else: ?>
                            <td><span class="text-dark fw-bold">Rp <?= number_format($row['total_omset'] ?? 0, 0, ',', '.') ?></span></td>
                            <td><span class="text-secondary fw-semibold">Rp <?= number_format($row['total_pengeluaran'] ?? 0, 0, ',', '.') ?></span></td>
                            <td><span class="text-danger fw-semibold">Rp <?= number_format($row['total_operasional'] ?? 0, 0, ',', '.') ?></span></td>
                            <td><span class="fw-bold text-success">Rp <?= number_format($row['sisa_tunai'] ?? 0, 0, ',', '.') ?></span></td>
                            <td><span class="fw-semibold text-primary">Rp <?= number_format($row['pencairan_qris'] ?? 0, 0, ',', '.') ?></span></td>
                            <td><span class="fw-bold text-info">Rp <?= number_format($row['sisa_qris'] ?? 0, 0, ',', '.') ?></span></td>
                            <td><span class="fw-bold <?= ($row['net_profit'] ?? 0) >= 0 ? 'text-success' : 'text-danger' ?>">Rp <?= number_format($row['net_profit'] ?? 0, 0, ',', '.') ?></span></td>
                            <td><span class="badge <?= $margin >= 20 ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning' ?> px-2 py-1 rounded"><?= number_format($margin, 2) ?>%</span></td>
                            <?php endif; ?>
                            <td class="text-center px-4">
                                <?php if ($libur): ?>
                                    <span class="text-muted small fst-italic">Tidak ada laporan</span>
                                <?php else: ?>
                                <a href="input_laporan.php?id_cabang=<?= (int) $row['id_cabang'] ?>&tanggal=<?= h($row['tanggal']) ?>" class="btn btn-sm btn-outline-primary px-3 rounded-pill fw-medium">
                                    <i class="bi bi-pencil-square me-1"></i> <?= $lengkap ? 'Lihat/Edit' : 'Isi' ?>
                                </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="px-3">
                <?php render_pagination($page, $total_pages, ['from' => $offset + 1, 'to' => min($offset + $limit, $total_data), 'total' => $total_data, 'label' => 'laporan']); ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const mode = document.getElementById('filterMode');
    const form = document.getElementById('filterForm');
    if (!mode || !form) return;

    function toggleInput() {
        document.querySelectorAll('.filter-input').forEach(el => el.classList.add('d-none'));
        document.getElementById('input-' + mode.value).classList.remove('d-none');
    }
    toggleInput();
    mode.addEventListener('change', toggleInput);

    form.addEventListener('submit', function () {
        const val = mode.value;
        let awal = '', akhir = '';
        if (val == 'harian') {
            awal = akhir = document.querySelector('input[name="tgl"]').value;
        }
        if (val == 'mingguan') {
            const valWeek = document.querySelector('input[name="minggu"]').value;
            const [year, week] = valWeek.split('-W');
            const d = new Date(year, 0, 1 + (week - 1) * 7);
            const day = d.getDay();
            const diff = d.getDate() - day + (day == 0 ? -6 : 1);
            d.setDate(diff);
            awal = d.toISOString().split('T')[0];
            d.setDate(d.getDate() + 6);
            akhir = d.toISOString().split('T')[0];
        }
        if (val == 'bulanan') {
            const [year, month] = document.querySelector('input[name="bulan"]').value.split('-');
            awal = `${year}-${month}-01`;
            akhir = new Date(year, month, 0).toISOString().split('T')[0];
        }
        document.getElementById('tgl_awal').value = awal;
        document.getElementById('tgl_akhir').value = akhir;
    });
});
</script>
