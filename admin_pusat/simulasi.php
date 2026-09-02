<?php
require '../config/koneksi.php';
include 'sidebar_pusat.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'pusat') {
    header('Location: ../login');
    exit;
}

// ---------------------------------------------------------------------------
// Simulasi murni kalkulator — TIDAK PERNAH menulis ke database. Cocok untuk
// gambaran cabang baru / calon investor sebelum data harian sungguhan ada.
// Rumus harian dihitung lewat hitung_laporan_harian() yang SAMA PERSIS dipakai
// form input sungguhan (config/keuangan.php), supaya hasil simulasi tidak
// pernah menyimpang dari cara sistem ini benar-benar menghitung.
// ---------------------------------------------------------------------------
$sudah_hitung = isset($_GET['hitung']);
$nama_simulasi = trim($_GET['nama_simulasi'] ?? '');
$jumlah_hari = (int) ($_GET['jumlah_hari'] ?? 30);
if ($jumlah_hari < 1 || $jumlah_hari > 31) $jumlah_hari = 30;
$persen_admin = (float) ($_GET['persen_admin'] ?? 3);
if (!in_array($persen_admin, [3.0, 5.0, 7.5], true)) $persen_admin = 3.0;

$hasil = null;
$bulanan = null;
$bagi_harian = null;
$bagi_bulanan = null;

if ($sudah_hitung) {
    $hasil = hitung_laporan_harian($_GET, 'bersihkan_angka');

    // Proyeksi bulanan — kalikan setiap komponen harian dengan jumlah hari operasional.
    $bulanan = [
        'total_omset'       => $hasil['total_omset'] * $jumlah_hari,
        'total_pengeluaran' => $hasil['total_pengeluaran'] * $jumlah_hari,
        'net_profit'        => $hasil['net_profit'] * $jumlah_hari,
        'persentase'        => $hasil['persentase'], // margin % tidak berubah walau dikali hari
    ];

    // Pembagian hasil — rumus SAMA PERSIS dengan rekapitulasi.php:
    // Admin Fee dipotong dulu dari net profit, sisanya dibagi 50%/50% Investor/Pengelola.
    $bagi = static function (float $net_profit, float $persen_admin): array {
        $admin_fee = max(0, $net_profit) * $persen_admin / 100;
        $sisa      = max(0, $net_profit) - $admin_fee;
        return [
            'admin_fee' => $admin_fee,
            'investor'  => $sisa * 0.5,
            'pengelola' => $sisa * 0.5,
        ];
    };
    $bagi_harian  = $bagi((float) $hasil['net_profit'], $persen_admin);
    $bagi_bulanan = $bagi((float) $bulanan['net_profit'], $persen_admin);
}

$rp = static fn ($v) => 'Rp ' . number_format((float) ($v ?? 0), 0, ',', '.');

// Render satu field input Rp (mask ribuan) — untuk form simulasi.
$sim_field = static function (string $label, string $name) {
    // bersihkan_angka(), BUKAN (int) langsung — nilai di $_GET sudah terformat
    // titik ribuan ("1.000.000"), dan (int) cast akan berhenti di titik pertama.
    $val = bersihkan_angka($_GET[$name] ?? 0);
    ?>
    <div class="col-6 col-sm-4 col-lg-3">
        <label class="sim-lbl"><?= h($label) ?></label>
        <div class="input-group input-group-sm sim-ig">
            <span class="input-group-text">Rp</span>
            <input type="text" inputmode="decimal" name="<?= $name ?>"
                   value="<?= $val ? number_format($val, 0, ',', '.') : '' ?>"
                   class="form-control sim-num" placeholder="0" oninput="maskRupiahSim(this)">
        </div>
    </div>
    <?php
};
?>

<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">

