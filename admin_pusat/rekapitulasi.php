<?php
require '../config/koneksi.php';
include 'sidebar_pusat.php';

// 1. PROTEKSI ROLE PUSAT
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'pusat') {
    header("Location:../login");
    exit;
}

$periode = $_GET['periode'] ?? 'bulanan';
$tahun = (int) ($_GET['tahun'] ?? date('Y'));
$bulan = (int) ($_GET['bulan'] ?? date('m'));
if ($tahun < 2000 || $tahun > 2100) $tahun = (int) date('Y');
if ($bulan < 1 || $bulan > 12)      $bulan = (int) date('m');
$bulan = str_pad((string) $bulan, 2, '0', STR_PAD_LEFT);
$id_cabang = $_GET['id_cabang'] ?? '';

// Rentang tanggal EFEKTIF untuk periode "bulanan" — default kalender biasa,
// tapi resolve_periode_bulanan() otomatis menggabungkan closing pertama kalau
// cabang ini baru mulai pembukuan pada/setelah tanggal 20 (sekali saja di
// awal, lihat config/koneksi.php). Bisa ditimpa manual lewat 2 input tanggal
// di form filter (tgl_mulai/tgl_selesai) — override menang atas resolusi
// otomatis, dan mematikan notice "periode kosong" karena user sudah eksplisit
// memilih rentangnya sendiri.
$tgl_mulai_efektif   = date("$tahun-$bulan-01");
$tgl_selesai_efektif = date('Y-m-t', strtotime($tgl_mulai_efektif));
$periode_kosong      = false;
$periode_digabung     = false;
$override_tanggal    = false;
$bulan_berikutnya_label = '';

if ($periode === 'bulanan' && $id_cabang !== '') {
    $resolusi            = resolve_periode_bulanan($conn, (int) $id_cabang, $tahun, (int) $bulan);
    $tgl_mulai_efektif   = $resolusi['tgl_mulai'];
    $tgl_selesai_efektif = $resolusi['tgl_selesai'];
    $periode_kosong      = $resolusi['periode_kosong'];
    $periode_digabung    = $resolusi['digabung'];
    if ($periode_kosong) {
        $bulan_berikutnya_label = date('F Y', strtotime("$tahun-$bulan-01 +1 month"));
    }

    $get_tgl_mulai   = $_GET['tgl_mulai'] ?? '';
    $get_tgl_selesai = $_GET['tgl_selesai'] ?? '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $get_tgl_mulai) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $get_tgl_selesai)) {
        if ($get_tgl_selesai < $get_tgl_mulai) { [$get_tgl_mulai, $get_tgl_selesai] = [$get_tgl_selesai, $get_tgl_mulai]; }
        $tgl_mulai_efektif   = $get_tgl_mulai;
        $tgl_selesai_efektif = $get_tgl_selesai;
        $periode_kosong      = false;
        $periode_digabung    = false;
        $override_tanggal    = true;
    }
}

// Kalau periode ini dikelola LEBIH DARI 1 pengelola (rotasi di tengah jalan),
// tawarkan pemilihan pengelola (tombol di sebelah filter Rentang Tanggal
// Custom) — pilih salah satu untuk melihat rekapitulasi LENGKAP pengelola itu
// saja (bukan digabung/ditumpuk). Default: pengelola pertama dalam periode.
// urutan_pengelola_aktif dipakai sbg diskriminator utk Klaim Bulanan, Keterangan
// Beban Operasional, dan Revenue Sharing (masing2 pengelola datanya terpisah).
$segmen_pengelola       = [];
$urutan_pengelola_aktif = 1;
$pengelola_terpilih_seg = null;
if ($periode === 'bulanan' && $id_cabang !== '' && !$periode_kosong) {
    $segmen_pengelola = resolve_pengelola_segments($conn, (int) $id_cabang, $tgl_mulai_efektif, $tgl_selesai_efektif);
    if (count($segmen_pengelola) > 1) {
        $pilih_segmen = (int) ($_GET['pengelola_segmen'] ?? 1);
        $segmen_valid = null;
        foreach ($segmen_pengelola as $s) {
            if ($s['urutan'] === $pilih_segmen) { $segmen_valid = $s; break; }
        }
        if ($segmen_valid === null) { $segmen_valid = $segmen_pengelola[0]; }

        $urutan_pengelola_aktif = $segmen_valid['urutan'];
        $pengelola_terpilih_seg = $segmen_valid['pengelola'];
        // Persempit rentang efektif ke rentang pengelola yg dipilih SAJA —
        // seluruh perhitungan di bawah (query utama, tabel harian, BO,
        // klaim bulanan, revenue sharing) otomatis ikut rentang ini.
        $tgl_mulai_efektif   = $segmen_valid['tgl_mulai'];
        $tgl_selesai_efektif = $segmen_valid['tgl_selesai'];
    }
}

// Tanggal acuan untuk atribusi pengelola/investor historis — akhir periode
// yang dipilih (efektif), BUKAN hari ini. Supaya rekap bulan lama tetap benar
// walau sudah ada rotasi pengelola/investor sesudahnya.
$periode_anchor = ($periode === 'bulanan')
    ? anchor_periode($tgl_selesai_efektif)
    : anchor_periode(date('Y-m-t', strtotime("$tahun-$bulan-01")));

// Ambil nama cabang yang kepilih biar input keisi
$nama_cabang_terpilih = '';
if ($id_cabang != '') {
    $stmt = $conn->prepare("SELECT nama_cabang FROM cabang WHERE id_cabang=?");
    $stmt->bind_param("i", $id_cabang);
    $stmt->execute();
    $nama_cabang_terpilih = $stmt->get_result()->fetch_assoc()['nama_cabang'] ?? '';
}

$list_cabang = $conn->query("SELECT id_cabang, nama_cabang FROM cabang ORDER BY nama_cabang");

// 2. BUAT WHERE PAKAI PREPARED
$where_sql = "";
$params = [];
$types = "";

// Rekap hanya menghitung laporan yang sudah difinalisasi PIC.
if ($periode == 'mingguan') {
    $where_sql = "WHERE l.status_laporan = 'lengkap' AND YEAR(l.tanggal)=? AND WEEK(l.tanggal,1) = WEEK(CURDATE(),1)";
    $params[] = $tahun;
    $types .= "i";
    $judul = "Rekap Mingguan - Minggu " . date('W');
} elseif ($periode == 'tahunan') {
    $where_sql = "WHERE l.status_laporan = 'lengkap' AND YEAR(l.tanggal)=?";
    $params[] = $tahun;
    $types .= "i";
    $judul = "Rekap Tahunan - Tahun $tahun";
} else {
    $where_sql = "WHERE l.status_laporan = 'lengkap' AND l.tanggal BETWEEN ? AND ?";
    $params[] = $tgl_mulai_efektif;
    $params[] = $tgl_selesai_efektif;
    $types .= "ss";
    $judul = "Rekap Bulanan - " . date('F Y', strtotime("$tahun-$bulan-01"));
}

