<?php
require '../config/koneksi.php';
require '../config/wa_grup.php';
require_role('pusat');

// ============================================================
// HANDLER CRUD — DIJALANKAN SEBELUM sidebar_pusat.php di-include,
// supaya header() redirect selalu berjalan tanpa output HTML duluan
// (sebelumnya pernah bikin blank putih pada beberapa skenario).
// ============================================================
$flash = null;

// Tambah grup
if (isset($_POST['tambah'])) {
    if (!csrf_check($_POST['csrf'] ?? '')) die("<script>alert('Token tidak valid!'); history.back();</script>");

    $nama        = trim($_POST['nama_grup'] ?? '');
    $link        = trim($_POST['link_invite'] ?? '');
    $keterangan  = trim($_POST['keterangan'] ?? '');
    $id_investor = !empty($_POST['id_investor']) ? (int) $_POST['id_investor'] : null;

    if ($nama === '') {
        $flash = ['danger', 'Nama grup wajib diisi.'];
    } else {
        try {
            $stmt = $conn->prepare("INSERT INTO wa_grup (nama_grup, link_invite, keterangan, id_investor) VALUES (?,?,?,?)");
            $stmt->bind_param('sssi', $nama, $link, $keterangan, $id_investor);
            $stmt->execute();
            audit($conn, 'wa_grup_tambah', 'wa_grup', $stmt->insert_id, ['nama_grup' => $nama, 'id_investor' => $id_investor]);
            $stmt->close();
            header('Location: data_wa_grup.php?ok=tambah');
            exit;
        } catch (Throwable $e) {
            error_log('data_wa_grup tambah gagal: ' . $e->getMessage());
            if (str_contains($e->getMessage(), 'Duplicate entry')) {
                $flash = ['danger', 'Nama grup "' . h($nama) . '" sudah ada. Pakai nama lain.'];
            } else {
                $flash = ['danger', 'Gagal tambah: ' . h($e->getMessage())];
            }
        }
    }
}

// Edit grup
if (isset($_POST['edit'])) {
    if (!csrf_check($_POST['csrf'] ?? '')) die("<script>alert('Token tidak valid!'); history.back();</script>");

    $id          = (int) ($_POST['id'] ?? 0);
    $nama        = trim($_POST['nama_grup'] ?? '');
    $link        = trim($_POST['link_invite'] ?? '');
    $keterangan  = trim($_POST['keterangan'] ?? '');
    $id_investor = !empty($_POST['id_investor']) ? (int) $_POST['id_investor'] : null;

    if ($id > 0 && $nama !== '') {
        try {
            $stmt = $conn->prepare("UPDATE wa_grup SET nama_grup=?, link_invite=?, keterangan=?, id_investor=? WHERE id=?");
            $stmt->bind_param('sssii', $nama, $link, $keterangan, $id_investor, $id);
            $stmt->execute();
            audit($conn, 'wa_grup_edit', 'wa_grup', $id, ['nama_grup' => $nama, 'id_investor' => $id_investor]);
            $stmt->close();
            header('Location: data_wa_grup.php?ok=edit');
            exit;
        } catch (Throwable $e) {
            error_log('data_wa_grup edit gagal: ' . $e->getMessage());
            if (str_contains($e->getMessage(), 'Duplicate entry')) {
                $flash = ['danger', 'Nama grup "' . h($nama) . '" sudah dipakai grup lain.'];
            } else {
                $flash = ['danger', 'Gagal edit: ' . h($e->getMessage())];
            }
        }
    }
}

