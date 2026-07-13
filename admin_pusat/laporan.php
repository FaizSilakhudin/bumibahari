<?php
require '../config/koneksi.php';
include 'sidebar_pusat.php';

// 1. Pindahkan inisialisasi filter ke atas agar bisa dipakai di proses POST (Update) maupun GET
$tgl_awal = $_GET['tgl_awal'] ?? date('Y-m-01');
$tgl_akhir = $_GET['tgl_akhir'] ?? date('Y-m-d');
$id_cabang = $_GET['id_cabang'] ?? '';

// Proses update
if(isset($_POST['update_laporan'])){
    $id = (int)$_POST['id'];
    
    $tunai = (int)($_POST['tunai'] ?? 0);
    $qris = (int)($_POST['qris'] ?? 0);
    $grab_food = (int)($_POST['grab_food'] ?? 0);
    $go_food = (int)($_POST['go_food'] ?? 0);
    $belanja_pasar = (int)($_POST['belanja_pasar'] ?? 0);
    $belanja_sembako = (int)($_POST['belanja_sembako'] ?? 0);
    $belanja_beras = (int)($_POST['belanja_beras'] ?? 0);
    $belanja_toko = (int)($_POST['belanja_toko'] ?? 0);
    $sewa = (int)($_POST['sewa'] ?? 0);
    $gaji = (int)($_POST['gaji'] ?? 0);
    $listrik = (int)($_POST['listrik'] ?? 0);
    $air = (int)($_POST['air'] ?? 0);
    $sampah = (int)($_POST['sampah'] ?? 0);
    $keamanan = (int)($_POST['keamanan'] ?? 0);
    $internet = (int)($_POST['internet'] ?? 0);
    $lain_lain = (int)($_POST['lain_lain'] ?? 0);
    $keterangan = $_POST['keterangan'] ?? '';
    
    $total_omset = $tunai + $qris + $grab_food + $go_food;
    $total_rutin = $belanja_pasar + $belanja_sembako + $belanja_beras + $belanja_toko;
    $total_operasional = $sewa + $gaji + $listrik + $air + $sampah + $keamanan + $internet + $lain_lain;
    $total_pengeluaran = $total_rutin + $total_operasional;
    $net_profit = $total_omset - $total_pengeluaran;
    $persentase = $total_omset > 0 ? round(($net_profit / $total_omset) * 100, 2) : 0;
    $sisa_tunai = $tunai - $total_pengeluaran;
    
    $sql = "UPDATE laporan_cabang SET 
            tunai=?, qris=?, grab_food=?, go_food=?, total_omset=?,
            belanja_pasar=?, belanja_sembako=?, belanja_beras=?, belanja_toko=?, total_rutin=?,
            sewa=?, gaji=?, listrik=?, air=?, sampah=?, keamanan=?, internet=?, lain_lain=?, total_operasional=?,
            total_pengeluaran=?, sisa_tunai=?, net_profit=?, persentase=?, keterangan=?
            WHERE id=?";
    
    $stmt = $conn->prepare($sql);
    
    // 24 huruf: 22x i + 1x d + 1x s
    $types = "iiiiiiiiiiiiiiiiiiiiiidsi";
    
    $stmt->bind_param(
        $types,
        $tunai, $qris, $grab_food, $go_food, $total_omset,
        $belanja_pasar, $belanja_sembako, $belanja_beras, $belanja_toko, $total_rutin,
        $sewa, $gaji, $listrik, $air, $sampah, $keamanan, $internet, $lain_lain, $total_operasional,
        $total_pengeluaran, $sisa_tunai, $net_profit, $persentase, $keterangan, $id
    );
    
    if($stmt->execute()){
        $param = http_build_query(['tgl_awal'=>$tgl_awal, 'tgl_akhir'=>$tgl_akhir, 'id_cabang'=>$id_cabang]);
        echo "<script>alert('Data berhasil diupdate'); window.location='laporan.php?$param';</script>";
        exit;
    } else {
        echo "<script>alert('Gagal update: ".$stmt->error."');</script>";
    }
}

