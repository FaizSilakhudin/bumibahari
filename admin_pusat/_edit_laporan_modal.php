<?php
/**
 * Partial: modal "Koreksi Laporan Harian" (admin pusat)
 * Dipakai di dalam loop laporan.php — butuh variabel dari scope loop:
 *   $row  = satu baris tabel laporan_cabang (assoc)
 *   $id   = $row['id']
 *
 * Aset sekali-pakai (CSS + lightbox + JS recalc) ada di laporan.php.
 */

// Partial ini hanya boleh dipanggil dari dalam loop laporan.php
if (!isset($row) || !isset($id)) {
    http_response_code(404);
    exit;
}

$ed_id = (int) $id;

// Kumpulkan foto nota yang terisi
$ed_notas = [];
for ($n = 1; $n <= 4; $n++) {
    if (!empty($row["foto_nota{$n}"])) {
        $ed_notas[$n] = $row["foto_nota{$n}"];
    }
}

// Render satu field angka (Rp)
$ed_field = static function (string $label, string $name) use ($row) {
    $val = (int) ($row[$name] ?? 0);
    ?>
    <div class="col-6 col-sm-4">
        <label class="ed-lbl"><?= h($label) ?></label>
        <div class="input-group input-group-sm ed-ig">
            <span class="input-group-text">Rp</span>
            <input type="number" inputmode="numeric" step="1"
                   name="<?= $name ?>" value="<?= $val ?>"
                   class="form-control ed-num" data-field="<?= $name ?>">
        </div>
    </div>
    <?php
};