// Filter cabang
$cabang_info = ['investor' => '-', 'no_rekening' => '-', 'nama_bank' => '-', "atas_nama_rekening" => "-"];
$nama_cabang = "Semua Cabang";
// Alamat/telp kop surat PDF — default kantor pusat, ditimpa alamat cabang aslinya
// kalau lagi lihat 1 cabang spesifik (bukan "Semua Cabang").
$alamat_cabang = "Kantor Pusat : Jl. Pamulang Permai Raya, Pamulang Bar., Kec. Pamulang, Kota Tangerang Selatan, Banten 15417";
$no_hp_cabang = "087784838769";
if ($id_cabang != '') {
    $where_sql .= " AND l.id_cabang = ?";
    $params[] = (int)$id_cabang;
    $types .= "i";

    $stmt = $conn->prepare("
    SELECT
        c.nama_cabang,
        IFNULL(c.atas_nama_cabang, '-') AS atas_nama_rekening,
        IFNULL(c.no_rekening_cabang, '-') AS no_rekening,
        IFNULL(c.nama_bank_cabang, '-') AS nama_bank,
        c.alamat,
        c.no_telp
    FROM cabang c
    WHERE c.id_cabang = ?
    LIMIT 1
");
    $stmt->bind_param("i", $id_cabang);
    $stmt->execute();
    $ass = $stmt->get_result()->fetch_assoc();
    $cabang_info = $ass ?? $cabang_info;
    $cabang_info['investor'] = investor_pada_tanggal($conn, (int) $id_cabang, $periode_anchor);
    $nama_cabang = $cabang_info['nama_cabang'] ?? "Semua Cabang";
    $judul .= " - " . $nama_cabang;
    if (!empty($cabang_info['alamat'])) $alamat_cabang = $cabang_info['alamat'];
    if (!empty($cabang_info['no_telp'])) $no_hp_cabang = $cabang_info['no_telp'];
}

// 3. QUERY DATA UTAMA PAKAI PREPARED
$query = "SELECT c.nama_cabang,
          (SELECT i.nama_investor
             FROM cabang_investor ci
             JOIN investor i ON i.id_investor = ci.id_investor
            WHERE ci.id_cabang = c.id_cabang
              AND ci.tgl_mulai <= ?
              AND (ci.tgl_selesai IS NULL OR ci.tgl_selesai >= ?)
            ORDER BY ci.tgl_mulai DESC
            LIMIT 1) as investor,
          c.no_rekening_cabang as no_rekening,
          c.nama_bank_cabang as nama_bank,
          SUM(l.tunai) as tunai,
          SUM(l.qris) as qris,
          SUM(l.pencairan_qris) as pencairan_qris,
          SUM(l.grab_food) as grab_food,
          SUM(l.go_food) as go_food,
          SUM(l.total_omset) as penjualan,
          SUM(l.belanja_pasar) as belanja_pasar,
          SUM(l.belanja_sembako) as belanja_sembako,
          SUM(l.belanja_beras) as belanja_beras,
          SUM(l.belanja_toko) as belanja_toko,
          SUM(l.total_rutin) as total_rutin,
          SUM(l.sewa) as sewa,
          SUM(l.gaji) as gaji,
          SUM(l.listrik) as listrik,
          SUM(l.air) as air,
          SUM(l.sampah) as sampah,
          SUM(l.keamanan) as keamanan,
          SUM(l.internet) as internet,
          SUM(l.gas) as gas,
          SUM(l.mingguan_karyawan) as mingguan_karyawan,
          SUM(l.es_batu) as es_batu,
          SUM(l.bensin) as bensin,
          SUM(l.lain_lain) as lain_lain,
          SUM(l.total_operasional) as total_operasional,
          SUM(l.total_pengeluaran) as pengeluaran,
          SUM(l.sisa_tunai) as sisa_tunai,
          SUM(l.sisa_qris) as sisa_qris,
          SUM(l.net_profit) as laba_bersih
          FROM laporan_cabang l
          JOIN cabang c ON l.id_cabang = c.id_cabang
          $where_sql
          GROUP BY l.id_cabang";

$stmt = $conn->prepare($query);
$params_query = array_merge([$periode_anchor, $periode_anchor], $params);
$types_query  = 'ss' . $types;
$stmt->bind_param($types_query, ...$params_query);
$stmt->execute();
$data = $stmt->get_result();
$row = ($data->num_rows > 0) ? $data->fetch_assoc() : [];

$penjualan   = (float)($row['penjualan'] ?? 0);
$pengeluaran = (float)($row['pengeluaran'] ?? 0);

/* Net Profit Dasar sebelum koreksi talangan warung */
$laba_bersih_dasar = (float)($row['laba_bersih'] ?? ($penjualan - $pengeluaran));

$margin = $penjualan > 0
    ? ($laba_bersih_dasar / $penjualan) * 100
    : 0;

// Data BO & Pengeluaran dari DB
$bo_db = [
    'belanja_pasar' => $row['belanja_pasar'] ?? 0,
    'belanja_sembako' => $row['belanja_sembako'] ?? 0,
    'belanja_beras' => $row['belanja_beras'] ?? 0,
    'belanja_toko' => $row['belanja_toko'] ?? 0,
    'sewa' => $row['sewa'] ?? 0,
    'gaji' => $row['gaji'] ?? 0,
    'listrik' => $row['listrik'] ?? 0,
    'internet' => $row['internet'] ?? 0,
    'sampah' => $row['sampah'] ?? 0,
    'keamanan' => $row['keamanan'] ?? 0,
    'air' => $row['air'] ?? 0,
    'gas' => $row['gas'] ?? 0,
    'mingguan_karyawan' => $row['mingguan_karyawan'] ?? 0,
    'es_batu' => $row['es_batu'] ?? 0,
    'bensin' => $row['bensin'] ?? 0,
    'lain_lain' => $row['lain_lain'] ?? 0
];

// Revenue sharing
$persen_investor = 50;
$persen_pengelola = 50;
$persen_admin = 3; // Admin Fee Pusat: 3%

// Klaim Bulanan (baris manual, lihat "10. Klaim Bulanan" & _rekap_klaim_bulanan.php)
// — nominalnya (SEMUA baris, apapun sumber dananya) mengurangi Net Profit
// SEBELUM admin fee 3% dipotong. Baris ber-sumber_dana='investor' JUGA
// otomatis masuk ke "Pengembalian Dana Talangan" (Koreksi Dividen: Sisi
// Investor); baris ber-sumber_dana='pusat' JUGA otomatis masuk ke "Admin
// Management Pusat" (6. Rekapan Hasil Akhir Keuntungan) — keduanya di luar
// potongan Net Profit di atas. urutan_pengelola_aktif = segmen pengelola
// yang sedang dipilih (1 kalau cuma 1 pengelola di periode ini).
$total_klaim_bulanan = 0.0;
$total_klaim_dana_investor = 0.0;
$total_klaim_dana_pusat = 0.0;
$daftar_klaim_bulanan = [];
if ($id_cabang !== '') {
    $stmt_kb = $conn->prepare("SELECT id, uraian, nominal, sumber_dana, keterangan FROM klaim_bulanan WHERE id_cabang = ? AND tahun = ? AND bulan = ? AND urutan_pengelola = ? ORDER BY urutan ASC, id ASC");
    $stmt_kb->bind_param('iiii', $id_cabang, $tahun, $bulan, $urutan_pengelola_aktif);
    $stmt_kb->execute();
    $daftar_klaim_bulanan = $stmt_kb->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_kb->close();
    foreach ($daftar_klaim_bulanan as $kb) {
        $total_klaim_bulanan += (float) $kb['nominal'];
        if (($kb['sumber_dana'] ?? 'warung') === 'investor') {
            $total_klaim_dana_investor += (float) $kb['nominal'];
        }
        if (($kb['sumber_dana'] ?? 'warung') === 'pusat') {
            $total_klaim_dana_pusat += (float) $kb['nominal'];
        }
    }
}

// Perhitungan Laba Default (Sebelum pilihan dinamis di UI)
// Urutan: Net Profit -> dikurangi Klaim Bulanan -> BARU admin fee 3% dihitung
// dari sisanya -> split 50/50 investor-pengelola.
$laba_bersih_setelah_klaim = $laba_bersih_dasar - $total_klaim_bulanan;
$share_admin = $laba_bersih_setelah_klaim * $persen_admin / 100;
$laba_setelah_admin = $laba_bersih_setelah_klaim - $share_admin;
$share_investor = $laba_setelah_admin * $persen_investor / 100;
$share_pengelola = $laba_setelah_admin * $persen_pengelola / 100;

$persen_admin_pengelola = 3;
$share_admin_pengelola = $share_pengelola * $persen_admin_pengelola / 100;
$share_pengelola_bersih = $share_pengelola - $share_admin_pengelola;

// Data pengelola aman
$pengelola = [
    'nama_pengelola' => '-',
    'no_rekening' => '-',
    'nama_bank' => '-',
    'atas_nama_rekening' => '-'
];

if ($id_cabang != '') {
    // Pengelola PADA PERIODE yang dipilih — bukan yang aktif sekarang, supaya
    // rekap bulan lama tetap benar walau pengelola sudah berganti sesudahnya.
    $stmt = $conn->prepare("
        SELECT
            nama_pengelola,
            no_rekening_pengelola AS no_rekening,
            nama_bank_pengelola   AS nama_bank,
            atas_nama_pengelola   AS atas_nama_rekening
        FROM pengelola
        WHERE id_cabang = ?
          AND tgl_mulai <= ?
          AND (tgl_selesai IS NULL OR tgl_selesai >= ?)
        ORDER BY tgl_mulai DESC
        LIMIT 1
    ");

    $stmt->bind_param("iss", $id_cabang, $periode_anchor, $periode_anchor);
    $stmt->execute();
    $pengelola = $stmt->get_result()->fetch_assoc() ?? $pengelola;
}

// PIC yang menginput/memfinalisasi laporan bulan ini untuk cabang terpilih.
$nama_pic = '-';
if ($id_cabang != '') {
    $stmt = $conn->prepare("
        SELECT GROUP_CONCAT(DISTINCT u.username ORDER BY u.username SEPARATOR ', ') AS pic
        FROM laporan_cabang lc
        JOIN users u ON u.id = lc.id_user_laporan
        WHERE lc.id_cabang = ? AND lc.tanggal BETWEEN ? AND ?
          AND lc.status_laporan = 'lengkap' AND lc.id_user_laporan IS NOT NULL
    ");
    $stmt->bind_param("iss", $id_cabang, $tgl_mulai_efektif, $tgl_selesai_efektif);
    $stmt->execute();
    $nama_pic = $stmt->get_result()->fetch_assoc()['pic'] ?? null;
    $nama_pic = $nama_pic ?: '-';

    // Belum ada laporan yang difinalisasi bulan ini — fallback ke PIC yang
    // DITUGASKAN (tabel pengelola) supaya kolom ini tidak kosong percuma.
    if ($nama_pic === '-') {
        $stmt2 = $conn->prepare("
            SELECT u.username FROM pengelola p
            JOIN users u ON u.id = p.id_user AND u.role = 'pic'
            WHERE p.id_cabang = ? AND p.tgl_mulai <= ? AND (p.tgl_selesai IS NULL OR p.tgl_selesai >= ?)
            ORDER BY p.tgl_mulai DESC LIMIT 1
        ");
        $stmt2->bind_param('iss', $id_cabang, $periode_anchor, $periode_anchor);
        $stmt2->execute();
        $ditugaskan = $stmt2->get_result()->fetch_assoc()['username'] ?? null;
        $stmt2->close();
        if ($ditugaskan) {
            $nama_pic = $ditugaskan . ' (ditugaskan)';
        }
    }
}

// Format nama file export — manusiawi & jadi redaksi WA otomatis (lihat sharePdfToWA()).
$nama_file_export = "Rekapitulasi Bulanan " . $nama_cabang . " " . nama_bulan_id((int) $bulan) . " " . $tahun;
?>


<!-- Link tambahan stylesheet icons -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<!-- Kustomisasi CSS untuk kenyamanan mata & kerapian ekstra -->
<style>
    .main-wrapper {
        font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
        color: #334155;
    }

    .card {
        border: 1px solid #e2e8f0 !important;
        border-radius: 14px !important;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.02), 0 2px 4px -1px rgba(0, 0, 0, 0.01) !important;
        background-color: #ffffff;
    }

    .card-header {
        font-size: 0.95rem;
        letter-spacing: 0.02em;
        padding: 1rem 1.25rem !important;
    }

    .table th {
        font-weight: 600;
        text-transform: uppercase;
        font-size: 0.75rem;
        letter-spacing: 0.05em;
        color: #64748b;
        background-color: #f8fafc !important;
        border-bottom: 2px solid #e2e8f0 !important;
        padding: 12px 14px !important;
    }

    .table td {
        padding: 12px 14px !important;
        font-size: 0.875rem;
        color: #475569;
    }

    .table-clean-input input.form-control {
        border: 1px solid transparent !important;
        background-color: transparent !important;
        box-shadow: none !important;
        padding: 4px 8px;
        font-size: 0.875rem;
        transition: all 0.2s ease;
        border-radius: 6px !important;
    }

    .table-clean-input input.form-control:hover {
        background-color: #f1f5f9 !important;
        border-color: #cbd5e1 !important;
    }

    .table-clean-input input.form-control:focus {
        background-color: #ffffff !important;
        border-color: #3b82f6 !important;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15) !important;
    }

    .table-clean-input input.keterangan {
        text-align: left;
    }

    .form-label-sm {
        font-size: 0.785rem;
        font-weight: 600;
        color: #64748b;
        margin-bottom: 6px;
        text-transform: uppercase;
        letter-spacing: 0.02em;
    }

    .form-control-premium {
        border: 2px solid #e2e8f0 !important;
        background-color: #f8fafc !important;
        border-radius: 8px !important;
        font-size: 0.9rem;
        padding: 0.5rem 0.75rem;
    }

    .form-control-premium:focus {
        background-color: #ffffff !important;
        border-color: #3b82f6 !important;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1) !important;
    }
</style>

<div class="container-fluid py-4 main-wrapper">
    <!-- Header Page -->
    <div class="mb-4">
        <h3 class="fw-bold text-dark mb-1" style="letter-spacing: -0.5px;"><?= h($judul) ?></h3>
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
                    <input list="listCabang" id="inputCabang" class="form-control form-control-premium border-start-0" style="border-radius: 0 8px 8px 0!important;" placeholder="Ketik nama cabang..." value="<?= h($nama_cabang_terpilih) ?>" autocomplete="off">
                </div>
                <input type="hidden" name="id_cabang" id="idCabang" value="<?= h($id_cabang) ?>">

                <datalist id="listCabang">
                    <option value="Semua Cabang" data-id=""></option>
                    <?php $list_cabang->data_seek(0);
                    while ($c = $list_cabang->fetch_assoc()): ?>
                        <option value="<?= h($c['nama_cabang']) ?>" data-id="<?= $c['id_cabang'] ?>"></option>
                    <?php endwhile; ?>
                </datalist>
            </div>
            <div class="col-xl-2 col-md-6">
                <label class="form-label-sm">Periode Analisis</label>
                <select name="periode" class="form-select form-control-premium" id="periodeSelect">
                    <option value="mingguan" <?= $periode == 'mingguan' ? 'selected' : '' ?>>Mingguan</option>
                    <option value="bulanan" <?= $periode == 'bulanan' ? 'selected' : '' ?>>Bulanan</option>
                    <option value="tahunan" <?= $periode == 'tahunan' ? 'selected' : '' ?>>Tahunan</option>
                </select>
            </div>
            <div class="col-xl-2 col-md-6">
                <label class="form-label-sm">Tahun Buku</label>
                <select name="tahun" class="form-select form-control-premium">
                    <?php for ($t = date('Y'); $t >= 2024; $t--): ?>
                        <option value="<?= $t ?>" <?= $tahun == $t ? 'selected' : '' ?>><?= $t ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-xl-2 col-md-6">
                <label class="form-label-sm">Bulan Buku</label>
                <select name="bulan" id="bulanSelect" class="form-select form-control-premium" <?= $periode == 'tahunan' ? 'disabled' : '' ?>>
                    <?php for ($b = 1; $b <= 12; $b++): ?>
                        <option value="<?= str_pad($b, 2, '0', STR_PAD_LEFT) ?>" <?= $bulan == str_pad($b, 2, '0', STR_PAD_LEFT) ? 'selected' : '' ?>>
                            <?= date('F', mktime(0, 0, 0, $b, 1)) ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-xl-3 col-md-12 d-grid">
                <button class="btn btn-primary fw-semibold py-2" style="border-radius: 8px;"><i class="bi bi-funnel-fill me-1"></i> Ambil Data</button>
            </div>

            <?php if ($periode === 'bulanan'): ?>
            <div class="col-12">
                <hr class="my-1">
                <label class="form-label-sm d-block mb-2">
                    <i class="bi bi-calendar-range me-1"></i> Rentang Tanggal Custom
                    <span class="text-muted fw-normal">(opsional — menimpa Tahun/Bulan Buku di atas kalau diisi)</span>
                </label>
            </div>
            <div class="col-xl-2 col-md-6">
                <input type="date" name="tgl_mulai" class="form-control form-control-premium" value="<?= $override_tanggal ? h($tgl_mulai_efektif) : '' ?>">
            </div>
            <div class="col-xl-2 col-md-6">
                <input type="date" name="tgl_selesai" class="form-control form-control-premium" value="<?= $override_tanggal ? h($tgl_selesai_efektif) : '' ?>">
            </div>
            <?php if ($override_tanggal): ?>
            <div class="col-xl-2 col-md-6 d-grid">
                <a href="?id_cabang=<?= h($id_cabang) ?>&periode=bulanan&tahun=<?= h($tahun) ?>&bulan=<?= h($bulan) ?>" class="btn btn-outline-secondary btn-sm fw-semibold">
                    <i class="bi bi-x-circle me-1"></i> Hapus Rentang Custom
                </a>
            </div>
            <?php endif; ?>

            <?php if (count($segmen_pengelola) > 1): ?>
            <div class="col-12">
                <label class="form-label-sm d-block mb-2 mt-2">
                    <i class="bi bi-people me-1"></i> Periode ini dikelola <?= count($segmen_pengelola) ?> pengelola berbeda — pilih salah satu
                </label>
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach ($segmen_pengelola as $s):
                        $s_aktif = $s['urutan'] === $urutan_pengelola_aktif;
                        $s_nama  = $s['pengelola']['nama_pengelola'] ?? ('Pengelola ' . $s['urutan']);
                    ?>
                    <a href="?id_cabang=<?= h($id_cabang) ?>&periode=bulanan&tahun=<?= h($tahun) ?>&bulan=<?= h($bulan) ?>&pengelola_segmen=<?= $s['urutan'] ?>"
                       class="btn btn-sm fw-semibold <?= $s_aktif ? 'btn-primary' : 'btn-outline-primary' ?>">
                        <?= $s_aktif ? '<i class="bi bi-check-circle-fill me-1"></i>' : '' ?><?= h($s_nama) ?>
                        <span class="fw-normal">(<?= h(date('d/m', strtotime($s['tgl_mulai']))) ?>&ndash;<?= h(date('d/m', strtotime($s['tgl_selesai']))) ?>)</span>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </form>
    </div>
</div>
    <?php if (empty($id_cabang)): ?>
    <div class="alert alert-warning border-0 p-4 d-flex align-items-center" role="alert" style="border-radius: 12px; background-color: #fffbeb; border: 1px solid #fde68a!important;">
        <i class="bi bi-exclamation-circle-fill fs-4 me-3 text-warning"></i>
        <div>
            <h6 class="fw-bold text-warning-emphasis mb-1">Pilih Cabang Terlebih Dahulu</h6>
            <span class="text-secondary small">Gunakan form pencarian di atas untuk memuat data transaksi, rincian biaya, dan grafik pembagian hasil.</span>
        </div>
    </div>
    <?php elseif ($periode_kosong): ?>
    <div class="alert alert-info border-0 p-4 d-flex align-items-center" role="alert" style="border-radius: 12px; background-color: #eff6ff; border: 1px solid #bfdbfe!important;">
        <i class="bi bi-info-circle-fill fs-4 me-3 text-primary"></i>
        <div>
            <h6 class="fw-bold text-primary mb-1">Periode Ini Digabung ke Closing Bulan Berikutnya</h6>
            <span class="text-secondary small">
                <?= h($nama_cabang) ?> baru mulai pembukuan pada/setelah tanggal 20 di bulan ini, jadi tidak ada closing tersendiri untuk periode ini —
                datanya digabung ke closing <strong><?= h($bulan_berikutnya_label) ?></strong>. Pilih bulan tersebut untuk melihat rekapitulasinya.
            </span>
        </div>
    </div>
    <?php else: ?>

        <!-- 1. Rekap Cabang Overview Widgets -->
        <div class="row g-3 mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="card border-0 border-start border-4 border-dark h-100">
                    <div class="card-body p-3 d-flex align-items-center justify-content-between">
                        <div>
                            <span class="text-muted small text-uppercase fw-semibold" style="font-size: 0.75rem;">Nama Cabang</span>
                            <h5 class="fw-bold text-dark mb-0 mt-1"><?= h($nama_cabang) ?></h5>
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
                            <span class="text-muted small text-uppercase fw-semibold" style="font-size: 0.75rem;">Total Omset</span>
                            <h5 class="fw-bold text-primary mb-0 mt-1">Rp <?= number_format($penjualan, 0, ',', '.') ?></h5>
                        </div>
                        <div class="bg-primary bg-opacity-10 text-primary p-2.5 rounded-3">
                            <i class="bi bi-cash-stack fs-5"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="card border-0 border-start border-4 border-danger h-100">
                    <div class="card-body p-3 d-flex align-items-center justify-content-between">
                        <div>
                            <span class="text-muted small text-uppercase fw-semibold" style="font-size: 0.75rem;">Total Pengeluaran</span>
                            <h5 class="fw-bold text-danger mb-0 mt-1">Rp <?= number_format($pengeluaran, 0, ',', '.') ?></h5>
                        </div>
                        <div class="bg-danger bg-opacity-10 text-danger p-2.5 rounded-3">
                            <i class="bi bi-receipt fs-5"></i>
                        </div>
                    </div>
                </div>
            </div>
            <?php $np_warna = $laba_bersih_dasar < 0 ? 'danger' : 'success'; ?>
            <div class="col-xl-3 col-md-6">
                <div class="card border-0 border-start border-4 border-<?= $np_warna ?> h-100">
                    <div class="card-body p-3 d-flex align-items-center justify-content-between">
                        <div>
                            <span class="text-muted small text-uppercase fw-semibold" style="font-size: 0.75rem;">NET PROFIT / (Margin)</span>
                            <h5 class="fw-bold text-<?= $np_warna ?> mb-0 mt-1">Rp <?= number_format($laba_bersih_dasar, 0, ',', '.') ?> <span class="fs-6 text-muted fw-normal">(<?= number_format($margin, 2) ?>%)</span></h5>
                        </div>
                        <div class="bg-<?= $np_warna ?> bg-opacity-10 text-<?= $np_warna ?> p-2.5 rounded-3">
                            <i class="bi bi-pie-chart fs-5"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 1. Rekap Harian Sebulan Full -->
        <div class="card border-0 mt-4" style="overflow: hidden;">
            <div class="card-header bg-dark text-white py-3 d-flex align-items-center justify-content-between">
                <span class="fw-bold"><i class="bi bi-calendar3 me-2"></i>1. Rekapitulasi Pendapatan & Pengeluaran Harian - <?= date('F Y', strtotime("$tahun-$bulan-01")) ?></span>
                <?php if (count($segmen_pengelola) > 1): ?>
                    <span class="badge bg-primary bg-opacity-10 text-primary fw-medium px-3 py-1.5 rounded-pill">
                        <i class="bi bi-person-badge me-1"></i><?= h($pengelola_terpilih_seg['nama_pengelola'] ?? 'Pengelola ' . $urutan_pengelola_aktif) ?>
                        (<?= h(date('d/m', strtotime($tgl_mulai_efektif))) ?>&ndash;<?= h(date('d/m/Y', strtotime($tgl_selesai_efektif))) ?>)
                    </span>
                <?php elseif ($periode_digabung): ?>
                    <span class="badge bg-info-subtle text-primary fw-medium px-3 py-1.5 rounded-pill" title="Termasuk sisa hari sejak <?= h(date('d/m/Y', strtotime($tgl_mulai_efektif))) ?> (cabang baru mulai pembukuan)">
                        <i class="bi bi-signpost-split me-1"></i>Gabungan sejak <?= h(date('d/m/Y', strtotime($tgl_mulai_efektif))) ?>
                    </span>
                <?php elseif ($override_tanggal): ?>
                    <span class="badge bg-light text-dark fw-medium px-3 py-1.5 rounded-pill"><?= h(date('d/m/Y', strtotime($tgl_mulai_efektif))) ?> &ndash; <?= h(date('d/m/Y', strtotime($tgl_selesai_efektif))) ?></span>
                <?php else: ?>
                    <span class="badge bg-light text-dark fw-medium px-3 py-1.5 rounded-pill">Detail per tanggal</span>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <?php
                    $rk_tgl_mulai   = $tgl_mulai_efektif;
                    $rk_tgl_selesai = $tgl_selesai_efektif;
                    $rk_id_cabang = (int) $id_cabang;
                    $rk_tabel_id = 'tabelRekapHarian';
                    include '_rekap_tabel_harian.php';

                    // Angka yang dipakai bagian lain (Matrik Akumulasi, tombol export, dll.)
                    // $jumlah_hari_data = total baris (termasuk libur), untuk "ada data / tombol export".
                    // $jumlah_hari_kerja = jumlah hari 'lengkap' saja, untuk pembagi rata-rata harian
                    // (hari libur tidak boleh mengencerkan rata-rata beban operasional harian).
                    $jumlah_hari_data  = $rk_num_rows;
                    $jumlah_hari_kerja = $rk_num_lengkap;
                    $total_pasar   = $rk_t_pasar;
                    $total_beras   = $rk_t_beras;
                    $total_sembako = $rk_t_sembako;
                    $total_toko    = $rk_t_toko;
                    ?>
                </div>
            </div>
        </div>

        <!-- (tersembunyi) tabel harian BULAN SEBELUMNYA — sumber Export PDF Harian.
             Dihitung dari bulan kalender sebelum tgl_mulai_efektif (bukan $tahun/$bulan
             mentah) — supaya tetap konsisten kalau periode ini sedang digabung/di-override. -->
        <?php
        $rk_prev    = strtotime(date('Y-m-01', strtotime($tgl_mulai_efektif)) . ' -1 month');
        $bulan_prev = (int) date('n', $rk_prev);
        $tahun_prev = (int) date('Y', $rk_prev);
        ?>
        <div aria-hidden="true" style="position:absolute; left:-99999px; top:0; width:1600px; pointer-events:none;">
            <?php
            $rk_tgl_mulai   = date('Y-m-01', $rk_prev);
            $rk_tgl_selesai = date('Y-m-t', $rk_prev);
            $rk_id_cabang = (int) $id_cabang;
            $rk_tabel_id = 'tabelRekapHarianPrev';
            include '_rekap_tabel_harian.php';
            ?>
        </div>


<!-- 2. Rincian Beban Operasional -->
<div class="card border-0 mb-4" style="overflow: hidden;">
    <div class="card-header bg-light border-bottom py-3 d-flex align-items-center justify-content-between">
        <span class="fw-bold text-dark"><i class="bi bi-folder-symlink me-2 text-muted"></i>2. Rincian Beban Operasional</span>
        <span class="badge bg-secondary bg-opacity-10 text-secondary px-3 py-1.5 rounded-pill fw-medium" style="font-size: 0.75rem;">Total Rekap 1 Bulan</span>
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
                    // Hitung jumlah hari ada data untuk dapat rata2 harian (hari libur tidak dihitung)
                    $jumlah_hari = (($jumlah_hari_kerja ?? 0) > 0) ? $jumlah_hari_kerja : 1;

                    // Keterangan Tambahan Beban Operasional (persisten) — key: cabang+tahun+bulan+
                    // urutan_pengelola_aktif (segmen pengelola yg sedang dipilih).
                    $ket_bo = [];
                    $stmt_ket = $conn->prepare("SELECT uraian_key, keterangan FROM beban_operasional_keterangan WHERE id_cabang = ? AND tahun = ? AND bulan = ? AND urutan_pengelola = ?");
                    $stmt_ket->bind_param('iiii', $id_cabang, $tahun, $bulan, $urutan_pengelola_aktif);
                    $stmt_ket->execute();
                    $res_ket = $stmt_ket->get_result();
                    while ($rket = $res_ket->fetch_assoc()) {
                        $ket_bo[$rket['uraian_key']] = $rket['keterangan'];
                    }
                    $stmt_ket->close();

                    $uraian_bo = [
                        1 => ['nama' => 'Sewa Ruko', 'field' => 'sewa', 'harian' => true, 'tahunan' => true],
                        2 => ['nama' => 'Gaji Karyawan', 'field' => 'gaji', 'harian' => true, 'tahunan' => false],
                        3 => ['nama' => 'Listrik Prabayar', 'field' => 'listrik', 'harian' => false, 'tahunan' => false],
                        4 => ['nama' => 'Air PAM', 'field' => 'air', 'harian' => false, 'tahunan' => false],
                        5 => ['nama' => 'Iuran Sampah', 'field' => 'sampah', 'harian' => false, 'tahunan' => false],
                        6 => ['nama' => 'Keamanan', 'field' => 'keamanan', 'harian' => false, 'tahunan' => false],
                        7 => ['nama' => 'Wifi/Internet', 'field' => 'internet', 'harian' => false, 'tahunan' => false],
                        8 => ['nama' => 'Gas', 'field' => 'gas', 'harian' => false, 'tahunan' => false],
                        9 => ['nama' => 'Mingguan Karyawan', 'field' => 'mingguan_karyawan', 'harian' => false, 'tahunan' => false],
                        10 => ['nama' => 'Es Batu', 'field' => 'es_batu', 'harian' => false, 'tahunan' => false],
                        11 => ['nama' => 'Bensin', 'field' => 'bensin', 'harian' => false, 'tahunan' => false],
                        12 => ['nama' => 'Lain-lain', 'field' => 'lain_lain', 'harian' => false, 'tahunan' => false],
                    ];

                    $total_bo_all = 0;
                    foreach ($uraian_bo as $no => $item):
                        $field = $item['field'];
                        $val_bulanan = $field != '' ? (float)($bo_db[$field] ?? 0) : 0;
                        $val_tahunan = ($item['tahunan'] == true) ? $val_bulanan * 12 : 0;
                        $val_harian = ($item['harian'] == true && $jumlah_hari > 0) ? round($val_bulanan / $jumlah_hari) : 0;

                        $total_bo_all += $val_bulanan;
                    ?>
                        <tr>
                            <td class="text-center text-muted fw-medium"><?= $no ?></td>
                            <td class="fw-semibold text-dark"><?= h($item['nama']) ?></td>
                            <td class="text-center fw-semibold">
                                <?= $val_harian > 0 ? number_format($val_harian, 0, ',', '.') : '-' ?>
                            </td>
                            <td class="text-center fw-semibold"><?= number_format($val_bulanan, 0, ',', '.') ?></td>
                            <td class="text-center fw-semibold">
                                <?= $val_tahunan > 0 ? number_format($val_tahunan, 0, ',', '.') : '-' ?>
                            </td>
                            <td class="text-center fw-semibold"><?= number_format($val_bulanan, 0, ',', '.') ?></td>
                            <td class="text-end fw-bold text-dark pe-3"><?= number_format($val_bulanan, 0, ',', '.') ?></td>
                            <td class="ps-4">
                                <input type="text" class="form-control form-control-sm border-0 bg-transparent keterangan"
                                    name="ket_bo[<?= h($item['field']) ?>]"
                                    value="<?= h($ket_bo[$item['field']] ?? '') ?>"
                                    placeholder="Ketik keterangan..."
                                    style="min-width: 180px;">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="table-light border-top border-2" style="background-color: #f8fafc !important;">
                        <td colspan="6" class="text-end fw-bold text-secondary py-3">TOTAL BEBAN OPERASIONAL:</td>
                        <td class="text-end fw-bold text-primary py-3 pe-3"><?= number_format($total_bo_all, 0, ',', '.') ?></td>
                        <td></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="p-3 border-top d-flex justify-content-end" style="background-color: #f8fafc;">
            <button type="button" id="btnSimpanKeteranganBO" class="btn btn-sm btn-outline-primary fw-semibold">
                <i class="bi bi-save me-1"></i>Simpan Keterangan
            </button>
        </div>
    </div>
</div>
<script>
(function () {
    const btn = document.getElementById('btnSimpanKeteranganBO');
    if (!btn) return;
    const asalHtml = btn.innerHTML;
    btn.addEventListener('click', function () {
        const fd = new FormData();
        fd.append('csrf', <?= json_encode(csrf_token()) ?>);
        fd.append('id_cabang', <?= (int) $id_cabang ?>);
        fd.append('tahun', <?= (int) $tahun ?>);
        fd.append('bulan', <?= (int) $bulan ?>);
        fd.append('urutan_pengelola', <?= (int) $urutan_pengelola_aktif ?>);
        document.querySelectorAll('input.keterangan[name^="ket_bo"]').forEach(function (inp) {
            const m = inp.name.match(/ket_bo\[(.+)\]/);
            if (m) fd.append('ket_bo[' + m[1] + ']', inp.value);
        });
        btn.disabled = true;
        fetch('rekap_beban_keterangan_handler.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                btn.disabled = false;
                if (!data.ok) { alert('Gagal: ' + (data.msg || 'unknown')); return; }
                btn.innerHTML = '<i class="bi bi-check2 me-1"></i>Tersimpan';
                setTimeout(function () { btn.innerHTML = asalHtml; }, 1500);
            })
            .catch(function (err) {
                btn.disabled = false;
                alert('Gagal mengirim: ' + err);
            });
    });
})();
</script>

<!-- ROW MATRIK & REVENUE SHARING -->
<div class="row g-3 mb-4">

    <!-- 4. Matrik Akumulasi -->
    <div class="col-lg-6">
        <div class="card border-0 h-100" style="overflow: hidden;">
            <div class="card-header bg-light border-bottom py-3">
                <span class="fw-bold text-dark"><i class="bi bi-calculator me-2 text-muted"></i>4. Matrik Akumulasi <?= date('F Y', strtotime("$tahun-$bulan-01")) ?></span>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr class="table-light">
                            <th class="px-3">Uraian Akun</th>
                            <th class="text-end px-3" width="45%">Nilai Akumulasi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $omzet_akumulasi = $penjualan ?? 0;
                        $total_pasar = $total_pasar ?? 0;
                        $total_beras = $total_beras ?? 0;
                        $total_sembako = $total_sembako ?? 0;
                        $total_toko = $total_toko ?? 0;
                        
                        $belanja_akumulasi = $total_pasar + $total_beras + $total_sembako + $total_toko;
                        $bo_akumulasi = $total_bo_all ?? 0;
                        $pengeluaran_akumulasi = $belanja_akumulasi + $bo_akumulasi;
                        $laba_akumulasi = $laba_bersih_dasar ?? ($omzet_akumulasi - $pengeluaran_akumulasi);
                        ?>
                        <tr>
                            <td class="px-3 fw-medium text-dark"><i class="bi bi-cash-stack text-primary me-2"></i>1. Omzet Penjualan</td>
                            <td class="text-end fw-bold text-primary px-3">Rp <?= number_format($omzet_akumulasi, 0, ',', '.') ?></td>
                        </tr>
                        <tr>
                            <td class="px-3 fw-medium text-dark"><i class="bi bi-cart3 text-danger me-2"></i>2. Pengeluaran Belanja</td>
                            <td class="text-end fw-bold text-danger px-3">Rp <?= number_format($belanja_akumulasi, 0, ',', '.') ?></td>
                        </tr>
                        <tr>
                            <td class="px-3 fw-medium text-dark"><i class="bi bi-building-gear text-secondary me-2"></i>3. Beban Operasional</td>
                            <td class="text-end fw-bold text-secondary px-3">Rp <?= number_format($bo_akumulasi, 0, ',', '.') ?></td>
                        </tr>
                        <tr class="table-light">
                            <td class="px-3 fw-bold text-dark"><i class="bi bi-receipt text-dark me-2"></i>Total Pengeluaran</td>
                            <td class="text-end fw-bold text-dark px-3">Rp <?= number_format($pengeluaran_akumulasi, 0, ',', '.') ?></td>
                        </tr>
                        <tr>
                            <td class="px-3 fw-medium text-dark"><i class="bi bi-wallet2 text-warning me-2"></i>4. Modal Awal</td>
                            <td class="text-end px-3">
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text bg-transparent border-0 text-warning fw-bold pe-1">Rp</span>
                                    <input type="number" id="matrik_modal_awal"
                                           class="form-control text-end fw-bold text-warning border-0 bg-transparent p-0"
                                           value="0" min="0" step="1000" oninput="hitungCascade()">
                                </div>
                            </td>
                        </tr>
                        <tr class="table-success">
                            <td class="px-3 fw-bold text-dark"><i class="bi bi-graph-up-arrow text-success me-2"></i>5. Laba Bersih</td>
                            <td class="text-end fw-bold text-success px-3" id="matrik_laba_bersih">Rp <?= number_format($laba_akumulasi, 0, ',', '.') ?></td>
                        </tr>
                    </tbody>
                </table>
                <div class="p-3 bg-light text-muted border-top" style="font-size: 0.8rem; line-height: 1.4;">
                    <i class="bi bi-info-circle me-1 text-primary"></i> Angka lain diambil otomatis dari rekapitulasi harian bulan <?= date('F Y', strtotime("$tahun-$bulan-01")) ?>. <strong>Modal Awal</strong> diisi manual &mdash; nilainya mengurangi Net Profit awal 100% (sebelum potong admin 3%).
                </div>
            </div>
        </div>
    </div>

    <!-- 5. Revenue Sharing Reference -->
    <div class="col-lg-6">
        <div class="card border-0 h-100" style="overflow: hidden;">
            <div class="card-header bg-light border-bottom py-3">
                <span class="fw-bold text-dark">
                    <i class="bi bi-share me-2 text-muted"></i>
                    5. Kontrak Pembagian Hasil (Revenue Sharing)
                </span>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr class="table-light">
                            <th class="px-3">Komponen Pembagian</th>
                            <th class="text-center" width="20%">Porsi</th>
                            <th class="text-end px-3" width="35%">Nilai</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- NET PROFIT -->
                        <tr>
                            <td class="px-3 fw-medium text-dark">
                                <i class="bi bi-graph-up-arrow text-dark me-2"></i>Net Profit
                            </td>
                            <td class="text-center">
                                <span class="badge bg-dark text-white px-2.5 py-1.5 fw-bold" style="font-size: 0.8rem;">100%</span>
                            </td>
                            <td class="text-end fw-bold text-dark px-3" id="rev_net_profit">
                                Rp <?= number_format($laba_bersih_dasar ?? 0, 0, ',', '.') ?>
                            </td>
                        </tr>

                        <!-- KLAIM BULANAN (dipotong SEBELUM admin fee) -->
                        <tr>
                            <td class="px-3 fw-medium text-dark">
                                <i class="bi bi-receipt-cutoff text-warning me-2"></i>Klaim Bulanan
                            </td>
                            <td class="text-center">
                                <span class="badge bg-warning bg-opacity-10 text-warning-emphasis px-2.5 py-1.5 fw-bold" style="font-size: 0.8rem;">(-)</span>
                            </td>
                            <td class="text-end fw-bold text-warning-emphasis px-3" id="rev_klaim_bulanan">
                                Rp <?= number_format($total_klaim_bulanan ?? 0, 0, ',', '.') ?>
                            </td>
                        </tr>

                        <!-- ADMIN FEE -->
                        <tr>
                            <td class="px-3 fw-medium text-dark">
                                <i class="bi bi-shield-check text-danger me-2"></i>Management Pusat
                            </td>
                            <td class="text-center">
                                <span class="badge bg-danger bg-opacity-10 text-danger px-2.5 py-1.5 fw-bold" style="font-size: 0.8rem;">
                                    <?= $persen_admin ?? 3 ?>%
                                </span>
                            </td>
                            <td class="text-end fw-bold text-danger px-3" id="rev_admin_fee">
                                Rp <?= number_format($share_admin ?? 0, 0, ',', '.') ?>
                            </td>
                        </tr>

                        <!-- LABA SETELAH ADMIN -->
                        <tr class="table-warning">
                            <td class="px-3 fw-bold text-dark">
                                <i class="bi bi-calculator text-warning me-2"></i>Laba Setelah Admin Fee
                            </td>
                            <td class="text-center">
                                <span class="badge bg-warning bg-opacity-25 text-dark px-2.5 py-1.5 fw-bold" style="font-size: 0.8rem;">
                                    <?= 100 - ($persen_admin ?? 3) ?>%
                                </span>
                            </td>
                            <td class="text-end fw-bold text-warning-emphasis px-3" id="rev_laba_setelah_admin">
                                Rp <?= number_format($laba_setelah_admin ?? 0, 0, ',', '.') ?>
                            </td>
                        </tr>

                        <!-- INVESTOR -->
                        <tr>
                            <td class="px-3 fw-medium text-dark">
                                <i class="bi bi-person text-primary me-2"></i>Investor Utama
                            </td>
                            <td class="text-center">
                                <span class="badge bg-primary bg-opacity-10 text-primary px-2.5 py-1.5 fw-bold" style="font-size: 0.8rem;">
                                    <?= $persen_investor ?? 50 ?>%
                                </span>
                            </td>
                            <td class="text-end fw-bold text-primary px-3" id="rev_share_investor">
                                Rp <?= number_format($share_investor ?? 0, 0, ',', '.') ?>
                            </td>
                        </tr>

                        <!-- PENGELOLA -->
                        <tr>
                            <td class="px-3 fw-medium text-dark">
                                <i class="bi bi-person-gear text-success me-2"></i>Pengelola Lapangan
                            </td>
                            <td class="text-center">
                                <span class="badge bg-success bg-opacity-10 text-success px-2.5 py-1.5 fw-bold" style="font-size: 0.8rem;">
                                    <?= $persen_pengelola ?? 50 ?>%
                                </span>
                            </td>
                            <td class="text-end fw-bold text-success px-3" id="pgl_share_kotor">
                                Rp <?= number_format($share_pengelola ?? 0, 0, ',', '.') ?>
                            </td>
                        </tr>

                        <!-- TOTAL -->
                        <tr class="table-success">
                            <td class="px-3 fw-bold text-dark">Total Pembagian</td>
                            <td class="text-center">
                                <span class="badge bg-success text-white px-2.5 py-1.5 fw-bold" style="font-size: 0.8rem;">100%</span>
                            </td>
                            <td class="text-end fw-bold text-dark px-3" id="rev_total_pembagian">
                                Rp <?= number_format((($share_investor ?? 0) + ($share_pengelola ?? 0)), 0, ',', '.') ?>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <!-- KETERANGAN -->
                <div class="p-3 bg-light text-muted border-top" style="font-size: 0.8rem; line-height: 1.5;">
                    <div class="mb-1">
                        <i class="bi bi-info-circle me-1 text-primary"></i><strong>Skema Pembagian:</strong>
                    </div>
                    <div class="ms-3">
                        Net Profit dipotong terlebih dahulu dengan <strong>Klaim Bulanan</strong> (kalau ada), baru sisanya dipotong <strong>Admin Fee <?= $persen_admin ?? 3 ?>%</strong>.
                        Setelah Admin Fee dipotong, sisa laba dibagi secara <strong>50% untuk Investor</strong> dan <strong>50% untuk Pengelola</strong>.
                    </div>
                </div>
                <div class="p-3 border-top d-flex justify-content-end">
                    <button type="button" id="btnSimpanRevenueSharing" class="btn btn-sm btn-outline-primary fw-semibold">
                        <i class="bi bi-save me-1"></i>Simpan Revenue Sharing
                    </button>
                </div>
            </div>
        </div>
    </div>
    <script>
    // Dipakai bareng oleh tombol "Simpan Revenue Sharing" (kartu Kontrak
    // Pembagian Hasil) DAN "Simpan Service Fee" (kartu Koreksi Dividen: Sisi
    // Pengelola) — dua titik masuk, satu aksi: simpan admin_fee + persen/
    // nominal_service_fee (sesuai pgl_admin_persen SAAT ini) ke revenue_sharing.
    function simpanRevenueSharing(btn) {
        const persenEl = document.getElementById('pgl_admin_persen');
        const persen = persenEl ? parseFloat(persenEl.value) : 3;
        const asalHtml = btn.innerHTML;

        const fd = new FormData();
        fd.append('csrf', <?= json_encode(csrf_token()) ?>);
        fd.append('aksi', 'simpan_dari_rekap');
        fd.append('id_cabang', <?= (int) $id_cabang ?>);
        fd.append('tahun', <?= (int) $tahun ?>);
        fd.append('bulan', <?= (int) $bulan ?>);
        fd.append('urutan_pengelola', <?= (int) $urutan_pengelola_aktif ?>);
        fd.append('admin_fee', typeof RK_adminFee === 'number' ? RK_adminFee : 0);
        fd.append('persen_service_fee', persen);
        fd.append('nominal_service_fee', typeof RK_serviceFee === 'number' ? RK_serviceFee : 0);

        btn.disabled = true;
        fetch('revenue_sharing_handler.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                btn.disabled = false;
                if (!data.ok) { alert('Gagal: ' + (data.msg || 'unknown')); return; }
                btn.innerHTML = '<i class="bi bi-check2 me-1"></i>Tersimpan';
                setTimeout(function () { btn.innerHTML = asalHtml; }, 1500);
            })
            .catch(function (err) {
                btn.disabled = false;
                alert('Gagal mengirim: ' + err);
            });
    }

    (function () {
        const btn = document.getElementById('btnSimpanRevenueSharing');
        if (btn) btn.addEventListener('click', function () { simpanRevenueSharing(btn); });
    })();
    </script>
</div>

<?php include '_rekap_klaim_bulanan.php'; ?>

<!-- 5. Profit Investor & Pengelola Manual Panel -->
<div class="row g-3 mb-4">
    <!-- Investor Form Card -->
    <div class="col-lg-6">
        <div class="card border-0 border-top border-4 border-primary h-100">
            <div class="card-header bg-white border-bottom py-3">
                <span class="fw-bold text-primary">
                    <i class="bi bi-pencil-square me-2"></i>Koreksi Dividen: Sisi Investor
                </span>
            </div>
            <div class="card-body p-4 d-flex flex-column justify-content-between">
                <div class="row g-3 mb-3">
                    <div class="col-sm-6">
                        <label class="form-label text-muted small fw-semibold">Profit Investor</label>
                        <div class="form-control border-2 bg-light d-flex align-items-center" style="border-radius: 8px; height: 38px;">
                            <span id="inv_profit_val" class="fw-bold text-primary">Rp <?= number_format($share_investor ?? 0, 0, ',', '.') ?></span>
                            <!-- Raw Value untuk JS -->
                            <input type="hidden" id="inv_profit" value="<?= (float)($share_investor ?? 0) ?>">
                        </div>
                    </div>

                    <div class="col-sm-6">
                        <label class="form-label text-muted small fw-semibold">Sewa Ruko</label>
                        <div class="input-group">
                            <select id="inv_sewa_operator" class="form-select border-2" style="max-width: 70px; border-radius: 8px 0 0 8px;" onchange="hitungCascade()">
                                <option value="minus" selected>−</option>
                                <option value="plus">+</option>
                            </select>
                            <input type="number" id="inv_sewa" class="form-control border-2 bg-light" style="border-radius: 0 8px 8px 0;" value="<?= $bo_db['sewa'] ?? 0 ?>" readonly>
                        </div>
                    </div>

                    <div class="col-sm-6">
                        <label class="form-label text-muted small fw-semibold">
                            Pengembalian Dana Talangan
                            <i class="bi bi-info-circle text-muted" title="Otomatis dari total baris &quot;Dana Investor&quot; di Klaim Bulanan — tidak bisa diisi manual di sini."></i>
                        </label>
                        <div class="form-control border-2 bg-light d-flex align-items-center" style="border-radius: 8px; height: 38px;">
                            <span id="inv_modal_val" class="fw-bold text-primary">Rp <?= number_format($total_klaim_dana_investor ?? 0, 0, ',', '.') ?></span>
                            <input type="hidden" id="inv_modal" value="<?= (float) ($total_klaim_dana_investor ?? 0) ?>">
                        </div>
                    </div>

                    <div class="col-sm-6">
                        <label class="form-label text-muted small fw-semibold">Kasbon Pengelola</label>
                        <div class="input-group">
                            <input type="number" id="inv_kasbon" class="form-control border-2" style="border-radius: 8px 0 0 8px;" value="0" min="0" oninput="hitungCascade()">
                            <select id="inv_kasbon_sumber" class="form-select border-2" style="max-width: 120px; border-radius: 0 8px 8px 0;" title="Sumber Kasbon — siapa yang menalangi kasbon ini" onchange="hitungCascade()">
                                <option value="investor" selected>Dana Investor</option>
                                <option value="pusat">Dana Pusat</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-center bg-primary bg-opacity-10 p-3 rounded-3 mt-auto border border-primary border-opacity-10">
                    <span class="fw-bold text-primary small">TOTAL BERSIH INVESTOR:</span>
                    <h4 class="fw-bold text-primary mb-0" id="inv_total">Rp 0</h4>
                </div>
            </div>
        </div>
    </div>

    <!-- Pengelola Form Card -->
   <div class="col-lg-6">
    <div class="card border-0 border-top border-4 border-success h-100">
        <div class="card-header bg-white border-bottom py-3">
            <span class="fw-bold text-success">
                <i class="bi bi-pencil-square me-2"></i>Koreksi Dividen: Sisi Pengelola
            </span>
        </div>
        <div class="card-body p-4 d-flex flex-column justify-content-between">
            <div class="row g-3 mb-3">
                <div class="col-sm-6">
                    <label class="form-label text-muted small fw-semibold">Profit Pengelola</label>
                    <input type="text" id="pgl_profit_display" class="form-control border-2 bg-light fw-bold text-success" style="border-radius: 8px;" value="Rp <?= number_format($share_pengelola ?? 0, 0, ',', '.') ?>" readonly>
                    <!-- Raw Value untuk JS -->
                    <input type="hidden" id="pgl_profit" value="<?= (float)($share_pengelola ?? 0) ?>">
                </div>

                <div class="col-sm-6">
                    <label class="form-label text-muted small fw-semibold">Service Fee</label>
                    <select id="pgl_admin_persen" class="form-select border-2" style="border-radius: 8px;" onchange="hitungPengelola()">
                        <option value="7.5">7,5%</option>
                        <option value="5">5%</option>
                        <option value="3" selected>3%</option>
                    </select>
                </div>
            </div>

            <div class="table-responsive bg-light p-3 rounded-3 border mt-auto">
                <table class="table table-sm table-borderless align-middle mb-0 text-center text-nowrap" style="font-size: 0.85rem;">
                    <thead>
                        <tr class="text-secondary border-bottom">
                            <th class="pb-2 fw-semibold">Net Profit</th>
                            <th class="pb-2 fw-semibold">Service Fee</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="fw-bold text-dark pt-2">
                            <td class="pt-2"><span id="pgl_total_profit" class="text-success">Rp 0</span></td>
                            <td class="pt-2"><span id="pgl_total_admin" class="text-dark">Rp 0</span></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="d-flex justify-content-end mt-3">
                <button type="button" id="btnSimpanServiceFee" class="btn btn-sm btn-outline-success fw-semibold" onclick="simpanRevenueSharing(this)">
                    <i class="bi bi-save me-1"></i>Simpan Service Fee
                </button>
            </div>
        </div>
    </div>
</div>
</div>
<!-- 6. Rekapan Hasil Keseluruhan Keuntungan Final -->
<div class="card border-0 mb-5" style="overflow: hidden;">
    <div class="card-header bg-danger text-white py-3 d-flex align-items-center justify-content-between" style="background-color: #dc3545 !important;">
        <span class="fw-bold"><i class="bi bi-wallet2 me-2"></i>7. Rekapan Hasil Akhir Keuntungan (Distribusi Payroll)</span>
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
                        <td class="px-4 fw-semibold text-dark"><?= isset($cabang_info['investor']) ? htmlspecialchars($cabang_info['investor'], ENT_QUOTES, 'UTF-8') : '-' ?></td>
                        <td><span class="badge bg-primary bg-opacity-10 text-primary px-2.5 py-1.5 fw-bold" style="font-size: 0.75rem;">Investor Cabang</span></td>
                        <td class="font-monospace text-secondary" style="font-size: 0.85rem; letter-spacing: 0.5px;"><?= isset($cabang_info['no_rekening']) ? htmlspecialchars($cabang_info['no_rekening'], ENT_QUOTES, 'UTF-8') : '-' ?></td>
                        <td class="fw-medium text-dark"><?= isset($cabang_info['atas_nama_rekening']) ? htmlspecialchars($cabang_info['atas_nama_rekening'], ENT_QUOTES, 'UTF-8') : '-' ?></td>
                        <td><span class="badge bg-light text-dark border px-2.5 py-1 fw-medium"><?= isset($cabang_info['nama_bank']) ? htmlspecialchars($cabang_info['nama_bank'], ENT_QUOTES, 'UTF-8') : '-' ?></span></td>
                        <td class="text-end px-4 fw-bold text-primary"><span id="final_inv" class="fs-6">Rp 0</span></td>
                    </tr>
                    <tr>
                        <td class="px-4 fw-semibold text-dark"><?= isset($pengelola['nama_pengelola']) ? htmlspecialchars($pengelola['nama_pengelola'], ENT_QUOTES, 'UTF-8') : '-' ?></td>
                        <td><span class="badge bg-success bg-opacity-10 text-success px-2.5 py-1.5 fw-bold" style="font-size: 0.75rem;">Pengelola Cabang</span></td>
                        <td class="font-monospace text-secondary" style="font-size: 0.85rem; letter-spacing: 0.5px;"><?= isset($pengelola['no_rekening']) ? htmlspecialchars($pengelola['no_rekening'], ENT_QUOTES, 'UTF-8') : '-' ?></td>
                        <td class="fw-medium text-dark"><?= isset($pengelola['atas_nama_rekening']) ? htmlspecialchars($pengelola['atas_nama_rekening'], ENT_QUOTES, 'UTF-8') : '-' ?></td>
                        <td><span class="badge bg-light text-dark border px-2.5 py-1 fw-medium"><?= isset($pengelola['nama_bank']) ? htmlspecialchars($pengelola['nama_bank'], ENT_QUOTES, 'UTF-8') : '-' ?></span></td>
                        <td class="text-end px-4 fw-bold text-success"><span id="final_pgl" class="fs-6">Rp 0</span></td>
                    </tr>
                    <tr class="table-light">
                        <td class="px-4 fw-semibold text-dark">Admin Management Pusat</td>
                        <td><span class="badge bg-danger bg-opacity-10 text-danger px-2.5 py-1.5 fw-bold" style="font-size: 0.75rem;">Internal Admin</span></td>
                        <td class="font-monospace text-muted" style="font-size: 0.85rem; letter-spacing: 0.5px;">1662598199</td>
                        <td class="fw-medium text-dark">WARDOYO</td>
                        <td><span class="badge bg-light text-dark border px-2.5 py-1 fw-medium">BCA</span></td>
                        <td class="text-end px-4 fw-bold text-danger">
                            <span id="final_admin" class="fs-6">Rp <?= number_format(($share_admin ?? 0) + ($total_klaim_dana_pusat ?? 0), 0, ',', '.') ?></span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Tombol Export -->
<?php if (($jumlah_hari_data ?? 0) > 0): ?>
    <div class="row g-3 mt-4 mb-5">
        <div class="col-md-6">
            <div class="d-flex gap-2 mb-2">
                <button onclick="exportPdfHarian('save')" class="btn btn-success flex-fill py-3 fw-semibold" style="border-radius: 10px; font-size: 1rem;">
                    <i class="bi bi-file-earmark-pdf me-2"></i>Export PDF Harian
                </button>
                <button onclick="exportPdfHarian('share')" class="btn btn-outline-success py-3 fw-semibold px-3" style="border-radius: 10px;" title="Bagikan ke WhatsApp">
                    <i class="bi bi-whatsapp"></i>
                </button>
            </div>
            <button onclick="exportExcelHarian()" class="btn btn-outline-success w-100 py-2 fw-semibold" style="border-radius: 10px;">
                <i class="bi bi-file-earmark-excel me-2"></i>Export Excel Harian
            </button>
        </div>
        <div class="col-md-6">
            <div class="d-flex gap-2 mb-2">
                <button onclick="exportPDF('save')" class="btn btn-danger flex-fill py-3 fw-semibold" style="border-radius: 10px; font-size: 1rem;">
                    <i class="bi bi-file-earmark-pdf me-2"></i>Export PDF
                </button>
                <button onclick="exportPDF('share')" class="btn btn-outline-danger py-3 fw-semibold px-3" style="border-radius: 10px;" title="Bagikan ke WhatsApp">
                    <i class="bi bi-whatsapp"></i>
                </button>
            </div>
            <button onclick="exportExcel()" class="btn btn-outline-danger w-100 py-2 fw-semibold" style="border-radius: 10px;">
                <i class="bi bi-file-earmark-excel me-2"></i>Export Excel
            </button>
        </div>
    </div>
<?php endif; ?>

<?php endif; ?>
</div>


<!-- Library Export -->
<script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jspdf-autotable@3.8.3/dist/jspdf.plugin.autotable.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>

        <?php include __DIR__ . '/_rekap_script_matrix.php'; ?>

        <?php if (!empty($id_cabang) && ($jumlah_hari_data ?? 0) > 0): ?>
        <?php include __DIR__ . '/_rekap_script_export.php'; ?>
        <?php endif; ?>