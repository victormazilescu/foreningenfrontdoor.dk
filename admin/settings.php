<?php
require_once __DIR__ . '/auth.php';
$user     = require_login();
$pdo      = get_db();
$is_admin = $user['role'] === 'admin';
ensure_user_permissions_column($pdo);
ensure_user_member_link_column($pdo);
ensure_user_avatar_column($pdo);
ensure_user_ui_lang_column($pdo);
ensure_tags_i18n_schema($pdo);
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
        $ui_lang = $_POST['ui_lang'] ?? '';
        if (!array_key_exists($ui_lang, UI_LANGS)) { $ui_lang = $user['ui_lang'] ?? 'ro'; }

        $pdo->prepare('UPDATE bf_users SET name=?, avatar=?, ui_lang=? WHERE id=?')->execute([$name, $avatar, $ui_lang, $user['id']]);
        $_SESSION['fd_user']['name']    = $name;
        $_SESSION['fd_user']['avatar']  = $avatar;
        $_SESSION['fd_user']['ui_lang'] = $ui_lang;
        // Preferința salvată pe cont câștigă din nou — orice comutare rapidă
        // ?lang= din sesiunea curentă nu mai are rost să suprascrie alegerea
        // proaspăt salvată.
        unset($_SESSION['fd_ui_lang']);
        flash('ok', t('profile_updated'));
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
            if ($count >= MAX_PANEL_ACCOUNTS) { flash('error','Limita de '.MAX_PANEL_ACCOUNTS.' membri atinsă.'); }
            else {
                $uname    = trim($_POST['uname']          ?? '');
                $uemail   = trim($_POST['uemail']         ?? '');
                $position = $_POST['position']            ?? 'consilier';
                $plabel   = trim($_POST['position_label'] ?? '');
                $cap_error = array_key_exists($position, POSITIONS) ? position_capacity_error($pdo, $position) : null;
                if ($cap_error) {
                    flash('error', $cap_error);
                } elseif ($uname && $uemail && filter_var($uemail, FILTER_VALIDATE_EMAIL) && array_key_exists($position, POSITIONS)) {
                    $role = POSITIONS[$position]['role'];
                    // Accesul granular (bifele) se aplică acum și lui revizor, nu doar
                    // consilierului — vezi CONSILIER_PERMISSION_SCHEMA / has_perm().
                    $perms_json = in_array($role, ['member', 'revizor'], true) ? build_permissions_json($_POST['perm'] ?? []) : null;
                    $temp_pwd = gen_temp_password();
                    $hash = password_hash($temp_pwd, PASSWORD_BCRYPT, ['cost'=>12]);
                    // Poziții exclusive (bestyrelsen + revizor) — la o desemnare nouă,
                    // dezactivăm automat cine ocupă deja poziția, ca să nu rămână doi
                    // „președinți" simultan.
                    $bumped = find_active_position_holder($pdo, $position);
                    if ($bumped) { delete_panel_account($pdo, $bumped['id']); }
                    try {
                        $pdo->prepare('INSERT INTO bf_users (name,email,password,role,position,position_label,permissions,must_change_pwd,active) VALUES (?,?,?,?,?,?,?,1,1)')
                            ->execute([$uname,$uemail,$hash,$role,$position,$plabel?:null,$perms_json]);
                        $msg = $uname.' adăugat. Parolă inițială: '.$temp_pwd;
                        if ($bumped) { $msg .= ' '.$bumped['name'].' a fost înlocuit automat — contul lui a fost șters (ocupa aceeași poziție).'; }
                        flash('ok', $msg);
                    } catch(PDOException $e) { flash('error','Email există deja.'); }
                } else { flash('error','Date invalide.'); }
            }
            header('Location: /admin/settings.php?s=users'); exit;
        }

        if ($action === 'toggle_user') {
            // „Dezactivează" scoate contul definitiv din panou (delete_panel_account),
            // nu doar îi blochează login-ul — postul dispare din listă și profilul de
            // membru legat redevine liber pentru o desemnare nouă (vezi auth.php).
            $uid = (int)($_POST['uid'] ?? 0);
            if ($uid === (int)$user['id']) { flash('error','Nu te poți dezactiva pe tine însuți.'); }
            else {
                $target = $pdo->prepare('SELECT name FROM bf_users WHERE id=?'); $target->execute([$uid]); $target = $target->fetch();
                if ($target) {
                    delete_panel_account($pdo, $uid);
                    flash('ok', $target['name'] . ' a fost dezactivat — contul din panou a fost șters. Poate primi un rol nou oricând, din profilul lui de membru sau din formularul de mai sus.');
                }
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
                $cap_error = position_capacity_error($pdo, $pos, $uid);
                if ($cap_error) {
                    flash('error', $cap_error);
                } else {
                    // Poziții exclusive — dacă mutăm pe cineva pe o poziție deja
                    // ocupată de alt cont activ, dezactivăm acel cont automat.
                    $bumped = find_active_position_holder($pdo, $pos, $uid);
                    if ($bumped) { delete_panel_account($pdo, $bumped['id']); }
                    $new_role   = POSITIONS[$pos]['role'];
                    $perms_json = in_array($new_role, ['member', 'revizor'], true) ? build_permissions_json($_POST['perm'] ?? []) : null;
                    $pdo->prepare('UPDATE bf_users SET position=?,position_label=?,role=?,permissions=? WHERE id=?')
                        ->execute([$pos,$plbl?:null,$new_role,$perms_json,$uid]);
                    $msg = 'Poziție actualizată.';
                    if ($bumped) { $msg .= ' '.$bumped['name'].' a fost înlocuit automat — contul lui a fost șters (ocupa aceeași poziție).'; }
                    flash('ok', $msg);
                }
            }
            header('Location: /admin/settings.php?s=users'); exit;
        }

        // Tags CRUD
        if ($action === 'add_tag') {
            $tname_ro = trim($_POST['tname_ro'] ?? '');
            $tname_da = trim($_POST['tname_da'] ?? '');
            $tname_en = trim($_POST['tname_en'] ?? '');
            $color = preg_match('/^#[0-9a-fA-F]{6}$/',$_POST['color']??'') ? $_POST['color'] : 'rgba(255,255,255,.15)';
            $sort  = (int)($_POST['sort_order'] ?? 0);
            $valid_cats = ['artistic','cultural','social'];
            $cats = implode(',', array_intersect($_POST['categories'] ?? [], $valid_cats)) ?: 'artistic,cultural,social';
            if ($tname_ro) {
                if (!$tname_da) $tname_da = $tname_ro;
                if (!$tname_en) $tname_en = $tname_ro;
                $slug = strtolower(preg_replace('/[^a-z0-9]+/i','-',iconv('UTF-8','ASCII//TRANSLIT',$tname_ro)));
                try {
                    // Asigură coloana categories
                    try { $pdo->exec("ALTER TABLE tags ADD COLUMN categories SET('artistic','cultural','social') NOT NULL DEFAULT 'artistic,cultural,social' AFTER sort_order"); } catch(PDOException $e) {}
                    $pdo->prepare('INSERT INTO tags (name,name_ro,name_da,name_en,slug,color,sort_order,categories) VALUES (?,?,?,?,?,?,?,?)')->execute([$tname_ro,$tname_ro,$tname_da,$tname_en,$slug,$color,$sort,$cats]);
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
            $tname_ro = trim($_POST['tname_ro']??'');
            $tname_da = trim($_POST['tname_da']??'');
            $tname_en = trim($_POST['tname_en']??'');
            $color = preg_match('/^#[0-9a-fA-F]{6}$/',$_POST['color']??'') ? $_POST['color'] : 'rgba(255,255,255,.15)';
            $sort  = (int)($_POST['sort_order']??0);
            $valid_cats = ['artistic','cultural','social'];
            $cats = implode(',', array_intersect($_POST['categories'] ?? [], $valid_cats)) ?: 'artistic,cultural,social';
            if ($tname_ro) {
                if (!$tname_da) $tname_da = $tname_ro;
                if (!$tname_en) $tname_en = $tname_ro;
                $slug = strtolower(preg_replace('/[^a-z0-9]+/i','-',iconv('UTF-8','ASCII//TRANSLIT',$tname_ro)));
                $pdo->prepare('UPDATE tags SET name=?,name_ro=?,name_da=?,name_en=?,slug=?,color=?,sort_order=?,categories=? WHERE id=?')->execute([$tname_ro,$tname_ro,$tname_da,$tname_en,$slug,$color,$sort,$cats,$tid]);
                flash('ok','Tag actualizat.');
            }
            header('Location: /admin/settings.php?s=tags'); exit;
        }
    }

    header('Location: /admin/settings.php?s='.$section); exit;
}