// Hapus grup — lepas semua cabang dulu (set NULL), baru hapus grup.
if (isset($_POST['hapus'])) {
    if (!csrf_check($_POST['csrf'] ?? '')) die("<script>alert('Token tidak valid!'); history.back();</script>");

    $id = (int) ($_POST['id'] ?? 0);
    if ($id > 0) {
        $conn->begin_transaction();
        try {
            $u = $conn->prepare("UPDATE cabang SET id_wa_grup=NULL WHERE id_wa_grup=?");
            $u->bind_param('i', $id);
            $u->execute();
            $u->close();

            $d = $conn->prepare("DELETE FROM wa_grup WHERE id=?");
            $d->bind_param('i', $id);
            $d->execute();
            $d->close();

            $conn->commit();
            audit($conn, 'wa_grup_hapus', 'wa_grup', $id, []);
            header('Location: data_wa_grup.php?ok=hapus');
            exit;
        } catch (Throwable $e) {
            $conn->rollback();
            $flash = ['danger', 'Gagal hapus: ' . h($e->getMessage())];
        }
    }
}

// Simpan anggota grup (set/replace cabang-cabang di grup ini)
if (isset($_POST['set_anggota'])) {
    if (!csrf_check($_POST['csrf'] ?? '')) die("<script>alert('Token tidak valid!'); history.back();</script>");

    $id       = (int) ($_POST['id'] ?? 0);
    // $anggota sudah dibersihkan ke INT dgn array_map/intval + filter 0, aman utk dipakai
    // literal di dalam SQL (bukan string user). Pakai literal supaya tidak perlu
    // bind_param spread (yg kurang reliabel saat jumlah anggota banyak).
    $anggota  = array_values(array_filter(array_map('intval', $_POST['anggota'] ?? [])));

    if ($id > 0) {
        $conn->begin_transaction();
        try {
            // 1. Lepas semua cabang yang saat ini di grup ini.
            $u = $conn->prepare("UPDATE cabang SET id_wa_grup=NULL WHERE id_wa_grup=?");
            $u->bind_param('i', $id);
            $u->execute();
            $u->close();

            // 2. Set cabang baru ke grup ini (akan menimpa grup lain-nya kalau sudah di-mapping).
            if ($anggota) {
                $ids_literal = implode(',', $anggota); // sudah pure int
                $u2 = $conn->prepare("UPDATE cabang SET id_wa_grup=? WHERE id_cabang IN ($ids_literal)");
                $u2->bind_param('i', $id);
                $u2->execute();
                $u2->close();
            }

            $conn->commit();
            audit($conn, 'wa_grup_set_anggota', 'wa_grup', $id, ['jumlah' => count($anggota)]);
            header('Location: data_wa_grup.php?ok=anggota');
            exit;
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('data_wa_grup set_anggota gagal: ' . $e->getMessage());
            $flash = ['danger', 'Gagal simpan anggota: ' . h($e->getMessage())];
        }
    }
}

// ============================================================
// Handler selesai → sekarang baru render UI (sidebar + konten).
// ============================================================
include 'sidebar_pusat.php';

// ============================================================
// DATA UNTUK TAMPILAN
// ============================================================
$list_grup     = wa_grup_list($conn);
$list_investor = investor_list_aktif($conn);

