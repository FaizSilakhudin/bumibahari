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

            let RK_serviceFee       = 0;                    // service fee pengelola (dioper antar fungsi)
            let RK_netProfitEfektif = RK_NET_PROFIT_100;    // setelah dikurangi Modal Awal + Pengembalian Dana Talangan

            function setTxt(id, val) {
                const el = document.getElementById(id);
                if (el) el.innerText = val;
            }

            // Net Profit awal 100% dikurangi Modal Awal (matrik) + Pengembalian Dana Talangan (koreksi dividen investor)
            // Tidak di-nol-kan: kalau rugi (minus) tetap ditampilkan apa adanya.
            function getNetProfitEfektif() {
                const modalAwal = parseFloat(document.getElementById('matrik_modal_awal')?.value) || 0;
                const talangan  = parseFloat(document.getElementById('inv_modal')?.value) || 0;
                return RK_NET_PROFIT_100 - modalAwal - talangan;
            }

            // =========================================================
            // KALKULASI BERANTAI — dipanggil tiap ada perubahan input
            // =========================================================
            function hitungCascade() {
                RK_netProfitEfektif = getNetProfitEfektif();

                const adminFee       = RK_netProfitEfektif * RK_PERSEN_ADMIN / 100;
                const labaSetelahAdm = RK_netProfitEfektif - adminFee;
                const shareInvBase   = labaSetelahAdm * RK_PERSEN_INV / 100;
                const sharePglBase   = labaSetelahAdm * RK_PERSEN_PGL / 100;

                // 4. Matrik Akumulasi — Laba Bersih
                setTxt('matrik_laba_bersih', formatRupiah(RK_netProfitEfektif));

                // 5. Kontrak Pembagian Hasil (Revenue Sharing)
                setTxt('rev_net_profit',         formatRupiah(RK_netProfitEfektif));
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
                const kasbon       = parseFloat(document.getElementById('inv_kasbon')?.value) || 0;
                const talangan     = parseFloat(document.getElementById('inv_modal')?.value) || 0;
                const operatorSewa = document.getElementById('inv_sewa_operator')?.value || 'minus';
                const sumber       = document.getElementById('inv_sumber_talangan')?.value || 'investor';

                let total = profit;
                total += (operatorSewa === 'plus') ? sewa : -sewa;
                total += kasbon;

                // Pengembalian Dana Talangan:
                //  - "Dana Investor" -> uang kembali ke investor (total bersih investor bertambah)
                //  - "Dana Warung"   -> hanya mengurangi Net Profit awal (sudah ditangani di hitungCascade)
                if (sumber === 'investor') {
                    total += talangan;
                }

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
                const kasbon      = parseFloat(document.getElementById('inv_kasbon')?.value) || 0;

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

                const admin3   = RK_netProfitEfektif * RK_PERSEN_ADMIN / 100;
                const adminTot = admin3 + RK_serviceFee;

                setTxt('final_inv',   formatRupiah(finalInv));
                setTxt('final_pgl',   formatRupiah(finalPgl));
                setTxt('final_admin', formatRupiah(adminTot));
            }

            document.addEventListener('DOMContentLoaded', hitungCascade);
        </script>
