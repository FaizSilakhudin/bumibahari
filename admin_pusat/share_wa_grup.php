<?php
require '../config/koneksi.php';
require '../config/wa_grup.php';
include 'sidebar_pusat.php';

require_role('pusat');

// ============================================================
// FILTER PERIODE:
//  - Harian: 1 tanggal (default: kemarin)
//  - Mingguan: rentang tanggal mulai–selesai (default: 7 hari terakhir)
//    laporan_mingguan.php memang menerima tgl_mulai & tgl_selesai bebas,
//    bukan minggu ISO — jadi user bisa pilih rentang berapa pun.
//  - Bulanan & Rekap: 1 bulan (default: bulan berjalan)
// ============================================================
$tgl_harian  = $_GET['tgl_harian']  ?? date('Y-m-d', strtotime('-1 day'));
$mg_mulai    = $_GET['mg_mulai']    ?? date('Y-m-d', strtotime('-7 day'));
$mg_selesai  = $_GET['mg_selesai']  ?? date('Y-m-d', strtotime('-1 day'));
$bulan_rekap = $_GET['bulan']       ?? date('Y-m');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl_harian))  $tgl_harian  = date('Y-m-d', strtotime('-1 day'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $mg_mulai))    $mg_mulai    = date('Y-m-d', strtotime('-7 day'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $mg_selesai))  $mg_selesai  = date('Y-m-d', strtotime('-1 day'));
if ($mg_selesai < $mg_mulai) { [$mg_mulai, $mg_selesai] = [$mg_selesai, $mg_mulai]; }
if (!preg_match('/^\d{4}-\d{2}$/',       $bulan_rekap)) $bulan_rekap = date('Y-m');

$list_grup   = wa_grup_list($conn);
$total_grup  = count($list_grup);
$total_cab   = 0;
foreach ($list_grup as $g) $total_cab += (int)$g['jml_cabang'];
?>

