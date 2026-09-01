<?php
require_once __DIR__ . '/auth.php';
require_login();

$id     = isset($_GET['id'])     ? (int)$_GET['id']     : 0;
$action = isset($_GET['action']) ? $_GET['action']       : '';
$token  = $_GET['csrf'] ?? '';

if (!$id || !hash_equals(csrf_token(), $token)) {
    http_response_code(403);
    die('Acțiune invalidă.');
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
        $path = '/home/dzppntag/public_html/' . ltrim($row['cover_image'], '/');
        if (file_exists($path)) @unlink($path);
    }
    $pdo->prepare('DELETE FROM events WHERE id = ?')->execute([$id]);
    $_SESSION['flash'] = ['type' => 'ok', 'msg' => 'Evenimentul a fost șters definitiv.'];
} else {
    $map = [
        'activate' => 'active',
        'suspend'  => 'suspended',
        'cancel'   => 'cancelled',
    ];
    $new_status = $map[$action];
    $pdo->prepare('UPDATE events SET status = ? WHERE id = ?')->execute([$new_status, $id]);
    $labels = ['active' => 'reactivat', 'suspended' => 'suspendat', 'cancelled' => 'anulat'];
    $_SESSION['flash'] = ['type' => 'ok', 'msg' => 'Evenimentul a fost ' . $labels[$new_status] . '.'];
}

header('Location: /admin/dashboard.php');
exit;
