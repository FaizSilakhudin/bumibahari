<?php
require '../config/koneksi.php';
include 'sidebar_pusat.php';

// Filter cabang
$search = $_GET['search'] ?? '';
$where = $search ? "WHERE nama_cabang LIKE '%".mysqli_real_escape_string($conn, $search)."%' " : "";

// Ambil data investor untuk dropdown
$list_investor = mysqli_query($conn, "SELECT id_investor, nama_investor FROM investor WHERE status='aktif' ORDER BY nama_investor ASC");

// 1. Proses Tambah Cabang
if(isset($_POST['tambah'])){
    $nama = mysqli_real_escape_string($conn, $_POST['nama_cabang']);
    $alamat = mysqli_real_escape_string($conn, $_POST['alamat']);
    $telp = mysqli_real_escape_string($conn, $_POST['no_telp']);
    $pengelola = mysqli_real_escape_string($conn, $_POST['nama_pengelola']);
    $id_investor = mysqli_real_escape_string($conn, $_POST['id_investor']);
    
    $cek = mysqli_query($conn, "SELECT * FROM cabang WHERE nama_cabang='$nama'");
    if(mysqli_num_rows($cek) > 0){
        echo "<script>alert('Nama cabang sudah ada!');</script>";
    } else {
        mysqli_query($conn, "INSERT INTO cabang (nama_cabang, alamat, no_telp, nama_pengelola, id_investor) 
        VALUES ('$nama','$alamat','$telp','$pengelola','$id_investor')");
        echo "<script>alert('Cabang berhasil ditambah'); window.location='data_cabang.php';</script>";
    }
}

// 2. Proses Edit Cabang
if(isset($_POST['edit'])){
    $id = $_POST['id_cabang'];
    $nama = mysqli_real_escape_string($conn, $_POST['nama_cabang']);
    $alamat = mysqli_real_escape_string($conn, $_POST['alamat']);
    $telp = mysqli_real_escape_string($conn, $_POST['no_telp']);
    $pengelola = mysqli_real_escape_string($conn, $_POST['nama_pengelola']);
    $id_investor = mysqli_real_escape_string($conn, $_POST['id_investor']);
    
    mysqli_query($conn, "UPDATE cabang SET 
        nama_cabang='$nama', 
        alamat='$alamat', 
        no_telp='$telp', 
        nama_pengelola='$pengelola',
        id_investor='$id_investor'
        WHERE id_cabang=$id");
    echo "<script>alert('Data berhasil diupdate'); window.location='data_cabang.php';</script>";
}

// 3. Proses Hapus Cabang
if(isset($_GET['hapus'])){
    $id = $_GET['hapus'];
    
    $cek = mysqli_query($conn, "SELECT * FROM laporan_cabang WHERE id_cabang=$id");
    if(mysqli_num_rows($cek) > 0){
        echo "<script>alert('Tidak bisa hapus! Cabang ini sudah ada data laporan');</script>";
    } else {
        mysqli_query($conn, "DELETE FROM cabang WHERE id_cabang=$id");
        echo "<script>alert('Cabang berhasil dihapus'); window.location='data_cabang.php';</script>";
    }
}

$data = mysqli_query($conn, "SELECT c.*, i.nama_investor FROM cabang c LEFT JOIN investor i ON c.id_investor=i.id_investor $where ORDER BY c.id_cabang DESC");
$no = 1;
?>

<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
    body {background-color: #f4f7fe !important; font-family: 'Plus Jakarta Sans', sans-serif !important; color: #1b2559;}
    .saas-card {background: #ffffff; border: none !important; border-radius: 20px !important; box-shadow: 0px 18px 40px rgba(112, 144, 176, 0.06) !important; padding: 24px;}
    .title-mark {width: 12px; height: 12px; background-color: #4318ff; border-radius: 4px; display: inline-block; margin-right: 10px;}
    .btn-premium {background-color: #4318ff !important; color: #ffffff !important; border: none !important; padding: 10px 20px; border-radius: 12px; font-weight: 600; font-size: 14px; transition: all 0.2s ease;}
    .btn-premium:hover {background-color: #3310cc !important; transform: translateY(-1px); box-shadow: 0px 8px 20px rgba(67, 24, 255, 0.15);}
    .btn-premium-outline {background-color: #f4f7fe !important; color: #4318ff !important; border: 1px solid #e0e7ff !important; padding: 10px 20px; border-radius: 12px; font-weight: 600; font-size: 14px;}
    .form-control-premium {border-radius: 12px !important; border: 1px solid #e0e7ff !important; padding: 10px 16px; color: #1b2559; font-size: 14px;}
    .form-control-premium:focus {border-color: #4318ff !important; box-shadow: 0 0 0 4px rgba(67, 24, 255, 0.1) !important;}
    .btn-action-edit {background-color: #fff3cd; color: #856404; border: none; padding: 8px 14px; border-radius: 10px; font-size: 14px; font-weight: 600; display: inline-flex; align-items: center; justify-content: center;}
    .btn-action-edit:hover {background-color: #ffe8a1;}
    .btn-action-delete {background-color: #fde8e8; color: #ef4444; border: none; padding: 8px 14px; border-radius: 10px; font-size: 14px; font-weight: 600; display: inline-flex; align-items: center; justify-content: center;}
    .btn-action-delete:hover {background-color: #fbd5d5;}
    
    /* Responsive Table Styles */
    .table-saas {margin-bottom: 0; width: 100% !important;}
    .table-saas thead th {background-color: #f8f9fc !important; color: #8f9bba !important; font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #eef2f9 !important; padding: 16px 12px; border-top: none !important;}
    .table-saas tbody td {padding: 16px 12px; border-bottom: 1px solid #f4f7fe !important; color: #1b2559; font-size: 14px; vertical-align: middle;}
    .table-saas tbody tr:hover {background-color: rgba(244, 247, 254, 0.5);}
    
    .modal-premium .modal-content {border-radius: 24px !important; border: none !important; box-shadow: 0px 24px 48px rgba(112, 144, 176, 0.15) !important;}
    .modal-premium .modal-header {border-bottom: 1px solid #f4f7fe !important; padding: 20px 24px !important;}
    .modal-premium .modal-body {padding: 24px !important;}
    .modal-premium .modal-footer {border-top: 1px solid #f4f7fe !important; padding: 16px 24px !important;}

    /* Perubahan Khusus Layar HP / Mobile */
    @media (max-width: 767.98px) {
        .saas-card { padding: 16px !important; }
        .search-box-container { width: 100% !important; }
        .search-box-container input { width: 100% !important; }
        .btn-premium, .btn-premium-outline { width: 100%; justify-content: center; }
        
        /* Mengubah struktur tabel menjadi kartu list yang rapi di HP */
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
            padding: 8px 0 !important; 
            border-bottom: 1px dashed #f4f7fe !important;
            text-align: right;
            white-space: normal !important;
        }
        .table-saas tbody td:last-child { border-bottom: none !important; padding-top: 12px !important; }
        
        /* Membuat label data otomatis di sebelah kiri pada tampilan HP */
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
            <input type="text" name="search" class="form-control form-control-premium" placeholder="Cari nama cabang..." value="<?= htmlspecialchars($search) ?>" style="width: 280px;">
            <button type="submit" class="btn btn-premium-outline d-flex align-items-center gap-2">
                <i class="bi bi-funnel-fill"></i> <span class="d-none d-sm-inline">Filter</span>
            </button>
            <?php if($search): ?>
            <a href="data_cabang.php" class="btn btn-premium-outline bg-white text-secondary d-flex align-items-center justify-content-center">
                <i class="bi bi-arrow-clockwise"></i>
            </a>
            <?php endif; ?>
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
                    <?php while($row = mysqli_fetch_assoc($data)): ?>
                    <tr>
                        <td data-label="No" class="text-center text-muted fw-semibold"><?= $no++ ?></td>
                        <td data-label="Nama Cabang"><span class="fw-bold" style="color: #1b2559;"><?= $row['nama_cabang'] ?></span></td>
                        <td data-label="Alamat Lengkap" class="text-secondary" title="<?= htmlspecialchars($row['alamat']) ?>"><?= $row['alamat'] ?></td>
                        <td data-label="No Telp" class="text-secondary"><?= $row['no_telp'] ?></td>
                        <td data-label="Pengelola"><span class="fw-semibold" style="color: #1b2559;"><?= $row['nama_pengelola'] ?></span></td>
                        <td data-label="Investor"><?= $row['nama_investor'] ?: '<span class="text-muted small">Belum ada</span>' ?></td>
                        <td data-label="Aksi" class="text-center">
                            <div class="d-inline-flex gap-2 w-100 justify-content-end justify-content-md-center">
                                <button class="btn btn-action-edit" data-bs-toggle="modal" data-bs-target="#modalEdit<?= $row['id_cabang'] ?>" title="Edit">
                                    <i class="bi bi-pencil-square me-1 d-md-none"></i> Edit
                                </button>
                                <a href="?hapus=<?= $row['id_cabang'] ?>" class="btn btn-action-delete" onclick="return confirm('Yakin hapus cabang <?= $row['nama_cabang'] ?>?')" title="Hapus">
                                    <i class="bi bi-trash-fill me-1 d-md-none"></i> Hapus
                                </a>
                            </div>
                        </td>
                    </tr>

                    <div class="modal fade modal-premium" id="modalEdit<?= $row['id_cabang'] ?>" tabindex="-1">
                        <div class="modal-dialog modal-dialog-centered modal-lg">
                            <form method="POST">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <div>
                                            <h5 class="modal-title fw-bold" style="color: #1b2559;">Modifikasi Data Cabang</h5>
                                            <small class="text-muted">Perbarui data entitas cabang pilihan Anda</small>
                                        </div>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <input type="hidden" name="id_cabang" value="<?= $row['id_cabang'] ?>">
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <label class="form-label small fw-bold text-muted mb-1">Nama Cabang</label>
                                                <input type="text" name="nama_cabang" value="<?= $row['nama_cabang'] ?>" class="form-control form-control-premium" required>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small fw-bold text-muted mb-1">Telephone</label>
                                                <input type="text" name="no_telp" value="<?= $row['no_telp'] ?>" class="form-control form-control-premium" required>
                                            </div>
                                            <div class="col-md-12">
                                                <label class="form-label small fw-bold text-muted mb-1">Alamat Lengkap</label>
                                                <textarea name="alamat" class="form-control form-control-premium" rows="2" required><?= $row['alamat'] ?></textarea>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small fw-bold text-muted mb-1">Nama Pengelola</label>
                                                <input type="text" name="nama_pengelola" value="<?= $row['nama_pengelola'] ?>" class="form-control form-control-premium" required>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small fw-bold text-muted mb-1">Investor</label>
                                                <select name="id_investor" class="form-control form-control-premium">
                                                    <option value="">-- Pilih Investor --</option>
                                                    <?php mysqli_data_seek($list_investor, 0); while($inv=mysqli_fetch_assoc($list_investor)): ?>
                                                    <option value="<?= $inv['id_investor'] ?>" <?= $row['id_investor']==$inv['id_investor']?'selected':'' ?>>
                                                        <?= $inv['nama_investor'] ?>
                                                    </option>
                                                    <?php endwhile; ?>
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
                                <?php mysqli_data_seek($list_investor, 0); while($inv=mysqli_fetch_assoc($list_investor)): ?>
                                <option value="<?= $inv['id_investor'] ?>"><?= $inv['nama_investor'] ?></option>
                                <?php endwhile; ?>
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