<?php
/**
 * Partial: "5. Klaim Bulanan" — daftar baris klaim manual pada Rekapitulasi.
 * Ditulis sekali, dicopy verbatim ke admin_pusat/ dan admin_pic/ (konvensi
 * proyek ini — lihat _rekap_tabel_harian.php, _rekap_script_export.php, dst).
 *
 * INPUT (dari scope pemanggil, rekapitulasi.php):
 *   $conn                        mysqli
 *   $id_cabang, $tahun, $bulan
 *   $daftar_klaim_bulanan        array  hasil query klaim_bulanan (lihat rekapitulasi.php)
 *   $total_klaim_bulanan         float  SEMUA baris (investor + warung + pusat)
 *   $total_klaim_dana_investor   float  baris ber-sumber_dana='investor' SAJA
 *   $total_klaim_dana_pusat      float  baris ber-sumber_dana='pusat' SAJA
 *
 * Kolom: No, Iuran Beban, Jumlah Akhir, Sumber Dana, Keterangan Tambahan.
 * Sumber Dana ('investor'/'warung'/'pusat') per baris:
 *   - investor : nominal masuk Pengembalian Dana Talangan (menambah Total
 *                Bersih Investor) SELAIN memotong Net Profit.
 *   - pusat    : nominal masuk Admin Management Pusat (8. Rekapan Hasil
 *                Akhir Keuntungan) SELAIN memotong Net Profit.
 *   - warung   : nominal HANYA memotong Net Profit.
 * Semua baris (apapun sumber dananya) memotong Net Profit SEBELUM admin fee
 * 3% dipotong (lihat rekapitulasi.php & _rekap_script_matrix.php).
 *
 * Baris ditambah/dihapus bebas oleh user (JS murni) — saat Simpan, SEMUA
 * baris lama utk (id_cabang,tahun,bulan,urutan_pengelola) dihapus lalu
 * diganti set yang dikirim (lihat rekap_klaim_bulanan_handler.php).
 */
?>
<div class="card border-0 mb-4" style="overflow: hidden;">
    <div class="card-header bg-light border-bottom py-3 d-flex align-items-center justify-content-between">
        <span class="fw-bold text-dark"><i class="bi bi-receipt-cutoff me-2 text-muted"></i>5. Klaim Bulanan</span>
        <span class="badge bg-warning bg-opacity-10 text-warning-emphasis px-3 py-1.5 rounded-pill fw-medium" style="font-size: 0.75rem;">Memotong Net Profit Sebelum Admin Fee</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 text-nowrap table-clean-input" id="tabelKlaimBulanan">
                <thead>
                    <tr>
                        <th class="text-center" width="5%">No</th>
                        <th>Iuran Beban</th>
                        <th class="text-end" width="15%">Jumlah Akhir</th>
                        <th class="text-center" width="15%">Sumber Dana</th>
                        <th class="ps-4">Keterangan Tambahan</th>
                        <th class="text-center" width="5%"></th>
                    </tr>
                </thead>
                <tbody id="klaimBulananBody">
                    <?php if (empty($daftar_klaim_bulanan)): ?>
                        <tr class="klaim-empty-row">
                            <td colspan="6" class="text-center text-muted py-4">
                                <i class="bi bi-inbox fs-4 d-block mb-1 opacity-50"></i>
                                Belum ada klaim bulanan. Klik "+ Tambah Baris" untuk menambahkan.
                            </td>
                        </tr>
                    <?php else: $kb_no = 1; foreach ($daftar_klaim_bulanan as $kb):
                        $kb_sumber = $kb['sumber_dana'] ?? 'warung';
                    ?>
                        <tr class="klaim-row">
                            <td class="text-center text-muted fw-medium klaim-no"><?= $kb_no++ ?></td>
                            <td><input type="text" class="form-control form-control-sm border-0 bg-transparent klaim-uraian" value="<?= h($kb['uraian']) ?>" placeholder="Uraian klaim..."></td>
                            <td class="text-end">
                                <input type="text" inputmode="numeric" class="form-control form-control-sm border-0 bg-transparent text-end fw-bold klaim-nominal" value="<?= number_format((float) $kb['nominal'], 0, ',', '.') ?>">
                            </td>
                            <td class="text-center">
                                <select class="form-select form-select-sm border-0 bg-transparent klaim-sumber">
                                    <option value="warung" <?= $kb_sumber === 'warung' ? 'selected' : '' ?>>Dana Warung</option>
                                    <option value="investor" <?= $kb_sumber === 'investor' ? 'selected' : '' ?>>Dana Investor</option>
                                    <option value="pusat" <?= $kb_sumber === 'pusat' ? 'selected' : '' ?>>Dana Pusat</option>
                                </select>
                            </td>
                            <td class="ps-4"><input type="text" class="form-control form-control-sm border-0 bg-transparent klaim-keterangan" value="<?= h($kb['keterangan'] ?? '') ?>" placeholder="Ketik keterangan..."></td>
                            <td class="text-center">
                                <button type="button" class="btn btn-sm btn-link text-danger p-0 klaim-hapus" title="Hapus baris"><i class="bi bi-trash"></i></button>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
                <tfoot>
                    <tr class="table-light border-top border-2" style="background-color: #f8fafc !important;">
                        <td colspan="2" class="text-end fw-bold text-secondary py-3">TOTAL KLAIM BULANAN:</td>
                        <td class="text-end fw-bold text-warning-emphasis py-3" id="klaimBulananTotal">Rp <?= number_format($total_klaim_bulanan, 0, ',', '.') ?></td>
                        <td colspan="3"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <div class="px-3 pb-2 pt-1" style="font-size: 0.78rem; color: #64748b;">
            <i class="bi bi-info-circle me-1"></i>
            Semua baris memotong Net Profit sebelum Admin Fee 3%. Baris "Dana Investor" tambahan otomatis masuk Pengembalian Dana Talangan (Koreksi Dividen: Sisi Investor); baris "Dana Pusat" tambahan otomatis masuk Admin Management Pusat (8. Rekapan Hasil Akhir Keuntungan).
        </div>
        <div class="p-3 border-top d-flex justify-content-between align-items-center" style="background-color: #f8fafc;">
            <button type="button" id="btnTambahKlaimBulanan" class="btn btn-sm btn-outline-secondary fw-semibold">
                <i class="bi bi-plus-circle me-1"></i>Tambah Baris
            </button>
            <button type="button" id="btnSimpanKlaimBulanan" class="btn btn-sm btn-outline-primary fw-semibold">
                <i class="bi bi-save me-1"></i>Simpan Klaim Bulanan
            </button>
        </div>
    </div>
