<?php
require '../config/koneksi.php';
require '../config/wa_grup.php';
include 'sidebar_pusat.php';

require_role('pusat');

// ============================================================
// HANDLER CRUD
// ============================================================
$flash = null;

// Tambah grup
if (isset($_POST['tambah'])) {
    if (!csrf_check($_POST['csrf'] ?? '')) die("<script>alert('Token tidak valid!'); history.back();</script>");

    $nama       = trim($_POST['nama_grup'] ?? '');
    $link       = trim($_POST['link_invite'] ?? '');
    $keterangan = trim($_POST['keterangan'] ?? '');

    if ($nama === '') {
        $flash = ['danger', 'Nama grup wajib diisi.'];
    } else {
        $stmt = $conn->prepare("INSERT INTO wa_grup (nama_grup, link_invite, keterangan) VALUES (?,?,?)");
        $stmt->bind_param('sss', $nama, $link, $keterangan);
        if ($stmt->execute()) {
            audit($conn, 'wa_grup_tambah', 'wa_grup', $stmt->insert_id, ['nama_grup' => $nama]);
            $stmt->close();
            header('Location: data_wa_grup.php?ok=tambah');
            exit;
        }
        $flash = ['danger', 'Gagal tambah: ' . h($stmt->error)];
        $stmt->close();
    }
}

// Edit grup
if (isset($_POST['edit'])) {
    if (!csrf_check($_POST['csrf'] ?? '')) die("<script>alert('Token tidak valid!'); history.back();</script>");

    $id         = (int) ($_POST['id'] ?? 0);
    $nama       = trim($_POST['nama_grup'] ?? '');
    $link       = trim($_POST['link_invite'] ?? '');
    $keterangan = trim($_POST['keterangan'] ?? '');

    if ($id > 0 && $nama !== '') {
        $stmt = $conn->prepare("UPDATE wa_grup SET nama_grup=?, link_invite=?, keterangan=? WHERE id=?");
        $stmt->bind_param('sssi', $nama, $link, $keterangan, $id);
        $stmt->execute();
        audit($conn, 'wa_grup_edit', 'wa_grup', $id, ['nama_grup' => $nama]);
        $stmt->close();
        header('Location: data_wa_grup.php?ok=edit');
        exit;
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
                $placeholders = implode(',', array_fill(0, count($anggota), '?'));
                $types = 'i' . str_repeat('i', count($anggota));
                $u2 = $conn->prepare("UPDATE cabang SET id_wa_grup=? WHERE id_cabang IN ($placeholders)");
                $u2->bind_param($types, $id, ...$anggota);
                $u2->execute();
                $u2->close();
            }

            $conn->commit();
            audit($conn, 'wa_grup_set_anggota', 'wa_grup', $id, ['jumlah' => count($anggota)]);
            header('Location: data_wa_grup.php?ok=anggota');
            exit;
        } catch (Throwable $e) {
            $conn->rollback();
            $flash = ['danger', 'Gagal simpan anggota: ' . h($e->getMessage())];
        }
    }
}

// ============================================================
// DATA UNTUK TAMPILAN
// ============================================================
$list_grup = wa_grup_list($conn);

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
.wg-chip-cabang.diluar{background:#fef2f2;border-color:#fecaca;color:#b91c1c}
.wg-form-check{padding:6px 10px;border:1px solid #e2e8f0;border-radius:8px;margin-bottom:4px;transition:background .15s}
.wg-form-check:hover{background:#f8fafc}
.wg-form-check.taken{background:#fef3c7}
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
                        <th>Keterangan</th>
                        <th class="text-center">Cabang</th>
                        <th style="width:280px" class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($list_grup as $i => $g):
                    $anggota = wa_grup_cabang($conn, (int)$g['id']);
                ?>
                    <tr>
                        <td class="text-muted"><?= $i + 1 ?></td>
                        <td class="fw-semibold"><?= h($g['nama_grup']) ?>
                            <?php if (!empty($g['link_invite'])): ?>
                                <a href="<?= h($g['link_invite']) ?>" target="_blank" rel="noopener" class="small text-success ms-1" title="Buka Grup WA">
                                    <i class="bi bi-box-arrow-up-right"></i>
                                </a>
                            <?php endif; ?>
                        </td>
                        <td class="small text-muted"><?= h($g['keterangan'] ?: '—') ?></td>
                        <td class="text-center">
                            <?php if ((int)$g['jml_cabang'] > 0): ?>
                                <span class="wg-badge-count"><?= (int)$g['jml_cabang'] ?> cabang</span>
                            <?php else: ?>
                                <span class="wg-badge-empty">kosong</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalAnggota<?= (int)$g['id'] ?>" title="Kelola Cabang Anggota">
                                <i class="bi bi-shop"></i> Kelola Cabang
                            </button>
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
                            <div class="row g-1" id="daftar-anggota-<?= (int)$g['id'] ?>">
                                <?php foreach ($all_cabang as $c):
                                    $tercentang = ((int)$c['id_wa_grup']) === (int)$g['id'];
                                    $milik_grup_lain = ($c['id_wa_grup'] && !$tercentang);
                                ?>
                                <div class="col-md-6 cabang-item" data-nama="<?= h(strtolower($c['nama_cabang'])) ?>">
                                    <label class="wg-form-check d-flex align-items-center gap-2 <?= $milik_grup_lain ? 'taken' : '' ?>">
                                        <input class="form-check-input m-0" type="checkbox" name="anggota[]" value="<?= (int)$c['id_cabang'] ?>" <?= $tercentang ? 'checked' : '' ?>>
                                        <span class="flex-grow-1">
                                            <span class="small fw-semibold"><?= h($c['nama_cabang']) ?></span>
                                            <?php if ($milik_grup_lain): ?>
                                                <span class="d-block text-muted" style="font-size:.68rem">saat ini: <?= h($c['nama_grup']) ?></span>
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
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

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
                <input type="text" name="nama_grup" class="form-control" required placeholder="mis. Grup Area Jakarta Timur"></div>
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

<script>
function filterAnggota(input, idGrup) {
    const q = (input.value || '').toLowerCase().trim();
    document.querySelectorAll('#daftar-anggota-' + idGrup + ' .cabang-item').forEach(function(it) {
        it.style.display = (!q || (it.dataset.nama || '').includes(q)) ? '' : 'none';
    });
}
</script>
