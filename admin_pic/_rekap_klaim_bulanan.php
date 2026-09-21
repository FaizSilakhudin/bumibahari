<?php
/**
 * Partial: "10. Klaim Bulanan" — daftar baris klaim manual pada Rekapitulasi.
 * Ditulis sekali, dicopy verbatim ke admin_pusat/ dan admin_pic/ (konvensi
 * proyek ini — lihat _rekap_tabel_harian.php, _rekap_script_export.php, dst).
 *
 * INPUT (dari scope pemanggil, rekapitulasi.php):
 *   $conn                   mysqli
 *   $id_cabang, $tahun, $bulan
 *   $daftar_klaim_bulanan   array  hasil query klaim_bulanan (lihat rekapitulasi.php)
 *   $total_klaim_bulanan    float
 *
 * Visual meniru tabel "2. Rincian Beban Operasional" (8 kolom header), tapi
 * hanya 4 sel yang hidup per baris: No (auto), Uraian, Nominal, Keterangan.
 * Kolom Harian/Bulanan/Tahunan/Di Bayarkan render "-" (tidak relevan utk
 * klaim manual, dipertahankan biar tabelnya simetris).
 *
 * Baris ditambah/dihapus bebas oleh user (JS murni) — saat Simpan, SEMUA
 * baris lama utk (id_cabang,tahun,bulan,urutan_pengelola) dihapus lalu
 * diganti set yang dikirim (lihat rekap_klaim_bulanan_handler.php).
 */
?>
<div class="card border-0 mb-4" style="overflow: hidden;">
    <div class="card-header bg-light border-bottom py-3 d-flex align-items-center justify-content-between">
        <span class="fw-bold text-dark"><i class="bi bi-receipt-cutoff me-2 text-muted"></i>10. Klaim Bulanan</span>
        <span class="badge bg-warning bg-opacity-10 text-warning-emphasis px-3 py-1.5 rounded-pill fw-medium" style="font-size: 0.75rem;">Mengurangi Net Profit Setelah Admin Fee</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 text-nowrap table-clean-input" id="tabelKlaimBulanan">
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
                        <th class="text-center" width="5%"></th>
                    </tr>
                </thead>
                <tbody id="klaimBulananBody">
                    <?php if (empty($daftar_klaim_bulanan)): ?>
                        <tr class="klaim-empty-row">
                            <td colspan="9" class="text-center text-muted py-4">
                                <i class="bi bi-inbox fs-4 d-block mb-1 opacity-50"></i>
                                Belum ada klaim bulanan. Klik "+ Tambah Baris" untuk menambahkan.
                            </td>
                        </tr>
                    <?php else: $kb_no = 1; foreach ($daftar_klaim_bulanan as $kb): ?>
                        <tr class="klaim-row">
                            <td class="text-center text-muted fw-medium klaim-no"><?= $kb_no++ ?></td>
                            <td><input type="text" class="form-control form-control-sm border-0 bg-transparent klaim-uraian" value="<?= h($kb['uraian']) ?>" placeholder="Uraian klaim..."></td>
                            <td class="text-center text-muted">-</td>
                            <td class="text-center text-muted">-</td>
                            <td class="text-center text-muted">-</td>
                            <td class="text-center text-muted">-</td>
                            <td class="text-end">
                                <input type="number" class="form-control form-control-sm border-0 bg-transparent text-end fw-bold klaim-nominal" value="<?= (float) $kb['nominal'] ?>" min="0" step="1000">
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
                        <td colspan="6" class="text-end fw-bold text-secondary py-3">TOTAL KLAIM BULANAN:</td>
                        <td class="text-end fw-bold text-warning-emphasis py-3 pe-3" id="klaimBulananTotal">Rp <?= number_format($total_klaim_bulanan, 0, ',', '.') ?></td>
                        <td></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
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
        let no = 1;
        tbody.querySelectorAll('tr.klaim-row').forEach(function (tr) {
            tr.querySelector('.klaim-no').textContent = no++;
            total += parseFloat(tr.querySelector('.klaim-nominal').value) || 0;
        });
        totalCell.textContent = fmtRp(total);
    }

    function baruBaris() {
        const tr = document.createElement('tr');
        tr.className = 'klaim-row';
        tr.innerHTML =
            '<td class="text-center text-muted fw-medium klaim-no"></td>' +
            '<td><input type="text" class="form-control form-control-sm border-0 bg-transparent klaim-uraian" placeholder="Uraian klaim..."></td>' +
            '<td class="text-center text-muted">-</td>' +
            '<td class="text-center text-muted">-</td>' +
            '<td class="text-center text-muted">-</td>' +
            '<td class="text-center text-muted">-</td>' +
            '<td class="text-end"><input type="number" class="form-control form-control-sm border-0 bg-transparent text-end fw-bold klaim-nominal" value="0" min="0" step="1000"></td>' +
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
            tbody.innerHTML = '<tr class="klaim-empty-row"><td colspan="9" class="text-center text-muted py-4"><i class="bi bi-inbox fs-4 d-block mb-1 opacity-50"></i>Belum ada klaim bulanan. Klik "+ Tambah Baris" untuk menambahkan.</td></tr>';
        }
        hitungUlangNoDanTotal();
    });

    tbody.addEventListener('input', function (ev) {
        if (ev.target.classList.contains('klaim-nominal')) hitungUlangNoDanTotal();
    });

    btnSimpan.addEventListener('click', function () {
        const rows = [];
        tbody.querySelectorAll('tr.klaim-row').forEach(function (tr, idx) {
            const uraian = tr.querySelector('.klaim-uraian').value.trim();
            const nominal = parseFloat(tr.querySelector('.klaim-nominal').value) || 0;
            const keterangan = tr.querySelector('.klaim-keterangan').value.trim();
            if (uraian === '' && nominal === 0 && keterangan === '') return; // baris kosong, skip
            rows.push({ urutan: idx, uraian: uraian, nominal: nominal, keterangan: keterangan });
        });

        const fd = new FormData();
        fd.append('csrf', <?= json_encode(csrf_token()) ?>);
        fd.append('id_cabang', <?= (int) $id_cabang ?>);
        fd.append('tahun', <?= (int) $tahun ?>);
        fd.append('bulan', <?= (int) $bulan ?>);
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
