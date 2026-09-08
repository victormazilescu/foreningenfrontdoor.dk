<?php
require_once __DIR__ . '/auth.php';
// Suspend/reactivate/cancel/delete require the 'manage' permission on
// events — the events.php list only shows these buttons when allowed, but
// that was UI-only; this endpoint must enforce it too, or anyone logged in
// could call it directly on any event.
require_perm('events', 'manage');

$id     = isset($_GET['id'])     ? (int)$_GET['id']     : 0;
$action = isset($_GET['action']) ? $_GET['action']       : '';
$token  = $_GET['csrf'] ?? '';

if (!$id || !hash_equals(csrf_token(), $token)) {
    http_response_code(403);
    die(t('invalid_action'));
}

$allowed = ['activate', 'suspend', 'cancel', 'delete'];
if (!in_array($action, $allowed)) {
    header('Location: /admin/dashboard.php');
    exit;
}

$pdo = get_db();

if ($action === 'delete') {
    // Șterge imaginea de copertă dacă există
    $stmt = $pdo->prepare('SELECT cover_image FROM events WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if ($row && $row['cover_image']) {
        $path = dirname(__DIR__) . '/' . ltrim($row['cover_image'], '/');
        if (file_exists($path)) @unlink($path);
    }
    $pdo->prepare('DELETE FROM events WHERE id = ?')->execute([$id]);
    $_SESSION['flash'] = ['type' => 'ok', 'msg' => t('event_deleted')];
} else {
    $map = [
        'activate' => 'active',
        'suspend'  => 'suspended',
        'cancel'   => 'cancelled',
    ];
    $new_status = $map[$action];
    $pdo->prepare('UPDATE events SET status = ? WHERE id = ?')->execute([$new_status, $id]);
    $status_msg_keys = ['active' => 'event_status_reactivated', 'suspended' => 'event_status_suspended', 'cancelled' => 'event_status_cancelled'];
    $_SESSION['flash'] = ['type' => 'ok', 'msg' => t($status_msg_keys[$new_status])];
}

header('Location: /admin/dashboard.php');
exit;