</div>
<script>
(function () {
    const tbody = document.getElementById('klaimBulananBody');
    const btnTambah = document.getElementById('btnTambahKlaimBulanan');
    const btnSimpan = document.getElementById('btnSimpanKlaimBulanan');
    const totalCell = document.getElementById('klaimBulananTotal');
    if (!tbody || !btnTambah || !btnSimpan) return;

    const fmtRp = (n) => 'Rp ' + Math.round(n || 0).toLocaleString('id-ID');

    function hitungUlangNoDanTotal() {
        let total = 0;
        let totalInvestor = 0;
        let totalPusat = 0;
        let no = 1;
        tbody.querySelectorAll('tr.klaim-row').forEach(function (tr) {
            tr.querySelector('.klaim-no').textContent = no++;
            const nominal = bersihkanAngka(tr.querySelector('.klaim-nominal').value);
            const sumber = tr.querySelector('.klaim-sumber').value;
            total += nominal;
            if (sumber === 'investor') totalInvestor += nominal;
            if (sumber === 'pusat') totalPusat += nominal;
        });
        totalCell.textContent = fmtRp(total);

        // Update Net Profit / Revenue Sharing / Koreksi Dividen / Rekapan Hasil
        // Akhir secara LIVE (sebelum diklik Simpan) — RK_TOTAL_KLAIM_BULANAN,
        // RK_TOTAL_KLAIM_DANA_INVESTOR, RK_TOTAL_KLAIM_DANA_PUSAT, & hitungCascade()
        // datang dari _rekap_script_matrix.php. Semuanya sudah pasti ada di scope
        // global saat fungsi ini benar-benar terpanggil (event user, bukan saat
        // parse awal), walau file itu di-include belakangan.
        if (typeof hitungCascade === 'function') {
            RK_TOTAL_KLAIM_BULANAN = total;
            RK_TOTAL_KLAIM_DANA_INVESTOR = totalInvestor;
            RK_TOTAL_KLAIM_DANA_PUSAT = totalPusat;
            hitungCascade();
        }
    }

    function baruBaris() {
        const tr = document.createElement('tr');
        tr.className = 'klaim-row';
        tr.innerHTML =
            '<td class="text-center text-muted fw-medium klaim-no"></td>' +
            '<td><input type="text" class="form-control form-control-sm border-0 bg-transparent klaim-uraian" placeholder="Uraian klaim..."></td>' +
            '<td class="text-end"><input type="text" inputmode="numeric" class="form-control form-control-sm border-0 bg-transparent text-end fw-bold klaim-nominal" value="0"></td>' +
            '<td class="text-center"><select class="form-select form-select-sm border-0 bg-transparent klaim-sumber"><option value="warung" selected>Dana Warung</option><option value="investor">Dana Investor</option><option value="pusat">Dana Pusat</option></select></td>' +
            '<td class="ps-4"><input type="text" class="form-control form-control-sm border-0 bg-transparent klaim-keterangan" placeholder="Ketik keterangan..."></td>' +
            '<td class="text-center"><button type="button" class="btn btn-sm btn-link text-danger p-0 klaim-hapus" title="Hapus baris"><i class="bi bi-trash"></i></button></td>';
        return tr;
    }

    btnTambah.addEventListener('click', function () {
        const emptyRow = tbody.querySelector('.klaim-empty-row');
        if (emptyRow) emptyRow.remove();
        tbody.appendChild(baruBaris());
        hitungUlangNoDanTotal();
    });

    tbody.addEventListener('click', function (ev) {
        const btn = ev.target.closest('.klaim-hapus');
        if (!btn) return;
        btn.closest('tr').remove();
        if (!tbody.querySelector('tr.klaim-row')) {
            tbody.innerHTML = '<tr class="klaim-empty-row"><td colspan="6" class="text-center text-muted py-4"><i class="bi bi-inbox fs-4 d-block mb-1 opacity-50"></i>Belum ada klaim bulanan. Klik "+ Tambah Baris" untuk menambahkan.</td></tr>';
        }
        hitungUlangNoDanTotal();
    });

    tbody.addEventListener('input', function (ev) {
        if (!ev.target.classList.contains('klaim-nominal')) return;
        const el = ev.target;
        let cursorPosition = el.selectionStart;
        let oldLength = el.value.length;
        el.value = formatRibuanTitik(el.value);
        let newLength = el.value.length;
        cursorPosition += (newLength - oldLength);
        el.setSelectionRange(cursorPosition, cursorPosition);
        hitungUlangNoDanTotal();
    });

    tbody.addEventListener('change', function (ev) {
        if (ev.target.classList.contains('klaim-sumber')) hitungUlangNoDanTotal();
    });

    btnSimpan.addEventListener('click', function () {
        const rows = [];
        tbody.querySelectorAll('tr.klaim-row').forEach(function (tr, idx) {
            const uraian = tr.querySelector('.klaim-uraian').value.trim();
            const nominal = bersihkanAngka(tr.querySelector('.klaim-nominal').value);
            const sumber = tr.querySelector('.klaim-sumber').value;
            const keterangan = tr.querySelector('.klaim-keterangan').value.trim();
            if (uraian === '' && nominal === 0 && keterangan === '') return; // baris kosong, skip
            rows.push({ urutan: idx, uraian: uraian, nominal: nominal, sumber_dana: sumber, keterangan: keterangan });
        });

        const fd = new FormData();
        fd.append('csrf', <?= json_encode(csrf_token()) ?>);
        fd.append('id_cabang', <?= (int) $id_cabang ?>);
        fd.append('tahun', <?= (int) $tahun ?>);
        fd.append('bulan', <?= (int) $bulan ?>);
        fd.append('urutan_pengelola', <?= (int) ($urutan_pengelola_aktif ?? 1) ?>);
        fd.append('rows', JSON.stringify(rows));

        const asalHtml = btnSimpan.innerHTML;
        btnSimpan.disabled = true;
        fetch('rekap_klaim_bulanan_handler.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                btnSimpan.disabled = false;
                if (!data.ok) { alert('Gagal: ' + (data.msg || 'unknown')); return; }
                btnSimpan.innerHTML = '<i class="bi bi-check2 me-1"></i>Tersimpan';
                if (typeof data.total === 'number') totalCell.textContent = fmtRp(data.total);
                setTimeout(function () { btnSimpan.innerHTML = asalHtml; }, 1500);
            })
            .catch(function (err) {
                btnSimpan.disabled = false;
                alert('Gagal mengirim: ' + err);
            });
    });
})();
</script>