// Semua cabang (untuk modal "Kelola Anggota") + info grup saat ini
$all_cabang_res = $conn->query("
    SELECT c.id_cabang, c.nama_cabang, c.id_wa_grup, g.nama_grup
    FROM cabang c
    LEFT JOIN wa_grup g ON g.id = c.id_wa_grup
    ORDER BY c.nama_cabang ASC
");
$all_cabang = $all_cabang_res->fetch_all(MYSQLI_ASSOC);

$ok = $_GET['ok'] ?? '';
if ($ok === 'tambah')  $flash = ['success', 'Grup WA berhasil ditambah.'];
if ($ok === 'edit')    $flash = ['success', 'Grup WA berhasil diubah.'];
if ($ok === 'hapus')   $flash = ['success', 'Grup WA berhasil dihapus. Cabang-cabang di dalamnya menjadi "belum di-mapping".'];
if ($ok === 'anggota') $flash = ['success', 'Anggota grup berhasil disimpan.'];
?>

<style>
.wg-card{background:#fff;border:none;border-radius:14px;box-shadow:0 2px 8px rgba(0,0,0,.04)}
.wg-badge-count{background:#e0e7ff;color:#4338ca;font-size:.72rem;font-weight:700;padding:2px 10px;border-radius:999px}
.wg-badge-empty{background:#fef3c7;color:#b45309;font-size:.72rem;font-weight:700;padding:2px 10px;border-radius:999px}
.wg-chip-cabang{display:inline-block;background:#f1f5f9;border:1px solid #e2e8f0;color:#334155;font-size:.78rem;padding:3px 10px;border-radius:999px;margin:2px}
.wg-form-check{display:flex;align-items:center;gap:10px;padding:8px 12px;border:1px solid #e2e8f0;border-radius:8px;margin-bottom:6px;transition:background .15s;cursor:pointer;width:100%}
.wg-form-check:hover{background:#f8fafc}
.wg-form-check.taken{background:#fef3c7}
.wg-form-check input{flex-shrink:0;margin:0}
.wg-form-check .wg-nama{flex-grow:1;min-width:0;font-size:.85rem;font-weight:600;color:#0f172a}
.wg-form-check .wg-sub{display:block;font-size:.68rem;color:#64748b;font-weight:500}
/* z-index override: pastikan modal di atas sidebar (sidebar z-index 1000) */
.modal{z-index:1070!important}
.modal-backdrop{z-index:1065!important}
.wg-anggota-grid{display:grid;grid-template-columns:1fr;gap:0}
@media(min-width:768px){.wg-anggota-grid{grid-template-columns:1fr 1fr;gap:0 10px}}
</style>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold mb-1 text-dark"><i class="bi bi-whatsapp text-success me-2"></i>Grup WhatsApp</h3>
            <p class="text-muted small mb-0">Daftar grup WA untuk pengiriman laporan. 1 cabang hanya bisa masuk 1 grup.</p>
        </div>
        <button class="btn btn-success fw-semibold" data-bs-toggle="modal" data-bs-target="#modalTambah">
            <i class="bi bi-plus-circle me-1"></i> Tambah Grup
        </button>
    </div>

    <?php if ($flash): ?>
    <div class="alert alert-<?= h($flash[0]) ?> rounded-3"><i class="bi bi-info-circle me-1"></i> <?= $flash[1] ?></div>
    <?php endif; ?>

    <div class="wg-card p-3 mb-4">
        <?php if (!$list_grup): ?>
            <div class="text-center text-muted py-5">
                <i class="bi bi-whatsapp fs-1 d-block mb-2 opacity-50"></i>
                Belum ada grup. Klik "Tambah Grup" untuk mulai.
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:40px">#</th>
                        <th>Nama Grup</th>
                        <th>Investor Terkait</th>
                        <th class="text-center">Cabang</th>
                        <th style="width:300px" class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($list_grup as $i => $g):
                    $pakai_investor = !empty($g['id_investor']);
                ?>
                    <tr>
                        <td class="text-muted"><?= $i + 1 ?></td>
                        <td class="fw-semibold"><?= h($g['nama_grup']) ?>
                            <?php if (!empty($g['link_invite'])): ?>
                                <a href="<?= h($g['link_invite']) ?>" target="_blank" rel="noopener" class="small text-success ms-1" title="Buka Grup WA">
                                    <i class="bi bi-box-arrow-up-right"></i>
                                </a>
                            <?php endif; ?>
                            <?php if (!empty($g['keterangan'])): ?>
                                <div class="small text-muted mt-1" style="font-weight:400"><?= h($g['keterangan']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($pakai_investor): ?>
                                <span class="badge bg-primary bg-opacity-10 text-primary"><i class="bi bi-person-check me-1"></i><?= h($g['nama_investor']) ?></span>
                                <div class="small text-muted mt-1">Cabang otomatis dari data investor</div>
                            <?php else: ?>
                                <span class="small text-muted"><i class="bi bi-dash-circle"></i> Manual (tanpa investor)</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ((int)$g['jml_cabang'] > 0): ?>
                                <span class="wg-badge-count"><?= (int)$g['jml_cabang'] ?> cabang</span>
                            <?php else: ?>
                                <span class="wg-badge-empty">kosong</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <?php if ($pakai_investor): ?>
                                <button class="btn btn-sm btn-outline-secondary" disabled title="Tidak perlu — cabang otomatis dari investor terkait">
                                    <i class="bi bi-magic"></i> Otomatis
                                </button>
                            <?php else: ?>
                                <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalAnggota<?= (int)$g['id'] ?>" title="Kelola Cabang Anggota">
                                    <i class="bi bi-shop"></i> Kelola Cabang
                                </button>
                            <?php endif; ?>
                            <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#modalEdit<?= (int)$g['id'] ?>" title="Edit">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Hapus grup <?= h($g['nama_grup']) ?>? Cabang-cabang di dalamnya akan menjadi \'belum di-mapping\'.');">
                                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                                <input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
                                <button type="submit" name="hapus" class="btn btn-sm btn-outline-danger" title="Hapus">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- =========================================================
         Semua modal (Edit + Kelola Anggota) di LUAR table.
         Dulu di dalam <tbody> → browser auto-move keluar, bikin
         layout kacau.
         ========================================================= -->
    <?php if ($list_grup): foreach ($list_grup as $g): ?>
        <!-- Modal Edit -->
        <div class="modal fade" id="modalEdit<?= (int)$g['id'] ?>" tabindex="-1" aria-hidden="true">
          <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" method="POST">
              <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
              <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="bi bi-pencil me-2"></i>Edit Grup WA</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
              </div>
              <div class="modal-body">
                <div class="mb-3"><label class="form-label small fw-semibold">Nama Grup</label>
                    <input type="text" name="nama_grup" class="form-control" value="<?= h($g['nama_grup']) ?>" required></div>
                <div class="mb-3"><label class="form-label small fw-semibold">Investor Terkait <span class="text-muted">(opsional)</span></label>
                    <select name="id_investor" class="form-select">
                        <option value="">— Tidak terkait investor (manual mapping cabang) —</option>
                        <?php foreach ($list_investor as $inv): ?>
                            <option value="<?= (int)$inv['id_investor'] ?>" <?= ((int)($g['id_investor'] ?? 0) === (int)$inv['id_investor']) ? 'selected' : '' ?>>
                                <?= h($inv['nama_investor']) ?> (<?= (int)$inv['jml_cabang'] ?> cabang)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">Kalau diisi: anggota cabang <strong>otomatis</strong> dari data investor (cabang_investor yg masih aktif).</small>
                </div>
                <div class="mb-3"><label class="form-label small fw-semibold">Link Invite WA (opsional)</label>
                    <input type="url" name="link_invite" class="form-control" placeholder="https://chat.whatsapp.com/XXXX" value="<?= h($g['link_invite'] ?? '') ?>"></div>
                <div class="mb-0"><label class="form-label small fw-semibold">Keterangan (opsional)</label>
                    <input type="text" name="keterangan" class="form-control" value="<?= h($g['keterangan'] ?? '') ?>"></div>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Batal</button>
                <button type="submit" name="edit" class="btn btn-primary">Simpan</button>
              </div>
            </form>
          </div>
        </div>

        <!-- Modal Kelola Anggota -->
        <div class="modal fade" id="modalAnggota<?= (int)$g['id'] ?>" tabindex="-1" aria-hidden="true">
          <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <form class="modal-content" method="POST">
              <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
              <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="bi bi-shop me-2"></i>Kelola Anggota — <?= h($g['nama_grup']) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
              </div>
              <div class="modal-body">
                <div class="alert alert-info small"><i class="bi bi-info-circle me-1"></i>
                    Centang cabang yang masuk ke grup ini. Cabang dengan tanda <span class="wg-badge-empty">kuning</span> saat ini di grup lain — kalau dipindah ke sini, otomatis lepas dari grup lama.
                </div>
                <input type="text" id="search-anggota-<?= (int)$g['id'] ?>" class="form-control mb-3" placeholder="Cari nama cabang..." oninput="filterAnggota(this, <?= (int)$g['id'] ?>)">
                <div class="wg-anggota-grid" id="daftar-anggota-<?= (int)$g['id'] ?>">
                    <?php foreach ($all_cabang as $c):
                        $tercentang = ((int)$c['id_wa_grup']) === (int)$g['id'];
                        $milik_grup_lain = ($c['id_wa_grup'] && !$tercentang);
                    ?>
                    <div class="cabang-item" data-nama="<?= h(strtolower($c['nama_cabang'])) ?>">
                        <label class="wg-form-check <?= $milik_grup_lain ? 'taken' : '' ?>">
                            <input class="form-check-input" type="checkbox" name="anggota[]" value="<?= (int)$c['id_cabang'] ?>" <?= $tercentang ? 'checked' : '' ?>>
                            <span class="wg-nama">
                                <?= h($c['nama_cabang']) ?>
                                <?php if ($milik_grup_lain): ?>
                                    <span class="wg-sub">saat ini: <?= h($c['nama_grup']) ?></span>
                                <?php endif; ?>
                            </span>
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Batal</button>
                <button type="submit" name="set_anggota" class="btn btn-primary"><i class="bi bi-save me-1"></i>Simpan Anggota</button>
              </div>
            </form>
          </div>
        </div>
    <?php endforeach; endif; ?>

    <!-- Modal Tambah -->
    <div class="modal fade" id="modalTambah" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <div class="modal-header">
            <h5 class="modal-title fw-bold"><i class="bi bi-plus-circle me-2"></i>Tambah Grup WA</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="mb-3"><label class="form-label small fw-semibold">Nama Grup <span class="text-danger">*</span></label>
                <input type="text" name="nama_grup" class="form-control" required placeholder="mis. Grup Investor Aden Subagio"></div>
            <div class="mb-3"><label class="form-label small fw-semibold">Investor Terkait <span class="text-muted">(opsional)</span></label>
                <select name="id_investor" class="form-select">
                    <option value="">— Tidak terkait investor (manual mapping cabang) —</option>
                    <?php foreach ($list_investor as $inv): ?>
                        <option value="<?= (int)$inv['id_investor'] ?>">
                            <?= h($inv['nama_investor']) ?> (<?= (int)$inv['jml_cabang'] ?> cabang)
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted">Kalau diisi: anggota cabang <strong>otomatis</strong> dari data investor — tidak perlu mapping manual.</small>
            </div>
            <div class="mb-3"><label class="form-label small fw-semibold">Link Invite WA (opsional)</label>
                <input type="url" name="link_invite" class="form-control" placeholder="https://chat.whatsapp.com/XXXX">
                <small class="text-muted">Supaya tombol "Buka Grup" langsung ngarah ke grup yang tepat.</small></div>
            <div class="mb-0"><label class="form-label small fw-semibold">Keterangan (opsional)</label>
                <input type="text" name="keterangan" class="form-control"></div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Batal</button>
            <button type="submit" name="tambah" class="btn btn-success"><i class="bi bi-save me-1"></i>Simpan</button>
          </div>
        </form>
      </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function filterAnggota(input, idGrup) {
    const q = (input.value || '').toLowerCase().trim();
    document.querySelectorAll('#daftar-anggota-' + idGrup + ' .cabang-item').forEach(function(it) {
        it.style.display = (!q || (it.dataset.nama || '').includes(q)) ? '' : 'none';
    });
}
</script>
