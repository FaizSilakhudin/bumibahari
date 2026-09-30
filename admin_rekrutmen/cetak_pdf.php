<?php
require '../config/koneksi.php';
require '../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

require_role('rekrutmen');

$id_calon = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if (!$id_calon) {
    http_response_code(400);
    exit('ID tidak valid.');
}

$stmt = $conn->prepare("SELECT * FROM calon_pengelola WHERE id = ?");
$stmt->bind_param("i", $id_calon);
$stmt->execute();
$d = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$d) {
    http_response_code(404);
    exit('Data tidak ditemukan.');
}

$UPLOAD_DIR = __DIR__ . '/../uploads/calon_pengelola/';
$UPLOAD_FIELDS = ['foto_calon', 'foto_ktp', 'foto_kk', 'foto_buku_nikah', 'foto_masakan1', 'foto_masakan2', 'foto_masakan3'];
$UPLOAD_LABEL = [
    'foto_calon' => 'Foto Calon Pengelola',
    'foto_ktp' => 'KTP', 'foto_kk' => 'Kartu Keluarga', 'foto_buku_nikah' => 'Buku Nikah',
    'foto_masakan1' => 'Foto Masakan 1', 'foto_masakan2' => 'Foto Masakan 2', 'foto_masakan3' => 'Foto Masakan 3',
];

function cetak_yt($val) {
    $ya_cls = $val === 'ya' ? ' pill-ya-aktif' : '';
    $tidak_cls = $val === 'tidak' ? ' pill-tidak-aktif' : '';
    return '<span class="pill' . $ya_cls . '">Ya</span><span class="pill' . $tidak_cls . '">Tidak</span>';
}
function cetak_isi($val) {
    $val = trim((string) $val);
    return $val !== '' ? nl2br(h($val)) : '<span class="kosong">Tidak diisi</span>';
}
function kesimpulan_badge_cls(string $k): string {
    switch ($k) {
        case 'direkomendasikan':       return 'badge-hijau';
        case 'tes_memasak':            return 'badge-biru';
        case 'belum_direkomendasikan': return 'badge-merah';
        default:                       return 'badge-abu';
    }
}
// Gambar disisipkan sebagai data URI (base64) langsung dari file di server --
// Dompdf merender dari file lokal, tidak lewat HTTP/browser sama sekali, jadi
// tidak mungkin kena masalah cache/CORS/canvas seperti pendekatan client-side lama.
function img_data_uri(string $path): ?string {
    if (!is_file($path)) return null;
    $info = @getimagesize($path);
    $mime = is_array($info) ? ($info['mime'] ?? '') : '';
    $data = @file_get_contents($path);
    if ($data === false) return null;
    return 'data:' . ($mime ?: 'image/jpeg') . ';base64,' . base64_encode($data);
}
function nama_file_aman(string $s): string {
    $s = str_replace(['/', '\\'], '-', $s);
    return preg_replace('/[<>:"|?*]/', '', $s);
}

$bagian_no_urut = ($d['no_urut'] ?? '') !== '' ? nama_file_aman($d['no_urut']) : '-';
$bagian_nama    = nama_file_aman($d['nama_calon']);
$bagian_tanggal = date('d-m-Y', strtotime($d['tanggal_interview']));
$nama_file_cetak = "$bagian_no_urut - $bagian_nama - $bagian_tanggal.pdf";

$opsi_kesimpulan = [
    'direkomendasikan' => 'Direkomendasikan',
    'dipertimbangkan' => 'Dipertimbangkan / Tes Lanjutan',
    'tes_memasak' => 'Tes Memasak',
    'belum_direkomendasikan' => 'Belum Direkomendasikan',
];

// Kop surat -- logo & alamat kantor pusat, gaya sama dengan cetak PDF di
// menu Rekapitulasi/Laporan Mingguan.
$logo_uri = img_data_uri(__DIR__ . '/../assets/img/wbb.png');
$alamat_pusat = 'Kantor Pusat : Jl. Pamulang Permai Raya, Pamulang Bar., Kec. Pamulang, Kota Tangerang Selatan, Banten 15417';
$telp_pusat = '087784838769';

