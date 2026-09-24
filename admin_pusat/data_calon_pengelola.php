<?php
require '../config/koneksi.php';
include 'sidebar_pusat.php';

// 1. PROTEKSI ROLE PUSAT
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'pusat') {
    header("Location: ../login");
    exit;
}

function kesimpulan_badge_class(string $k): string {
    switch ($k) {
        case 'direkomendasikan':       return 'bg-success-subtle text-success';
        case 'tes_memasak':            return 'bg-info-subtle text-info';
        case 'belum_direkomendasikan': return 'bg-danger-subtle text-danger';
        default:                       return 'bg-warning-subtle text-warning';
    }
}

function kesimpulan_label(string $k): string {
    switch ($k) {
        case 'direkomendasikan':       return 'Direkomendasikan';
        case 'tes_memasak':            return 'Tes Memasak';
        case 'belum_direkomendasikan': return 'Belum Direkomendasikan';
        default:                       return 'Dipertimbangkan';
    }
}

function status_badge_class(string $s): string {
    switch ($s) {
        case 'diterima': return 'bg-success-subtle text-success';
        case 'ditolak':  return 'bg-danger-subtle text-danger';
        default:         return 'bg-secondary-subtle text-secondary';
    }
}

function status_label(string $s): string {
    switch ($s) {
        case 'diterima': return 'Diterima';
        case 'ditolak':  return 'Ditolak';
        default:         return 'Menunggu';
    }
}

// 2. PROSES UBAH STATUS TINDAK LANJUT (tidak menyentuh isi interview aslinya)
if (isset($_POST['simpan_status'])) {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        echo "<script>alert('Token CSRF tidak valid!'); history.back();</script>";
        exit;
    }
    $id_calon = (int) ($_POST['id_calon'] ?? 0);
    $status = in_array($_POST['status_tindak_lanjut'] ?? '', ['menunggu', 'diterima', 'ditolak'], true)
        ? $_POST['status_tindak_lanjut'] : 'menunggu';
    $catatan_pusat = trim($_POST['catatan_pusat'] ?? '');

    $stmt = $conn->prepare("UPDATE calon_pengelola SET status_tindak_lanjut = ?, catatan_pusat = ? WHERE id = ?");
    $stmt->bind_param("ssi", $status, $catatan_pusat, $id_calon);
    $stmt->execute();
    $stmt->close();

    audit($conn, 'calon_pengelola_status', 'calon_pengelola', $id_calon, ['status_tindak_lanjut' => $status]);

    echo "<script>alert('Status tindak lanjut berhasil disimpan'); window.location='data_calon_pengelola';</script>";
    exit;
}

// --- FILTER & PAGINATION ---
$search = trim($_GET['search'] ?? '');
$filter_status = in_array($_GET['status'] ?? '', ['menunggu', 'diterima', 'ditolak'], true) ? $_GET['status'] : '';
$where_parts = [];
$params = [];
$types = "";

if ($search !== '') {
    $where_parts[] = "(cp.nama_calon LIKE ? OR cp.interviewer LIKE ? OR cp.no_hp LIKE ?)";
    $search_param = "%{$search}%";
    array_push($params, $search_param, $search_param, $search_param);
    $types .= "sss";
}
if ($filter_status !== '') {
    $where_parts[] = "cp.status_tindak_lanjut = ?";
    $params[] = $filter_status;
    $types .= "s";
}
$where_sql = $where_parts ? (" WHERE " . implode(' AND ', $where_parts)) : "";

$limit  = 20;
$page   = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

$sql_count = "SELECT COUNT(*) total FROM calon_pengelola cp $where_sql";
$stmt_count = $conn->prepare($sql_count);
if ($types !== '') $stmt_count->bind_param($types, ...$params);
$stmt_count->execute();
$total_data = (int) $stmt_count->get_result()->fetch_assoc()['total'];
$stmt_count->close();
$total_pages = max(1, (int) ceil($total_data / $limit));

// Badge "menunggu" utk sidebar/notifikasi visual di halaman ini sendiri
$total_menunggu = (int) $conn->query("SELECT COUNT(*) c FROM calon_pengelola WHERE status_tindak_lanjut = 'menunggu'")->fetch_assoc()['c'];

$sql = "SELECT cp.*, u.username AS nama_input
        FROM calon_pengelola cp
        LEFT JOIN users u ON u.id = cp.id_user_input
        $where_sql
        ORDER BY cp.created_at DESC LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
