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
    if (!password_verify($cur, $row['password']))   $error = t('err_current_pwd_wrong');
    elseif (strlen($new) < 10)                       $error = t('err_new_pwd_min_len');
    elseif ($new !== $conf)                          $error = t('err_pwd_mismatch');
    elseif ($new === $cur)                           $error = t('err_new_pwd_same_as_current');
    else {
        $hash = password_hash($new, PASSWORD_BCRYPT, ['cost'=>12]);
        $pdo->prepare('UPDATE bf_users SET password=?, must_change_pwd=0 WHERE id=?')->execute([$hash, $user['id']]);
        $_SESSION['fd_user']['must_change_pwd'] = false;
        flash('ok', t('pwd_changed_ok'));
        header('Location: /admin/dashboard.php'); exit;
    }
}
$cur_lang = ui_lang();
?>
<!DOCTYPE html>
<html lang="<?= e($cur_lang) ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Schimbă parola — Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@700;900&family=Jost:wght@300;400;500&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Jost',system-ui,sans-serif;background-color:#000;background-image:radial-gradient(circle at 15% -10%,rgba(255,255,255,.1),transparent 45%),radial-gradient(circle at 100% 10%,rgba(255,255,255,.06),transparent 40%);background-attachment:fixed;color:#fff;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;-webkit-font-smoothing:antialiased}
.box{width:100%;max-width:440px;border-radius:24px;padding:44px 40px;border:1px solid rgba(255,255,255,.09);background:rgba(255,255,255,.03);backdrop-filter:blur(22px) saturate(150%);-webkit-backdrop-filter:blur(22px) saturate(150%);box-shadow:0 24px 70px rgba(0,0,0,.5)}
.brand{font-family:'Nunito',sans-serif;font-weight:900;font-size:24px;letter-spacing:-.02em;margin-bottom:4px}
.brand-sub{font-size:11px;font-weight:300;letter-spacing:.22em;text-transform:uppercase;color:rgba(255,255,255,.5);margin-bottom:32px}
.notice{border:1px solid rgba(255,255,255,.12);border-radius:14px;padding:14px 16px;font-size:14px;font-weight:300;color:rgba(255,255,255,.65);margin-bottom:28px;line-height:1.6;background:rgba(255,255,255,.03)}
.notice strong{color:#fff;font-weight:500}
label{display:block;font-size:11px;font-weight:500;letter-spacing:.1em;text-transform:uppercase;color:rgba(255,255,255,.65);margin-bottom:6px}
input{width:100%;padding:12px 15px;font-size:15px;font-family:'Jost',sans-serif;font-weight:400;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.18);border-radius:12px;color:#fff;margin-bottom:6px;transition:border-color .15s,background .15s,box-shadow .15s}
input:focus{outline:none;border-color:rgba(255,255,255,.55);background:rgba(255,255,255,.06);box-shadow:0 0 0 3px rgba(255,255,255,.08)}
.hint{font-size:11px;font-weight:300;color:rgba(255,255,255,.5);margin-bottom:16px}
button{width:100%;padding:14px;background:#fff;color:#000;border:none;border-radius:999px;font-family:'Nunito',sans-serif;font-size:14px;font-weight:900;letter-spacing:.04em;cursor:pointer;margin-top:8px;transition:opacity .15s,box-shadow .15s,transform .1s;box-shadow:0 8px 24px rgba(255,255,255,.12)}
button:hover{opacity:.88;box-shadow:0 10px 30px rgba(255,255,255,.18)}
button:active{transform:scale(.98)}
.err{border:1px solid rgba(200,50,50,.35);color:rgba(255,150,150,.9);background:rgba(200,50,50,.08);border-radius:12px;padding:12px 15px;font-size:13px;font-weight:300;margin-bottom:20px}
a:focus-visible,button:focus-visible{outline:2px solid #fff;outline-offset:2px}
</style>
</head>
<body>
<div class="box">
  <div class="brand">Front Door</div>
  <div class="brand-sub"><?= e(t('change_pwd_h_sub')) ?></div>
  <div class="notice"><?= e(t('change_pwd_notice_prefix')) ?><strong><?= e($user['name']) ?></strong><?= e(t('change_pwd_notice_suffix')) ?></div>
  <?php if ($error): ?><div class="err"><?= e($error) ?></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <label><?= e(t('current_password_label')) ?></label>
    <input type="password" name="current_password" required autocomplete="current-password">
    <div class="hint"></div>
    <label><?= e(t('new_password_label')) ?></label>
    <input type="password" name="new_password" required autocomplete="new-password">
    <div class="hint"><?= e(t('new_password_hint')) ?></div>
    <label><?= e(t('confirm_new_password_label')) ?></label>
    <input type="password" name="confirm_password" required autocomplete="new-password">
    <button type="submit"><?= e(t('set_password_btn')) ?></button>
  </form>
</div>
</body>
</html>
