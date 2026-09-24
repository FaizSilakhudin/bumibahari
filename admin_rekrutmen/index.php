<?php
require '../config/koneksi.php';
include 'sidebar.php';

// 1. PROTEKSI ROLE REKRUTMEN
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'rekrutmen') {
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

$id_user = current_user_id();

// 2. PROSES TAMBAH / EDIT
if (isset($_POST['simpan'])) {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        echo "<script>alert('Token CSRF tidak valid!'); history.back();</script>";
        exit;
    }

    $id_calon          = !empty($_POST['id_calon']) ? (int) $_POST['id_calon'] : null;
    $nama_calon        = trim($_POST['nama_calon'] ?? '');
    $usia              = !empty($_POST['usia']) ? (int) $_POST['usia'] : null;
    $alamat            = trim($_POST['alamat'] ?? '');
    $no_hp             = trim($_POST['no_hp'] ?? '');
    $tanggal_interview = $_POST['tanggal_interview'] ?? '';
    $interviewer       = trim($_POST['interviewer'] ?? '');
    $catatan_identitas = trim($_POST['catatan_identitas'] ?? '');
    $catatan_pengetahuan_wbb = trim($_POST['catatan_pengetahuan_wbb'] ?? '');
    $catatan_kesiapan_sistem = trim($_POST['catatan_kesiapan_sistem'] ?? '');
    $catatan_komitmen_karier = trim($_POST['catatan_komitmen_karier'] ?? '');
    $catatan_komitmen_akhir  = trim($_POST['catatan_komitmen_akhir'] ?? '');
    $kesimpulan_interviewer  = in_array($_POST['kesimpulan_interviewer'] ?? '', ['direkomendasikan', 'dipertimbangkan', 'tes_memasak', 'belum_direkomendasikan'], true)
        ? $_POST['kesimpulan_interviewer'] : 'dipertimbangkan';
    $catatan_kesimpulan = trim($_POST['catatan_kesimpulan'] ?? '');

    if ($nama_calon === '') {
        echo "<script>alert('Nama calon pengelola wajib diisi!'); history.back();</script>";
        exit;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal_interview)) {
        echo "<script>alert('Tanggal interview wajib diisi!'); history.back();</script>";
        exit;
    }

    if ($id_calon) {
        $stmt = $conn->prepare("UPDATE calon_pengelola SET
                nama_calon = ?, usia = ?, alamat = ?, no_hp = ?, tanggal_interview = ?, interviewer = ?,
                catatan_identitas = ?, catatan_pengetahuan_wbb = ?, catatan_kesiapan_sistem = ?,
                catatan_komitmen_karier = ?, catatan_komitmen_akhir = ?,
                kesimpulan_interviewer = ?, catatan_kesimpulan = ?
            WHERE id = ?");
        $stmt->bind_param(
            "sisssssssssssi",
            $nama_calon, $usia, $alamat, $no_hp, $tanggal_interview, $interviewer,
            $catatan_identitas, $catatan_pengetahuan_wbb, $catatan_kesiapan_sistem,
            $catatan_komitmen_karier, $catatan_komitmen_akhir,
            $kesimpulan_interviewer, $catatan_kesimpulan, $id_calon
        );
        $stmt->execute();
        $stmt->close();
        audit($conn, 'calon_pengelola_edit', 'calon_pengelola', $id_calon, ['nama_calon' => $nama_calon]);
        echo "<script>alert('Data calon pengelola berhasil diperbarui'); window.location='index';</script>";
        exit;
    }

    $stmt = $conn->prepare("INSERT INTO calon_pengelola
            (nama_calon, usia, alamat, no_hp, tanggal_interview, interviewer,
             catatan_identitas, catatan_pengetahuan_wbb, catatan_kesiapan_sistem,
             catatan_komitmen_karier, catatan_komitmen_akhir,
             kesimpulan_interviewer, catatan_kesimpulan, id_user_input)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param(
        "sisssssssssssi",
        $nama_calon, $usia, $alamat, $no_hp, $tanggal_interview, $interviewer,
        $catatan_identitas, $catatan_pengetahuan_wbb, $catatan_kesiapan_sistem,
        $catatan_komitmen_karier, $catatan_komitmen_akhir,
        $kesimpulan_interviewer, $catatan_kesimpulan, $id_user
    );
    $stmt->execute();
    $new_id = $conn->insert_id;
    $stmt->close();

    audit($conn, 'calon_pengelola_tambah', 'calon_pengelola', $new_id, ['nama_calon' => $nama_calon]);

    kirim_notifikasi(
        $conn,
        semua_user_pusat($conn),
        'calon_pengelola_baru',
        'Calon Pengelola Baru: ' . $nama_calon,
        'Diinput oleh ' . current_username() . ' pada ' . date('d M Y', strtotime($tanggal_interview)) . '. Kesimpulan interviewer: ' . kesimpulan_label($kesimpulan_interviewer) . '.',
        'data_calon_pengelola'
    );

    echo "<script>alert('Data calon pengelola berhasil disimpan & sudah dikirim ke Admin Pusat'); window.location='index';</script>";
    exit;
}

// 3. PROSES HAPUS
if (isset($_POST['hapus'])) {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        echo "<script>alert('Token CSRF tidak valid!'); history.back();</script>";
        exit;
    }
    $id_calon = (int) $_POST['id_calon'];
    $nama_dihapus = $conn->query("SELECT nama_calon FROM calon_pengelola WHERE id=" . (int) $id_calon)->fetch_assoc()['nama_calon'] ?? null;

    $del = $conn->prepare("DELETE FROM calon_pengelola WHERE id = ?");
    $del->bind_param("i", $id_calon);
    $del->execute();
    $del->close();

    audit($conn, 'calon_pengelola_hapus', 'calon_pengelola', $id_calon, ['nama_calon' => $nama_dihapus]);

    echo "<script>alert('Data calon pengelola berhasil dihapus'); window.location='index';</script>";
    exit;
}