if ($types !== '') {
    $fetch_types = $types . "ii";
    $fetch_params = array_merge($params, [$limit, $offset]);
    $stmt->bind_param($fetch_types, ...$fetch_params);
} else {
    $stmt->bind_param("ii", $limit, $offset);
}
$stmt->execute();
$calon_list = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>

<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">

<style>
    body { background-color: #f4f7fe !important; font-family: 'Plus Jakarta Sans', sans-serif !important; }

    .saas-card { background: #ffffff; border: none !important; border-radius: 20px !important; box-shadow: 0px 18px 40px rgba(112, 144, 176, 0.06) !important; padding: 20px; }
    .title-mark { width: 12px; height: 12px; background-color: #4318ff; border-radius: 4px; display: inline-block; margin-right: 10px; }

    .btn-premium { background-color: #4318ff !important; color: #ffffff !important; border: none !important; padding: 10px 20px; border-radius: 12px; font-weight: 600; font-size: 14px; }
    .btn-premium-outline { background-color: #f4f7fe !important; color: #4318ff !important; border: 1px solid #e0e7ff !important; padding: 10px 16px; border-radius: 12px; font-weight: 600; font-size: 14px; }
    .btn-premium-outline:hover { background-color: #e0e7ff !important; }

    .form-control-premium, .form-select-premium { border-radius: 12px !important; border: 1px solid #e0e7ff !important; padding: 10px 16px; color: #1b2559; font-size: 14px; background-color: #ffffff; }
    .form-control-premium:focus, .form-select-premium:focus { border-color: #4318ff !important; box-shadow: 0 0 0 4px rgba(67, 24, 255, 0.1) !important; }

    .btn-action-view { background-color: #e0f2fe; color: #0369a1; border: none; padding: 6px 12px; border-radius: 8px; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; }
    .btn-action-view:hover { background-color: #bae6fd; }
    .btn-action-status { background-color: #ede9fe; color: #6d28d9; border: none; padding: 6px 12px; border-radius: 8px; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; }
    .btn-action-status:hover { background-color: #ddd6fe; }

    .table-saas { margin-bottom: 0; width: 100% !important; }
    .table-saas thead th { background-color: #f8f9fc !important; color: #8f9bba !important; font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #eef2f9 !important; padding: 14px 12px; }
    .table-saas tbody td { padding: 14px 12px; border-bottom: 1px solid #f4f7fe !important; color: #2b3674; font-size: 14px; vertical-align: middle; }

    .modal-premium .modal-content { border-radius: 24px !important; border: none !important; box-shadow: 0px 24px 48px rgba(112, 144, 176, 0.15) !important; }
    .form-section-title { font-weight: 700; color: #4318ff; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; margin: 20px 0 10px; padding-top: 12px; border-top: 1px solid #eef2f9; }
    .form-section-title:first-child { margin-top: 0; padding-top: 0; border-top: none; }

    .mobile-card { display: none; }
    @media (max-width: 768px) {
        .table-desktop { display: none; }
        .mobile-card { display: block; }
        .search-container { width: 100% !important; }
        .search-container input { width: 100% !important; }
        .saas-card { padding: 12px; }
    }
</style>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <div class="d-flex align-items-center">
                <span class="title-mark"></span>
                <h3 class="fw-bold mb-0" style="color: #1b2559;">Data Calon Pengelola</h3>
            </div>
            <span class="text-muted small ms-4">Hasil interview dari Admin Rekrutmen</span>
        </div>
        <?php if ($total_menunggu > 0): ?>
            <span class="badge bg-warning-subtle text-warning px-3 py-2 rounded-pill fw-semibold">
                <i class="bi bi-hourglass-split me-1"></i> <?= $total_menunggu ?> menunggu tindak lanjut
            </span>
        <?php endif; ?>
    </div>

    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-stretch align-items-sm-center gap-3 mb-4">
        <form method="GET" class="d-flex gap-2">
            <select name="status" class="form-select form-select-premium" style="width:auto;" onchange="this.form.submit()">
                <option value="">Semua Status</option>
                <option value="menunggu" <?= $filter_status === 'menunggu' ? 'selected' : '' ?>>Menunggu</option>
                <option value="diterima" <?= $filter_status === 'diterima' ? 'selected' : '' ?>>Diterima</option>
                <option value="ditolak" <?= $filter_status === 'ditolak' ? 'selected' : '' ?>>Ditolak</option>
            </select>
            <input type="text" name="search" class="form-control form-control-premium" placeholder="Cari nama, interviewer, no HP..." value="<?= h($search) ?>">
            <button type="submit" class="btn btn-premium-outline"><i class="bi bi-search"></i></button>
            <?php if ($search !== '' || $filter_status !== ''): ?>
                <a href="data_calon_pengelola" class="btn btn-premium-outline bg-white text-secondary"><i class="bi bi-arrow-clockwise"></i></a>
            <?php endif; ?>
        </form>
    </div>

    <div class="card saas-card p-0 overflow-hidden border-0">
        <div class="table-responsive table-desktop">
            <table class="table table-saas align-middle mb-0">
                <thead>
                    <tr>
                        <th width="4%" class="text-center">No</th>
                        <th width="17%">Nama Calon</th>
                        <th width="10%">Tgl Interview</th>
                        <th width="13%">Interviewer</th>
                        <th width="16%">Kesimpulan Interviewer</th>
                        <th width="14%">Status Tindak Lanjut</th>
                        <th width="12%">Diinput Oleh</th>
                        <th width="14%" class="text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($calon_list)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-5 text-muted fw-semibold">
                            <i class="bi bi-inbox fs-2 d-block mb-2"></i> Belum ada data calon pengelola
                        </td>
                    </tr>
                    <?php else: $no = $offset + 1; foreach ($calon_list as $d): ?>
                    <tr>
                        <td class="text-center text-muted fw-semibold"><?= $no++ ?></td>
                        <td><span class="fw-bold text-dark"><?= h($d['nama_calon']) ?></span></td>
                        <td><?= date('d/m/Y', strtotime($d['tanggal_interview'])) ?></td>
                        <td><?= h($d['interviewer'] ?: '-') ?></td>
                        <td><span class="badge <?= kesimpulan_badge_class($d['kesimpulan_interviewer']) ?> px-3 py-2 rounded-pill"><?= kesimpulan_label($d['kesimpulan_interviewer']) ?></span></td>
                        <td><span class="badge <?= status_badge_class($d['status_tindak_lanjut']) ?> px-3 py-2 rounded-pill"><?= status_label($d['status_tindak_lanjut']) ?></span></td>
                        <td class="text-muted small"><?= h($d['nama_input'] ?? '-') ?></td>
                        <td class="text-center">
                            <div class="d-inline-flex gap-2 justify-content-center">
                                <button type="button" class="btn btn-action-view" onclick='lihatDetail(<?= json_encode($d, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                                    <i class="bi bi-eye-fill"></i>
                                </button>
                                <button type="button" class="btn btn-action-status" onclick='ubahStatus(<?= json_encode($d, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                                    <i class="bi bi-check2-square"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <div class="mobile-card p-3">
            <?php if (empty($calon_list)): ?>
                <div class="text-center py-4 text-muted"><i class="bi bi-inbox fs-2 d-block mb-2"></i> Belum ada data calon pengelola</div>
            <?php else: foreach ($calon_list as $d): ?>
                <div class="bg-light p-3 rounded-4 mb-3 border">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="fw-bold text-dark"><?= h($d['nama_calon']) ?></span>
                        <span class="badge <?= status_badge_class($d['status_tindak_lanjut']) ?>"><?= status_label($d['status_tindak_lanjut']) ?></span>
                    </div>
                    <div class="text-muted small mb-1"><?= date('d/m/Y', strtotime($d['tanggal_interview'])) ?> &middot; <?= h($d['interviewer'] ?: '-') ?></div>
                    <div class="mb-3"><span class="badge <?= kesimpulan_badge_class($d['kesimpulan_interviewer']) ?>"><?= kesimpulan_label($d['kesimpulan_interviewer']) ?></span></div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-action-view flex-fill py-2" onclick='lihatDetail(<?= json_encode($d, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'><i class="bi bi-eye-fill me-1"></i> Lihat</button>
                        <button type="button" class="btn btn-action-status flex-fill py-2" onclick='ubahStatus(<?= json_encode($d, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'><i class="bi bi-check2-square me-1"></i> Status</button>
                    </div>
                </div>
            <?php endforeach; endif; ?>
        </div>

        <?php render_pagination($page, $total_pages, ['from' => $offset + 1, 'to' => min($offset + $limit, $total_data), 'total' => $total_data, 'label' => 'calon pengelola']); ?>
    </div>
</div>

<!-- MODAL DETAIL (read-only) -->
<div class="modal fade modal-premium" id="modalDetail" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header border-bottom-0 pb-0">
                <div>
                    <h5 class="modal-title fw-bold" id="detailNama" style="color: #1b2559;"></h5>
                    <small class="text-muted" id="detailMeta"></small>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" style="max-height: 70vh; overflow-y: auto;" id="detailBody"></div>
        </div>
    </div>
</div>

<!-- MODAL UBAH STATUS -->
<div class="modal fade modal-premium" id="modalStatus" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="id_calon" id="status_id_calon" value="">
                <div class="modal-header border-bottom-0 pb-0">
                    <div>
                        <h5 class="modal-title fw-bold" style="color: #1b2559;">Ubah Status Tindak Lanjut</h5>
                        <small class="text-muted" id="statusNama"></small>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">Status</label>
                        <select name="status_tindak_lanjut" id="status_pilihan" class="form-select form-select-premium">
                            <option value="menunggu">Menunggu</option>
                            <option value="diterima">Diterima</option>
                            <option value="ditolak">Ditolak</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">Catatan Admin Pusat</label>
                        <textarea name="catatan_pusat" id="status_catatan" class="form-control form-control-premium" rows="3" placeholder="Opsional"></textarea>
                    </div>
                </div>
                <div class="modal-footer border-top-0 pt-0">
                    <button type="button" class="btn btn-premium-outline text-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" name="simpan_status" class="btn btn-premium">Simpan Status</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
const KESIMPULAN_LABEL = {
    direkomendasikan: 'Direkomendasikan',
    dipertimbangkan: 'Dipertimbangkan / Tes Lanjutan',
    tes_memasak: 'Tes Memasak',
    belum_direkomendasikan: 'Belum Direkomendasikan'
};
const STATUS_LABEL = { menunggu: 'Menunggu', diterima: 'Diterima', ditolak: 'Ditolak' };

function getModalInstance(id) {
    const modalEl = document.getElementById(id);
    return bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
}

function esc(s) {
    const d = document.createElement('div');
    d.innerText = s || '-';
    return d.innerHTML;
}

function lihatDetail(d) {
    document.getElementById('detailNama').innerText = d.nama_calon || '';
    document.getElementById('detailMeta').innerText =
        (d.tanggal_interview || '-') + ' · Interviewer: ' + (d.interviewer || '-') + ' · Diinput oleh: ' + (d.nama_input || '-');

    document.getElementById('detailBody').innerHTML = `
        <div class="form-section-title" style="margin-top:0;border-top:none;">Identitas</div>
        <p class="mb-2"><strong>Usia:</strong> ${esc(d.usia)} tahun &middot; <strong>No. HP:</strong> ${esc(d.no_hp)}</p>
        <p class="mb-3"><strong>Alamat:</strong> ${esc(d.alamat)}</p>

        <div class="form-section-title">A. Identitas &amp; Pengalaman Kerja</div>
        <p style="white-space:pre-wrap;">${esc(d.catatan_identitas)}</p>

        <div class="form-section-title">B. Pengetahuan tentang WBB</div>
        <p style="white-space:pre-wrap;">${esc(d.catatan_pengetahuan_wbb)}</p>

        <div class="form-section-title">C. Kesiapan Ikuti Sistem</div>
        <p style="white-space:pre-wrap;">${esc(d.catatan_kesiapan_sistem)}</p>

        <div class="form-section-title">D. Komitmen &amp; Jenjang Karier</div>
        <p style="white-space:pre-wrap;">${esc(d.catatan_komitmen_karier)}</p>

        <div class="form-section-title">F. Pertanyaan Komitmen Akhir</div>
        <p style="white-space:pre-wrap;">${esc(d.catatan_komitmen_akhir)}</p>

        <div class="form-section-title">Kesimpulan</div>
        <p class="mb-1"><strong>Interviewer:</strong> ${KESIMPULAN_LABEL[d.kesimpulan_interviewer] || '-'}</p>
        <p class="mb-1"><strong>Catatan:</strong> ${esc(d.catatan_kesimpulan)}</p>
        <p class="mb-1"><strong>Status tindak lanjut:</strong> ${STATUS_LABEL[d.status_tindak_lanjut] || 'Menunggu'}</p>
        ${d.catatan_pusat ? '<p class="mb-0"><strong>Catatan Admin Pusat:</strong> ' + esc(d.catatan_pusat) + '</p>' : ''}
    `;
    getModalInstance('modalDetail').show();
}

function ubahStatus(d) {
    document.getElementById('status_id_calon').value = d.id || '';
    document.getElementById('statusNama').innerText = d.nama_calon || '';
    document.getElementById('status_pilihan').value = d.status_tindak_lanjut || 'menunggu';
    document.getElementById('status_catatan').value = d.catatan_pusat || '';
    getModalInstance('modalStatus').show();
}
</script>