ob_start();
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
    @page { margin: 14mm 13mm; }
    body { font-family: Helvetica, Arial, sans-serif; color: #1e293b; font-size: 10.5px; line-height: 1.55; }

    /* ===== Kop surat ===== */
    table.kop { width: 100%; border-bottom: 2.5px solid #0d9488; padding-bottom: 10px; margin-bottom: 14px; border-collapse: collapse; }
    table.kop td.logo { width: 52px; vertical-align: middle; }
    table.kop td.logo img { width: 46px; height: 46px; }
    table.kop td.nama { vertical-align: middle; }
    table.kop .kop-title { font-size: 16px; font-weight: bold; color: #0f766e; letter-spacing: .3px; }
    table.kop .kop-addr { font-size: 8.5px; color: #64748b; margin-top: 2px; line-height: 1.5; }
    table.kop td.cetak-info { text-align: right; vertical-align: middle; font-size: 8px; color: #94a3b8; }

    /* ===== Banner judul ===== */
    .banner-judul { background: #0d9488; color: #fff; text-align: center; font-size: 13px; font-weight: bold; letter-spacing: .6px; padding: 9px 10px; border-radius: 7px; margin: 0 0 14px; }

    /* ===== Kartu info identitas ===== */
    table.info-card { width: 100%; border-collapse: collapse; background: #f0fdfa; border: 1px solid #99f6e4; margin-bottom: 16px; }
    table.info-card td { padding: 6px 10px; font-size: 10px; border-bottom: 1px solid #ccfbf1; }
    table.info-card tr:last-child td { border-bottom: none; }
    table.info-card td.lbl { font-weight: bold; color: #0f766e; width: 95px; }
    table.info-card td.val { color: #1e293b; width: 165px; }

    /* ===== Judul bagian (A, B, C, ...) ===== */
    .sect { background: #0d9488; color: #fff; font-weight: bold; padding: 6px 12px; margin: 13px 0 8px; font-size: 11px; border-radius: 6px; letter-spacing: .3px; }

    /* ===== Blok pertanyaan ===== */
    .q { background: #f8fafc; border-left: 3px solid #0d9488; border-radius: 0 5px 5px 0; padding: 5px 10px; margin-bottom: 6px; }
    .q .no { font-weight: bold; color: #0f172a; font-size: 10px; margin-bottom: 2px; }
    .jawab { color: #334155; font-size: 10px; padding-top: 1px; }
    .kosong { color: #94a3b8; font-style: italic; }
    .desc { background: #f0fdfa; border: 1px solid #99f6e4; border-radius: 6px; font-size: 9px; color: #134e4a; text-align: justify; padding: 7px 10px; margin-bottom: 7px; font-style: italic; line-height: 1.45; }

    /* ===== Pill Ya / Tidak ===== */
    .pill { display: inline-block; padding: 2px 9px; border-radius: 10px; font-size: 9px; font-weight: bold; border: 1px solid #cbd5e1; color: #94a3b8; background: #f1f5f9; margin-right: 6px; }
    .pill-ya-aktif { background: #dcfce7; border-color: #86efac; color: #15803d; }
    .pill-tidak-aktif { background: #fee2e2; border-color: #fca5a5; color: #b91c1c; }

    /* ===== Kesimpulan interviewer ===== */
    .badge-kesimpulan { display: inline-block; padding: 5px 14px; border-radius: 8px; font-size: 11px; font-weight: bold; }
    .badge-hijau { background: #dcfce7; color: #15803d; border: 1px solid #86efac; }
    .badge-biru  { background: #e0f2fe; color: #0369a1; border: 1px solid #7dd3fc; }
    .badge-merah { background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; }
    .badge-abu   { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }

    /* ===== Tanda tangan ===== */
    .tutup-blok { page-break-inside: avoid; }
    .ttd-tempat-tgl { text-align: right; font-size: 10px; color: #475569; margin: 14px 0 6px; }
    table.ttd { width: 100%; margin-top: 6px; text-align: center; border-collapse: collapse; }
    table.ttd td { width: 45%; vertical-align: top; }
    table.ttd .ttd-line { height: 34px; border-bottom: 1px solid #334155; margin-bottom: 4px; }
    table.ttd .ttd-nama { font-weight: bold; font-size: 10px; color: #0f172a; }
    table.ttd .ttd-peran { font-size: 8.5px; color: #64748b; margin-top: 1px; }

    /* ===== Lampiran dokumen ===== */
    .lampiran-page { page-break-before: always; }
    .dokumen-utama { text-align: center; font-size: 10px; padding: 12px; margin: 8px 0 12px; background: #f0fdfa; border: 1px solid #99f6e4; border-radius: 8px; }
    .dokumen-utama img { max-width: 75%; max-height: 105mm; border: 1px solid #99f6e4; border-radius: 6px; }
    .dokumen-utama .lbl { margin-top: 7px; font-weight: bold; color: #0f766e; font-size: 11.5px; }
    table.dokumen-grid { width: 100%; margin-top: 8px; border-collapse: separate; border-spacing: 8px; }
    table.dokumen-grid tr { page-break-inside: avoid; }
    table.dokumen-grid td { width: 50%; text-align: center; font-size: 9.5px; padding: 8px; vertical-align: middle; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; }
    table.dokumen-grid img { max-width: 100%; max-height: 60mm; border: 1px solid #cbd5e1; border-radius: 4px; }
    table.dokumen-grid .lbl { margin-top: 5px; font-weight: bold; color: #0f766e; }

    /* ===== Footer ===== */
    .footer-wm { text-align: center; font-size: 8.5px; color: #94a3b8; margin-top: 22px; letter-spacing: .8px; border-top: 1px solid #e2e8f0; padding-top: 8px; }
</style>
</head>
<body>
    <table class="kop">
        <tr>
            <?php if ($logo_uri): ?><td class="logo"><img src="<?= $logo_uri ?>"></td><?php endif; ?>
            <td class="nama">
                <div class="kop-title">WARTEG BUMI BAHARI</div>
                <div class="kop-addr"><?= h($alamat_pusat) ?><br>Telp. <?= h($telp_pusat) ?></div>
            </td>
            <td class="cetak-info">Dicetak: <?= date('d/m/Y H:i') ?></td>
        </tr>
    </table>
    <div class="banner-judul">FORMULIR INTERVIEW CALON PENGELOLA</div>

    <table class="info-card">
        <tr>
            <td class="lbl">No. Urut</td><td class="val"><?= cetak_isi($d['no_urut'] ?? '') ?></td>
            <td class="lbl">Tanggal Interview</td><td class="val"><?= date('d F Y', strtotime($d['tanggal_interview'])) ?></td>
        </tr>
        <tr>
            <td class="lbl">Nama Calon</td><td class="val"><?= h($d['nama_calon']) ?></td>
            <td class="lbl">Interviewer</td><td class="val"><?= cetak_isi($d['interviewer'] ?? '') ?></td>
        </tr>
        <tr>
            <td class="lbl">Usia</td><td class="val"><?= $d['usia'] ? (int) $d['usia'] . ' Tahun' : '-' ?></td>
            <td class="lbl">No. HP</td><td class="val"><?= cetak_isi($d['no_hp'] ?? '') ?></td>
        </tr>
        <tr>
            <td class="lbl">Alamat</td><td class="val" colspan="3"><?= cetak_isi($d['alamat'] ?? '') ?></td>
        </tr>
    </table>

    <div class="sect">A. IDENTITAS &amp; PENGALAMAN KERJA</div>
    <div class="q"><div class="no">1. Perkenalan &amp; pengalaman kerja sebelumnya</div><div class="jawab"><?= cetak_isi($d['a_perkenalan']) ?></div></div>
    <div class="q"><div class="no">2. Bekerja/mengelola usaha sebelumnya</div>
        <div class="jawab">Nama tempat/usaha: <?= cetak_isi($d['a_nama_tempat_usaha']) ?> &mdash; Posisi/jabatan: <?= cetak_isi($d['a_posisi_jabatan']) ?> &mdash; Lama bekerja: <?= cetak_isi($d['a_lama_bekerja']) ?></div>
    </div>
    <div class="q"><div class="no">3. Pernah mengelola warteg?</div>
        <div class="jawab"><?= cetak_yt($d['a_pernah_kelola_warteg']) ?><br>
        Lama: <?= cetak_isi($d['a_lama_kelola_warteg']) ?> &mdash; Omzet rata-rata: <?= cetak_isi($d['a_omzet_rata_rata']) ?> &mdash; Omzet tertinggi: <?= cetak_isi($d['a_omzet_tertinggi']) ?></div>
    </div>
    <div class="q"><div class="no">4. Alasan berhenti/keluar</div><div class="jawab"><?= cetak_isi($d['a_alasan_berhenti']) ?></div></div>
    <div class="q"><div class="no">Punya video hasil masakan?</div><div class="jawab"><?= cetak_yt($d['a_video_masakan']) ?></div></div>
    <div class="q"><div class="no">Menu yang dikuasai</div><div class="jawab"><?= cetak_isi($d['a_menu_dikuasai']) ?></div></div>
    <div class="q"><div class="no">Catatan Interviewer</div><div class="jawab"><?= cetak_isi($d['a_catatan_interviewer']) ?></div></div>

    <div class="sect">B. PENGETAHUAN TENTANG WARTEG BUMI BAHARI</div>
    <div class="q"><div class="no">1. Tahu WBB dari mana</div><div class="jawab"><?= cetak_isi($d['b_tahu_dari_mana']) ?></div></div>
    <div class="q"><div class="no">2. Alasan tertarik bergabung</div><div class="jawab"><?= cetak_isi($d['b_alasan_tertarik']) ?></div></div>
    <div class="q"><div class="no">3. Pernah lihat/kunjungi outlet WBB?</div>
        <div class="jawab"><?= cetak_yt($d['b_pernah_kunjungi_outlet']) ?><br>Outlet: <?= cetak_isi($d['b_outlet_mana']) ?> &mdash; Yang diperhatikan: <?= cetak_isi($d['b_yang_diperhatikan']) ?></div>
    </div>
    <div class="q"><div class="no">4. Pendapat agar penjualan outlet baik</div><div class="jawab"><?= cetak_isi($d['b_pendapat_penjualan_baik']) ?></div></div>
    <div class="q"><div class="no">Catatan Interviewer</div><div class="jawab"><?= cetak_isi($d['b_catatan_interviewer']) ?></div></div>

    <div class="sect">C. PENJELASAN SISTEM &amp; KARAKTER WBB</div>
    <div class="desc">WBB tidak hanya berorientasi membuka warung, tetapi membangun perusahaan dan jaringan usaha yang kuat serta berkelanjutan. Lokasi outlet dipilih selektif berdasarkan potensi pasar, kepadatan konsumen, lingkungan, akses, dan peluang omzet. Pengelola harus siap mengikuti sistem, SOP, evaluasi, dan arahan manajemen.</div>
    <div class="q"><div class="no">Jawaban kesiapan ikuti standar &amp; kebijakan Manajemen WBB</div><div class="jawab"><?= cetak_isi($d['c_jawaban_kesiapan']) ?></div></div>

    <div class="sect">D. KOMITMEN &amp; JENJANG KARIER PENGELOLA</div>
    <div class="desc">Evaluasi berdasarkan: 1) Komunikatif, 2) Kemampuan memasak, 3) Disiplin &amp; dapat diarahkan, 4) Kemampuan mengelola outlet, 5) Integritas &amp; tanggung jawab.</div>

    <div class="sect">E. PELUANG PENEMPATAN OUTLET</div>
    <div class="desc">Pengelola berkinerja baik dapat dipertimbangkan mengelola outlet berpotensi omzet lebih tinggi, berdasarkan kinerja, kesiapan, kemampuan, dan kebutuhan operasional perusahaan.</div>

    <div class="sect">F. PERTANYAAN KOMITMEN AKHIR</div>
    <div class="q"><div class="no">1. Siap ikuti SOP &amp; arahan manajemen?</div><div class="jawab"><?= cetak_yt($d['f_siap_sop']) ?></div></div>
    <div class="q"><div class="no">2. Siap dievaluasi berkala?</div><div class="jawab"><?= cetak_yt($d['f_siap_evaluasi']) ?></div></div>
    <div class="q"><div class="no">3. Siap ditempatkan/dipindahkan?</div><div class="jawab"><?= cetak_yt($d['f_siap_dipindah']) ?></div></div>
    <div class="q"><div class="no">4. Siap jaga kualitas masakan/pelayanan/kebersihan/laporan?</div><div class="jawab"><?= cetak_yt($d['f_siap_jaga_kualitas']) ?></div></div>
    <div class="q"><div class="no">5. Rencana tingkatkan penjualan (outlet omzet besar)</div><div class="jawab"><?= cetak_isi($d['f_rencana_tingkatkan_penjualan']) ?></div></div>
    <div class="q"><div class="no">6. Target bergabung dengan WBB</div><div class="jawab"><?= cetak_isi($d['f_target_bergabung']) ?></div></div>

    <div class="tutup-blok">
        <div class="sect">KESIMPULAN INTERVIEWER</div>
        <?php $kk = $d['kesimpulan_interviewer'] ?? 'dipertimbangkan'; ?>
        <div class="badge-kesimpulan <?= kesimpulan_badge_cls($kk) ?>"><?= h($opsi_kesimpulan[$kk] ?? $opsi_kesimpulan['dipertimbangkan']) ?></div>
        <div class="q" style="margin-top:9px;"><div class="no">Catatan</div><div class="jawab"><?= cetak_isi($d['catatan_kesimpulan']) ?></div></div>

        <div class="ttd-tempat-tgl">Tangerang Selatan, <?= date('d F Y', strtotime($d['tanggal_interview'])) ?></div>
        <table class="ttd">
            <tr>
                <td>
                    <div class="ttd-line"></div>
                    <div class="ttd-nama"><?= h($d['interviewer'] ?: '.....................') ?></div>
                    <div class="ttd-peran">Interviewer</div>
                </td>
                <td></td>
                <td>
                    <div class="ttd-line"></div>
                    <div class="ttd-nama"><?= h($d['nama_calon']) ?></div>
                    <div class="ttd-peran">Calon Pengelola</div>
                </td>
            </tr>
        </table>
    </div>

    <?php
    $foto_ada = array_values(array_filter($UPLOAD_FIELDS, fn($f) => !empty($d[$f])));
    $foto_lainnya = array_values(array_filter($foto_ada, fn($f) => $f !== 'foto_calon'));
    if ($foto_ada):
    ?>
    <div class="lampiran-page">
        <div class="sect">LAMPIRAN DOKUMEN</div>
        <?php if (!empty($d['foto_calon'])):
            $uri_calon = img_data_uri($UPLOAD_DIR . $d['foto_calon']);
        ?>
        <div class="dokumen-utama">
            <?php if ($uri_calon): ?><img src="<?= $uri_calon ?>"><?php endif; ?>
            <div class="lbl"><?= h($UPLOAD_LABEL['foto_calon']) ?></div>
        </div>
        <?php endif; ?>
        <?php if ($foto_lainnya): ?>
        <table class="dokumen-grid">
            <tr>
            <?php foreach ($foto_lainnya as $i => $f):
                if ($i > 0 && $i % 2 === 0) echo '</tr><tr>';
                $uri = img_data_uri($UPLOAD_DIR . $d[$f]);
            ?>
                <td>
                    <?php if ($uri): ?><img src="<?= $uri ?>"><?php endif; ?>
                    <div class="lbl"><?= h($UPLOAD_LABEL[$f]) ?></div>
                </td>
            <?php endforeach; ?>
            </tr>
        </table>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="footer-wm">WARTEG BUMI BAHARI MANAGEMENT</div>
</body>
</html>
<?php
$html = ob_get_clean();

$options = new Options();
$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'Helvetica');
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$canvas = $dompdf->getCanvas();
$canvas->page_text($canvas->get_width() - 95, $canvas->get_height() - 20, "Halaman {PAGE_NUM} / {PAGE_COUNT}", null, 8, [0.58, 0.64, 0.72]);

$dompdf->stream($nama_file_cetak, ['Attachment' => true]);
