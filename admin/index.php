<?php
require_once __DIR__ . '/auth.php';
if (!empty($_SESSION['fd_user'])) {
    header('Location: /admin/dashboard.php'); exit;
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';
    $pdo   = get_db();
    ensure_user_permissions_column($pdo);
    ensure_user_ui_lang_column($pdo);
    $stmt  = $pdo->prepare('SELECT * FROM bf_users WHERE email=? AND active=1 LIMIT 1');
    $stmt->execute([$email]);
    $user  = $stmt->fetch();
    if ($user && password_verify($pass, $user['password'])) {
        session_regenerate_id(true);
        $_SESSION['fd_user'] = [
            'id'             => (int)$user['id'],
            'name'           => $user['name'],
            'email'          => $user['email'],
            'role'           => $user['role'],
            'position'       => $user['position'],
            'position_label' => $user['position_label'],
            'avatar'         => $user['avatar'] ?? null,
            'must_change_pwd'=> (bool)$user['must_change_pwd'],
            'permissions'    => $user['permissions'] ?? null,
            'ui_lang'        => $user['ui_lang'] ?? 'ro',
        ];
        if ($user['must_change_pwd']) {
            header('Location: /admin/change-pwd.php'); exit;
        }
        header('Location: /admin/dashboard.php'); exit;
    } else {
        sleep(1);
        $error = t('err_wrong_credentials');
    }
}
$cur_lang = ui_lang();
$lang_switch_qs = $_GET;
?>
<!DOCTYPE html>
<html lang="<?= e($cur_lang) ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Login — Front Door Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@700;900&family=Jost:wght@300;400;500&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Jost',system-ui,sans-serif;background-color:#000;background-image:radial-gradient(circle at 15% -10%,rgba(255,255,255,.1),transparent 45%),radial-gradient(circle at 100% 10%,rgba(255,255,255,.06),transparent 40%),radial-gradient(circle at 20% 110%,rgba(255,255,255,.05),transparent 40%);background-attachment:fixed;color:#fff;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;-webkit-font-smoothing:antialiased}
.lang-switch{position:fixed;top:20px;right:20px;display:flex;gap:3px;background:rgba(255,255,255,.04);backdrop-filter:blur(14px);border:1px solid rgba(255,255,255,.1);border-radius:999px;padding:3px}
.lang-switch a{font-size:11px;font-weight:700;letter-spacing:.04em;padding:6px 10px;color:rgba(255,255,255,.45);border-radius:999px;transition:color .15s,background .15s}
.lang-switch a:hover{color:rgba(255,255,255,.85)}
.lang-switch a.active{color:#000;background:#fff}
.hero{width:100%;max-width:920px;display:grid;grid-template-columns:1.1fr 1fr;gap:0;border-radius:28px;overflow:hidden;border:1px solid rgba(255,255,255,.09);background:rgba(255,255,255,.025);backdrop-filter:blur(24px) saturate(150%);-webkit-backdrop-filter:blur(24px) saturate(150%);box-shadow:0 30px 90px rgba(0,0,0,.55)}
.hero-side{padding:52px 44px;display:flex;flex-direction:column;align-items:flex-start;justify-content:center;position:relative;background:linear-gradient(160deg,rgba(255,255,255,.07),rgba(255,255,255,0) 60%);border-right:1px solid rgba(255,255,255,.08)}
.hero-side::before{content:'';position:absolute;inset:0;background:radial-gradient(circle at 30% 20%,rgba(255,255,255,.12),transparent 55%);pointer-events:none}
.hero-logo{height:38px;width:auto;flex-shrink:0;object-fit:contain;display:block;margin-bottom:18px;position:relative}
.hero-brand{font-family:'Nunito',sans-serif;font-weight:900;font-size:26px;letter-spacing:-.02em;margin-bottom:6px;position:relative}
.hero-sub{font-size:11px;font-weight:300;letter-spacing:.22em;text-transform:uppercase;color:rgba(255,255,255,.4);margin-bottom:34px;position:relative}
.hero-title{font-family:'Nunito',sans-serif;font-weight:900;font-size:30px;line-height:1.15;letter-spacing:-.01em;margin-bottom:14px;position:relative}
.hero-text{font-size:14px;font-weight:300;line-height:1.6;color:rgba(255,255,255,.6);max-width:320px;position:relative}
.box{padding:52px 44px;display:flex;flex-direction:column;justify-content:center}
label{display:block;font-size:11px;font-weight:500;letter-spacing:.1em;text-transform:uppercase;color:rgba(255,255,255,.45);margin-bottom:7px}
input{width:100%;padding:12px 15px;font-size:15px;font-family:'Jost',sans-serif;font-weight:400;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.16);border-radius:12px;color:#fff;margin-bottom:18px;transition:border-color .15s,background .15s,box-shadow .15s}
input:focus{outline:none;border-color:rgba(255,255,255,.55);background:rgba(255,255,255,.06);box-shadow:0 0 0 3px rgba(255,255,255,.08)}
button{width:100%;padding:14px;background:#fff;color:#000;border:none;border-radius:999px;font-family:'Nunito',sans-serif;font-size:14px;font-weight:900;letter-spacing:.04em;cursor:pointer;transition:opacity .15s,box-shadow .15s,transform .1s;margin-top:4px;box-shadow:0 8px 24px rgba(255,255,255,.12)}
button:hover{opacity:.88;box-shadow:0 10px 30px rgba(255,255,255,.18)}
button:active{transform:scale(.98)}
.err{border:1px solid rgba(200,50,50,.35);color:rgba(255,150,150,.9);background:rgba(200,50,50,.08);border-radius:12px;padding:12px 15px;font-size:13px;font-weight:300;margin-bottom:22px}
.back{font-size:12px;font-weight:300;color:rgba(255,255,255,.3);margin-top:22px;text-align:center}
.back a{color:rgba(255,255,255,.5);border-bottom:1px solid rgba(255,255,255,.18)}
a:focus-visible,button:focus-visible{outline:2px solid #fff;outline-offset:2px}
@media(max-width:720px){
  .hero{grid-template-columns:1fr;border-radius:22px}
  .hero-side{border-right:none;border-bottom:1px solid rgba(255,255,255,.08);padding:36px 28px 28px;align-items:center;text-align:center}
  .hero-logo{margin-bottom:14px}
  .hero-sub{margin-bottom:16px}
  .hero-text{display:none}
  .box{padding:36px 28px 40px}
}
</style>
</head>
<body>
<nav class="lang-switch">
  <?php foreach (UI_LANGS as $lc => $lname):
    $qs = $lang_switch_qs; $qs['lang'] = $lc;
  ?>
    <a href="?<?= e(http_build_query($qs)) ?>" class="<?= $cur_lang===$lc?'active':'' ?>"><?= strtoupper($lc) ?></a>
  <?php endforeach; ?>
</nav>
<div class="hero">
  <div class="hero-side">
    <img class="hero-logo" src="/assets/logos/white_logo_square_transparent_background.png" alt="Front Door">
    <div class="hero-brand">Front Door</div>
    <div class="hero-sub"><?= e(t('login_portal_admin')) ?></div>
    <div class="hero-title"><?= e(t('login_hero_title')) ?></div>
    <p class="hero-text"><?= e(t('login_hero_sub')) ?></p>
  </div>
  <div class="box">
    <?php if ($error): ?><div class="err"><?= e($error) ?></div><?php endif; ?>
    <?php if (isset($_GET['r'])): ?><div class="err"><?= e(t('err_session_expired')) ?></div><?php endif; ?>
    <form method="post">
      <label><?= e(t('field_email')) ?></label>
      <input type="email" name="email" autocomplete="email" required autofocus>
      <label><?= e(t('field_password')) ?></label>
      <input type="password" name="password" autocomplete="current-password" required>
      <button type="submit"><?= e(t('login_btn')) ?> →</button>
    </form>
    <p class="back"><a href="/"><?= e(t('back_to_site')) ?></a></p>
  </div>
</div>
</body>
</html>
