<?php
require '../config/koneksi.php';
include 'sidebar_pusat.php';

// 1. PROTEKSI ROLE PUSAT
if(!isset($_SESSION['role']) || $_SESSION['role']!= 'pusat'){
    header("Location:../login"); exit;
}

// Filter cabang
$search = $_GET['search'] ?? '';
$where_sql = "";
$params = [];
$types = "";
if($search){
    $where_sql = "WHERE nama_cabang LIKE ? ";
    $params[] = "%$search%";
    $types .= "s";
}

// Ambil data investor untuk dropdown
$list_investor = $conn->query("SELECT id_investor, nama_investor FROM investor WHERE status='aktif' ORDER BY nama_investor ASC");

// 1. Proses Tambah Cabang
if(isset($_POST['tambah'])){
    if(!csrf_check($_POST['csrf']?? '')){ die("<script>alert('Token tidak valid!'); history.back();</script>"); }
    
    $nama = $_POST['nama_cabang'];
    $alamat = $_POST['alamat'];
    $telp = $_POST['no_telp'];
    $pengelola = $_POST['nama_pengelola'];
    $id_investor = $_POST['id_investor']?: null;
    
    $cek = $conn->prepare("SELECT * FROM cabang WHERE nama_cabang=?");
    $cek->bind_param("s", $nama);
    $cek->execute();
    if($cek->get_result()->num_rows > 0){
        echo "<script>alert('Nama cabang sudah ada!');</script>";
    } else {
        $stmt = $conn->prepare("INSERT INTO cabang (nama_cabang, alamat, no_telp, nama_pengelola, id_investor) VALUES (?,?,?,?,?)");
        $stmt->bind_param("ssssi", $nama,$alamat,$telp,$pengelola,$id_investor);
        $stmt->execute();
        echo "<script>alert('Cabang berhasil ditambah'); window.location='data_cabang';</script>";
    }
}

// 2. Proses Edit Cabang
if(isset($_POST['edit'])){
    if(!csrf_check($_POST['csrf']?? '')){ die("<script>alert('Token tidak valid!'); history.back();</script>"); }
    
    $id = $_POST['id_cabang'];
    $nama = $_POST['nama_cabang'];
    $alamat = $_POST['alamat'];
    $telp = $_POST['no_telp'];
    $pengelola = $_POST['nama_pengelola'];
    $id_investor = $_POST['id_investor']?: null;
    
    $stmt = $conn->prepare("UPDATE cabang SET nama_cabang=?, alamat=?, no_telp=?, nama_pengelola=?, id_investor=? WHERE id_cabang=?");
    $stmt->bind_param("ssssii", $nama,$alamat,$telp,$pengelola,$id_investor,$id);
    $stmt->execute();
    echo "<script>alert('Data berhasil diupdate'); window.location='data_cabang';</script>";
}

// 3. Proses Hapus Cabang - UBAH JADI POST
if(isset($_POST['hapus'])){
    if(!csrf_check($_POST['csrf']?? '')){ die("<script>alert('Token tidak valid!'); history.back();</script>"); }
    
    $id = $_POST['id_cabang'];
    
    $cek = $conn->prepare("SELECT * FROM laporan_cabang WHERE id_cabang=?");
    $cek->bind_param("i", $id);
    $cek->execute();
    if($cek->get_result()->num_rows > 0){
        echo "<script>alert('Tidak bisa hapus! Cabang ini sudah ada data laporan');</script>";
    } else {
        $del = $conn->prepare("DELETE FROM cabang WHERE id_cabang=?");
        $del->bind_param("i", $id);
        $del->execute();
        echo "<script>alert('Cabang berhasil dihapus'); window.location='data_cabang';</script>";
    }
}

// 4. PAGINATION - TAMBAHAN
$limit = 10; // tampil 10 data per halaman
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$page = $page < 1 ? 1 : $page;
$offset = ($page - 1) * $limit;

// Hitung total data
$sql_count = "SELECT COUNT(*) as total FROM cabang c $where_sql";
$stmt_count = $conn->prepare($sql_count);
if($search) $stmt_count->bind_param($types, ...$params);
$stmt_count->execute();
$total_data = $stmt_count->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_data / $limit);

// 5. SELECT DATA PAKAI PREPARED + LIMIT
$sql = "SELECT c.*, i.nama_investor FROM cabang c LEFT JOIN investor i ON c.id_investor=i.id_investor $where_sql ORDER BY c.id_cabang DESC LIMIT ? OFFSET ?";
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
$no = $offset + 1; // nomor urut ikut halaman
?>

