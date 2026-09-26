<?php /** Partial: script kalkulasi matriks rekap (hitungCascade dkk) rekapitulasi.php — dipisah biar file utama tidak kegemukan. */ ?>
        <script>
            // Bagikan Blob PDF ke WhatsApp via menu share bawaan HP (Web Share API + file).
            // Redaksi/teks pesan WA otomatis mengikuti nama file PDF-nya (tanpa ".pdf") —
            // tidak perlu ditulis manual terpisah.
            // Fallback (desktop / browser tanpa dukungan share file): PDF didownload otomatis
            // dan WhatsApp Web dibuka dengan teks siap kirim, tinggal lampirkan filenya.
            async function sharePdfToWA(doc, filename) {
                const teks = filename.replace(/\.pdf$/i, '');
                const blob = doc.output('blob');
                const file = new File([blob], filename, { type: 'application/pdf' });

                if (navigator.canShare && navigator.canShare({ files: [file] })) {
                    try {
                        await navigator.share({ files: [file], title: 'Laporan WBB', text: teks });
                        return;
                    } catch (e) {
                        if (e && e.name === 'AbortError') return;
                    }
                }

                const a = document.createElement('a');
                a.href = URL.createObjectURL(blob);
                a.download = filename;
                a.click();
                window.open('https://wa.me/?text=' + encodeURIComponent(teks + ' (PDF terlampir, silakan unggah manual)'), '_blank');
            }

            function formatRupiah(angka) {
                return 'Rp ' + Math.round(angka || 0).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ".");
            }

            // Input nominal manual (Modal Awal, Kasbon Pengelola) — tampil dengan
            // pemisah ribuan "." (gaya Indonesia, sama seperti semua nilai Rupiah
            // lain di halaman ini) SAAT DIKETIK, bukan angka mentah "2000000".
            // angkaBersih() membaca kembali nilai bersihnya (tanpa titik) untuk kalkulasi.
            function formatRibuanTitik(str) {
                if (str === '' || str === null || str === undefined) return '';
                const bersih = str.toString().replace(/[^0-9]/g, '');
                if (bersih === '') return '';
                return parseInt(bersih, 10).toLocaleString('id-ID');
            }
            function angkaBersih(elId) {
                const el = document.getElementById(elId);
                if (!el) return 0;
                const bersih = (el.value || '').toString().replace(/[^0-9]/g, '');
                return bersih === '' ? 0 : parseInt(bersih, 10);
            }
            document.querySelectorAll('.mask-ribuan-titik').forEach(function (el) {
                el.addEventListener('input', function () {
                    let cursorPosition = this.selectionStart;
                    let oldLength = this.value.length;
                    this.value = formatRibuanTitik(this.value);
                    let newLength = this.value.length;
                    cursorPosition += (newLength - oldLength);
                    this.setSelectionRange(cursorPosition, cursorPosition);
                    hitungCascade();
                });
            });

            document.addEventListener('DOMContentLoaded', function() {
                const inputCabang = document.getElementById('inputCabang');
                const idCabang = document.getElementById('idCabang');
                const datalist = document.getElementById('listCabang');
                const formFilter = document.getElementById('formFilter');
                const periodeSelect = document.getElementById('periodeSelect');
                const bulanSelect = document.getElementById('bulanSelect');

                // 1. PAS KETIK / PILIH DARI DATALIST -> ISI HIDDEN
                inputCabang.addEventListener('input', function() {
                    let val = this.value;
                    let options = datalist.querySelectorAll('option');
                    let found = false;

                    options.forEach(opt => {
                        if (opt.value === val) {
                            idCabang.value = opt.getAttribute('data-id'); // bisa "" untuk Semua Cabang
                            found = true;
                        }
                    });

                    if (!found && val !== '') {
                        idCabang.value = ''; // reset kalau ketik ngaco
                    }
                });

                // 2. PAS GANTI PERIODE -> DISABLE BULAN + AUTO SUBMIT
                periodeSelect.addEventListener('change', function() {
                    bulanSelect.disabled = this.value === 'tahunan';
                    formFilter.submit(); // submit setelah bulan di disable
                });

                // 3. VALIDASI KETIKA KLIK TOMBOL "AMBIL DATA"
                formFilter.addEventListener('submit', function(e) {
                    let valCabang = inputCabang.value.trim();
                    
                    // Boleh "Semua Cabang" atau kosong
                    if (valCabang === '' || valCabang === 'Semua Cabang') {
                        idCabang.value = '';
                    }
                    
                    // Kalau ada tulisan tapi id kosong = ketik manual ga valid
                    if (valCabang !== '' && valCabang !== 'Semua Cabang' && idCabang.value === '') {
                        e.preventDefault();
                        alert('Pilih cabang dari daftar, jangan ketik manual!');
                        inputCabang.focus();
                    }
                });
            });

            // =========================================================
            // BASIS DARI SERVER
            // =========================================================
            const RK_NET_PROFIT_100 = <?= (float) ($laba_bersih_dasar ?? 0) ?>;      // Net Profit awal 100% (sebelum admin 3%)
            const RK_PERSEN_ADMIN   = <?= (float) ($persen_admin ?? 3) ?>;
            const RK_PERSEN_INV     = <?= (float) ($persen_investor ?? 50) ?>;
            const RK_PERSEN_PGL     = <?= (float) ($persen_pengelola ?? 50) ?>;
            // "5. Klaim Bulanan" — SEMUA baris (investor+warung) mengurangi Net
            // Profit SEBELUM admin fee dihitung. RK_TOTAL_KLAIM_DANA_INVESTOR =
            // subset baris ber-sumber_dana='investor' SAJA, otomatis jadi
            // Pengembalian Dana Talangan (Koreksi Dividen: Sisi Investor).
            // let (bukan const): di-update LIVE oleh _rekap_klaim_bulanan.php tiap
            // nominal/sumber dana diubah / baris ditambah / baris dihapus —
            // SEBELUM disimpan, supaya seluruh halaman langsung menyesuaikan
            // tanpa perlu reload.
            let RK_TOTAL_KLAIM_BULANAN = <?= (float) ($total_klaim_bulanan ?? 0) ?>;
            let RK_TOTAL_KLAIM_DANA_INVESTOR = <?= (float) ($total_klaim_dana_investor ?? 0) ?>;
            // Baris Klaim Bulanan ber-sumber_dana='pusat' — otomatis masuk Admin
            // Management Pusat (8. Rekapan Hasil Akhir), lihat updateFinalRekap().
            let RK_TOTAL_KLAIM_DANA_PUSAT = <?= (float) ($total_klaim_dana_pusat ?? 0) ?>;
            // Kasbon Pengelola kalau sumbernya "Dana Pusat" (bukan "Dana Investor")
            // — diisi ulang tiap hitungInvestor() dipanggil, dipakai updateFinalRekap().
            let RK_KASBON_DANA_PUSAT = 0;

            let RK_serviceFee       = 0;                    // service fee pengelola (dioper antar fungsi)
            let RK_adminFee         = 0;                    // admin fee 3% (dioper ke tombol "Simpan Revenue Sharing")
            let RK_netProfitEfektif = RK_NET_PROFIT_100;    // setelah dikurangi Modal Awal saja (Klaim Bulanan ditangani terpisah di hitungCascade)

            function setTxt(id, val) {
                const el = document.getElementById(id);
                if (el) el.innerText = val;
            }

            // Net Profit awal 100% dikurangi Modal Awal (matrik) saja — Klaim
            // Bulanan TIDAK di sini lagi (ditangani di hitungCascade, sebelum
            // admin fee dihitung). Tidak di-nol-kan: kalau rugi (minus) tetap
            // ditampilkan apa adanya.
            function getNetProfitEfektif() {
                const modalAwal = angkaBersih('matrik_modal_awal');
                return RK_NET_PROFIT_100 - modalAwal;
            }

            // =========================================================
            // KALKULASI BERANTAI — dipanggil tiap ada perubahan input
            // Urutan: Net Profit -> dikurangi Klaim Bulanan -> BARU admin fee
            // 3% dihitung dari sisanya -> split 50/50 investor-pengelola.
            // =========================================================
            function hitungCascade() {
                RK_netProfitEfektif = getNetProfitEfektif();

                const netProfitSetelahKlaim = RK_netProfitEfektif - RK_TOTAL_KLAIM_BULANAN;
                const adminFee       = netProfitSetelahKlaim * RK_PERSEN_ADMIN / 100;
                RK_adminFee          = adminFee;
                const labaSetelahAdm = netProfitSetelahKlaim - adminFee;
                const shareInvBase   = labaSetelahAdm * RK_PERSEN_INV / 100;
                const sharePglBase   = labaSetelahAdm * RK_PERSEN_PGL / 100;

                // 3. Matrik Akumulasi — Laba Bersih
                setTxt('matrik_laba_bersih', formatRupiah(RK_netProfitEfektif));

                // 4. Kontrak Pembagian Hasil (Revenue Sharing)
                setTxt('rev_net_profit',         formatRupiah(RK_netProfitEfektif));
                setTxt('rev_klaim_bulanan',      formatRupiah(RK_TOTAL_KLAIM_BULANAN));
                setTxt('rev_admin_fee',          formatRupiah(adminFee));
                setTxt('rev_laba_setelah_admin', formatRupiah(labaSetelahAdm));
                setTxt('rev_share_investor',     formatRupiah(shareInvBase));
                setTxt('pgl_share_kotor',        formatRupiah(sharePglBase));
                setTxt('rev_total_pembagian',    formatRupiah(shareInvBase + sharePglBase));

                // Sinkron ke panel Koreksi Dividen
                const invProfitEl = document.getElementById('inv_profit');
                if (invProfitEl) invProfitEl.value = shareInvBase;
                setTxt('inv_profit_val', formatRupiah(shareInvBase));

                const pglProfitEl = document.getElementById('pgl_profit');
                if (pglProfitEl) pglProfitEl.value = sharePglBase;
                const pglProfitDisp = document.getElementById('pgl_profit_display');
                if (pglProfitDisp) pglProfitDisp.value = formatRupiah(sharePglBase);

                // Pengembalian Dana Talangan — read-only, otomatis dari Klaim Bulanan.
                const invModalEl = document.getElementById('inv_modal');
                if (invModalEl) invModalEl.value = RK_TOTAL_KLAIM_DANA_INVESTOR;
                setTxt('inv_modal_val', formatRupiah(RK_TOTAL_KLAIM_DANA_INVESTOR));

                hitungInvestor();
            }

            // alias lama
            function hitungBO() { hitungCascade(); }

            // =========================================================
            // KOREKSI DIVIDEN — SISI INVESTOR
            // =========================================================
            function hitungInvestor() {
                const profit       = parseFloat(document.getElementById('inv_profit')?.value) || 0;
                const sewa         = parseFloat(document.getElementById('inv_sewa')?.value) || 0;
                const kasbon       = angkaBersih('inv_kasbon');
                const kasbonSumber = document.getElementById('inv_kasbon_sumber')?.value || 'investor';
                const talangan     = parseFloat(document.getElementById('inv_modal')?.value) || 0;
                const operatorSewa = document.getElementById('inv_sewa_operator')?.value || 'minus';

                let total = profit;
                total += (operatorSewa === 'plus') ? sewa : -sewa;

                // Kasbon Pengelola TETAP dipotong dari sisi Pengelola apapun
                // sumbernya (lihat hitungPengelola()) — tapi PENGGANTIANNYA cuma
                // masuk ke Investor kalau sumbernya "Dana Investor". Kalau
                // "Dana Pusat", penggantian itu masuk ke Admin Management Pusat
                // (RK_KASBON_DANA_PUSAT, dipakai updateFinalRekap()) — BUKAN ke
                // Investor.
                if (kasbonSumber === 'investor') {
                    total += kasbon;
                    RK_KASBON_DANA_PUSAT = 0;
                } else {
                    RK_KASBON_DANA_PUSAT = kasbon;
                }

                // Pengembalian Dana Talangan SELALU ditambahkan — sumbernya
                // sudah pasti "Dana Investor" (nilai ini hanya berisi total
                // baris Klaim Bulanan ber-sumber_dana='investor').
                total += talangan;

                total = Math.max(0, total);
                setTxt('inv_total', formatRupiah(total));

                hitungPengelola();
            }

            // =========================================================
            // KOREKSI DIVIDEN — SISI PENGELOLA
            // =========================================================
            function hitungPengelola() {
                const profit      = parseFloat(document.getElementById('pgl_profit')?.value) || 0;
                const adminPersen = parseFloat(document.getElementById('pgl_admin_persen')?.value) || 0;
                const kasbon      = angkaBersih('inv_kasbon');

                RK_serviceFee = (profit * adminPersen) / 100;
                const profitBersih = Math.max(0, profit - RK_serviceFee - kasbon);

                setTxt('pgl_total_profit', formatRupiah(profitBersih));
                setTxt('pgl_total_admin',  formatRupiah(RK_serviceFee));

                updateFinalRekap();
            }

            // =========================================================
            // 6. REKAP HASIL AKHIR — Management Pusat 3% + Service Fee
            // =========================================================
            function updateFinalRekap() {
                const finalInv = parseFloat(document.getElementById('inv_total')?.innerText.replace(/[^0-9-]/g, '') || 0);
                const finalPgl = parseFloat(document.getElementById('pgl_total_profit')?.innerText.replace(/[^0-9-]/g, '') || 0);

                // RK_adminFee sudah dihitung benar di hitungCascade() (dari Net
                // Profit SETELAH Klaim Bulanan) — jangan hitung ulang dari
                // RK_netProfitEfektif mentah di sini, akan salah (tidak ikut
                // potongan Klaim Bulanan). RK_TOTAL_KLAIM_DANA_PUSAT (dari Klaim
                // Bulanan "Dana Pusat") dan RK_KASBON_DANA_PUSAT (dari Kasbon
                // Pengelola bersumber "Dana Pusat") keduanya masuk ke Admin
                // Management Pusat juga.
                const adminTot = RK_adminFee + RK_serviceFee + RK_TOTAL_KLAIM_DANA_PUSAT + RK_KASBON_DANA_PUSAT;

                setTxt('final_inv',   formatRupiah(finalInv));
                setTxt('final_pgl',   formatRupiah(finalPgl));
                setTxt('final_admin', formatRupiah(adminTot));
            }

            document.addEventListener('DOMContentLoaded', hitungCascade);

            // =========================================================
            // 3. Matrik Akumulasi — tombol "Simpan Akumulasi" (Modal Awal)
            // Sama pola dengan simpanRevenueSharing(): AJAX ke handler khusus,
            // supaya nilai Modal Awal tidak hilang saat halaman di-refresh.
            // =========================================================
            function simpanAkumulasi(btn) {
                const modalAwal = angkaBersih('matrik_modal_awal');
                const asalHtml = btn.innerHTML;

                const fd = new FormData();
                fd.append('csrf', <?= json_encode(csrf_token()) ?>);
                fd.append('id_cabang', <?= (int) $id_cabang ?>);
                fd.append('tahun', <?= (int) $tahun ?>);
                fd.append('bulan', <?= (int) $bulan ?>);
                fd.append('modal_awal', modalAwal);

                btn.disabled = true;
                fetch('akumulasi_modal_awal_handler.php', { method: 'POST', body: fd, credentials: 'same-origin' })
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
                const btn = document.getElementById('btnSimpanAkumulasi');
                if (btn) btn.addEventListener('click', function () { simpanAkumulasi(btn); });
            })();
        </script>
