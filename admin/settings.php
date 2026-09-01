<?php
require_once __DIR__ . '/auth.php';
$user     = require_login();
$pdo      = get_db();
$is_admin = $user['role'] === 'admin';
$flash    = get_flash();
$section  = $_GET['s'] ?? ($is_admin ? 'users' : 'profile');

// Forțează ne-adminii la profil
if (!$is_admin && $section !== 'profile') $section = 'profile';

// ── ACȚIUNI POST ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    // Profil propriu
    if ($action === 'update_profile') {
        $name = trim($_POST['name'] ?? '');
        if (!$name) { flash('error','Numele e obligatoriu.'); header('Location: /admin/settings.php?s=profile'); exit; }

        // Avatar upload — same rule as event covers: never trust the
        // client's Content-Type or filename extension, verify real
        // image content with getimagesize() and derive the extension
        // ourselves.
        $avatar = $user['avatar'] ?? null;
        if (!empty($_FILES['avatar']['tmp_name']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['avatar'];
            $allowed_types = [
                IMAGETYPE_JPEG => 'jpg',
                IMAGETYPE_PNG  => 'png',
                IMAGETYPE_WEBP => 'webp',
            ];
            $info = @getimagesize($file['tmp_name']);
            if ($file['size'] <= 3*1024*1024 && $info && isset($allowed_types[$info[2]])) {
                $ext   = $allowed_types[$info[2]];
                $fname = 'avatar-' . $user['id'] . '-' . time() . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
                $dest  = dirname(__DIR__) . '/assets/avatars/' . $fname;
                @mkdir(dirname($dest), 0755, true);
                if (move_uploaded_file($file['tmp_name'], $dest)) {
                    if ($avatar) { @unlink(dirname(__DIR__) . '/' . ltrim($avatar,'/')); }
                    $avatar = 'assets/avatars/' . $fname;
                }
            }
        }
        // Adaugă coloana avatar dacă nu există
        try { $pdo->exec('ALTER TABLE bf_users ADD COLUMN avatar VARCHAR(255) NULL'); } catch(PDOException $e) {}
        $pdo->prepare('UPDATE bf_users SET name=?, avatar=? WHERE id=?')->execute([$name, $avatar, $user['id']]);
        $_SESSION['fd_user']['name']   = $name;
        $_SESSION['fd_user']['avatar'] = $avatar;
        flash('ok','Profilul a fost actualizat.');
        header('Location: /admin/settings.php?s=profile'); exit;
    }

    // Schimbă parola
    if ($action === 'change_password') {
        $cur  = $_POST['current_password']  ?? '';
        $new  = $_POST['new_password']      ?? '';
        $conf = $_POST['confirm_password']  ?? '';
        $row  = $pdo->prepare('SELECT password FROM bf_users WHERE id=?');
        $row->execute([$user['id']]); $row = $row->fetch();
        if (!password_verify($cur, $row['password']))  { flash('error','Parola curentă e incorectă.'); }
        elseif (strlen($new) < 10)                     { flash('error','Minim 10 caractere.'); }
        elseif ($new !== $conf)                        { flash('error','Parolele nu coincid.'); }
        else {
            $hash = password_hash($new, PASSWORD_BCRYPT, ['cost'=>12]);
            $pdo->prepare('UPDATE bf_users SET password=?, must_change_pwd=0 WHERE id=?')->execute([$hash, $user['id']]);
            $_SESSION['fd_user']['must_change_pwd'] = false;
            flash('ok','Parola a fost schimbată.');
        }
        header('Location: /admin/settings.php?s=profile'); exit;
    }

    // ── ADMIN ONLY ──
    if ($is_admin) {
        // Adaugă user
        if ($action === 'add_user') {
            $count = (int)$pdo->query('SELECT COUNT(*) FROM bf_users WHERE active=1')->fetchColumn();
            if ($count >= 8) { flash('error','Limita de 8 membri atinsă.'); }
            else {
                $uname    = trim($_POST['uname']          ?? '');
                $uemail   = trim($_POST['uemail']         ?? '');
                $position = $_POST['position']            ?? 'consilier';
                $plabel   = trim($_POST['position_label'] ?? '');
                if ($uname && $uemail && filter_var($uemail, FILTER_VALIDATE_EMAIL) && array_key_exists($position, POSITIONS)) {
                    $role = POSITIONS[$position]['role'];
                    $temp_pwd = gen_temp_password();
                    $hash = password_hash($temp_pwd, PASSWORD_BCRYPT, ['cost'=>12]);
                    try {
                        $pdo->prepare('INSERT INTO bf_users (name,email,password,role,position,position_label,must_change_pwd,active) VALUES (?,?,?,?,?,?,1,1)')
                            ->execute([$uname,$uemail,$hash,$role,$position,$plabel?:null]);
                        flash('ok',$uname.' adăugat. Parolă inițială: '.$temp_pwd);
                    } catch(PDOException $e) { flash('error','Email există deja.'); }
                } else { flash('error','Date invalide.'); }
            }
            header('Location: /admin/settings.php?s=users'); exit;
        }

        if ($action === 'toggle_user') {
            $uid = (int)($_POST['uid'] ?? 0);
            if ($uid === (int)$user['id']) { flash('error','Nu te poți dezactiva pe tine însuți.'); }
            else {
                $cur = (int)$pdo->prepare('SELECT active FROM bf_users WHERE id=?')->execute([$uid]) ? 1 : 0;
                $cur = $pdo->prepare('SELECT active FROM bf_users WHERE id=?'); $cur->execute([$uid]); $cur = (int)$cur->fetchColumn();
                if (!$cur) { // reactivare
                    $cnt = (int)$pdo->query('SELECT COUNT(*) FROM bf_users WHERE active=1')->fetchColumn();
                    if ($cnt >= 8) { flash('error','Limita de 8 atinsă.'); header('Location: /admin/settings.php?s=users'); exit; }
                }
                $pdo->prepare('UPDATE bf_users SET active=? WHERE id=?')->execute([$cur?0:1, $uid]);
                flash('ok', $cur ? 'Dezactivat.' : 'Reactivat.');
            }
            header('Location: /admin/settings.php?s=users'); exit;
        }

        if ($action === 'reset_pwd') {
            $uid      = (int)($_POST['uid'] ?? 0);
            $temp_pwd = gen_temp_password();
            $hash     = password_hash($temp_pwd, PASSWORD_BCRYPT, ['cost'=>12]);
            $pdo->prepare('UPDATE bf_users SET password=?,must_change_pwd=1 WHERE id=?')->execute([$hash,$uid]);
            flash('ok','Parolă resetată la: '.$temp_pwd);
            header('Location: /admin/settings.php?s=users'); exit;
        }

        if ($action === 'edit_position') {
            $uid  = (int)($_POST['uid']            ?? 0);
            $pos  = $_POST['position']              ?? 'consilier';
            $plbl = trim($_POST['position_label']   ?? '');
            if (array_key_exists($pos, POSITIONS)) {
                $pdo->prepare('UPDATE bf_users SET position=?,position_label=?,role=? WHERE id=?')
                    ->execute([$pos,$plbl?:null,POSITIONS[$pos]['role'],$uid]);
                flash('ok','Poziție actualizată.');
            }
            header('Location: /admin/settings.php?s=users'); exit;
        }

        // Tags CRUD
        if ($action === 'add_tag') {
            $tname = trim($_POST['tname'] ?? '');
            $color = preg_match('/^#[0-9a-fA-F]{6}$/',$_POST['color']??'') ? $_POST['color'] : 'rgba(255,255,255,.15)';
            $sort  = (int)($_POST['sort_order'] ?? 0);
            $valid_cats = ['artistic','cultural','social'];
            $cats = implode(',', array_intersect($_POST['categories'] ?? [], $valid_cats)) ?: 'artistic,cultural,social';
            if ($tname) {
                $slug = strtolower(preg_replace('/[^a-z0-9]+/i','-',iconv('UTF-8','ASCII//TRANSLIT',$tname)));
                try {
                    // Asigură coloana categories
                    try { $pdo->exec("ALTER TABLE tags ADD COLUMN categories SET('artistic','cultural','social') NOT NULL DEFAULT 'artistic,cultural,social' AFTER sort_order"); } catch(PDOException $e) {}
                    $pdo->prepare('INSERT INTO tags (name,slug,color,sort_order,categories) VALUES (?,?,?,?,?)')->execute([$tname,$slug,$color,$sort,$cats]);
                    flash('ok','Tag adăugat.');
                }
                catch(PDOException $e) { flash('error','Slug duplicat.'); }
            }
            header('Location: /admin/settings.php?s=tags'); exit;
        }
        if ($action === 'delete_tag') {
            $pdo->prepare('DELETE FROM tags WHERE id=?')->execute([(int)($_POST['tid']??0)]);
            flash('ok','Tag șters.');
            header('Location: /admin/settings.php?s=tags'); exit;
        }
        if ($action === 'edit_tag') {
            $tid   = (int)($_POST['tid']??0);
            $tname = trim($_POST['tname']??'');
            $color = preg_match('/^#[0-9a-fA-F]{6}$/',$_POST['color']??'') ? $_POST['color'] : 'rgba(255,255,255,.15)';
            $sort  = (int)($_POST['sort_order']??0);
            $valid_cats = ['artistic','cultural','social'];
            $cats = implode(',', array_intersect($_POST['categories'] ?? [], $valid_cats)) ?: 'artistic,cultural,social';
            if ($tname) {
                $slug = strtolower(preg_replace('/[^a-z0-9]+/i','-',iconv('UTF-8','ASCII//TRANSLIT',$tname)));
                $pdo->prepare('UPDATE tags SET name=?,slug=?,color=?,sort_order=?,categories=? WHERE id=?')->execute([$tname,$slug,$color,$sort,$cats,$tid]);
                flash('ok','Tag actualizat.');
            }
            header('Location: /admin/settings.php?s=tags'); exit;
        }
    }

    header('Location: /admin/settings.php?s='.$section); exit;
}

