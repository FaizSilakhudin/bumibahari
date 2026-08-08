<?php
require '../config/koneksi.php';
include 'sidebar_pusat.php';

// 1. PROTEKSI ROLE PUSAT
if(!isset($_SESSION['role']) || $_SESSION['role']!= 'pusat'){
    header("Location:../login"); exit;
}

// Helper
if(!function_exists('h')){ function h($s){ return htmlspecialchars($s??'', ENT_QUOTES); } }
if(!function_exists('csrf_token')){
    function csrf_token(){
        if(empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
        return $_SESSION['csrf'];
    }
}
if(!function_exists('csrf_check')){
    function csrf_check($t){ return hash_equals($_SESSION['csrf']??'', $t); }
}

// Filter
$search = $_GET['search'] ?? '';
$where_sql = "WHERE u.role='cabang'";
$params = [];
$types = "";
if($search!= ''){
    $where_sql .= " AND (u.nama_pengelola LIKE ? OR c.nama_cabang LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= "ss";
}

// BARU: Ambil total semua pengelola untuk card di atas
$total_pengelola_all = $conn->query("SELECT COUNT(*) as total FROM users WHERE role='cabang'")->fetch_assoc()['total'];

// Tambah pengelola
if(isset($_POST['tambah'])){
    if(!csrf_check($_POST['csrf']?? '')){ die("<script>alert('Token tidak valid!'); history.back();</script>"); }
    
    $username = trim($_POST['username']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $nama = $_POST['nama_pengelola'];
    $id_cabang = (int)$_POST['id_cabang'];
    $no_rek = $_POST['no_rekening'];
    $bank = $_POST['nama_bank'];
    $atas_nama = $_POST['atas_nama_rekening']; // BARU
    $tgl_mulai = $_POST['tgl_mulai'];
    $tgl_selesai = !empty($_POST['tgl_selesai']) ? $_POST['tgl_selesai'] : null;
    $role = 'cabang';

    // 1. CEK USERNAME DUPLIKAT DI PERIODE AKTIF
    $cek_user = $conn->prepare("SELECT id FROM users WHERE username=? AND tgl_selesai IS NULL");
    $cek_user->bind_param("s", $username);
    $cek_user->execute();
    if($cek_user->get_result()->num_rows > 0){
        echo "<script>alert('Gagal! Username $username sudah dipakai pengelola aktif'); history.back();</script>";
        exit;
    }

    // 2. CEK TUMPANG TINDIH PERIODE
    $cek = $conn->prepare("SELECT * FROM users WHERE id_cabang=? AND role='cabang' AND tgl_selesai IS NULL");
    $cek->bind_param("i", $id_cabang);
    $cek->execute();
    if($cek->get_result()->num_rows > 0 && $tgl_selesai === null){
        echo "<script>alert('Cabang ini masih punya pengelola aktif!'); history.back();</script>";
        exit;
    } else {
        // PERBAIKAN: 10 kolom = 10 tanda ? dan 10 bind_param
        $stmt = $conn->prepare("INSERT INTO `users` (`username`,`password`,`nama_pengelola`,`no_rekening`,`nama_bank`,`atas_nama_rekening`,`tgl_mulai`,`tgl_selesai`,`role`,`id_cabang`) VALUES (?,?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param("sssssssssi", $username,$password,$nama,$no_rek,$bank,$atas_nama,$tgl_mulai,$tgl_selesai,$role,$id_cabang);
        
        if($stmt->execute()){
            echo "<script>alert('Pengelola berhasil ditambah'); window.location='data_pengelola';</script>";
        } else {
            echo "<script>alert('Gagal Insert: ".$stmt->error."'); history.back();</script>";
        }
    }
}

// Edit pengelola - VERSI RIWAYAT
if(isset($_POST['edit'])){
    if(!csrf_check($_POST['csrf']?? '')){ die("<script>alert('Token tidak valid!'); history.back();</script>"); }
    
    $id = $_POST['id'];
    $username = $_POST['username'];
    $nama = $_POST['nama_pengelola'];
    $id_cabang = $_POST['id_cabang'];
    $no_rek = $_POST['no_rekening'];
    $bank = $_POST['nama_bank'];
    $atas_nama = $_POST['atas_nama_rekening']; // BARU
    $tgl_mulai = $_POST['tgl_mulai'];
    $tgl_selesai = $_POST['tgl_selesai'] ?: NULL;

    // 1. AMBIL DATA LAMA DULU BUAT BANDINGIN
    $cek_lama = $conn->prepare("SELECT * FROM users WHERE id=?");
    $cek_lama->bind_param("i", $id);
    $cek_lama->execute();
    $data_lama = $cek_lama->get_result()->fetch_assoc();
    $cek_lama->close();

    // 2. CEK APAKAH ADA PERUBAHAN DATA KRITIS
    $ada_perubahan = (
        $data_lama['nama_pengelola'] != $nama ||
        $data_lama['id_cabang'] != $id_cabang ||
        $data_lama['no_rekening'] != $no_rek ||
        $data_lama['nama_bank'] != $bank ||
        $data_lama['atas_nama_rekening'] != $atas_nama || // BARU
        $data_lama['tgl_mulai'] != $tgl_mulai ||
        $data_lama['tgl_selesai'] != $tgl_selesai
    );

    if($ada_perubahan){
        // 3a. KALAU ADA PERUBAHAN -> TUTUP DATA LAMA
        $close_stmt = $conn->prepare("UPDATE users SET tgl_selesai=? WHERE id=?");
        $close_stmt->bind_param("si", $tgl_mulai, $id); 
        $close_stmt->execute();
        $close_stmt->close();

        // 3b. BUAT DATA BARU DENGAN PERIODE BARU
        $pass = $data_lama['password']; 
        $role = 'cabang'; 
        if(!empty($_POST['password'])){
            $pass = password_hash($_POST['password'], PASSWORD_DEFAULT);
        }

        $insert_stmt = $conn->prepare("INSERT INTO `users` (`username`,`password`,`nama_pengelola`,`no_rekening`,`nama_bank`,`atas_nama_rekening`,`tgl_mulai`,`tgl_selesai`,`role`,`id_cabang`) VALUES (?,?,?,?,?,?,?,?,?,?)"); // BARU
        $insert_stmt->bind_param("sssssssssi", $username,$pass,$nama,$no_rek,$bank,$atas_nama,$tgl_mulai,$tgl_selesai,$role,$id_cabang); // BARU
        $insert_stmt->execute();
        $insert_stmt->close();

        echo "<script>alert('Periode baru berhasil dibuat. Data lama diarsipkan.'); window.location='data_pengelola';</script>";

    } else {
        // 4. KALAU CUMA GANTI PASSWORD SAJA -> UPDATE BIASA
        if(!empty($_POST['password'])){
            $pass = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $sql = "UPDATE users SET password=? WHERE id=?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("si", $pass, $id);
            $stmt->execute();
        }
        echo "<script>alert('Data berhasil diupdate'); window.location='data_pengelola';</script>";
    }
}

// HAPUS PAKAI POST + CSRF - BARU DITAMBAH
if(isset($_POST['hapus'])){
    if(!csrf_check($_POST['csrf']?? '')){ die("<script>alert('Token tidak valid!'); history.back();</script>"); }
    
    $id = $_POST['id'];
    $del = $conn->prepare("DELETE FROM users WHERE id=?");
    $del->bind_param("i", $id);
    if($del->execute()){
        echo "<script>alert('Pengelola berhasil dihapus'); window.location='data_pengelola';</script>";
    } else {
        echo "<script>alert('Gagal hapus: ".$del->error."'); history.back();</script>";
    }
}

// 3. PAGINATION
$limit = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$page = $page < 1 ? 1 : $page;
$offset = ($page - 1) * $limit;

// Hitung total data
$sql_count = "SELECT COUNT(*) as total FROM users u LEFT JOIN cabang c ON u.id_cabang=c.id_cabang $where_sql";
$stmt_count = $conn->prepare($sql_count);
if($search) $stmt_count->bind_param($types, ...$params);
$stmt_count->execute();
$total_data = $stmt_count->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_data / $limit);

// 4. SELECT DATA PAKAI PREPARED + LIMIT
$sql = "SELECT u.*, c.nama_cabang FROM users u LEFT JOIN cabang c ON u.id_cabang=c.id_cabang $where_sql ORDER BY c.nama_cabang ASC, u.tgl_mulai DESC LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
if($search){
    $types .= "ii";
    $params[] = $limit;
    $params[] = $offset;
    $stmt->bind_param($types, ...$params);
} else {
    $stmt->bind_param("ii", $limit, $offset);
}
$stmt->execute();
$data = $stmt->get_result();

$cabang = $conn->query("SELECT * FROM cabang");
$no = $offset + 1;
?>

<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
    body {background-color: #f4f7fe!important;font-family: 'Plus Jakarta Sans', sans-serif!important;color: #1b2559;}
    .saas-card {background: #ffffff;border: none!important;border-radius: 20px!important;box-shadow: 0px 18px 40px rgba(112, 144, 176, 0.06)!important;padding: 24px;}
    .title-mark {width: 12px;height: 12px;background-color: #4318ff;border-radius: 4px;display: inline-block;margin-right: 10px;}
    .btn-premium {background-color: #4318ff!important;color: #ffffff!important;border: none!important;padding: 10px 20px;border-radius: 12px;font-weight: 600;font-size: 14px;transition: all 0.2s ease;}
    .btn-premium:hover {background-color: #3310cc!important;transform: translateY(-1px);box-shadow: 0px 8px 20px rgba(67, 24, 255, 0.15);}
    .btn-premium-outline {background-color: #f4f7fe!important;color: #4318ff!important;border: 1px solid #e0e7ff!important;padding: 10px 20px;border-radius: 12px;font-weight: 600;font-size: 14px;}
    .btn-premium-outline:hover {background-color: #e0e7ff!important;}
    .form-control-premium, .form-select-premium {border-radius: 12px!important;border: 1px solid #e0e7ff!important;padding: 10px 16px;color: #1b2559;font-size: 14px;background-color: #ffffff;}
    .form-control-premium:focus, .form-select-premium:focus {border-color: #4318ff!important;box-shadow: 0 0 0 4px rgba(67, 24, 255, 0.1)!important;}
    .btn-action-edit {background-color: #fff3cd;color: #856404;border: none;padding: 8px 14px;border-radius: 10px;font-size: 13px;font-weight: 600;display: inline-flex;align-items: center;justify-content: center;}
    .btn-action-edit:hover { background-color: #ffe8a1; }
    .btn-action-delete {background-color: #fde8e8;color: #ef4444;border: none;padding: 8px 14px;border-radius: 10px;font-size: 13px;font-weight: 600;display: inline-flex;align-items: center;justify-content: center;}
    .btn-action-delete:hover { background-color: #fbd5d5; }
    .pagination .page-link {border-radius: 10px!important; margin: 0 3px; border: 1px solid #e0e7ff; color: #4318ff; font-weight: 600;}
    .pagination .page-item.active .page-link {background-color: #4318ff; border-color: #4318ff; color: #fff;}
    .pagination .page-link:hover {background-color: #e0e7ff;}
    .stat-card {border-radius: 16px; padding: 20px; background: #fff; border: 1px solid #e0e7ff;}
    .stat-card .icon {width: 48px; height: 48px; background: #f4f7fe; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 24px; color: #4318ff;}
    .table-saas {margin-bottom: 0;width: 100%!important;}
    .table-saas thead th {background-color: #f8f9fc!important;color: #8f9bba!important;font-weight: 600;font-size: 12px;text-transform: uppercase;letter-spacing: 0.5px;border-bottom: 1px solid #eef2f9!important;padding: 16px 12px;border-top: none!important;}
    .table-saas tbody td {padding: 16px 12px;border-bottom: 1px solid #f4f7fe!important;color: #1b2559;font-size: 14px;vertical-align: middle;}
    .table-saas tbody tr:hover {background-color: rgba(244, 247, 254, 0.5);}
    .modal-premium .modal-content {border-radius: 24px!important;border: none!important;box-shadow: 0px 24px 48px rgba(112, 144, 176, 0.15)!important;}
    .modal-premium .modal-header { border-bottom: 1px solid #f4f7fe!important; padding: 20px 24px!important; }
    .modal-premium .modal-body { padding: 24px!important; }
    .modal-premium .modal-footer { border-top: 1px solid #f4f7fe!important; padding: 16px 24px!important; }
    @media (max-width: 767.98px) {
        .saas-card { padding: 16px!important; }
        .header-container { flex-direction: column; align-items: flex-start!important; gap: 12px; }
        .action-container { flex-direction: column; align-items: stretch!important; gap: 16px; }
        .action-container form { flex-direction: column; width: 100%; }
        .action-container input { width: 100%!important; }
        .action-container button, .action-container a { flex: 1; justify-content: center; }
        .table-saas thead { display: none; }
        .table-saas tbody tr { display: block; border: 1px solid #e0e7ff; border-radius: 16px; margin-bottom: 16px; padding: 16px;background: #ffffff;box-shadow: 0px 4px 12px rgba(112, 144, 176, 0.03);}
        .table-saas tbody td { display: flex; justify-content: space-between; align-items: center; padding: 10px 0!important; border-bottom: 1px dashed #f4f7fe!important;text-align: right;}
        .table-saas tbody td:last-child { border-bottom: none!important; padding-top: 14px!important; }
        .table-saas tbody td::before {content: attr(data-label);font-weight: 600;color: #8f9bba;font-size: 13px;text-transform: uppercase;text-align: left;margin-right: 15px;}
        .table-saas tbody td .d-inline-flex { width: 100%; }
        .table-saas tbody td .btn-action-edit, .table-saas tbody td .btn-action-delete { flex: 1; }
    }
</style>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center header-container mb-4">
        <div>
            <div class="d-flex align-items-center">
                <span class="title-mark"></span>
                <h3 class="fw-bold mb-0" style="color: #1b2559; font-size: calc(1.3rem + 0.6vw);">Data Pengelola</h3>
            </div>
            <span class="text-muted small ms-sm-4 d-block mt-1 mt-sm-0">Manajemen otoritas kredensial dan akun penanggung jawab cabang</span>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-md-4">
            <div class="stat-card d-flex align-items-center gap-3">
                <div class="icon"><i class="bi bi-person-badge"></i></div>
                <div>
                    <div class="text-muted small">Total Pengelola</div>
                    <div class="fw-bold fs-3"><?= number_format($total_pengelola_all)?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-between align-items-center action-container gap-3 mb-4">
        <div>
            <button class="btn btn-premium d-flex align-items-center gap-2 w-100 justify-content-center" data-bs-toggle="modal" data-bs-target="#modalTambah">
                <i class="bi bi-plus-circle-fill"></i> Tambah Pengelola
            </button>
        </div>
        
        <form method="GET" class="d-flex gap-2">
            <input type="text" name="search" class="form-control form-control-premium" placeholder="Cari nama pengelola/cabang..." value="<?= h($search)?>" style="width: 300px;">
            <div class="d-flex gap-2 w-100">
                <button type="submit" class="btn btn-premium-outline d-flex align-items-center gap-2 justify-content-center">
                    <i class="bi bi-search"></i> Cari
                </button>
                <?php if($search):?>
                <a href="data_pengelola" class="btn btn-premium-outline bg-white text-secondary d-flex align-items-center justify-content-center">
                    <i class="bi bi-arrow-clockwise"></i>
                </a>
                <?php endif;?>
            </div>
        </form>
    </div>

    <div class="card saas-card p-0 overflow-hidden border-0">
        <div class="table-responsive-md">
            <table class="table table-saas align-middle mb-0">
                <thead>
                    <tr>
                        <th width="5%" class="text-center">No</th>
                        <th width="14%">Nama Cabang</th>
                        <th width="14%">Nama Pengelola</th>
                        <th width="10%">Username</th>
                        <th width="12%">Periode Kelola</th>
                        <th width="11%">Rekening</th>
                        <th width="7%">Bank</th>
                        <th width="12%">Atas Nama Rekening</th>
                        <th width="15%" class="text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if($data->num_rows == 0):?>
                    <tr><td colspan="9" class="text-center text-muted py-5 fw-semibold"><i class="bi bi-inbox fs-2 d-block mb-2"></i> Data tidak ditemukan</td></tr>
                    <?php endif;?>
                    
                    <?php while($row = $data->fetch_assoc()):?>
                    <tr>
                        <td data-label="No" class="text-center text-muted fw-semibold"><?= $no++?></td>
                        <td data-label="Nama Cabang"><span class="fw-bold" style="color: #1b2559;"><?= h($row['nama_cabang']?? '-')?></span></td>
                        <td data-label="Nama Pengelola">
                            <span class="fw-semibold" style="color: #1b2559;"><?= h($row['nama_pengelola'])?></span>
                            <?php if($row['tgl_selesai'] == NULL):?>
                                <span class="badge bg-success-subtle text-success border-success-subtle ms-1">Aktif</span>
                            <?php endif;?>
                        </td>
                        <td data-label="Username" class="text-secondary"><?= h($row['username'])?></td>
                        <td data-label="Periode">
                            <small>
                                <?= date('d M Y', strtotime($row['tgl_mulai']))?> 
                                <i class="bi bi-arrow-right"></i> 
                                <?= $row['tgl_selesai'] ? date('d M Y', strtotime($row['tgl_selesai'])) : '<span class="text-success fw-bold">Sekarang</span>' ?>
                            </small>
                        </td>
                        
                        <td data-label="No Rekening" class="font-monospace text-secondary" style="font-size: 13px;">
                            <?= !empty($row['no_rekening']) ? h($row['no_rekening']) : '<span class="text-muted">-</span>' ?>
                        </td>

                        <td data-label="Bank">
                            <?php if($row['nama_bank']):?>
                                <span class="badge bg-light text-dark px-2 py-1 border text-uppercase" style="font-size: 10px; letter-spacing: 0.5px;"><?= h($row['nama_bank'])?></span>
                            <?php else:?>
                                <span class="text-muted">-</span>
                            <?php endif;?>
                        </td>

                        <td data-label="Atas Nama Rekening" class="fw-semibold">
                            <?= !empty($row['atas_nama_rekening']) ? h($row['atas_nama_rekening']) : '<span class="text-muted">-</span>' ?>
                        </td>

                        <td data-label="Aksi" class="text-center">
                            <div class="d-inline-flex gap-2 w-100 justify-content-end justify-content-md-center">
                                <button class="btn btn-action-edit" data-bs-toggle="modal" data-bs-target="#modalEdit<?= $row['id']?>" title="Edit">
                                    <i class="bi bi-pencil-square me-1 d-md-none"></i> <span class="d-none d-md-inline"><i class="bi bi-pencil-square"></i></span> Edit
                                </button>
                                
                                <form method="POST" class="d-inline" onsubmit="return confirm('Hapus pengelola <?= h($row['nama_pengelola'])?>?')">
                                    <input type="hidden" name="csrf" value="<?=csrf_token()?>">
                                    <input type="hidden" name="id" value="<?= $row['id']?>">
                                    <button type="submit" name="hapus" class="btn btn-action-delete" title="Hapus">
                                        <i class="bi bi-trash-fill me-1 d-md-none"></i> <span class="d-none d-md-inline"><i class="bi bi-trash-fill"></i></span> Hapus
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>

                    <div class="modal fade modal-premium" id="modalEdit<?= $row['id']?>" tabindex="-1">
                        <div class="modal-dialog modal-dialog-centered modal-lg">
                            <form method="POST">
                                <input type="hidden" name="csrf" value="<?=csrf_token()?>">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <div>
                                            <h5 class="modal-title fw-bold" style="color: #1b2559;">Edit Data Kredensial Pengelola</h5>
                                            <small class="text-muted">Perbarui hak akses dan data bank akun cabang</small>
                                        </div>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <input type="hidden" name="id" value="<?= $row['id']?>">
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <label class="form-label small fw-bold text-muted mb-1">Nama Cabang</label>
                                                <select name="id_cabang" class="form-select-premium w-100" required>
                                                    <?php $cabang->data_seek(0); while($c=$cabang->fetch_assoc()):?>
                                                    <option value="<?= $c['id_cabang']?>" <?= $c['id_cabang']==$row['id_cabang']?'selected':''?>><?= h($c['nama_cabang'])?></option>
                                                    <?php endwhile;?>
                                                </select>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small fw-bold text-muted mb-1">Nama Pengelola</label>
                                                <input type="text" name="nama_pengelola" value="<?= h($row['nama_pengelola'])?>" class="form-control form-control-premium" required>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small fw-bold text-muted mb-1">Username</label>
                                                <input type="text" name="username" value="<?= h($row['username'])?>" class="form-control form-control-premium" required>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small fw-bold text-muted mb-1">Password Baru</label>
                                                <input type="password" name="password" class="form-control form-control-premium" placeholder="Kosongkan jika tidak ganti">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small fw-bold text-muted mb-1">Tgl Mulai Kelola</label>
                                                <input type="date" name="tgl_mulai" value="<?= $row['tgl_mulai']?>" class="form-control form-control-premium" required>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small fw-bold text-muted mb-1">Tgl Selesai Kelola</label>
                                                <input type="date" name="tgl_selesai" value="<?= $row['tgl_selesai']?>" class="form-control form-control-premium" placeholder="Kosongkan jika masih aktif">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small fw-bold text-muted mb-1">Rekening</label>
                                                <input type="text" name="no_rekening" value="<?= h($row['no_rekening'])?>" class="form-control form-control-premium" placeholder="327031647">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small fw-bold text-muted mb-1">Bank</label>
                                                <input type="text" name="nama_bank" value="<?= h($row['nama_bank'])?>" class="form-control form-control-premium" placeholder="BCA">
                                            </div>
                                            <div class="col-md-12">
                                                <label class="form-label small fw-bold text-muted mb-1">Atas Nama Rekening</label>
                                                <input type="text" name="atas_nama_rekening" value="<?= h($row['atas_nama_rekening'])?>" class="form-control form-control-premium" placeholder="PT. BUMI BAHARI SEJAHTERA">
                                            </div>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-premium-outline text-secondary" data-bs-dismiss="modal">Batal</button>
                                        <button type="submit" name="edit" class="btn btn-premium">Simpan Perubahan</button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                    <?php endwhile;?>
                </tbody>
            </table>
        </div>

        <!-- PAGINATION -->
        <?php if($total_pages > 1): ?>
        <div class="d-flex justify-content-between align-items-center p-3 border-top">
            <small class="text-muted">Menampilkan <?= $offset+1 ?> - <?= min($offset+$limit, $total_data) ?> dari <?= $total_data ?> data</small>
            <nav>
                <ul class="pagination pagination-sm mb-0">
                    <?php if($page > 1): ?>
                    <li class="page-item">
                        <a class="page-link" href="?page=<?= $page-1 ?>&search=<?= urlencode($search) ?>">Prev</a>
                    </li>
                    <?php endif; ?>

                    <?php for($i=1; $i<=$total_pages; $i++): ?>
                    <li class="page-item <?= $i==$page ? 'active' : '' ?>">
                        <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>"><?= $i ?></a>
                    </li>
                    <?php endfor; ?>

                    <?php if($page < $total_pages): ?>
                    <li class="page-item">
                        <a class="page-link" href="?page=<?= $page+1 ?>&search=<?= urlencode($search) ?>">Next</a>
                    </li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal fade modal-premium" id="modalTambah" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form method="POST">
            <input type="hidden" name="csrf" value="<?=csrf_token()?>">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title fw-bold" style="color: #1b2559;">Registrasi Pengelola Baru</h5>
                        <small class="text-muted">Buat akun manajemen penanggung jawab untuk unit cabang</small>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted mb-1">Nama Cabang</label>
                            <select name="id_cabang" class="form-select-premium w-100" required>
                                <option value="">Pilih Cabang</option>
                                <?php $cabang->data_seek(0); while($c=$cabang->fetch_assoc()):?>
                                <option value="<?= $c['id_cabang']?>"><?= h($c['nama_cabang'])?></option>
                                <?php endwhile;?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted mb-1">Nama Pengelola</label>
                            <input type="text" name="nama_pengelola" class="form-control form-control-premium" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted mb-1">Username</label>
                            <input type="text" name="username" class="form-control form-control-premium" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted mb-1">Password</label>
                            <input type="password" name="password" class="form-control form-control-premium" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted mb-1">Tgl Mulai Kelola</label>
                            <input type="date" name="tgl_mulai" class="form-control form-control-premium" value="<?= date('Y-m-d')?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted mb-1">Tgl Selesai Kelola</label>
                            <input type="date" name="tgl_selesai" class="form-control form-control-premium" placeholder="Kosongkan jika masih aktif">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted mb-1">Rekening</label>
                            <input type="text" name="no_rekening" class="form-control form-control-premium" placeholder="327031647">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted mb-1">Bank</label>
                            <input type="text" name="nama_bank" class="form-control form-control-premium" placeholder="BCA">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label small fw-bold text-muted mb-1">Atas Nama Rekening</label>
                            <input type="text" name="atas_nama_rekening" class="form-control form-control-premium" placeholder="PT. BUMI BAHARI SEJAHTERA">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-premium-outline text-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" name="tambah" class="btn btn-premium">Simpan Pengelola</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3/dist/js/bootstrap.bundle.min.js"></script>