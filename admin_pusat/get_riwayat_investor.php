<?php
require '../config/koneksi.php';

// Bersihkan buffer supaya output benar-benar JSON murni.
if (ob_get_level()) {
    ob_clean();
}
header('Content-Type: application/json; charset=utf-8');

// 1. PROTEKSI AKSES ROLE PUSAT
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'pusat') {
    echo json_encode([
        'success' => false, 
        'message' => 'Akses ditolak. Anda tidak memiliki izin.'
    ]);
    exit;
}

// 2. VALIDASI INPUT ID INVESTOR
$id_investor = isset($_GET['id_investor']) ? (int)$_GET['id_investor'] : 0;

if ($id_investor <= 0) {
    echo json_encode([
        'success' => false, 
        'message' => 'ID Investor tidak valid.'
    ]);
    exit;
}

// 3. QUERY: daftar cabang yang diinvestasikan investor
//    beserta periode investasi + nama pengelola aktif tiap cabang
// 1 baris per cabang (gabung bila ada beberapa periode investasi di cabang yang sama).
$sql = "SELECT
            c.id_cabang,
            c.nama_cabang,
            COALESCE(
                (SELECT p.nama_pengelola
                   FROM pengelola p
                  WHERE p.id_cabang = c.id_cabang AND p.status = 'aktif'
                  ORDER BY p.tgl_mulai DESC
                  LIMIT 1),
                c.nama_pengelola
            ) AS nama_pengelola,
            MIN(ci.tgl_mulai) AS tgl_mulai,
            CASE WHEN SUM(ci.tgl_selesai IS NULL) > 0 THEN NULL ELSE MAX(ci.tgl_selesai) END AS tgl_selesai
        FROM cabang_investor ci
        JOIN cabang c ON ci.id_cabang = c.id_cabang
        WHERE ci.id_investor = ?
        GROUP BY c.id_cabang, c.nama_cabang, c.nama_pengelola
        ORDER BY (MAX(CASE WHEN ci.tgl_selesai IS NULL THEN 1 ELSE 0 END)) DESC, tgl_mulai DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id_investor);
$stmt->execute();
$result = $stmt->get_result();

$cabang_list = [];
$today = date('Y-m-d');

while ($row = $result->fetch_assoc()) {
    $tgl_mulai   = !empty($row['tgl_mulai']) ? $row['tgl_mulai'] : null;
    $tgl_selesai = !empty($row['tgl_selesai']) ? $row['tgl_selesai'] : null;

    // Tentukan status keaktifan investasi
    if (isset($row['status_relasi']) && !empty($row['status_relasi'])) {
        $status = ucfirst(strtolower($row['status_relasi']));
    } else {
        if (empty($tgl_selesai) || $tgl_selesai >= $today) {
            $status = 'Aktif';
        } else {
            $status = 'Selesai';
        }
    }

    // Format Tanggal Indonesia Sederhana (opsional untuk frontend)
    $tgl_mulai_fmt   = $tgl_mulai ? date('d-m-Y', strtotime($tgl_mulai)) : '-';
    $tgl_selesai_fmt = $tgl_selesai ? date('d-m-Y', strtotime($tgl_selesai)) : '-';

    $cabang_list[] = [
        'id_cabang'       => $row['id_cabang'] ?? null,
        'nama_cabang'     => $row['nama_cabang'] ?? ('Cabang #' . ($row['id_cabang'] ?? '-')),
        'nama_pengelola'  => !empty($row['nama_pengelola']) ? $row['nama_pengelola'] : '-',
        'tgl_mulai'       => $tgl_mulai,
        'tgl_selesai'     => $tgl_selesai,
        'tgl_mulai_fmt'   => $tgl_mulai_fmt,
        'tgl_selesai_fmt' => $tgl_selesai_fmt,
        'status'          => $status
    ];
}

$stmt->close();
$conn->close();

// 4. RESPONS KELUARAN JSON
echo json_encode([
    'success' => true,
    'data'    => $cabang_list
]);
exit;