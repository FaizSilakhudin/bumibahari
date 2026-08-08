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

// BARU: Ambil total semua investor untuk card di atas
$total_investor_all = $conn->query("SELECT COUNT(*) as total FROM investor")->fetch_assoc()['total'];

// Proses tambah/edit
if(isset($_POST['simpan'])){
    if(!csrf_check($_POST['csrf']?? '')){ die("<script>alert('Token tidak valid!'); history.back();</script>"); }
    
    $id = $_POST['id_investor'] ?? '';
    $nama = trim($_POST['nama_investor']);
    $hp = trim($_POST['no_hp']);
    $alamat = trim($_POST['alamat']);
    $no_rek = trim($_POST['no_rekening']);
    $nama_bank = trim($_POST['nama_bank']);
    $atas_nama = trim($_POST['atas_nama_rekening']); 
    $tgl_mulai = !empty($_POST['tgl_mulai_investasi']) ? $_POST['tgl_mulai_investasi'] : NULL; 
    $tgl_selesai = !empty($_POST['tgl_selesai_investasi']) ? $_POST['tgl_selesai_investasi'] : NULL; 
    $file_lama = $_POST['file_lama'] ?? '';
    
    // UPLOAD FILE AMAN
    $file_surat = $file_lama;
    if(isset($_FILES['surat_perjanjian']) && $_FILES['surat_perjanjian']['error'] == 0 && $_FILES['surat_perjanjian']['name']!= ''){
        if(!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        $cek = getimagesize($_FILES['surat_perjanjian']['tmp_name']);
        $ext = strtolower(pathinfo($_FILES['surat_perjanjian']['name'], PATHINFO_EXTENSION));
        $is_pdf = $ext=='pdf' && $_FILES['surat_perjanjian']['type']=='application/pdf';
        $is_img = $cek!== false && in_array($ext, ['jpg','jpeg','png']);
        
        if(($is_pdf || $is_img) && $_FILES['surat_perjanjian']['size'] < 5000000){
            $file_surat = 'SP_'.uniqid().'.'.$ext;
            move_uploaded_file($_FILES['surat_perjanjian']['tmp_name'], $upload_dir.$file_surat);
            if($file_lama && file_exists($upload_dir.$file_lama)) @unlink($upload_dir.$file_lama);
        }
    }

    if($id){
        $stmt = $conn->prepare("UPDATE investor SET nama_investor=?, no_hp=?, alamat=?, no_rekening=?, nama_bank=?, atas_nama_rekening=?, tgl_mulai_investasi=?, tgl_selesai_investasi=?, surat_perjanjian=? WHERE id_investor=?");
        $stmt->bind_param("sssssssssi", $nama,$hp,$alamat,$no_rek,$nama_bank,$atas_nama,$tgl_mulai,$tgl_selesai,$file_surat,$id); // 9s + 1i = 10
    } else {
        $stmt = $conn->prepare("INSERT INTO investor (nama_investor, no_hp, alamat, no_rekening, nama_bank, atas_nama_rekening, tgl_mulai_investasi, tgl_selesai_investasi, surat_perjanjian) VALUES (?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param("ssssssssi", $nama,$hp,$alamat,$no_rek,$nama_bank,$atas_nama,$tgl_mulai,$tgl_selesai,$file_surat); // 9s
    }

    if($stmt->execute()){
        echo "<script>alert('Data berhasil disimpan');window.location='data_investor.php'</script>"; exit;
    } else {
        echo "<script>alert('Gagal Simpan: ".$stmt->error."'); history.back();</script>"; exit;
    }
}

// HAPUS PAKAI POST + CSRF
if(isset($_POST['hapus'])){
    if(!csrf_check($_POST['csrf']?? '')){ die("<script>alert('Token tidak valid!'); history.back();</script>"); }
    
    $id = (int)$_POST['id_investor'];

    $q = $conn->prepare("SELECT surat_perjanjian FROM investor WHERE id_investor=?");
    $q->bind_param("i", $id);
    $q->execute();
    $res = $q->get_result();
    if($res->num_rows > 0){
        $file = $res->fetch_assoc()['surat_perjanjian'];
        if($file && file_exists($upload_dir.$file)) @unlink($upload_dir.$file);
    }
    $q->close();
    
    // Hapus relasi di tabel cabang_investor juga pakai prepared
    $del_relasi = $conn->prepare("DELETE FROM cabang_investor WHERE id_investor=?");
    $del_relasi->bind_param("i", $id);
    $del_relasi->execute();

    $del = $conn->prepare("DELETE FROM investor WHERE id_investor=?");
    $del->bind_param("i", $id);
    $del->execute();
    $del->close();

    echo "<script>alert('Data berhasil dihapus');window.location='data_investor.php'</script>"; exit;
}

// FILTER + PAGINATION
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

// EDIT PAKAI GET
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
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
    body {background-color: #f4f7fe!important; font-family: 'Plus Jakarta Sans', sans-serif!important; color: #1b2559;}
    .saas-card {background: #ffffff; border: none!important; border-radius: 20px!important; box-shadow: 0px 18px 40px rgba(112, 144, 176, 0.06)!important; padding: 24px;}
    .title-mark {width: 12px; height: 12px; background-color: #4318ff; border-radius: 4px; display: inline-block; margin-right: 10px;}
    .btn-premium {background-color: #4318ff!important; color: #ffffff!important; border: none!important; padding: 10px 20px; border-radius: 12px; font-weight: 600; font-size: 14px;}
    .btn-premium:hover {background-color: #3310cc!important;}
    .btn-premium-outline {background-color: #f4f7fe!important; color: #4318ff!important; border: 1px solid #e0e7ff!important; padding: 10px 20px; border-radius: 12px; font-weight: 600; font-size: 14px;}
    .form-control-premium {border-radius: 12px!important; border: 1px solid #e0e7ff!important; padding: 10px 16px;}
    .btn-action-edit {background-color: #fff3cd; color: #856404; border: none; padding: 8px 14px; border-radius: 10px; font-size: 13px; font-weight: 600;}
    .btn-action-delete {background-color: #fde8e8; color: #ef4444; border: none; padding: 8px 14px; border-radius: 10px; font-size: 13px; font-weight: 600;}
    .btn-action-info {background-color: #dbeafe; color: #2563eb; border: none; padding: 8px 14px; border-radius: 10px; font-size: 13px; font-weight: 600;} 
    .table-saas thead th {background-color: #f8f9fc!important; color: #8f9bba!important; font-weight: 600; font-size: 12px; text-transform: uppercase;}
    .modal-premium .modal-content {border-radius: 24px!important; border: none!important;}
    .pagination .page-link {color: #4318ff; border: 1px solid #e0e7ff; font-weight: 600;}
    .pagination .page-link:hover {background-color: #f4f7fe; color: #3310cc;}
    .pagination .page-item.active .page-link {background-color: #4318ff; border-color: #4318ff; color: #fff;}
    .pagination .page-item.disabled .page-link {color: #a3aed0;}
    .stat-card {border-radius: 16px; padding: 20px; background: #fff; border: 1px solid #e0e7ff;}
    .stat-card .icon {width: 48px; height: 48px; background: #f4f7fe; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 24px; color: #4318ff;}
</style>

<div class="container-fluid py-4">
    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center header-container mb-4">
        <div>
            <div class="d-flex align-items-center">
                <span class="title-mark"></span>
                <h3 class="fw-bold mb-0">Data Investor</h3>
            </div>
            <span class="text-muted small ms-sm-4">Manajemen data investor Warteg Bumi Bahari</span>
        </div>
        <button class="btn btn-premium" data-bs-toggle="modal" data-bs-target="#modalInvestor" onclick="resetForm()">
            <i class="bi bi-plus-circle-fill me-2"></i> Tambah Investor Baru
        </button>
    </div>

    <div class="row mb-4">
        <div class="col-md-4">
            <div class="stat-card d-flex align-items-center gap-3">
                <div class="icon"><i class="bi bi-people-fill"></i></div>
                <div>
                    <div class="text-muted small">Total Investor</div>
                    <div class="fw-bold fs-3"><?= number_format($total_investor_all)?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-end gap-2 mb-3">
        <form method="GET" class="d-flex gap-2">
            <input type="text" name="search" class="form-control form-control-premium" placeholder="Cari nama, no HP, bank..." value="<?= h($search)?>" style="width: 280px;">
            <button type="submit" class="btn btn-premium-outline"><i class="bi bi-search"></i> Cari</button>
        </form>
    </div>

    <div class="card saas-card p-0 overflow-hidden border-0">
        <div class="table-responsive-md">
            <table class="table table-saas align-middle mb-0">
                <thead>
                    <tr>
                        <th width="4%" class="text-center">No</th>
                        <th width="15%">Nama Investor</th>
                        <th width="10%">Telephone</th>
                        <th width="15%">Alamat</th>
                        <th width="11%">Rekening</th>
                        <th width="8%">Bank</th>
                        <th width="12%">Atas Nama Rekening</th> <!-- BARU -->
                       <th width="12%">Periode Investasi</th> <!-- BARU -->
                        <th width="6%">Surat</th>
                        <th width="7%" class="text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if($investor->num_rows==0):?>
                    <tr><td colspan="10" class="text-center py-5 text-muted"><i class="bi bi-inbox fs-1 d-block mb-2"></i>Belum ada data investor</td></tr> <!-- colspan 10 -->
                    <?php else: $no=$offset+1; while($d=$investor->fetch_assoc()):?>
                    <tr>
                        <td class="text-center text-muted fw-semibold"><?= $no++?></td>
                        <td><span class="fw-bold"><?= h($d['nama_investor'])?></span></td>
                        <td class="text-secondary"><?= h($d['no_hp'])?></td>
                        <td class="text-secondary"><?= substr(h($d['alamat']),0,30)?><?= strlen($d['alamat'])>30?'...':''?></td>
                        <td class="font-monospace text-secondary" style="font-size: 13px;"><?= h($d['no_rekening'])?></td>
                        <td><?php if($d['nama_bank']):?><span class="badge bg-light text-dark"><?= h($d['nama_bank'])?></span><?php endif;?></td>
                        
                        <td class="fw-semibold"><?= !empty($d['atas_nama_rekening']) ? h($d['atas_nama_rekening']) : '<span class="text-muted">-</span>' ?></td> <!-- BARU -->
                        
                        <td> <!-- BARU -->
                            <small>
                                <?= $d['tgl_mulai_investasi'] ? date('d M Y', strtotime($d['tgl_mulai_investasi'])) : '-' ?> 
                                <i class="bi bi-arrow-right"></i> 
                                <?= $d['tgl_selesai_investasi'] ? date('d M Y', strtotime($d['tgl_selesai_investasi'])) : '<span class="text-success fw-bold">Aktif</span>' ?>
                            </small>
                        </td>

                        <td>
                            <?php if($d['surat_perjanjian']):?>
                                <a href="../uploads/surat_perjanjian/<?= h($d['surat_perjanjian'])?>" target="_blank" class="btn btn-sm btn-outline-success"><i class="bi bi-file-earmark-pdf"></i> Lihat</a>
                            <?php else:?>-<?php endif;?>
                        </td>
                        <td class="text-center">
                            <div class="d-inline-flex gap-2">
                                <button class="btn btn-action-info" data-bs-toggle="modal" data-bs-target="#modalDetail<?= $d['id_investor']?>" title="Riwayat Cabang">
                                    <i class="bi bi-eye"></i> Riwayat
                                </button>
                                <button class="btn btn-action-edit" data-bs-toggle="modal" data-bs-target="#modalInvestor" onclick="editInvestor(<?= $d['id_investor']?>, '<?= addslashes(h($d['nama_investor']))?>', '<?= addslashes(h($d['no_hp']))?>', '<?= addslashes(h($d['alamat']))?>', '<?= addslashes(h($d['no_rekening']))?>', '<?= addslashes(h($d['nama_bank']))?>', '<?= addslashes(h($d['atas_nama_rekening']))?>', '<?= $d['tgl_mulai_investasi']?>', '<?= $d['tgl_selesai_investasi']?>', '<?= h($d['surat_perjanjian'])?>')">Edit</button> <!-- BARU -->
                                <form method="POST" class="d-inline" onsubmit="return confirm('Yakin hapus investor <?= addslashes(h($d['nama_investor']))?>?')">
                                    <input type="hidden" name="csrf" value="<?=csrf_token()?>">
                                    <input type="hidden" name="id_investor" value="<?= $d['id_investor']?>">
                                    <button type="submit" name="hapus" class="btn btn-action-delete">Hapus</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; endif;?>
                </tbody>
            </table>
        </div>

        <!-- PAGINATION BARU -->
        <?php if($total_pages > 1): ?>
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-center p-4 border-top">
            <div class="text-muted small mb-3 mb-md-0">
                Menampilkan <?= $offset + 1?> - <?= min($offset + $limit, $total_data)?> dari <?= $total_data?> data
            </div>
            <nav>
                <ul class="pagination pagination-sm mb-0" style="--bs-pagination-border-radius: 10px;">
                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=<?= $page-1 ?>&search=<?= urlencode($search)?>" style="border-radius: 10px 0 0 10px;">
                            <i class="bi bi-chevron-left"></i>
                        </a>
                    </li>
                    <?php 
                    $range = 2;
                    $start = max(1, $page - $range);
                    $end = min($total_pages, $page + $range);
                    if($start > 1){
                        echo '<li class="page-item"><a class="page-link" href="?page=1&search='.urlencode($search).'">1</a></li>';
                        if($start > 2) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                    }
                    for($i = $start; $i <= $end; $i++): 
                    ?>
                    <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                        <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search)?>">
                            <?= $i ?>
                        </a>
                    </li>
                    <?php endfor; ?>
                    <?php 
                    if($end < $total_pages){
                        if($end < $total_pages - 1) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                        echo '<li class="page-item"><a class="page-link" href="?page='.$total_pages.'&search='.urlencode($search).'">'.$total_pages.'</a></li>';
                    }
                    ?>
                    <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=<?= $page+1 ?>&search=<?= urlencode($search)?>" style="border-radius: 0 10px 10px 0;">
                            <i class="bi bi-chevron-right"></i>
                        </a>
                    </li>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
    </div>

    <!-- MODAL DETAIL RIWAYAT CABANG -->
    <?php 
    $sql_modal = "SELECT * FROM investor $where_sql ORDER BY id_investor DESC";
    $stmt_modal = $conn->prepare($sql_modal);
    if($search){
        $types_modal = "sss";
        $params_modal = ["%$search%", "%$search%", "%$search%"];
        $stmt_modal->bind_param($types_modal, ...$params_modal);
    }
    $stmt_modal->execute();
    $investor_modal = $stmt_modal->get_result();
    while($d=$investor_modal->fetch_assoc()):
    ?>
    <div class="modal fade modal-premium" id="modalDetail<?= $d['id_investor']?>" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title fw-bold">Riwayat Investasi: <?= h($d['nama_investor'])?></h5>
                        <small class="text-muted">Daftar cabang yang pernah/sedang diinvestasikan</small>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php 
                    $stmt = $conn->prepare("SELECT c.nama_cabang, c.alamat, ci.tgl_mulai, ci.tgl_selesai FROM cabang_investor ci JOIN cabang c ON ci.id_cabang=c.id_cabang WHERE ci.id_investor=? ORDER BY ci.tgl_mulai DESC");
                    $stmt->bind_param("i", $d['id_investor']);
                    $stmt->execute();
                    $cabang_inv = $stmt->get_result();
                    if($cabang_inv->num_rows > 0):?>
                    <div class="table-responsive">
                        <table class="table table-saas table-sm mb-0">
                            <thead><tr><th>Nama Cabang</th><th>Alamat</th><th>Periode Investasi</th></tr></thead>
                            <tbody>
                                <?php while($c=$cabang_inv->fetch_assoc()):?>
                                <tr>
                                    <td class="fw-semibold"><?= h($c['nama_cabang'])?></td>
                                    <td class="text-secondary"><?= h($c['alamat'])?></td>
                                    <td>
                                        <?= date('d M Y', strtotime($c['tgl_mulai']))?> 
                                        <i class="bi bi-arrow-right"></i> 
                                        <?= $c['tgl_selesai'] ? date('d M Y', strtotime($c['tgl_selesai'])) : '<span class="badge bg-success-subtle text-success">Aktif</span>' ?>
                                    </td>
                                </tr>
                                <?php endwhile;?>
                            </tbody>
                        </table>
                    </div>
                    <?php else:?>
                    <div class="text-center py-4 text-muted"><i class="bi bi-building-x fs-2 d-block mb-2"></i>Investor ini belum memiliki riwayat cabang</div>
                    <?php endif; $stmt->close();?>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-premium-outline" data-bs-dismiss="modal">Tutup</button></div>
            </div>
        </div>
    </div>
    <?php endwhile;?>
</div>

<!-- Modal Tambah/Edit Investor -->
<div class="modal fade modal-premium" id="modalInvestor" tabindex="-1">
<div class="modal-dialog modal-dialog-centered modal-lg">
<form method="POST" enctype="multipart/form-data">
<input type="hidden" name="csrf" value="<?=csrf_token()?>">
<div class="modal-content">
    <div class="modal-header">
        <h5 class="modal-title fw-bold" id="modalTitle"><?= $edit? 'Modifikasi Data Investor' : 'Registrasi Investor Baru'?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body">
        <input type="hidden" name="id_investor" id="id_investor" value="<?= $edit['id_investor']?? ''?>">
        <input type="hidden" name="file_lama" id="file_lama" value="<?= $edit['surat_perjanjian']?? ''?>">
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Nama Investor</label><input type="text" name="nama_investor" id="nama_investor" class="form-control form-control-premium" value="<?= h($edit['nama_investor']?? '')?>" required></div>
            <div class="col-md-6"><label class="form-label">Telephone</label><input type="text" name="no_hp" id="no_hp" class="form-control form-control-premium" value="<?= h($edit['no_hp']?? '')?>"></div>
        </div>
        <div class="mt-3"><label class="form-label">Alamat</label><textarea name="alamat" id="alamat" class="form-control form-control-premium" rows="2"><?= h($edit['alamat']?? '')?></textarea></div>
        <div class="row g-3 mt-1">
            <div class="col-md-6"><label class="form-label">Rekening</label><input type="text" name="no_rekening" id="no_rekening" class="form-control form-control-premium" value="<?= h($edit['no_rekening']?? '')?>"></div>
            <div class="col-md-6"><label class="form-label">Bank</label><input type="text" name="nama_bank" id="nama_bank" class="form-control form-control-premium" value="<?= h($edit['nama_bank']?? '')?>"></div>
        </div>
        <div class="row g-3 mt-1"> <!-- BARU -->
            <div class="col-md-12"><label class="form-label">Atas Nama Rekening</label><input type="text" name="atas_nama_rekening" id="atas_nama_rekening" class="form-control form-control-premium" value="<?= h($edit['atas_nama_rekening']?? '')?>" placeholder="PT. BUMI BAHARI SEJAHTERA"></div>
        </div>
        <div class="row g-3 mt-1"> <!-- BARU -->
            <div class="col-md-6"><label class="form-label">Tgl Mulai Investasi</label><input type="date" name="tgl_mulai_investasi" id="tgl_mulai_investasi" class="form-control form-control-premium" value="<?= h($edit['tgl_mulai_investasi']?? '')?>"></div>
            <div class="col-md-6"><label class="form-label">Tgl Selesai Investasi</label><input type="date" name="tgl_selesai_investasi" id="tgl_selesai_investasi" class="form-control form-control-premium" value="<?= h($edit['tgl_selesai_investasi']?? '')?>"></div>
        </div>
        <div class="mt-3">
            <label class="form-label">Surat Perjanjian</label>
            <input type="file" name="surat_perjanjian" class="form-control form-control-premium" accept=".pdf,.jpg,.jpeg,.png">
            <small class="text-muted" id="file_info"><?= $edit && $edit['surat_perjanjian'] ? 'File saat ini: '.h($edit['surat_perjanjian']) : ''?></small>
        </div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-premium-outline" data-bs-dismiss="modal">Batal</button>
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
    document.getElementById('atas_nama_rekening').value = ''; // BARU
    document.getElementById('tgl_mulai_investasi').value = ''; // BARU
    document.getElementById('tgl_selesai_investasi').value = ''; // BARU
    document.getElementById('modalTitle').innerText = 'Registrasi Investor Baru';
    document.getElementById('file_info').innerText = '';
}
function editInvestor(id, nama, hp, alamat, no_rek, bank, atas_nama, tgl_mulai, tgl_selesai, file){ // BARU
    document.getElementById('id_investor').value = id;
    document.getElementById('file_lama').value = file;
    document.getElementById('nama_investor').value = nama;
    document.getElementById('no_hp').value = hp;
    document.getElementById('alamat').value = alamat;
    document.getElementById('no_rekening').value = no_rek;
    document.getElementById('nama_bank').value = bank;
    document.getElementById('atas_nama_rekening').value = atas_nama; // BARU
    document.getElementById('tgl_mulai_investasi').value = tgl_mulai; // BARU
    document.getElementById('tgl_selesai_investasi').value = tgl_selesai; // BARU
    document.getElementById('modalTitle').innerText = 'Modifikasi Data Investor';
    document.getElementById('file_info').innerText = file? 'File saat ini: ' + file : '';
}
<?php if($edit):?>var modal = new bootstrap.Modal(document.getElementById('modalInvestor')); modal.show();<?php endif;?>
</script>