<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
    body {background-color: #f4f7fe!important; font-family: 'Plus Jakarta Sans', sans-serif!important; color: #1b2559;}
    .saas-card {background: #ffffff; border: none!important; border-radius: 20px!important; box-shadow: 0px 18px 40px rgba(112, 144, 176, 0.06)!important; padding: 24px;}
    .title-mark {width: 12px; height: 12px; background-color: #4318ff; border-radius: 4px; display: inline-block; margin-right: 10px;}
    .btn-premium {background-color: #4318ff!important; color: #ffffff!important; border: none!important; padding: 10px 20px; border-radius: 12px; font-weight: 600; font-size: 14px; transition: all 0.2s ease;}
    .btn-premium:hover {background-color: #3310cc!important; transform: translateY(-1px); box-shadow: 0px 8px 20px rgba(67, 24, 255, 0.15);}
    .btn-premium-outline {background-color: #f4f7fe!important; color: #4318ff!important; border: 1px solid #e0e7ff!important; padding: 10px 20px; border-radius: 12px; font-weight: 600; font-size: 14px;}
    .form-control-premium {border-radius: 12px!important; border: 1px solid #e0e7ff!important; padding: 10px 16px; color: #1b2559; font-size: 14px;}
    .form-control-premium:focus {border-color: #4318ff!important; box-shadow: 0 0 0 4px rgba(67, 24, 255, 0.1)!important;}
    .btn-action-edit {background-color: #fff3cd; color: #856404; border: none; padding: 8px 14px; border-radius: 10px; font-size: 14px; font-weight: 600; display: inline-flex; align-items: center; justify-content: center;}
    .btn-action-edit:hover {background-color: #ffe8a1;}
    .btn-action-delete {background-color: #fde8e8; color: #ef4444; border: none; padding: 8px 14px; border-radius: 10px; font-size: 14px; font-weight: 600; display: inline-flex; align-items: center; justify-content: center;}
    .btn-action-delete:hover {background-color: #fbd5d5;}
    
    /* Pagination Style */
    .pagination .page-link {border-radius: 10px!important; margin: 0 3px; border: 1px solid #e0e7ff; color: #4318ff; font-weight: 600;}
    .pagination .page-item.active .page-link {background-color: #4318ff; border-color: #4318ff; color: #fff;}
    .pagination .page-link:hover {background-color: #e0e7ff;}

    /* Responsive Table Styles */
    .table-saas {margin-bottom: 0; width: 100%!important;}
    .table-saas thead th {background-color: #f8f9fc!important; color: #8f9bba!important; font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #eef2f9!important; padding: 16px 12px; border-top: none!important;}
    .table-saas tbody td {padding: 16px 12px; border-bottom: 1px solid #f4f7fe!important; color: #1b2559; font-size: 14px; vertical-align: middle;}
    .table-saas tbody tr:hover {background-color: rgba(244, 247, 254, 0.5);}
    
    .modal-premium .modal-content {border-radius: 24px!important; border: none!important; box-shadow: 0px 24px 48px rgba(112, 144, 176, 0.15)!important;}
    .modal-premium .modal-header {border-bottom: 1px solid #f4f7fe!important; padding: 20px 24px!important;}
    .modal-premium .modal-body {padding: 24px!important;}
    .modal-premium .modal-footer {border-top: 1px solid #f4f7fe!important; padding: 16px 24px!important;}

    /* Perubahan Khusus Layar HP / Mobile */
    @media (max-width: 767.98px) {
        .saas-card { padding: 16px!important; }
        .search-box-container { width: 100%!important; }
        .search-box-container input { width: 100%!important; }
        .btn-premium, .btn-premium-outline { width: 100%; justify-content: center; }
        
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
            white-space: normal!important;
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
        .table-saas tbody td .btn-action-edit, .table-saas tbody td .btn-action-delete { flex: 1; }
    }
</style>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <div class="d-flex align-items-center">
                <span class="title-mark"></span>
                <h3 class="fw-bold mb-0" style="color: #1b2559; font-size: calc(1.3rem + 0.6vw);">Data Cabang</h3>
            </div>
            <span class="text-muted small ms-4 d-block d-sm-inline mt-1 mt-sm-0">Manajemen data operasional seluruh cabang resmi</span>
        </div>
    </div>

    <div class="d-flex flex-column flex-md-row justify-content-between gap-3 mb-4">
        <div>
            <button class="btn btn-premium d-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#modalTambah">
                <i class="bi bi-plus-circle-fill"></i> Tambah Cabang Baru
            </button>
        </div>
        
        <form method="GET" class="d-flex flex-row gap-2 search-box-container">
            <input type="text" name="search" class="form-control form-control-premium" placeholder="Cari nama cabang..." value="<?= h($search)?>" style="width: 280px;">
            <button type="submit" class="btn btn-premium-outline d-flex align-items-center gap-2">
                <i class="bi bi-funnel-fill"></i> <span class="d-none d-sm-inline">Filter</span>
            </button>
            <?php if($search):?>
            <a href="data_cabang" class="btn btn-premium-outline bg-white text-secondary d-flex align-items-center justify-content-center">
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
                        <th width="18%">Nama Cabang</th>
                        <th width="25%">Alamat Lengkap</th>
                        <th width="13%">Telephone</th>
                        <th width="15%">Pengelola</th>
                        <th width="14%">Investor</th>
                        <th width="10%" class="text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if($data->num_rows == 0): ?>
                    <tr><td colspan="7" class="text-center py-5 text-muted">Data tidak ditemukan</td></tr>
                    <?php endif; ?>
                    <?php while($row = $data->fetch_assoc()):?>
                    <tr>
                        <td data-label="No" class="text-center text-muted fw-semibold"><?= $no++?></td>
                        <td data-label="Nama Cabang"><span class="fw-bold" style="color: #1b2559;"><?= h($row['nama_cabang'])?></span></td>
                        <td data-label="Alamat Lengkap" class="text-secondary" title="<?= h($row['alamat'])?>"><?= h($row['alamat'])?></td>
                        <td data-label="No Telp" class="text-secondary"><?= h($row['no_telp'])?></td>
                        <td data-label="Pengelola"><span class="fw-semibold" style="color: #1b2559;"><?= h($row['nama_pengelola'])?></span></td>
                        
                        <td data-label="Investor">
                            <?= !empty($row['nama_investor']) ? h($row['nama_investor']) : '<span class="text-muted small">Belum ada</span>' ?>
                        </td>

                        <td data-label="Aksi" class="text-center">
                            <div class="d-inline-flex gap-2 w-100 justify-content-end justify-content-md-center">
                                <button class="btn btn-action-edit" data-bs-toggle="modal" data-bs-target="#modalEdit<?= $row['id_cabang']?>" title="Edit">
                                    <i class="bi bi-pencil-square me-1 d-md-none"></i> Edit
                                </button>
                                
                                <form method="POST" class="d-inline" onsubmit="return confirm('Yakin hapus cabang <?= h($row['nama_cabang'])?>?')">
                                    <input type="hidden" name="csrf" value="<?=csrf_token()?>">
                                    <input type="hidden" name="id_cabang" value="<?= $row['id_cabang']?>">
                                    <button type="submit" name="hapus" class="btn btn-action-delete" title="Hapus">
                                        <i class="bi bi-trash-fill me-1 d-md-none"></i> Hapus
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>

                    <div class="modal fade modal-premium" id="modalEdit<?= $row['id_cabang']?>" tabindex="-1">
                        <div class="modal-dialog modal-dialog-centered modal-lg">
                            <form method="POST">
                                <input type="hidden" name="csrf" value="<?=csrf_token()?>">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <div>
                                            <h5 class="modal-title fw-bold" style="color: #1b2559;">Modifikasi Data Cabang</h5>
                                            <small class="text-muted">Perbarui data entitas cabang pilihan Anda</small>
                                        </div>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <input type="hidden" name="id_cabang" value="<?= $row['id_cabang']?>">
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <label class="form-label small fw-bold text-muted mb-1">Nama Cabang</label>
                                                <input type="text" name="nama_cabang" value="<?= h($row['nama_cabang'])?>" class="form-control form-control-premium" required>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small fw-bold text-muted mb-1">Telephone</label>
                                                <input type="text" name="no_telp" value="<?= h($row['no_telp'])?>" class="form-control form-control-premium" required>
                                            </div>
                                            <div class="col-md-12">
                                                <label class="form-label small fw-bold text-muted mb-1">Alamat Lengkap</label>
                                                <textarea name="alamat" class="form-control form-control-premium" rows="2" required><?= h($row['alamat'])?></textarea>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small fw-bold text-muted mb-1">Nama Pengelola</label>
                                                <input type="text" name="nama_pengelola" value="<?= h($row['nama_pengelola'])?>" class="form-control form-control-premium" required>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small fw-bold text-muted mb-1">Investor</label>
                                                <select name="id_investor" class="form-control form-control-premium">
                                                    <option value="">-- Pilih Investor --</option>
                                                    <?php $list_investor->data_seek(0); while($inv=$list_investor->fetch_assoc()):?>
                                                    <option value="<?= $inv['id_investor']?>" <?= $row['id_investor']==$inv['id_investor']?'selected':''?>>
                                                        <?= h($inv['nama_investor'])?>
                                                    </option>
                                                    <?php endwhile;?>
                                                </select>
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
                        <h5 class="modal-title fw-bold" style="color: #1b2559;">Registrasi Cabang Baru</h5>
                        <small class="text-muted">Tambahkan data operasional unit cabang komersial baru</small>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted mb-1">Nama Cabang</label>
                            <input type="text" name="nama_cabang" class="form-control form-control-premium" placeholder="Cabang Brebes Timur" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted mb-1">No Telp</label>
                            <input type="text" name="no_telp" class="form-control form-control-premium" placeholder="08xxxxxxxx" required>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label small fw-bold text-muted mb-1">Alamat Lengkap</label>
                            <textarea name="alamat" class="form-control form-control-premium" rows="2" placeholder="Tulis alamat jalan dan kota lokasi cabang..." required></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted mb-1">Nama Pengelola</label>
                            <input type="text" name="nama_pengelola" class="form-control form-control-premium" placeholder="Nama kepala cabang" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted mb-1">Investor</label>
                            <select name="id_investor" class="form-control form-control-premium">
                                <option value="">-- Pilih Investor --</option>
                                <?php $list_investor->data_seek(0); while($inv=$list_investor->fetch_assoc()):?>
                                <option value="<?= $inv['id_investor']?>"><?= h($inv['nama_investor'])?></option>
                                <?php endwhile;?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-premium-outline text-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" name="tambah" class="btn btn-premium">Simpan Unit Cabang</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>