// --- FILTER & PAGINATION ---
$search = trim($_GET['search'] ?? '');
$where_sql = "";
$params = [];
$types = "";

if ($search !== '') {
    $where_sql = " WHERE cp.nama_calon LIKE ? OR cp.interviewer LIKE ? OR cp.no_hp LIKE ? ";
    $search_param = "%{$search}%";
    $params = [$search_param, $search_param, $search_param];
    $types = "sss";
}

$limit  = 20;
$page   = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

$sql_count = "SELECT COUNT(*) total FROM calon_pengelola cp $where_sql";
$stmt_count = $conn->prepare($sql_count);
if ($search !== '') $stmt_count->bind_param($types, ...$params);
$stmt_count->execute();
$total_data = (int) $stmt_count->get_result()->fetch_assoc()['total'];
$stmt_count->close();
$total_pages = max(1, (int) ceil($total_data / $limit));

$sql = "SELECT cp.*, u.username AS nama_input
        FROM calon_pengelola cp
        LEFT JOIN users u ON u.id = cp.id_user_input
        $where_sql
        ORDER BY cp.created_at DESC LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
if ($search !== '') {
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
    .title-mark { width: 12px; height: 12px; background-color: #0d9488; border-radius: 4px; display: inline-block; margin-right: 10px; }

    .btn-premium { background-color: #0d9488 !important; color: #ffffff !important; border: none !important; padding: 10px 20px; border-radius: 12px; font-weight: 600; font-size: 14px; transition: all 0.2s ease; }
    .btn-premium:hover { background-color: #0f766e !important; transform: translateY(-1px); box-shadow: 0px 8px 20px rgba(13, 148, 136, 0.2); }
    .btn-premium-outline { background-color: #f0fdfa !important; color: #0d9488 !important; border: 1px solid #99f6e4 !important; padding: 10px 16px; border-radius: 12px; font-weight: 600; font-size: 14px; }
    .btn-premium-outline:hover { background-color: #ccfbf1 !important; }

    .form-control-premium, .form-select-premium { border-radius: 12px !important; border: 1px solid #e0e7ff !important; padding: 10px 16px; color: #1b2559; font-size: 14px; background-color: #ffffff; }
    .form-control-premium:focus, .form-select-premium:focus { border-color: #0d9488 !important; box-shadow: 0 0 0 4px rgba(13, 148, 136, 0.1) !important; }

    .btn-action-edit { background-color: #fff3cd; color: #856404; border: none; padding: 6px 12px; border-radius: 8px; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; cursor: pointer; }
    .btn-action-edit:hover { background-color: #ffe8a1; color: #856404; }
    .btn-action-delete { background-color: #fde8e8; color: #ef4444; border: none; padding: 6px 12px; border-radius: 8px; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; }
    .btn-action-delete:hover { background-color: #fbd5d5; }
    .btn-action-view { background-color: #e0f2fe; color: #0369a1; border: none; padding: 6px 12px; border-radius: 8px; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; }
    .btn-action-view:hover { background-color: #bae6fd; }

    .table-saas { margin-bottom: 0; width: 100% !important; }
    .table-saas thead th { background-color: #f8f9fc !important; color: #8f9bba !important; font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #eef2f9 !important; padding: 14px 12px; }
    .table-saas tbody td { padding: 14px 12px; border-bottom: 1px solid #f4f7fe !important; color: #2b3674; font-size: 14px; vertical-align: middle; }

    .modal-premium .modal-content { border-radius: 24px !important; border: none !important; box-shadow: 0px 24px 48px rgba(112, 144, 176, 0.15) !important; }
    .form-section-title { font-weight: 700; color: #0d9488; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; margin: 20px 0 10px; padding-top: 12px; border-top: 1px solid #eef2f9; }
    .form-section-title:first-child { margin-top: 0; padding-top: 0; border-top: none; }

    .mobile-card { display: none; }
    @media (max-width: 768px) {
        .table-desktop { display: none; }
        .mobile-card { display: block; }
        .search-container { width: 100% !important; }
        .search-container input { width: 100% !important; }
        .btn-premium { width: 100%; justify-content: center; }
        .saas-card { padding: 12px; }
    }
</style>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <div class="d-flex align-items-center">
                <span class="title-mark"></span>
                <h3 class="fw-bold mb-0" style="color: #1b2559;">Data Calon Pengelola</h3>
            </div>
            <span class="text-muted small ms-4">Hasil interview calon pengelola Warteg Bumi Bahari</span>
        </div>
    </div>

    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-stretch align-items-sm-center gap-3 mb-4">
        <button type="button" class="btn btn-premium d-flex align-items-center gap-2" onclick="resetForm()">
            <i class="bi bi-person-plus-fill"></i> Tambah Calon Pengelola
        </button>

        <form method="GET" class="d-flex gap-2 search-container">
            <input type="text" name="search" class="form-control form-control-premium" placeholder="Cari nama, interviewer, no HP..." value="<?= h($search) ?>">
            <button type="submit" class="btn btn-premium-outline"><i class="bi bi-search"></i></button>
            <?php if ($search !== ''): ?>
                <a href="index" class="btn btn-premium-outline bg-white text-secondary"><i class="bi bi-arrow-clockwise"></i></a>
            <?php endif; ?>
        </form>
    </div>

    <div class="card saas-card p-0 overflow-hidden border-0">
        <div class="table-responsive table-desktop">
            <table class="table table-saas align-middle mb-0">
                <thead>
                    <tr>
                        <th width="4%" class="text-center">No</th>
                        <th width="18%">Nama Calon</th>
                        <th width="10%">Tgl Interview</th>
                        <th width="14%">Interviewer</th>
                        <th width="16%">Kesimpulan Interviewer</th>
                        <th width="14%">Status Tindak Lanjut</th>
                        <th width="10%">Diinput Oleh</th>
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
                                <button type="button" class="btn btn-action-edit" onclick='editCalon(<?= json_encode($d, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                                    <i class="bi bi-pencil-square"></i>
                                </button>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Yakin menghapus data <?= h($d['nama_calon']) ?>?')">
                                    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                                    <input type="hidden" name="id_calon" value="<?= (int) $d['id'] ?>">
                                    <button type="submit" name="hapus" class="btn btn-action-delete"><i class="bi bi-trash-fill"></i></button>
                                </form>
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
                        <button type="button" class="btn btn-action-edit flex-fill py-2" onclick='editCalon(<?= json_encode($d, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'><i class="bi bi-pencil-square me-1"></i> Edit</button>
                        <form method="POST" class="flex-fill" onsubmit="return confirm('Yakin menghapus data <?= h($d['nama_calon']) ?>?')">
                            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                            <input type="hidden" name="id_calon" value="<?= (int) $d['id'] ?>">
                            <button type="submit" name="hapus" class="btn btn-action-delete w-100 py-2"><i class="bi bi-trash-fill"></i></button>
                        </form>
                    </div>
                </div>
            <?php endforeach; endif; ?>
        </div>

        <?php render_pagination($page, $total_pages, ['from' => $offset + 1, 'to' => min($offset + $limit, $total_data), 'total' => $total_data, 'label' => 'calon pengelola']); ?>
    </div>
</div>

<!-- MODAL TAMBAH / EDIT -->
<div class="modal fade modal-premium" id="modalCalon" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="id_calon" id="id_calon" value="">
                <div class="modal-header border-bottom-0 pb-0">
                    <div>
                        <h5 class="modal-title fw-bold" id="modalTitle" style="color: #1b2559;">Tambah Calon Pengelola</h5>
                        <small class="text-muted">Ringkasan hasil interview calon pengelola</small>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">

                    <div class="form-section-title">Identitas Calon</div>
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label small fw-bold text-muted">Nama Calon Pengelola</label>
                            <input type="text" name="nama_calon" id="nama_calon" class="form-control form-control-premium" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-muted">Usia</label>
                            <input type="number" name="usia" id="usia" class="form-control form-control-premium" min="0" max="100">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">No. HP</label>
                            <input type="text" name="no_hp" id="no_hp" class="form-control form-control-premium">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">Alamat</label>
                            <input type="text" name="alamat" id="alamat" class="form-control form-control-premium">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">Tanggal Interview</label>
                            <input type="date" name="tanggal_interview" id="tanggal_interview" class="form-control form-control-premium" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">Interviewer</label>
                            <input type="text" name="interviewer" id="interviewer" class="form-control form-control-premium">
                        </div>
                    </div>

                    <div class="form-section-title">A. Identitas &amp; Pengalaman Kerja</div>
                    <textarea name="catatan_identitas" id="catatan_identitas" class="form-control form-control-premium" rows="3" placeholder="Pengalaman kerja, pernah mengelola warteg, omzet, alasan berhenti, penguasaan menu, dsb."></textarea>

                    <div class="form-section-title">B. Pengetahuan tentang Warteg Bumi Bahari</div>
                    <textarea name="catatan_pengetahuan_wbb" id="catatan_pengetahuan_wbb" class="form-control form-control-premium" rows="3" placeholder="Tahu WBB dari mana, alasan tertarik, pendapat soal outlet WBB, dsb."></textarea>

                    <div class="form-section-title">C. Kesiapan Ikuti Sistem &amp; Kebijakan WBB</div>
                    <textarea name="catatan_kesiapan_sistem" id="catatan_kesiapan_sistem" class="form-control form-control-premium" rows="2" placeholder="Jawaban kesiapan mengikuti SOP, evaluasi, dan arahan manajemen."></textarea>

                    <div class="form-section-title">D. Komitmen &amp; Jenjang Karier</div>
                    <textarea name="catatan_komitmen_karier" id="catatan_komitmen_karier" class="form-control form-control-premium" rows="3" placeholder="Evaluasi komunikasi, kemampuan memasak, kedisiplinan, kemampuan mengelola outlet, integritas."></textarea>

                    <div class="form-section-title">F. Pertanyaan Komitmen Akhir</div>
                    <textarea name="catatan_komitmen_akhir" id="catatan_komitmen_akhir" class="form-control form-control-premium" rows="3" placeholder="Kesiapan dievaluasi/dipindah outlet, rencana tingkatkan penjualan, target bergabung, dsb."></textarea>

                    <div class="form-section-title">Kesimpulan Interviewer</div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <select name="kesimpulan_interviewer" id="kesimpulan_interviewer" class="form-select form-select-premium">
                                <option value="direkomendasikan">Direkomendasikan</option>
                                <option value="dipertimbangkan">Dipertimbangkan / Tes Lanjutan</option>
                                <option value="tes_memasak">Tes Memasak</option>
                                <option value="belum_direkomendasikan">Belum Direkomendasikan</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <input type="text" name="catatan_kesimpulan" id="catatan_kesimpulan" class="form-control form-control-premium" placeholder="Catatan tambahan (opsional)">
                        </div>
                    </div>

                </div>
                <div class="modal-footer border-top-0 pt-0">
                    <button type="button" class="btn btn-premium-outline text-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" name="simpan" class="btn btn-premium">Simpan Data</button>
                </div>
            </form>
        </div>
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

function resetForm() {
    document.getElementById('modalTitle').innerText = 'Tambah Calon Pengelola';
    document.getElementById('id_calon').value = '';
    document.getElementById('nama_calon').value = '';
    document.getElementById('usia').value = '';
    document.getElementById('no_hp').value = '';
    document.getElementById('alamat').value = '';
    document.getElementById('tanggal_interview').value = '';
    document.getElementById('interviewer').value = '';
    document.getElementById('catatan_identitas').value = '';
    document.getElementById('catatan_pengetahuan_wbb').value = '';
    document.getElementById('catatan_kesiapan_sistem').value = '';
    document.getElementById('catatan_komitmen_karier').value = '';
    document.getElementById('catatan_komitmen_akhir').value = '';
    document.getElementById('kesimpulan_interviewer').value = 'dipertimbangkan';
    document.getElementById('catatan_kesimpulan').value = '';
    getModalInstance('modalCalon').show();
}

function editCalon(d) {
    document.getElementById('modalTitle').innerText = 'Edit Data Calon Pengelola';
    document.getElementById('id_calon').value = d.id || '';
    document.getElementById('nama_calon').value = d.nama_calon || '';
    document.getElementById('usia').value = d.usia || '';
    document.getElementById('no_hp').value = d.no_hp || '';
    document.getElementById('alamat').value = d.alamat || '';
    document.getElementById('tanggal_interview').value = d.tanggal_interview || '';
    document.getElementById('interviewer').value = d.interviewer || '';
    document.getElementById('catatan_identitas').value = d.catatan_identitas || '';
    document.getElementById('catatan_pengetahuan_wbb').value = d.catatan_pengetahuan_wbb || '';
    document.getElementById('catatan_kesiapan_sistem').value = d.catatan_kesiapan_sistem || '';
    document.getElementById('catatan_komitmen_karier').value = d.catatan_komitmen_karier || '';
    document.getElementById('catatan_komitmen_akhir').value = d.catatan_komitmen_akhir || '';
    document.getElementById('kesimpulan_interviewer').value = d.kesimpulan_interviewer || 'dipertimbangkan';
    document.getElementById('catatan_kesimpulan').value = d.catatan_kesimpulan || '';
    getModalInstance('modalCalon').show();
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
        <p class="mb-1"><strong>Status tindak lanjut (Admin Pusat):</strong> ${STATUS_LABEL[d.status_tindak_lanjut] || 'Menunggu'}</p>
        ${d.catatan_pusat ? '<p class="mb-0"><strong>Catatan Admin Pusat:</strong> ' + esc(d.catatan_pusat) + '</p>' : ''}
    `;
    getModalInstance('modalDetail').show();
}
</script>
