<?php
require '../vendor/autoload.php';
require '../config/koneksi.php';
include 'sidebar_pusat.php';

// 1. PROTEKSI ROLE PUSAT
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'pusat') {
    header("Location:../login");
    exit;
}

$periode = $_GET['periode'] ?? 'bulanan';
$tahun = $_GET['tahun'] ?? date('Y');
$bulan = $_GET['bulan'] ?? date('m');
$id_cabang = $_GET['id_cabang'] ?? '';

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

if ($periode == 'mingguan') {
    $where_sql = "WHERE YEAR(l.tanggal)=? AND WEEK(l.tanggal,1) = WEEK(CURDATE(),1)";
    $params[] = $tahun;
    $types .= "i";
    $judul = "Rekap Mingguan - Minggu " . date('W');
} elseif ($periode == 'tahunan') {
    $where_sql = "WHERE YEAR(l.tanggal)=?";
    $params[] = $tahun;
    $types .= "i";
    $judul = "Rekap Tahunan - Tahun $tahun";
} else {
    $where_sql = "WHERE YEAR(l.tanggal)=? AND MONTH(l.tanggal)=?";
    $params[] = $tahun;
    $params[] = $bulan;
    $types .= "ii";
    $judul = "Rekap Bulanan - " . date('F Y', strtotime("$tahun-$bulan-01"));
}

// Filter cabang
$cabang_info = ['investor' => '-', 'no_rekening' => '-', 'nama_bank' => '-', "atas_nama_rekening" => "-"];
$nama_cabang = "Semua Cabang";
if ($id_cabang != '') {
    $where_sql .= " AND l.id_cabang = ?";
    $params[] = (int)$id_cabang;
    $types .= "i";

    $stmt = $conn->prepare("
    SELECT 
        c.nama_cabang,
        IFNULL(i.nama_investor, '-') AS investor,
        IFNULL(i.atas_nama_rekening, '-') AS atas_nama_rekening,
        IFNULL(i.no_rekening, '-') AS no_rekening,
        IFNULL(i.nama_bank, '-') AS nama_bank
    FROM cabang c
    LEFT JOIN cabang_investor ci 
        ON ci.id_cabang = c.id_cabang
    LEFT JOIN investor i 
        ON ci.id_investor = i.id_investor
    WHERE c.id_cabang = ?
");
    $stmt->bind_param("i", $id_cabang);
    $stmt->execute();
    $ass = $stmt->get_result()->fetch_assoc();
    $cabang_info = $ass ?? $cabang_info;
    $nama_cabang = $cabang_info['nama_cabang'] ?? "Semua Cabang";
    $judul .= " - " . $nama_cabang;
}

// 3. QUERY DATA UTAMA PAKAI PREPARED
$query = "SELECT c.nama_cabang, i.nama_investor as investor, i.no_rekening, c.nama_bank,
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
          LEFT JOIN investor i ON i.id_investor = c.id_investor
          $where_sql
          GROUP BY l.id_cabang";

$stmt = $conn->prepare($query);
if (!empty($params)) $stmt->bind_param($types, ...$params);
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
    $stmt = $conn->prepare("
        SELECT
            nama_pengelola,
            no_rekening,
            nama_bank,
            atas_nama_rekening
        FROM users
        WHERE id_cabang=?
        AND role='cabang'
        LIMIT 1
    ");

    $stmt->bind_param("i", $id_cabang);
    $stmt->execute();
    $pengelola = $stmt->get_result()->fetch_assoc() ?? $pengelola;
}

// Format nama file export
$nama_file_export = "Rekap Bulanan_" . str_replace(' ', '_', $nama_cabang) . "_" . $tahun . $bulan;
?>

<!-- HTML / JS PERHITUNGAN DINAMIS & EKSPOR PDF -->
<script>
    // Global variable basis laba bersih dasar
    const labaBersihDasar = <?= (float)$laba_bersih_dasar ?>;

    // Helper Parse Angka: Kebal terhadap format Rupiah ("Rp 412.973" -> 412973)
    function parseAngka(val) {
        if (typeof val === 'number') return isNaN(val) ? 0 : val;
        if (!val) return 0;
        let str = String(val)
            .replace(/[^0-9,.-]+/g, "")
            .replace(/\./g, "")
            .replace(",", ".");
        let num = parseFloat(str);
        return isNaN(num) ? 0 : num;
    }

    function formatRupiah(angka) {
        return 'Rp ' + Math.round(angka || 0).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ".");
    }

    // Script untuk datalist cabang -> hidden ID
    const inputCabang = document.getElementById('inputCabang');
    const idCabang = document.getElementById('idCabang');
    const datalist = document.getElementById('listCabang');

    if (inputCabang) {
        inputCabang.addEventListener('input', function() {
            let val = this.value;
            let options = datalist.querySelectorAll('option');
            let found = false;

            options.forEach(opt => {
                if (opt.value === val) {
                    idCabang.value = opt.getAttribute('data-id');
                    found = true;
                }
            });

            if (!found && val !== '') {
                idCabang.value = '';
            }
        });
    }

    const formFilter = document.getElementById('formFilter');
    if (formFilter) {
        formFilter.addEventListener('submit', function(e) {
            if (idCabang.value === '') {
                e.preventDefault();
                alert('Pilih cabang dari daftar, jangan ketik manual yang tidak ada di list!');
                inputCabang.focus();
            }
        });
    }

    // =========================================================
    // HITUNG BEBAN OPERASIONAL
    // =========================================================
    function hitungBO() {
        let totalBO = 0;

        document.querySelectorAll('.jumlah').forEach(function(el) {
            let no = el.dataset.no;
            let harian = parseFloat(document.querySelector('.harian[data-no="' + no + '"]')?.value) || 0;
            let bulanan = parseFloat(document.querySelector('.bulanan[data-no="' + no + '"]')?.value) || 0;
            let tahunan = parseFloat(document.querySelector('.tahunan[data-no="' + no + '"]')?.value) || 0;
            let dibayarkan = parseFloat(document.querySelector('.dibayarkan[data-no="' + no + '"]')?.value) || 0;
            let jumlah = harian + bulanan + tahunan - dibayarkan;
            el.innerText = formatRupiah(jumlah);
            totalBO += jumlah;
        });

        if (document.getElementById('total_bo')) document.getElementById('total_bo').innerText = formatRupiah(totalBO);
        if (document.getElementById('bo_akumulasi')) document.getElementById('bo_akumulasi').innerText = formatRupiah(totalBO);

        // Jalankan kalkulasi investor & pengelola
        hitungInvestor();
    }

    // =========================================================
    // UPDATE REKAP AKHIR (MANAGEMENT PUSAT 3% + SERVICE FEE)
    // =========================================================
    function updateFinalRekap(serviceFee = 0) {
        // 1. Ambil Total Net Investor
        let final_inv = parseAngka(document.getElementById('inv_total')?.innerText || 0);

        // 2. Ambil Total Net Pengelola
        let final_pgl = parseAngka(document.getElementById('pgl_total_profit')?.innerText || 0);

        // 3. HITUNG MANAGEMENT PUSAT 3% DARI NET PROFIT (SESUAI TABEL POIN 5)
        const persenAdmin = parseFloat("<?= floatval($persen_admin ?? 3) ?>") || 3;
        
        // Komponen 1: Management Pusat 3% dari Net Profit Dasar
        let admin3Persen = (labaBersihDasar * persenAdmin) / 100;
        
        // Komponen 2: Service Fee dari Pengelola (dioper dari hitungPengelola)
        
        // TOTAL AKHIR ADMIN PUSAT = 3% Net Profit + Service Fee Pengelola
        let totalAdminGabungan = admin3Persen + serviceFee;

        // Render ke Tampilan
        if (document.getElementById('final_inv')) {
            document.getElementById('final_inv').innerText = formatRupiah(final_inv);
        }
        if (document.getElementById('final_pgl')) {
            document.getElementById('final_pgl').innerText = formatRupiah(final_pgl);
        }
        if (document.getElementById('final_admin')) {
            // Gabungan 2 Keuntungan Admin Pusat
            document.getElementById('final_admin').innerText = formatRupiah(totalAdminGabungan);
        }
    }

    // =========================================================
    // KOREKSI DIVIDEN - INVESTOR
    // =========================================================
    function hitungInvestor() {
        let profit = parseFloat(document.getElementById('inv_profit')?.value) || 0;
        let sewa = parseFloat(document.getElementById('inv_sewa')?.value) || 0;
        let modal = parseFloat(document.getElementById('inv_modal')?.value) || 0;
        let kasbon = parseFloat(document.getElementById('inv_kasbon')?.value) || 0;
        let operatorSewa = document.getElementById('inv_sewa_operator')?.value || 'minus';

        let total = profit;

        if (operatorSewa === 'plus') {
            total += sewa;
        } else {
            total -= sewa;
        }

        total += modal;
        total += kasbon;
        total = Math.max(0, total);

        const invTotal = document.getElementById('inv_total');
        if (invTotal) {
            invTotal.innerText = formatRupiah(total);
        }

        hitungPengelola();
    }

    // =========================================================
    // KOREKSI DIVIDEN - PENGELOLA
    // =========================================================
    function hitungPengelola() {
        let profit = parseFloat(document.getElementById('pgl_profit')?.value) || 0;
        let adminPersen = parseFloat(document.getElementById('pgl_admin_persen')?.value) || 0;
        let kasbon = parseFloat(document.getElementById('inv_kasbon')?.value) || 0;

        // Service Fee yang dipotong dari Pengelola (3%, 5%, atau 7.5%)
        let serviceFee = (profit * adminPersen) / 100;

        // Net Profit Pengelola setelah dipotong Service Fee & Kasbon
        let profitBersih = profit - serviceFee - kasbon;
        profitBersih = Math.max(0, profitBersih);

        const totalProfit = document.getElementById('pgl_total_profit');
        if (totalProfit) {
            totalProfit.innerText = formatRupiah(profitBersih);
        }

        const totalAdmin = document.getElementById('pgl_total_admin');
        if (totalAdmin) {
            totalAdmin.innerText = formatRupiah(serviceFee);
        }

        // Kirim Service Fee ke Rekap Akhir untuk dijumlahkan dengan Management Pusat 3%
        updateFinalRekap(serviceFee);
    }

    // =========================================================
    // JALANKAN SAAT HALAMAN SELESAI DIMUAT
    // =========================================================
    document.addEventListener('DOMContentLoaded', function() {
        hitungBO();
    });
