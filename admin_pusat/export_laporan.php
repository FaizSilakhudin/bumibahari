<?php
require '../config/koneksi.php';
require_role('pusat');

// ==========================================================
// FILTER — sama persis dengan laporan.php, tanpa paginasi.
// ==========================================================
$tgl_awal  = $_GET['tgl_awal'] ?? date('Y-m-01');
$tgl_akhir = $_GET['tgl_akhir'] ?? date('Y-m-d');
$id_cabang = $_GET['id_cabang'] ?? '';

$where_sql = "WHERE l.tanggal BETWEEN ? AND ?";
$params = [$tgl_awal, $tgl_akhir];
$types = "ss";

if ($id_cabang !== '') {
    $where_sql .= " AND l.id_cabang = ?";
    $params[] = (int) $id_cabang;
    $types .= "i";
}

$query = "
    SELECT l.tanggal, c.nama_cabang, l.nama_pengelola, l.status_laporan,
           l.tunai, l.qris, l.grab_food, l.go_food, l.total_omset,
           l.belanja_pasar, l.belanja_sembako, l.belanja_beras, l.belanja_toko, l.total_rutin,
           l.sewa, l.gaji, l.listrik, l.air, l.sampah, l.keamanan, l.internet, l.gas,
           l.mingguan_karyawan, l.es_batu, l.bensin, l.lain_lain, l.total_operasional,
           l.total_pengeluaran, l.sisa_tunai, l.pencairan_qris, l.sisa_qris, l.net_profit, l.persentase
    FROM laporan_cabang l
    JOIN cabang c ON l.id_cabang = c.id_cabang
    $where_sql
    ORDER BY l.tanggal ASC, c.nama_cabang ASC
";
$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

$filename = 'laporan_' . $tgl_awal . '_sd_' . $tgl_akhir . '.csv';

while (ob_get_level()) {
    ob_end_clean();
}
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM supaya Excel baca UTF-8 dengan benar

fputcsv($out, [
    'Tanggal', 'Cabang', 'Pengelola', 'Status',
    'Tunai', 'QRIS', 'GrabFood', 'GoFood', 'Total Omset',
    'Belanja Pasar', 'Belanja Sembako', 'Belanja Beras', 'Belanja Toko', 'Total Belanja Rutin',
    'Sewa', 'Gaji', 'Listrik', 'Air', 'Sampah', 'Keamanan', 'Internet', 'Gas',
    'Mingguan Karyawan', 'Es Batu', 'Bensin', 'Lain-lain', 'Total Operasional',
    'Total Pengeluaran', 'Sisa Tunai', 'Pencairan QRIS', 'Sisa QRIS', 'Net Profit', 'Margin (%)',
]);

while ($row = $res->fetch_assoc()) {
    fputcsv($out, [
        $row['tanggal'], $row['nama_cabang'], $row['nama_pengelola'], $row['status_laporan'],
        $row['tunai'], $row['qris'], $row['grab_food'], $row['go_food'], $row['total_omset'],
        $row['belanja_pasar'], $row['belanja_sembako'], $row['belanja_beras'], $row['belanja_toko'], $row['total_rutin'],
        $row['sewa'], $row['gaji'], $row['listrik'], $row['air'], $row['sampah'], $row['keamanan'], $row['internet'], $row['gas'],
        $row['mingguan_karyawan'], $row['es_batu'], $row['bensin'], $row['lain_lain'], $row['total_operasional'],
        $row['total_pengeluaran'], $row['sisa_tunai'], $row['pencairan_qris'], $row['sisa_qris'], $row['net_profit'], $row['persentase'],
    ]);
}

fclose($out);
exit;
