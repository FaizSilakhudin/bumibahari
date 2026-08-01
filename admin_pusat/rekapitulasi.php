<?php
require '../config/koneksi.php';
include 'sidebar_pusat.php';

// 1. PROTEKSI ROLE PUSAT
if(!isset($_SESSION['role']) || $_SESSION['role']!= 'pusat'){
    header("Location:../login"); exit;
}

$periode = $_GET['periode'] ?? 'bulanan';
$tahun = $_GET['tahun'] ?? date('Y');
$bulan = $_GET['bulan'] ?? date('m');
$id_cabang = $_GET['id_cabang'] ?? '';

// Ambil nama cabang yang kepilih biar input keisi
$nama_cabang_terpilih = '';
if($id_cabang!= ''){
    $stmt = $conn->prepare("SELECT nama_cabang FROM cabang WHERE id_cabang=?");
    $stmt->bind_param("i", $id_cabang);
    $stmt->execute();
    $nama_cabang_terpilih = $stmt->get_result()->fetch_assoc()['nama_cabang']?? '';
}

$list_cabang = $conn->query("SELECT id_cabang, nama_cabang FROM cabang ORDER BY nama_cabang");

// 2. BUAT WHERE PAKAI PREPARED
$where_sql = "";
$params = [];
$types = "";

if($periode == 'mingguan'){
    $where_sql = "WHERE YEAR(l.tanggal)=? AND WEEK(l.tanggal,1) = WEEK(CURDATE(),1)";
    $params[] = $tahun;
    $types .= "i";
    $judul = "Rekap Mingguan - Minggu ".date('W');
} 
elseif($periode == 'tahunan'){
    $where_sql = "WHERE YEAR(l.tanggal)=?";
    $params[] = $tahun;
    $types .= "i";
    $judul = "Rekap Tahunan - Tahun $tahun";
} 
else {
    $where_sql = "WHERE YEAR(l.tanggal)=? AND MONTH(l.tanggal)=?";
    $params[] = $tahun;
    $params[] = $bulan;
    $types .= "ii";
    $judul = "Rekap Bulanan - ".date('F Y', strtotime("$tahun-$bulan-01"));
}

// Filter cabang
$cabang_info = ['investor'=>'-', 'no_rekening'=>'-', 'nama_bank'=>'-'];
$nama_cabang = "Semua Cabang";
if($id_cabang!= ''){
    $where_sql .= " AND l.id_cabang = ?";
    $params[] = (int)$id_cabang;
    $types .= "i";
    
    $stmt = $conn->prepare("SELECT nama_cabang, investor, no_rekening, nama_bank FROM cabang WHERE id_cabang=?");
    $stmt->bind_param("i", $id_cabang);
    $stmt->execute();
    $cabang_info = $stmt->get_result()->fetch_assoc()?? $cabang_info;
    $nama_cabang = $cabang_info['nama_cabang']?? "Semua Cabang";
    $judul .= " - ".$nama_cabang;
}

// 3. QUERY DATA UTAMA PAKAI PREPARED
$query = "SELECT c.nama_cabang, c.investor, c.no_rekening, c.nama_bank,
          SUM(l.total_omset) as penjualan,
          SUM(l.total_pengeluaran) as pengeluaran,
          SUM(l.net_profit) as laba_bersih,
          SUM(l.sewa) as sewa,
          SUM(l.gaji) as gaji,
          SUM(l.listrik) as listrik,
          SUM(l.air) as air,
          SUM(l.sampah) as sampah,
          SUM(l.keamanan) as keamanan,
          SUM(l.internet) as internet,
          SUM(l.lain_lain) as lain_lain
          FROM laporan_cabang l
          JOIN cabang c ON l.id_cabang = c.id_cabang
          $where_sql
          GROUP BY l.id_cabang";

