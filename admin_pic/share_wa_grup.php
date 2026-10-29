<?php
require '../config/koneksi.php';
require '../config/wa_grup.php';
include 'sidebar.php';

require_role('pic');

$id_user   = current_user_id();
$cabang_ids = pic_cabang_ids($conn, $id_user);

// ============================================================
// FILTER PERIODE
// ============================================================
$tgl_harian   = $_GET['tgl_harian']   ?? date('Y-m-d', strtotime('-1 day'));
$minggu_ini   = $_GET['minggu']       ?? date('Y-\WW');
$bulan_rekap  = $_GET['bulan']        ?? date('Y-m');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl_harian))      $tgl_harian  = date('Y-m-d', strtotime('-1 day'));
if (!preg_match('/^\d{4}-W\d{2}$/i',      $minggu_ini))     $minggu_ini  = date('Y-\WW');
if (!preg_match('/^\d{4}-\d{2}$/',        $bulan_rekap))    $bulan_rekap = date('Y-m');

// PIC hanya lihat grup yang berisi cabang-cabang yang dia pegang.
// jml_cabang di output = hanya hitung cabang milik PIC.
$list_grup  = wa_grup_list($conn, $cabang_ids);
$total_grup = count($list_grup);
$total_cab  = 0;
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
.sg-cabang-item{border-bottom:1px solid #f1f5f9;padding:10px 0}
.sg-cabang-item:last-child{border-bottom:none}
.modal{z-index:1070!important}
.modal-backdrop{z-index:1065!important}
</style>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h3 class="fw-bold mb-1 text-dark"><i class="bi bi-whatsapp text-success me-2"></i>Share WA Group</h3>
            <p class="text-muted small mb-0">Kirim laporan ke grup WhatsApp. Hanya tampil grup yang berisi cabang Anda.</p>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="sg-card p-3 d-flex align-items-center gap-3">
                <div class="rounded-circle bg-success bg-opacity-10 d-flex align-items-center justify-content-center" style="width:48px;height:48px">
                    <i class="bi bi-people-fill text-success fs-4"></i>
                </div>
                <div>
                    <div class="text-muted small">Grup Terkait Anda</div>
                    <div class="fw-bold fs-5"><?= $total_grup ?> grup</div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="sg-card p-3 d-flex align-items-center gap-3">
                <div class="rounded-circle bg-primary bg-opacity-10 d-flex align-items-center justify-content-center" style="width:48px;height:48px">
                    <i class="bi bi-shop text-primary fs-4"></i>
                </div>
                <div>
                    <div class="text-muted small">Cabang Anda di Grup</div>
                    <div class="fw-bold fs-5"><?= $total_cab ?> cabang</div>
                </div>
            </div>
        </div>
    </div>

    <div class="sg-filter-card mb-4">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3 col-6">
                <label class="form-label small fw-semibold text-success">Tanggal (Harian)</label>
                <input type="date" name="tgl_harian" value="<?= h($tgl_harian) ?>" class="form-control form-control-sm">
            </div>
            <div class="col-md-3 col-6">
                <label class="form-label small fw-semibold text-success">Minggu</label>
                <input type="week" name="minggu" value="<?= h($minggu_ini) ?>" class="form-control form-control-sm">
            </div>
            <div class="col-md-3 col-6">
                <label class="form-label small fw-semibold text-success">Bulan</label>
                <input type="month" name="bulan" value="<?= h($bulan_rekap) ?>" class="form-control form-control-sm">
            </div>
            <div class="col-md-3 col-6 d-grid">
                <button type="submit" class="btn btn-success btn-sm fw-semibold"><i class="bi bi-funnel me-1"></i>Pakai Periode Ini</button>
            </div>
        </form>
    </div>

    <?php if (!$list_grup): ?>
    <div class="sg-card p-5 text-center text-muted">
        <i class="bi bi-whatsapp fs-1 d-block mb-3 opacity-50"></i>
        <h5>Belum ada grup WA</h5>
        <p class="small">Cabang Anda belum di-mapping ke grup WA. Minta admin pusat untuk mengatur mapping di menu Kelola Grup WA.</p>
    </div>
    <?php else: ?>
    <div class="sg-card p-3">
        <?php foreach ($list_grup as $g):
            $anggota = wa_grup_cabang($conn, (int)$g['id'], $cabang_ids);
            $jml     = count($anggota);
            $gid     = (int)$g['id'];
        ?>
        <div class="sg-row">
            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                <div class="flex-grow-1">
                    <span class="sg-grup-title"><?= h($g['nama_grup']) ?></span>
                    <span class="sg-badge"><?= $jml ?> cabang Anda</span>
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
                <button class="btn btn-sm btn-success sg-btn-share" data-bs-toggle="modal" data-bs-target="#modalShare<?= $gid ?>Harian">
                    <i class="bi bi-calendar-day"></i> Harian
                </button>
                <button class="btn btn-sm btn-info text-white sg-btn-share" data-bs-toggle="modal" data-bs-target="#modalShare<?= $gid ?>Mingguan">
                    <i class="bi bi-calendar-week"></i> Mingguan
                </button>
                <button class="btn btn-sm btn-primary sg-btn-share" data-bs-toggle="modal" data-bs-target="#modalShare<?= $gid ?>Bulanan">
                    <i class="bi bi-calendar-month"></i> Bulanan
                </button>
                <button class="btn btn-sm btn-danger sg-btn-share" data-bs-toggle="modal" data-bs-target="#modalShare<?= $gid ?>Rekap">
                    <i class="bi bi-graph-up-arrow"></i> Rekapitulasi
                </button>
            </div>
        </div>

        <!-- Modal View -->
        <div class="modal fade" id="modalView<?= $gid ?>" tabindex="-1" aria-hidden="true">
          <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
              <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="bi bi-shop me-2"></i><?= h($g['nama_grup']) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
              </div>
              <div class="modal-body">
                <div class="small text-muted mb-2">Cabang Anda di grup ini (<?= $jml ?>):</div>
                <?php foreach ($anggota as $c): ?>
                    <span class="sg-chip-cabang"><i class="bi bi-shop me-1"></i><?= h($c['nama_cabang']) ?></span>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        </div>

        <?php
        $share_tipe = [
            'Harian' => [
                'label'        => 'Harian',
                'periode_text' => date('d M Y', strtotime($tgl_harian)),
                'url_builder'  => function($id_cabang) use ($tgl_harian, $gid, $g) {
                    return 'rekapitulasi.php?' . http_build_query([
                        'periode'   => 'bulanan',
                        'bulan'     => substr($tgl_harian, 0, 7),
                        'id_cabang' => $id_cabang,
                        'auto_share'=> 'harian',
                        'auto_tgl'  => $tgl_harian,
                        'wa_grup'   => $gid,
                        'wa_nama'   => $g['nama_grup'],
                    ]);
                },
            ],
            'Mingguan' => [
                'label'        => 'Mingguan',
                'periode_text' => $minggu_ini,
                'url_builder'  => function($id_cabang) use ($minggu_ini, $gid, $g) {
                    return 'laporan_mingguan.php?' . http_build_query([
                        'id_cabang' => $id_cabang,
                        'minggu'    => $minggu_ini,
                        'auto_share'=> '1',
                        'wa_grup'   => $gid,
                        'wa_nama'   => $g['nama_grup'],
                    ]);
                },
            ],
            'Bulanan' => [
                'label'        => 'Bulanan',
                'periode_text' => date('F Y', strtotime($bulan_rekap . '-01')),
                'url_builder'  => function($id_cabang) use ($bulan_rekap, $gid, $g) {
                    return 'rekapitulasi.php?' . http_build_query([
                        'periode'   => 'bulanan',
                        'bulan'     => $bulan_rekap,
                        'id_cabang' => $id_cabang,
                        'auto_share'=> 'bulanan',
                        'wa_grup'   => $gid,
                        'wa_nama'   => $g['nama_grup'],
                    ]);
                },
            ],
            'Rekap' => [
                'label'        => 'Rekapitulasi',
                'periode_text' => date('F Y', strtotime($bulan_rekap . '-01')),
                'url_builder'  => function($id_cabang) use ($bulan_rekap, $gid, $g) {
                    return 'rekapitulasi.php?' . http_build_query([
                        'periode'   => 'bulanan',
                        'bulan'     => $bulan_rekap,
                        'id_cabang' => $id_cabang,
                        'auto_share'=> 'rekap',
                        'wa_grup'   => $gid,
                        'wa_nama'   => $g['nama_grup'],
                    ]);
                },
            ],
        ];
        foreach ($share_tipe as $tipe_key => $tipe):
        ?>
        <div class="modal fade" id="modalShare<?= $gid ?><?= $tipe_key ?>" tabindex="-1" aria-hidden="true">
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

                <div class="alert alert-light border small mb-3">
                    <i class="bi bi-lightbulb-fill text-warning me-1"></i>
                    Klik "Share WA" per cabang di bawah. HP → share picker; laptop → download PDF + WA Web.
                </div>

                <?php foreach ($anggota as $i => $c):
                    $url = $tipe['url_builder']((int)$c['id_cabang']);
                ?>
                <div class="sg-cabang-item d-flex align-items-center gap-3">
                    <div class="rounded-circle bg-light text-muted d-flex align-items-center justify-content-center" style="width:32px;height:32px;font-size:.8rem;font-weight:600"><?= $i + 1 ?></div>
                    <div class="flex-grow-1">
                        <div class="fw-semibold small"><?= h($c['nama_cabang']) ?></div>
                        <div class="text-muted" style="font-size:.72rem">
                            <span class="text-success status-share" data-cabang-id="<?= (int)$c['id_cabang'] ?>" data-tipe="<?= $tipe_key ?>" data-grup="<?= $gid ?>" style="display:none">
                                <i class="bi bi-check-circle-fill"></i> sudah dikirim
                            </span>
                        </div>
                    </div>
                    <a href="<?= h($url) ?>" target="_blank" rel="noopener"
                       class="btn btn-sm btn-success fw-semibold tombol-share"
                       data-cabang-id="<?= (int)$c['id_cabang'] ?>" data-tipe="<?= $tipe_key ?>" data-grup="<?= $gid ?>">
                        <i class="bi bi-send"></i> Share WA
                    </a>
                </div>
                <?php endforeach; ?>
              </div>
              <div class="modal-footer bg-light justify-content-between">
                <small class="text-muted"><i class="bi bi-arrow-clockwise me-1"></i>Status tersimpan di browser.</small>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="resetStatusShare(<?= $gid ?>, '<?= $tipe_key ?>')"><i class="bi bi-arrow-counterclockwise me-1"></i>Reset</button>
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
function resetStatusShare(gid, tipe) {
    try { localStorage.removeItem(statusShareKey(gid, tipe)); } catch(e){}
    refreshStatusModal(gid, tipe);
}
document.querySelectorAll('.tombol-share').forEach(function(btn) {
    btn.addEventListener('click', function() {
        markShared(parseInt(this.dataset.grup, 10), this.dataset.tipe, parseInt(this.dataset.cabangId, 10));
    });
});
document.querySelectorAll('[id^="modalShare"]').forEach(function(m) {
    m.addEventListener('show.bs.modal', function() {
        const sample = this.querySelector('.status-share');
        if (sample) refreshStatusModal(parseInt(sample.dataset.grup, 10), sample.dataset.tipe);
    });
});
</script>
