<?php
require_once __DIR__ . '/auth.php';
$user  = require_login();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $cur  = $_POST['current_password']  ?? '';
    $new  = $_POST['new_password']      ?? '';
    $conf = $_POST['confirm_password']  ?? '';
    $pdo  = get_db();
    $row  = $pdo->prepare('SELECT password FROM bf_users WHERE id=?');
    $row->execute([$user['id']]); $row = $row->fetch();
    if (!password_verify($cur, $row['password']))   $error = 'Parola curentă e incorectă.';
    elseif (strlen($new) < 10)                       $error = 'Parola nouă — minim 10 caractere.';
    elseif ($new !== $conf)                          $error = 'Parolele nu coincid.';
    elseif ($new === $cur)                           $error = 'Parola nouă trebuie să difere de cea actuală.';
    else {
        $hash = password_hash($new, PASSWORD_BCRYPT, ['cost'=>12]);
        $pdo->prepare('UPDATE bf_users SET password=?, must_change_pwd=0 WHERE id=?')->execute([$hash, $user['id']]);
        $_SESSION['fd_user']['must_change_pwd'] = false;
        flash('ok', 'Parola a fost schimbată.');
        header('Location: /admin/events.php'); exit;
    }
}
?>
<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Schimbă parola — Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@700;900&family=Jost:wght@300;400;500&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Jost',system-ui,sans-serif;background:#000;color:#fff;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;-webkit-font-smoothing:antialiased}
.box{width:100%;max-width:420px}
.brand{font-family:'Nunito',sans-serif;font-weight:900;font-size:24px;letter-spacing:-.02em;margin-bottom:4px}
.brand-sub{font-size:11px;font-weight:300;letter-spacing:.22em;text-transform:uppercase;color:rgba(255,255,255,.3);margin-bottom:32px}
.notice{border:1px solid rgba(255,255,255,.12);padding:14px 16px;font-size:14px;font-weight:300;color:rgba(255,255,255,.55);margin-bottom:28px;line-height:1.6}
.notice strong{color:#fff;font-weight:500}
label{display:block;font-size:11px;font-weight:500;letter-spacing:.1em;text-transform:uppercase;color:rgba(255,255,255,.4);margin-bottom:6px}
input{width:100%;padding:12px 14px;font-size:15px;font-family:'Jost',sans-serif;font-weight:400;background:transparent;border:1px solid rgba(255,255,255,.2);color:#fff;margin-bottom:6px;transition:border-color .15s}
input:focus{outline:none;border-color:#fff}
.hint{font-size:11px;font-weight:300;color:rgba(255,255,255,.25);margin-bottom:16px}
button{width:100%;padding:13px;background:#fff;color:#000;border:none;font-family:'Nunito',sans-serif;font-size:14px;font-weight:900;letter-spacing:.04em;cursor:pointer;margin-top:8px;transition:opacity .15s}
button:hover{opacity:.85}
.err{border:1px solid rgba(200,50,50,.4);color:rgba(255,150,150,.9);padding:12px 14px;font-size:13px;font-weight:300;margin-bottom:20px}
a:focus-visible,button:focus-visible{outline:2px solid #fff;outline-offset:2px}
</style>
</head>
<body>
<div class="box">
  <div class="brand">Front Door</div>
  <div class="brand-sub">Schimbă parola</div>
  <div class="notice">Bun venit, <strong><?= e($user['name']) ?></strong>. Setează o parolă personală înainte de a continua.</div>
  <?php if ($error): ?><div class="err"><?= e($error) ?></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <label>Parola actuală</label>
    <input type="password" name="current_password" required autocomplete="current-password">
    <div class="hint"></div>
    <label>Parola nouă</label>
    <input type="password" name="new_password" required autocomplete="new-password">
    <div class="hint">Minim 10 caractere.</div>
    <label>Confirmă parola nouă</label>
    <input type="password" name="confirm_password" required autocomplete="new-password">
    <button type="submit">Setează parola →</button>
  </form>
</div>
</body>
</html>