</script>

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
                        <input list="listCabang" id="inputCabang" class="form-control form-control-premium border-start-0" style="border-radius: 0 8px 8px 0!important;" placeholder="Ketik nama cabang..." value="<?= h($nama_cabang_terpilih) ?>" autocomplete="off" required>
                    </div>
                    <input type="hidden" name="id_cabang" id="idCabang" value="<?= h($id_cabang) ?>">

                    <datalist id="listCabang">
                        <?php $list_cabang->data_seek(0);
                        while ($c = $list_cabang->fetch_assoc()): ?>
                            <option value="<?= h($c['nama_cabang']) ?>" data-id="<?= $c['id_cabang'] ?>"></option>
                        <?php endwhile; ?>
                    </datalist>
                </div>
                <div class="col-xl-2 col-md-6">
                    <label class="form-label-sm">Periode Analisis</label>
                    <select name="periode" class="form-select form-control-premium" onchange="this.form.submit()">
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
                    <select name="bulan" class="form-select form-control-premium" <?= $periode == 'tahunan' ? 'disabled' : '' ?>>
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

    <!-- Pengecekan Diperbaiki menggunakan empty() -->
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
                            <span class="text-muted small text-uppercase fw-semibold" style="font-size: 0.75rem;">Total Penjualan</span>
                            <h5 class="fw-bold text-primary mb-0 mt-1">Rp <?= number_format($penjualan, 0, ',', '.') ?></h5>
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
                            <h5 class="fw-bold text-secondary mb-0 mt-1">Rp <?= number_format($pengeluaran, 0, ',', '.') ?></h5>
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
                            <h5 class="fw-bold text-success mb-0 mt-1">Rp <?= number_format($laba_bersih, 0, ',', '.') ?> <span class="fs-6 text-muted fw-normal">(<?= number_format($margin, 2) ?>%)</span></h5>
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
                <span class="fw-bold"><i class="bi bi-calendar3 me-2"></i>1. Rekapitulasi Pendapatan & Pengeluaran Harian - <?= date('F Y', strtotime("$tahun-$bulan-01")) ?></span>
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
                            $query_harian = "SELECT l.tanggal, l.tunai, l.qris, l.go_food, l.grab_food, 
                    l.total_omset, l.total_pengeluaran, 
                    l.belanja_pasar, l.belanja_beras, l.belanja_sembako, l.belanja_toko,
                    l.sewa, l.gaji, l.listrik, l.air, l.sampah, l.keamanan, l.internet, l.gas, l.mingguan_karyawan, l.es_batu, l.bensin, l.lain_lain,
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
                            $total_sewa = $total_gaji = $total_lain_bo = 0;
                            $total_pengeluaran = $total_sisa_tunai = $total_laba = 0;
                            $no = 1;

                            if ($data_harian->num_rows > 0):
                                while ($h = $data_harian->fetch_assoc()):
                                    $tunai         = (float)($h['tunai'] ?? 0);
                                    $qris          = (float)($h['qris'] ?? 0);
                                    $go_food       = (float)($h['go_food'] ?? 0);
                                    $grab_food     = (float)($h['grab_food'] ?? 0);
                                    $omzet         = (float)($h['total_omset'] ?? 0);
                                    $pasar         = (float)($h['belanja_pasar'] ?? 0);
                                    $beras         = (float)($h['belanja_beras'] ?? 0);
                                    $sembako       = (float)($h['belanja_sembako'] ?? 0);
                                    $toko          = (float)($h['belanja_toko'] ?? 0);
                                    $sewa          = (float)($h['sewa'] ?? 0);
                                    $gaji          = (float)($h['gaji'] ?? 0);
                                    $laba_harian   = (float)($h['net_profit'] ?? 0);
                                    $persentase    = (float)($h['persentase'] ?? 0);

                                    $lain_lain_operasional = (float)($h['listrik'] ?? 0)
                                        + (float)($h['air'] ?? 0)
                                        + (float)($h['sampah'] ?? 0)
                                        + (float)($h['keamanan'] ?? 0)
                                        + (float)($h['internet'] ?? 0)
                                        + (float)($h['gas'] ?? 0)
                                        + (float)($h['mingguan_karyawan'] ?? 0)
                                        + (float)($h['es_batu'] ?? 0)
                                        + (float)($h['bensin'] ?? 0)
                                        + (float)($h['lain_lain'] ?? 0);

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
                                    $total_lain_bo += $lain_lain_operasional;
                                    $total_laba += $laba_harian;
                                    $total_pengeluaran += $total_pengeluaran_harian;
                                    $total_sisa_tunai += $sisa_tunai_harian;
                            ?>
                                    <tr>
                                        <td class="text-center text-muted"><?= $no++ ?></td>
                                        <td class="fw-medium"><?= date('d/m/Y', strtotime($h['tanggal'])) ?></td>
                                        <td class="text-end"><?= number_format($tunai, 0, ',', '.') ?></td>
                                        <td class="text-end"><?= $qris > 0 ? number_format($qris, 0, ',', '.') : '-' ?></td>
                                        <td class="text-end"><?= $go_food > 0 ? number_format($go_food, 0, ',', '.') : '-' ?></td>
                                        <td class="text-end"><?= $grab_food > 0 ? number_format($grab_food, 0, ',', '.') : '-' ?></td>
                                        <td class="text-end fw-semibold"><?= number_format($omzet, 0, ',', '.') ?></td>
                                        <td class="text-end"><?= number_format($pasar, 0, ',', '.') ?></td>
                                        <td class="text-end"><?= number_format($beras, 0, ',', '.') ?></td>
                                        <td class="text-end"><?= number_format($sembako, 0, ',', '.') ?></td>
                                        <td class="text-end"><?= number_format($toko, 0, ',', '.') ?></td>
                                        <td class="text-end"><?= number_format($sewa, 0, ',', '.') ?></td>
                                        <td class="text-end"><?= number_format($gaji, 0, ',', '.') ?></td>
                                        <td class="text-end"><?= number_format($lain_lain_operasional, 0, ',', '.') ?></td>
                                        <td class="text-end fw-semibold text-danger"><?= number_format($total_pengeluaran_harian, 0, ',', '.') ?></td>
                                        <td class="text-end fw-bold text-success"><?= number_format($laba_harian, 0, ',', '.') ?></td>
                                        <td class="text-center fw-semibold"><?= number_format($persentase, 2) ?>%</td>
                                    </tr>
                                <?php
                                endwhile;
                                $margin_total = $total_omzet > 0 ? ($total_laba / $total_omzet) * 100 : 0;
                            else: ?>
                                <tr>
                                    <td colspan="17" class="text-center text-muted py-5">
                                        <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                                        Belum ada data laporan untuk periode ini
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                        <?php if ($data_harian->num_rows > 0): ?>
                            <tfoot class="table-dark">
                                <tr class="fw-bold">
                                    <td colspan="2" class="text-center">JUMLAH</td>
                                    <td class="text-end"><?= number_format($total_tunai, 0, ',', '.') ?></td>
                                    <td class="text-end"><?= number_format($total_qris, 0, ',', '.') ?></td>
                                    <td class="text-end"><?= number_format($total_gofood, 0, ',', '.') ?></td>
                                    <td class="text-end"><?= number_format($total_grab, 0, ',', '.') ?></td>
                                    <td class="text-end"><?= number_format($total_omzet, 0, ',', '.') ?></td>
                                    <td class="text-end"><?= number_format($total_pasar, 0, ',', '.') ?></td>
                                    <td class="text-end"><?= number_format($total_beras, 0, ',', '.') ?></td>
                                    <td class="text-end"><?= number_format($total_sembako, 0, ',', '.') ?></td>
                                    <td class="text-end"><?= number_format($total_toko, 0, ',', '.') ?></td>
                                    <td class="text-end"><?= number_format($total_sewa, 0, ',', '.') ?></td>
                                    <td class="text-end"><?= number_format($total_gaji, 0, ',', '.') ?></td>
                                    <td class="text-end"><?= number_format($total_lain_bo, 0, ',', '.') ?></td>
                                    <td class="text-end text-danger"><?= number_format($total_pengeluaran, 0, ',', '.') ?></td>
                                    <td class="text-end"><?= number_format($total_laba, 0, ',', '.') ?></td>
                                    <td class="text-center"><?= number_format($margin_total, 2) ?>%</td>
                                </tr>
                            </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
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
                    // Hitung jumlah hari ada data untuk dapat rata2 harian
                    $jumlah_hari = (isset($data_harian) && $data_harian->num_rows > 0) ? $data_harian->num_rows : 1;

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
                        $modal_awal = 0;
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
                            <td class="text-end fw-bold text-warning px-3">Rp <?= number_format($modal_awal, 0, ',', '.') ?></td>
                        </tr>
                        <tr class="table-success">
                            <td class="px-3 fw-bold text-dark"><i class="bi bi-graph-up-arrow text-success me-2"></i>5. Laba Bersih</td>
                            <td class="text-end fw-bold text-success px-3" id="matrik_laba_bersih">Rp <?= number_format($laba_akumulasi, 0, ',', '.') ?></td>
                        </tr>
                    </tbody>
                </table>
                <div class="p-3 bg-light text-muted border-top" style="font-size: 0.8rem; line-height: 1.4;">
                    <i class="bi bi-info-circle me-1 text-primary"></i> Data otomatis diambil dari rekapitulasi harian bulan <?= date('F Y', strtotime("$tahun-$bulan-01")) ?>. Tidak dapat diubah manual.
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
            <div class="card-body p-4">
                <div class="row g-3">
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
                            <select id="inv_sewa_operator" class="form-select border-2" style="max-width: 70px; border-radius: 8px 0 0 8px;" onchange="hitungInvestor()">
                                <option value="minus" selected>−</option>
                                <option value="plus">+</option>
                            </select>
                            <input type="number" id="inv_sewa" class="form-control border-2 bg-light" style="border-radius: 0 8px 8px 0;" value="<?= $bo_db['sewa'] ?? 0 ?>" readonly>
                        </div>
                    </div>

                    <!-- DROPDOWN ASAL DANA TALANGAN -->
                    <div class="col-sm-6">
                        <label class="form-label text-muted small fw-semibold">Asal Dana Talangan</label>
                        <select id="inv_sumber_talangan" class="form-select border-2" style="border-radius: 8px;" onchange="hitungInvestor()">
                            <option value="investor" selected>Dana Investor</option>
                            <option value="warung">Dana Warung</option>
                        </select>
                    </div>

                    <div class="col-sm-6">
                        <label class="form-label text-muted small fw-semibold">Pengembalian Dana Talangan</label>
                        <input type="number" id="inv_modal" class="form-control border-2" style="border-radius: 8px;" value="0" oninput="hitungInvestor()">
                    </div>

                    <div class="col-sm-6">
                        <label class="form-label text-muted small fw-semibold">Kasbon Pengelola</label>
                        <input type="number" id="inv_kasbon" class="form-control border-2" style="border-radius: 8px;" value="0" min="0" oninput="hitungInvestor()">
                    </div>

                    <div class="col-sm-6">
                        <label class="form-label text-muted small fw-semibold">Potongan Admin</label>
                        <input type="number" id="inv_admin" class="form-control border-2" style="border-radius: 8px;" value="0" min="0" oninput="hitungInvestor()">
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-center bg-primary bg-opacity-10 p-3 rounded-3 mt-4 border border-primary border-opacity-10">
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
                        <td><span class="badge bg-success bg-opacity-10 text-success px-2.5 py-1.5 fw-bold" style="font-size: 0.75rem;">Pengelola Lapangan</span></td>
                        <td class="font-monospace text-secondary" style="font-size: 0.85rem; letter-spacing: 0.5px;"><?= isset($pengelola['no_rekening']) ? htmlspecialchars($pengelola['no_rekening'], ENT_QUOTES, 'UTF-8') : '-' ?></td>
                        <td class="fw-medium text-dark"><?= isset($pengelola['atas_nama_rekening']) ? htmlspecialchars($pengelola['atas_nama_rekening'], ENT_QUOTES, 'UTF-8') : '-' ?></td>
                        <td><span class="badge bg-light text-dark border px-2.5 py-1 fw-medium"><?= isset($pengelola['nama_bank']) ? htmlspecialchars($pengelola['nama_bank'], ENT_QUOTES, 'UTF-8') : '-' ?></span></td>
                        <td class="text-end px-4 fw-bold text-success"><span id="final_pgl" class="fs-6">Rp 0</span></td>
                    </tr>
                    <tr class="table-light">
                        <td class="px-4 fw-semibold text-dark">Admin Management Pusat</td>
                        <td><span class="badge bg-danger bg-opacity-10 text-danger px-2.5 py-1.5 fw-bold" style="font-size: 0.75rem;">Internal Admin</span></td>
                        <td class="font-monospace text-muted" style="font-size: 0.85rem; letter-spacing: 0.5px;">080808080</td>
                        <td class="fw-medium text-dark">PT WARTEG BUMI BAHARI</td>
                        <td><span class="badge bg-light text-dark border px-2.5 py-1 fw-medium">BRI</span></td>
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
<?php if (isset($data_harian) && $data_harian instanceof mysqli_result && $data_harian->num_rows > 0): ?>
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
            function formatRupiah(angka) {
                return 'Rp ' + Math.round(angka || 0).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ".");
            }

            // Script untuk datalist cabang -> hidden ID
            const inputCabang = document.getElementById('inputCabang');
            const idCabang = document.getElementById('idCabang');
            const datalist = document.getElementById('listCabang');

            if (inputCabang) {
                inputCabang.addEventListener('input', function() {
                    let val = this.value;
                    let options = datalist.querySelectorAll('option');
                    let found = false;

                    options.forEach(opt => {
                        if (opt.value === val) {
                            idCabang.value = opt.getAttribute('data-id');
                            found = true;
                        }
                    });

                    if (!found && val !== '') {
                        idCabang.value = '';
                    }
                });
            }

            const formFilter = document.getElementById('formFilter');
            if (formFilter) {
                formFilter.addEventListener('submit', function(e) {
                    if (idCabang.value === '') {
                        e.preventDefault();
                        alert('Pilih cabang dari daftar, jangan ketik manual yang tidak ada di list!');
                        inputCabang.focus();
                    }
                });
            }

            // =========================================================
            // HITUNG BEBAN OPERASIONAL
            // =========================================================
            function hitungBO() {
                let totalBO = 0;

                document.querySelectorAll('.jumlah').forEach(function(el) {
                    let no = el.dataset.no;
                    let harian = parseFloat(document.querySelector('.harian[data-no="' + no + '"]')?.value) || 0;
                    let bulanan = parseFloat(document.querySelector('.bulanan[data-no="' + no + '"]')?.value) || 0;
                    let tahunan = parseFloat(document.querySelector('.tahunan[data-no="' + no + '"]')?.value) || 0;
                    let dibayarkan = parseFloat(document.querySelector('.dibayarkan[data-no="' + no + '"]')?.value) || 0;
                    let jumlah = harian + bulanan + tahunan - dibayarkan;
                    el.innerText = formatRupiah(jumlah);
                    totalBO += jumlah;
                });

                if (document.getElementById('total_bo')) document.getElementById('total_bo').innerText = formatRupiah(totalBO);
                if (document.getElementById('bo_akumulasi')) document.getElementById('bo_akumulasi').innerText = formatRupiah(totalBO);

                // Jalankan kalkulasi investor & pengelola
                hitungInvestor();
            }

            // =========================================================
            // UPDATE REKAP AKHIR (MANAGEMENT PUSAT 3% + SERVICE FEE)
            // =========================================================
            function updateFinalRekap(serviceFee = 0) {
                // 1. Ambil Total Net Investor
                let final_inv = parseFloat(document.getElementById('inv_total')?.innerText.replace(/[^0-9-]/g, '') || 0);

                // 2. Ambil Total Net Pengelola
                let final_pgl = parseFloat(document.getElementById('pgl_total_profit')?.innerText.replace(/[^0-9-]/g, '') || 0);

                // 3. HITUNG MANAGEMENT PUSAT 3% DARI NET PROFIT (SESUAI TABEL POIN 5)
                const labaBersih = parseFloat("<?= floatval($laba_bersih ?? 0) ?>") || 0;
                const persenAdmin = parseFloat("<?= floatval($persen_admin ?? 3) ?>") || 3;
                
                // Komponen 1: Management Pusat 3% dari Net Profit
                let admin3Persen = (labaBersih * persenAdmin) / 100;
                
                // Komponen 2: Service Fee dari Pengelola (dioper dari hitungPengelola)
                
                // TOTAL AKHIR ADMIN PUSAT = 3% Net Profit + Service Fee Pengelola
                let totalAdminGabungan = admin3Persen + serviceFee;

                // Render ke Tampilan
                if (document.getElementById('final_inv')) {
                    document.getElementById('final_inv').innerText = formatRupiah(final_inv);
                }
                if (document.getElementById('final_pgl')) {
                    document.getElementById('final_pgl').innerText = formatRupiah(final_pgl);
                }
                if (document.getElementById('final_admin')) {
                    // Gabungan 2 Keuntungan Admin Pusat
                    document.getElementById('final_admin').innerText = formatRupiah(totalAdminGabungan);
                }
            }

            // =========================================================
            // KOREKSI DIVIDEN - INVESTOR
            // =========================================================
            function hitungInvestor() {
                let profit = parseFloat(document.getElementById('inv_profit')?.value) || 0;
                let sewa = parseFloat(document.getElementById('inv_sewa')?.value) || 0;
                let modal = parseFloat(document.getElementById('inv_modal')?.value) || 0;
                let kasbon = parseFloat(document.getElementById('inv_kasbon')?.value) || 0;
                let operatorSewa = document.getElementById('inv_sewa_operator')?.value || 'minus';

                let total = profit;

                if (operatorSewa === 'plus') {
                    total += sewa;
                } else {
                    total -= sewa;
                }

                total += modal;
                total += kasbon;
                total = Math.max(0, total);

                const invTotal = document.getElementById('inv_total');
                if (invTotal) {
                    invTotal.innerText = formatRupiah(total);
                }

                hitungPengelola();
            }

            // =========================================================
            // KOREKSI DIVIDEN - PENGELOLA
            // =========================================================
            function hitungPengelola() {
                let profit = parseFloat(document.getElementById('pgl_profit')?.value) || 0;
                let adminPersen = parseFloat(document.getElementById('pgl_admin_persen')?.value) || 0;
                let kasbon = parseFloat(document.getElementById('inv_kasbon')?.value) || 0;

                // Service Fee yang dipotong dari Pengelola (3%, 5%, atau 7.5%)
                let serviceFee = (profit * adminPersen) / 100;

                // Net Profit Pengelola setelah dipotong Service Fee & Kasbon
                let profitBersih = profit - serviceFee - kasbon;
                profitBersih = Math.max(0, profitBersih);

                const totalProfit = document.getElementById('pgl_total_profit');
                if (totalProfit) {
                    totalProfit.innerText = formatRupiah(profitBersih);
                }

                const totalAdmin = document.getElementById('pgl_total_admin');
                if (totalAdmin) {
                    totalAdmin.innerText = formatRupiah(serviceFee);
                }

                // Kirim Service Fee ke Rekap Akhir untuk dijumlahkan dengan Management Pusat 3%
                updateFinalRekap(serviceFee);
            }

            // =========================================================
            // JALANKAN SAAT HALAMAN SELESAI DIMUAT
            // =========================================================
            document.addEventListener('DOMContentLoaded', function() {
                hitungBO();
            });
        </script>

        <script>
            // Export Excel - SESUAI STRUKTUR BARU & PERHITUNGAN PRESISI
            async function exportExcel() {
                const wb = XLSX.utils.book_new();

                const periode = `<?= date("F Y", strtotime("$tahun-$bulan-01")) ?>`;
                const namaCabang = `<?= h($nama_cabang ?? "WBB Cabang") ?>`;

                // Data PHP biar sinkron
                const omzet_akumulasi = <?= (float)$penjualan ?>;
                const belanja_akumulasi = <?= (float)$belanja_akumulasi ?>;
                const bo_akumulasi = <?= (float)$bo_akumulasi ?>;
                const pengeluaran_akumulasi = <?= (float)$pengeluaran_akumulasi ?>;
                const modal_awal = <?= (float)$modal_awal ?>;
                const laba_akumulasi = <?= (float)$laba_akumulasi ?>;
                const persen_investor = <?= (float)$persen_investor ?>;
                const persen_pengelola = <?= (float)$persen_pengelola ?>;
                const persen_admin = <?= (float)$persen_admin ?>;
                const share_investor = <?= (float)$share_investor ?>;
                const share_pengelola = <?= (float)$share_pengelola ?>;
                const share_admin = <?= (float)$share_admin ?>;
                const share_pengelola_bersih = <?= (float)$share_pengelola_bersih ?>;

                // Fungsi kop surat biar gak nulis ulang
                function addKop(ws, startRow = 1) {
                    ws['A' + startRow] = {
                        t: 's',
                        v: 'WARTEG BUMI BAHARI'
                    };
                    ws['A' + (startRow + 1)] = {
                        t: 's',
                        v: <?= json_encode($alamat_cabang ?? "Kantor Pusat : Kecamatan Pamulang Kota Tangerang Selatan | BANTEN 15417") ?>
                    };
                    ws['A' + (startRow + 2)] = {
                        t: 's',
                        v: 'Phone : <?= h($no_hp_cabang ?? "+62 858 1111 2222") ?>'
                    };

                    ws['F' + startRow] = {
                        t: 's',
                        v: 'Cabang'
                    };
                    ws['G' + startRow] = {
                        t: 's',
                        v: ': ' + namaCabang
                    };
                    ws['F' + (startRow + 1)] = {
                        t: 's',
                        v: 'Periode'
                    };
                    ws['G' + (startRow + 1)] = {
                        t: 's',
                        v: ': ' + periode
                    };
                    ws['F' + (startRow + 2)] = {
                        t: 's',
                        v: 'Pengelola'
                    };
                    ws['G' + (startRow + 2)] = {
                        t: 's',
                        v: ': <?= h($pengelola['nama_pengelola'] ?? "-") ?>'
                    };
                    ws['F' + (startRow + 3)] = {
                        t: 's',
                        v: 'Investor'
                    };
                    ws['G' + (startRow + 3)] = {
                        t: 's',
                        v: ': <?= h($cabang_info['investor'] ?? "-") ?>'
                    };

                    ws['!cols'] = [{
                        wch: 35
                    }, {
                        wch: 18
                    }, {
                        wch: 18
                    }, {
                        wch: 22
                    }, {
                        wch: 15
                    }, {
                        wch: 12
                    }, {
                        wch: 32
                    }];
                    return startRow + 5;
                }

                // =========================================================================
                // SHEET 1: REKAPITULASI HARIAN
                // =========================================================================
                let ws1 = {};
                let r1 = addKop(ws1);
                ws1['A' + r1] = {
                    t: 's',
                    v: '1. REKAPITULASI PENDAPATAN & PENGELUARAN HARIAN'
                };
                r1++;
                let elTabel1 = document.getElementById('tabelRekapHarian');
                if (elTabel1) {
                    const tempWs = XLSX.utils.table_to_sheet(elTabel1, {
                        raw: true
                    });
                    Object.keys(tempWs).forEach(cell => {
                        if (!cell.startsWith('!')) ws1[cell.replace(/\d+/, m => parseInt(m) + r1 - 1)] = tempWs[cell];
                    });
                    const range = XLSX.utils.decode_range(tempWs['!ref']);
                    ws1['!ref'] = `A1:G${r1+range.e.r}`;
                }
                XLSX.utils.book_append_sheet(wb, ws1, "Rekap Harian");

                // =========================================================================
                // SHEET 2: RINCIAN BEBAN OPERASIONAL
                // =========================================================================
                let ws2 = {};
                let r2 = addKop(ws2);
                ws2['A' + r2] = {
                    t: 's',
                    v: '2. RINCIAN BEBAN OPERASIONAL'
                };
                r2++;
                let t2 = document.querySelector('.table-clean-input');
                let elTabel2 = t2?.tagName === 'TABLE' ? t2 : t2?.querySelector('table');
                if (elTabel2) {
                    const tempWs = XLSX.utils.table_to_sheet(elTabel2, {
                        raw: true
                    });
                    Object.keys(tempWs).forEach(cell => {
                        if (!cell.startsWith('!')) ws2[cell.replace(/\d+/, m => parseInt(m) + r2 - 1)] = tempWs[cell];
                    });
                    const range = XLSX.utils.decode_range(tempWs['!ref']);
                    ws2['!ref'] = `A1:H${r2+range.e.r}`;
                }
                XLSX.utils.book_append_sheet(wb, ws2, "Rincian BO");

                // =========================================================================
                // SHEET 3: MATRIKS AKUMULASI
                // =========================================================================
                let ws3 = {};
                let r3 = addKop(ws3);
                ws3['A' + r3] = {
                    t: 's',
                    v: '3. MATRIKS AKUMULASI'
                };
                r3++;
                const dataMatriks = [
                    ['Komponen Pokok', 'Jumlah', 'Catatan Ringkas'],
                    ['Omzet Penjualan', omzet_akumulasi, 'Pendapatan bruto masuk'],
                    ['Pengeluaran Belanja', belanja_akumulasi, 'Total belanja 1 bulan'],
                    ['Beban Operasional', bo_akumulasi, 'Total BO 1 bulan'],
                    ['Total Pengeluaran', pengeluaran_akumulasi, 'Belanja + BO'],
                    ['Modal Awal', modal_awal, 'Penyesuaian kas awal'],
                    ['Total Laba Bersih', laba_akumulasi, 'Omzet - Total Pengeluaran'],
                ];
                XLSX.utils.sheet_add_aoa(ws3, dataMatriks, {
                    origin: 'A' + r3
                });
                ws3['!ref'] = `A1:C${r3+dataMatriks.length}`;
                XLSX.utils.book_append_sheet(wb, ws3, "Matriks Akumulasi");

                // =========================================================================
                // SHEET 4: REVENUE SHARING
                // =========================================================================
                let ws4 = {};
                let r4 = addKop(ws4);
                ws4['A' + r4] = {
                    t: 's',
                    v: '4. KONTRAK PEMBAGIAN HASIL - REVENUE SHARING'
                };
                r4++;
                const dataSharing = [
                    ['Entitas Mitra', 'Porsi Rasio', 'Nilai Estimasi'],
                    ['Investor Utama', persen_investor + '%', share_investor],
                    ['Pengelola Lapangan', persen_pengelola + '%', share_pengelola],
                    ['Management Pusat', persen_admin + '%', share_admin],
                    ['Pengelola Bersih', '47%', share_pengelola_bersih],
                ];
                XLSX.utils.sheet_add_aoa(ws4, dataSharing, {
                    origin: 'A' + r4
                });
                ws4['!ref'] = `A1:C${r4+dataSharing.length}`;
                XLSX.utils.book_append_sheet(wb, ws4, "Revenue Sharing");

                // =========================================================================
                // SHEET 5: KOREKSI INVESTOR
                // =========================================================================
                let ws5 = {};
                let r5 = addKop(ws5);
                ws5['A' + r5] = {
                    t: 's',
                    v: '5. KOREKSI DIVIDEN: SISI INVESTOR'
                };
                r5++;

                // Mengambil nilai aktual dari Form Koreksi Investor atau Fallback
                const inv_profit = parseFloat(document.getElementById('inv_profit')?.value || share_investor);
                const inv_sewa = parseFloat(document.getElementById('inv_sewa')?.value || <?= (float)($bo_db['sewa'] ?? 0) ?>);
                const inv_modal = parseFloat(document.getElementById('inv_modal')?.value || 0);
                const inv_kasbon = parseFloat(document.getElementById('inv_kasbon')?.value || 0);
                const inv_admin = parseFloat(document.getElementById('inv_admin')?.value || 0);
                
                // Kalkulasi Koreksi Investor (Profit - Sewa - Modal + Kasbon - Admin) Sesuai Logika
                const inv_total = inv_profit - inv_sewa - inv_modal + inv_kasbon - inv_admin;

                const dataInv = [
                    ['Keterangan Komponen', 'Nilai'],
                    ['Profit Dasar Share (50%)', inv_profit],
                    ['Potongan Sewa Ruko', inv_sewa],
                    ['Pengembalian Dana Talangan / Modal', inv_modal],
                    ['Penambahan/Pengembalian Kasbon Pengelola', inv_kasbon],
                    ['Potongan Admin', inv_admin],
                    ['TOTAL BERSIH INVESTOR', inv_total],
                ];
                XLSX.utils.sheet_add_aoa(ws5, dataInv, {
                    origin: 'A' + r5
                });
                ws5['!ref'] = `A1:B${r5+dataInv.length}`;
                XLSX.utils.book_append_sheet(wb, ws5, "Koreksi Investor");

                // =========================================================================
                // SHEET 6: KOREKSI PENGELOLA
                // =========================================================================
                let ws6 = {};
                let r6 = addKop(ws6);
                ws6['A' + r6] = {
                    t: 's',
                    v: '6. KOREKSI DIVIDEN: SISI PENGELOLA'
                };
                r6++;

                // Mengambil nilai aktual dari Form Koreksi Pengelola atau Fallback
                const pgl_profit = parseFloat(document.getElementById('pgl_profit')?.value || share_pengelola);
                const pgl_admin = parseFloat(document.getElementById('pgl_admin')?.value || share_admin);
                const pgl_service = parseFloat(document.getElementById('pgl_service')?.value || 0);
                
                // Kalkulasi Koreksi Pengelola (Profit 50% - Admin 3% - Service/BPJS = 47% Bersih)
                const pgl_total = pgl_profit - pgl_admin - pgl_service;

                const dataPgl = [
                    ['Keterangan Komponen', 'Nilai'],
                    ['Profit Pengelola (50%)', pgl_profit],
                    ['Admin Fee Cabang (3%)', pgl_admin],
                    ['Service Fee + BPJS', pgl_service],
                    ['TOTAL BERSIH PENGELOLA (47%)', pgl_total],
                ];
                XLSX.utils.sheet_add_aoa(ws6, dataPgl, {
                    origin: 'A' + r6
                });
                ws6['!ref'] = `A1:B${r6+dataPgl.length}`;
                XLSX.utils.book_append_sheet(wb, ws6, "Koreksi Pengelola");

                // =========================================================================
                // SHEET 7: DISTRIBUSI PAYROLL
                // =========================================================================
                let ws7 = {};
                let r7 = addKop(ws7);
                ws7['A' + r7] = {
                    t: 's',
                    v: '7. REKAPAN HASIL AKHIR KEUNTUNGAN - DISTRIBUSI PAYROLL'
                };
                r7++;
                let wrapperPayroll = document.querySelector('.card.border-0.mb-5');
                let elTabel6 = wrapperPayroll?.querySelector('table');
                if (elTabel6) {
                    const tempWs = XLSX.utils.table_to_sheet(elTabel6, {
                        raw: true
                    });
                    Object.keys(tempWs).forEach(cell => {
                        if (!cell.startsWith('!')) ws7[cell.replace(/\d+/, m => parseInt(m) + r7 - 1)] = tempWs[cell];
                    });
                    const range = XLSX.utils.decode_range(tempWs['!ref']);
                    ws7['!ref'] = `A1:F${r7+range.e.r}`;
                }
                XLSX.utils.book_append_sheet(wb, ws7, "Distribusi Payroll");

                // =========================================================================
                // EXPORT
                // =========================================================================
                XLSX.writeFile(wb, `<?= h($nama_file_export) ?>_MultiSheet.xlsx`);
            }

            async function exportPDF() {
                const {
                    jsPDF
                } = window.jspdf;
                let doc = new jsPDF('landscape', 'mm', 'a4');

                const margin = 14;
                const baseTableStyles = {
                    theme: 'grid',
                    styles: {
                        fontSize: 8,
                        cellPadding: 2
                    },
                    headStyles: {
                        fillColor: [52, 58, 64],
                        textColor: 255,
                        halign: 'center'
                    }
                };

                function parseAngka(val) {
                    return parseFloat(
                        String(val || 0)
                        .replace(/[^0-9,.-]+/g, "")
                        .replace(/\./g, "")
                        .replace(",", ".")
                    ) || 0;
                }

                function formatRupiahPDF(angka) {
                    return 'Rp. ' + parseAngka(angka).toLocaleString('id-ID');
                }

                const omzet_akumulasi = <?= (float)$penjualan ?>;
                const belanja_akumulasi = <?= (float)$belanja_akumulasi ?>;
                const bo_akumulasi = <?= (float)$bo_akumulasi ?>;
                const pengeluaran_akumulasi = <?= (float)$pengeluaran_akumulasi ?>;
                const modal_awal = <?= (float)$modal_awal ?>;
                const laba_akumulasi = <?= (float)$laba_akumulasi ?>;

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
                            pdfDoc.setGState(new pdfDoc.GState({
                                opacity: 0.08
                            }));
                            pdfDoc.addImage(imgLogo, 'PNG', wmX, wmY, wmSize, wmSize);
                            pdfDoc.restoreGraphicsState();
                        } catch (err) {
                            console.warn("Gagal memuat watermark pada halaman ini:", err);
                        }
                    }
                };

                // =========================================================================
                // HALAMAN 1: REKAPITULASI + KOP
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

                let textYStart = imgLogo ? startYContent + 4.5 : startYContent;
                doc.setFont('helvetica', 'bold');
                doc.setFontSize(14);
                doc.setTextColor(40, 40, 40);
                doc.text('WARTEG BUMI BAHARI', textXPosition, textYStart);

                doc.setFont('helvetica', 'normal');
                doc.setFontSize(8.5);
                doc.setTextColor(100, 100, 100);
                doc.text(<?= json_encode($alamat_cabang ?? "Kantor Pusat : Kecamatan Pamulang Kota Tangerang Selatan | BANTEN 15417") ?>, textXPosition, textYStart + 5);
                doc.text('Phone : <?= h($no_hp_cabang ?? "+62 858 1111 2222") ?>', textXPosition, textYStart + 9);

                let infoData = [
                    ['Cabang', ': <?= h($nama_cabang ?? "WBB Cabang") ?>'],
                    ['Periode', ': <?= date("F Y", strtotime("$tahun-$bulan-01")) ?>'],
                    ['Pengelola', ': <?= h($pengelola['nama_pengelola'] ?? "-") ?>'],
                    ['Investor', ': <?= h($cabang_info['investor'] ?? "-") ?>']
                ];

                doc.autoTable({
                    body: infoData,
                    startY: imgLogo ? startYContent + 1.5 : startYContent - 3,
                    theme: 'plain',
                    styles: {
                        fontSize: 8.5,
                        cellPadding: 0.3,
                        fontStyle: 'bold',
                        textColor: [40, 40, 40]
                    },
                    columnStyles: {
                        0: {
                            cellWidth: 20
                        },
                        1: {
                            cellWidth: 80
                        }
                    },
                    margin: {
                        left: 190
                    }
                });

                let yLine = startYContent + 20;
                doc.setDrawColor(200, 200, 200);
                doc.setLineWidth(0.4);
                doc.line(margin, yLine, 283, yLine);

                let yTabelHarian = yLine + 7;
                doc.setFont('helvetica', 'bold');
                doc.setFontSize(11);
                doc.setTextColor(0, 0, 0);
                doc.text('1. Rekapitulasi Pendapatan & Pengeluaran Harian - <?= date("F Y", strtotime("$tahun-$bulan-01")) ?>', margin, yTabelHarian);

                doc.autoTable({
                    html: '#tabelRekapHarian',
                    startY: yTabelHarian + 4,
                    ...baseTableStyles,
                    styles: {
                        fontSize: 7,
                        cellPadding: 1.2
                    }
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
                let elTabel2 = t2?.tagName === 'TABLE' ? t2 : t2?.querySelector('table');
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
                    ['Omzet Penjualan', formatRupiahPDF(omzet_akumulasi), 'Pendapatan bruto masuk'],
                    ['Pengeluaran Belanja', formatRupiahPDF(belanja_akumulasi), 'Total belanja 1 bulan'],
                    ['Beban Operasional', formatRupiahPDF(bo_akumulasi), 'Total BO 1 bulan'],
                    ['Total Pengeluaran', formatRupiahPDF(pengeluaran_akumulasi), 'Belanja + BO'],
                    ['Modal Awal', formatRupiahPDF(modal_awal), 'Penyesuaian kas awal'],
                    ['Total Laba Bersih', formatRupiahPDF(laba_akumulasi), 'Omzet - Total Pengeluaran'],
                ];

                doc.autoTable({
                    head: [
                        ['Komponen Pokok', 'Jumlah', 'Catatan Ringkas']
                    ],
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

                // Ambil nilai dari input elemen atau fallback ke perhitungan dasar
                const inv_profit = parseAngka(document.getElementById('inv_profit')?.value || <?= (float)$share_investor ?>);
                const inv_sewa = parseAngka(document.getElementById('inv_sewa')?.value || <?= (float)($bo_db['sewa'] ?? 0) ?>);
                const inv_modal = parseAngka(document.getElementById('inv_modal')?.value || 0);
                const inv_kasbon = parseAngka(document.getElementById('inv_kasbon')?.value || 0);
                const inv_admin = parseAngka(document.getElementById('inv_admin')?.value || 0);
                
                // Kalkulasi Total Investor yang Sinkron
                const inv_total_val = parseAngka(document.getElementById('inv_total')?.innerText) || (inv_profit - inv_sewa - inv_modal + inv_kasbon - inv_admin);

                let dataInvestor = [
                    ['Profit Investor (50%)', formatRupiahPDF(inv_profit)],
                    ['Potongan Sewa Ruko', formatRupiahPDF(inv_sewa)],
                    ['Pengembalian Dana Talangan / Modal', formatRupiahPDF(inv_modal)],
                    ['Penambahan/Pengembalian Kasbon Pengelola', formatRupiahPDF(inv_kasbon)],
                    ['Potongan Admin', formatRupiahPDF(inv_admin)],
                    ['TOTAL BERSIH INVESTOR', formatRupiahPDF(inv_total_val)],
                ];

                doc.autoTable({
                    head: [
                        ['Keterangan Komponen', 'Nilai']
                    ],
                    body: dataInvestor,
                    startY: y + 5,
                    ...baseTableStyles
                });

                y = doc.lastAutoTable.finalY + 12;

                doc.setFontSize(12);
                doc.setFont('helvetica', 'bold');
                doc.text('5. Koreksi Dividen: Sisi Pengelola', margin, y);

                // 1. Ambil Profit Pengelola dasar dari UI
                const pgl_profit = parseAngka(document.getElementById('pgl_profit')?.value || <?= (float)$share_pengelola ?>);

                // 2. Ambil persentase Service Fee secara REAL-TIME dari Dropdown/Select di UI
                let elSelectFee = document.getElementById('pgl_service_fee_percent') || document.querySelector('select[name*="service_fee"]') || document.querySelector('.card select');
                let pct_admin = 3; // Fallback jika select tidak ditemukan
                
                if (elSelectFee) {
                    pct_admin = parseAngka(elSelectFee.value) || 3;
                } else {
                    pct_admin = <?= (float)($persen_admin ?? 3) ?>;
                }

                // 3. Hitung persentase porsi bersih Pengelola (50% dikurangi % Service Fee)
                const pct_pengelola_bersih = 50 - pct_admin;

                // 4. Ambil nominal Service Fee, Service Lainnya, dan Total Net Profit Pengelola
                const pgl_admin = parseAngka(document.getElementById('pgl_admin')?.value) || (pgl_profit * (pct_admin / 100));
                const pgl_service = parseAngka(document.getElementById('pgl_service')?.value || 0);

                // Kalkulasi Total Pengelola yang Sinkron (50% - Admin% - Service = Net%)
                const pgl_total_val = parseAngka(document.getElementById('pgl_total_profit')?.innerText || document.querySelector('.text-success')?.innerText) || (pgl_profit - pgl_admin - pgl_service);

                // Format string desimal untuk tampilan persen di PDF (mengubah titik menjadi koma, misal: 7,5% & 42,5%)
                const str_pct_admin = pct_admin.toString().replace('.', ',');
                const str_pct_bersih = pct_pengelola_bersih.toString().replace('.', ',');

                let dataPengelola = [
                    ['Profit Pengelola (50%)', formatRupiahPDF(pgl_profit)],
                    [`Service Fee / Admin (${str_pct_admin}%)`, formatRupiahPDF(pgl_admin)],
                    ['Potongan Service / BPJS / Lainnya', formatRupiahPDF(pgl_service)],
                    [`TOTAL BERSIH PENGELOLA (${str_pct_bersih}%)`, formatRupiahPDF(pgl_total_val)]
                ];  

                doc.autoTable({
                    head: [
                        ['Keterangan Komponen', 'Nilai']
                    ],
                    body: dataPengelola,
                    startY: y + 5,
                    ...baseTableStyles
                });

                y = doc.lastAutoTable.finalY + 12;

                doc.setFontSize(12);
                doc.setFont('helvetica', 'bold');
                doc.text('6. Rekapan Hasil Akhir Keuntungan (Distribusi Payroll)', margin, y);

                let wrapperPayroll = document.querySelector('.card.border-0.mb-5') || document.querySelector('.card.border-0.mb-5.table');
                let elTabel6 = wrapperPayroll?.tagName === 'TABLE' ? wrapperPayroll : wrapperPayroll?.querySelector('table');

                if (elTabel6) {
                    doc.autoTable({
                        html: elTabel6,
                        startY: y + 5,
                        ...baseTableStyles,
                        columnStyles: {
                            5: {
                                halign: 'right'
                            }
                        }
                    });
                }

                doc.save("<?= h($nama_file_export) ?>.pdf");
            }
        </script>