<?php
require '../config/koneksi.php';
include 'sidebar_pusat.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'pusat') {
    header('Location: ../login');
    exit;
}

// ---------------------------------------------------------------------------
// Filter
// ---------------------------------------------------------------------------
$f_aksi  = trim($_GET['aksi'] ?? '');
$f_user  = trim($_GET['user'] ?? '');
$f_dari  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['dari'] ?? '')  ? $_GET['dari']  : '';
$f_sampai = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['sampai'] ?? '') ? $_GET['sampai'] : '';

$where  = "WHERE 1=1";
$params = [];
$types  = "";

if ($f_aksi !== '')   { $where .= " AND aksi = ?";            $params[] = $f_aksi;  $types .= "s"; }
if ($f_user !== '')   { $where .= " AND username LIKE ?";     $params[] = "%$f_user%"; $types .= "s"; }
if ($f_dari !== '')   { $where .= " AND waktu >= ?";          $params[] = $f_dari . " 00:00:00"; $types .= "s"; }
if ($f_sampai !== '') { $where .= " AND waktu <= ?";          $params[] = $f_sampai . " 23:59:59"; $types .= "s"; }

// Daftar aksi (untuk dropdown)
$aksi_list = [];
$ra = $conn->query("SELECT DISTINCT aksi FROM audit_log ORDER BY aksi");
while ($r = $ra->fetch_assoc()) $aksi_list[] = $r['aksi'];

// Pagination
$limit  = 25;
$page   = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

$sc = $conn->prepare("SELECT COUNT(*) t FROM audit_log $where");
if ($types) $sc->bind_param($types, ...$params);
$sc->execute();
$total = (int) $sc->get_result()->fetch_assoc()['t'];
$sc->close();
$total_pages = max(1, (int) ceil($total / $limit));

$sql = "SELECT * FROM audit_log $where ORDER BY id DESC LIMIT ? OFFSET ?";
$st  = $conn->prepare($sql);
$bt  = $types . "ii";
$bp  = array_merge($params, [$limit, $offset]);
$st->bind_param($bt, ...$bp);
$st->execute();
$rows = $st->get_result();

function badge_aksi(string $a): string
{
    $c = 'secondary';
    if (str_contains($a, 'hapus')) $c = 'danger';
    elseif (str_contains($a, 'tambah') || $a === 'login') $c = 'success';
    elseif (str_contains($a, 'edit') || str_contains($a, 'ganti')) $c = 'warning';
    elseif (str_contains($a, 'gagal') || str_contains($a, 'ditolak')) $c = 'danger';
    elseif ($a === 'logout') $c = 'secondary';
    return '<span class="badge bg-' . $c . '-subtle text-' . $c . ' border border-' . $c . '-subtle px-2 py-1" style="font-size:11px;">' . h($a) . '</span>';
}

?>

