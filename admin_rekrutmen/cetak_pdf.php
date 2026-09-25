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
$UPLOAD_FIELDS = ['foto_ktp', 'foto_kk', 'foto_buku_nikah', 'foto_masakan1', 'foto_masakan2', 'foto_masakan3'];
$UPLOAD_LABEL = [
    'foto_ktp' => 'KTP', 'foto_kk' => 'Kartu Keluarga', 'foto_buku_nikah' => 'Buku Nikah',
    'foto_masakan1' => 'Foto Masakan 1', 'foto_masakan2' => 'Foto Masakan 2', 'foto_masakan3' => 'Foto Masakan 3',
];

function cetak_yt($val) {
    $ya = $val === 'ya';
    $tidak = $val === 'tidak';
    return '<span class="chk' . ($ya ? ' checked' : '') . '"></span> Ya&nbsp;&nbsp;&nbsp;'
         . '<span class="chk' . ($tidak ? ' checked' : '') . '"></span> Tidak';
}
function cetak_isi($val) {
    $val = trim((string) $val);
    return $val !== '' ? nl2br(h($val)) : '<span class="kosong">-</span>';
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

ob_start();
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
    body { font-family: 'Times New Roman', Times, serif; color: #111; font-size: 11px; line-height: 1.5; }
    h1 { text-align: center; font-size: 16px; margin: 0 0 2px; }
    h2 { text-align: center; font-size: 13px; margin: 0 0 14px; font-weight: normal; }
    table.f-head { width: 100%; margin-bottom: 14px; border-collapse: collapse; }
    table.f-head td { padding: 2px 8px 2px 0; vertical-align: top; }
    table.f-head td.lbl { font-weight: bold; width: 140px; }
    .sect { background: #e5e5e5; font-weight: bold; padding: 5px 8px; margin: 14px 0 8px; font-size: 12px; }
    .q { margin-bottom: 8px; }
    .q .no { font-weight: bold; }
    .jawab { border-bottom: 1px dotted #999; padding: 2px 0 3px 4px; min-height: 14px; }
    .kosong { color: #999; }
    .desc { font-size: 10px; color: #333; text-align: justify; margin-bottom: 8px; }
    .chk { display: inline-block; width: 10px; height: 10px; border: 1.4px solid #111; margin-right: 4px; }
    .chk.checked { background: #111; }
    .kesimpulan-item { margin-bottom: 4px; }
    table.ttd { width: 100%; margin-top: 40px; text-align: center; border-collapse: collapse; }
    table.ttd td { width: 45%; }
    table.ttd .garis { margin-top: 50px; border-top: 1px solid #111; padding-top: 4px; }
    table.dokumen-grid { width: 100%; margin-top: 8px; border-collapse: collapse; }
    table.dokumen-grid td { width: 33%; text-align: center; font-size: 10px; padding: 4px; }
    table.dokumen-grid img { max-width: 100%; max-height: 75px; border: 1px solid #ccc; }
    .footer-wm { text-align: center; font-size: 10px; color: #555; margin-top: 20px; letter-spacing: 1px; }
</style>
</head>
<body>
    <h1>INTERVIEW CALON PENGELOLA</h1>
    <h2>WARTEG BUMI BAHARI (WBB)</h2>

    <table class="f-head">
        <tr><td class="lbl">No. Urut</td><td><?= cetak_isi($d['no_urut'] ?? '') ?></td></tr>
        <tr><td class="lbl">Nama Calon Pengelola</td><td><?= h($d['nama_calon']) ?></td></tr>
        <tr><td class="lbl">Usia</td><td><?= $d['usia'] ? (int) $d['usia'] . ' Tahun' : '-' ?></td></tr>
        <tr><td class="lbl">Alamat</td><td><?= cetak_isi($d['alamat'] ?? '') ?></td></tr>
        <tr><td class="lbl">No. HP</td><td><?= cetak_isi($d['no_hp'] ?? '') ?></td></tr>
        <tr><td class="lbl">Tanggal Interview</td><td><?= date('d F Y', strtotime($d['tanggal_interview'])) ?></td></tr>
        <tr><td class="lbl">Interviewer</td><td><?= cetak_isi($d['interviewer'] ?? '') ?></td></tr>
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

    <div class="sect">KESIMPULAN INTERVIEWER</div>
    <?php foreach ($opsi_kesimpulan as $key => $label):
        $checked = ($d['kesimpulan_interviewer'] ?? '') === $key;
    ?>
        <div class="kesimpulan-item"><span class="chk<?= $checked ? ' checked' : '' ?>"></span> <?= h($label) ?></div>
    <?php endforeach; ?>
    <div class="q" style="margin-top:8px;"><div class="no">Catatan</div><div class="jawab"><?= cetak_isi($d['catatan_kesimpulan']) ?></div></div>

    <table class="ttd">
        <tr>
            <td><div class="garis"><?= h($d['interviewer'] ?: '.....................') ?></div>Interviewer</td>
            <td></td>
            <td><div class="garis"><?= h($d['nama_calon']) ?></div>Calon Pengelola</td>
        </tr>
    </table>

    <?php
    $foto_ada = array_values(array_filter($UPLOAD_FIELDS, fn($f) => !empty($d[$f])));
    if ($foto_ada):
    ?>
    <div class="sect" style="margin-top:20px;">LAMPIRAN DOKUMEN</div>
    <table class="dokumen-grid">
        <tr>
        <?php foreach ($foto_ada as $i => $f):
            if ($i > 0 && $i % 3 === 0) echo '</tr><tr>';
            $uri = img_data_uri($UPLOAD_DIR . $d[$f]);
        ?>
            <td>
                <?php if ($uri): ?><img src="<?= $uri ?>"><?php endif; ?>
                <div><?= h($UPLOAD_LABEL[$f]) ?></div>
            </td>
        <?php endforeach; ?>
        </tr>
    </table>
    <?php endif; ?>

    <div class="footer-wm">WARTEG BUMI BAHARI MANAGEMENT</div>
</body>
</html>
<?php
$html = ob_get_clean();

$options = new Options();
$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'Times New Roman');
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream($nama_file_cetak, ['Attachment' => true]);
