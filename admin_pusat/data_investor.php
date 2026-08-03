<?php
require '../config/koneksi.php';
include 'sidebar_pusat.php';

// HELPER BIAR GAK ERROR
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

// 1. PROTEKSI ROLE PUSAT
if(!isset($_SESSION['role']) || $_SESSION['role']!= 'pusat'){
    header("Location:../login.php"); exit;
}

$upload_dir = '../uploads/surat_perjanjian/';

// Proses tambah/edit
if(isset($_POST['simpan'])){
    if(!csrf_check($_POST['csrf']?? '')){ die("<script>alert('Token tidak valid!'); history.back();</script>"); }
    
    $id = $_POST['id_investor'] ?? '';
    $nama = trim($_POST['nama_investor']);
    $hp = trim($_POST['no_hp']);
    $alamat = trim($_POST['alamat']);
    $no_rek = trim($_POST['no_rekening']);
    $nama_bank = trim($_POST['nama_bank']);
    $file_lama = $_POST['file_lama'] ?? '';
    
    // 2. UPLOAD FILE AMAN: Validasi tipe, ukuran 5MB, rename
    $file_surat = $file_lama;
    if(isset($_FILES['surat_perjanjian']) && $_FILES['surat_perjanjian']['error'] == 0 && $_FILES['surat_perjanjian']['name']!= ''){
        if(!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        $cek = getimagesize($_FILES['surat_perjanjian']['tmp_name']);
        $ext = strtolower(pathinfo($_FILES['surat_perjanjian']['name'], PATHINFO_EXTENSION));
        $allowed = ['pdf','jpg','jpeg','png'];
        
        $is_pdf = $ext=='pdf' && $_FILES['surat_perjanjian']['type']=='application/pdf';
        $is_img = $cek!== false && in_array($ext, ['jpg','jpeg','png']);
        
        if(($is_pdf || $is_img) && $_FILES['surat_perjanjian']['size'] < 5000000){ // max 5MB
            $file_surat = 'SP_'.uniqid().'.'.$ext;
            move_uploaded_file($_FILES['surat_perjanjian']['tmp_name'], $upload_dir.$file_surat);
            
            // hapus file lama kalau ada
            if($file_lama && file_exists($upload_dir.$file_lama)) @unlink($upload_dir.$file_lama);
        }
    }

    if($id){
        $stmt = $conn->prepare("UPDATE investor SET nama_investor=?, no_hp=?, alamat=?, no_rekening=?, nama_bank=?, surat_perjanjian=? WHERE id_investor=?");
        $stmt->bind_param("ssssssi", $nama,$hp,$alamat,$no_rek,$nama_bank,$file_surat,$id);
        $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $conn->prepare("INSERT INTO investor (nama_investor, no_hp, alamat, no_rekening, nama_bank, surat_perjanjian) VALUES (?,?,?,?,?,?)");
        $stmt->bind_param("ssssss", $nama,$hp,$alamat,$no_rek,$nama_bank,$file_surat);
        $stmt->execute();
        $stmt->close();
    }
    echo "<script>alert('Data berhasil disimpan');window.location='data_investor.php'</script>"; exit;
}

// 3. HAPUS PAKAI POST + CSRF
if(isset($_POST['hapus'])){
    if(!csrf_check($_POST['csrf']?? '')){ die("<script>alert('Token tidak valid!'); history.back();</script>"); }
    
    $id = (int)$_POST['id_investor'];

    // Ambil nama file dulu buat dihapus
    $q = $conn->prepare("SELECT surat_perjanjian FROM investor WHERE id_investor=?");
    $q->bind_param("i", $id);
    $q->execute();
    $res = $q->get_result();
    if($res->num_rows > 0){
        $file = $res->fetch_assoc()['surat_perjanjian'];
        if($file && file_exists($upload_dir.$file)) @unlink($upload_dir.$file);
    }
    $q->close();
    
    // Lepas relasi dulu biar gak error FK
    $up = $conn->prepare("UPDATE cabang SET id_investor=NULL WHERE id_investor=?");
    $up->bind_param("i",$id);
    $up->execute();
    $up->close();

    // Baru hapus investor
    $del = $conn->prepare("DELETE FROM investor WHERE id_investor=?");
    $del->bind_param("i", $id);
    $del->execute();
    $del->close();

    echo "<script>alert('Data berhasil dihapus');window.location='data_investor.php'</script>"; exit;
}

// 4. FILTER + PAGINATION - TAMBAHAN
$search = $_GET['search'] ?? '';
$where_sql = "";
$params = [];
$types = "";

if($search != ''){
    $where_sql = "WHERE nama_investor LIKE ? OR no_hp LIKE ? OR nama_bank LIKE ?";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types = "sss";
}

$limit = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$page = $page < 1 ? 1 : $page;
$offset = ($page - 1) * $limit;

// Hitung total data
$sql_count = "SELECT COUNT(*) as total FROM investor $where_sql";
$stmt_count = $conn->prepare($sql_count);
if($search) $stmt_count->bind_param($types, ...$params);
$stmt_count->execute();
$total_data = $stmt_count->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_data / $limit);