// ── DATE ──────────────────────────────────────────────────────
// „Dezactivează" șterge acum contul definitiv (delete_panel_account), deci nu
// mai există conturi inactive de afișat — un cont există și e activ, sau nu
// există deloc. Filtrul de mai jos ascunde și eventuale rânduri inactive mai
// vechi, dinainte de schimbarea asta.
$users = $is_admin ? $pdo->query('SELECT * FROM bf_users WHERE active = 1 ORDER BY id ASC')->fetchAll() : [];
$tags  = $is_admin ? $pdo->query('SELECT * FROM tags ORDER BY sort_order ASC, name ASC')->fetchAll() : [];
$pos_colors = ['presedinte'=>'rgba(255,255,255,.15)','vicepresedinte'=>'rgba(255,255,255,.15)','trezorier'=>'rgba(255,255,255,.15)','consilier'=>'#2E5E4E'];

layout_head('Setări','settings');
?>
<div class="content">
  <?php if ($flash): ?>
    <div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div>
  <?php endif; ?>

  <div class="page-head"><h1><?= e(t('settings_title')) ?></h1></div>

  <!-- Sub-tabs -->
  <div style="display:flex;gap:4px;border-bottom:1px solid rgba(255,255,255,.05);margin-bottom:28px;flex-wrap:wrap">
    <?php
    $stabs = [
      'profile' => ['label'=>t('my_profile'),'access'=>'all'],
    ];
    if ($is_admin) $stabs += [
      'users'   => ['label'=>t('tab_users'),'access'=>'admin'],
      'tags'    => ['label'=>t('tab_tags'), 'access'=>'admin'],
    ];
    foreach ($stabs as $k=>$t_): ?>
    <a href="/admin/settings.php?s=<?= $k ?>"
       style="padding:11px 16px;font-size:13px;font-weight:600;color:<?= $section===$k?'#fff':'rgba(255,255,255,.45)' ?>;border-bottom:2px solid <?= $section===$k?'rgba(255,255,255,.15)':'transparent' ?>;transition:color .15s">
      <?= e($t_['label']) ?>
    </a>
    <?php endforeach; ?>
  </div>

  <?php if ($section === 'profile'): ?>
  <!-- ── PROFIL ── -->
  <div style="max-width:560px">
    <div class="form-section">
      <p class="section-label"><?= e(t('personal_data')) ?></p>
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
            <p style="font-size:13px;color:rgba(255,255,255,.65);margin-bottom:6px"><?= e(t('profile_photo')) ?></p>
            <input type="file" name="avatar" accept="image/jpeg,image/png,image/webp" style="font-size:13px;color:rgba(255,255,255,.65)">
            <p style="font-size:11px;color:rgba(255,255,255,.45);margin-top:4px"><?= e(t('photo_hint')) ?></p>
          </div>
        </div>
        <div class="field" style="margin-bottom:14px">
          <label><?= e(t('full_name')) ?></label>
          <input type="text" name="name" value="<?= e($user['name']) ?>" required>
        </div>
        <div class="field" style="margin-bottom:14px">
          <label><?= e(t('email')) ?></label>
          <input type="text" value="<?= e($user['email']) ?>" disabled style="opacity:.5">
          <span class="field-hint"><?= e(t('email_locked')) ?></span>
        </div>
        <div class="field" style="margin-bottom:14px">
          <label><?= e(t('position')) ?></label>
          <input type="text" value="<?= e(position_label($user)) ?>" disabled style="opacity:.5">
        </div>
        <div class="field" style="margin-bottom:18px">
          <label><?= e(t('ui_language')) ?></label>
          <select name="ui_lang">
            <?php foreach (UI_LANGS as $lc => $lname): ?>
              <option value="<?= e($lc) ?>" <?= ($user['ui_lang'] ?? 'ro') === $lc ? 'selected' : '' ?>><?= e($lname) ?></option>
            <?php endforeach; ?>
          </select>
          <span class="field-hint"><?= e(t('ui_language_hint')) ?></span>
        </div>
        <button class="btn btn-solid" type="submit"><?= e(t('save_profile')) ?></button>
      </form>
    </div>

    <div class="form-section">
      <p class="section-label"><?= e(t('change_password')) ?></p>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="change_password">
        <div class="field" style="margin-bottom:12px">
          <label><?= e(t('current_password')) ?></label>
          <input type="password" name="current_password" required autocomplete="current-password">
        </div>
        <div class="field" style="margin-bottom:12px">
          <label><?= e(t('new_password')) ?></label>
          <input type="password" name="new_password" required autocomplete="new-password">
          <span class="field-hint"><?= e(t('min_10_chars')) ?></span>
        </div>
        <div class="field" style="margin-bottom:18px">
          <label><?= e(t('confirm_new_password')) ?></label>
          <input type="password" name="confirm_password" required autocomplete="new-password">
        </div>
        <button class="btn btn-solid" type="submit"><?= e(t('change_password')) ?></button>
      </form>
    </div>
  </div>

  <?php elseif ($section === 'users' && $is_admin): ?>
  <!-- ── UTILIZATORI ── -->
  <div style="max-width:900px">
    <?php $active_count = count(array_filter($users, fn($u)=>$u['active'])); ?>
    <p style="font-size:13px;color:rgba(255,255,255,.45);margin-bottom:10px">Sloturi: <strong style="color:rgba(255,255,255,.6)"><?= $active_count ?> / <?= MAX_PANEL_ACCOUNTS ?></strong></p>
    <div style="display:flex;gap:14px;flex-wrap:wrap;margin-bottom:20px">
      <?php foreach (POSITIONS as $pk => $pdef):
        $occ = count_active_by_position($pdo, $pk);
        $max = $pk === 'consilier' ? MAX_CONSILIERI : (in_array($pk, EXCLUSIVE_POSITIONS, true) ? 1 : null);
        $full = $max !== null && $occ >= $max;
      ?>
        <span style="font-size:11px;color:<?= $full?'rgba(255,180,80,.9)':'rgba(255,255,255,.5)' ?>"><?= e($pdef['label']) ?>: <?= $occ ?><?= $max !== null ? '/'.$max : '' ?></span>
      <?php endforeach; ?>
    </div>

    <?php if ($active_count < MAX_PANEL_ACCOUNTS): ?>
    <div class="form-section" style="margin-bottom:24px">
      <p class="section-label">Adaugă membru</p>
      <p class="field-hint" style="margin-bottom:14px">
        Președinte, vicepreședinte, trezorier și revizor au un singur loc — dacă poziția e deja ocupată,
        contul care o ocupă acum va fi înlocuit automat (șters). Consilier are maximum <?= MAX_CONSILIERI ?> locuri;
        dacă sunt toate ocupate, dezactivează (șterge) sau schimbă poziția unui consilier existent înainte de a adăuga altul.
      </p>
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
        <div id="permMatrixWrap" style="display:none;background:#000;border:1px solid rgba(255,255,255,.08);padding:16px;margin-bottom:16px;max-width:520px">
          <p style="font-size:12px;font-weight:700;color:rgba(255,255,255,.65);text-transform:uppercase;letter-spacing:.08em;margin-bottom:12px">Acces — bifează ce poate face</p>
          <?php render_permission_matrix([], 'perm'); ?>
        </div>
        <div style="display:flex;align-items:center;gap:16px">
          <button class="btn btn-solid" type="submit">Adaugă</button>
          <span style="font-size:12px;color:rgba(255,255,255,.45)">Parola inițială e generată automat și afișată o singură dată, aici, după ce adaugi membrul.</span>
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
      <div style="background:#0a0a0a;border:1px solid rgba(255,255,255,.05);padding:16px 20px;display:flex;align-items:center;gap:14px;flex-wrap:wrap">
        <div style="width:40px;height:40px;border-radius:50%;background:<?= e($col) ?>;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:15px;color:#fff;overflow:hidden;flex-shrink:0">
          <?php if (!empty($u['avatar'])): ?><img src="/<?= e(ltrim($u['avatar'],'/')) ?>" style="width:100%;height:100%;object-fit:cover" alt=""><?php else: ?><?= e($init) ?><?php endif; ?>
        </div>
        <div style="flex:1;min-width:140px">
          <div style="font-size:15px;font-weight:700"><?= e($u['name']) ?><?php if($isMe): ?> <span style="font-size:11px;color:rgba(255,255,255,.6)">— tu</span><?php endif; ?><?php if($u['must_change_pwd']): ?> <span style="font-size:11px;color:#e65100">parolă nesetată</span><?php endif; ?></div>
          <div style="font-size:12px;color:rgba(255,255,255,.45)"><?= e($u['email']) ?></div>
          <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:4px">
            <span style="padding:2px 8px;font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;background:<?= e($col) ?>22;color:<?= e($col) ?>"><?= e(position_label($u)) ?></span>
            <?php if (!empty($u['member_id'])): ?>
              <a href="/admin/member-profile.php?id=<?= (int)$u['member_id'] ?>" style="font-size:11px;color:rgba(255,255,255,.6);border-bottom:1px solid rgba(255,255,255,.15)">profil membru →</a>
            <?php endif; ?>
          </div>
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
          <form method="post" style="display:inline" onsubmit="return confirm('Dezactivezi pe <?= e($u['name']) ?>? Contul din panou va fi șters definitiv — poate primi un rol nou oricând, dar va porni cu o parolă nouă.')">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="toggle_user">
            <input type="hidden" name="uid" value="<?= (int)$u['id'] ?>">
            <button class="btn btn-danger btn-xs" type="submit">Dezactivează</button>
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
            </div>
            <?php
              $is_editable_role = in_array($u['position'], ['consilier', 'revizor'], true);
              // Prebifare: ce are salvat contul; pentru un revizor fără nimic
              // salvat încă explicit, pornim de la setul implicit istoric, ca
              // adminul să vadă exact ce acces are acum, nu o matrice goală.
              $current_perms = user_permissions($u);
              if ($u['position'] === 'revizor' && empty($current_perms)) { $current_perms = REVIZOR_PERMISSIONS; }
            ?>
            <div id="epperm-<?= (int)$u['id'] ?>" style="<?= $is_editable_role?'':'display:none' ?>;background:#0a0a0a;border:1px solid rgba(255,255,255,.08);padding:16px;margin-top:12px;max-width:520px">
              <p style="font-size:12px;font-weight:700;color:rgba(255,255,255,.65);text-transform:uppercase;letter-spacing:.08em;margin-bottom:12px">Acces — bifează ce poate face</p>
              <?php render_permission_matrix($current_perms, 'perm', 'perm-edit-' . (int)$u['id']); ?>
            </div>
            <div style="display:flex;gap:10px;margin-top:12px">
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
          <div class="field" style="flex:1;min-width:140px"><label>Nume (RO) *</label><input type="text" name="tname_ro" required placeholder="ex: Muzică"></div>
          <div class="field" style="flex:1;min-width:140px"><label>Navn (DA)</label><input type="text" name="tname_da" placeholder="ex: Musik"></div>
          <div class="field" style="flex:1;min-width:140px"><label>Name (EN)</label><input type="text" name="tname_en" placeholder="ex: Music"></div>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:12px">
          <div class="field"><label>Culoare</label><input type="color" name="color" value="rgba(255,255,255,.15)" style="width:48px;height:38px;padding:2px;background:#000;border:1.5px solid rgba(255,255,255,.1);cursor:pointer"></div>
          <div class="field" style="width:80px"><label>Ordine</label><input type="number" name="sort_order" value="0" min="0"></div>
        </div>
        <div class="field" style="margin-bottom:14px">
          <label style="font-size:12px;font-weight:600;color:rgba(255,255,255,.65);margin-bottom:6px;display:block">Apare în secțiunile</label>
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
            <div style="font-size:14px;font-weight:600"><?= e($tag['name_ro'] ?: $tag['name']) ?></div>
            <div style="font-size:11px;color:rgba(255,255,255,.45);margin-top:2px">DA: <?= e($tag['name_da'] ?: $tag['name']) ?> · EN: <?= e($tag['name_en'] ?: $tag['name']) ?></div>
            <div style="font-size:11px;color:rgba(255,255,255,.45);margin-top:2px">#<?= e($tag['slug']) ?> · ord:<?= (int)$tag['sort_order'] ?></div>
            <div style="display:flex;gap:4px;margin-top:5px;flex-wrap:wrap">
              <?php foreach ($tag_cats as $tc): ?>
                <span style="display:inline-block;padding:2px 7px;font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;background:<?= $cat_colors_t[$tc] ?? 'rgba(255,255,255,.15)' ?>22;color:<?= $cat_colors_t[$tc] ?? 'rgba(255,255,255,.15)' ?>"><?= e($cat_labels_t[$tc] ?? $tc) ?></span>
              <?php endforeach; ?>
            </div>
          </div>
          <div style="display:flex;gap:6px;flex-shrink:0">
            <button class="btn btn-ghost btn-xs" onclick="toggleEditTag(<?= (int)$tag['id'] ?>)">Edit</button>
            <form method="post" style="display:inline" onsubmit="return confirm('Ștergi tagul «<?= e($tag['name_ro'] ?: $tag['name']) ?>»?')">
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
              <div class="field" style="flex:1;min-width:120px"><label>Nume (RO)</label><input type="text" name="tname_ro" value="<?= e($tag['name_ro'] ?: $tag['name']) ?>" required></div>
              <div class="field" style="flex:1;min-width:120px"><label>Navn (DA)</label><input type="text" name="tname_da" value="<?= e($tag['name_da'] ?: $tag['name']) ?>"></div>
              <div class="field" style="flex:1;min-width:120px"><label>Name (EN)</label><input type="text" name="tname_en" value="<?= e($tag['name_en'] ?: $tag['name']) ?>"></div>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-bottom:10px">
              <div class="field"><label>Culoare</label><input type="color" name="color" value="<?= e($tag['color']) ?>" style="width:44px;height:36px;padding:2px;background:#000;border:1.5px solid rgba(255,255,255,.1)"></div>
              <div class="field" style="width:70px"><label>Ord.</label><input type="number" name="sort_order" value="<?= (int)$tag['sort_order'] ?>"></div>
            </div>
            <div style="margin-bottom:10px">
              <div style="font-size:12px;font-weight:600;color:rgba(255,255,255,.65);margin-bottom:6px">Secțiuni</div>
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
      <?php if (empty($tags)): ?><p style="color:rgba(255,255,255,.45);font-size:14px">Niciun tag creat.</p><?php endif; ?>
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
function togglePLabel(){
  var val = document.getElementById('posSelect').value;
  var isCons = val==='consilier';
  var isEditableRole = isCons || val==='revizor';
  document.getElementById('pLabelWrap').style.display = isCons?'block':'none';
  document.getElementById('permMatrixWrap').style.display = isEditableRole?'block':'none';
}
function toggleEditPLabel(id,sel){
  var isCons = sel.value==='consilier';
  var isEditableRole = isCons || sel.value==='revizor';
  document.getElementById('eplbl-'+id).style.display = isCons?'block':'none';
  document.getElementById('epperm-'+id).style.display = isEditableRole?'block':'none';
}
</script>
<?php layout_foot(); ?>