<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<style>
    body { background-color: #f4f7fe !important; font-family: 'Plus Jakarta Sans', sans-serif !important; color: #1b2559; }
    .saas-card { background: #fff; border: none !important; border-radius: 20px !important; box-shadow: 0 18px 40px rgba(112,144,176,.06) !important; }
    .title-mark { width: 12px; height: 12px; background: #4318ff; border-radius: 4px; display: inline-block; margin-right: 10px; }
    .al-form .form-control, .al-form .form-select { border-radius: 10px; border: 1px solid #e0e7ff; font-size: 14px; }
    .al-table { width: 100%; margin: 0; }
    .al-table thead th { background: #f8f9fc; color: #8f9bba; font-size: 11px; text-transform: uppercase; letter-spacing: .5px; font-weight: 600; padding: 13px 14px; border-bottom: 1px solid #eef2f9; white-space: nowrap; }
    .al-table tbody td { padding: 13px 14px; border-bottom: 1px solid #f4f7fe; font-size: 13px; vertical-align: top; }
    .al-detail { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 11.5px; color: #475569; white-space: pre-wrap; word-break: break-word; max-width: 420px; }
    .al-time { white-space: nowrap; font-weight: 600; color: #334155; }
    .al-ip { font-family: ui-monospace, monospace; font-size: 11.5px; color: #64748b; }
    @media (max-width: 767.98px) {
        .al-table thead { display: none; }
        .al-table, .al-table tbody, .al-table tr, .al-table td { display: block; width: 100%; }
        .al-table tr { border: 1px solid #e0e7ff; border-radius: 14px; margin-bottom: 14px; padding: 8px 12px; background: #fff; }
        .al-table td { border: 0; display: flex; justify-content: space-between; gap: 12px; padding: 8px 4px; }
        .al-table td::before { content: attr(data-label); font-weight: 700; font-size: 11px; color: #8f9bba; text-transform: uppercase; }
        .al-detail { max-width: none; text-align: right; }
    }
</style>

<div class="container-fluid py-4">
    <div class="d-flex align-items-center mb-1">
        <span class="title-mark"></span>
        <h3 class="fw-bold mb-0" style="color:#1b2559;">Log Aktivitas</h3>
    </div>
    <span class="text-muted small ms-4 d-block mb-4">Jejak audit seluruh aktivitas penting sistem &mdash; <?= number_format($total) ?> entri</span>

    <div class="card saas-card mb-4">
        <div class="card-body p-3 p-md-4">
            <form method="GET" class="al-form row g-2 align-items-end">
                <div class="col-6 col-md-3">
                    <label class="form-label small fw-semibold text-muted mb-1">Aksi</label>
                    <select name="aksi" class="form-select">
                        <option value="">Semua aksi</option>
                        <?php foreach ($aksi_list as $a): ?>
                            <option value="<?= h($a) ?>" <?= $f_aksi === $a ? 'selected' : '' ?>><?= h($a) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label small fw-semibold text-muted mb-1">User</label>
                    <input type="text" name="user" value="<?= h($f_user) ?>" class="form-control" placeholder="username...">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small fw-semibold text-muted mb-1">Dari</label>
                    <input type="date" name="dari" value="<?= h($f_dari) ?>" class="form-control">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small fw-semibold text-muted mb-1">Sampai</label>
                    <input type="date" name="sampai" value="<?= h($f_sampai) ?>" class="form-control">
                </div>
                <div class="col-12 col-md-2 d-flex gap-2">
                    <button class="btn btn-primary w-100 fw-semibold" style="border-radius:10px;"><i class="bi bi-funnel-fill me-1"></i>Filter</button>
                    <?php if ($f_aksi || $f_user || $f_dari || $f_sampai): ?>
                        <a href="audit_log" class="btn btn-light border" style="border-radius:10px;" title="Reset"><i class="bi bi-arrow-clockwise"></i></a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <div class="card saas-card overflow-hidden">
        <div class="table-responsive">
            <table class="al-table">
                <thead>
                    <tr>
                        <th style="width:150px;">Waktu</th>
                        <th style="width:150px;">User</th>
                        <th style="width:150px;">Aksi</th>
                        <th style="width:160px;">Objek</th>
                        <th>Detail</th>
                        <th style="width:120px;">IP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($rows->num_rows === 0): ?>
                        <tr><td colspan="6" class="text-center text-muted py-5"><i class="bi bi-inbox fs-3 d-block mb-2"></i>Tidak ada log</td></tr>
                    <?php else: while ($r = $rows->fetch_assoc()): ?>
                        <tr>
                            <td data-label="Waktu" class="al-time"><?= date('d/m/Y H:i:s', strtotime($r['waktu'])) ?></td>
                            <td data-label="User">
                                <?php if ($r['username']): ?>
                                    <span class="fw-semibold"><?= h($r['username']) ?></span><br>
                                    <span class="text-muted" style="font-size:11px;"><?= h($r['role'] ?? '-') ?></span>
                                <?php else: ?>
                                    <span class="text-muted">&mdash;</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Aksi"><?= badge_aksi($r['aksi']) ?></td>
                            <td data-label="Objek">
                                <?php if ($r['tabel']): ?>
                                    <span class="fw-semibold text-dark"><?= h($r['tabel']) ?></span>
                                    <?php if ($r['record_id'] !== null && $r['record_id'] !== ''): ?>
                                        <span class="text-muted">#<?= h($r['record_id']) ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted">&mdash;</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Detail">
                                <?php if ($r['detail']): ?>
                                    <div class="al-detail"><?php
                                        $j = json_decode($r['detail'], true);
                                        echo h($j !== null ? json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $r['detail']);
                                    ?></div>
                                <?php else: ?>
                                    <span class="text-muted">&mdash;</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="IP" class="al-ip"><?= h($r['ip'] ?? '-') ?></td>
                        </tr>
                    <?php endwhile; endif; ?>
                </tbody>
            </table>
        </div>

        <?php render_pagination($page, $total_pages, ['from' => $offset + 1, 'to' => min($offset + $limit, $total), 'total' => $total, 'label' => 'entri']); ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
