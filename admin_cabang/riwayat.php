<?php
session_start();
require '../config/koneksi.php';
include 'sidebar.php';

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'cabang'){
    header('Location: ../login.php');
    exit;
}

$id_cabang = $_SESSION['id_cabang'];
$nama_pengelola = $_SESSION['nama_pengelola'];

// Hapus data
if(isset($_GET['hapus'])){
    $id = $_GET['hapus'];
    $cek = mysqli_query($conn, "SELECT * FROM laporan_cabang WHERE id_laporan=$id AND id_cabang=$id_cabang");
    if(mysqli_num_rows($cek) > 0){
        $data = mysqli_fetch_assoc($cek);
        // hapus file foto
        for($i=1; $i<=4; $i++){
            if(!empty($data["foto_nota$i"])) unlink("../uploads/nota/".$data["foto_nota$i"]);
        }
        mysqli_query($conn, "DELETE FROM laporan_cabang WHERE id_laporan=$id");
        echo "<script>alert('Data berhasil dihapus'); window.location='riwayat.php';</script>";
    }
}

// Filter tanggal
$tgl_awal = $_GET['tgl_awal'] ?? date('Y-m-01');
$tgl_akhir = $_GET['tgl_akhir'] ?? date('Y-m-d');

$query = "SELECT * FROM laporan_cabang 
          WHERE id_cabang=$id_cabang 
          AND tanggal BETWEEN '$tgl_awal' AND '$tgl_akhir' 
          ORDER BY tanggal DESC";
$data = mysqli_query($conn, $query);

$cabang = mysqli_fetch_assoc(mysqli_query($conn, "SELECT nama_cabang FROM cabang WHERE id_cabang=$id_cabang"))['nama_cabang'];
?>

<h3 class="mb-4"><i class="bi bi-clock-history"></i> Riwayat Input Data</h3>

<div class="alert alert-info">
    <i class="bi bi-shop"></i> <strong><?= $cabang ?></strong> | Pengelola: <?= $nama_pengelola ?>
</div>

<!-- Filter -->
<div class="card mb-3">
<div class="card-body">
<form method="GET" class="row g-2">
<div class="col-md-4">
<label>Tanggal Awal</label>
<input type="date" name="tgl_awal" value="<?= $tgl_awal ?>" class="form-control">
</div>
<div class="col-md-4">
<label>Tanggal Akhir</label>
<input type="date" name="tgl_akhir" value="<?= $tgl_akhir ?>" class="form-control">
</div>
<div class="col-md-4 d-flex align-items-end">
<button class="btn btn-primary w-100"><i class="bi bi-search"></i> Filter</button>
</div>
</form>
</div>
</div>

<!-- Tabel Riwayat -->
<div class="card">
<div class="card-body">
<div class="table-responsive">
<table class="table table-bordered table-hover align-middle">
<thead class="table-dark">
<tr>
<th>No</th>
<th>Tanggal</th>
<th>Omzet</th>
<th>Pengeluaran</th>
<th>Net Profit</th>
<th>Margin</th>
<th>Nota</th>
<th>Aksi</th>
</tr>
</thead>
<tbody>
<?php if(mysqli_num_rows($data) == 0): ?>
<tr><td colspan="8" class="text-center text-muted">Belum ada data</td></tr>
<?php endif; ?>
<?php $no=1; while($row=mysqli_fetch_assoc($data)): ?>
<tr>
<td><?= $no++ ?></td>
<td><?= date('d/m/Y', strtotime($row['tanggal'])) ?></td>
<td><b class="text-success">Rp <?= number_format($row['total_omset'],0,',','.') ?></b></td>
<td class="text-danger">Rp <?= number_format($row['total_pengeluaran'],0,',','.') ?></td>
<td><b class="text-primary">Rp <?= number_format($row['net_profit'],0,',','.') ?></b></td>
<td><span class="badge bg-success"><?= number_format($row['persentase'],2) ?>%</span></td>
<td>
<?php for($i=1; $i<=4; $i++): 
if(!empty($row["foto_nota$i"])): ?>
<a href="../uploads/nota/<?= $row["foto_nota$i"] ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
<i class="bi bi-image"></i>
</a>
<?php endif; endfor; ?>
</td>
<td>
<button class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#detail<?= $row['id_laporan'] ?>">
<i class="bi bi-eye"></i> Detail
</button>
<a href="?hapus=<?= $row['id_laporan'] ?>&tgl_awal=<?= $tgl_awal ?>&tgl_akhir=<?= $tgl_akhir ?>" 
   class="btn btn-sm btn-danger" 
   onclick="return confirm('Hapus data tanggal <?= date('d/m/Y', strtotime($row['tanggal'])) ?>?')">
