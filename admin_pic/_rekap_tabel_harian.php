<?php
/**
 * Partial: tabel "Rekapitulasi Pendapatan & Pengeluaran Harian" (satu rentang
 * tanggal). Dipakai berulang di rekapitulasi.php (periode berjalan + periode
 * sebelumnya).
 *
 * INPUT  (dari scope pemanggil):
 *   $conn            mysqli
 *   $rk_tgl_mulai    string  'YYYY-MM-DD' — awal rentang (inklusif)
 *   $rk_tgl_selesai  string  'YYYY-MM-DD' — akhir rentang (inklusif)
 *   $rk_id_cabang    int
 *   $rk_tabel_id     string  id untuk atribut <table>
 *
 * Rentang tidak harus persis 1 bulan kalender — bisa lebih lebar (closing
 * digabung, lihat resolve_periode_bulanan()) atau custom (filter tanggal
 * manual di rekapitulasi.php).
 *
 * OUTPUT (di-set untuk pemanggil):
 *   $rk_num_rows     int  total baris (termasuk hari libur)
 *   $rk_num_lengkap  int  jumlah hari status 'lengkap' saja (dipakai untuk rata-rata harian)
 *   $rk_t_pasar, $rk_t_beras, $rk_t_sembako, $rk_t_toko  float
 */

if (!isset($conn, $rk_tgl_mulai, $rk_tgl_selesai, $rk_id_cabang, $rk_tabel_id)) {
    http_response_code(404);
    exit;
}