<style>
body { background-color: #f6f8ff !important; font-family: 'Plus Jakarta Sans', sans-serif !important; }
.saas-card { background: #fff; border: 1px solid #eef1fb !important; border-radius: 18px !important; box-shadow: 0 4px 6px -1px rgb(0 0 0 / .02), 0 10px 15px -3px rgb(0 0 0 / .01) !important; padding: 22px; }
.sim-sec { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 14px; }
.sim-sec-h { font-weight: 700; font-size: .8rem; letter-spacing: .02em; text-transform: uppercase; color: #334155; margin-bottom: 12px; display: flex; align-items: center; gap: 8px; }
.sim-h-green { color: #059669; } .sim-h-red { color: #dc2626; } .sim-h-amber { color: #d97706; }
.sim-lbl { font-size: .72rem; font-weight: 600; color: #64748b; margin-bottom: 3px; display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.sim-ig .input-group-text { background: #f8fafc; border-color: #e2e8f0; color: #94a3b8; font-size: .75rem; padding: .2rem .5rem; }
.sim-num { border-color: #e2e8f0; font-weight: 600; text-align: right; font-size: .85rem; }
.sim-num:focus { border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99,102,241,.15); }
.sim-result-card { border-radius: 16px; padding: 20px; background: #fff; border: 1px solid #eef1fb; box-shadow: 0 4px 6px -1px rgb(0 0 0 / .02); }
.sim-result-card .label { font-size: 11px; font-weight: 700; color: #8b7aa0; text-transform: uppercase; letter-spacing: .5px; }
.sim-result-card .value { font-size: 20px; font-weight: 800; color: #0f172a; margin-top: 4px; }
.sim-split-row { display: flex; justify-content: space-between; align-items: center; padding: 10px 14px; border-bottom: 1px solid #f1f5f9; font-size: .9rem; }
.sim-split-row:last-child { border-bottom: none; }
.sim-split-row b { color: #0f172a; }
</style>

<div class="container-fluid py-4">
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
        <div>
            <h3 class="fw-bold mb-0" style="color:#0f172a;"><i class="bi bi-calculator-fill text-primary me-2"></i>Simulasi Pendapatan</h3>
            <span class="text-muted small">Gambaran omzet &amp; pembagian hasil untuk cabang baru / calon investor — angka di bawah ini murni simulasi, tidak pernah disimpan ke database.</span>
        </div>
    </div>

    <form method="GET" class="d-flex flex-column gap-3">
        <input type="hidden" name="hitung" value="1">

        <div class="saas-card">
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="sim-lbl">Nama Simulasi / Cabang (opsional, hanya label)</label>
                    <input type="text" name="nama_simulasi" value="<?= h($nama_simulasi) ?>" class="form-control form-control-sm" placeholder="mis. Rencana Cabang Cipete 2">
                </div>
                <div class="col-6 col-md-3">
                    <label class="sim-lbl">Hari Operasional / Bulan</label>
                    <input type="number" name="jumlah_hari" value="<?= $jumlah_hari ?>" min="1" max="31" class="form-control form-control-sm">
                </div>
                <div class="col-6 col-md-3">
                    <label class="sim-lbl">Admin Fee</label>
                    <select name="persen_admin" class="form-select form-select-sm">
                        <option value="3" <?= $persen_admin == 3 ? 'selected' : '' ?>>3%</option>
                        <option value="5" <?= $persen_admin == 5 ? 'selected' : '' ?>>5%</option>
                        <option value="7.5" <?= $persen_admin == 7.5 ? 'selected' : '' ?>>7,5%</option>
                    </select>
                </div>
            </div>

            <div class="d-flex flex-column gap-3">
                <section class="sim-sec">
                    <div class="sim-sec-h sim-h-green"><i class="bi bi-cash-coin"></i> Pendapatan Harian (perkiraan)</div>
                    <div class="row g-2">
                        <?php $sim_field('Tunai', 'tunai'); ?>
                        <?php $sim_field('QRIS', 'qris'); ?>
                        <?php $sim_field('Grab Food', 'grab_food'); ?>
                        <?php $sim_field('Go Food', 'go_food'); ?>
                        <?php $sim_field('Pencairan QRIS', 'pencairan_qris'); ?>
                    </div>
                </section>

                <section class="sim-sec">
                    <div class="sim-sec-h sim-h-red"><i class="bi bi-basket"></i> Belanja Rutin Harian (perkiraan)</div>
                    <div class="row g-2">
                        <?php $sim_field('Pasar', 'belanja_pasar'); ?>
                        <?php $sim_field('Sembako', 'belanja_sembako'); ?>
                        <?php $sim_field('Beras', 'belanja_beras'); ?>
                        <?php $sim_field('Toko', 'belanja_toko'); ?>
                    </div>
                </section>

                <section class="sim-sec">
                    <div class="sim-sec-h sim-h-amber"><i class="bi bi-receipt"></i> Beban Operasional Harian (perkiraan)</div>
                    <div class="row g-2">
                        <?php
                        foreach ([
                            'sewa' => 'Sewa', 'gaji' => 'Gaji', 'listrik' => 'Listrik', 'air' => 'Air',
                            'sampah' => 'Sampah', 'keamanan' => 'Keamanan', 'internet' => 'Internet', 'gas' => 'Gas',
                            'mingguan_karyawan' => 'Mingguan Karyawan', 'es_batu' => 'Es Batu',
                            'bensin' => 'Bensin', 'lain_lain' => 'Lain-lain',
                        ] as $fname => $flabel) {
                            $sim_field($flabel, $fname);
                        }
                        ?>
                    </div>
                </section>
            </div>

            <div class="mt-3">
                <button type="submit" class="btn btn-primary fw-semibold px-4"><i class="bi bi-play-fill me-1"></i> Hitung Simulasi</button>
                <?php if ($sudah_hitung): ?>
                    <a href="simulasi" class="btn btn-outline-secondary fw-semibold ms-2"><i class="bi bi-arrow-counterclockwise me-1"></i> Reset</a>
                <?php endif; ?>
            </div>
        </div>
    </form>

    <?php if ($sudah_hitung): ?>
    <div class="mt-4">
        <?php if ($nama_simulasi !== ''): ?>
            <h5 class="fw-bold mb-3" style="color:#0f172a;"><i class="bi bi-bookmark-star-fill text-primary me-2"></i><?= h($nama_simulasi) ?></h5>
        <?php endif; ?>

        <div class="row g-3 mb-3">
            <div class="col-12"><h6 class="fw-bold text-muted text-uppercase small mb-2">Proyeksi Harian</h6></div>
            <div class="col-6 col-md-3">
                <div class="sim-result-card"><div class="label">Total Omzet</div><div class="value text-primary"><?= $rp($hasil['total_omset']) ?></div></div>
            </div>
            <div class="col-6 col-md-3">
                <div class="sim-result-card"><div class="label">Total Pengeluaran</div><div class="value" style="color:#dc2626;"><?= $rp($hasil['total_pengeluaran']) ?></div></div>
            </div>
            <div class="col-6 col-md-3">
                <div class="sim-result-card"><div class="label">Net Profit</div><div class="value" style="color:#16a34a;"><?= $rp($hasil['net_profit']) ?></div></div>
            </div>
            <div class="col-6 col-md-3">
                <div class="sim-result-card"><div class="label">Margin</div><div class="value" style="color:#0ea5e9;"><?= number_format($hasil['persentase'], 2) ?>%</div></div>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-12"><h6 class="fw-bold text-muted text-uppercase small mb-2">Proyeksi Bulanan (&times; <?= $jumlah_hari ?> hari)</h6></div>
            <div class="col-6 col-md-3">
                <div class="sim-result-card"><div class="label">Total Omzet</div><div class="value text-primary"><?= $rp($bulanan['total_omset']) ?></div></div>
            </div>
            <div class="col-6 col-md-3">
                <div class="sim-result-card"><div class="label">Total Pengeluaran</div><div class="value" style="color:#dc2626;"><?= $rp($bulanan['total_pengeluaran']) ?></div></div>
            </div>
            <div class="col-6 col-md-3">
                <div class="sim-result-card"><div class="label">Net Profit</div><div class="value" style="color:#16a34a;"><?= $rp($bulanan['net_profit']) ?></div></div>
            </div>
            <div class="col-6 col-md-3">
                <div class="sim-result-card"><div class="label">Margin</div><div class="value" style="color:#0ea5e9;"><?= number_format($bulanan['persentase'], 2) ?>%</div></div>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-12"><h6 class="fw-bold text-muted text-uppercase small mb-2">Gambaran Pembagian Hasil (Admin Fee <?= h((string) $persen_admin) ?>% &middot; sisanya 50% Investor / 50% Pengelola)</h6></div>

            <div class="col-lg-6">
                <div class="saas-card p-0 overflow-hidden">
                    <div class="px-3 pt-3 pb-2 fw-bold border-bottom" style="color:#0f172a;">Per Hari</div>
                    <div class="sim-split-row"><span>Admin Fee</span><b><?= $rp($bagi_harian['admin_fee']) ?></b></div>
                    <div class="sim-split-row"><span>Bagian Investor (50%)</span><b class="text-primary"><?= $rp($bagi_harian['investor']) ?></b></div>
                    <div class="sim-split-row"><span>Bagian Pengelola (50%)</span><b style="color:#16a34a;"><?= $rp($bagi_harian['pengelola']) ?></b></div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="saas-card p-0 overflow-hidden">
                    <div class="px-3 pt-3 pb-2 fw-bold border-bottom" style="color:#0f172a;">Per Bulan (&times; <?= $jumlah_hari ?> hari)</div>
                    <div class="sim-split-row"><span>Admin Fee</span><b><?= $rp($bagi_bulanan['admin_fee']) ?></b></div>
                    <div class="sim-split-row"><span>Bagian Investor (50%)</span><b class="text-primary"><?= $rp($bagi_bulanan['investor']) ?></b></div>
                    <div class="sim-split-row"><span>Bagian Pengelola (50%)</span><b style="color:#16a34a;"><?= $rp($bagi_bulanan['pengelola']) ?></b></div>
                </div>
            </div>
        </div>

        <div class="alert alert-warning rounded-4 mt-4 small mb-0">
            <i class="bi bi-info-circle-fill me-1"></i> Ini hanya simulasi berdasarkan angka perkiraan yang Anda masukkan — bukan data laporan sungguhan, dan tidak tersimpan di sistem.
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
// Mask ribuan sambil mengetik — murni kosmetik, tidak menyentuh nilai yang dihitung PHP.
function maskRupiahSim(el) {
    const digits = el.value.replace(/[^0-9]/g, '').replace(/^0+(?=\d)/, '');
    el.value = digits.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
}
</script>
