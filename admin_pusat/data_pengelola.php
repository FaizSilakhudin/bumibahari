<?php
require '../config/koneksi.php';
include 'sidebar_pusat.php';

// Filter
$search = $_GET['search'] ?? '';
$where = "WHERE u.role='cabang'";
if($search != ''){
    $search_safe = mysqli_real_escape_string($conn, $search);
    $where .= " AND (u.nama_pengelola LIKE '%$search_safe%' OR c.nama_cabang LIKE '%$search_safe%')";
}

// Tambah pengelola
if(isset($_POST['tambah'])){
    $username = mysqli_real_escape_string($conn, $_POST['username']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $nama = mysqli_real_escape_string($conn, $_POST['nama_pengelola']);
    $id_cabang = $_POST['id_cabang'];
    $no_rek = mysqli_real_escape_string($conn, $_POST['no_rekening']);
    $bank = mysqli_real_escape_string($conn, $_POST['nama_bank']);

    $cek = mysqli_query($conn, "SELECT * FROM users WHERE id_cabang=$id_cabang AND role='cabang'");
    if(mysqli_num_rows($cek) > 0){
        echo "<script>alert('Cabang ini sudah punya akun admin!');</script>";
    } else {
        mysqli_query($conn, "INSERT INTO users (username,password,nama_pengelola,no_rekening,nama_bank,role,id_cabang) 
        VALUES ('$username','$password','$nama','$no_rek','$bank','cabang',$id_cabang)");
        echo "<script>alert('Pengelola berhasil ditambah'); window.location='data_pengelola.php';</script>";
    }
}

// Edit pengelola
if(isset($_POST['edit'])){
    $id = $_POST['id'];
    $username = mysqli_real_escape_string($conn, $_POST['username']);
    $nama = mysqli_real_escape_string($conn, $_POST['nama_pengelola']);
    $id_cabang = $_POST['id_cabang'];
    $no_rek = mysqli_real_escape_string($conn, $_POST['no_rekening']);
    $bank = mysqli_real_escape_string($conn, $_POST['nama_bank']);

    $sql = "UPDATE users SET username='$username', nama_pengelola='$nama', no_rekening='$no_rek', nama_bank='$bank', id_cabang=$id_cabang";
    if(!empty($_POST['password'])){
        $pass = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $sql.= ", password='$pass'";
    }
    $sql.= " WHERE id=$id";
    mysqli_query($conn, $sql);
    echo "<script>alert('Data berhasil diupdate'); window.location='data_pengelola.php';</script>";
}

// Hapus
if(isset($_GET['hapus'])){
    mysqli_query($conn, "DELETE FROM users WHERE id=$_GET[hapus]");
    echo "<script>alert('Pengelola berhasil dihapus'); window.location='data_pengelola.php';</script>";
}

$data = mysqli_query($conn, "SELECT u.*, c.nama_cabang FROM users u LEFT JOIN cabang c ON u.id_cabang=c.id_cabang $where ORDER BY u.id DESC");
$cabang = mysqli_query($conn, "SELECT * FROM cabang");
$no = 1;
?>

<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
    body {
        background-color: #f4f7fe !important;
        font-family: 'Plus Jakarta Sans', sans-serif !important;
        color: #1b2559;
    }
    
    .saas-card {
        background: #ffffff;
        border: none !important;
        border-radius: 20px !important;
        box-shadow: 0px 18px 40px rgba(112, 144, 176, 0.06) !important;
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
        background-color: #4318ff !important;
        color: #ffffff !important;
        border: none !important;
        padding: 10px 20px;
        border-radius: 12px;
        font-weight: 600;
        font-size: 14px;
        transition: all 0.2s ease;
    }
    .btn-premium:hover {
        background-color: #3310cc !important;
        transform: translateY(-1px);
        box-shadow: 0px 8px 20px rgba(67, 24, 255, 0.15);
    }
    .btn-premium-outline {
        background-color: #f4f7fe !important;
        color: #4318ff !important;
        border: 1px solid #e0e7ff !important;
        padding: 10px 20px;
        border-radius: 12px;
        font-weight: 600;
        font-size: 14px;
    }
    .btn-premium-outline:hover {
        background-color: #e0e7ff !important;
    }

    .form-control-premium, .form-select-premium {
        border-radius: 12px !important;
        border: 1px solid #e0e7ff !important;
        padding: 10px 16px;
        color: #1b2559;
        font-size: 14px;
        background-color: #ffffff;
    }
    .form-control-premium:focus, .form-select-premium:focus {
        border-color: #4318ff !important;
        box-shadow: 0 0 0 4px rgba(67, 24, 255, 0.1) !important;
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

    /* LAYOUT DASAR TABEL */
    .table-saas {
        margin-bottom: 0;
        width: 100% !important;
    }
    .table-saas thead th {
        background-color: #f8f9fc !important;
        color: #8f9bba !important;
        font-weight: 600;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        border-bottom: 1px solid #eef2f9 !important;
        padding: 16px 12px;
        border-top: none !important;
    }
    .table-saas tbody td {
        padding: 16px 12px;
        border-bottom: 1px solid #f4f7fe !important;
        color: #1b2559;
        font-size: 14px;
        vertical-align: middle;
    }
    .table-saas tbody tr:hover {
        background-color: rgba(244, 247, 254, 0.5);
    }

    /* Modal Architecture */
    .modal-premium .modal-content {
        border-radius: 24px !important;
        border: none !important;
        box-shadow: 0px 24px 48px rgba(112, 144, 176, 0.15) !important;
    }
    .modal-premium .modal-header { border-bottom: 1px solid #f4f7fe !important; padding: 20px 24px !important; }
    .modal-premium .modal-body { padding: 24px !important; }
    .modal-premium .modal-footer { border-top: 1px solid #f4f7fe !important; padding: 16px 24px !important; }

    /* OPTIMASI LAYOUT LAYAR RESPONSIVE MOBILE */
    @media (max-width: 767.98px) {
        .saas-card { padding: 16px !important; }
        .header-container { flex-direction: column; align-items: flex-start !important; gap: 12px; }
        .action-container { flex-direction: column; align-items: stretch !important; gap: 16px; }
        .action-container form { flex-direction: column; width: 100%; }
        .action-container input { width: 100% !important; }
        .action-container button, .action-container a { flex: 1; justify-content: center; }
        
        /* Transformasi tabel menjadi deretan kartu data di HP */
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
            padding: 10px 0 !important; 
            border-bottom: 1px dashed #f4f7fe !important;
            text-align: right;
        }
        .table-saas tbody td:last-child { border-bottom: none !important; padding-top: 14px !important; }
        
        /* Membuat Data Label Kiri Otomatis */
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
        .table-saas tbody td .btn-action-edit, 
        .table-saas tbody td .btn-action-delete { flex: 1; }
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

    <div class="d-flex justify-content-between align-items-center action-container gap-3 mb-4">
        <div>
            <button class="btn btn-premium d-flex align-items-center gap-2 w-100 justify-content-center" data-bs-toggle="modal" data-bs-target="#modalTambah">
                <i class="bi bi-plus-circle-fill"></i> Tambah Pengelola
            </button>
        </div>
        
        <form method="GET" class="d-flex gap-2">
            <input type="text" name="search" class="form-control form-control-premium" placeholder="Cari nama pengelola/cabang..." value="<?= htmlspecialchars($search) ?>" style="width: 300px;">
            <div class="d-flex gap-2 w-100">
                <button type="submit" class="btn btn-premium-outline d-flex align-items-center gap-2 justify-content-center">
                    <i class="bi bi-search"></i> Cari
                </button>
                <?php if($search): ?>
                <a href="data_pengelola.php" class="btn btn-premium-outline bg-white text-secondary d-flex align-items-center justify-content-center">
                    <i class="bi bi-arrow-clockwise"></i>
                </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="card saas-card p-0 overflow-hidden border-0">
        <div class="table-responsive-md">
            <table class="table table-saas align-middle mb-0">
                <thead>
                    <tr>
                        <th width="5%" class="text-center">No</th>
                        <th width="20%">Nama Cabang</th>
                        <th width="20%">Nama Pengelola</th>
                        <th width="15%">Username</th>
                        <th width="17%">Rekening</th>
                        <th width="10%">Bank</th>
                        <th width="13%" class="text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(mysqli_num_rows($data) == 0): ?>
                    <tr><td colspan="7" class="text-center text-muted py-5 fw-semibold"><i class="bi bi-inbox fs-2 d-block mb-2"></i> Data tidak ditemukan</td></tr>
                    <?php endif; ?>
                    
                    <?php while($row = mysqli_fetch_assoc($data)):?>
                    <tr>
                        <td data-label="No" class="text-center text-muted fw-semibold"><?= $no++?></td>
                        <td data-label="Nama Cabang"><span class="fw-bold" style="color: #1b2559;"><?= $row['nama_cabang']?? '-'?></span></td>
                        <td data-label="Nama Pengelola"><span class="fw-semibold" style="color: #1b2559;"><?= $row['nama_pengelola']?></span></td>
                        <td data-label="Username" class="text-secondary"><?= $row['username']?></td>
                        <td data-label="No Rekening" class="font-monospace text-secondary" style="font-size: 13px;"><?= $row['no_rekening'] ?: '<span class="text-muted">-</span>' ?></td>
                        <td data-label="Bank">
                            <?php if($row['nama_bank']): ?>
                                <span class="badge bg-light text-dark px-2 py-1 border text-uppercase" style="font-size: 10px; letter-spacing: 0.5px;"><?= $row['nama_bank'] ?></span>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Aksi" class="text-center">
                            <div class="d-inline-flex gap-2 w-100 justify-content-end justify-content-md-center">
                                <button class="btn btn-action-edit" data-bs-toggle="modal" data-bs-target="#modalEdit<?= $row['id']?>" title="Edit">
                                    <i class="bi bi-pencil-square me-1 d-md-none"></i> <span class="d-none d-md-inline"><i class="bi bi-pencil-square"></i></span> Edit
                                </button>
                                <a href="?hapus=<?= $row['id']?>" class="btn btn-action-delete" onclick="return confirm('Hapus pengelola <?= $row['nama_pengelola'] ?>?')" title="Hapus">
                                    <i class="bi bi-trash-fill me-1 d-md-none"></i> <span class="d-none d-md-inline"><i class="bi bi-trash-fill"></i></span> Hapus
                                </a>
                            </div>
                        </td>
                    </tr>

                    <div class="modal fade modal-premium" id="modalEdit<?= $row['id']?>" tabindex="-1">
                        <div class="modal-dialog modal-dialog-centered modal-lg">
                            <form method="POST">
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
                                                    <?php mysqli_data_seek($cabang,0); while($c=mysqli_fetch_assoc($cabang)):?>
                                                    <option value="<?= $c['id_cabang']?>" <?= $c['id_cabang']==$row['id_cabang']?'selected':''?>><?= $c['nama_cabang']?></option>
                                                    <?php endwhile;?>
                                                </select>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small fw-bold text-muted mb-1">Nama Pengelola</label>
                                                <input type="text" name="nama_pengelola" value="<?= $row['nama_pengelola']?>" class="form-control form-control-premium" required>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small fw-bold text-muted mb-1">Username</label>
                                                <input type="text" name="username" value="<?= $row['username']?>" class="form-control form-control-premium" required>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small fw-bold text-muted mb-1">Password Baru</label>
                                                <input type="password" name="password" class="form-control form-control-premium" placeholder="Kosongkan jika tidak ganti">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small fw-bold text-muted mb-1">Rekening</label>
                                                <input type="text" name="no_rekening" value="<?= $row['no_rekening']?>" class="form-control form-control-premium" placeholder="327031647">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small fw-bold text-muted mb-1">Bank</label>
                                                <input type="text" name="nama_bank" value="<?= $row['nama_bank']?>" class="form-control form-control-premium" placeholder="BCA">
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
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade modal-premium" id="modalTambah" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form method="POST">
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
                                <?php mysqli_data_seek($cabang,0); while($c=mysqli_fetch_assoc($cabang)):?>
                                <option value="<?= $c['id_cabang']?>"><?= $c['nama_cabang']?></option>
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
                            <label class="form-label small fw-bold text-muted mb-1">Rekening</label>
                            <input type="text" name="no_rekening" class="form-control form-control-premium" placeholder="327031647">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted mb-1">Bank</label>
                            <input type="text" name="nama_bank" class="form-control form-control-premium" placeholder="BCA">
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>