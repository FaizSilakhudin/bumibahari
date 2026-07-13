<?php
require '../config/koneksi.php';

$tgl_awal = $_GET['tgl_awal'];
$tgl_akhir = $_GET['tgl_akhir'];
$id_cabang = $_GET['id_cabang'] ?? '';

$where = "WHERE l.tanggal BETWEEN '$tgl_awal' AND '$tgl_akhir'";
if($id_cabang != '') $where .= " AND l.id_cabang = $id_cabang";

$query = "SELECT l.*, c.nama_cabang FROM laporan_cabang l JOIN cabang c ON l.id_cabang=c.id_cabang $where ORDER BY l.tanggal";
$result = mysqli_query($conn, $query);

header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=Laporan_$tgl_awal-sd-$tgl_akhir.xls");

echo "No\tTanggal\tCabang\tPengelola\tOmzet\tPengeluaran\tLaba Bersih\tMargin\n";
$no=1;
while($d=mysqli_fetch_assoc($result)){
    $margin = $d['total_omset']>0 ? round(($d['net_profit']/$d['total_omset'])*100,2) : 0;
    echo $no++."\t";
    echo date('d/m/Y', strtotime($d['tanggal']))."\t";
    echo $d['nama_cabang']."\t";
    echo $d['nama_pengelola']."\t";
    echo $d['total_omset']."\t";
    echo $d['total_pengeluaran']."\t";
    echo $d['net_profit']."\t";
    echo $margin."%\n";
}