<style>
.sg-card{background:#fff;border:none;border-radius:14px;box-shadow:0 2px 10px rgba(0,0,0,.04)}
.sg-row{border:1px solid #e2e8f0;border-radius:12px;padding:14px 16px;margin-bottom:10px;transition:background .15s}
.sg-row:hover{background:#f8fafc}
.sg-grup-title{font-weight:700;color:#0f172a}
.sg-badge{display:inline-block;background:#e0e7ff;color:#4338ca;font-size:.68rem;font-weight:700;padding:2px 8px;border-radius:999px;margin-left:6px}
.sg-badge-empty{background:#fef3c7;color:#b45309}
.sg-btn-share{min-width:85px}
.sg-chip-cabang{display:inline-block;background:#f1f5f9;border:1px solid #e2e8f0;color:#334155;font-size:.78rem;padding:4px 10px;border-radius:999px;margin:2px 2px 0 0}
.sg-filter-card{background:linear-gradient(135deg,#f0fdf4 0%,#dcfce7 100%);border:1px solid #bbf7d0;border-radius:12px;padding:14px 18px}
.sg-cabang-item{border-bottom:1px solid #f1f5f9;padding:10px 0;transition:background .15s}
.sg-cabang-item:last-child{border-bottom:none}
.sg-cabang-item.is-checked{background:#eff6ff}
.sg-done{opacity:.5;text-decoration:line-through}
/* Checkbox pemilihan: warna biru explicit (kadang Bootstrap custom theme kurang kontras) */
.sg-cabang-check{width:20px!important;height:20px!important;accent-color:#2563eb;cursor:pointer}
.sg-master-check{accent-color:#2563eb;cursor:pointer}
/* Indikator "ikut batch" di dekat tombol Share WA — centang biru saat row dicentang */
.sg-batch-indicator{color:#2563eb;font-size:1.1rem;line-height:1;display:none}
.sg-cabang-item.is-checked .sg-batch-indicator{display:inline}
/* Toggle "Cetak Bulan Kemarin" per cabang (hanya tampil utk tipe Harian) */
.sg-opsi-bl{display:inline-flex;align-items:center;gap:6px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:6px;padding:4px 10px;font-size:.72rem;color:#334155;cursor:pointer;user-select:none;white-space:nowrap}
.sg-opsi-bl input{accent-color:#2563eb;cursor:pointer}
.sg-opsi-bl.off{background:#fef3c7;border-color:#fde68a;color:#92400e}
/* z-index override: pastikan modal di atas sidebar (1000) & notifikasi bell (1060) */
.modal{z-index:1070!important}
.modal-backdrop{z-index:1065!important}
</style>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h3 class="fw-bold mb-1 text-dark"><i class="bi bi-whatsapp text-success me-2"></i>Share WA Group</h3>
            <p class="text-muted small mb-0">Kirim laporan ke grup WhatsApp. 1 klik tombol → buka tab share per cabang di grup itu.</p>
        </div>
        <a href="data_wa_grup" class="btn btn-outline-success fw-semibold">
            <i class="bi bi-gear me-1"></i> Kelola Grup WA
        </a>
    </div>

    <!-- Statistik + Filter periode -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="sg-card p-3 d-flex align-items-center gap-3">
                <div class="rounded-circle bg-success bg-opacity-10 d-flex align-items-center justify-content-center" style="width:48px;height:48px">
                    <i class="bi bi-people-fill text-success fs-4"></i>
                </div>
                <div>
                    <div class="text-muted small">Total Grup WA</div>
                    <div class="fw-bold fs-5"><?= $total_grup ?> grup</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="sg-card p-3 d-flex align-items-center gap-3">
                <div class="rounded-circle bg-primary bg-opacity-10 d-flex align-items-center justify-content-center" style="width:48px;height:48px">
                    <i class="bi bi-shop text-primary fs-4"></i>
                </div>
                <div>
                    <div class="text-muted small">Cabang Ter-mapping</div>
                    <div class="fw-bold fs-5"><?= $total_cab ?> cabang</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="sg-card p-3 d-flex align-items-center gap-3">
                <div class="rounded-circle bg-warning bg-opacity-10 d-flex align-items-center justify-content-center" style="width:48px;height:48px">
                    <i class="bi bi-info-circle text-warning fs-4"></i>
                </div>
                <div>
                    <div class="text-muted small">Tips</div>
                    <div class="small fw-semibold">Buka dari HP → share langsung ke grup WA 1 tap</div>
                </div>
            </div>
        </div>
    </div>

    <div class="sg-filter-card mb-4">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-2 col-6">
                <label class="form-label small fw-semibold text-success">Tanggal (Harian)</label>
                <input type="date" name="tgl_harian" value="<?= h($tgl_harian) ?>" class="form-control form-control-sm">
            </div>
            <div class="col-md-3 col-6">
                <label class="form-label small fw-semibold text-success">Mingguan — Mulai</label>
                <input type="date" name="mg_mulai" value="<?= h($mg_mulai) ?>" class="form-control form-control-sm">
            </div>
            <div class="col-md-3 col-6">
                <label class="form-label small fw-semibold text-success">Mingguan — Selesai</label>
                <input type="date" name="mg_selesai" value="<?= h($mg_selesai) ?>" class="form-control form-control-sm">
            </div>
            <div class="col-md-2 col-6">
                <label class="form-label small fw-semibold text-success">Bulan (Bulanan & Rekap)</label>
                <input type="month" name="bulan" value="<?= h($bulan_rekap) ?>" class="form-control form-control-sm">
            </div>
            <div class="col-md-2 col-12 d-grid">
                <button type="submit" class="btn btn-success btn-sm fw-semibold"><i class="bi bi-funnel me-1"></i>Pakai Periode</button>
            </div>
        </form>
    </div>

    <!-- Daftar Grup -->
    <?php if (!$list_grup): ?>
    <div class="sg-card p-5 text-center text-muted">
        <i class="bi bi-whatsapp fs-1 d-block mb-3 opacity-50"></i>
        <h5>Belum ada grup WA</h5>
        <p class="small">Mulai dengan menambah grup WA di menu <a href="data_wa_grup" class="text-success fw-semibold">Kelola Grup WA</a>.</p>
    </div>
    <?php else: ?>
    <div class="sg-card p-3">
        <?php foreach ($list_grup as $g):
            $anggota = wa_grup_cabang($conn, (int)$g['id']);
            $jml     = count($anggota);
            $gid     = (int)$g['id'];
        ?>
        <div class="sg-row">
            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                <div class="flex-grow-1">
                    <span class="sg-grup-title"><?= h($g['nama_grup']) ?></span>
                    <?php if ($jml > 0): ?>
                        <span class="sg-badge"><?= $jml ?> cabang</span>
                    <?php else: ?>
                        <span class="sg-badge sg-badge-empty">kosong</span>
                    <?php endif; ?>
                    <?php if (!empty($g['nama_investor'])): ?>
                        <span class="badge bg-primary bg-opacity-10 text-primary ms-1" title="Cabang otomatis dari data investor"><i class="bi bi-person-check me-1"></i><?= h($g['nama_investor']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($g['link_invite'])): ?>
                        <a href="<?= h($g['link_invite']) ?>" target="_blank" rel="noopener" class="small ms-2 text-success"><i class="bi bi-box-arrow-up-right"></i> Buka Grup WA</a>
                    <?php endif; ?>
                    <?php if (!empty($g['keterangan'])): ?>
                        <div class="small text-muted mt-1"><?= h($g['keterangan']) ?></div>
                    <?php endif; ?>
                </div>
                <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#modalView<?= $gid ?>">
                    <i class="bi bi-eye"></i> View (<?= $jml ?>)
                </button>
            </div>

            <div class="d-flex flex-wrap gap-2">
                <button class="btn btn-sm btn-success sg-btn-share" <?= $jml ? '' : 'disabled title="Grup belum ada cabang"' ?>
                    data-bs-toggle="modal" data-bs-target="#modalShare<?= $gid ?>Harian">
                    <i class="bi bi-calendar-day"></i> Harian
                </button>
                <button class="btn btn-sm btn-info text-white sg-btn-share" <?= $jml ? '' : 'disabled' ?>
                    data-bs-toggle="modal" data-bs-target="#modalShare<?= $gid ?>Mingguan">
                    <i class="bi bi-calendar-week"></i> Mingguan
                </button>
                <button class="btn btn-sm btn-primary sg-btn-share" <?= $jml ? '' : 'disabled' ?>
                    data-bs-toggle="modal" data-bs-target="#modalShare<?= $gid ?>Bulanan">
                    <i class="bi bi-calendar-month"></i> Bulanan
                </button>
                <button class="btn btn-sm btn-danger sg-btn-share" <?= $jml ? '' : 'disabled' ?>
                    data-bs-toggle="modal" data-bs-target="#modalShare<?= $gid ?>Rekap">
                    <i class="bi bi-graph-up-arrow"></i> Rekapitulasi
                </button>
            </div>
        </div>

        <!-- Modal View Cabang -->
        <div class="modal fade" id="modalView<?= $gid ?>" tabindex="-1" aria-hidden="true">
          <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
              <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="bi bi-shop me-2"></i><?= h($g['nama_grup']) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
              </div>
              <div class="modal-body">
                <?php if (!$anggota): ?>
                    <div class="text-center text-muted py-4">Grup ini belum punya cabang.</div>
                <?php else: ?>
                    <div class="small text-muted mb-2"><?= $jml ?> cabang:</div>
                    <?php foreach ($anggota as $c): ?>
                        <span class="sg-chip-cabang"><i class="bi bi-shop me-1"></i><?= h($c['nama_cabang']) ?></span>
                    <?php endforeach; ?>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>

        <?php
        // Siapkan 4 modal share (harian/mingguan/bulanan/rekap) untuk grup ini
        $wa_link = $g['link_invite'] ?? '';
        // Setiap tipe menyediakan 2 URL builder:
        //  - url_builder   : untuk tombol "Share WA" per cabang (buka tab + auto_share)
        //  - prepare_url   : untuk IFRAME batch (prepare_pdf_blob=1, kirim blob ke parent)
        $share_tipe = [
            'Harian' => [
                'label'        => 'Harian',
                'tipe_kode'    => 'harian',
                'target_page'  => 'rekapitulasi.php',
                'periode_text' => date('d M Y', strtotime($tgl_harian)),
                'url_builder'  => function($id_cabang) use ($tgl_harian, $gid, $g, $wa_link) {
                    return 'rekapitulasi.php?' . http_build_query([
                        'periode' => 'bulanan', 'bulan' => substr($tgl_harian, 0, 7),
                        'id_cabang' => $id_cabang, 'auto_share' => 'harian', 'auto_tgl' => $tgl_harian,
                        'wa_grup' => $gid, 'wa_nama' => $g['nama_grup'], 'wa_link' => $wa_link,
                    ]);
                },
                'prepare_url'  => function($id_cabang, $req_id) use ($tgl_harian) {
                    return 'rekapitulasi.php?' . http_build_query([
                        'periode' => 'bulanan', 'bulan' => substr($tgl_harian, 0, 7),
                        'id_cabang' => $id_cabang,
                        'prepare_pdf_blob' => '1', 'tipe' => 'harian', 'request_id' => $req_id,
                    ]);
                },
            ],
            'Mingguan' => [
                'label'        => 'Mingguan',
                'tipe_kode'    => 'mingguan',
                'target_page'  => 'laporan_mingguan.php',
                'periode_text' => date('d M Y', strtotime($mg_mulai)) . ' – ' . date('d M Y', strtotime($mg_selesai)),
                'url_builder'  => function($id_cabang) use ($mg_mulai, $mg_selesai, $gid, $g, $wa_link) {
                    return 'laporan_mingguan.php?' . http_build_query([
                        'id_cabang' => $id_cabang, 'tgl_mulai' => $mg_mulai, 'tgl_selesai' => $mg_selesai,
                        'auto_share' => '1',
                        'wa_grup' => $gid, 'wa_nama' => $g['nama_grup'], 'wa_link' => $wa_link,
                    ]);
                },
                'prepare_url'  => function($id_cabang, $req_id) use ($mg_mulai, $mg_selesai) {
                    return 'laporan_mingguan.php?' . http_build_query([
                        'id_cabang' => $id_cabang, 'tgl_mulai' => $mg_mulai, 'tgl_selesai' => $mg_selesai,
                        'prepare_pdf_blob' => '1', 'request_id' => $req_id,
                    ]);
                },
            ],
            'Bulanan' => [
                'label'        => 'Bulanan',
                'tipe_kode'    => 'bulanan',
                'target_page'  => 'rekapitulasi.php',
                'periode_text' => date('F Y', strtotime($bulan_rekap . '-01')),
                'url_builder'  => function($id_cabang) use ($bulan_rekap, $gid, $g, $wa_link) {
                    return 'rekapitulasi.php?' . http_build_query([
                        'periode' => 'bulanan', 'bulan' => $bulan_rekap, 'id_cabang' => $id_cabang,
                        'auto_share' => 'bulanan', 'wa_grup' => $gid, 'wa_nama' => $g['nama_grup'], 'wa_link' => $wa_link,
                    ]);
                },
                'prepare_url'  => function($id_cabang, $req_id) use ($bulan_rekap) {
                    return 'rekapitulasi.php?' . http_build_query([
                        'periode' => 'bulanan', 'bulan' => $bulan_rekap, 'id_cabang' => $id_cabang,
                        'prepare_pdf_blob' => '1', 'tipe' => 'bulanan', 'request_id' => $req_id,
                    ]);
                },
            ],
            'Rekap' => [
                'label'        => 'Rekapitulasi',
                'tipe_kode'    => 'rekap',
                'target_page'  => 'rekapitulasi.php',
                'periode_text' => date('F Y', strtotime($bulan_rekap . '-01')),
                'url_builder'  => function($id_cabang) use ($bulan_rekap, $gid, $g, $wa_link) {
                    return 'rekapitulasi.php?' . http_build_query([
                        'periode' => 'bulanan', 'bulan' => $bulan_rekap, 'id_cabang' => $id_cabang,
                        'auto_share' => 'rekap', 'wa_grup' => $gid, 'wa_nama' => $g['nama_grup'], 'wa_link' => $wa_link,
                    ]);
                },
                'prepare_url'  => function($id_cabang, $req_id) use ($bulan_rekap) {
                    return 'rekapitulasi.php?' . http_build_query([
                        'periode' => 'bulanan', 'bulan' => $bulan_rekap, 'id_cabang' => $id_cabang,
                        'prepare_pdf_blob' => '1', 'tipe' => 'rekap', 'request_id' => $req_id,
                    ]);
                },
            ],
        ];
        foreach ($share_tipe as $tipe_key => $tipe):
        ?>
        <div class="modal fade sg-modal-share" id="modalShare<?= $gid ?><?= $tipe_key ?>" tabindex="-1" aria-hidden="true"
             data-grup-id="<?= $gid ?>" data-grup-nama="<?= h($g['nama_grup']) ?>"
             data-tipe-kode="<?= h($tipe['tipe_kode']) ?>" data-tipe-label="<?= h($tipe['label']) ?>"
             data-periode-text="<?= h($tipe['periode_text']) ?>" data-wa-link="<?= h($wa_link) ?>">
          <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
            <div class="modal-content">
              <div class="modal-header bg-light">
                <div>
                    <h5 class="modal-title fw-bold mb-1"><i class="bi bi-whatsapp text-success me-2"></i>Share <?= $tipe['label'] ?> — <?= h($g['nama_grup']) ?></h5>
                    <div class="small text-muted">Periode: <strong><?= h($tipe['periode_text']) ?></strong> &middot; <?= $jml ?> cabang</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
              </div>
              <div class="modal-body">
                <?php if (!empty($g['link_invite'])): ?>
                <div class="alert alert-success py-2 small d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-info-circle me-1"></i>Pastikan sudah masuk ke grup WA tujuan.</span>
                    <a href="<?= h($g['link_invite']) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-success">
                        <i class="bi bi-whatsapp me-1"></i>Buka Grup WA
                    </a>
                </div>
                <?php endif; ?>

                <!-- ======== TOMBOL BATCH: Share Semua Cabang (1 Tap) — HP only ======== -->
                <div class="sg-batch-section mb-3 p-3 rounded-3" style="background:linear-gradient(135deg,#f0fdf4 0%,#dcfce7 100%);border:1px solid #bbf7d0">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <i class="bi bi-phone-fill text-success fs-5"></i>
                        <strong class="small">Share Semua Sekaligus (HP)</strong>
                    </div>
                    <div class="small text-muted mb-2">
                        Satu tap → siapkan <?= $jml ?> PDF cabang → pilih grup WA → kirim semua bareng.
                        <span class="d-block text-danger sg-batch-only-mobile" style="display:none!important">Fitur ini hanya di HP. Di laptop pakai tombol per-cabang di bawah.</span>
                    </div>
                    <button type="button" class="btn btn-success w-100 fw-bold py-2 sg-batch-btn"
                        data-grup-id="<?= $gid ?>" data-tipe-key="<?= $tipe_key ?>">
                        <i class="bi bi-send-fill me-1"></i> Share Semua <?= $jml ?> Cabang (1 Tap)
                    </button>

                    <!-- Progress area (hidden awal) -->
                    <div class="sg-batch-progress mt-3" style="display:none">
                        <div class="progress mb-2" style="height:20px"><div class="progress-bar bg-success sg-progress-bar" style="width:0%">0 / <?= $jml ?></div></div>
                        <div class="small text-muted sg-progress-detail">Menyiapkan PDF cabang 1 dari <?= $jml ?>...</div>
                    </div>
                </div>

                <div class="alert alert-light border small mb-2">
                    <i class="bi bi-check2-square text-primary me-1"></i>
                    <strong>Pilih cabang:</strong> centang cabang yang mau dikirim lewat tombol batch di atas.
                    Boleh juga tetap pakai tombol "Share WA" per baris (tanpa harus dicentang).
                </div>

                <div class="d-flex align-items-center gap-2 mb-2 pb-2 border-bottom">
                    <label class="d-flex align-items-center gap-2 small fw-semibold mb-0" style="cursor:pointer">
                        <input type="checkbox" class="form-check-input m-0 sg-master-check" checked>
                        Centang / Lepas Semua
                    </label>
                    <span class="ms-auto small text-muted">Terpilih: <strong class="sg-selected-count"><?= $jml ?></strong> / <?= $jml ?> cabang</span>
                </div>

                <?php foreach ($anggota as $i => $c):
                    $url = $tipe['url_builder']((int)$c['id_cabang']);
                    $is_harian = ($tipe_key === 'Harian');
                ?>
                <div class="sg-cabang-item is-checked d-flex align-items-center gap-2 gap-md-3 flex-wrap"
                     data-cabang-id="<?= (int)$c['id_cabang'] ?>"
                     data-cabang-nama="<?= h($c['nama_cabang']) ?>"
                     data-share-url-base="<?= h($url) ?>"
                     data-prepare-url-template="<?= h($tipe['prepare_url']((int)$c['id_cabang'], 'REQID')) ?>">
                    <input type="checkbox" class="form-check-input sg-cabang-check" checked style="flex-shrink:0">
                    <div class="rounded-circle bg-light text-muted d-flex align-items-center justify-content-center" style="width:28px;height:28px;font-size:.75rem;font-weight:600;flex-shrink:0"><?= $i + 1 ?></div>
                    <div class="flex-grow-1 min-w-0">
                        <div class="fw-semibold small"><?= h($c['nama_cabang']) ?></div>
                        <div class="text-muted" style="font-size:.72rem">
                            <span class="text-success status-share" data-cabang-id="<?= (int)$c['id_cabang'] ?>" data-tipe="<?= $tipe_key ?>" data-grup="<?= $gid ?>" style="display:none">
                                <i class="bi bi-check-circle-fill"></i> sudah dikirim
                            </span>
                        </div>
                    </div>
                    <?php if ($is_harian): ?>
                    <label class="sg-opsi-bl" title="Cetak PDF termasuk halaman bulan sebelumnya">
                        <input type="checkbox" class="sg-opsi-bulan-lalu" checked>
                        <span>Cetak Bulan Kemarin</span>
                    </label>
                    <?php endif; ?>
                    <span class="sg-batch-indicator" title="Ikut batch share"><i class="bi bi-check-circle-fill"></i></span>
                    <a href="<?= h($url) ?>" target="_blank" rel="noopener"
                       class="btn btn-sm btn-success fw-semibold tombol-share"
                       data-cabang-id="<?= (int)$c['id_cabang'] ?>" data-tipe="<?= $tipe_key ?>" data-grup="<?= $gid ?>">
                        <i class="bi bi-send"></i> Share WA
                    </a>
                </div>
                <?php endforeach; ?>
              </div>
              <div class="modal-footer bg-light justify-content-between">
                <small class="text-muted"><i class="bi bi-info-circle me-1"></i>Reset = centang semua + toggle default + hapus status "sudah dikirim".</small>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary fw-semibold" onclick="resetStatusShare(<?= $gid ?>, '<?= $tipe_key ?>')"><i class="bi bi-arrow-counterclockwise me-1"></i>Reset Semua</button>
                    <button type="button" class="btn btn-sm btn-light border" data-bs-dismiss="modal">Tutup</button>
                </div>
              </div>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// =============================================================
// BATCH SHARE: 1 tap kirim semua PDF cabang ke 1 grup WA (HP only).
//
// Cara kerja:
//   1. User tap tombol "Share Semua N Cabang"
//   2. Buat N <iframe> hidden, masing-masing load halaman PDF cabang
//      dgn param ?prepare_pdf_blob=1 (modenya ada di rekapitulasi.php
//      & laporan_mingguan.php — generate PDF, kirim blob via postMessage).
//   3. Kumpulkan N blob -> navigator.share({files: [...], text: redaksi})
//   4. OS share picker muncul -> user pilih grup WA -> SEMUA PDF kirim.
//
// Fallback: kalau browser tidak support share files (desktop), tombol
// disable + instruksi pakai mode per-cabang di bawah.
// =============================================================

// Deteksi apakah device bisa share file (mobile Android/iOS).
const SG_CAN_SHARE_FILES = (() => {
    if (!navigator.canShare) return false;
    try {
        const t = new File([new Blob(['x'])], 't.txt', { type: 'text/plain' });
        return navigator.canShare({ files: [t] });
    } catch (e) { return false; }
})();

// Disable tombol batch di desktop; tampilkan peringatan.
document.querySelectorAll('.sg-modal-share').forEach(function(modal) {
    const btn = modal.querySelector('.sg-batch-btn');
    const warn = modal.querySelector('.sg-batch-only-mobile');
    if (!SG_CAN_SHARE_FILES) {
        if (btn) {
            btn.disabled = true;
            btn.classList.remove('btn-success');
            btn.classList.add('btn-secondary');
            btn.innerHTML = '<i class="bi bi-x-circle me-1"></i> Fitur Batch Hanya di HP';
        }
        if (warn) warn.style.setProperty('display', 'inline', 'important');
    }
});

// Helper: build URL dari template dgn placeholder REQID
function sgBuildPrepareUrl(template, reqId) {
    return template.replace(/REQID/g, encodeURIComponent(reqId));
}

// Helper: build teks redaksi WA
function sgBuildRedaksi(grupNama, tipeLabel, periodeText, cabangList) {
    let txt = 'Laporan ' + tipeLabel + ' — ' + grupNama + '\n';
    txt += 'Periode: ' + periodeText + '\n';
    txt += cabangList.length + ' cabang:\n';
    cabangList.forEach(function(c, i) { txt += (i+1) + '. ' + c.nama + '\n'; });
    return txt;
}

// Core: orkestrasi batch share
// ---- Helper: build URL dgn tambahan opsi_bulan_lalu (0/1) dari toggle per item
function sgUrlWithOpsi(baseUrl, item) {
    const toggle = item.querySelector('.sg-opsi-bulan-lalu');
    if (!toggle) return baseUrl; // bukan tipe Harian — tidak perlu param
    const val = toggle.checked ? '1' : '0';
    const sep = baseUrl.includes('?') ? '&' : '?';
    return baseUrl + sep + 'opsi_bulan_lalu=' + val;
}

// ---- Checkbox cabang: master toggle + counter + highlight row + sinkronisasi label tombol
function sgUpdateSelection(modal) {
    const items = modal.querySelectorAll('.sg-cabang-item');
    const total = items.length;
    let selected = 0;
    items.forEach(function(it) {
        const chk = it.querySelector('.sg-cabang-check');
        const isOn = chk && chk.checked;
        it.classList.toggle('is-checked', isOn);
        if (isOn) selected++;
    });
    const master = modal.querySelector('.sg-master-check');
    const counter = modal.querySelector('.sg-selected-count');
    const btn = modal.querySelector('.sg-batch-btn');

    if (counter) counter.textContent = selected;
    if (master) {
        master.checked = selected === total && total > 0;
        master.indeterminate = selected > 0 && selected < total;
    }
    if (btn && !modal._sgPendingShare && SG_CAN_SHARE_FILES) {
        if (selected === 0) {
            btn.disabled = true;
            btn.innerHTML = '<i class="bi bi-x-circle me-1"></i> Pilih dulu minimal 1 cabang';
        } else {
            btn.disabled = false;
            btn.classList.remove('btn-secondary', 'btn-warning');
            btn.classList.add('btn-success');
            btn.innerHTML = '<i class="bi bi-send-fill me-1"></i> Share ' + selected + ' Cabang Terpilih (1 Tap)';
        }
    }
}

document.querySelectorAll('.sg-modal-share').forEach(function(modal) {
    const master = modal.querySelector('.sg-master-check');
    if (master) {
        master.addEventListener('change', function() {
            modal.querySelectorAll('.sg-cabang-check').forEach(c => { c.checked = master.checked; });
            sgUpdateSelection(modal);
        });
    }
    modal.querySelectorAll('.sg-cabang-check').forEach(function(c) {
        c.addEventListener('change', function() {
            modal._sgPendingShare = null;
            sgUpdateSelection(modal);
        });
    });
    // Toggle Cetak Bulan Kemarin per cabang — styling + reset pending
    modal.querySelectorAll('.sg-opsi-bulan-lalu').forEach(function(t) {
        const refresh = function() {
            const lbl = t.closest('.sg-opsi-bl');
            if (lbl) lbl.classList.toggle('off', !t.checked);
        };
        refresh();
        t.addEventListener('change', function() {
            refresh();
            modal._sgPendingShare = null; // perubahan opsi = PDF harus di-regenerate
        });
    });
    // Tombol Share WA per-cabang: inject opsi_bulan_lalu ke href SAAT diklik
    modal.querySelectorAll('.tombol-share').forEach(function(a) {
        a.addEventListener('click', function(ev) {
            const item = a.closest('.sg-cabang-item');
            if (!item) return;
            const base = item.dataset.shareUrlBase || a.getAttribute('href');
            a.setAttribute('href', sgUrlWithOpsi(base, item));
        });
    });
    sgUpdateSelection(modal);
});

// Prepare PDF HANYA utk cabang yang DICENTANG. Isi modal._sgPendingShare dgn
// { files, redaksi, title }. TIDAK panggil navigator.share — itu harus dari
// tap kedua user supaya "user gesture" valid (browser menolak share tanpa
// gesture langsung).
async function sgPreparePdfs(modal) {
    const grupId    = modal.dataset.grupId;
    const grupNama  = modal.dataset.grupNama;
    const tipeKode  = modal.dataset.tipeKode;
    const tipeLabel = modal.dataset.tipeLabel;
    const periode   = modal.dataset.periodeText;

    // Hanya cabang yang di-check
    const items = Array.from(modal.querySelectorAll('.sg-cabang-item')).filter(it => {
        const chk = it.querySelector('.sg-cabang-check');
        return chk && chk.checked;
    });
    if (items.length === 0) { alert('Pilih dulu minimal 1 cabang.'); return; }

    const progressBox   = modal.querySelector('.sg-batch-progress');
    const progressBar   = modal.querySelector('.sg-progress-bar');
    const progressDetail= modal.querySelector('.sg-progress-detail');
    const batchBtn      = modal.querySelector('.sg-batch-btn');

    const total = items.length;
    progressBox.style.display = 'block';
    batchBtn.disabled = true;
    batchBtn.classList.remove('btn-success', 'btn-warning');
    batchBtn.classList.add('btn-secondary');
    batchBtn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i> Menyiapkan ' + total + ' PDF...';
    progressBar.style.width = '0%';
    progressBar.textContent = '0 / ' + total;

    const files = [];
    const errors = [];
    const cabangList = items.map(it => ({ id: it.dataset.cabangId, nama: it.dataset.cabangNama }));

    const iframeContainer = document.createElement('div');
    iframeContainer.style.cssText = 'position:fixed;top:-9999px;left:-9999px;width:1200px;height:800px;overflow:hidden;';
    document.body.appendChild(iframeContainer);

    async function processCabang(item, idx) {
        return new Promise((resolve) => {
            const reqId = 'sg_' + grupId + '_' + tipeKode + '_' + idx + '_' + Date.now();
            const baseUrl = sgBuildPrepareUrl(item.dataset.prepareUrlTemplate, reqId);
            const url = sgUrlWithOpsi(baseUrl, item); // tambahkan opsi_bulan_lalu kalau tipe Harian
            const iframe = document.createElement('iframe');
            iframe.style.cssText = 'width:1200px;height:800px;border:0;';
            iframe.src = url;
            let settled = false;
            function finish(result) {
                if (settled) return;
                settled = true;
                window.removeEventListener('message', msgHandler);
                clearTimeout(timeoutId);
                try { iframe.remove(); } catch(e){}
                resolve(result);
            }
            function msgHandler(ev) {
                const d = ev.data || {};
                if (d.request_id !== reqId) return;
                if (d.type === 'pdf-ready' && d.buffer) {
                    const file = new File([d.buffer], d.filename || 'laporan.pdf', { type: 'application/pdf' });
                    finish({ ok: true, file: file, nama: item.dataset.cabangNama });
                } else if (d.type === 'pdf-error') {
                    finish({ ok: false, nama: item.dataset.cabangNama, reason: d.reason || 'unknown' });
                }
            }
            window.addEventListener('message', msgHandler);
            const timeoutId = setTimeout(() => finish({ ok: false, nama: item.dataset.cabangNama, reason: 'timeout' }), 25000);
            iframeContainer.appendChild(iframe);
        });
    }

    for (let i = 0; i < items.length; i++) {
        progressDetail.textContent = 'Menyiapkan PDF ' + (i+1) + ' dari ' + total + ': ' + items[i].dataset.cabangNama + '...';
        const result = await processCabang(items[i], i);
        if (result.ok) files.push(result.file);
        else errors.push(result.nama + ' (' + result.reason + ')');
        const pct = Math.round(((i+1) / total) * 100);
        progressBar.style.width = pct + '%';
        progressBar.textContent = (i+1) + ' / ' + total;
    }

    try { iframeContainer.remove(); } catch(e){}

    if (files.length === 0) {
        batchBtn.disabled = false;
        batchBtn.classList.remove('btn-secondary', 'btn-warning');
        batchBtn.classList.add('btn-success');
        batchBtn.innerHTML = '<i class="bi bi-send-fill me-1"></i> Share Semua ' + total + ' Cabang (1 Tap)';
        progressDetail.innerHTML = '<span class="text-danger">Semua PDF gagal dibuat. Mungkin belum ada data untuk periode ini.</span>';
        modal._sgPendingShare = null;
        return;
    }

    // SIMPAN pending share — tap kedua yang akan trigger navigator.share
    const redaksi = sgBuildRedaksi(grupNama, tipeLabel, periode, cabangList);
    modal._sgPendingShare = {
        files: files,
        text:  redaksi,
        title: 'Laporan ' + tipeLabel + ' ' + grupNama,
    };

    batchBtn.disabled = false;
    batchBtn.classList.remove('btn-secondary', 'btn-success');
    batchBtn.classList.add('btn-warning');
    batchBtn.innerHTML = '<i class="bi bi-send-fill me-1"></i> TAP SEKARANG → BUKA WA PICKER (' + files.length + ' PDF)';
    progressDetail.innerHTML = '<i class="bi bi-check-circle-fill text-success"></i> <strong>' + files.length + ' PDF siap!</strong> Tap tombol oranye di atas untuk buka share picker WA.';
    if (errors.length > 0) {
        progressDetail.innerHTML += '<br><small class="text-warning">Dilewati (tanpa data): ' + errors.join(', ') + '</small>';
    }
}

// Dipanggil langsung dari click handler → user gesture valid utk navigator.share
async function sgDoShare(modal, batchBtn, progressDetail) {
    const pending = modal._sgPendingShare;
    if (!pending || !pending.files || pending.files.length === 0) return;
    try {
        await navigator.share(pending);
        progressDetail.innerHTML = '<i class="bi bi-check-circle-fill text-success"></i> Picker share muncul — pilih grup & kirim di WA.';
        batchBtn.classList.remove('btn-warning');
        batchBtn.classList.add('btn-success');
        batchBtn.disabled = true;
        batchBtn.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i> Share dimulai';
    } catch(e) {
        if (e.name === 'AbortError') {
            progressDetail.textContent = 'Share dibatalkan — tap tombol oranye lagi untuk coba lagi.';
        } else {
            progressDetail.innerHTML = '<span class="text-danger">Gagal share: ' + e.message + '</span>';
        }
    }
}

document.querySelectorAll('.sg-batch-btn').forEach(function(btn) {
    btn.addEventListener('click', function(ev) {
        const modal = btn.closest('.sg-modal-share');
        if (!modal) return;
        // Kalau sudah ada PDF siap (status "warning"), tap ini = share langsung
        if (modal._sgPendingShare && modal._sgPendingShare.files && modal._sgPendingShare.files.length > 0) {
            const progressDetail = modal.querySelector('.sg-progress-detail');
            sgDoShare(modal, btn, progressDetail);
            return;
        }
        // Kalau belum, mulai generate PDF
        sgPreparePdfs(modal);
    });
});

// Reset state saat modal ditutup supaya buka lagi fresh (centang semua + label asli).
document.querySelectorAll('.sg-modal-share').forEach(function(modal) {
    modal.addEventListener('hidden.bs.modal', function() {
        modal._sgPendingShare = null;
        const progressBox = modal.querySelector('.sg-batch-progress');
        modal.querySelectorAll('.sg-cabang-check').forEach(c => { c.checked = true; });
        if (progressBox) progressBox.style.display = 'none';
        sgUpdateSelection(modal);
    });
});

// Tandai "sudah dikirim" setelah user klik "Share WA" — status disimpan di
// localStorage per browser (per periode), supaya PIC/pusat tahu cabang mana
// yang sudah dishare dalam sesi ini.
function statusShareKey(gid, tipe) {
    return 'sg_share_' + gid + '_' + tipe + '_' + '<?= h($tgl_harian) ?>_<?= h($minggu_ini) ?>_<?= h($bulan_rekap) ?>';
}
function markShared(gid, tipe, idCabang) {
    const key = statusShareKey(gid, tipe);
    let arr = [];
    try { arr = JSON.parse(localStorage.getItem(key) || '[]'); } catch(e){}
    if (!arr.includes(idCabang)) { arr.push(idCabang); }
    try { localStorage.setItem(key, JSON.stringify(arr)); } catch(e){}
    refreshStatusModal(gid, tipe);
}
function refreshStatusModal(gid, tipe) {
    const key = statusShareKey(gid, tipe);
    let arr = [];
    try { arr = JSON.parse(localStorage.getItem(key) || '[]'); } catch(e){}
    document.querySelectorAll('.status-share[data-grup="' + gid + '"][data-tipe="' + tipe + '"]').forEach(function(el) {
        const idc = parseInt(el.dataset.cabangId, 10);
        el.style.display = arr.includes(idc) ? '' : 'none';
    });
}
// Reset KOMPREHENSIF: kembalikan modal ke state awal (centang semua,
// toggle Cetak Bulan Kemarin aktif, status "sudah dikirim" dihapus,
// pending batch share clear, progress UI tertutup, tombol batch ke
// "Share Semua N Cabang").
function resetStatusShare(gid, tipe) {
    // 1. Hapus flag "sudah dikirim" dari localStorage
    try { localStorage.removeItem(statusShareKey(gid, tipe)); } catch(e){}
    refreshStatusModal(gid, tipe);

    // 2. Cari modal berdasarkan gid+tipe
    const modal = document.getElementById('modalShare' + gid + tipe);
    if (!modal) return;

    // 3. Centang semua cabang kembali
    modal.querySelectorAll('.sg-cabang-check').forEach(c => { c.checked = true; });
    // 4. Toggle "Cetak Bulan Kemarin" per cabang kembali ke default (ON)
    modal.querySelectorAll('.sg-opsi-bulan-lalu').forEach(t => {
        t.checked = true;
        const lbl = t.closest('.sg-opsi-bl');
        if (lbl) lbl.classList.remove('off');
    });
    // 5. Clear pending batch share
    modal._sgPendingShare = null;
    // 6. Sembunyikan progress UI
    const progressBox = modal.querySelector('.sg-batch-progress');
    if (progressBox) progressBox.style.display = 'none';
    const progressBar = modal.querySelector('.sg-progress-bar');
    if (progressBar) { progressBar.style.width = '0%'; progressBar.textContent = ''; }
    const progressDetail = modal.querySelector('.sg-progress-detail');
    if (progressDetail) progressDetail.textContent = '';
    // 7. Kembalikan tombol batch ke state awal
    if (typeof sgUpdateSelection === 'function') sgUpdateSelection(modal);
}

document.querySelectorAll('.tombol-share').forEach(function(btn) {
    btn.addEventListener('click', function() {
        markShared(parseInt(this.dataset.grup, 10), this.dataset.tipe, parseInt(this.dataset.cabangId, 10));
    });
});

// Saat modal share dibuka, refresh status "sudah dikirim"
document.querySelectorAll('[id^="modalShare"]').forEach(function(m) {
    m.addEventListener('show.bs.modal', function() {
        const sample = this.querySelector('.status-share');
        if (sample) refreshStatusModal(parseInt(sample.dataset.grup, 10), sample.dataset.tipe);
    });
});
</script>