$ed_rp = static fn ($v) => 'Rp ' . number_format((float) ($v ?? 0), 0, ',', '.');
?>
<div class="modal fade ed-modal" id="detailModal<?= $ed_id ?>" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable modal-fullscreen-lg-down">
    <form method="POST" class="modal-content ed-content">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="id" value="<?= $ed_id ?>">
      <input type="hidden" name="update_laporan" value="1">

      <div class="modal-header ed-head">
        <div class="pe-2">
          <h5 class="modal-title fw-bold mb-1">Koreksi Laporan Harian</h5>
          <div class="ed-head-sub">
            <span><i class="bi bi-shop me-1"></i><?= h($row['nama_cabang'] ?? '-') ?></span>
            <span><i class="bi bi-person me-1"></i><?= h($row['nama_pengelola'] ?? '-') ?></span>
            <span><i class="bi bi-calendar3 me-1"></i><?= date('d M Y', strtotime($row['tanggal'])) ?></span>
          </div>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Tutup"></button>
      </div>

      <div class="modal-body ed-body">
        <div class="row g-3">

          <!-- ============ KIRI: FORM INPUT ============ -->
          <div class="col-xl-7">
            <div class="d-flex flex-column gap-3">

              <section class="ed-sec">
                <div class="ed-sec-h ed-h-green"><i class="bi bi-cash-coin"></i> Pendapatan</div>
                <div class="row g-2">
                  <?php $ed_field('Tunai', 'tunai'); ?>
                  <?php $ed_field('QRIS', 'qris'); ?>
                  <?php $ed_field('Grab Food', 'grab_food'); ?>
                  <?php $ed_field('Go Food', 'go_food'); ?>
                  <?php $ed_field('Pencairan QRIS', 'pencairan_qris'); ?>
                </div>
              </section>

              <section class="ed-sec">
                <div class="ed-sec-h ed-h-red"><i class="bi bi-basket"></i> Belanja Rutin</div>
                <div class="row g-2">
                  <?php $ed_field('Pasar', 'belanja_pasar'); ?>
                  <?php $ed_field('Sembako', 'belanja_sembako'); ?>
                  <?php $ed_field('Beras', 'belanja_beras'); ?>
                  <?php $ed_field('Toko', 'belanja_toko'); ?>
                </div>
              </section>

              <section class="ed-sec">
                <div class="ed-sec-h ed-h-amber"><i class="bi bi-receipt"></i> Beban Operasional</div>
                <div class="row g-2">
                  <?php
                  foreach ([
                      'sewa' => 'Sewa', 'gaji' => 'Gaji', 'listrik' => 'Listrik', 'air' => 'Air',
                      'sampah' => 'Sampah', 'keamanan' => 'Keamanan', 'internet' => 'Internet', 'gas' => 'Gas',
                      'mingguan_karyawan' => 'Mingguan Karyawan', 'es_batu' => 'Es Batu',
                      'bensin' => 'Bensin', 'lain_lain' => 'Lain-lain',
                  ] as $fname => $flabel) {
                      $ed_field($flabel, $fname);
                  }
                  ?>
                </div>
              </section>

              <section class="ed-sec">
                <div class="ed-sec-h ed-h-blue"><i class="bi bi-chat-left-text"></i> Catatan / Keterangan</div>
                <textarea name="keterangan" rows="3" class="form-control form-control-sm"
                          placeholder="Rincian pengeluaran tak terduga, dll."><?= h($row['keterangan'] ?? '') ?></textarea>
              </section>

            </div>
          </div>

          <!-- ============ KANAN: RINGKASAN + NOTA ============ -->
          <div class="col-xl-5">
            <div class="ed-side">

              <section class="ed-sec ed-sum">
                <div class="ed-sec-h"><i class="bi bi-calculator"></i> Ringkasan <span class="ed-auto">otomatis</span></div>
                <div class="ed-sum-list">
                  <div><span>Total Omzet</span><b id="summaryOmzet<?= $ed_id ?>"><?= $ed_rp($row['total_omset']) ?></b></div>
                  <div><span>Belanja Rutin</span><b id="summaryRutin<?= $ed_id ?>"><?= $ed_rp($row['total_rutin']) ?></b></div>
                  <div><span>Operasional</span><b id="summaryOperasional<?= $ed_id ?>"><?= $ed_rp($row['total_operasional']) ?></b></div>
                  <div><span>Total Pengeluaran</span><b id="summaryPengeluaran<?= $ed_id ?>"><?= $ed_rp($row['total_pengeluaran']) ?></b></div>
                  <div><span>Sisa Tunai</span><b id="summaryTunai<?= $ed_id ?>"><?= $ed_rp($row['sisa_tunai']) ?></b></div>
                  <div><span>Sisa QRIS</span><b id="summaryQris<?= $ed_id ?>"><?= $ed_rp($row['sisa_qris']) ?></b></div>
                  <div class="ed-sum-hi"><span>Net Profit</span><b id="summaryNet<?= $ed_id ?>"><?= $ed_rp($row['net_profit']) ?></b></div>
                  <div class="ed-sum-hi"><span>Margin</span><b id="summaryMargin<?= $ed_id ?>"><?= number_format((float) ($row['persentase'] ?? 0), 2) ?>%</b></div>
                </div>
              </section>

              <section class="ed-sec">
                <div class="ed-sec-h">
                  <i class="bi bi-images"></i> Foto Nota
                  <span class="ed-auto"><?= count($ed_notas) ?> file</span>
                </div>
                <?php if ($ed_notas): ?>
                  <div class="ed-notas">
                    <?php foreach ($ed_notas as $n => $file): ?>
                      <figure class="ed-nota">
                        <img src="../uploads/nota/<?= h($file) ?>" alt="Nota <?= $n ?>" loading="lazy"
                             class="ed-nota-img" onclick="edZoom(this.src)">
                        <figcaption>Nota <?= $n ?> &middot; ketuk untuk perbesar &middot; <a href="../uploads/nota/<?= h($file) ?>" download onclick="event.stopPropagation()">unduh</a></figcaption>
                      </figure>
                    <?php endforeach; ?>
                  </div>
                <?php else: ?>
                  <div class="ed-nota-empty">
                    <i class="bi bi-image"></i>
                    <span>Tidak ada foto nota pada laporan ini</span>
                  </div>
                <?php endif; ?>
              </section>

            </div>
          </div>

        </div>
      </div>

      <div class="modal-footer ed-foot">
        <button type="button" class="btn btn-light border fw-semibold" data-bs-dismiss="modal">Batal</button>
        <button type="submit" class="btn btn-primary fw-semibold">
          <i class="bi bi-check-circle-fill me-1"></i> Simpan Perubahan
        </button>
      </div>
    </form>
  </div>
</div>
