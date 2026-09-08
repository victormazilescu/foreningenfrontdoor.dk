<?php
require_once __DIR__ . '/auth.php';
// projects.php only shows these buttons with the 'manage' permission, but
// this endpoint must enforce it too, or anyone logged in could call it
// directly on any project.
require_perm('projects', 'manage');

$id     = isset($_GET['id'])     ? (int)$_GET['id']  : 0;
$action = $_GET['action'] ?? '';
$token  = $_GET['csrf']   ?? '';

if (!$id || !hash_equals(csrf_token(), $token)) {
    http_response_code(403); die(t('invalid_action'));
}

$allowed = ['complete','cancel','delete','activate'];
if (!in_array($action, $allowed)) {
    header('Location: /admin/projects.php'); exit;
}

$pdo = get_db();

if ($action === 'delete') {
    $pdo->prepare('DELETE FROM projects WHERE id=?')->execute([$id]);
    $_SESSION['flash'] = ['type'=>'ok','msg'=>t('project_deleted')];
} else {
    $map = ['complete'=>'completed','cancel'=>'cancelled','activate'=>'active'];
    $msg_keys = ['completed'=>'project_status_completed','cancelled'=>'project_status_cancelled','active'=>'project_status_reactivated'];
    $new = $map[$action];
    $pdo->prepare('UPDATE projects SET status=? WHERE id=?')->execute([$new, $id]);
    $_SESSION['flash'] = ['type'=>'ok','msg'=>t($msg_keys[$new])];
}

header('Location: /admin/projects.php');
exit;
