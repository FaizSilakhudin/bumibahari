<?php /** Partial: script export PDF (Harian & Bulanan) rekapitulasi.php — dipisah biar file utama tidak kegemukan. Butuh variabel PHP dari scope pemanggil (nama_cabang, pengelola, dst) via <?= ?> di dalamnya. */ ?>
        <script>
            // Pewarnaan kolom tabel "1. Rekapitulasi Pendapatan & Pengeluaran Harian" saat
            // di-export ke PDF — menyamai warna yang tampil di layar (lihat _rekap_tabel_harian.php).
            // Baris harian, kolom (index 0-based): 14 Total Pengeluaran -> merah,
            // 15 Sisa Tunai -> hitam (merah kalau minus), 16 Net Profit -> hijau (merah kalau minus),
            // 17 Margin -> biru (merah kalau minus).
            // Baris JUMLAH (tfoot): latar hijau, semua tulisan putih — KECUALI kolom 16/17 yang
            // ikut jadi merah kalau nilainya minus, menimpa putih, sama seperti di layar.
            //
            // Catatan: dengan sumber html:, data.cell.raw untuk sel dari HTML adalah elemen DOM
            // <td> aslinya (BUKAN string) — jangan di-String()-kan langsung untuk cek isi teks,
            // ambil .textContent-nya. Deteksi baris JUMLAH juga dicek langsung lewat
            // tr.closest('tfoot') supaya tidak bergantung 100% pada data.section (beberapa versi
            // jspdf-autotable kurang konsisten mengklasifikasikan baris tfoot lewat html:).
            function rekapHarianDidParseCell(data) {
                if (data.section === 'head') return;

                function teksSel() {
                    const raw = data.cell.raw;
                    return (raw && typeof raw === 'object' && typeof raw.textContent === 'string')
                        ? raw.textContent
                        : (data.cell.text || []).join(' ');
                }
                function minus() { return teksSel().indexOf('-') !== -1; }

                const tr = data.row && data.row.raw;
                const isFoot = data.section === 'foot' || !!(tr && typeof tr.closest === 'function' && tr.closest('tfoot'));
                const idx = data.column.index;

                if (isFoot) {
                    data.cell.styles.fillColor = [25, 135, 84];
                    data.cell.styles.textColor = [255, 255, 255];
                    if ((idx === 16 || idx === 17) && minus()) {
                        data.cell.styles.textColor = [220, 53, 69];
                    }
                    return;
                }

                if (idx === 14) {
                    data.cell.styles.textColor = [220, 53, 69];
                } else if (idx === 15) {
                    if (minus()) data.cell.styles.textColor = [220, 53, 69];
                } else if (idx === 16) {
                    data.cell.styles.textColor = minus() ? [220, 53, 69] : [25, 135, 84];
                } else if (idx === 17) {
                    data.cell.styles.textColor = minus() ? [220, 53, 69] : [13, 110, 253];
                }
            }

            // =========================================================
            // EXPORT PDF HARIAN
            //  Hal.1 : 1. Rekapitulasi Pendapatan & Pengeluaran Harian - bulan sebelumnya
            //  Hal.2 : 1. Rekapitulasi Pendapatan & Pengeluaran Harian - bulan berjalan
            //  Hal.3 : 2. Rincian Beban Operasional - bulan berjalan saja
            // =========================================================
            async function exportPdfHarian(mode) {
                const { jsPDF } = window.jspdf;
                const doc = new jsPDF('landscape', 'mm', 'a4');
                const margin = 14;

                const namaCabang = <?= json_encode($nama_cabang ?? 'WBB Cabang') ?>;
                const pengelola  = <?= json_encode($pengelola['nama_pengelola'] ?? '-') ?>;
                const investor   = <?= json_encode($cabang_info['investor'] ?? '-') ?>;
                const picHarian  = <?= json_encode($nama_pic ?? '-') ?>;
                const blnIni     = <?= json_encode(date('F Y', strtotime("$tahun-$bulan-01"))) ?>;
                const blnLalu    = <?= json_encode(date('F Y', mktime(0, 0, 0, (int) $bulan_prev, 1, (int) $tahun_prev))) ?>;
                const ALAMAT     = <?= json_encode($alamat_cabang ?? "Kantor Pusat : Jl. Pamulang Permai Raya, Pamulang Bar., Kec. Pamulang, Kota Tangerang Selatan, Banten 15417") ?>;
                const PHONE      = 'Phone : <?= h($no_hp_cabang ?? "087784838769") ?>';

                const baseStyles = {
                    theme: 'grid',
                    styles: { fontSize: 6.5, cellPadding: 1, overflow: 'linebreak' },
                    headStyles: { fillColor: [33, 37, 41], textColor: 255, halign: 'center', fontSize: 6.5 },
                    includeHiddenHtml: true
                };

                const addWatermark = () => {
                    const imgLogo = document.getElementById('logoWWB');
                    if (!imgLogo) return;
                    try {
                        const wmSize = 80, wmX = (297 - wmSize) / 2, wmY = (210 - wmSize) / 2;
                        doc.saveGraphicsState();
                        doc.setGState(new doc.GState({ opacity: 0.08 }));
                        doc.addImage(imgLogo, 'PNG', wmX, wmY, wmSize, wmSize);
                        doc.restoreGraphicsState();
                    } catch (e) { console.warn('watermark:', e); }
                };

                // Kop surat — DISAMAKAN dengan Export PDF. Return: startY untuk tabel.
                function kop(title, periodeLabel) {
                    addWatermark();
                    const startYContent = 12;
                    let textXPosition = margin;
                    const imgLogo = document.getElementById('logoWWB');
                    let logoHeight = 16, logoWidth = 16;
                    if (imgLogo) {
                        try {
                            const ratio = (imgLogo.naturalWidth || 1) / (imgLogo.naturalHeight || 1);
                            logoWidth = logoHeight * ratio;
                            doc.addImage(imgLogo, 'PNG', margin, startYContent, logoWidth, logoHeight);
                            textXPosition = margin + logoWidth + 5;
                        } catch (e) { textXPosition = margin; }
                    }
                    const textYStart = imgLogo ? startYContent + 4.5 : startYContent;
                    doc.setFont('helvetica', 'bold'); doc.setFontSize(14); doc.setTextColor(40, 40, 40);
                    doc.text('WARTEG BUMI BAHARI', textXPosition, textYStart);
                    doc.setFont('helvetica', 'normal'); doc.setFontSize(8.5); doc.setTextColor(100, 100, 100);
                    // Alamat dibatasi lebar 140mm supaya panjang jadi maks. 2 baris & tidak
                    // menabrak blok keterangan Cabang/Pengelola/dst di sebelah kanan.
                    const alamatLines = doc.splitTextToSize(ALAMAT, 140);
                    doc.text(alamatLines, textXPosition, textYStart + 5);
                    doc.text(PHONE, textXPosition, textYStart + 5 + alamatLines.length * 3.5);
                    doc.autoTable({
                        body: [
                            ['Cabang', ': ' + namaCabang],
                            ['Periode', ': ' + periodeLabel],
                            ['Pengelola', ': ' + pengelola],
                            ['Investor', ': ' + investor],
                            ['PIC', ': ' + picHarian]
                        ],
                        startY: imgLogo ? startYContent + 1.5 : startYContent - 3, theme: 'plain',
                        styles: { fontSize: 8.5, cellPadding: 0.3, fontStyle: 'bold', textColor: [40, 40, 40] },
                        columnStyles: { 0: { cellWidth: 20 }, 1: { cellWidth: 80 } },
                        // Kanan blok ini disamakan dengan ujung kanan garis pembatas di bawah kop
                        // (doc.line(margin, yLine, 283, yLine)) -> 283 - (20+80) = 183.
                        margin: { left: 183, right: margin }
                    });
                    const yLine = startYContent + 24;
                    doc.setDrawColor(200, 200, 200); doc.setLineWidth(0.4); doc.line(margin, yLine, 283, yLine);
                    doc.setFont('helvetica', 'bold'); doc.setFontSize(11); doc.setTextColor(0, 0, 0);
                    doc.text(title, margin, yLine + 7);
                    return yLine + 11;
                }

                // Hal. 1 — Rekap harian bulan berjalan
                let ty = kop('1. Rekapitulasi Pendapatan & Pengeluaran Harian - ' + blnIni, blnIni);
                doc.autoTable({ html: '#tabelRekapHarian', startY: ty, ...baseStyles, didParseCell: rekapHarianDidParseCell });

                // Hal. 2 — Rekap harian bulan sebelumnya
                doc.addPage('a4', 'landscape');
                ty = kop('1. Rekapitulasi Pendapatan & Pengeluaran Harian - ' + blnLalu, blnLalu);
                doc.autoTable({ html: '#tabelRekapHarianPrev', startY: ty, ...baseStyles, didParseCell: rekapHarianDidParseCell });

                // Hal. 3 — Rincian Beban Operasional bulan berjalan
                doc.addPage('a4', 'landscape');
                ty = kop('2. Rincian Beban Operasional - ' + blnIni, blnIni);
                const t2 = document.querySelector('.table-clean-input');
                const el2 = t2 && (t2.tagName === 'TABLE' ? t2 : t2.querySelector('table'));
                if (el2) {
                    doc.autoTable({ html: el2, startY: ty, ...baseStyles, styles: { fontSize: 8, cellPadding: 1.5 } });
                }

                // Nama file: "Update Laporan Pembukuan Harian <Cabang> <tanggal LAPORAN>" —
                // pakai tanggal KEMARIN (bukan tanggal cetak/kirim), karena laporan harian
                // selalu berisi data transaksi kemarin (cabang input hari ini untuk kemarin).
                // Nama cabang ada di nama file, TANPA teks/redaksi terpisah saat dikirim ke WA.
                const filenameHarian = <?= json_encode(
                    'Update Laporan Pembukuan Harian ' . $nama_cabang . ' ' . date('j', strtotime('-1 day')) . ' ' . nama_bulan_id((int) date('n', strtotime('-1 day'))) . ' ' . date('Y', strtotime('-1 day')) . '.pdf'
                ) ?>;
                if (mode === 'share') {
                    await sharePdfToWA(doc, filenameHarian);
                } else {
                    doc.save(filenameHarian);
                }
            }

            async function exportPDF(mode) {
                const { jsPDF } = window.jspdf;
                let doc = new jsPDF('landscape', 'mm', 'a4');

                const margin = 14;
                const baseTableStyles = {
                    theme: 'grid',
                    styles: { fontSize: 8, cellPadding: 2 },
                    headStyles: { fillColor: [52, 58, 64], textColor: 255, halign: 'center' }
                };

                function parseAngka(val) {
                    return parseFloat(String(val || 0).replace(/[^0-9,.-]+/g, "").replace(/\./g, "").replace(",", ".")) || 0;
                }

                function formatRupiahPDF(angka) {
                    return 'Rp ' + Math.round(angka || 0).toLocaleString('id-ID');
                }

                const omzet_akumulasi = <?= (float)$penjualan ?>;
                const belanja_akumulasi = <?= (float)$belanja_akumulasi ?>;
                const bo_akumulasi = <?= (float)$bo_akumulasi ?>;
                const pengeluaran_akumulasi = <?= (float)$pengeluaran_akumulasi ?>;

                // Net Profit awal 100% dikurangi Modal Awal (manual) + Pengembalian Dana Talangan
                const net_profit_100  = <?= (float) ($laba_bersih_dasar ?? 0) ?>;
                const persen_admin    = <?= (float) ($persen_admin ?? 3) ?>;
                const persen_investor = <?= (float) ($persen_investor ?? 50) ?>;
                const persen_pengelola = <?= (float) ($persen_pengelola ?? 50) ?>;
                const modal_awal      = parseFloat(document.getElementById('matrik_modal_awal')?.value || 0);
                const talangan_val    = parseFloat(document.getElementById('inv_modal')?.value || 0);
                const laba_akumulasi  = net_profit_100 - modal_awal - talangan_val;   // = Net Profit efektif
                const admin_fee_val   = laba_akumulasi * persen_admin / 100;
                const laba_setelah_admin = laba_akumulasi - admin_fee_val;
                const share_inv_base  = laba_setelah_admin * persen_investor / 100;
                const share_pgl_base  = laba_setelah_admin * persen_pengelola / 100;

                const addWatermark = (pdfDoc) => {
                    let imgLogo = document.getElementById('logoWWB');
                    if (imgLogo) {
                        try {
                            const pageWidth = 297; const pageHeight = 210; const wmSize = 80;
                            const wmX = (pageWidth - wmSize) / 2; const wmY = (pageHeight - wmSize) / 2;
                            pdfDoc.saveGraphicsState();
                            pdfDoc.setGState(new pdfDoc.GState({ opacity: 0.08 }));
                            pdfDoc.addImage(imgLogo, 'PNG', wmX, wmY, wmSize, wmSize);
                            pdfDoc.restoreGraphicsState();
                        } catch (err) { console.warn("Gagal memuat watermark:", err); }
                    }
                };

                // HALAMAN 1
                addWatermark(doc);
                let startYContent = 12; let textXPosition = margin;
                let imgLogo = document.getElementById('logoWWB'); let logoHeight = 16; let logoWidth = 16;
                if (imgLogo) { try { let ratio = (imgLogo.naturalWidth||1)/(imgLogo.naturalHeight||1); logoWidth = logoHeight * ratio; doc.addImage(imgLogo, 'PNG', margin, startYContent, logoWidth, logoHeight); textXPosition = margin + logoWidth + 5; } catch (e) { textXPosition = margin; } }
                let textYStart = imgLogo ? startYContent + 4.5 : startYContent;
                doc.setFont('helvetica', 'bold'); doc.setFontSize(14); doc.setTextColor(40, 40, 40); doc.text('WARTEG BUMI BAHARI', textXPosition, textYStart);
                doc.setFont('helvetica', 'normal'); doc.setFontSize(8.5); doc.setTextColor(100, 100, 100);
                // Alamat dibatasi lebar 140mm supaya panjang jadi maks. 2 baris & tidak
                // menabrak blok keterangan Cabang/Pengelola/dst di sebelah kanan.
                let alamatLinesHal1 = doc.splitTextToSize(<?= json_encode($alamat_cabang ?? "Kantor Pusat : Jl. Pamulang Permai Raya, Pamulang Bar., Kec. Pamulang, Kota Tangerang Selatan, Banten 15417") ?>, 140);
                doc.text(alamatLinesHal1, textXPosition, textYStart + 5);
                doc.text('Phone : <?= h($no_hp_cabang ?? "087784838769") ?>', textXPosition, textYStart + 5 + alamatLinesHal1.length * 3.5);
                let infoData = [
                    ['Cabang', ': <?= h($nama_cabang ?? "WBB Cabang") ?>'],
                    ['Periode', ': <?= date("F Y", strtotime("$tahun-$bulan-01")) ?>'],
                    ['Pengelola', ': <?= h($pengelola['nama_pengelola'] ?? "-") ?>'],
                    ['Investor', ': <?= h($cabang_info['investor'] ?? "-") ?>'],
                    ['PIC', ': <?= h($nama_pic ?? "-") ?>']
                ];
                doc.autoTable({ body: infoData, startY: imgLogo ? startYContent + 1.5 : startYContent - 3, theme: 'plain', styles: { fontSize: 8.5, cellPadding: 0.3, fontStyle: 'bold', textColor: [40, 40, 40] }, columnStyles: { 0: { cellWidth: 20 }, 1: { cellWidth: 80 } }, margin: { left: 183, right: margin } });
                let yLine = startYContent + 24; doc.setDrawColor(200, 200, 200); doc.setLineWidth(0.4); doc.line(margin, yLine, 283, yLine);
                let yTabelHarian = yLine + 7; doc.setFont('helvetica', 'bold'); doc.setFontSize(11); doc.setTextColor(0, 0, 0);
                doc.text('1. Rekapitulasi Pendapatan & Pengeluaran Harian - <?= date("F Y", strtotime("$tahun-$bulan-01")) ?>', margin, yTabelHarian);
                doc.autoTable({ html: '#tabelRekapHarian', startY: yTabelHarian + 4, ...baseTableStyles, styles: { fontSize: 7, cellPadding: 1.2 }, didParseCell: rekapHarianDidParseCell });

                // HALAMAN 2
                doc.addPage(); addWatermark(doc); let y = 15;
                doc.setFontSize(12); doc.setFont('helvetica', 'bold'); doc.text('2. Rincian Beban Operasional', margin, y);
                let t2 = document.querySelector('.table-clean-input'); let elTabel2 = t2?.tagName === 'TABLE' ? t2 : t2?.querySelector('table');
                if (elTabel2) { doc.autoTable({ html: elTabel2, startY: y + 5, ...baseTableStyles }); y = doc.lastAutoTable.finalY + 12; } else { y += 15; }
                doc.setFontSize(12); doc.setFont('helvetica', 'bold'); doc.text('3. Matriks Akumulasi', margin, y);
                let dataMatriks = [
                    ['Omzet Penjualan', formatRupiahPDF(omzet_akumulasi), 'Pendapatan bruto masuk'],
                    ['Pengeluaran Belanja', formatRupiahPDF(belanja_akumulasi), 'Total belanja 1 bulan'],
                    ['Beban Operasional', formatRupiahPDF(bo_akumulasi), 'Total BO 1 bulan'],
                    ['Total Pengeluaran', formatRupiahPDF(pengeluaran_akumulasi), 'Belanja + BO'],
                    ['Modal Awal', formatRupiahPDF(modal_awal), 'Diisi manual, mengurangi Net Profit awal'],
                    ['Laba Bersih (Net Profit efektif)', formatRupiahPDF(laba_akumulasi), 'Net Profit 100% - Modal Awal - Dana Talangan'],
                ];
                doc.autoTable({ head: [['Komponen Pokok', 'Jumlah', 'Catatan Ringkas']], body: dataMatriks, startY: y + 5, ...baseTableStyles });

                // HALAMAN 3 - FIX
                doc.addPage(); addWatermark(doc); y = 15;
                doc.setFontSize(12); doc.setFont('helvetica', 'bold'); doc.text('4. Koreksi Dividen: Sisi Investor', margin, y);

                const inv_profit = share_inv_base;
                const inv_sewa = parseFloat(document.getElementById('inv_sewa')?.value || <?= (float)($bo_db['sewa'] ?? 0) ?>);
                const inv_modal = talangan_val;
                const inv_kasbon = parseFloat(document.getElementById('inv_kasbon')?.value || 0);
                const operatorSewa = document.getElementById('inv_sewa_operator')?.value || 'minus';
                const inv_sumber = document.getElementById('inv_sumber_talangan')?.value || 'investor';
                const inv_modal_ket = (document.getElementById('inv_modal_ket')?.value || '').trim();

                let inv_total_val = inv_profit;
                if (operatorSewa === 'plus') inv_total_val += inv_sewa; else inv_total_val -= inv_sewa;
                inv_total_val += inv_kasbon;
                if (inv_sumber === 'investor') inv_total_val += inv_modal;   // pengembalian talangan kembali ke investor
                inv_total_val = Math.max(0, inv_total_val);

                let dataInvestor = [
                    ['Profit Investor (50%)', formatRupiahPDF(inv_profit)],
                    ['Potongan Sewa Ruko ' + (operatorSewa === 'plus' ? '(+)' : '(-)'), formatRupiahPDF(inv_sewa)],
                    ['Pengembalian Dana Talangan (' + (inv_sumber === 'investor' ? 'Dana Investor' : 'Dana Warung') + ')' + (inv_modal_ket ? ' — ' + inv_modal_ket : ''), formatRupiahPDF(inv_modal)],
                    ['Penambahan/Pengembalian Kasbon Pengelola', formatRupiahPDF(inv_kasbon)],
                    ['TOTAL BERSIH INVESTOR', formatRupiahPDF(inv_total_val)],
                ];
                doc.autoTable({ head: [['Keterangan Komponen', 'Nilai']], body: dataInvestor, startY: y + 5, ...baseTableStyles });
                y = doc.lastAutoTable.finalY + 12;

                doc.setFontSize(12); doc.setFont('helvetica', 'bold'); doc.text('5. Koreksi Dividen: Sisi Pengelola', margin, y);

                const pgl_profit = share_pgl_base;
                const elSelectFee = document.getElementById('pgl_admin_persen');
                const pct_admin = parseFloat(elSelectFee?.value || <?= (float)($persen_admin ?? 3) ?>);
                const pgl_service_fee = (pgl_profit * pct_admin) / 100;
                const pgl_kasbon = parseFloat(document.getElementById('inv_kasbon')?.value || 0);
                const pct_bersih = 50 - pct_admin; // FIX: ini yang tadinya undefined
                const pgl_total_val = Math.max(0, pgl_profit - pgl_service_fee - pgl_kasbon);

                const str_pct_admin = pct_admin.toString().replace('.', ',');
                const str_pct_bersih = pct_bersih.toString().replace('.', ',');

                let dataPengelola = [
                    ['Profit Pengelola (50%)', formatRupiahPDF(pgl_profit)],
                    [`Service Fee / Admin (${str_pct_admin}%)`, formatRupiahPDF(pgl_service_fee)], // FIX: pgl_service_fee
                    ['Potongan Kasbon', formatRupiahPDF(pgl_kasbon)],
                    [`TOTAL BERSIH PENGELOLA (${str_pct_bersih}%)`, formatRupiahPDF(pgl_total_val)]
                ];  
                doc.autoTable({ head: [['Keterangan Komponen', 'Nilai']], body: dataPengelola, startY: y + 5, ...baseTableStyles });
                y = doc.lastAutoTable.finalY + 12;

                // UPDATE FINAL DULU BARU AMBIL TABLE
                if (document.getElementById('final_inv')) document.getElementById('final_inv').innerText = formatRupiahPDF(inv_total_val);
                if (document.getElementById('final_pgl')) document.getElementById('final_pgl').innerText = formatRupiahPDF(pgl_total_val);
                const admin3Persen = admin_fee_val; // 3% dari Net Profit efektif
                const totalAdminGabungan = admin3Persen + pgl_service_fee;
                if (document.getElementById('final_admin')) document.getElementById('final_admin').innerText = formatRupiahPDF(totalAdminGabungan);

                doc.setFontSize(12); doc.setFont('helvetica', 'bold'); doc.text('6. Rekapan Hasil Akhir Keuntungan (Distribusi Payroll)', margin, y);
                let wrapperPayroll = document.querySelector('.card.border-0.mb-5'); 
                let elTabel6 = wrapperPayroll?.querySelector('table');
                if (elTabel6) {
                    doc.autoTable({ html: elTabel6, startY: y + 5, ...baseTableStyles, columnStyles: { 5: { halign: 'right' } } });
                }
                const filenameFull = "<?= h($nama_file_export) ?>.pdf";
                if (mode === 'share') {
                    await sharePdfToWA(doc, filenameFull);
                } else {
                    doc.save(filenameFull);
                }
            }

            // =========================================================
            // EXPORT EXCEL HARIAN — sheet per sheet menyamai isi Export PDF Harian:
            //  Sheet 1: Rekap harian bulan berjalan
            //  Sheet 2: Rekap harian bulan sebelumnya
            //  Sheet 3: Rincian Beban Operasional bulan berjalan
            // Catatan penting: sheet_add_dom() HARUS pakai { raw: true } — tanpa itu
            // SheetJS mencoba menebak angka dari teks tampilan & salah kaprah dengan
            // format Indonesia (mis. "500.000" terbaca sebagai 500, bukan 500000).
            // { raw: true } menjaga sel tetap berisi teks tampilan apa adanya.
            // =========================================================
            function exportExcelHarian() {
                const namaCabang = <?= json_encode($nama_cabang ?? 'WBB Cabang') ?>;
                const pengelola  = <?= json_encode($pengelola['nama_pengelola'] ?? '-') ?>;
                const investor   = <?= json_encode($cabang_info['investor'] ?? '-') ?>;
                const picHarian  = <?= json_encode($nama_pic ?? '-') ?>;
                const blnIni     = <?= json_encode(date('F Y', strtotime("$tahun-$bulan-01"))) ?>;
                const blnLalu    = <?= json_encode(date('F Y', mktime(0, 0, 0, (int) $bulan_prev, 1, (int) $tahun_prev))) ?>;

                function sheetInfoTabel(judul, periodeLabel, tableEl) {
                    const ws = XLSX.utils.aoa_to_sheet([
                        ['WARTEG BUMI BAHARI'],
                        [judul],
                        [],
                        ['Cabang', namaCabang],
                        ['Periode', periodeLabel],
                        ['Pengelola', pengelola],
                        ['Investor', investor],
                        ['PIC', picHarian],
                        [],
                    ]);
                    if (tableEl) XLSX.utils.sheet_add_dom(ws, tableEl, { origin: -1, raw: true });
                    return ws;
                }

                const wb = XLSX.utils.book_new();
                XLSX.utils.book_append_sheet(wb,
                    sheetInfoTabel('1. Rekapitulasi Pendapatan & Pengeluaran Harian - ' + blnIni, blnIni, document.querySelector('#tabelRekapHarian')),
                    'Rekap Bulan Ini');
                XLSX.utils.book_append_sheet(wb,
                    sheetInfoTabel('1. Rekapitulasi Pendapatan & Pengeluaran Harian - ' + blnLalu, blnLalu, document.querySelector('#tabelRekapHarianPrev')),
                    'Rekap Bulan Lalu');

                const t2 = document.querySelector('.table-clean-input');
                const elBO = t2 && (t2.tagName === 'TABLE' ? t2 : t2.querySelector('table'));
                const wsBO = XLSX.utils.aoa_to_sheet([['2. Rincian Beban Operasional - ' + blnIni], []]);
                if (elBO) XLSX.utils.sheet_add_dom(wsBO, elBO, { origin: -1, raw: true });
                XLSX.utils.book_append_sheet(wb, wsBO, 'Rincian BO');

                // Tanggal KEMARIN juga di sini, samakan dengan filenameHarian (PDF) di atas.
                const filenameHarianXLS = <?= json_encode(
                    'Update Laporan Pembukuan Harian ' . $nama_cabang . ' ' . date('j', strtotime('-1 day')) . ' ' . nama_bulan_id((int) date('n', strtotime('-1 day'))) . ' ' . date('Y', strtotime('-1 day')) . '.xlsx'
                ) ?>;
                XLSX.writeFile(wb, filenameHarianXLS);
            }

            // =========================================================
            // EXPORT EXCEL BULANAN — sheet per sheet menyamai isi Export PDF (lengkap):
            //  Rekap Harian, Rincian BO, Matriks Akumulasi, Koreksi Investor,
            //  Koreksi Pengelola, Distribusi Payroll. Rumus & nilai SAMA PERSIS dengan
            //  exportPDF() — dibaca dari input form yang sama pada saat tombol diklik.
            // =========================================================
            function exportExcel() {
                function formatRupiahXLS(angka) {
                    return 'Rp ' + Math.round(angka || 0).toLocaleString('id-ID');
                }

                const omzet_akumulasi = <?= (float)$penjualan ?>;
                const belanja_akumulasi = <?= (float)$belanja_akumulasi ?>;
                const bo_akumulasi = <?= (float)$bo_akumulasi ?>;
                const pengeluaran_akumulasi = <?= (float)$pengeluaran_akumulasi ?>;

                const net_profit_100  = <?= (float) ($laba_bersih_dasar ?? 0) ?>;
                const persen_admin    = <?= (float) ($persen_admin ?? 3) ?>;
                const persen_investor = <?= (float) ($persen_investor ?? 50) ?>;
                const persen_pengelola = <?= (float) ($persen_pengelola ?? 50) ?>;
                const modal_awal      = parseFloat(document.getElementById('matrik_modal_awal')?.value || 0);
                const talangan_val    = parseFloat(document.getElementById('inv_modal')?.value || 0);
                const laba_akumulasi  = net_profit_100 - modal_awal - talangan_val;
                const admin_fee_val   = laba_akumulasi * persen_admin / 100;
                const laba_setelah_admin = laba_akumulasi - admin_fee_val;
                const share_inv_base  = laba_setelah_admin * persen_investor / 100;
                const share_pgl_base  = laba_setelah_admin * persen_pengelola / 100;

                const namaCabangX = <?= json_encode($nama_cabang ?? 'WBB Cabang') ?>;
                const pengelolaX  = <?= json_encode($pengelola['nama_pengelola'] ?? '-') ?>;
                const investorX   = <?= json_encode($cabang_info['investor'] ?? '-') ?>;
                const picX        = <?= json_encode($nama_pic ?? '-') ?>;
                const periodeX    = <?= json_encode(date('F Y', strtotime("$tahun-$bulan-01"))) ?>;

                const wb = XLSX.utils.book_new();

                // Sheet 1: Rekap Harian
                const ws1 = XLSX.utils.aoa_to_sheet([
                    ['WARTEG BUMI BAHARI'],
                    ['1. Rekapitulasi Pendapatan & Pengeluaran Harian - ' + periodeX],
                    [],
                    ['Cabang', namaCabangX],
                    ['Periode', periodeX],
                    ['Pengelola', pengelolaX],
                    ['Investor', investorX],
                    ['PIC', picX],
                    [],
                ]);
                const tblRekap = document.querySelector('#tabelRekapHarian');
                if (tblRekap) XLSX.utils.sheet_add_dom(ws1, tblRekap, { origin: -1, raw: true });
                XLSX.utils.book_append_sheet(wb, ws1, 'Rekap Harian');

                // Sheet 2: Rincian Beban Operasional
                const t2 = document.querySelector('.table-clean-input');
                const elBO = t2 && (t2.tagName === 'TABLE' ? t2 : t2.querySelector('table'));
                const ws2 = XLSX.utils.aoa_to_sheet([['2. Rincian Beban Operasional'], []]);
                if (elBO) XLSX.utils.sheet_add_dom(ws2, elBO, { origin: -1, raw: true });
                XLSX.utils.book_append_sheet(wb, ws2, 'Rincian BO');

                // Sheet 3: Matriks Akumulasi
                const dataMatriksX = [
                    ['Komponen Pokok', 'Jumlah', 'Catatan Ringkas'],
                    ['Omzet Penjualan', formatRupiahXLS(omzet_akumulasi), 'Pendapatan bruto masuk'],
                    ['Pengeluaran Belanja', formatRupiahXLS(belanja_akumulasi), 'Total belanja 1 bulan'],
                    ['Beban Operasional', formatRupiahXLS(bo_akumulasi), 'Total BO 1 bulan'],
                    ['Total Pengeluaran', formatRupiahXLS(pengeluaran_akumulasi), 'Belanja + BO'],
                    ['Modal Awal', formatRupiahXLS(modal_awal), 'Diisi manual, mengurangi Net Profit awal'],
                    ['Laba Bersih (Net Profit efektif)', formatRupiahXLS(laba_akumulasi), 'Net Profit 100% - Modal Awal - Dana Talangan'],
                ];
                XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet([['3. Matriks Akumulasi'], [], ...dataMatriksX]), 'Matriks Akumulasi');

                // Sheet 4: Koreksi Dividen — Investor
                const inv_profit = share_inv_base;
                const inv_sewa = parseFloat(document.getElementById('inv_sewa')?.value || <?= (float)($bo_db['sewa'] ?? 0) ?>);
                const inv_modal = talangan_val;
                const inv_kasbon = parseFloat(document.getElementById('inv_kasbon')?.value || 0);
                const operatorSewa = document.getElementById('inv_sewa_operator')?.value || 'minus';
                const inv_sumber = document.getElementById('inv_sumber_talangan')?.value || 'investor';
                const inv_modal_ket = (document.getElementById('inv_modal_ket')?.value || '').trim();

                let inv_total_val = inv_profit;
                if (operatorSewa === 'plus') inv_total_val += inv_sewa; else inv_total_val -= inv_sewa;
                inv_total_val += inv_kasbon;
                if (inv_sumber === 'investor') inv_total_val += inv_modal;
                inv_total_val = Math.max(0, inv_total_val);

                const dataInvestorX = [
                    ['Keterangan Komponen', 'Nilai'],
                    ['Profit Investor (50%)', formatRupiahXLS(inv_profit)],
                    ['Potongan Sewa Ruko ' + (operatorSewa === 'plus' ? '(+)' : '(-)'), formatRupiahXLS(inv_sewa)],
                    ['Pengembalian Dana Talangan (' + (inv_sumber === 'investor' ? 'Dana Investor' : 'Dana Warung') + ')' + (inv_modal_ket ? ' — ' + inv_modal_ket : ''), formatRupiahXLS(inv_modal)],
                    ['Penambahan/Pengembalian Kasbon Pengelola', formatRupiahXLS(inv_kasbon)],
                    ['TOTAL BERSIH INVESTOR', formatRupiahXLS(inv_total_val)],
                ];
                XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet([['4. Koreksi Dividen: Sisi Investor'], [], ...dataInvestorX]), 'Koreksi Investor');

                // Sheet 5: Koreksi Dividen — Pengelola
                const pgl_profit = share_pgl_base;
                const elSelectFee = document.getElementById('pgl_admin_persen');
                const pct_admin = parseFloat(elSelectFee?.value || <?= (float)($persen_admin ?? 3) ?>);
                const pgl_service_fee = (pgl_profit * pct_admin) / 100;
                const pgl_kasbon = parseFloat(document.getElementById('inv_kasbon')?.value || 0);
                const pct_bersih = 50 - pct_admin;
                const pgl_total_val = Math.max(0, pgl_profit - pgl_service_fee - pgl_kasbon);
                const str_pct_admin = pct_admin.toString().replace('.', ',');
                const str_pct_bersih = pct_bersih.toString().replace('.', ',');

                const dataPengelolaX = [
                    ['Keterangan Komponen', 'Nilai'],
                    ['Profit Pengelola (50%)', formatRupiahXLS(pgl_profit)],
                    ['Service Fee / Admin (' + str_pct_admin + '%)', formatRupiahXLS(pgl_service_fee)],
                    ['Potongan Kasbon', formatRupiahXLS(pgl_kasbon)],
                    ['TOTAL BERSIH PENGELOLA (' + str_pct_bersih + '%)', formatRupiahXLS(pgl_total_val)],
                ];
                XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet([['5. Koreksi Dividen: Sisi Pengelola'], [], ...dataPengelolaX]), 'Koreksi Pengelola');

                // Sheet 6: Distribusi Payroll — samakan dulu angka final_inv/final_pgl/final_admin
                // di halaman (persis seperti exportPDF()) sebelum tabelnya dibaca.
                if (document.getElementById('final_inv')) document.getElementById('final_inv').innerText = formatRupiahXLS(inv_total_val);
                if (document.getElementById('final_pgl')) document.getElementById('final_pgl').innerText = formatRupiahXLS(pgl_total_val);
                const totalAdminGabunganX = admin_fee_val + pgl_service_fee;
                if (document.getElementById('final_admin')) document.getElementById('final_admin').innerText = formatRupiahXLS(totalAdminGabunganX);

                const wrapperPayroll = document.querySelector('.card.border-0.mb-5');
                const elTabel6 = wrapperPayroll?.querySelector('table');
                const ws6 = XLSX.utils.aoa_to_sheet([['6. Rekapan Hasil Akhir Keuntungan (Distribusi Payroll)'], []]);
                if (elTabel6) XLSX.utils.sheet_add_dom(ws6, elTabel6, { origin: -1, raw: true });
                XLSX.utils.book_append_sheet(wb, ws6, 'Distribusi Payroll');

                const filenameFullXLS = "<?= h($nama_file_export) ?>.xlsx";
                XLSX.writeFile(wb, filenameFullXLS);
            }
        </script>
