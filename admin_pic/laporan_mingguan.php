<?php
require '../config/koneksi.php';
require_role('pic');
include 'sidebar.php';

$id_user = current_user_id();
$cabang_ids_pic = pic_cabang_ids($conn, $id_user);

// -----------------------------------------------------------------------------
// FILTER & PARAMETER
// -----------------------------------------------------------------------------
$id_cabang   = isset($_GET['id_cabang']) ? (int) $_GET['id_cabang'] : 0;
$tgl_mulai   = $_GET['tgl_mulai']   ?? date('Y-m-01');
$tgl_selesai = $_GET['tgl_selesai'] ?? date('Y-m-d');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl_mulai))   { $tgl_mulai   = date('Y-m-01'); }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl_selesai)) { $tgl_selesai = date('Y-m-d'); }
if ($tgl_selesai < $tgl_mulai) { [$tgl_mulai, $tgl_selesai] = [$tgl_selesai, $tgl_mulai]; }

// -----------------------------------------------------------------------------
// DAFTAR CABANG (dropdown) — HANYA cabang yang dipegang PIC ini.
// -----------------------------------------------------------------------------
$list_cabang = [];
if (!empty($cabang_ids_pic)) {
    $ph = implode(',', array_fill(0, count($cabang_ids_pic), '?'));
    $stmt = $conn->prepare("SELECT id_cabang, nama_cabang FROM cabang WHERE id_cabang IN ($ph) ORDER BY nama_cabang ASC");
    $stmt->bind_param(str_repeat('i', count($cabang_ids_pic)), ...$cabang_ids_pic);
    $stmt->execute();
    $list_cabang = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Wajib: cabang yang diminta harus salah satu yang dipegang PIC ini.
if (!$id_cabang || !in_array($id_cabang, $cabang_ids_pic, true)) {
    $id_cabang = $list_cabang ? (int) $list_cabang[0]['id_cabang'] : 0;
}

// -----------------------------------------------------------------------------
// INFO CABANG + PENGELOLA AKTIF + INVESTOR AKTIF + REKENING
// -----------------------------------------------------------------------------
// Pengelola/investor diambil PADA AWAL PERIODE laporan ($tgl_mulai) — bukan
// yang aktif sekarang — supaya laporan periode lama tetap benar walau sudah
// ada rotasi pengelola/investor setelahnya.
$info = null;
if ($id_cabang) {
    $stmt = $conn->prepare("
        SELECT c.nama_cabang, c.no_rekening_cabang, c.nama_bank_cabang, c.atas_nama_cabang, c.alamat, c.no_telp
        FROM cabang c WHERE c.id_cabang = ? LIMIT 1
    ");
    $stmt->bind_param('i', $id_cabang);
    $stmt->execute();
    $info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($info) {
        $anchor = anchor_periode($tgl_selesai);
        $info['nama_pengelola'] = pengelola_pada_tanggal($conn, $id_cabang, $anchor);
        $info['nama_investor']  = investor_pada_tanggal($conn, $id_cabang, $anchor);
    }
}

$nama_cabang   = $info['nama_cabang']        ?? '-';
$pengelola     = !empty($info['nama_pengelola'])    ? $info['nama_pengelola']    : '-';
$investor      = !empty($info['nama_investor'])     ? $info['nama_investor']     : '-';
$no_rekening   = !empty($info['no_rekening_cabang']) ? $info['no_rekening_cabang'] : '-';
$nama_bank     = !empty($info['nama_bank_cabang'])   ? $info['nama_bank_cabang']   : '-';
$atas_nama_rek = !empty($info['atas_nama_cabang'])   ? $info['atas_nama_cabang']   : '-';
$alamat_cabang = !empty($info['alamat'])   ? $info['alamat']   : 'Kantor Pusat : Jl. Pamulang Permai Raya, Pamulang Bar., Kec. Pamulang, Kota Tangerang Selatan, Banten 15417';
$no_hp_cabang  = !empty($info['no_telp'])  ? $info['no_telp']  : '087784838769';

// PIC yang menginput/memfinalisasi laporan pada periode ini (bisa lebih dari satu).
$nama_pic = '-';
if ($id_cabang) {
    $stmt = $conn->prepare("
        SELECT GROUP_CONCAT(DISTINCT u.username ORDER BY u.username SEPARATOR ', ') AS pic
        FROM laporan_cabang lc
        JOIN users u ON u.id = lc.id_user_laporan
        WHERE lc.id_cabang = ? AND lc.tanggal BETWEEN ? AND ?
          AND lc.status_laporan = 'lengkap' AND lc.id_user_laporan IS NOT NULL
    ");
    $stmt->bind_param('iss', $id_cabang, $tgl_mulai, $tgl_selesai);
    $stmt->execute();
    $nama_pic = $stmt->get_result()->fetch_assoc()['pic'] ?? null;
    $nama_pic = $nama_pic ?: '-';
    $stmt->close();

    // Belum ada laporan yang difinalisasi periode ini (mis. laporan sedang berjalan) —
    // fallback ke PIC yang DITUGASKAN (tabel pengelola) supaya kolom ini tidak
    // kosong percuma padahal cabangnya sudah punya PIC.
    if ($nama_pic === '-') {
        $stmt2 = $conn->prepare("
            SELECT u.username FROM pengelola p
            JOIN users u ON u.id = p.id_user AND u.role = 'pic'
            WHERE p.id_cabang = ? AND p.tgl_mulai <= ? AND (p.tgl_selesai IS NULL OR p.tgl_selesai >= ?)
            ORDER BY p.tgl_mulai DESC LIMIT 1
        ");
        $anchor_pic = anchor_periode($tgl_selesai);
        $stmt2->bind_param('iss', $id_cabang, $anchor_pic, $anchor_pic);
        $stmt2->execute();
        $ditugaskan = $stmt2->get_result()->fetch_assoc()['username'] ?? null;
        $stmt2->close();
        if ($ditugaskan) {
            $nama_pic = $ditugaskan . ' (ditugaskan)';
        }
    }
}

// -----------------------------------------------------------------------------
// DATA LAPORAN (Setor Tunai & Sewa Tempat)
// -----------------------------------------------------------------------------
$data_laporan     = [];
$total_sewa       = 0;
$total_sisa_tunai = 0;

if ($id_cabang) {
    $stmt = $conn->prepare("
        SELECT tanggal, sewa, sisa_tunai
        FROM laporan_cabang
        WHERE id_cabang = ? AND tanggal BETWEEN ? AND ? AND status_laporan = 'lengkap'
        ORDER BY tanggal ASC
    ");
    $stmt->bind_param('iss', $id_cabang, $tgl_mulai, $tgl_selesai);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $sisa = abs((int) $row['sisa_tunai']);
        $total_sewa       += (int) $row['sewa'];
        $total_sisa_tunai += $sisa;
        $data_laporan[] = [
            'tanggal'    => $row['tanggal'],
            'sewa'       => (int) $row['sewa'],
            'sisa_tunai' => $sisa,
        ];
    }
    $stmt->close();
}

$total_baris       = count($data_laporan);
$jumlah_disetorkan = $total_sewa - $total_sisa_tunai;

// Nama file PDF
$clean_nama_cabang = preg_replace('/[^a-zA-Z0-9_-]/', '_', $nama_cabang);
$nama_file_pdf     = 'Laporan_Mingguan_' . $clean_nama_cabang
    . '_' . date('d-m-Y', strtotime($tgl_mulai))
    . '_sd_' . date('d-m-Y', strtotime($tgl_selesai)) . '.pdf';

/** Format angka rupiah tanpa simbol */
function lm_rp($n): string
{
    return number_format((float) $n, 0, ',', '.');
}
?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

<style>
    :root {
        --lm-ink: #0f172a;
        --lm-muted: #64748b;
        --lm-line: #e2e8f0;
        --lm-brand: #1d4ed8;
        --lm-brand-soft: #eff6ff;
        --lm-red: #dc2626;
    }

    .lm-wrap { max-width: 960px; margin: 0 auto; }

    .lm-page-head h4 { font-weight: 800; letter-spacing: -.3px; color: var(--lm-ink); }
    .lm-page-head p  { color: var(--lm-muted); }

    .lm-filter {
        background: #fff;
        border: 1px solid var(--lm-line);
        border-radius: 14px;
        padding: 18px;
        box-shadow: 0 6px 24px rgba(15, 23, 42, .04);
    }
    .lm-filter .form-label { font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: var(--lm-muted); }
    .lm-filter .form-select, .lm-filter .form-control { border-color: var(--lm-line); border-radius: 10px; }

    /* ---- Kertas laporan ---- */
    .lm-paper {
        background: #fff;
        border: 1px solid var(--lm-line);
        border-radius: 16px;
        box-shadow: 0 10px 40px rgba(15, 23, 42, .06);
        padding: 34px 38px;
        position: relative;
        overflow: hidden;
        color: var(--lm-ink);
    }
    .lm-watermark {
        position: absolute; top: 50%; left: 50%;
        width: 360px; max-width: 60%;
        transform: translate(-50%, -50%);
        opacity: .045; pointer-events: none; z-index: 0;
    }
    .lm-paper > *:not(.lm-watermark) { position: relative; z-index: 1; }

    /* Kop surat (tampil di layar & PDF) */
    .lm-kop {
        display: flex; align-items: center; gap: 18px;
        border-bottom: 3px double #334155;
        padding-bottom: 16px; margin-bottom: 22px;
    }
    .lm-kop img { width: 66px; height: 66px; object-fit: contain; }
    .lm-kop-name { font-size: 1.35rem; font-weight: 800; letter-spacing: .5px; margin: 0; color: #111827; }
    .lm-kop-addr { font-size: .74rem; color: var(--lm-muted); margin: 3px 0 0; line-height: 1.45; }

    .lm-doc-title {
        text-align: center; font-weight: 800; letter-spacing: .06em;
        font-size: 1rem; margin: 4px 0 4px;
    }
    .lm-doc-sub { text-align: center; font-size: .78rem; color: var(--lm-muted); margin-bottom: 22px; }

    /* Meta info */
    .lm-meta {
        display: grid; grid-template-columns: 1fr 1fr; gap: 6px 28px;
        font-size: .84rem; margin-bottom: 22px;
    }
    .lm-meta div { display: flex; gap: 8px; }
    .lm-meta .k { color: var(--lm-muted); min-width: 84px; }
    .lm-meta .v { font-weight: 600; }

    /* Tabel */
    .lm-table { width: 100%; border-collapse: collapse; font-size: .86rem; }
    .lm-table th, .lm-table td { border: 1px solid #cbd5e1; padding: 9px 14px; }
    .lm-table thead th {
        background: var(--lm-brand); color: #fff; text-align: center;
        font-weight: 700; letter-spacing: .03em; font-size: .78rem; text-transform: uppercase;
    }
    .lm-table tbody td.num { text-align: right; font-variant-numeric: tabular-nums; }
    .lm-table tbody td.c   { text-align: center; }
    .lm-table tbody tr:nth-child(even) td { background: #f8fafc; }
    .lm-neg { color: var(--lm-red); font-weight: 600; }
    .lm-table tr.lm-total td {
        background: #dbeafe; color: #1e3a8a; font-weight: 800;
        border-color: #93c5fd;
    }
    .lm-empty td { text-align: center; color: var(--lm-muted); padding: 26px; }

    /* Callout jumlah disetorkan */
    .lm-callout {
        margin-top: 22px;
        display: flex; align-items: center; justify-content: space-between;
        gap: 16px; flex-wrap: wrap;
        background: linear-gradient(90deg, #eff6ff, #ffffff);
        border: 1px solid #bfdbfe; border-left: 5px solid var(--lm-brand);
        border-radius: 12px; padding: 16px 20px;
    }
    .lm-callout .lbl { font-size: .78rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: var(--lm-muted); }
    .lm-callout .val { font-size: 1.5rem; font-weight: 800; color: var(--lm-brand); font-variant-numeric: tabular-nums; }

    /* Rekening tujuan */
    .lm-rek { margin-top: 20px; }
    .lm-rek h6 { font-size: .74rem; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; color: var(--lm-muted); margin-bottom: 8px; }
    .lm-rek-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
    .lm-rek-item { border: 1px solid var(--lm-line); border-radius: 10px; padding: 10px 14px; }
    .lm-rek-item .k { font-size: .68rem; color: var(--lm-muted); text-transform: uppercase; letter-spacing: .04em; }
    .lm-rek-item .v { font-weight: 700; font-size: .92rem; }

    .lm-foot-note { display: none; }

    /* ================= MODE CETAK PDF ================= */
    .lm-paper.lm-print {
        box-shadow: none; border: none; border-radius: 0;
        padding: 6px 4px 4px;
    }
    .lm-paper.lm-print .lm-watermark { opacity: .05; }
    .lm-paper.lm-print .lm-foot-note {
        display: block; text-align: right; font-size: .68rem;
        color: #94a3b8; margin-top: 22px;
    }
    .lm-paper.lm-print .lm-table thead th { background: #1d4ed8 !important; -webkit-print-color-adjust: exact; }

    @media (max-width: 768px) {
        .lm-paper { padding: 22px 18px; }
        .lm-kop { flex-direction: column; text-align: center; gap: 10px; }
        .lm-meta { grid-template-columns: 1fr; }
        .lm-rek-grid { grid-template-columns: 1fr; }
        .lm-callout .val { font-size: 1.25rem; }
        .lm-table { font-size: .8rem; }
        .lm-table th, .lm-table td { padding: 8px 10px; }
        .lm-btns { flex-direction: column; }
        .lm-btns .btn { width: 100%; }
    }
</style>

<div class="container-fluid py-3">
  <div class="lm-wrap">

    <div class="lm-page-head d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h4 class="mb-1">Laporan Mingguan</h4>
            <p class="mb-0">Rekapitulasi setor tunai &amp; sewa tempat &mdash; cabang yang Anda pegang</p>
        </div>
    </div>

    <?php if (empty($cabang_ids_pic)): ?>
        <div class="alert alert-warning rounded-4"><i class="bi bi-exclamation-triangle-fill me-2"></i> Anda belum ditugaskan ke cabang manapun. Hubungi Admin Pusat.</div>
    <?php else: ?>

    <!-- Filter -->
    <form method="GET" action="" class="lm-filter mb-4">
        <div class="row g-3 align-items-end">
            <div class="col-lg-3 col-sm-6">
                <label class="form-label">Cabang</label>
                <select name="id_cabang" class="form-select">
                    <?php foreach ($list_cabang as $c): ?>
                        <option value="<?= (int) $c['id_cabang'] ?>" <?= ($c['id_cabang'] == $id_cabang) ? 'selected' : '' ?>>
                            <?= h($c['nama_cabang']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-3 col-sm-6">
                <label class="form-label">Tanggal Mulai</label>
                <input type="date" name="tgl_mulai" class="form-control" value="<?= h($tgl_mulai) ?>">
            </div>
            <div class="col-lg-3 col-sm-6">
                <label class="form-label">Tanggal Selesai</label>
                <input type="date" name="tgl_selesai" class="form-control" value="<?= h($tgl_selesai) ?>">
            </div>
            <div class="col-lg-3 col-sm-6">
                <div class="d-flex gap-2 lm-btns">
                    <button type="submit" class="btn btn-primary flex-fill fw-semibold">
                        <i class="bi bi-funnel-fill me-1"></i> Tampilkan
                    </button>
                    <button type="button" onclick="cetakPDF(this)" class="btn btn-outline-secondary flex-fill fw-semibold text-nowrap">
                        <i class="bi bi-file-earmark-pdf me-1"></i> Cetak PDF
                    </button>
                    <button type="button" onclick="bagikanWA(this)" class="btn btn-success flex-fill fw-semibold text-nowrap">
                        <i class="bi bi-whatsapp me-1"></i> Bagikan ke WA
                    </button>
                </div>
            </div>
        </div>
    </form>

    <!-- Kertas laporan -->
    <div class="lm-paper" id="area-laporan">
        <img src="../assets/img/wbb.png" class="lm-watermark" alt="">

        <div class="lm-kop">
            <img src="../assets/img/wbb.png" alt="Logo Warteg Bumi Bahari">
            <div>
                <p class="lm-kop-name">WARTEG BUMI BAHARI</p>
                <p class="lm-kop-addr">
                    <?= h($alamat_cabang) ?><br>
                    Telp. <?= h($no_hp_cabang) ?>
                </p>
            </div>
        </div>

        <div class="lm-doc-title">LAPORAN MINGGUAN &mdash; SETOR TUNAI &amp; SEWA TEMPAT</div>

        <div class="lm-meta">
            <div><span class="k">Cabang</span><span class="v">: <?= h($nama_cabang) ?></span></div>
            <div><span class="k">Periode</span><span class="v">: <?= date('d M Y', strtotime($tgl_mulai)) ?> &ndash; <?= date('d M Y', strtotime($tgl_selesai)) ?></span></div>
            <div><span class="k">Pengelola</span><span class="v">: <?= h($pengelola) ?></span></div>
            <div><span class="k">Investor</span><span class="v">: <?= h($investor) ?></span></div>
            <div><span class="k">PIC</span><span class="v">: <?= h($nama_pic) ?></span></div>
        </div>

        <table class="lm-table">
            <thead>
                <tr>
                    <th style="width: 8%;">No</th>
                    <th style="width: 24%;">Tanggal</th>
                    <th style="width: 34%;">Sewa Tempat (Rp)</th>
                    <th style="width: 34%;">Sisa Tunai (Rp)</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($total_baris > 0): ?>
                    <?php $no = 1; foreach ($data_laporan as $row): ?>
                        <tr>
                            <td class="c"><?= $no++ ?></td>
                            <td class="c"><?= date('d/m/Y', strtotime($row['tanggal'])) ?></td>
                            <td class="num"><?= lm_rp($row['sewa']) ?></td>
                            <td class="num lm-neg"><?= $row['sisa_tunai'] > 0 ? '(' . lm_rp($row['sisa_tunai']) . ')' : '0' ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr class="lm-empty"><td colspan="4">Tidak ada data laporan pada periode ini.</td></tr>
                <?php endif; ?>

                <tr class="lm-total">
                    <td class="c" colspan="2">TOTAL &mdash; <?= $total_baris ?> HARI</td>
                    <td class="num"><?= lm_rp($total_sewa) ?></td>
                    <td class="num"><?= $total_sisa_tunai > 0 ? '(' . lm_rp($total_sisa_tunai) . ')' : '0' ?></td>
                </tr>
            </tbody>
        </table>

        <div class="lm-callout">
            <span class="lbl">Jumlah yang Disetorkan</span>
            <span class="val">Rp <?= lm_rp($jumlah_disetorkan) ?></span>
        </div>

        <div class="lm-rek">
            <h6>Rekening Tujuan Setoran</h6>
            <div class="lm-rek-grid">
                <div class="lm-rek-item"><div class="k">Bank</div><div class="v"><?= h($nama_bank) ?></div></div>
                <div class="lm-rek-item"><div class="k">No. Rekening</div><div class="v"><?= h($no_rekening) ?></div></div>
                <div class="lm-rek-item"><div class="k">Atas Nama</div><div class="v"><?= h($atas_nama_rek) ?></div></div>
            </div>
        </div>

        <div class="lm-foot-note">Dicetak pada <?= date('d/m/Y H:i') ?> WIB</div>
    </div>

    <?php endif; ?>
  </div>
</div>

<script>
const LM_PDF_FILENAME = <?= json_encode($nama_file_pdf) ?>;

function lmPdfOpt() {
    return {
        margin:      [14, 12, 14, 12],
        filename:    LM_PDF_FILENAME,
        image:       { type: 'jpeg', quality: 0.98 },
        html2canvas: { scale: 2, useCORS: true, logging: false, backgroundColor: '#ffffff' },
        jsPDF:       { unit: 'mm', format: 'a4', orientation: 'portrait' },
        pagebreak:   { mode: ['avoid-all', 'css', 'legacy'] }
    };
}

function cetakPDF(btn) {
    const area = document.getElementById('area-laporan');
    if (btn) { btn.disabled = true; btn.classList.add('disabled'); }

    area.classList.add('lm-print');

    html2pdf().set(lmPdfOpt()).from(area).save().then(function () {
        area.classList.remove('lm-print');
        if (btn) { btn.disabled = false; btn.classList.remove('disabled'); }
    }).catch(function () {
        area.classList.remove('lm-print');
        if (btn) { btn.disabled = false; btn.classList.remove('disabled'); }
        alert('Gagal membuat PDF. Coba lagi.');
    });
}

// Bagikan PDF ke WhatsApp via menu share bawaan HP (Web Share API + file).
// Di desktop / browser yang tidak mendukung share file: PDF didownload otomatis
// dan WhatsApp Web dibuka dengan teks siap kirim, tinggal lampirkan filenya.
function bagikanWA(btn) {
    const area = document.getElementById('area-laporan');
    if (btn) { btn.disabled = true; btn.classList.add('disabled'); }
    area.classList.add('lm-print');

    const teks = 'Laporan Mingguan &mdash; <?= addslashes($nama_cabang) ?> (<?= date('d/m/Y', strtotime($tgl_mulai)) ?> s/d <?= date('d/m/Y', strtotime($tgl_selesai)) ?>)'.replace('&mdash;', '-');

    html2pdf().set(lmPdfOpt()).from(area).outputPdf('blob').then(async function (blob) {
        area.classList.remove('lm-print');
        if (btn) { btn.disabled = false; btn.classList.remove('disabled'); }

        const file = new File([blob], LM_PDF_FILENAME, { type: 'application/pdf' });

        if (navigator.canShare && navigator.canShare({ files: [file] })) {
            try {
                await navigator.share({ files: [file], title: 'Laporan Mingguan', text: teks });
                return;
            } catch (e) {
                if (e && e.name === 'AbortError') return; // dibatalkan user
            }
        }

        // Fallback: unduh PDF-nya lalu buka WhatsApp Web dengan teks siap kirim.
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = LM_PDF_FILENAME;
        a.click();
        window.open('https://wa.me/?text=' + encodeURIComponent(teks + ' (PDF terlampir, silakan unggah manual)'), '_blank');
    }).catch(function () {
        area.classList.remove('lm-print');
        if (btn) { btn.disabled = false; btn.classList.remove('disabled'); }
        alert('Gagal membuat PDF. Coba lagi.');
    });
}
</script>