// ── DATE ──────────────────────────────────────────────────────
$users = $is_admin ? $pdo->query('SELECT * FROM bf_users ORDER BY active DESC, id ASC')->fetchAll() : [];
$tags  = $is_admin ? $pdo->query('SELECT * FROM tags ORDER BY sort_order ASC, name ASC')->fetchAll() : [];
$pos_colors = ['presedinte'=>'rgba(255,255,255,.15)','vicepresedinte'=>'rgba(255,255,255,.15)','trezorier'=>'rgba(255,255,255,.15)','consilier'=>'#2E5E4E'];

layout_head('Setări','settings');
?>
<div class="content">
  <?php if ($flash): ?>
    <div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div>
  <?php endif; ?>

  <div class="page-head"><h1>Setări</h1></div>

  <!-- Sub-tabs -->
  <div style="display:flex;gap:4px;border-bottom:1px solid rgba(255,255,255,.05);margin-bottom:28px;flex-wrap:wrap">
    <?php
    $stabs = [
      'profile' => ['icon'=>'👤','label'=>'Profil meu','access'=>'all'],
    ];
    if ($is_admin) $stabs += [
      'users'   => ['icon'=>'👥','label'=>'Utilizatori','access'=>'admin'],
      'tags'    => ['icon'=>'🏷','label'=>'Taguri',      'access'=>'admin'],
    ];
    foreach ($stabs as $k=>$t): ?>
    <a href="/admin/settings.php?s=<?= $k ?>"
       style="padding:11px 16px;font-size:13px;font-weight:600;color:<?= $section===$k?'#fff':'rgba(255,255,255,.25)' ?>;border-bottom:2px solid <?= $section===$k?'rgba(255,255,255,.15)':'transparent' ?>;transition:color .15s">
      <?= $t['icon'] ?> <?= $t['label'] ?>
    </a>
    <?php endforeach; ?>
  </div>

  <?php if ($section === 'profile'): ?>
  <!-- ── PROFIL ── -->
  <div style="max-width:560px">
    <div class="form-section">
      <p class="section-label">Date personale</p>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="update_profile">
        <div style="display:flex;align-items:center;gap:20px;margin-bottom:20px">
          <div style="width:72px;height:72px;border-radius:50%;background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;font-size:26px;font-weight:700;color:#fff;overflow:hidden;flex-shrink:0">
            <?php if (!empty($user['avatar'])): ?>
              <img src="/<?= e(ltrim($user['avatar'],'/')) ?>" style="width:100%;height:100%;object-fit:cover" alt="">
            <?php else: ?>
              <?= e(mb_substr($user['name'],0,1)) ?>
            <?php endif; ?>
          </div>
          <div>
            <p style="font-size:13px;color:rgba(255,255,255,.4);margin-bottom:6px">Fotografie de profil</p>
            <input type="file" name="avatar" accept="image/jpeg,image/png,image/webp" style="font-size:13px;color:rgba(255,255,255,.4)">
            <p style="font-size:11px;color:rgba(255,255,255,.25);margin-top:4px">JPG, PNG sau WebP · max 3 MB</p>
          </div>
        </div>
        <div class="field" style="margin-bottom:14px">
          <label>Nume complet</label>
          <input type="text" name="name" value="<?= e($user['name']) ?>" required>
        </div>
        <div class="field" style="margin-bottom:14px">
          <label>Email</label>
          <input type="text" value="<?= e($user['email']) ?>" disabled style="opacity:.5">
          <span class="field-hint">Emailul nu poate fi schimbat de aici.</span>
        </div>
        <div class="field" style="margin-bottom:18px">
          <label>Poziție</label>
          <input type="text" value="<?= e(position_label($user)) ?>" disabled style="opacity:.5">
        </div>
        <button class="btn btn-solid" type="submit">Salvează profilul</button>
      </form>
    </div>

    <div class="form-section">
      <p class="section-label">Schimbă parola</p>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="change_password">
        <div class="field" style="margin-bottom:12px">
          <label>Parola actuală</label>
          <input type="password" name="current_password" required autocomplete="current-password">
        </div>
        <div class="field" style="margin-bottom:12px">
          <label>Parola nouă</label>
          <input type="password" name="new_password" required autocomplete="new-password">
          <span class="field-hint">Minim 10 caractere.</span>
        </div>
        <div class="field" style="margin-bottom:18px">
          <label>Confirmă parola nouă</label>
          <input type="password" name="confirm_password" required autocomplete="new-password">
        </div>
        <button class="btn btn-solid" type="submit">Schimbă parola</button>
      </form>
    </div>
  </div>

  <?php elseif ($section === 'users' && $is_admin): ?>
  <!-- ── UTILIZATORI ── -->
  <div style="max-width:900px">
    <?php $active_count = count(array_filter($users, fn($u)=>$u['active'])); ?>
    <p style="font-size:13px;color:rgba(255,255,255,.25);margin-bottom:20px">Sloturi: <strong style="color:rgba(255,255,255,.6)"><?= $active_count ?> / 8</strong></p>

    <?php if ($active_count < 8): ?>
    <div class="form-section" style="margin-bottom:24px">
      <p class="section-label">Adaugă membru</p>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="add_user">
        <div class="grid-3" style="margin-bottom:12px">
          <div class="field"><label>Nume *</label><input type="text" name="uname" required placeholder="Prenume Nume"></div>
          <div class="field"><label>Email *</label><input type="email" name="uemail" required placeholder="prenume.nume@foreningenfrontdoor.dk"></div>
          <div class="field">
            <label>Poziție *</label>
            <select name="position" id="posSelect" onchange="togglePLabel()">
              <?php foreach(POSITIONS as $k=>$p): ?>
                <option value="<?= e($k) ?>"><?= e($p['label']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="field" id="pLabelWrap" style="display:none;max-width:300px;margin-bottom:12px">
          <label>Rol personalizat</label>
          <input type="text" name="position_label" placeholder="ex: Responsabil social media">
        </div>
        <div style="display:flex;align-items:center;gap:16px">
          <button class="btn btn-solid" type="submit">Adaugă</button>
          <span style="font-size:12px;color:rgba(255,255,255,.25)">Parola inițială e generată automat și afișată o singură dată, aici, după ce adaugi membrul.</span>
        </div>
      </form>
    </div>
    <?php endif; ?>

    <div style="display:flex;flex-direction:column;gap:10px">
      <?php foreach ($users as $u):
        $isMe = ((int)$u['id'] === (int)$user['id']);
        $col  = $pos_colors[$u['position']] ?? 'rgba(255,255,255,.25)';
        $init = mb_substr($u['name'],0,1);
      ?>
      <div style="background:#0a0a0a;border:1px solid rgba(255,255,255,.05);padding:16px 20px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;<?= $u['active']?'':'opacity:.45' ?>">
        <div style="width:40px;height:40px;border-radius:50%;background:<?= e($col) ?>;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:15px;color:#fff;overflow:hidden;flex-shrink:0">
          <?php if (!empty($u['avatar'])): ?><img src="/<?= e(ltrim($u['avatar'],'/')) ?>" style="width:100%;height:100%;object-fit:cover" alt=""><?php else: ?><?= e($init) ?><?php endif; ?>
        </div>
        <div style="flex:1;min-width:140px">
          <div style="font-size:15px;font-weight:700"><?= e($u['name']) ?><?php if($isMe): ?> <span style="font-size:11px;color:rgba(255,255,255,.6)">— tu</span><?php endif; ?><?php if($u['must_change_pwd']): ?> <span style="font-size:11px;color:#e65100">⚠ parolă nesetată</span><?php endif; ?></div>
          <div style="font-size:12px;color:rgba(255,255,255,.25)"><?= e($u['email']) ?></div>
          <span style="display:inline-block;margin-top:4px;padding:2px 8px;font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;background:<?= e($col) ?>22;color:<?= e($col) ?>"><?= e(position_label($u)) ?></span>
        </div>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
          <button class="btn btn-ghost btn-xs" onclick="toggleEditUser(<?= (int)$u['id'] ?>)">Editează poziția</button>
          <form method="post" style="display:inline" onsubmit="return confirm('Resetezi parola?')">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="reset_pwd">
            <input type="hidden" name="uid" value="<?= (int)$u['id'] ?>">
            <button class="btn btn-warn btn-xs" type="submit">Reset parolă</button>
          </form>
          <?php if (!$isMe): ?>
          <form method="post" style="display:inline">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="toggle_user">
            <input type="hidden" name="uid" value="<?= (int)$u['id'] ?>">
            <button class="btn <?= $u['active']?'btn-danger':'btn-green' ?> btn-xs" type="submit"><?= $u['active']?'Dezactivează':'Reactivează' ?></button>
          </form>
          <?php endif; ?>
        </div>
        <!-- edit position inline -->
        <div id="edituser-<?= (int)$u['id'] ?>" style="display:none;width:100%;background:#000;border:1px solid rgba(29,83,129,.25);padding:14px;margin-top:4px">
          <form method="post">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="edit_position">
            <input type="hidden" name="uid" value="<?= (int)$u['id'] ?>">
            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
              <div class="field">
                <label>Poziție</label>
                <select name="position" onchange="toggleEditPLabel(<?= (int)$u['id'] ?>,this)">
                  <?php foreach(POSITIONS as $k=>$p): ?>
                    <option value="<?= e($k) ?>" <?= $u['position']===$k?'selected':'' ?>><?= e($p['label']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="field" id="eplbl-<?= (int)$u['id'] ?>" style="<?= $u['position']==='consilier'?'':'display:none' ?>">
                <label>Rol personalizat</label>
                <input type="text" name="position_label" value="<?= e($u['position_label']??'') ?>">
              </div>
              <button class="btn btn-solid btn-sm" type="submit">Salvează</button>
              <button class="btn btn-ghost btn-sm" type="button" onclick="toggleEditUser(<?= (int)$u['id'] ?>)">Anulează</button>
            </div>
          </form>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <?php elseif ($section === 'tags' && $is_admin): ?>
  <!-- ── TAGURI ── -->
  <?php
  $cat_labels_t = ['artistic'=>'Artistic','cultural'=>'Cultural','social'=>'Social'];
  $cat_colors_t = ['artistic'=>'rgba(255,255,255,.15)','cultural'=>'#2E5E4E','social'=>'#5A1E3D'];
  ?>
  <div style="max-width:780px">
    <div class="form-section" style="margin-bottom:24px">
      <p class="section-label">Tag nou</p>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="add_tag">
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:12px">
          <div class="field" style="flex:1;min-width:140px"><label>Nume *</label><input type="text" name="tname" required placeholder="ex: Muzică"></div>
          <div class="field"><label>Culoare</label><input type="color" name="color" value="rgba(255,255,255,.15)" style="width:48px;height:38px;padding:2px;background:#000;border:1.5px solid rgba(255,255,255,.1);cursor:pointer"></div>
          <div class="field" style="width:80px"><label>Ordine</label><input type="number" name="sort_order" value="0" min="0"></div>
        </div>
        <div class="field" style="margin-bottom:14px">
          <label style="font-size:12px;font-weight:600;color:rgba(255,255,255,.4);margin-bottom:6px;display:block">Apare în secțiunile</label>
          <div style="display:flex;gap:8px;flex-wrap:wrap">
            <?php foreach ($cat_labels_t as $cv => $cl): ?>
              <input type="checkbox" name="categories[]" id="nc_<?= $cv ?>" value="<?= $cv ?>" checked style="display:none">
              <label for="nc_<?= $cv ?>" style="display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border:1.5px solid <?= $cat_colors_t[$cv] ?>;font-size:12px;font-weight:700;cursor:pointer;color:<?= $cat_colors_t[$cv] ?>;letter-spacing:.06em;text-transform:uppercase"><?= $cl ?></label>
            <?php endforeach; ?>
          </div>
        </div>
        <button class="btn btn-solid" type="submit">Adaugă tagul</button>
      </form>
    </div>

    <div style="display:flex;flex-direction:column;gap:8px">
      <?php foreach ($tags as $tag):
        $tag_cats = array_filter(explode(',', $tag['categories'] ?? ''));
      ?>
      <div style="background:#0a0a0a;border:1px solid rgba(255,255,255,.05);padding:12px 16px;position:relative">
        <div style="display:flex;align-items:center;gap:12px">
          <div style="width:12px;height:12px;border-radius:50%;background:<?= e($tag['color']) ?>;flex-shrink:0"></div>
          <div style="flex:1">
            <div style="font-size:14px;font-weight:600"><?= e($tag['name']) ?></div>
            <div style="font-size:11px;color:rgba(255,255,255,.25);margin-top:2px">#<?= e($tag['slug']) ?> · ord:<?= (int)$tag['sort_order'] ?></div>
            <div style="display:flex;gap:4px;margin-top:5px;flex-wrap:wrap">
              <?php foreach ($tag_cats as $tc): ?>
                <span style="display:inline-block;padding:2px 7px;font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;background:<?= $cat_colors_t[$tc] ?? 'rgba(255,255,255,.15)' ?>22;color:<?= $cat_colors_t[$tc] ?? 'rgba(255,255,255,.15)' ?>"><?= e($cat_labels_t[$tc] ?? $tc) ?></span>
              <?php endforeach; ?>
            </div>
          </div>
          <div style="display:flex;gap:6px;flex-shrink:0">
            <button class="btn btn-ghost btn-xs" onclick="toggleEditTag(<?= (int)$tag['id'] ?>)">Edit</button>
            <form method="post" style="display:inline" onsubmit="return confirm('Ștergi tagul «<?= e($tag['name']) ?>»?')">
              <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="action" value="delete_tag">
              <input type="hidden" name="tid" value="<?= (int)$tag['id'] ?>">
              <button class="btn btn-danger btn-xs" type="submit">Șterge</button>
            </form>
          </div>
        </div>
        <!-- Edit inline -->
        <div id="edittag-<?= (int)$tag['id'] ?>" style="display:none;background:#000;border:1px solid rgba(255,255,255,.12);padding:14px;margin-top:10px">
          <form method="post">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="edit_tag">
            <input type="hidden" name="tid" value="<?= (int)$tag['id'] ?>">
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-bottom:10px">
              <div class="field" style="flex:1"><label>Nume</label><input type="text" name="tname" value="<?= e($tag['name']) ?>" required></div>
              <div class="field"><label>Culoare</label><input type="color" name="color" value="<?= e($tag['color']) ?>" style="width:44px;height:36px;padding:2px;background:#000;border:1.5px solid rgba(255,255,255,.1)"></div>
              <div class="field" style="width:70px"><label>Ord.</label><input type="number" name="sort_order" value="<?= (int)$tag['sort_order'] ?>"></div>
            </div>
            <div style="margin-bottom:10px">
              <div style="font-size:12px;font-weight:600;color:rgba(255,255,255,.4);margin-bottom:6px">Secțiuni</div>
              <div style="display:flex;gap:6px;flex-wrap:wrap">
                <?php foreach ($cat_labels_t as $cv => $cl): ?>
                  <input type="checkbox" name="categories[]" id="et_<?= (int)$tag['id'] ?>_<?= $cv ?>" value="<?= $cv ?>" <?= in_array($cv, $tag_cats) ? 'checked' : '' ?> style="display:none">
                  <label for="et_<?= (int)$tag['id'] ?>_<?= $cv ?>" style="display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border:1.5px solid <?= $cat_colors_t[$cv] ?>;font-size:11px;font-weight:700;cursor:pointer;color:<?= $cat_colors_t[$cv] ?>;letter-spacing:.06em;text-transform:uppercase"><?= $cl ?></label>
                <?php endforeach; ?>
              </div>
            </div>
            <div style="display:flex;gap:6px">
              <button class="btn btn-solid btn-xs" type="submit">Salvează</button>
              <button class="btn btn-ghost btn-xs" type="button" onclick="toggleEditTag(<?= (int)$tag['id'] ?>)">✕</button>
            </div>
          </form>
        </div>
      </div>
      <?php endforeach; ?>
      <?php if (empty($tags)): ?><p style="color:rgba(255,255,255,.25);font-size:14px">Niciun tag creat.</p><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

</div>
<script>
function toggleEditUser(id){ var el=document.getElementById('edituser-'+id); el.style.display=el.style.display==='none'?'block':'none'; }
function toggleEditTag(id){ var el=document.getElementById('edittag-'+id); el.style.display=el.style.display==='none'?'block':'none'; }
// Toggle cat checkbox visual
document.querySelectorAll('input[type=checkbox][name="categories[]"]').forEach(function(cb){
  function upd(){ var lbl=document.querySelector('label[for="'+cb.id+'"]'); if(lbl) lbl.style.background=cb.checked?'rgba(255,255,255,.05)':''; }
  upd(); cb.addEventListener('change',upd);
});
function togglePLabel(){ document.getElementById('pLabelWrap').style.display=document.getElementById('posSelect').value==='consilier'?'block':'none'; }
function toggleEditPLabel(id,sel){ document.getElementById('eplbl-'+id).style.display=sel.value==='consilier'?'block':'none'; }
</script>
<?php layout_foot(); ?>