$rk_stmt = $conn->prepare("
    SELECT l.tanggal, l.tunai, l.qris, l.pencairan_qris, l.sisa_qris, l.go_food, l.grab_food,
           l.total_omset, l.total_pengeluaran,
           l.belanja_pasar, l.belanja_beras, l.belanja_sembako, l.belanja_toko,
           l.sewa, l.gaji, l.listrik, l.air, l.sampah, l.keamanan, l.internet,
           l.gas, l.mingguan_karyawan, l.es_batu, l.bensin, l.lain_lain,
           l.net_profit, l.persentase, l.status_laporan, l.keterangan
    FROM laporan_cabang l
    WHERE l.tanggal BETWEEN ? AND ? AND l.id_cabang = ? AND l.status_laporan IN ('lengkap','libur')
    ORDER BY l.tanggal ASC
");
$rk_stmt->bind_param('ssi', $rk_tgl_mulai, $rk_tgl_selesai, $rk_id_cabang);
$rk_stmt->execute();
$rk_res        = $rk_stmt->get_result();
$rk_num_rows   = $rk_res->num_rows;
$rk_num_lengkap = 0;

$rk_t_tunai = $rk_t_qris = $rk_t_gofood = $rk_t_grab = 0;
$rk_t_omzet = $rk_t_pasar = $rk_t_beras = $rk_t_sembako = $rk_t_toko = 0;
$rk_t_sewa = $rk_t_gaji = $rk_t_lain_bo = 0;
$rk_t_pengeluaran = $rk_t_sisa = $rk_t_laba = 0;
$rk_no = 1;
?>
<table class="table table-hover align-middle mb-0 text-nowrap" id="<?= h($rk_tabel_id) ?>">
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
            <th class="text-end" width="8%">Sisa Tunai</th>
            <th class="text-end" width="8%">Net Profit</th>
            <th class="text-center" width="4%">Margin (%)</th>
            <th width="10%">Keterangan</th>
        </tr>
        <tr class="table-secondary" style="font-size: 0.8rem;">
            <th></th>
            <th></th>
            <th></th>
            <th class="text-end">QRIS (Sisa)</th>
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
            <th></th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        <?php if ($rk_num_rows > 0): ?>
            <?php while ($rk_h = $rk_res->fetch_assoc()):
                if (($rk_h['status_laporan'] ?? '') === 'libur'): ?>
                <tr class="table-secondary">
                    <td class="text-center text-muted"><?= $rk_no++ ?></td>
                    <td class="fw-medium"><?= date('d/m/Y', strtotime($rk_h['tanggal'])) ?></td>
                    <td colspan="17" class="text-center text-muted fst-italic">
                        <i class="bi bi-moon-stars-fill me-1"></i> LIBUR / TUTUP
                    </td>
                </tr>
                <?php continue; endif;

                $rk_num_lengkap++;
                $rk_tunai     = (float) ($rk_h['tunai'] ?? 0);
                // Kolom "QRIS" di rekap harian menampilkan SISA QRIS (qris - pencairan_qris),
                // sama seperti yang dilihat PIC saat input — bukan QRIS kotor masuk.
                // QRIS asli & pencairan tetap disimpan (untuk dropdown detail kroscek di
                // layar) — TIDAK memengaruhi nilai/kolom yang dihitung atau di-export PDF.
                $rk_qris      = (float) ($rk_h['sisa_qris'] ?? 0);
                $rk_qris_asli = (float) ($rk_h['qris'] ?? 0);
                $rk_pencairan = (float) ($rk_h['pencairan_qris'] ?? 0);
                $rk_gofood    = (float) ($rk_h['go_food'] ?? 0);
                $rk_grab      = (float) ($rk_h['grab_food'] ?? 0);
                $rk_omzet     = (float) ($rk_h['total_omset'] ?? 0);
                $rk_pasar     = (float) ($rk_h['belanja_pasar'] ?? 0);
                $rk_beras     = (float) ($rk_h['belanja_beras'] ?? 0);
                $rk_sembako   = (float) ($rk_h['belanja_sembako'] ?? 0);
                $rk_toko      = (float) ($rk_h['belanja_toko'] ?? 0);
                $rk_sewa      = (float) ($rk_h['sewa'] ?? 0);
                $rk_gaji      = (float) ($rk_h['gaji'] ?? 0);
                $rk_laba      = (float) ($rk_h['net_profit'] ?? 0);
                $rk_persen    = (float) ($rk_h['persentase'] ?? 0);

                $rk_lain_op = (float) ($rk_h['listrik'] ?? 0) + (float) ($rk_h['air'] ?? 0)
                    + (float) ($rk_h['sampah'] ?? 0) + (float) ($rk_h['keamanan'] ?? 0)
                    + (float) ($rk_h['internet'] ?? 0) + (float) ($rk_h['gas'] ?? 0)
                    + (float) ($rk_h['mingguan_karyawan'] ?? 0) + (float) ($rk_h['es_batu'] ?? 0)
                    + (float) ($rk_h['bensin'] ?? 0) + (float) ($rk_h['lain_lain'] ?? 0);

                $rk_peng_hari = $rk_pasar + $rk_beras + $rk_sembako + $rk_toko + $rk_sewa + $rk_gaji + $rk_lain_op;
                $rk_sisa_hari = $rk_tunai - $rk_peng_hari;

                $rk_t_tunai       += $rk_tunai;
                $rk_t_qris        += $rk_qris;
                $rk_t_gofood      += $rk_gofood;
                $rk_t_grab        += $rk_grab;
                $rk_t_omzet       += $rk_omzet;
                $rk_t_pasar       += $rk_pasar;
                $rk_t_beras       += $rk_beras;
                $rk_t_sembako     += $rk_sembako;
                $rk_t_toko        += $rk_toko;
                $rk_t_sewa        += $rk_sewa;
                $rk_t_gaji        += $rk_gaji;
                $rk_t_lain_bo     += $rk_lain_op;
                $rk_t_laba        += $rk_laba;
                $rk_t_pengeluaran += $rk_peng_hari;
                $rk_t_sisa        += $rk_sisa_hari;
            ?>
                <tr>
                    <td class="text-center text-muted"><?= $rk_no++ ?></td>
                    <td class="fw-medium"><?= date('d/m/Y', strtotime($rk_h['tanggal'])) ?></td>
                    <td class="text-end"><?= number_format($rk_tunai, 0, ',', '.') ?></td>
                    <?php
                    $rk_qris_text = $rk_qris != 0 ? number_format($rk_qris, 0, ',', '.') : '-';
                    $rk_ada_qris  = $rk_qris_asli != 0 || $rk_pencairan != 0 || $rk_qris != 0;
                    ?>
                    <td class="text-end" data-pdf-text="<?= h($rk_qris_text) ?>">
                        <?php if ($rk_ada_qris): ?>
                        <div class="dropdown d-inline-block">
                            <span><?= $rk_qris_text ?></span>
                            <button type="button" class="btn btn-link btn-sm p-0 ms-1 text-muted align-baseline" data-bs-toggle="dropdown" data-bs-strategy="fixed" aria-expanded="false" title="Detail QRIS (kroscek)">
                                <i class="bi bi-chevron-down" style="font-size: 10px;"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow-sm p-2 qris-dd-menu">
                                <li class="d-flex justify-content-between gap-3 px-2 py-1"><span class="text-muted small">QRIS Asli (Masuk)</span><span class="fw-semibold small"><?= number_format($rk_qris_asli, 0, ',', '.') ?></span></li>
                                <li class="d-flex justify-content-between gap-3 px-2 py-1"><span class="text-muted small">Pencairan QRIS</span><span class="fw-semibold small text-danger">- <?= number_format($rk_pencairan, 0, ',', '.') ?></span></li>
                                <li><hr class="dropdown-divider my-1"></li>
                                <li class="d-flex justify-content-between gap-3 px-2 py-1"><span class="text-muted small">Sisa QRIS</span><span class="fw-bold small"><?= $rk_qris_text ?></span></li>
                            </ul>
                        </div>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                    <td class="text-end"><?= $rk_gofood > 0 ? number_format($rk_gofood, 0, ',', '.') : '-' ?></td>
                    <td class="text-end"><?= $rk_grab > 0 ? number_format($rk_grab, 0, ',', '.') : '-' ?></td>
                    <td class="text-end fw-semibold"><?= number_format($rk_omzet, 0, ',', '.') ?></td>
                    <td class="text-end"><?= number_format($rk_pasar, 0, ',', '.') ?></td>
                    <td class="text-end"><?= number_format($rk_beras, 0, ',', '.') ?></td>
                    <td class="text-end"><?= number_format($rk_sembako, 0, ',', '.') ?></td>
                    <td class="text-end"><?= number_format($rk_toko, 0, ',', '.') ?></td>
                    <td class="text-end"><?= number_format($rk_sewa, 0, ',', '.') ?></td>
                    <td class="text-end"><?= number_format($rk_gaji, 0, ',', '.') ?></td>
                    <td class="text-end"><?= number_format($rk_lain_op, 0, ',', '.') ?></td>
                    <td class="text-end fw-semibold text-danger"><?= number_format($rk_peng_hari, 0, ',', '.') ?></td>
                    <td class="text-end fw-semibold <?= $rk_sisa_hari < 0 ? 'text-danger' : '' ?>"><?= number_format($rk_sisa_hari, 0, ',', '.') ?></td>
                    <td class="text-end fw-bold <?= $rk_laba < 0 ? 'text-danger' : 'text-success' ?>"><?= number_format($rk_laba, 0, ',', '.') ?></td>
                    <td class="text-center fw-semibold <?= $rk_persen < 0 ? 'text-danger' : 'text-primary' ?>"><?= number_format($rk_persen, 2) ?>%</td>
                    <td class="small text-muted" style="white-space: normal;"><?= h($rk_h['keterangan'] ?? '') ?></td>
                </tr>
            <?php endwhile; ?>
        <?php else: ?>
            <tr>
                <td colspan="19" class="text-center text-muted py-5">
                    <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                    Belum ada data laporan untuk periode ini
                </td>
            </tr>
        <?php endif; ?>
    </tbody>
    <?php if ($rk_num_rows > 0): $rk_margin = $rk_t_omzet > 0 ? ($rk_t_laba / $rk_t_omzet) * 100 : 0; ?>
        <tfoot class="table-dark">
            <tr class="fw-bold">
                <td colspan="2" class="text-center">JUMLAH</td>
                <td class="text-end"><?= number_format($rk_t_tunai, 0, ',', '.') ?></td>
                <td class="text-end"><?= number_format($rk_t_qris, 0, ',', '.') ?></td>
                <td class="text-end"><?= number_format($rk_t_gofood, 0, ',', '.') ?></td>
                <td class="text-end"><?= number_format($rk_t_grab, 0, ',', '.') ?></td>
                <td class="text-end"><?= number_format($rk_t_omzet, 0, ',', '.') ?></td>
                <td class="text-end"><?= number_format($rk_t_pasar, 0, ',', '.') ?></td>
                <td class="text-end"><?= number_format($rk_t_beras, 0, ',', '.') ?></td>
                <td class="text-end"><?= number_format($rk_t_sembako, 0, ',', '.') ?></td>
                <td class="text-end"><?= number_format($rk_t_toko, 0, ',', '.') ?></td>
                <td class="text-end"><?= number_format($rk_t_sewa, 0, ',', '.') ?></td>
                <td class="text-end"><?= number_format($rk_t_gaji, 0, ',', '.') ?></td>
                <td class="text-end"><?= number_format($rk_t_lain_bo, 0, ',', '.') ?></td>
                <td class="text-end text-danger"><?= number_format($rk_t_pengeluaran, 0, ',', '.') ?></td>
                <td class="text-end <?= $rk_t_sisa < 0 ? 'text-danger' : '' ?>"><?= number_format($rk_t_sisa, 0, ',', '.') ?></td>
                <td class="text-end <?= $rk_t_laba < 0 ? 'text-danger' : 'text-success' ?>"><?= number_format($rk_t_laba, 0, ',', '.') ?></td>
                <td class="text-center <?= $rk_margin < 0 ? 'text-danger' : 'text-primary' ?>"><?= number_format($rk_margin, 2) ?>%</td>
                <td></td>
            </tr>
        </tfoot>
    <?php endif; ?>
</table>
<?php
$rk_stmt->close();
