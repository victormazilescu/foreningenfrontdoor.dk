<?php
require_once __DIR__ . '/auth.php';
if (!empty($_SESSION['fd_user'])) {
    header('Location: /admin/events.php'); exit;
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';
    $pdo   = get_db();
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
        ];
        if ($user['must_change_pwd']) {
            header('Location: /admin/change-pwd.php'); exit;
        }
        header('Location: /admin/events.php'); exit;
    } else {
        sleep(1);
        $error = 'Email sau parolă incorectă.';
    }
}
?>
<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Login — Front Door Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@700;900&family=Jost:wght@300;400;500&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Jost',system-ui,sans-serif;background:#000;color:#fff;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;-webkit-font-smoothing:antialiased}
.box{width:100%;max-width:380px}
.brand{font-family:'Nunito',sans-serif;font-weight:900;font-size:24px;letter-spacing:-.02em;margin-bottom:4px}
.brand-sub{font-size:11px;font-weight:300;letter-spacing:.22em;text-transform:uppercase;color:rgba(255,255,255,.3);margin-bottom:40px}
label{display:block;font-size:11px;font-weight:500;letter-spacing:.1em;text-transform:uppercase;color:rgba(255,255,255,.4);margin-bottom:6px}
input{width:100%;padding:12px 14px;font-size:15px;font-family:'Jost',sans-serif;font-weight:400;background:transparent;border:1px solid rgba(255,255,255,.2);color:#fff;margin-bottom:18px;transition:border-color .15s}
input:focus{outline:none;border-color:#fff}
button{width:100%;padding:13px;background:#fff;color:#000;border:none;font-family:'Nunito',sans-serif;font-size:14px;font-weight:900;letter-spacing:.04em;cursor:pointer;transition:opacity .15s;margin-top:4px}
button:hover{opacity:.85}
.err{border:1px solid rgba(200,50,50,.4);color:rgba(255,150,150,.9);padding:12px 14px;font-size:13px;font-weight:300;margin-bottom:24px}
.back{font-size:12px;font-weight:300;color:rgba(255,255,255,.25);margin-top:20px;text-align:center}
.back a{color:rgba(255,255,255,.4);border-bottom:1px solid rgba(255,255,255,.15)}
a:focus-visible,button:focus-visible{outline:2px solid #fff;outline-offset:2px}
</style>
</head>
<body>
<div class="box">
  <div class="brand">Front Door</div>
  <div class="brand-sub">Portal Admin</div>
  <?php if ($error): ?><div class="err"><?= e($error) ?></div><?php endif; ?>
  <?php if (isset($_GET['r'])): ?><div class="err">Sesiunea a expirat.</div><?php endif; ?>
  <form method="post">
    <label>Email</label>
    <input type="email" name="email" autocomplete="email" required autofocus>
    <label>Parolă</label>
    <input type="password" name="password" autocomplete="current-password" required>
    <button type="submit">Intră →</button>
  </form>
  <p class="back"><a href="/">← Înapoi la site</a></p>
</div>
</body>
</html>
