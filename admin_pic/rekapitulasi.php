<?php
require '../config/koneksi.php';
require_role('pic');
include 'sidebar.php';

$id_user = current_user_id();
$cabang_ids_pic = pic_cabang_ids($conn, $id_user);

$periode = $_GET['periode'] ?? 'bulanan';
$tahun = (int) ($_GET['tahun'] ?? date('Y'));
$bulan = (int) ($_GET['bulan'] ?? date('m'));
if ($tahun < 2000 || $tahun > 2100) $tahun = (int) date('Y');
if ($bulan < 1 || $bulan > 12)      $bulan = (int) date('m');
$bulan = str_pad((string) $bulan, 2, '0', STR_PAD_LEFT);
$id_cabang = $_GET['id_cabang'] ?? '';

// Tanggal acuan untuk atribusi pengelola/investor historis — awal periode yang
// dipilih, BUKAN hari ini. Supaya rekap bulan lama tetap benar walau sudah
// ada rotasi pengelola/investor sesudahnya.
$periode_anchor = anchor_periode(date('Y-m-t', strtotime("$tahun-$bulan-01")));

// Wajib: cabang yang diminta harus salah satu yang dipegang PIC ini.
if ($id_cabang !== '' && !in_array((int) $id_cabang, $cabang_ids_pic, true)) {
    $id_cabang = '';
}

// Ambil nama cabang yang kepilih biar input keisi
$nama_cabang_terpilih = '';
if ($id_cabang != '') {
    $stmt = $conn->prepare("SELECT nama_cabang FROM cabang WHERE id_cabang=?");
    $stmt->bind_param("i", $id_cabang);
    $stmt->execute();
    $nama_cabang_terpilih = $stmt->get_result()->fetch_assoc()['nama_cabang'] ?? '';
}

// Daftar cabang untuk dropdown — HANYA cabang yang dipegang PIC ini.
$list_cabang = null;
if (!empty($cabang_ids_pic)) {
    $ph = implode(',', array_fill(0, count($cabang_ids_pic), '?'));
    $stmt_lc = $conn->prepare("SELECT id_cabang, nama_cabang FROM cabang WHERE id_cabang IN ($ph) ORDER BY nama_cabang");
    $stmt_lc->bind_param(str_repeat('i', count($cabang_ids_pic)), ...$cabang_ids_pic);
    $stmt_lc->execute();
    $list_cabang = $stmt_lc->get_result();
}

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
    $where_sql = "WHERE l.status_laporan = 'lengkap' AND YEAR(l.tanggal)=? AND MONTH(l.tanggal)=?";
    $params[] = $tahun;
    $params[] = $bulan;
    $types .= "ii";
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

// Perhitungan Laba Default (Sebelum pilihan dinamis di UI)
$share_admin = $laba_bersih_dasar * $persen_admin / 100;
$laba_setelah_admin = $laba_bersih_dasar - $share_admin;
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
    // Pengelola PADA PERIODE yang dipilih — bukan yang aktif sekarang.
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
        WHERE lc.id_cabang = ? AND YEAR(lc.tanggal) = ? AND MONTH(lc.tanggal) = ?
          AND lc.status_laporan = 'lengkap' AND lc.id_user_laporan IS NOT NULL
    ");
    $stmt->bind_param("iii", $id_cabang, $tahun, $bulan);
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

// Format nama file export
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
        <p class="text-secondary small mb-0">Halaman rekapitulasi performa finansial, rasio bagi hasil, serta perhitungan biaya operasional &mdash; cabang yang Anda pegang.</p>
    </div>

    <?php if (empty($cabang_ids_pic)): ?>
        <div class="alert alert-warning rounded-4"><i class="bi bi-exclamation-triangle-fill me-2"></i> Anda belum ditugaskan ke cabang manapun. Hubungi Admin Pusat.</div>
    <?php endif; ?>

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
                    <?php if ($list_cabang): $list_cabang->data_seek(0);
                    while ($c = $list_cabang->fetch_assoc()): ?>
                        <option value="<?= h($c['nama_cabang']) ?>" data-id="<?= $c['id_cabang'] ?>"></option>
                    <?php endwhile; endif; ?>
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
                <span class="badge bg-light text-dark fw-medium px-3 py-1.5 rounded-pill">Detail per tanggal</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <?php
                    $rk_th = (int) $tahun;
                    $rk_bl = (int) $bulan;
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

        <!-- (tersembunyi) tabel harian BULAN SEBELUMNYA — sumber Export PDF Harian -->
        <?php
        $rk_prev    = strtotime(sprintf('%04d-%02d-01 -1 month', (int) $tahun, (int) $bulan));
        $bulan_prev = (int) date('n', $rk_prev);
        $tahun_prev = (int) date('Y', $rk_prev);
        ?>
        <div aria-hidden="true" style="position:absolute; left:-99999px; top:0; width:1600px; pointer-events:none;">
            <?php
            $rk_th = $tahun_prev;
            $rk_bl = $bulan_prev;
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
    </div>
</div>

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
                        Net Profit dipotong terlebih dahulu dengan <strong>Admin Fee <?= $persen_admin ?? 3 ?>%</strong>. 
                        Setelah Admin Fee dipotong, sisa laba dibagi secara <strong>50% untuk Investor</strong> dan <strong>50% untuk Pengelola</strong>.
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
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
                        <label class="form-label text-muted small fw-semibold">Asal Dana Talangan</label>
                        <select id="inv_sumber_talangan" class="form-select border-2" style="border-radius: 8px;" onchange="hitungCascade()">
                            <option value="investor" selected>Dana Investor</option>
                            <option value="warung">Dana Warung</option>
                        </select>
                    </div>

                    <div class="col-sm-6">
                        <label class="form-label text-muted small fw-semibold">Pengembalian Dana Talangan</label>
                        <input type="number" id="inv_modal" class="form-control border-2" style="border-radius: 8px;" value="0" min="0" oninput="hitungCascade()">
                    </div>

                    <div class="col-sm-6">
                        <label class="form-label text-muted small fw-semibold">Kasbon Pengelola</label>
                        <input type="number" id="inv_kasbon" class="form-control border-2" style="border-radius: 8px;" value="0" min="0" oninput="hitungCascade()">
                    </div>

                    <div class="col-sm-6">
                        <label class="form-label text-muted small fw-semibold">Keterangan Dana Talangan</label>
                        <input type="text" id="inv_modal_ket" class="form-control border-2" style="border-radius: 8px;" placeholder="Opsional...">
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
        </div>
    </div>
</div>
</div>
<!-- 6. Rekapan Hasil Keseluruhan Keuntungan Final -->
<div class="card border-0 mb-5" style="overflow: hidden;">
    <div class="card-header bg-danger text-white py-3 d-flex align-items-center justify-content-between" style="background-color: #dc3545 !important;">
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
                            <span id="final_admin" class="fs-6">Rp <?= number_format($share_admin ?? 0, 0, ',', '.') ?></span>
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