// Ambil data dengan LIMIT
$sql = "SELECT * FROM investor $where_sql ORDER BY id_investor DESC LIMIT ? OFFSET ?";
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
$investor = $stmt->get_result();

// 5. EDIT PAKAI GET TAPI AMAN DENGAN PREPARED
$edit = null;
if(isset($_GET['edit'])){
    $stmt = $conn->prepare("SELECT * FROM investor WHERE id_investor=?");
    $stmt->bind_param("i", $_GET['edit']);
    $stmt->execute();
    $edit = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
?>

<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
    body {
        background-color: #f4f7fe!important;
        font-family: 'Plus Jakarta Sans', sans-serif!important;
        color: #1b2559;
    }
    
    .saas-card {
        background: #ffffff;
        border: none!important;
        border-radius: 20px!important;
        box-shadow: 0px 18px 40px rgba(112, 144, 176, 0.06)!important;
        padding: 24px;
    }

    .title-mark {
        width: 12px;
        height: 12px;
        background-color: #4318ff;
        border-radius: 4px;
        display: inline-block;
        margin-right: 10px;
    }

    .btn-premium {
        background-color: #4318ff!important;
        color: #ffffff!important;
        border: none!important;
        padding: 10px 20px;
        border-radius: 12px;
        font-weight: 600;
        font-size: 14px;
        transition: all 0.2s ease;
    }
    .btn-premium:hover {
        background-color: #3310cc!important;
        transform: translateY(-1px);
        box-shadow: 0px 8px 20px rgba(67, 24, 255, 0.15);
    }
    .btn-premium-outline {
        background-color: #f4f7fe!important;
        color: #4318ff!important;
        border: 1px solid #e0e7ff!important;
        padding: 10px 20px;
        border-radius: 12px;
        font-weight: 600;
        font-size: 14px;
    }

    .form-control-premium {
        border-radius: 12px!important;
        border: 1px solid #e0e7ff!important;
        padding: 10px 16px;
        color: #1b2559;
        font-size: 14px;
    }
    .form-control-premium:focus {
        border-color: #4318ff!important;
        box-shadow: 0 0 0 4px rgba(67, 24, 255, 0.1)!important;
    }

    .btn-action-edit {
        background-color: #fff3cd;
        color: #856404;
        border: none;
        padding: 8px 14px;
        border-radius: 10px;
        font-size: 13px;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }
    .btn-action-edit:hover { background-color: #ffe8a1; }
    
    .btn-action-delete {
        background-color: #fde8e8;
        color: #ef4444;
        border: none;
        padding: 8px 14px;
        border-radius: 10px;
        font-size: 13px;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }
    .btn-action-delete:hover { background-color: #fbd5d5; }

    .btn-action-info {
        background-color: #dbeafe;
        color: #2563eb;
        border: none;
        padding: 8px 14px;
        border-radius: 10px;
        font-size: 13px;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    } 
    .btn-action-info:hover { background-color: #bfdbfe; }

    /* Pagination Style */
    .pagination .page-link {border-radius: 10px!important; margin: 0 3px; border: 1px solid #e0e7ff; color: #4318ff; font-weight: 600;}
    .pagination .page-item.active .page-link {background-color: #4318ff; border-color: #4318ff; color: #fff;}
    .pagination .page-link:hover {background-color: #e0e7ff;}

    /* Table Base Layout */
    .table-saas {
        margin-bottom: 0;
        width: 100%!important;
    }
    .table-saas thead th {
        background-color: #f8f9fc!important;
        color: #8f9bba!important;
        font-weight: 600;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        border-bottom: 1px solid #eef2f9!important;
        padding: 16px 12px;
        border-top: none!important;
    }
    .table-saas tbody td {
        padding: 16px 12px;
        border-bottom: 1px solid #f4f7fe!important;
        color: #1b2559;
        font-size: 14px;
        vertical-align: middle;
    }
    .table-saas tbody tr:hover {
        background-color: rgba(244, 247, 254, 0.5);
    }

    .modal-premium .modal-content {
        border-radius: 24px!important;
        border: none!important;
        box-shadow: 0px 24px 48px rgba(112, 144, 176, 0.15)!important;
    }
    .modal-premium .modal-header { border-bottom: 1px solid #f4f7fe!important; padding: 20px 24px!important; }
    .modal-premium .modal-body { padding: 24px!important; }
    .modal-premium .modal-footer { border-top: 1px solid #f4f7fe!important; padding: 16px 24px!important; }

    .badge.bg-primary-subtle{background:#dbeafe; color:#2563eb;}

    /* Perubahan Khusus Layar HP / Mobile */
    @media (max-width: 767.98px) {
        .saas-card { padding: 16px!important; }
        .header-container { flex-direction: column; align-items: flex-start!important; gap: 16px; }
        .header-container button { width: 100%; justify-content: center; }
        .search-box-container { width: 100%!important; }
        .search-box-container input { width: 100%!important; }
        
        .table-saas thead { display: none; }
        .table-saas tbody tr { 
            display: block; 
            border: 1px solid #e0e7ff; 
            border-radius: 16px; 
            margin-bottom: 16px; 
            padding: 16px;
            background: #ffffff;
            box-shadow: 0px 4px 12px rgba(112, 144, 176, 0.03);
        }
        .table-saas tbody td { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            padding: 8px 0!important; 
            border-bottom: 1px dashed #f4f7fe!important;
            text-align: right;
        }
        .table-saas tbody td:last-child { border-bottom: none!important; padding-top: 12px!important; }
        
        .table-saas tbody td::before {
            content: attr(data-label);
            font-weight: 600;
            color: #8f9bba;
            font-size: 13px;
            text-transform: uppercase;
            text-align: left;
            margin-right: 15px;
        }
        .table-saas tbody td .d-inline-flex { width: 100%; }
        .table-saas tbody td .btn-action-info, 
        .table-saas tbody td .btn-action-edit, 
        .table-saas tbody td .btn-action-delete { flex: 1; }
    }
</style>

<div class="container-fluid py-4">
    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center header-container mb-4">
        <div>
            <div class="d-flex align-items-center">
                <span class="title-mark"></span>
                <h3 class="fw-bold mb-0" style="color: #1b2559; font-size: calc(1.3rem + 0.6vw);">Data Investor</h3>
            </div>
            <span class="text-muted small ms-sm-4 d-block mt-1 mt-sm-0">Manajemen data investor Warteg Bumi Bahari</span>
        </div>
        <button class="btn btn-premium d-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#modalInvestor" onclick="resetForm()">
            <i class="bi bi-plus-circle-fill"></i> Tambah Investor Baru
        </button>
    </div>

    <!-- FORM FILTER -->
    <div class="d-flex flex-column flex-md-row justify-content-end gap-2 mb-3">
        <form method="GET" class="d-flex gap-2 search-box-container">
            <input type="text" name="search" class="form-control form-control-premium" placeholder="Cari nama, no HP, bank..." value="<?= h($search)?>" style="width: 280px;">
            <button type="submit" class="btn btn-premium-outline d-flex align-items-center gap-2">
                <i class="bi bi-search"></i> Cari
            </button>
            <?php if($search):?>
            <a href="data_investor.php" class="btn btn-premium-outline bg-white text-secondary d-flex align-items-center justify-content-center">
                <i class="bi bi-arrow-clockwise"></i>
            </a>
            <?php endif;?>
        </form>
    </div>

    <div class="card saas-card p-0 overflow-hidden border-0">
        <div class="table-responsive-md">
            <table class="table table-saas align-middle mb-0">
                <thead>
                    <tr>
                        <th width="5%" class="text-center">No</th>
                        <th width="18%">Nama Investor</th>
                        <th width="12%">Telephone</th>
                        <th width="20%">Alamat</th>
                        <th width="12%">Rekening</th>
                        <th width="10%">Bank</th>
                        <th width="8%">Surat</th>
                        <th width="15%" class="text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if($investor->num_rows==0):?>
                    <tr>
                        <td colspan="8" class="text-center py-5 text-muted">
                            <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                            Belum ada data investor
                        </td>
                    </tr>
                    <?php else: $no=$offset+1; while($d=$investor->fetch_assoc()):?>
                    <tr>
                        <td data-label="No" class="text-center text-muted fw-semibold"><?= $no++?></td>
                        <td data-label="Nama Investor"><span class="fw-bold" style="color: #1b2559;"><?= h($d['nama_investor'])?></span></td>
                        <td data-label="No HP" class="text-secondary"><?= h($d['no_hp'])?></td>
                        <td data-label="Alamat" class="text-secondary" title="<?= h($d['alamat'])?>"><?= substr(h($d['alamat']),0,30)?><?= strlen($d['alamat'])>30?'...':''?></td>
                        <td data-label="No Rekening" class="font-monospace text-secondary" style="font-size: 13px;"><?= h($d['no_rekening'])?></td>
                        <td data-label="Nama Bank">
                            <?php if($d['nama_bank']):?>
                                <span class="badge bg-light text-dark px-2 py-1 border text-uppercase" style="font-size: 10px; letter-spacing: 0.5px;"><?= h($d['nama_bank'])?></span>
                            <?php else:?>
                                <span class="text-muted">-</span>
                            <?php endif;?>
                        </td>
                        <td data-label="Surat">
                            <?php if($d['surat_perjanjian']):?>
                                <a href="../uploads/surat_perjanjian/<?= h($d['surat_perjanjian'])?>" target="_blank" class="btn btn-sm btn-outline-success" style="font-size: 12px; padding: 4px 8px;">
                                    <i class="bi bi-file-earmark-pdf"></i> Lihat
                                </a>
                            <?php else:?>
                                <span class="text-muted small">-</span>
                            <?php endif;?>
                        </td>
                        <td data-label="Aksi" class="text-center">
                            <div class="d-inline-flex gap-2 w-100 justify-content-end justify-content-md-center">
                                <button class="btn btn-action-info" data-bs-toggle="modal" data-bs-target="#modalDetail<?= $d['id_investor']?>" title="Detail Cabang">
                                    <i class="bi bi-eye me-1 d-md-none"></i> <span class="d-none d-md-inline"><i class="bi bi-eye"></i></span> Detail
                                </button>
                                <button class="btn btn-action-edit" data-bs-toggle="modal" data-bs-target="#modalInvestor" onclick="editInvestor(<?= $d['id_investor']?>, '<?= addslashes(h($d['nama_investor']))?>', '<?= addslashes(h($d['no_hp']))?>', '<?= addslashes(h($d['alamat']))?>', '<?= addslashes(h($d['no_rekening']))?>', '<?= addslashes(h($d['nama_bank']))?>', '<?= h($d['surat_perjanjian'])?>')" title="Edit">
                                    <i class="bi bi-pencil-square me-1 d-md-none"></i> <span class="d-none d-md-inline"><i class="bi bi-pencil-square"></i></span> Edit
                                </button>
                                
                                <form method="POST" class="d-inline" onsubmit="return confirm('Yakin hapus investor <?= addslashes(h($d['nama_investor']))?>? Data cabang akan dilepas.')">
                                    <input type="hidden" name="csrf" value="<?=csrf_token()?>">
                                    <input type="hidden" name="id_investor" value="<?= $d['id_investor']?>">
                                    <button type="submit" name="hapus" class="btn btn-action-delete" title="Hapus">
                                        <i class="bi bi-trash-fill me-1 d-md-none"></i> <span class="d-none d-md-inline"><i class="bi bi-trash-fill"></i></span> Hapus
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; endif;?>
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

    <?php 
    
    // Loop modal detail - harus ikut filter juga tapi jangan pakai LIMIT
    $where_modal = "";
    $params_modal = [];
    $types_modal = "";
    if($search != ''){
        $where_modal = "WHERE nama_investor LIKE ? OR no_hp LIKE ? OR nama_bank LIKE ?";
        $params_modal = ["%$search%", "%$search%", "%$search%"];
        $types_modal = "sss";
    }

    $sql_modal = "SELECT * FROM investor $where_modal ORDER BY id_investor DESC";
    $stmt_modal = $conn->prepare($sql_modal);
    if($search) $stmt_modal->bind_param($types_modal, ...$params_modal);
    $stmt_modal->execute();
    $investor_modal = $stmt_modal->get_result();
    while($d=$investor_modal->fetch_assoc()):
    ?>
    <div class="modal fade modal-premium" id="modalDetail<?= $d['id_investor']?>" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title fw-bold" style="color: #1b2559;">Cabang Milik <?= h($d['nama_investor'])?></h5>
                        <small class="text-muted">Daftar cabang yang diinvestasikan</small>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php 
                    $stmt = $conn->prepare("SELECT nama_cabang, nama_pengelola, alamat FROM cabang WHERE id_investor=? ORDER BY nama_cabang ASC");
                    $stmt->bind_param("i", $d['id_investor']);
                    $stmt->execute();
                    $cabang_inv = $stmt->get_result();
                    if($cabang_inv->num_rows > 0):?>
                    <div class="table-responsive">
                        <table class="table table-saas table-sm mb-0">
                            <thead>
                                <tr>
                                    <th>Nama Cabang</th>
                                    <th>Nama Pengelola</th>
                                    <th>Alamat</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($c=$cabang_inv->fetch_assoc()):?>
                                <tr>
                                    <td class="fw-semibold"><?= h($c['nama_cabang'])?></td>
                                    <td><?= h($c['nama_pengelola'])?></td>
                                    <td class="text-secondary"><?= h($c['alamat'])?></td>
                                </tr>
                                <?php endwhile;?>
                            </tbody>
                        </table>
                    </div>
                    <?php else:?>
                    <div class="text-center py-4 text-muted">
                        <i class="bi bi-building-x fs-2 d-block mb-2"></i>
                        Investor ini belum memiliki cabang
                    </div>
                    <?php endif; $stmt->close();?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-premium-outline text-secondary" data-bs-dismiss="modal">Tutup</button>
                </div>
            </div>
        </div>
    </div>
    <?php endwhile;?>
</div>

<div class="modal fade modal-premium" id="modalInvestor" tabindex="-1">
<div class="modal-dialog modal-dialog-centered modal-lg">
<form method="POST" enctype="multipart/form-data">
<input type="hidden" name="csrf" value="<?=csrf_token()?>">
<div class="modal-content">
    <div class="modal-header">
        <div>
            <h5 class="modal-title fw-bold" style="color: #1b2559;" id="modalTitle"><?= $edit? 'Modifikasi Data Investor' : 'Registrasi Investor Baru'?></h5>
            <small class="text-muted" id="modalSub"><?= $edit? 'Perbarui data investor' : 'Tambahkan data investor komersial baru'?></small>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body">
        <input type="hidden" name="id_investor" id="id_investor" value="<?= $edit['id_investor']?? ''?>">
        <input type="hidden" name="file_lama" id="file_lama" value="<?= $edit['surat_perjanjian']?? ''?>">

        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label small fw-bold text-muted mb-1">Nama Investor <span class="text-danger">*</span></label>
                <input type="text" name="nama_investor" id="nama_investor" class="form-control form-control-premium" value="<?= h($edit['nama_investor']?? '')?>" required>
            </div>
            <div class="col-md-6">
                <label class="form-label small fw-bold text-muted mb-1">Telepohone</label>
                <input type="text" name="no_hp" id="no_hp" class="form-control form-control-premium" value="<?= h($edit['no_hp']?? '')?>" placeholder="08xxxxxxxxxx">
            </div>
        </div>

        <div class="mt-3">
            <label class="form-label small fw-bold text-muted mb-1">Alamat</label>
            <textarea name="alamat" id="alamat" class="form-control form-control-premium" rows="2" placeholder="Alamat lengkap"><?= h($edit['alamat']?? '')?></textarea>
        </div>

        <div class="row g-3 mt-1">
            <div class="col-md-6">
                <label class="form-label small fw-bold text-muted mb-1">Rekening</label>
                <input type="text" name="no_rekening" id="no_rekening" class="form-control form-control-premium" value="<?= h($edit['no_rekening']?? '')?>" placeholder="1234567890">
            </div>
            <div class="col-md-6">
                <label class="form-label small fw-bold text-muted mb-1">Bank</label>
                <input type="text" name="nama_bank" id="nama_bank" class="form-control form-control-premium" value="<?= h($edit['nama_bank']?? '')?>" placeholder="BCA / BNI / Mandiri">
            </div>
        </div>

        <div class="mt-3">
            <label class="form-label small fw-bold text-muted mb-1">Surat Perjanjian</label>
            <input type="file" name="surat_perjanjian" class="form-control form-control-premium" accept=".pdf,.jpg,.jpeg,.png">
            <?php if($edit && $edit['surat_perjanjian']):?>
            <small class="text-muted" id="file_info">File saat ini: <?= h($edit['surat_perjanjian'])?>. Upload baru untuk ganti.</small>
            <?php else:?>
            <small class="text-muted" id="file_info"></small>
            <?php endif;?>
        </div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-premium-outline text-secondary" data-bs-dismiss="modal">Batal</button>
        <button type="submit" name="simpan" class="btn btn-premium">Simpan Data</button>
    </div>
</div>
</form>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function resetForm(){
    document.getElementById('id_investor').value = '';
    document.getElementById('file_lama').value = '';
    document.getElementById('nama_investor').value = '';
    document.getElementById('no_hp').value = '';
    document.getElementById('alamat').value = '';
    document.getElementById('no_rekening').value = '';
    document.getElementById('nama_bank').value = '';
    document.getElementById('modalTitle').innerText = 'Registrasi Investor Baru';
    document.getElementById('modalSub').innerText = 'Tambahkan data investor komersial baru';
    document.getElementById('file_info').innerText = '';
}

function editInvestor(id, nama, hp, alamat, no_rek, bank, file){
    document.getElementById('id_investor').value = id;
    document.getElementById('file_lama').value = file;
    document.getElementById('nama_investor').value = nama;
    document.getElementById('no_hp').value = hp;
    document.getElementById('alamat').value = alamat;
    document.getElementById('no_rekening').value = no_rek;
    document.getElementById('nama_bank').value = bank;
    document.getElementById('modalTitle').innerText = 'Modifikasi Data Investor';
    document.getElementById('modalSub').innerText = 'Perbarui data investor';
    document.getElementById('file_info').innerText = file? 'File saat ini: ' + file + '. Upload baru untuk ganti.' : '';
}

// Buka modal otomatis kalau mode edit
<?php if($edit):?>
var modal = new bootstrap.Modal(document.getElementById('modalInvestor'));
modal.show();
<?php endif;?>
</script>