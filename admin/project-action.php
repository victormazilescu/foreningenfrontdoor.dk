<?php
require_once __DIR__ . '/auth.php';
require_login();

$id     = isset($_GET['id'])     ? (int)$_GET['id']  : 0;
$action = $_GET['action'] ?? '';
$token  = $_GET['csrf']   ?? '';

if (!$id || !hash_equals(csrf_token(), $token)) {
    http_response_code(403); die('Acțiune invalidă.');
}

$allowed = ['complete','cancel','delete','activate'];
if (!in_array($action, $allowed)) {
    header('Location: /admin/projects.php'); exit;
}

$pdo = get_db();

if ($action === 'delete') {
    $pdo->prepare('DELETE FROM projects WHERE id=?')->execute([$id]);
    $_SESSION['flash'] = ['type'=>'ok','msg'=>'Proiect șters definitiv.'];
} else {
    $map = ['complete'=>'completed','cancel'=>'cancelled','activate'=>'active'];
    $labels = ['completed'=>'marcat finalizat','cancelled'=>'anulat','active'=>'reactivat'];
    $new = $map[$action];
    $pdo->prepare('UPDATE projects SET status=? WHERE id=?')->execute([$new, $id]);
    $_SESSION['flash'] = ['type'=>'ok','msg'=>'Proiectul a fost '.$labels[$new].'.'];
}

header('Location: /admin/projects.php');
exit;