$stmt = $conn->prepare($query);
if(!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$data = $stmt->get_result();
$row = ($data->num_rows > 0)? $data->fetch_assoc() : [];

$penjualan   = (float)($row['penjualan']?? 0);
$pengeluaran = (float)($row['pengeluaran']?? 0);

/* Net Profit = Omzet - Total Pengeluaran */
$laba_bersih = $penjualan - $pengeluaran;

$margin = $penjualan > 0
    ? ($laba_bersih / $penjualan) * 100
    : 0;

// Data BO dari DB
$bo_db = [
    'sewa' => $row['sewa']?? 0,
    'gaji' => $row['gaji']?? 0,
    'listrik' => $row['listrik']?? 0,
    'internet' => $row['internet']?? 0,
    'sampah' => $row['sampah']?? 0,
    'keamanan' => $row['keamanan']?? 0,
    'air' => $row['air']?? 0,
    'lain_lain' => $row['lain_lain']?? 0
];

// Revenue sharing
$persen_investor = 50;
$persen_pengelola = 50;
$persen_admin = 3; // 3% dari bagian pengelola

$share_investor  = $laba_bersih * $persen_investor / 100;
$share_pengelola = $laba_bersih * $persen_pengelola / 100; 
$share_admin     = $share_pengelola * $persen_admin / 100;

// Pengelola bersih setelah kena admin
$share_pengelola_bersih = $share_pengelola - $share_admin;

// Data pengelola aman
$pengelola = ['nama_pengelola' => '-', 'no_rekening' => '-', 'nama_bank' => '-'];
if($id_cabang!= ''){
    $stmt = $conn->prepare("SELECT nama_pengelola, no_rekening, nama_bank FROM users WHERE id_cabang=? AND role='cabang' LIMIT 1");
    $stmt->bind_param("i", $id_cabang);
    $stmt->execute();
    $pengelola = $stmt->get_result()->fetch_assoc()?? $pengelola;
}

// Format nama file export
$nama_file_export = "Rekap Bulanan_".str_replace(' ', '_', $nama_cabang)."_".$tahun.$bulan;
?>

<!-- Link tambahan stylesheet icons -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<!-- Kustomisasi CSS untuk kenyamanan mata & kerapian ekstra -->
<style>
    .main-wrapper { font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; color: #334155; }
    .card { border: 1px solid #e2e8f0!important; border-radius: 14px!important; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.02), 0 2px 4px -1px rgba(0,0,0,0.01)!important; background-color: #ffffff; }
    .card-header { font-size: 0.95rem; letter-spacing: 0.02em; padding: 1rem 1.25rem!important; }
    .table th { font-weight: 600; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.05em; color: #64748b; background-color: #f8fafc!important; border-bottom: 2px solid #e2e8f0!important; padding: 12px 14px!important; }
    .table td { padding: 12px 14px!important; font-size: 0.875rem; color: #475569; }
    
    .table-clean-input input.form-control { border: 1px solid transparent!important; background-color: transparent!important; box-shadow: none!important; padding: 4px 8px; font-size: 0.875rem; transition: all 0.2s ease; border-radius: 6px!important; }
    .table-clean-input input.form-control:hover { background-color: #f1f5f9!important; border-color: #cbd5e1!important; }
    .table-clean-input input.form-control:focus { background-color: #ffffff!important; border-color: #3b82f6!important; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15)!important; }
    .table-clean-input input.keterangan { text-align: left; }
    
    .form-label-sm { font-size: 0.785rem; font-weight: 600; color: #64748b; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.02em; }
    .form-control-premium { border: 2px solid #e2e8f0!important; background-color: #f8fafc!important; border-radius: 8px!important; font-size: 0.9rem; padding: 0.5rem 0.75rem; }
    .form-control-premium:focus { background-color: #ffffff!important; border-color: #3b82f6!important; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1)!important; }
</style>

<div class="container-fluid py-4 main-wrapper">
    <!-- Header Page -->
    <div class="mb-4">
        <h3 class="fw-bold text-dark mb-1" style="letter-spacing: -0.5px;"><?= h($judul)?></h3>
        <p class="text-secondary small mb-0">Halaman rekapitulasi performa finansial, rasio bagi hasil, serta perhitungan biaya operasional.</p>
    </div>

    <!-- Filter Section -->
    <div class="card border-0 mb-4">
        <div class="card-body p-4">
            <form method="GET" class="row g-3 align-items-end" id="formFilter">
                <div class="col-xl-3 col-md-6">
                    <label class="form-label-sm">Cari / Pilih Cabang</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light border-2 text-muted border-end-0" style="border-radius: 8px 0 0 8px;"><i class="bi bi-shop"></i></span>
                        <input list="listCabang" id="inputCabang" class="form-control form-control-premium border-start-0" style="border-radius: 0 8px 8px 0!important;" placeholder="Ketik nama cabang..." value="<?= h($nama_cabang_terpilih)?>" autocomplete="off" required>
                    </div>
                    <input type="hidden" name="id_cabang" id="idCabang" value="<?= h($id_cabang)?>">

                    <datalist id="listCabang">
                        <?php $list_cabang->data_seek(0); 
                        while($c=$list_cabang->fetch_assoc()):?>
                        <option value="<?= h($c['nama_cabang'])?>" data-id="<?= $c['id_cabang']?>"></option>
                        <?php endwhile;?>
                    </datalist>
                </div>
                <div class="col-xl-2 col-md-6">
                    <label class="form-label-sm">Periode Analisis</label>
                    <select name="periode" class="form-select form-control-premium" onchange="this.form.submit()">
                        <option value="mingguan" <?= $periode=='mingguan'?'selected':''?>>Mingguan</option>
                        <option value="bulanan" <?= $periode=='bulanan'?'selected':''?>>Bulanan</option>
                        <option value="tahunan" <?= $periode=='tahunan'?'selected':''?>>Tahunan</option>
                    </select>
                </div>
                <div class="col-xl-2 col-md-6">
                    <label class="form-label-sm">Tahun Buku</label>
                    <select name="tahun" class="form-select form-control-premium">
                        <?php for($t=date('Y'); $t>=2024; $t--):?>
                        <option value="<?= $t?>" <?= $tahun==$t?'selected':''?>><?= $t?></option>
                        <?php endfor;?>
                    </select>
                </div>
                <div class="col-xl-2 col-md-6">
                    <label class="form-label-sm">Bulan Buku</label>
                    <select name="bulan" class="form-select form-control-premium" <?= $periode=='tahunan'?'disabled':''?>>
                        <?php for($b=1; $b<=12; $b++):?>
                        <option value="<?= str_pad($b,2,'0',STR_PAD_LEFT)?>" <?= $bulan==str_pad($b,2,'0',STR_PAD_LEFT)?'selected':''?>>
                            <?= date('F', mktime(0,0,0,$b,1))?>
                        </option>
                        <?php endfor;?>
                    </select>
                </div>
                <div class="col-xl-3 col-md-12 d-grid">
                    <button class="btn btn-primary fw-semibold py-2" style="border-radius: 8px;"><i class="bi bi-funnel-fill me-1"></i> Ambil Data</button>
                </div>
            </form>
        </div>
    </div>

    <?php if($id_cabang == ''):?>
    <div class="alert alert-warning border-0 p-4 d-flex align-items-center" role="alert" style="border-radius: 12px; background-color: #fffbeb; border: 1px solid #fde68a!important;">
        <i class="bi bi-exclamation-circle-fill fs-4 me-3 text-warning"></i>
        <div>
            <h6 class="fw-bold text-warning-emphasis mb-1">Pilih Cabang Terlebih Dahulu</h6>
            <span class="text-secondary small">Gunakan form pencarian di atas untuk memuat data transaksi, rincian biaya, dan grafik pembagian hasil.</span>
        </div>
    </div>
    <?php else:?>

    <!-- 1. Rekap Cabang Overview Widgets -->
    <div class="row g-3 mb-4">
        <div class="col-xl-3 col-md-6">
            <div class="card border-0 border-start border-4 border-dark h-100">
                <div class="card-body p-3 d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted small text-uppercase fw-semibold" style="font-size: 0.75rem;">Nama Cabang</span>
                        <h5 class="fw-bold text-dark mb-0 mt-1"><?= h($nama_cabang)?></h5>
                    </div>
                    <div class="bg-light text-dark p-2.5 rounded-3 border">
                        <i class="bi bi-shop fs-5"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card border-0 border-start border-4 border-primary h-100">
                <div class="card-body p-3 d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted small text-uppercase fw-semibold" style="font-size: 0.75rem;">Total Penjualan</span>
                        <h5 class="fw-bold text-primary mb-0 mt-1">Rp <?= number_format($penjualan,0,',','.')?></h5>
                    </div>
                    <div class="bg-primary bg-opacity-10 text-primary p-2.5 rounded-3">
                        <i class="bi bi-cash-stack fs-5"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card border-0 border-start border-4 border-secondary h-100">
                <div class="card-body p-3 d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted small text-uppercase fw-semibold" style="font-size: 0.75rem;">Total Pengeluaran</span>
                        <h5 class="fw-bold text-secondary mb-0 mt-1">Rp <?= number_format($pengeluaran,0,',','.')?></h5>
                    </div>
                    <div class="bg-secondary bg-opacity-10 text-secondary p-2.5 rounded-3">
                        <i class="bi bi-receipt fs-5"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card border-0 border-start border-4 border-success h-100">
                <div class="card-body p-3 d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted small text-uppercase fw-semibold" style="font-size: 0.75rem;">Laba Bersih (Margin)</span>
                        <h5 class="fw-bold text-success mb-0 mt-1">Rp <?= number_format($laba_bersih,0,',','.')?> <span class="fs-6 text-muted fw-normal">(<?= number_format($margin,2)?>%)</span></h5>
                    </div>
                    <div class="bg-success bg-opacity-10 text-success p-2.5 rounded-3">
                        <i class="bi bi-pie-chart fs-5"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 1. Rekap Harian Sebulan Full -->
<div class="card border-0 mt-4" style="overflow: hidden;">
    <div class="card-header bg-dark text-white py-3 d-flex align-items-center justify-content-between">
        <span class="fw-bold"><i class="bi bi-calendar3 me-2"></i>1. Rekapitulasi Pendapatan & Pengeluaran Harian - <?= date('F Y', strtotime("$tahun-$bulan-01"))?></span>
        <span class="badge bg-light text-dark fw-medium px-3 py-1.5 rounded-pill">Detail per tanggal</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 text-nowrap" id="tabelRekapHarian">
                <thead class="table-light">
                    <tr>
                        <th class="text-center" width="3%">No</th>
                        <th width="7%">Tanggal</th>
                        <th class="text-end" width="6%">Tunai</th>
                        <th colspan="3" class="text-center" width="15%">Non-Tunai</th>
                        <th class="text-end" width="7%">OMZET</th>
                        <th colspan="4" class="text-center" width="20%">Pengeluaran Belanja</th>
                        <th colspan="3" class="text-center" width="15%">Beban Operasional</th>
                        <th class="text-end" width="8%">Total Pengeluaran</th>
                        <th class="text-end" width="8%">Net Profit</th>
                        <th class="text-center" width="4%">%</th>
                    </tr>
                    <tr class="table-secondary" style="font-size: 0.8rem;">
                        <th></th>
                        <th></th>
                        <th></th>
                        <th class="text-end">QRIS</th>
                        <th class="text-end">Go-Food</th>
                        <th class="text-end">Grab-Food</th>
                        <th></th>
                        <th class="text-end">Pasar</th>
                        <th class="text-end">Beras</th>
                        <th class="text-end">Sembako</th>
                        <th class="text-end">Toko</th>
                        <th class="text-end">Sewa Ruko</th>
                        <th class="text-end">Gaji Karyawan</th>
                        <th class="text-end">Lain-Lain</th>
                        <th></th>
                        <th></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    // 4. QUERY HARIAN PAKAI PREPARED
                    $query_harian = "SELECT l.tanggal, l.tunai, l.qris, l.go_food, l.grab_food, 
                    l.total_omset, l.total_pengeluaran, 
                    l.belanja_pasar, l.belanja_beras, l.belanja_sembako, l.belanja_toko,
                    l.sewa, l.gaji, l.listrik, l.air, l.sampah, l.keamanan, l.internet, l.lain_lain,
                    l.net_profit, l.persentase 
                    FROM laporan_cabang l
                    JOIN cabang c ON l.id_cabang = c.id_cabang
                    WHERE YEAR(l.tanggal) = ? 
                    AND MONTH(l.tanggal) = ?
                    AND l.id_cabang = ?
                    ORDER BY l.tanggal ASC";

                    $stmt = $conn->prepare($query_harian);
                    $stmt->bind_param("iii", $tahun, $bulan, $id_cabang);
                    $stmt->execute();
                    $data_harian = $stmt->get_result();
                    
                    $total_tunai = $total_qris = $total_gofood = $total_grab = 0;
                    $total_omzet = $total_belanja = $total_pasar = $total_beras = $total_sembako = $total_toko = 0;
                    $total_sewa = $total_gaji = $total_lain = 0;
                    $total_pengeluaran = $total_sisa_tunai = $total_laba = 0;
                    $no = 1;

                    if($data_harian->num_rows > 0):
                        while($h = $data_harian->fetch_assoc()):
                            $tunai         = (float)($h['tunai']?? 0);
                            $qris          = (float)($h['qris']?? 0);
                            $go_food       = (float)($h['go_food']?? 0);
                            $grab_food     = (float)($h['grab_food']?? 0);
                            $omzet         = (float)($h['total_omset']?? 0);
                            $pasar         = (float)($h['belanja_pasar']?? 0);
                            $beras         = (float)($h['belanja_beras']?? 0);
                            $sembako       = (float)($h['belanja_sembako']?? 0);
                            $toko          = (float)($h['belanja_toko']?? 0);
                            $sewa          = (float)($h['sewa']?? 0);
                            $gaji          = (float)($h['gaji']?? 0);
                            $laba_harian   = (float)($h['net_profit']?? 0);
                            $persentase    = (float)($h['persentase']?? 0);

                            $lain_lain_operasional = (float)($h['listrik']?? 0) 
                                                   + (float)($h['air']?? 0) 
                                                   + (float)($h['sampah']?? 0) 
                                                   + (float)($h['keamanan']?? 0) 
                                                   + (float)($h['internet']?? 0) 
                                                   + (float)($h['lain_lain']?? 0);
                            
                            $total_pengeluaran_harian = $pasar + $beras + $sembako + $toko + $sewa + $gaji + $lain_lain_operasional;
                            $sisa_tunai_harian = $tunai - $total_pengeluaran_harian;

                            $total_tunai += $tunai;
                            $total_qris += $qris;
                            $total_gofood += $go_food;
                            $total_grab += $grab_food;
                            $total_omzet += $omzet;
                            $total_pasar += $pasar;
                            $total_beras += $beras;
                            $total_sembako += $sembako;
                            $total_toko += $toko;
                            $total_sewa += $sewa;
                            $total_gaji += $gaji;
                            $total_lain += $lain_lain_operasional;
                            $total_laba += $laba_harian;
                            $total_pengeluaran += $total_pengeluaran_harian;
                            $total_sisa_tunai += $sisa_tunai_harian;
                    ?>
                    <tr>
                        <td class="text-center text-muted"><?= $no++?></td>
                        <td class="fw-medium"><?= date('d/m/Y', strtotime($h['tanggal']))?></td>
                        <td class="text-end"><?= number_format($tunai,0,',','.')?></td>
                        <td class="text-end"><?= $qris > 0? number_format($qris,0,',','.') : '-'?></td>
                        <td class="text-end"><?= $go_food > 0? number_format($go_food,0,',','.') : '-'?></td>
                        <td class="text-end"><?= $grab_food > 0? number_format($grab_food,0,',','.') : '-'?></td>
                        <td class="text-end fw-semibold"><?= number_format($omzet,0,',','.')?></td>
                        <td class="text-end"><?= number_format($pasar,0,',','.')?></td>
                        <td class="text-end"><?= number_format($beras,0,',','.')?></td>
                        <td class="text-end"><?= number_format($sembako,0,',','.')?></td>
                        <td class="text-end"><?= number_format($toko,0,',','.')?></td>
                        <td class="text-end"><?= number_format($sewa,0,',','.')?></td>
                        <td class="text-end"><?= number_format($gaji,0,',','.')?></td>
                        <td class="text-end"><?= number_format($lain_lain_operasional,0,',','.')?></td>
                        <td class="text-end fw-semibold text-danger"><?= number_format($total_pengeluaran_harian,0,',','.')?></td>
                        <td class="text-end fw-bold text-success"><?= number_format($laba_harian,0,',','.')?></td>
                        <td class="text-center fw-semibold"><?= number_format($persentase,2)?>%</td>
                    </tr>
                    <?php 
                        endwhile;
                        $margin_total = $total_omzet > 0? ($total_laba / $total_omzet) * 100 : 0;
                    else:?>
                    <tr>
                        <td colspan="17" class="text-center text-muted py-5">
                            <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                            Belum ada data laporan untuk periode ini
                        </td>
                    </tr>
                    <?php endif;?>
                </tbody>
                <?php if($data_harian->num_rows > 0):?>
                <tfoot class="table-dark">
                <tr class="fw-bold">
                    <td colspan="2" class="text-center">JUMLAH</td>
                    <td class="text-end"><?= number_format($total_tunai,0,',','.')?></td>
                    <td class="text-end"><?= number_format($total_qris,0,',','.')?></td>
                    <td class="text-end"><?= number_format($total_gofood,0,',','.')?></td>
                    <td class="text-end"><?= number_format($total_grab,0,',','.')?></td>
                    <td class="text-end"><?= number_format($total_omzet,0,',','.')?></td>
                    <td class="text-end"><?= number_format($total_pasar,0,',','.')?></td>
                    <td class="text-end"><?= number_format($total_beras,0,',','.')?></td>
                    <td class="text-end"><?= number_format($total_sembako,0,',','.')?></td>
                    <td class="text-end"><?= number_format($total_toko,0,',','.')?></td>
                    <td class="text-end"><?= number_format($total_sewa,0,',','.')?></td>
                    <td class="text-end"><?= number_format($total_gaji,0,',','.')?></td>
                    <td class="text-end"><?= number_format($total_lain,0,',','.')?></td>
                    <td class="text-end text-danger"><?= number_format($total_pengeluaran,0,',','.')?></td>
                    <td class="text-end"><?= number_format($total_laba,0,',','.')?></td>
                    <td class="text-center"><?= number_format($margin_total,2)?>%</td>
                </tr>
                </tfoot>
                <?php endif;?>
            </table>
        </div>
    </div>
</div>

 <!-- 2. Rincian Beban Operasional -->
    <div class="card border-0 mb-4" style="overflow: hidden;">
        <div class="card-header bg-light border-bottom py-3 d-flex align-items-center justify-content-between">
            <span class="fw-bold text-dark"><i class="bi bi-folder-symlink me-2 text-muted"></i>2. Rincian Beban Operasional</span>
            <span class="badge bg-secondary bg-opacity-10 text-secondary px-3 py-1.5 rounded-pill fw-medium" style="font-size: 0.75rem;">Arahkan kursor / klik nilai untuk merubah</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 text-nowrap table-clean-input">
                    <thead>
                        <tr>
                            <th class="text-center" width="5%">No</th>
                            <th>Uraian Beban</th>
                            <th class="text-center" width="13%">Harian (Rp)</th>
                            <th class="text-center" width="13%">Bulanan (Rp)</th>
                            <th class="text-center" width="13%">Tahunan (Rp)</th>
                            <th class="text-center" width="13%">Di Bayarkan (Rp)</th>
                            <th class="text-end" width="15%">Jumlah Akhir</th>
                            <th class="ps-4">Keterangan Tambahan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $uraian_bo = [
                            1 => ['nama'=>'Sewa Ruko', 'field'=>'sewa'],
                            2 => ['nama'=>'Gaji Karyawan', 'field'=>'gaji'],
                            3 => ['nama'=>'Listrik Prabayar', 'field'=>'listrik'],
                            4 => ['nama'=>'Wifi/Internet', 'field'=>'internet'],
                            5 => ['nama'=>'Gas', 'field'=>'lain_lain'],
                            6 => ['nama'=>'Mingguan Karyawan', 'field'=>''],
                            7 => ['nama'=>'Es Batu, Air Galon', 'field'=>''],
                            8 => ['nama'=>'Iuran Kebersihan', 'field'=>'sampah'],
                            9 => ['nama'=>'Operasional Outlet', 'field'=>''],
                            10 => ['nama'=>'Kertas Nasi WBB', 'field'=>''],
                        ];

                        foreach($uraian_bo as $no => $item):
                            $field = $item['field'];
                            $val_bulanan = $field != '' ? ($bo_db[$field] ?? 0) : 0;
                            $is_admin = $item['nama'] == 'Admin Fee';
                        ?>
                        <tr>
                            <td class="text-center text-muted fw-medium"><?= $no ?></td>
                            <td class="fw-semibold text-dark"><?= h($item['nama']) ?></td>
                            <td><input type="number" class="form-control text-center harian" data-no="<?= $no ?>" value="0" oninput="hitungBO()"></td>
                            <td><input type="number" class="form-control text-center bulanan" data-no="<?= $no ?>" value="<?= $val_bulanan ?>" oninput="hitungBO()"></td>
                            <td><input type="number" class="form-control text-center tahunan" data-no="<?= $no ?>" value="0" oninput="hitungBO()"></td>
                            <td><input type="number" class="form-control text-center dibayarkan" data-no="<?= $no ?>" value="0" oninput="hitungBO()"></td>
                            <td class="text-end fw-bold text-dark pe-3"><span class="jumlah" data-no="<?= $no ?>">0</span></td>
                            <td class="ps-4"><input type="text" class="form-control keterangan" data-no="<?= $no ?>" placeholder="<?= h($is_admin ? '3% Dari Nett Profit' : '...') ?>"></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="table-light border-top border-2" style="background-color: #f8fafc !important;">
                            <td colspan="6" class="text-end fw-bold text-secondary py-3">TOTAL BEBAN OPERASIONAL:</td>
                            <td class="text-end fw-bold text-primary py-3 pe-3"><span id="total_bo">Rp 0</span></td>
                            <td></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

<!-- 3. Akumulasi & 4. Revenue Sharing Split Row -->
    <div class="row g-4 mb-4">
        <!-- 3. Akumulasi -->
        <div class="col-lg-6">
            <div class="card border-0 h-100" style="overflow: hidden;">
                <div class="card-header bg-light border-bottom py-3">
                    <span class="fw-bold text-dark"><i class="bi bi-calculator me-2 text-muted"></i>3. Matriks Akumulasi</span>
                </div>
                <div class="card-body p-0">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr class="table-light">
                                <th class="px-3">Komponen Pokok</th>
                                <th class="text-end" width="30%">Jumlah</th>
                                <th class="px-3 text-muted">Catatan Ringkas</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="px-3">Omzet Penjualan</td>
                                <td class="text-end fw-semibold text-dark">Rp <?= number_format($penjualan,0,',','.') ?></td>
                                <td class="text-muted small px-3">Pendapatan bruto masuk</td>
                            </tr>
                            <tr>
                                <td class="px-3">Pengeluaran Belanja</td>
                                <td class="text-end px-2"><input type="number" id="belanja" class="form-control form-control-sm text-end border-2" style="border-radius: 6px;" placeholder="0"></td>
                                <td class="text-muted small px-3">Input manual belanja</td>
                            </tr>
                            <tr>
                                <td class="px-3">Beban Operasional</td>
                                <td class="text-end fw-semibold text-secondary"><span id="bo_akumulasi">Rp 0</span></td>
                                <td class="text-muted small px-3 text-info">Sinkron tabel BO diatas</td>
                            </tr>
                            <tr>
                                <td class="px-3">Modal Awal</td>
                                <td class="text-end px-2"><input type="number" id="modal_awal" class="form-control form-control-sm text-end border-2" style="border-radius: 6px;" placeholder="0"></td>
                                <td class="text-muted small px-3">Penyesuaian kas awal</td>
                            </tr>
                            <tr>
                                <td class="px-3">Admin Fee</td>
                                <td class="text-end fw-semibold text-danger"><span id="admin_akumulasi">Rp <?= number_format($share_admin,0,',','.') ?></span></td>
                                <td class="text-muted small px-3">3% dari bagian Pengelola 50%</td> <!-- UBAH KETERANGAN -->
                            </tr>
                            <tr>
                                <td class="px-3">Bagian Pengelola Bersih</td> <!-- BARU -->
                                <td class="text-end fw-semibold text-dark"><span id="pengelola_bersih">Rp <?= number_format($share_pengelola_bersih,0,',','.') ?></span></td> <!-- BARU -->
                                <td class="text-muted small px-3">50% - Admin Fee 3%</td> <!-- BARU -->
                            </tr>
                            <tr class="table-success border-top border-2" style="background-color: #f0fdf4 !important;">
                                <td class="px-3 fw-bold text-success">Total Laba Bersih</td> <!-- UBAH NAMA -->
                                <td class="text-end fw-bold text-success">Rp <?= number_format($laba_bersih,0,',','.') ?></td>
                                <td class="text-success small fw-semibold px-3">Sebelum dibagi</td> <!-- UBAH KETERANGAN -->
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- 4. Revenue Sharing Reference -->
        <div class="col-lg-6">
            <div class="card border-0 h-100" style="overflow: hidden;">
                <div class="card-header bg-light border-bottom py-3">
                    <span class="fw-bold text-dark"><i class="bi bi-share me-2 text-muted"></i>4. Kontrak Pembagian Hasil (Revenue Sharing)</span>
                </div>
                <div class="card-body p-0">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr class="table-light">
                                <th class="px-3">Entitas Mitra</th>
                                <th class="text-center" width="20%">Porsi Rasio</th>
                                <th class="text-end px-3" width="35%">Nilai Estimasi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="px-3 fw-medium text-dark"><i class="bi bi-person text-primary me-2"></i>Investor Utama</td>
                                <td class="text-center"><span class="badge bg-primary-subtle text-primary px-2.5 py-1.5 fw-bold" style="font-size: 0.8rem;"><?= $persen_investor ?>%</span></td>
                                <td class="text-end fw-bold text-primary px-3">Rp <?= number_format($share_investor,0,',','.') ?></td>
                            </tr>
                            <tr>
                                <td class="px-3 fw-medium text-dark"><i class="bi bi-person-gear text-success me-2"></i>Pengelola Lapangan</td>
                                <td class="text-center"><span class="badge bg-success-subtle text-success px-2.5 py-1.5 fw-bold" style="font-size: 0.8rem;"><?= $persen_pengelola ?>%</span></td>
                                <td class="text-end fw-bold text-success px-3" id="pgl_share_kotor">Rp <?= number_format($share_pengelola,0,',','.') ?></td> <!-- KASIH ID -->
                            </tr>
                            <tr>
                                <td class="px-3 fw-medium text-dark"><i class="bi bi-shield-check text-danger me-2"></i>Management Pusat</td>
                                <td class="text-center"><span class="badge bg-danger-subtle text-danger px-2.5 py-1.5 fw-bold" style="font-size: 0.8rem;"><?= $persen_admin ?>%</span></td>
                                <td class="text-end fw-bold text-danger px-3">Rp <?= number_format($share_admin,0,',','.') ?></td>
                            </tr>
                            <tr class="table-success"> <!-- BARIS BARU -->
                                <td class="px-3 fw-bold text-dark">Pengelola Bersih</td>
                                <td class="text-center"><span class="badge bg-dark-subtle text-dark px-2.5 py-1.5 fw-bold" style="font-size: 0.8rem;">47%</span></td> <!-- 50% - 3% -->
                                <td class="text-end fw-bold text-dark px-3" id="pgl_share_bersih">Rp <?= number_format($share_pengelola_bersih,0,',','.') ?></td>
                            </tr>
                        </tbody>
                    </table>
                    <div class="p-3 bg-light text-muted border-top" style="font-size: 0.8rem; line-height: 1.4;">
                        <i class="bi bi-info-circle me-1 text-primary"></i> Admin Fee 3% dipotong dari bagian Pengelola. Investor tetap 50%.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 5. Profit Investor & Pengelola Manual Panel -->
    <div class="row g-4 mb-4">
        <!-- Investor Form Card -->
        <div class="col-lg-6">
            <div class="card border-0 border-top border-3 border-primary">
                <div class="card-header bg-white border-bottom py-3">
                    <span class="fw-bold text-primary"><i class="bi bi-pencil-square me-2"></i>Koreksi Dividen: Sisi Investor</span>
                </div>
                <div class="card-body p-4">
                    <div class="row g-3">
                        <div class="col-sm-6">
                            <label class="form-label text-muted small fw-semibold">Profit Dasar (Share)</label>
                            <input type="number" id="inv_profit" class="form-control border-2" style="border-radius: 6px;" value="<?= $share_investor ?>" oninput="hitungInvestor()">
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label text-muted small fw-semibold">Potongan Sewa Ruko</label>
                            <input type="number" id="inv_sewa" class="form-control border-2" style="border-radius: 6px;" value="0" oninput="hitungInvestor()">
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label text-muted small fw-semibold">Kembalian Modal</label>
                            <input type="number" id="inv_modal" class="form-control border-2" style="border-radius: 6px;" value="0" oninput="hitungInvestor()">
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label text-muted small fw-semibold">Kasbon Pengelola</label>
                            <input type="number" id="inv_kasbon" class="form-control border-2" style="border-radius: 6px;" value="0" oninput="hitungInvestor()">
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label text-muted small fw-semibold">Potongan Admin</label>
                            <input type="number" id="inv_admin" class="form-control border-2" style="border-radius: 6px;" value="0" oninput="hitungInvestor()">
                        </div>
                    </div>
                    <div class="d-flex justify-content-between align-items-center bg-primary bg-opacity-10 p-3 rounded-3 mt-4 border-primary border-opacity-10">
                        <span class="fw-bold text-primary small">TOTAL BERSIH INVESTOR:</span>
                        <h4 class="fw-bold text-primary mb-0" id="inv_total">Rp 0</h4>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pengelola Form Card -->
        <div class="col-lg-6">
            <div class="card border-0 border-top border-3 border-success">
                <div class="card-header bg-white border-bottom py-3">
                    <span class="fw-bold text-success"><i class="bi bi-pencil-square me-2"></i>Koreksi Dividen: Sisi Pengelola</span>
                </div>
                <div class="card-body p-4">
                    <div class="row g-3 mb-3">
                        <div class="col-sm-6">
                            <label class="form-label text-muted small fw-semibold">Profit Pengelola</label>
                            <input type="number" id="pgl_profit" class="form-control form-control-sm border-2" style="border-radius: 6px;" value="<?= $share_pengelola_bersih ?>" oninput="hitungPengelola()"> <!-- UBAH VALUE -->
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label text-muted small fw-semibold">Admin Fee Cabang</label>
                            <input type="number" id="pgl_admin" class="form-control form-control-sm border-2" style="border-radius: 6px;" value="0" oninput="hitungPengelola()"> <!-- UBAH VALUE JADI 0 -->
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label text-muted small fw-semibold">Beban Kertas Nasi</label>
                            <input type="number" id="pgl_kertas" class="form-control form-control-sm border-2" style="border-radius: 6px;" value="0" oninput="hitungPengelola()">
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label text-muted small fw-semibold">Service Fee + BPJS</label>
                            <input type="number" id="pgl_service" class="form-control form-control-sm border-2" style="border-radius: 6px;" value="0" oninput="hitungPengelola()">
                        </div>
                    </div>
                    
                    <div class="table-responsive bg-light p-2 rounded-3 border mb-0" style="overflow: hidden;">
                        <table class="table table-sm table-borderless align-middle mb-0 text-center text-nowrap" style="font-size: 0.8rem;">
                            <thead>
                                <tr class="text-secondary border-bottom">
                                    <th class="pb-1 fw-semibold">Net Profit</th>
                                    <th class="pb-1 fw-semibold">Net Admin</th>
                                    <th class="pb-1 fw-semibold">Net Kertas</th>
                                    <th class="pb-1 fw-semibold">Net Service</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr class="fw-bold text-dark pt-2">
                                    <td><span id="pgl_total_profit" class="text-success">Rp 0</span></td>
                                    <td><span id="pgl_total_admin">Rp 0</span></td>
                                    <td><span id="pgl_total_kertas">Rp 0</span></td>
                                    <td><span id="pgl_total_service">Rp 0</span></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 6. Rekapan Hasil Keseluruhan Keuntungan Final -->
    <div class="card border-0 mb-5" style="overflow: hidden;">
        <div class="card-header bg-danger text-white py-3 d-flex align-items-center justify-content-between" style="background-color: #ef4444 !important;">
            <span class="fw-bold"><i class="bi bi-wallet2 me-2"></i>6. Rekapan Hasil Akhir Keuntungan (Distribusi Payroll)</span>
            <span class="badge bg-white text-danger fw-bold px-3 py-1.5 rounded-pill shadow-sm" style="font-size: 0.75rem;"><i class="bi bi-check2-circle me-1"></i>Validasi Siap Transfer</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 text-nowrap">
                    <thead>
                        <tr class="table-light">
                            <th class="py-3 px-4">Nama Penerima</th>
                            <th class="py-3">Jabatan Hak</th>
                            <th class="py-3">Nomor Rekening</th>
                            <th class="py-3">Atas Nama Rekening</th>
                            <th class="py-3">Nama Bank</th>
                            <th class="py-3 text-end px-4" width="20%">Total Net Diterima</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="px-4 fw-semibold text-dark"><?= h($cabang_info['investor'] ?: '-') ?></td>
                            <td><span class="badge bg-primary-subtle text-primary px-2.5 py-1">Investor Cabang</span></td>
                            <td class="font-monospace text-secondary" style="font-size: 0.85rem; letter-spacing: 0.5px;"><?= h($cabang_info['no_rekening'] ?: '-') ?></td>
                            <td><?= h($cabang_info['investor'] ?: '-') ?></td>
                            <td><span class="badge bg-light text-dark border px-2 py-1 fw-medium"><?= h($cabang_info['nama_bank'] ?: '-') ?></span></td>
                            <td class="text-end px-4 fw-bold text-primary"><span id="final_inv" class="fs-6">Rp 0</span></td>
                        </tr>
                        <tr>
                            <td class="px-4 fw-semibold text-dark"><?= h($pengelola['nama_pengelola'] ?: '-') ?></td>
                            <td><span class="badge bg-success-subtle text-success px-2.5 py-1">Pengelola Lapangan</span></td>
                            <td class="font-monospace text-secondary" style="font-size: 0.85rem; letter-spacing: 0.5px;"><?= h($pengelola['no_rekening'] ?: '-') ?></td>
                            <td><?= h($pengelola['nama_pengelola'] ?: '-') ?></td>
                            <td><span class="badge bg-light text-dark border px-2 py-1 fw-medium"><?= h($pengelola['nama_bank'] ?: '-') ?></span></td>
                            <td class="text-end px-4 fw-bold text-success"><span id="final_pgl" class="fs-6">Rp 0</span></td>
                        </tr>
                        <tr class="table-light">
                            <td class="px-4 fw-medium text-dark">Admin Management Pusat</td>
                            <td><span class="badge bg-danger-subtle text-danger px-2.5 py-1">Internal Admin</span></td>
                            <td class="text-muted small">-</td>
                            <td>Kas Utama Pusat WBB</td>
                            <td class="text-muted small">-</td>
                            <td class="text-end px-4 fw-bold text-danger">Rp <?= number_format($share_admin,0,',','.') ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php endif; ?>
</div>

<!-- Tombol Export -->
<?php if(isset($data_harian) && $data_harian instanceof mysqli_result && mysqli_num_rows($data_harian) > 0): ?>
<div class="row g-3 mt-4 mb-5">
    <div class="col-md-6">
        <button onclick="exportExcel()" class="btn btn-success w-100 py-3 fw-semibold" style="border-radius: 10px; font-size: 1rem;">
            <i class="bi bi-file-earmark-excel me-2"></i>Export Excel
        </button>
    </div>
    <div class="col-md-6">
        <button onclick="exportPDF()" class="btn btn-danger w-100 py-3 fw-semibold" style="border-radius: 10px; font-size: 1rem;">
            <i class="bi bi-file-earmark-pdf me-2"></i>Export PDF
        </button>
    </div>
</div>
<?php endif; ?>

<!-- Library Export -->
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jspdf-autotable@3.8.3/dist/jspdf.plugin.autotable.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>

<script>
function formatRupiah(angka){
    return 'Rp ' + parseInt(angka || 0).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ".");
}

// Script untuk datalist cabang -> hidden ID
const inputCabang = document.getElementById('inputCabang');
const idCabang = document.getElementById('idCabang');
const datalist = document.getElementById('listCabang');

if(inputCabang){
    inputCabang.addEventListener('input', function(){
        let val = this.value;
        let options = datalist.querySelectorAll('option');
        let found = false;
        
        options.forEach(opt => {
            if(opt.value === val){
                idCabang.value = opt.getAttribute('data-id');
                found = true;
            }
        });
        
        if(!found && val !== ''){
            idCabang.value = '';
        }
    });
}

const formFilter = document.getElementById('formFilter');
if(formFilter){
    formFilter.addEventListener('submit', function(e){
        if(idCabang.value === ''){
            e.preventDefault();
            alert('Pilih cabang dari daftar, jangan ketik manual yang tidak ada di list!');
            inputCabang.focus();
        }
    });
}

// Hitung Beban Operasional
function hitungBO(){
    let totalBO = 0;
    const labaBersih = <?= $laba_bersih ?>;
    const persenAdmin = <?= $persen_admin ?>;

    document.querySelectorAll('.jumlah').forEach(function(el){
        let no = el.dataset.no;
        let harian = parseFloat(document.querySelector('.harian[data-no="'+no+'"]')?.value) || 0; // TAMBAH HARIAN
        let bulanan = parseFloat(document.querySelector('.bulanan[data-no="'+no+'"]')?.value) || 0;
        let tahunan = parseFloat(document.querySelector('.tahunan[data-no="'+no+'"]')?.value) || 0;
        let dibayarkan = parseFloat(document.querySelector('.dibayarkan[data-no="'+no+'"]')?.value) || 0; // TAMBAH DIBAYARKAN
        let jumlah = harian + bulanan + tahunan - dibayarkan; // FIX RUMUS
        el.innerText = formatRupiah(jumlah);
        totalBO += jumlah;
    });
    if(document.getElementById('total_bo')) document.getElementById('total_bo').innerText = formatRupiah(totalBO);
    if(document.getElementById('bo_akumulasi')) document.getElementById('bo_akumulasi').innerText = formatRupiah(totalBO);

    // Update Admin & Pengelola Bersih live
    const sharePengelola = (labaBersih * 50) / 100;
    const adminFee = (sharePengelola * persenAdmin) / 100;
    const pengelolaBersih = sharePengelola - adminFee;

    if(document.getElementById('admin_akumulasi')) document.getElementById('admin_akumulasi').innerText = formatRupiah(adminFee);
    if(document.getElementById('pengelola_bersih')) document.getElementById('pengelola_bersih').innerText = formatRupiah(pengelolaBersih);
    if(document.getElementById('pgl_share_bersih')) document.getElementById('pgl_share_bersih').innerText = formatRupiah(pengelolaBersih);
    if(document.getElementById('pgl_profit')) document.getElementById('pgl_profit').value = pengelolaBersih; // Sync ke input
}

function hitungInvestor(){
    let profit = parseFloat(document.getElementById('inv_profit')?.value) || 0;
    let sewa = parseFloat(document.getElementById('inv_sewa')?.value) || 0;
    let modal = parseFloat(document.getElementById('inv_modal')?.value) || 0;
    let kasbon = parseFloat(document.getElementById('inv_kasbon')?.value) || 0;
    let admin = parseFloat(document.getElementById('inv_admin')?.value) || 0;
    let total = profit - sewa - modal - kasbon - admin;
    if(document.getElementById('inv_total')) document.getElementById('inv_total').innerText = formatRupiah(total);
    if(document.getElementById('final_inv')) document.getElementById('final_inv').innerText = formatRupiah(total);
}

function hitungPengelola(){
    let profit = parseFloat(document.getElementById('pgl_profit')?.value) || 0; // Ini udah bersih
    let admin = parseFloat(document.getElementById('pgl_admin')?.value) || 0; // 0
    let kertas = parseFloat(document.getElementById('pgl_kertas')?.value) || 0;
    let service = parseFloat(document.getElementById('pgl_service')?.value) || 0;
    
    if(document.getElementById('pgl_total_profit')) document.getElementById('pgl_total_profit').innerText = formatRupiah(profit);
    if(document.getElementById('pgl_total_admin')) document.getElementById('pgl_total_admin').innerText = formatRupiah(admin);
    if(document.getElementById('pgl_total_kertas')) document.getElementById('pgl_total_kertas').innerText = formatRupiah(kertas);
    if(document.getElementById('pgl_total_service')) document.getElementById('pgl_total_service').innerText = formatRupiah(service);
    
    let total_bersih = profit - admin - kertas - service;
    if(document.getElementById('final_pgl')) document.getElementById('final_pgl').innerText = formatRupiah(total_bersih);
}

window.onload = function(){
    hitungBO();
    hitungInvestor();
    hitungPengelola();
}

// Export Excel - 6 sheet sesuai 6 section
async function exportExcel() {
    const wb = XLSX.utils.book_new();
    const ws = {};
    let currentRow = 1;

    // Ambil data dari PHP biar sinkron
    const labaBersih = <?= $laba_bersih?>;
    const persenAdmin = <?= $persen_admin?>; // 3
    const sharePengelolaKotor = (labaBersih * 50) / 100;
    const shareAdmin = (sharePengelolaKotor * persenAdmin) / 100; // 3% dari pengelola
    const sharePengelolaBersih = sharePengelolaKotor - shareAdmin; // 47%

    // Fungsi pembantu untuk mengisi data ke Cell secara spesifik
    function setCell(colStr, rowNum, value, isFormula = false, customStyle = {}) {
        const cellRef = colStr + rowNum;
        if (isFormula) {
            ws[cellRef] = { t: 'n', f: value };
        } else {
            let cleanValue = typeof value === 'string'? value.replace('Rp ', '').replace(/\./g, '').trim() : value;
            if (cleanValue!== '' &&!isNaN(cleanValue)) {
                ws[cellRef] = { t: 'n', v: Number(cleanValue) };
            } else {
                ws[cellRef] = { t: 's', v: value };
            }
        }
    }

    // =========================================================================
    // 1. BAGIAN KOP SURAT (Dibuat Rapih & Sejajar Seperti Laporan Perusahaan)
    // =========================================================================
    setCell('A', 1, 'WARTEG BUMI BAHARI');
    setCell('A', 2, <?= json_encode($alamat_cabang?? "Kantor Pusat : Kecamatan Pamulang Kota Tangerang Selatan | BANTEN 15417")?>);
    setCell('A', 3, 'Phone : <?= h($no_hp_cabang?? "+62 858 1111 2222")?>');

    setCell('F', 1, 'Cabang'); setCell('G', 1, ': <?= h($nama_cabang?? "WBB Cabang")?>');
    setCell('F', 2, 'Periode'); setCell('G', 2, ': <?= date("F Y", strtotime("$tahun-$bulan-01"))?>');
    setCell('F', 3, 'Pengelola'); setCell('G', 3, ': <?= h($pengelola['nama_pengelola']?? "-")?>'); // FIX ambil dari PHP
    setCell('F', 4, 'Investor'); setCell('G', 4, ': <?= h($cabang_info['investor']?? "-")?>'); // FIX ambil dari PHP

    currentRow = 6;

    // =========================================================================
    // TABEL 1: Rekapitulasi Pendapatan & Pengeluaran Harian
    // =========================================================================
    setCell('A', currentRow, '1. Rekapitulasi Pendapatan & Pengeluaran Harian');
    currentRow++;

    let elTabel1 = document.getElementById('tabelRekapHarian');
    if (elTabel1) {
        const tempWs = XLSX.utils.table_to_sheet(elTabel1, { raw: true });
        Object.keys(tempWs).forEach(cell => {
            if (!cell.startsWith('!')) {
                const col = cell.match(/[A-Z]+/)[0];
                const row = parseInt(cell.match(/\d+/)[0]);
                ws[col + (currentRow + row - 1)] = tempWs[cell];
            }
        });
        const range = XLSX.utils.decode_range(tempWs['!ref'] || 'A1:A1');
        currentRow += (range.e.r - range.s.r) + 3;
    } else {
        currentRow += 2;
    }

    // =========================================================================
    // TABEL 2: Rincian Beban Operasional
    // =========================================================================
    setCell('A', currentRow, '2. Rincian Beban Operasional');
    currentRow++;

    let t2 = document.querySelector('.table-clean-input');
    let elTabel2 = t2?.tagName === 'TABLE'? t2 : t2?.querySelector('table');
    if (elTabel2) {
        const tempWs = XLSX.utils.table_to_sheet(elTabel2, { raw: true });
        Object.keys(tempWs).forEach(cell => {
            if (!cell.startsWith('!')) {
                const col = cell.match(/[A-Z]+/)[0];
                const row = parseInt(cell.match(/\d+/)[0]);
                ws[col + (currentRow + row - 1)] = tempWs[cell];
            }
        });
        const range = XLSX.utils.decode_range(tempWs['!ref'] || 'A1:A1');
        currentRow += (range.e.r - range.s.r) + 3;
    } else {
        currentRow += 2;
    }

    // =========================================================================
    // TABEL 3: Matriks Akumulasi - FIX Admin 3% dari Pengelola
    // =========================================================================
    setCell('A', currentRow, '3. Matriks Akumulasi');
    currentRow++;

    // Bikin manual biar nilainya pasti bener 47% & 3%
    setCell('A', currentRow, 'Komponen Pokok'); setCell('B', currentRow, 'Jumlah'); setCell('C', currentRow, 'Catatan Ringkas'); currentRow++;
    setCell('A', currentRow, 'Omzet Penjualan'); setCell('B', currentRow, <?= $penjualan?>); setCell('C', currentRow, 'Pendapatan bruto masuk'); currentRow++;
    setCell('A', currentRow, 'Pengeluaran Belanja'); setCell('B', currentRow, document.getElementById('belanja')?.value || 0); setCell('C', currentRow, 'Input manual belanja'); currentRow++;
    setCell('A', currentRow, 'Beban Operasional'); setCell('B', currentRow, document.getElementById('bo_akumulasi')?.innerText.replace('Rp ','').replace(/\./g,'') || 0); setCell('C', currentRow, 'Sinkron tabel BO diatas'); currentRow++;
    setCell('A', currentRow, 'Modal Awal'); setCell('B', currentRow, document.getElementById('modal_awal')?.value || 0); setCell('C', currentRow, 'Penyesuaian kas awal'); currentRow++;
    setCell('A', currentRow, 'Admin Fee'); setCell('B', currentRow, shareAdmin); setCell('C', currentRow, '3% dari bagian Pengelola 50%'); currentRow++; // FIX
    setCell('A', currentRow, 'Bagian Pengelola Bersih'); setCell('B', currentRow, sharePengelolaBersih); setCell('C', currentRow, '50% - Admin Fee 3%'); currentRow++; // TAMBAH
    setCell('A', currentRow, 'Bagian Investor'); setCell('B', currentRow, <?= $share_investor?>); setCell('C', currentRow, '50% Laba Bersih'); currentRow++; // TAMBAH
    setCell('A', currentRow, 'Total Laba Bersih'); setCell('B', currentRow, labaBersih); setCell('C', currentRow, 'Sebelum dibagi'); currentRow++; // UBAH

    currentRow += 2;

    // =========================================================================
    // TABEL 4: Koreksi Dividen - Sisi Investor (DENGAN RUMUS DINAMIS)
    // =========================================================================
    setCell('A', currentRow, '4. Koreksi Dividen: Sisi Investor'); currentRow++;
    setCell('A', currentRow, 'Keterangan Komponen'); setCell('B', currentRow, 'Nilai');

    let startRowInv = currentRow + 1;
    setCell('A', currentRow + 1, 'Profit Dasar Share'); setCell('B', currentRow + 1, document.getElementById('inv_profit')?.value || <?= $share_investor?>);
    setCell('A', currentRow + 2, 'Potongan Sewa Ruko'); setCell('B', currentRow + 2, document.getElementById('inv_sewa')?.value || 0);
    setCell('A', currentRow + 3, 'Kembalian Modal'); setCell('B', currentRow + 3, document.getElementById('inv_modal')?.value || 0);
    setCell('A', currentRow + 4, 'Kasbon Pengelola'); setCell('B', currentRow + 4, document.getElementById('inv_kasbon')?.value || 0);
    setCell('A', currentRow + 5, 'Potongan Admin'); setCell('B', currentRow + 5, document.getElementById('inv_admin')?.value || 0);

    let totalInvRow = currentRow + 6;
    setCell('A', totalInvRow, 'TOTAL BERSIH INVESTOR');
    setCell('B', totalInvRow, `=B${startRowInv}-SUM(B${startRowInv+1}:B${startRowInv+5})`, true);

    currentRow = totalInvRow + 3;

    // =========================================================================
    // TABEL 5: Koreksi Dividen - Sisi Pengelola (DENGAN RUMUS DINAMIS) - FIX
    // =========================================================================
    setCell('A', currentRow, '5. Koreksi Dividen: Sisi Pengelola'); currentRow++;
    setCell('A', currentRow, 'Keterangan Komponen'); setCell('B', currentRow, 'Nilai');

    let startRowPgl = currentRow + 1;
    setCell('A', currentRow + 1, 'Profit Pengelola'); setCell('B', currentRow + 1, sharePengelolaBersih); // FIX: Langsung bersih 47%
    setCell('A', currentRow + 2, 'Admin Fee Cabang'); setCell('B', currentRow + 2, 0); // FIX: 0 karena udah dipotong di atas
    setCell('A', currentRow + 3, 'Beban Kertas Nasi'); setCell('B', currentRow + 3, document.getElementById('pgl_kertas')?.value || 0);
    setCell('A', currentRow + 4, 'Service Fee + BPJS'); setCell('B', currentRow + 4, document.getElementById('pgl_service')?.value || 0);

    let totalPglRow = currentRow + 5;
    setCell('A', totalPglRow, 'TOTAL BERSIH PENGELOLA');
    setCell('B', totalPglRow, `=B${startRowPgl}-SUM(B${startRowPgl+1}:B${startRowPgl+4})`, true);

    currentRow = totalPglRow + 3;

    // =========================================================================
    // TABEL 6: Rekapan Hasil Akhir Keuntungan (Distribusi Payroll)
    // =========================================================================
    setCell('A', currentRow, '6. Rekapan Hasil Akhir Keuntungan (Distribusi Payroll)');
    currentRow++;

    let wrapperPayroll = document.querySelector('.card.border-0.mb-5') || document.querySelector('.card.border-0.mb-5.table');
    let elTabel6 = wrapperPayroll?.tagName === 'TABLE'? wrapperPayroll : wrapperPayroll?.querySelector('table');

    if (elTabel6) {
        const tempWs = XLSX.utils.table_to_sheet(elTabel6, { raw: true });
        Object.keys(tempWs).forEach(cell => {
            if (!cell.startsWith('!')) {
                const col = cell.match(/[A-Z]+/)[0];
                const row = parseInt(cell.match(/\d+/)[0]);
                ws[col + (currentRow + row - 1)] = tempWs[cell];
            }
        });
        const range = XLSX.utils.decode_range(tempWs['!ref'] || 'A1:A1');
        currentRow += (range.e.r - range.s.r) + 1;
    }

    // =========================================================================
    // KONFIGURASI LAYOUT AKHIR SPREADSHEET
    // =========================================================================
    ws['!ref'] = `A1:G${currentRow}`;

    ws['!cols'] = [
        { wch: 35 }, { wch: 18 }, { wch: 18 },
        { wch: 22 }, { wch: 15 }, { wch: 12 }, { wch: 32 }
    ];

    XLSX.utils.book_append_sheet(wb, ws, "Rekapitulasi");
    XLSX.writeFile(wb, "<?= h($nama_file_export)?>.xlsx");
}

async function exportPDF(){
    const { jsPDF } = window.jspdf;
    let doc = new jsPDF('landscape', 'mm', 'a4');

    const margin = 14;
    const baseTableStyles = {
        theme: 'grid',
        styles: { fontSize: 8, cellPadding: 2 },
        headStyles: { fillColor: [52, 58, 64], textColor: 255, halign: 'center' }
    };

    // FIX: Fungsi parse biar angka 'Rp. 25.330.000' jadi 25330000 beneran
    function parseAngka(val){
        return parseFloat(String(val || 0).replace(/[^0-9.-]+/g,"")) || 0;
    }
    // FIX: Format Rp pake hasil parse biar gak jadi 25,33
    function formatRupiahPDF(angka){
        return 'Rp. ' + parseAngka(angka).toLocaleString('id-ID');
    }

    const labaBersih = <?= $laba_bersih?>;
    const persenAdmin = <?= $persen_admin?>;
    const sharePengelolaKotor = (labaBersih * 50) / 100;
    const shareAdmin = (sharePengelolaKotor * persenAdmin) / 100;
    const sharePengelolaBersih = sharePengelolaKotor - shareAdmin;

    const addWatermark = (pdfDoc) => {
        let imgLogo = document.getElementById('logoWWB');
        if (imgLogo) {
            try {
                const pageWidth = 297;
                const pageHeight = 210;
                const wmSize = 80;
                const wmX = (pageWidth - wmSize) / 2;
                const wmY = (pageHeight - wmSize) / 2;
                pdfDoc.saveGraphicsState();
                pdfDoc.setGState(new pdfDoc.GState({ opacity: 0.08 }));
                pdfDoc.addImage(imgLogo, 'PNG', wmX, wmY, wmSize, wmSize);
                pdfDoc.restoreGraphicsState();
            } catch (err) {
                console.warn("Gagal memuat watermark pada halaman ini:", err);
            }
        }
    };

    // =========================================================================
    // HALAMAN 1: REKAPITULASI + KOP MELEBAR + LOGO & DATA SEJAJAR
    // =========================================================================
    addWatermark(doc);
    let startYContent = 12;
    let textXPosition = margin;

    let imgLogo = document.getElementById('logoWWB');
    let logoHeight = 16;
    let logoWidth = 16;
    if (imgLogo) {
        try {
            let originalWidth = imgLogo.naturalWidth || 1;
            let originalHeight = imgLogo.naturalHeight || 1;
            let ratio = originalWidth / originalHeight;
            logoWidth = logoHeight * ratio;
            doc.addImage(imgLogo, 'PNG', margin, startYContent, logoWidth, logoHeight);
            textXPosition = margin + logoWidth + 5;
        } catch (e) {
            textXPosition = margin;
        }
    }

    let textYStart = imgLogo? startYContent + 4.5 : startYContent;
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(14);
    doc.setTextColor(40, 40, 40);
    doc.text('WARTEG BUMI BAHARI', textXPosition, textYStart);

    doc.setFont('helvetica', 'normal');
    doc.setFontSize(8.5);
    doc.setTextColor(100, 100, 100);
    doc.text(<?= json_encode($alamat_cabang?? "Kantor Pusat : Kecamatan Pamulang Kota Tangerang Selatan | BANTEN 15417")?>, textXPosition, textYStart + 5);
    doc.text('Phone : <?= h($no_hp_cabang?? "+62 858 1111 2222")?>', textXPosition, textYStart + 9);

    let infoData = [
        ['Cabang', ': <?= h($nama_cabang?? "WBB Cabang")?>'],
        ['Periode', ': <?= date("F Y", strtotime("$tahun-$bulan-01"))?>'],
        ['Pengelola', ': <?= h($pengelola['nama_pengelola']?? "-")?>'],
        ['Investor', ': <?= h($cabang_info['investor']?? "-")?>']
    ];

    doc.autoTable({
        body: infoData,
        startY: imgLogo? startYContent + 1.5 : startYContent - 3,
        theme: 'plain',
        styles: { fontSize: 8.5, cellPadding: 0.3, fontStyle: 'bold', textColor: [40, 40, 40] },
        columnStyles: { 0: { cellWidth: 20 }, 1: { cellWidth: 80 } },
        margin: { left: 190 }
    });

    let yLine = startYContent + 20;
    doc.setDrawColor(200, 200, 200);
    doc.setLineWidth(0.4);
    doc.line(margin, yLine, 283, yLine);

    let yTabelHarian = yLine + 7;
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(11);
    doc.setTextColor(0, 0, 0);
    doc.text('1. Rekapitulasi Pendapatan & Pengeluaran Harian - <?= date("F Y", strtotime("$tahun-$bulan-01"))?>', margin, yTabelHarian);

    doc.autoTable({
        html: '#tabelRekapHarian',
        startY: yTabelHarian + 4,
       ...baseTableStyles,
        styles: { fontSize: 7, cellPadding: 1.2 }
    });

    // =========================================================================
    // HALAMAN 2: Rincian Beban Operasional & Matriks Akumulasi
    // =========================================================================
    doc.addPage();
    addWatermark(doc);
    let y = 15;

    doc.setFontSize(12);
    doc.setFont('helvetica', 'bold');
    doc.text('2. Rincian Beban Operasional', margin, y);

    let t2 = document.querySelector('.table-clean-input');
    let elTabel2 = t2?.tagName === 'TABLE'? t2 : t2?.querySelector('table');
    if (elTabel2) {
        doc.autoTable({
            html: elTabel2,
            startY: y + 5,
           ...baseTableStyles
        });
        y = doc.lastAutoTable.finalY + 12;
    } else {
        y += 15;
    }

    doc.setFontSize(12);
    doc.setFont('helvetica', 'bold');
    doc.text('3. Matriks Akumulasi', margin, y);

    let dataMatriks = [
        ['Omzet Penjualan', formatRupiahPDF(<?= $penjualan?>), 'Pendapatan bruto masuk'],
        ['Pengeluaran Belanja', formatRupiahPDF(document.getElementById('belanja')?.value || 0), 'Input manual belanja'],
        ['Beban Operasional', formatRupiahPDF(document.getElementById('bo_akumulasi')?.innerText || 0), 'Sinkron tabel BO diatas'],
        ['Modal Awal', formatRupiahPDF(document.getElementById('modal_awal')?.value || 0), 'Penyesuaian kas awal'],
        ['Admin Fee', formatRupiahPDF(shareAdmin), '3% dari bagian Pengelola 50%'],
        ['Bagian Pengelola Bersih', formatRupiahPDF(sharePengelolaBersih), '50% - Admin Fee 3%'],
        ['Bagian Investor', formatRupiahPDF(<?= $share_investor?>), '50% Laba Bersih'],
        ['Total Laba Bersih', formatRupiahPDF(labaBersih), 'Sebelum dibagi'],
    ];

    doc.autoTable({
        head: [['Komponen Pokok', 'Jumlah', 'Catatan Ringkas']],
        body: dataMatriks,
        startY: y + 5,
       ...baseTableStyles
    });

    // =========================================================================
    // HALAMAN 3: Koreksi Investor, Koreksi Pengelola, & Payroll Final
    // =========================================================================
    doc.addPage();
    addWatermark(doc);
    y = 15;

    doc.setFontSize(12);
    doc.setFont('helvetica', 'bold');
    doc.text('4. Koreksi Dividen: Sisi Investor', margin, y);

    let dataInvestor = [
        ['Profit Dasar Share', formatRupiahPDF(document.getElementById('inv_profit')?.value || <?= $share_investor?>)],
        ['Potongan Sewa Ruko', formatRupiahPDF(document.getElementById('inv_sewa')?.value || 0)],
        ['Kembalian Modal', formatRupiahPDF(document.getElementById('inv_modal')?.value || 0)],
        ['Kasbon Pengelola', formatRupiahPDF(document.getElementById('inv_kasbon')?.value || 0)],
        ['Potongan Admin', formatRupiahPDF(document.getElementById('inv_admin')?.value || 0)],
        ['TOTAL BERSIH INVESTOR', formatRupiahPDF(document.getElementById('inv_total')?.innerText || 0)], // FIX: pake formatRupiahPDF
    ];

    doc.autoTable({
        head: [['Keterangan Komponen', 'Nilai']],
        body: dataInvestor,
        startY: y + 5,
       ...baseTableStyles
    });

    y = doc.lastAutoTable.finalY + 12;

    doc.setFontSize(12);
    doc.setFont('helvetica', 'bold');
    doc.text('5. Koreksi Dividen: Sisi Pengelola', margin, y);

    let dataPengelola = [
        ['Profit Pengelola', formatRupiahPDF(sharePengelolaBersih)],
        ['Admin Fee Cabang', formatRupiahPDF(0)],
        ['Beban Kertas Nasi', formatRupiahPDF(document.getElementById('pgl_kertas')?.value || 0)],
        ['Service Fee + BPJS', formatRupiahPDF(document.getElementById('pgl_service')?.value || 0)],
        ['TOTAL BERSIH PENGELOLA', formatRupiahPDF(document.getElementById('final_pgl')?.innerText || sharePengelolaBersih)] // FIX: pake formatRupiahPDF
    ];

    doc.autoTable({
        head: [['Keterangan Komponen', 'Nilai']],
        body: dataPengelola,
        startY: y + 5,
       ...baseTableStyles
    });

    y = doc.lastAutoTable.finalY + 12;

    doc.setFontSize(12);
    doc.setFont('helvetica', 'bold');
    doc.text('6. Rekapan Hasil Akhir Keuntungan (Distribusi Payroll)', margin, y);

    let wrapperPayroll = document.querySelector('.card.border-0.mb-5') || document.querySelector('.card.border-0.mb-5.table');
    let elTabel6 = wrapperPayroll?.tagName === 'TABLE'? wrapperPayroll : wrapperPayroll?.querySelector('table');

    if (elTabel6) {
        doc.autoTable({
            html: elTabel6,
            startY: y + 5,
           ...baseTableStyles,
            columnStyles: { 5: { halign: 'right' } // Kolom ke-6 = Total Net Diterima
        }});
    }

    doc.save("<?= h($nama_file_export)?>.pdf");
}
</script>