// 2. Query Data Otomatis Menggunakan Variabel di Atas
$where = "WHERE l.tanggal BETWEEN '$tgl_awal' AND '$tgl_akhir'";
if($id_cabang != '') $where .= " AND l.id_cabang = " . (int)$id_cabang;

$query = "SELECT l.*, c.nama_cabang 
          FROM laporan_cabang l 
          JOIN cabang c ON l.id_cabang = c.id_cabang 
          $where 
          ORDER BY l.tanggal DESC";
$data = mysqli_query($conn, $query);

$total_omset_query = mysqli_fetch_assoc(mysqli_query($conn, "SELECT SUM(total_omset) as total FROM laporan_cabang l $where"));
$total_omset = $total_omset_query['total'] ?? 0;

$total_laba_query = mysqli_fetch_assoc(mysqli_query($conn, "SELECT SUM(net_profit) as total FROM laporan_cabang l $where"));
$total_laba = $total_laba_query['total'] ?? 0;

$cabang = mysqli_query($conn, "SELECT * FROM cabang ORDER BY nama_cabang");
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold mb-1 text-dark">Laporan Harian Semua Cabang</h3>
            <p class="text-muted small mb-0">Pantau perkembangan omzet, pengeluaran, dan net profit secara berkala.</p>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px;">
        <div class="card-body p-4">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-lg-3 col-md-6">
                    <label class="form-label fw-semibold text-secondary small">Tanggal Awal</label>
                    <input type="date" name="tgl_awal" value="<?= $tgl_awal ?>" class="form-control form-control-md border-2 bg-light">
                </div>
                <div class="col-lg-3 col-md-6">
                    <label class="form-label fw-semibold text-secondary small">Tanggal Akhir</label>
                    <input type="date" name="tgl_akhir" value="<?= $tgl_akhir ?>" class="form-control form-control-md border-2 bg-light">
                </div>
                <div class="col-lg-4 col-md-8">
                    <label class="form-label fw-semibold text-secondary small">Pilih Cabang</label>
                    <select name="id_cabang" class="form-select form-select-md border-2 bg-light">
                        <option value="">Semua Cabang</option>
                        <?php while($c=mysqli_fetch_assoc($cabang)): ?>
                        <option value="<?= $c['id_cabang'] ?>" <?= $id_cabang==$c['id_cabang']?'selected':'' ?>>
                            <?= $c['nama_cabang'] ?>
                        </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="col-lg-2 col-md-4 d-grid">
                    <button type="submit" class="btn btn-primary btn-md fw-bold">
                        <i class="bi bi-funnel-fill me-1"></i> Filter
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="card border-0 shadow-sm border-start border-4 border-primary h-100" style="border-radius: 8px;">
                <div class="card-body d-flex align-items-center justify-content-between p-4">
                    <div>
                        <span class="text-muted small text-uppercase fw-bold tracking-wider">Total Omzet</span>
                        <h3 class="text-primary fw-bold mb-0 mt-1">Rp <?= number_format($total_omset,0,',','.') ?></h3>
                    </div>
                    <div class="bg-primary bg-opacity-10 text-primary p-3 rounded-3">
                        <i class="bi bi-wallet2 fs-3"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card border-0 shadow-sm border-start border-4 border-success h-100" style="border-radius: 8px;">
                <div class="card-body d-flex align-items-center justify-content-between p-4">
                    <div>
                        <span class="text-muted small text-uppercase fw-bold tracking-wider">Total Laba Bersih</span>
                        <h3 class="text-success fw-bold mb-0 mt-1">Rp <?= number_format($total_laba,0,',','.') ?></h3>
                    </div>
                    <div class="bg-success bg-opacity-10 text-success p-3 rounded-3">
                        <i class="bi bi-graph-up-arrow fs-3"></i>
                    </div>
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
                            <th class="py-3">Pengelola</th>
                            <th class="py-3">Omzet</th>
                            <th class="py-3">Pengeluaran</th>
                            <th class="py-3">Laba Bersih</th>
                            <th class="py-3">Margin</th>
                            <th class="py-3 text-center">Foto Nota</th>
                            <th class="py-3 text-center" width="10%">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $no=1; while($row=mysqli_fetch_assoc($data)): 
                        $margin = $row['persentase'] ?? 0;
                        $id = $row['id'];
                        ?>
                        <tr>
                            <td class="text-center px-4 text-muted fw-bold"><?= $no++ ?></td>
                            <td><span class="badge bg-light text-dark border p-2"><i class="bi bi-calendar3 me-1 text-muted"></i> <?= date('d/m/Y', strtotime($row['tanggal'])) ?></span></td>
                            <td class="fw-semibold text-dark"><?= $row['nama_cabang'] ?></td>
                            <td class="text-muted"><?= $row['nama_pengelola'] ?></td>
                            <td><span class="text-dark fw-medium">Rp <?= number_format($row['total_omset'] ?? 0,0,',','.') ?></span></td>
                            <td><span class="text-secondary">Rp <?= number_format($row['total_pengeluaran'] ?? 0,0,',','.') ?></span></td>
                            <td>
                                <span class="fw-bold <?= ($row['net_profit'] ?? 0) >= 0 ? 'text-success' : 'text-danger' ?>">
                                    Rp <?= number_format($row['net_profit'] ?? 0,0,',','.') ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge <?= $margin >= 20 ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning' ?> px-2 py-1.5 rounded">
                                    <?= number_format($margin,2) ?>%
                                </span>
                            </td>
                            <td class="text-center">
                                <div class="d-flex justify-content-center gap-1">
                                    <?php for($i=1; $i<=4; $i++): 
                                    if(!empty($row["foto_nota$i"])): ?>
                                    <a href="../uploads/nota/<?= $row["foto_nota$i"] ?>" target="_blank" class="d-inline-block">
                                        <img src="../uploads/nota/<?= $row["foto_nota$i"] ?>" width="38" height="38" class="img-thumbnail rounded-2 shadow-sm object-fit-cover" style="transition: transform .2s;" onmouseover="this.style.transform='scale(1.1)'" onmouseout="this.style.transform='scale(1)'">
                                    </a>
                                    <?php endif; endfor; ?>
                                </div>
                            </td>
                            <td class="text-center px-4">
                                <button class="btn btn-sm btn-outline-primary px-3 rounded-pill fw-medium" data-bs-toggle="modal" data-bs-target="#detailModal<?= $id ?>">
                                    <i class="bi bi-pencil-square me-1"></i> Detail / Edit
                                </button>

                                <div class="modal fade text-start" id="detailModal<?= $id ?>" tabindex="-1" aria-hidden="true">
                                    <div class="modal-dialog modal-xl modal-dialog-scrollable modal-dialog-centered m-2 m-sm-auto">
                                        <form method="POST" class="w-100">
                                            <input type="hidden" name="id" value="<?= $id ?>">
                                            <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
                                                
                                                <div class="modal-header bg-dark text-white p-3 p-sm-4">
                                                    <div>
                                                        <h5 class="modal-title fw-bold mb-1 fs-6 fs-sm-5">Detail & Koreksi Laporan</h5>
                                                        <small class="text-white-50 d-block" style="font-size: 0.75rem;">
                                                            <i class="bi bi-shop me-1"></i> <?= $row['nama_cabang'] ?> 
                                                            <span class="mx-1">|</span> 
                                                            <i class="bi bi-calendar3 me-1"></i> <?= date('d/m/Y', strtotime($row['tanggal'])) ?>
                                                        </small>
                                                    </div>
                                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                                </div>

                                                <div class="modal-body p-3 p-sm-4 bg-light">
                                                    <div class="row g-3">
                                                        
                                                        <div class="col-lg-6">
                                                            <div class="card border-0 shadow-sm h-100" style="border-radius: 10px;">
                                                                <div class="card-header bg-primary bg-opacity-10 text-primary border-0 py-2.5 px-3 fw-bold small">
                                                                    <i class="bi bi-currency-dollar me-1"></i> 1. Pendapatan (Omzet)
                                                                </div>
                                                                <div class="card-body p-3">
                                                                    <div class="d-flex justify-content-between align-items-center bg-light p-2 rounded mb-3 border">
                                                                        <span class="fw-semibold text-secondary small">Total Terkalkulasi</span>
                                                                        <span class="text-primary fw-bold small">Rp <?= number_format($row['total_omset'] ?? 0,0,',','.') ?></span>
                                                                    </div>
                                                                    <div class="row g-2">
                                                                        <div class="col-6">
                                                                            <label class="small text-muted mb-1" style="font-size: 0.75rem;">Tunai</label>
                                                                            <input type="number" name="tunai" class="form-control form-control-sm border-2" value="<?= $row['tunai'] ?? 0 ?>">
                                                                        </div>
                                                                        <div class="col-6">
                                                                            <label class="small text-muted mb-1" style="font-size: 0.75rem;">QRIS</label>
                                                                            <input type="number" name="qris" class="form-control form-control-sm border-2" value="<?= $row['qris'] ?? 0 ?>">
                                                                        </div>
                                                                        <div class="col-6">
                                                                            <label class="small text-muted mb-1" style="font-size: 0.75rem;">Grab Food</label>
                                                                            <input type="number" name="grab_food" class="form-control form-control-sm border-2" value="<?= $row['grab_food'] ?? 0 ?>">
                                                                        </div>
                                                                        <div class="col-6">
                                                                            <label class="small text-muted mb-1" style="font-size: 0.75rem;">Go Food</label>
                                                                            <input type="number" name="go_food" class="form-control form-control-sm border-2" value="<?= $row['go_food'] ?? 0 ?>">
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>

                                                        <div class="col-lg-6">
                                                            <div class="card border-0 shadow-sm h-100" style="border-radius: 10px;">
                                                                <div class="card-header bg-danger bg-opacity-10 text-danger border-0 py-2.5 px-3 fw-bold small">
                                                                    <i class="bi bi-cart3 me-1"></i> 2. Pengeluaran / Belanja
                                                                </div>
                                                                <div class="card-body p-3">
                                                                    <div class="row g-2">
                                                                        <div class="col-6">
                                                                            <label class="small text-muted mb-1" style="font-size: 0.75rem;">Belanja Pasar</label>
                                                                            <input type="number" name="belanja_pasar" class="form-control form-control-sm border-2" value="<?= $row['belanja_pasar'] ?? 0 ?>">
                                                                        </div>
                                                                        <div class="col-6">
                                                                            <label class="small text-muted mb-1" style="font-size: 0.75rem;">Belanja Sembako</label>
                                                                            <input type="number" name="belanja_sembako" class="form-control form-control-sm border-2" value="<?= $row['belanja_sembako'] ?? 0 ?>">
                                                                        </div>
                                                                        <div class="col-6">
                                                                            <label class="small text-muted mb-1" style="font-size: 0.75rem;">Belanja Beras</label>
                                                                            <input type="number" name="belanja_beras" class="form-control form-control-sm border-2" value="<?= $row['belanja_beras'] ?? 0 ?>">
                                                                        </div>
                                                                        <div class="col-6">
                                                                            <label class="small text-muted mb-1" style="font-size: 0.75rem;">Belanja Toko</label>
                                                                            <input type="number" name="belanja_toko" class="form-control form-control-sm border-2" value="<?= $row['belanja_toko'] ?? 0 ?>">
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>

                                                        <div class="col-lg-6">
                                                            <div class="card border-0 shadow-sm h-100" style="border-radius: 10px;">
                                                                <div class="card-header bg-secondary bg-opacity-10 text-dark border-0 py-2.5 px-3 fw-bold small">
                                                                    <i class="bi bi-building-gear me-1"></i> 3. Beban Operasional
                                                                </div>
                                                                <div class="card-body p-3">
                                                                    <div class="row g-2">
                                                                        <div class="col-6">
                                                                            <label class="small text-muted mb-1" style="font-size: 0.75rem;">Sewa</label>
                                                                            <input type="number" name="sewa" class="form-control form-control-sm border-2" value="<?= $row['sewa'] ?? 0 ?>">
                                                                        </div>
                                                                        <div class="col-6">
                                                                            <label class="small text-muted mb-1" style="font-size: 0.75rem;">Gaji</label>
                                                                            <input type="number" name="gaji" class="form-control form-control-sm border-2" value="<?= $row['gaji'] ?? 0 ?>">
                                                                        </div>
                                                                        <div class="col-6">
                                                                            <label class="small text-muted mb-1" style="font-size: 0.75rem;">Listrik</label>
                                                                            <input type="number" name="listrik" class="form-control form-control-sm border-2" value="<?= $row['listrik'] ?? 0 ?>">
                                                                        </div>
                                                                        <div class="col-6">
                                                                            <label class="small text-muted mb-1" style="font-size: 0.75rem;">Air</label>
                                                                            <input type="number" name="air" class="form-control form-control-sm border-2" value="<?= $row['air'] ?? 0 ?>">
                                                                        </div>
                                                                        <div class="col-6">
                                                                            <label class="small text-muted mb-1" style="font-size: 0.75rem;">Sampah / Keamanan</label>
                                                                            <div class="input-group input-group-sm">
                                                                                <input type="number" name="sampah" class="form-control border-2" value="<?= $row['sampah'] ?? 0 ?>" placeholder="Sampah">
                                                                                <input type="number" name="keamanan" class="form-control border-2" value="<?= $row['keamanan'] ?? 0 ?>" placeholder="Keamanan">
                                                                            </div>
                                                                        </div>
                                                                        <div class="col-6">
                                                                            <label class="small text-muted mb-1" style="font-size: 0.75rem;">Internet / Lain</label>
                                                                            <div class="input-group input-group-sm">
                                                                                <input type="number" name="internet" class="form-control border-2" value="<?= $row['internet'] ?? 0 ?>" placeholder="Net">
                                                                                <input type="number" name="lain_lain" class="form-control border-2" value="<?= $row['lain_lain'] ?? 0 ?>" placeholder="Lain">
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>

                                                        <div class="col-lg-6">
                                                            <div class="card border-0 shadow-sm h-100" style="border-radius: 10px;">
                                                                <div class="card-header bg-success bg-opacity-10 text-success border-0 py-2.5 px-3 fw-bold small">
                                                                    <i class="bi bi-chat-left-text me-1"></i> 4. Catatan / Keterangan
                                                                </div>
                                                                <div class="card-body p-3 d-flex flex-column justify-content-between">
                                                                    <div>
                                                                        <textarea name="keterangan" class="form-control border-2 mb-2 small" rows="3" placeholder="Tulis rincian tambahan di sini..."><?= $row['keterangan'] ?? '' ?></textarea>
                                                                        <div class="text-muted d-block" style="font-size: 0.7rem;">
                                                                            <span class="fw-semibold">Petunjuk:</span> Rincian pengeluaran darurat/tidak terduga.
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>

                                                    </div> 
                                                </div>
                                                
                                                <div class="modal-footer bg-white border-top p-3 justify-content-end gap-2">
                                                    <button type="button" class="btn btn-sm btn-light border fw-semibold px-3" data-bs-dismiss="modal">Batal</button>
                                                    <button type="submit" name="update_laporan" class="btn btn-sm btn-primary fw-semibold px-3">
                                                        <i class="bi bi-check-circle-fill me-1"></i> Simpan
                                                    </button>
                                                </div>

                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>