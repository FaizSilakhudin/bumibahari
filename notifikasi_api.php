<?php
require 'config/koneksi.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

$id_user = (int) $_SESSION['user_id'];
$action  = $_GET['action'] ?? $_POST['action'] ?? 'list';

if ($action === 'list') {
    $stmt = $conn->prepare("
        SELECT id, jenis, judul, pesan, link, is_read, created_at
        FROM notifikasi
        WHERE id_user = ?
        ORDER BY created_at DESC
        LIMIT 20
    ");
    $stmt->bind_param("i", $id_user);
    $stmt->execute();
    $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $stmt = $conn->prepare("SELECT COUNT(*) c FROM notifikasi WHERE id_user = ? AND is_read = 0");
    $stmt->bind_param("i", $id_user);
    $stmt->execute();
    $unread = (int) $stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();

    echo json_encode(['unread' => $unread, 'items' => $items]);
    exit;
}

if ($action === 'mark_read') {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        http_response_code(403);
        echo json_encode(['error' => 'csrf']);
        exit;
    }
    $id = (int) ($_POST['id'] ?? 0);
    $stmt = $conn->prepare("UPDATE notifikasi SET is_read = 1 WHERE id = ? AND id_user = ?");
    $stmt->bind_param("ii", $id, $id_user);
    $stmt->execute();
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'mark_all_read') {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        http_response_code(403);
        echo json_encode(['error' => 'csrf']);
        exit;
    }
    $stmt = $conn->prepare("UPDATE notifikasi SET is_read = 1 WHERE id_user = ? AND is_read = 0");
    $stmt->bind_param("i", $id_user);
    $stmt->execute();
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'bad_request']);