<i class="bi bi-trash"></i>
</a>
</td>
</tr>

<!-- Modal Detail -->
<div class="modal fade" id="detail<?= $row['id_laporan'] ?>" tabindex="-1">
<div class="modal-dialog modal-lg">
<div class="modal-content">
<div class="modal-header bg-info text-white">
<h5 class="modal-title">Detail Data <?= date('d/m/Y', strtotime($row['tanggal'])) ?></h5>
<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
<div class="row">
<div class="col-md-6">
<h6 class="text-success">Pendapatan</h6>
<table class="table table-sm">
<tr><td>Tunai</td><td>: Rp <?= number_format($row['tunai'],0,',','.') ?></td></tr>
<tr><td>QRIS</td><td>: Rp <?= number_format($row['qris'],0,',','.') ?></td></tr>
<tr><td>Grab Food</td><td>: Rp <?= number_format($row['grab_food'],0,',','.') ?></td></tr>
<tr><td>Go Food</td><td>: Rp <?= number_format($row['go_food'],0,',','.') ?></td></tr>
<tr class="table-success"><th>Total Omzet</th><th>: Rp <?= number_format($row['total_omset'],0,',','.') ?></th></tr>
</table>

<h6 class="text-warning mt-3">Pengeluaran Rutin</h6>
<table class="table table-sm">
<tr><td>Pasar</td><td>: Rp <?= number_format($row['belanja_pasar'],0,',','.') ?></td></tr>
<tr><td>Sembako</td><td>: Rp <?= number_format($row['belanja_sembako'],0,',','.') ?></td></tr>
<tr><td>Beras</td><td>: Rp <?= number_format($row['belanja_beras'],0,',','.') ?></td></tr>
<tr><td>Toko</td><td>: Rp <?= number_format($row['belanja_toko'],0,',','.') ?></td></tr>
<tr class="table-warning"><th>Total Rutin</th><th>: Rp <?= number_format($row['total_rutin'],0,',','.') ?></th></tr>
</table>
</div>

<div class="col-md-6">
<h6 class="text-info">Beban Operasional</h6>
<table class="table table-sm">
<tr><td>Sewa</td><td>: Rp <?= number_format($row['sewa'],0,',','.') ?></td></tr>
<tr><td>Gaji</td><td>: Rp <?= number_format($row['gaji'],0,',','.') ?></td></tr>
<tr><td>Listrik</td><td>: Rp <?= number_format($row['listrik'],0,',','.') ?></td></tr>
<tr><td>Air</td><td>: Rp <?= number_format($row['air'],0,',','.') ?></td></tr>
<tr><td>Sampah</td><td>: Rp <?= number_format($row['sampah'],0,',','.') ?></td></tr>
<tr><td>Keamanan</td><td>: Rp <?= number_format($row['keamanan'],0,',','.') ?></td></tr>
<tr><td>Internet</td><td>: Rp <?= number_format($row['internet'],0,',','.') ?></td></tr>
<tr><td>Lain-lain</td><td>: Rp <?= number_format($row['lain_lain'],0,',','.') ?></td></tr>
<tr class="table-info"><th>Total Op</th><th>: Rp <?= number_format($row['total_operasional'],0,',','.') ?></th></tr>
</table>

<h6 class="text-danger mt-3">Rekap</h6>
<table class="table table-sm">
<tr><td>Total Pengeluaran</td><td>: Rp <?= number_format($row['total_pengeluaran'],0,',','.') ?></td></tr>
<tr><td>Sisa Tunai</td><td>: Rp <?= number_format($row['sisa_tunai'],0,',','.') ?></td></tr>
<tr class="table-success"><th>Net Profit</th><th>: Rp <?= number_format($row['net_profit'],0,',','.') ?></th></tr>
<tr class="table-success"><th>Margin</th><th>: <?= number_format($row['persentase'],2) ?>%</th></tr>
</table>
</div>

<div class="col-12 mt-3">
<h6>Keterangan</h6>
<p class="border p-2 rounded bg-light"><?= $row['keterangan'] ?: '-' ?></p>
</div>
</div>
</div>
<div class="modal-footer">
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
</div>
</div>
</div>
</div>
<?php endwhile; ?>
</tbody>
</table>
</div>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</div></body></html>