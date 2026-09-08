<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mailer.php';
$user     = require_perm('members', 'view');
$can_edit = has_perm($user, 'members', 'edit');
$is_admin = $user['role'] === 'admin';
$pdo      = get_db();
ensure_member_schema($pdo);
ensure_user_permissions_column($pdo);
ensure_user_member_link_column($pdo);
ensure_user_avatar_column($pdo);
ensure_membership_lang_column($pdo);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) { header('Location: /admin/members.php'); exit; }

$stmt = $pdo->prepare('SELECT * FROM membership_requests WHERE id=?');
$stmt->execute([$id]);
$member = $stmt->fetch();
if (!$member) { flash('error', t('member_not_found')); header('Location: /admin/members.php'); exit; }

$statuses = [
    'new'       => t('status_new'),
    'contacted' => t('status_contacted'),
    'active'    => t('status_active'),
    'pending'   => t('status_pending'),
    'declined'  => t('status_declined'),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (!$can_edit) {
        http_response_code(403);
        die(t('no_edit_profile_perm'));
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $name    = trim($_POST['name']  ?? '');
        $email   = trim($_POST['email'] ?? '');
        $phone   = trim($_POST['phone'] ?? '');
        $city    = trim($_POST['city']  ?? '');
        $status  = $_POST['status'] ?? $member['status'];
        $lang    = array_key_exists($_POST['lang'] ?? '', UI_LANGS) ? $_POST['lang'] : 'da';
        $errors  = [];
        if (!$name)                                     $errors[] = t('name_required');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL))  $errors[] = t('invalid_email');
        if (!array_key_exists($status, $statuses))       $status = $member['status'];

        // Emailul de asociație (opțional). Dacă e completat, devine automat
        // adresa principală — pentru orice comunicare (welcome, resetare
        // parolă, emailuri din members.php) și pentru login în panoul admin,
        // dacă membrul are deja un cont. Nu e o alegere manuală.
        $email_secondary = trim($_POST['email_secondary'] ?? '');
        if ($email_secondary && !filter_var($email_secondary, FILTER_VALIDATE_EMAIL)) {
            $errors[] = t('invalid_secondary_email');
        }
        $login_email_source = $email_secondary ? 'secondary' : 'primary';

        // Fotografie de profil — opțională, aceeași regulă de validare ca
        // avatarul din Setări → Profil meu.
        $avatar = handle_avatar_upload('member-avatar', $id, $member['avatar'] ?? null);

        $member_number = trim($_POST['member_number'] ?? '') ?: null;
        $joined_date   = $_POST['joined_date'] ?: null;

        $dues_paid       = isset($_POST['dues_paid']) ? 1 : 0;
        $dues_paid_date  = $_POST['dues_paid_date']    ?: null;
        $dues_valid_until= $_POST['dues_valid_until']  ?: null;
        $dues_amount     = $_POST['dues_amount'] !== '' ? (float)$_POST['dues_amount'] : null;
        $dues_method     = array_key_exists($_POST['dues_method'] ?? '', DUES_METHODS) ? $_POST['dues_method'] : null;

        // Dacă a plătit și nu s-a dat o dată de valabilitate, presupunem 1 an de la plată.
        if ($dues_paid && $dues_paid_date && !$dues_valid_until) {
            $dues_valid_until = date('Y-m-d', strtotime($dues_paid_date . ' +1 year'));
        }

        $exempt        = isset($_POST['exempt']) ? 1 : 0;
        $exempt_reason = $exempt ? (trim($_POST['exempt_reason'] ?? '') ?: null) : null;

        $is_volunteer  = isset($_POST['is_volunteer']) ? 1 : 0;

        $relation_type = array_key_exists($_POST['relation_type'] ?? '', RELATION_TYPES) ? $_POST['relation_type'] : '';
        $related_member_id = (int)($_POST['related_member_id'] ?? 0);
        if (!$relation_type || !$related_member_id || $related_member_id === $id) {
            $relation_type = '';
            $related_member_id = null;
        } else {
            // Membrul relaționat trebuie să existe cu adevărat.
            $chk = $pdo->prepare('SELECT 1 FROM membership_requests WHERE id=?');
            $chk->execute([$related_member_id]);
            if (!$chk->fetch()) { $relation_type = ''; $related_member_id = null; }
        }

        // Date pentru raportarea către comună (folkeoplysningsloven — medlemsliste
        // cu navn, adresă, fødselsdato; tilskud suplimentar pentru handicap).
        $birth_date   = $_POST['birth_date'] ?: null;
        if ($birth_date && $birth_date > date('Y-m-d')) $birth_date = null; // fără date de naștere din viitor
        $gender       = array_key_exists($_POST['gender'] ?? '', GENDERS) ? $_POST['gender'] : null;
        $address      = trim($_POST['address'] ?? '') ?: null;
        $postal_code  = trim($_POST['postal_code'] ?? '') ?: null;
        $special_needs      = isset($_POST['special_needs']) ? 1 : 0;
        $special_needs_note = $special_needs ? (trim($_POST['special_needs_note'] ?? '') ?: null) : null;

        if ($errors) {
            flash('error', implode(' ', $errors));
        } else {
            $pdo->prepare('UPDATE membership_requests SET
                name=?, email=?, phone=?, city=?, status=?, lang=?,
                member_number=?, joined_date=?,
                dues_paid=?, dues_paid_date=?, dues_valid_until=?, dues_amount=?, dues_method=?,
                exempt=?, exempt_reason=?,
                is_volunteer=?,
                relation_type=?, related_member_id=?,
                birth_date=?, gender=?, address=?, postal_code=?, special_needs=?, special_needs_note=?,
                email_secondary=?, login_email_source=?, avatar=?
                WHERE id=?')
                ->execute([
                    $name, $email, $phone ?: null, $city ?: null, $status, $lang,
                    $member_number, $joined_date,
                    $dues_paid, $dues_paid_date, $dues_valid_until, $dues_amount, $dues_method,
                    $exempt, $exempt_reason,
                    $is_volunteer,
                    $relation_type ?: null, $related_member_id,
                    $birth_date, $gender, $address, $postal_code, $special_needs, $special_needs_note,
                    $email_secondary ?: null, $login_email_source, $avatar,
                    $id,
                ]);

            $msg = t('profile_updated');

            // Dacă profilul are deja un cont conectat în panoul admin, ținem
            // emailul lui de login sincronizat automat cu regula de mai sus
            // (email de asociație dacă există, altfel cel personal) — fără
            // să atingem parola.
            $desired_login = $email_secondary ?: $email;
            $linked = $pdo->prepare('SELECT * FROM bf_users WHERE member_id = ?');
            $linked->execute([$id]);
            $linked = $linked->fetch();
            if ($linked && $linked['email'] !== $desired_login) {
                $conflict = $pdo->prepare('SELECT id FROM bf_users WHERE email = ? AND id <> ?');
                $conflict->execute([$desired_login, $linked['id']]);
                if ($conflict->fetch()) {
                    $msg .= t('login_email_sync_failed') . $desired_login . '".';
                } else {
                    $pdo->prepare('UPDATE bf_users SET email=? WHERE id=?')->execute([$desired_login, $linked['id']]);
                    $msg .= t('login_email_synced') . $desired_login . '.';
                }
            }

            flash('ok', $msg);
        }
        header('Location: /admin/member-profile.php?id=' . $id); exit;
    }

    if ($action === 'add_contribution') {
        $project_id = (int)($_POST['project_id'] ?? 0);
        $cstatus    = ($_POST['contribution_status'] ?? '') === 'finalizat' ? 'finalizat' : 'activ';
        if ($project_id) {
            $pdo->prepare('INSERT IGNORE INTO member_projects (member_id, project_id, status) VALUES (?,?,?)')
                ->execute([$id, $project_id, $cstatus]);
            flash('ok', t('contribution_added'));
        }
        header('Location: /admin/member-profile.php?id=' . $id); exit;
    }

    if ($action === 'update_contribution') {
        $cid     = (int)($_POST['contribution_id'] ?? 0);
        $cstatus = ($_POST['contribution_status'] ?? '') === 'finalizat' ? 'finalizat' : 'activ';
        $pdo->prepare('UPDATE member_projects SET status=? WHERE id=? AND member_id=?')->execute([$cstatus, $cid, $id]);
        flash('ok', t('contribution_status_updated'));
        header('Location: /admin/member-profile.php?id=' . $id); exit;
    }

    if ($action === 'remove_contribution') {
        $cid = (int)($_POST['contribution_id'] ?? 0);
        $pdo->prepare('DELETE FROM member_projects WHERE id=? AND member_id=?')->execute([$cid, $id]);
        flash('ok', t('contribution_removed'));
        header('Location: /admin/member-profile.php?id=' . $id); exit;
    }

    // Desemnează membrul într-un rol în panoul admin — președinte,
    // vicepreședinte, trezorier, revizor sau consilier (cu permisiuni
    // granulare, bifate doar pentru consilier). Doar adminul poate face
    // asta. (opțional) trimite un email de welcome cu datele de prim login.
    if ($action === 'grant_panel_account' && $is_admin) {
        $position = trim($_POST['position'] ?? '');
        if (!array_key_exists($position, POSITIONS)) {
            flash('error', t('invalid_position'));
            header('Location: /admin/member-profile.php?id=' . $id); exit;
        }
        $cap_error = position_capacity_error($pdo, $position);
        if ($cap_error) {
            flash('error', $cap_error);
            header('Location: /admin/member-profile.php?id=' . $id); exit;
        }

        // Emailul de login — personal, sau de asociație dacă membrul are
        // unul completat în profil (devine automat principal).
        $login_email = member_primary_email($member);

        $exists = $pdo->prepare('SELECT id FROM bf_users WHERE email = ?');
        $exists->execute([$login_email]);
        if ($exists->fetch()) {
            flash('error', t('account_exists_for_email'));
            header('Location: /admin/member-profile.php?id=' . $id); exit;
        }

        // Poziții exclusive (bestyrelsen + revizor) — dacă poziția e deja
        // ocupată de un cont activ, îl scoatem definitiv din panou la
        // reasignare (delete_panel_account), ca să nu rămână doi
        // „președinți" simultan în listă și profilul lui vechi să
        // redevină liber pentru o desemnare nouă.
        $bumped = find_active_position_holder($pdo, $position);
        if ($bumped) {
            delete_panel_account($pdo, $bumped['id']);
        }

        $count = (int)$pdo->query('SELECT COUNT(*) FROM bf_users WHERE active=1')->fetchColumn();
        if ($count >= MAX_PANEL_ACCOUNTS) {
            flash('error', t('panel_limit_reached') . MAX_PANEL_ACCOUNTS . t('panel_limit_reached_suffix'));
            header('Location: /admin/member-profile.php?id=' . $id); exit;
        }
        $role       = POSITIONS[$position]['role'];
        $plabel     = $position === 'consilier' ? trim($_POST['position_label'] ?? '') : null;
        // Accesul granular (bifele) se aplică acum și lui revizor, nu doar
        // consilierului — vezi CONSILIER_PERMISSION_SCHEMA / has_perm().
        $perms_json = in_array($role, ['member', 'revizor'], true) ? build_permissions_json($_POST['perm'] ?? []) : null;
        $temp_pwd   = gen_temp_password();
        $hash       = password_hash($temp_pwd, PASSWORD_BCRYPT, ['cost' => 12]);

        // Poza de profil — dacă membrul are deja una pe profil, o preluăm și
        // pentru noul cont din panou (aceeași idee ca la link_existing_account,
        // în sens invers). Copiem fișierul, nu doar calea — vezi motivul acolo.
        $account_avatar = null;
        if (!empty($member['avatar'])) {
            $src_path = dirname(__DIR__) . '/' . ltrim($member['avatar'], '/');
            if (is_file($src_path)) {
                $ext   = strtolower(pathinfo($src_path, PATHINFO_EXTENSION)) ?: 'jpg';
                $fname = 'avatar-' . $id . '-' . time() . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
                $dest  = dirname(__DIR__) . '/assets/avatars/' . $fname;
                if (@copy($src_path, $dest)) { $account_avatar = 'assets/avatars/' . $fname; }
            }
        }

        try {
            $pdo->prepare('INSERT INTO bf_users (name,email,password,role,position,position_label,permissions,member_id,avatar,must_change_pwd,active) VALUES (?,?,?,?,?,?,?,?,?,1,1)')
                ->execute([$member['name'], $login_email, $hash, $role, $position, $plabel, $perms_json, $id, $account_avatar]);
        } catch (PDOException $e) {
            flash('error', t('could_not_create_account'));
            header('Location: /admin/member-profile.php?id=' . $id); exit;
        }

        $msg = t('account_created_prefix') . POSITIONS[$position]['label'] . t('login_label_paren') . $login_email . t('initial_password_label') . $temp_pwd;
        if ($bumped) { $msg .= ' ' . $bumped['name'] . t('replaced_auto_prefix'); }
        if (!empty($_POST['send_welcome'])) {
            $body = "Bună, {$member['name']},\n\n"
                . "Ai fost desemnat/ă " . POSITIONS[$position]['label'] . " în panoul de administrare Foreningen Front Door.\n\n"
                . "Login: https://foreningenfrontdoor.dk/admin/\n"
                . "Email: {$login_email}\n"
                . "Parolă inițială: {$temp_pwd}\n\n"
                . "La primul login ți se va cere să îți setezi o parolă nouă.\n\n"
                . "Cu drag,\nForeningen Front Door";
            $result = send_smtp_mail($login_email, $member['name'], 'Foreningen Front Door — Acces panou admin', $body, false);
            $msg .= $result === true ? t('welcome_email_sent') : (t('email_send_error_prefix') . $result);
        }
        flash('ok', $msg);
        header('Location: /admin/member-profile.php?id=' . $id); exit;
    }

    // Conectează la profilul curent un cont din panou care există deja, dar
    // nu e legat de niciun profil — util când cineva a fost adăugat manual
    // din Setări → Utilizatori (cu email profesional + parolă deja setată)
    // înainte să existe profilul de membru, sau când o desemnare anterioară
    // a creat un cont duplicat din greșeală. Nu atinge parola contului
    // adoptat — exact pentru cazul în care admin nu vrea să-și schimbe
    // parola deja existentă.
    if ($action === 'link_existing_account' && $is_admin) {
        $existing_id = (int)($_POST['existing_user_id'] ?? 0);
        $existing = $pdo->prepare('SELECT * FROM bf_users WHERE id = ?');
        $existing->execute([$existing_id]);
        $existing = $existing->fetch();
        if (!$existing || ($existing['member_id'] !== null && (int)$existing['member_id'] !== $id)) {
            flash('error', t('invalid_or_linked_account'));
            header('Location: /admin/member-profile.php?id=' . $id); exit;
        }

        // Dacă profilul are deja alt cont conectat (ex. duplicatul creat de
        // o desemnare anterioară), îl păstrăm sau îl ștergem, după alegerea
        // adminului — fără să atingem contul nou-adoptat.
        $dup = $pdo->prepare('SELECT * FROM bf_users WHERE member_id = ? AND id <> ?');
        $dup->execute([$id, $existing_id]);
        $dup = $dup->fetch();
        if ($dup && !empty($_POST['delete_duplicate'])) {
            $pdo->prepare('DELETE FROM bf_users WHERE id = ?')->execute([$dup['id']]);
        }

        $pdo->prepare('UPDATE bf_users SET member_id = ? WHERE id = ?')->execute([$id, $existing_id]);

        // Ține profilul consistent: dacă emailul contului adoptat diferă de
        // cel personal, îl reținem ca email profesional + preferință de login.
        if ($existing['email'] !== $member['email']) {
            $pdo->prepare('UPDATE membership_requests SET email_secondary=?, login_email_source=? WHERE id=?')
                ->execute([$existing['email'], 'secondary', $id]);
        }

        $msg = t('account_linked_prefix') . $existing['email'] . t('account_linked_suffix');
        if ($dup && !empty($_POST['delete_duplicate'])) { $msg .= t('duplicate_account_deleted_prefix') . $dup['email'] . t('duplicate_account_deleted_suffix'); }

        // Poza de profil — dacă adminul are deja o poză încărcată din Setări →
        // Utilizatori și profilul de membru nu are încă una, o preluăm de
        // aici la conectare. Copiem fișierul (nu doar calea) ca fiecare parte
        // să aibă propriul fișier — altfel un upload nou pe oricare parte
        // (handle_avatar_upload / update_profile din settings.php) ar șterge
        // fișierul folosit și de cealaltă.
        if (empty($member['avatar']) && !empty($existing['avatar'])) {
            $src_path = dirname(__DIR__) . '/' . ltrim($existing['avatar'], '/');
            if (is_file($src_path)) {
                $ext   = strtolower(pathinfo($src_path, PATHINFO_EXTENSION)) ?: 'jpg';
                $fname = 'member-avatar-' . $id . '-' . time() . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
                $dest  = dirname(__DIR__) . '/assets/avatars/' . $fname;
                if (@copy($src_path, $dest)) {
                    $pdo->prepare('UPDATE membership_requests SET avatar=? WHERE id=?')->execute(['assets/avatars/' . $fname, $id]);
                    $msg .= t('avatar_pulled_from_account');
                }
            }
        }

        // Dacă era dezactivat, îl reactivăm — cu aceleași verificări ca la
        // reactivarea din Setări → Utilizatori (plafon total + poziție
        // exclusivă deja ocupată).
        if (!$existing['active']) {
            $cnt = (int)$pdo->query('SELECT COUNT(*) FROM bf_users WHERE active=1')->fetchColumn();
            $cap_error = position_capacity_error($pdo, $existing['position'], $existing_id);
            $reactivate_bumped = find_active_position_holder($pdo, $existing['position'], $existing_id);
            if ($cnt >= MAX_PANEL_ACCOUNTS) {
                $msg .= t('could_not_reactivate_limit') . MAX_PANEL_ACCOUNTS . t('accounts_reached_paren');
            } elseif ($cap_error) {
                $msg .= t('could_not_reactivate_reason') . $cap_error . ')';
            } else {
                if ($reactivate_bumped) { delete_panel_account($pdo, $reactivate_bumped['id']); }
                $pdo->prepare('UPDATE bf_users SET active=1 WHERE id=?')->execute([$existing_id]);
                $msg .= t('account_reactivated');
                if ($reactivate_bumped) { $msg .= ' ' . $reactivate_bumped['name'] . t('replaced_auto_prefix'); }
            }
        }

        flash('ok', $msg);
        header('Location: /admin/member-profile.php?id=' . $id); exit;
    }

    // Resetează parola unui consilier deja creat din profilul de membru și
    // (opțional) retrimite emailul de welcome cu noile date.
    if ($action === 'resend_welcome' && $is_admin) {
        $acct = $pdo->prepare('SELECT * FROM bf_users WHERE member_id = ? OR email IN (?, ?) LIMIT 1');
        $acct->execute([$id, $member['email'], $member['email_secondary'] ?? '']);
        $acct = $acct->fetch();
        if (!$acct) {
            flash('error', t('no_panel_account'));
            header('Location: /admin/member-profile.php?id=' . $id); exit;
        }
        $temp_pwd = gen_temp_password();
        $hash     = password_hash($temp_pwd, PASSWORD_BCRYPT, ['cost' => 12]);
        $pdo->prepare('UPDATE bf_users SET password=?, must_change_pwd=1 WHERE id=?')->execute([$hash, $acct['id']]);
        $body = "Bună, {$member['name']},\n\n"
            . "Datele tale de acces la panoul de administrare Foreningen Front Door au fost resetate.\n\n"
            . "Login: https://foreningenfrontdoor.dk/admin/\n"
            . "Email: {$acct['email']}\n"
            . "Parolă nouă: {$temp_pwd}\n\n"
            . "La primul login ți se va cere să îți setezi o parolă nouă.\n\n"
            . "Cu drag,\nForeningen Front Door";
        $result = send_smtp_mail($acct['email'], $member['name'], 'Foreningen Front Door — Acces panou admin', $body, false);
        $msg = t('password_reset_to') . $temp_pwd;
        $msg .= $result === true ? t('email_resent') : (t('email_send_error_prefix') . $result);
        flash('ok', $msg);
        header('Location: /admin/member-profile.php?id=' . $id); exit;
    }

    header('Location: /admin/member-profile.php?id=' . $id); exit;
}

$flash = get_flash();

// Refresh (fișierul poate fi reîncărcat după update)
$stmt = $pdo->prepare('SELECT * FROM membership_requests WHERE id=?');
$stmt->execute([$id]);
$member = $stmt->fetch();

// Are deja cont în panoul admin (creat de aici sau din Setări → Utilizatori)?
// Legătura reală e member_id; păstrăm și potrivirea după email (personal
// sau profesional) pentru conturile mai vechi, create înainte de coloană.
$admin_account = null;
if ($is_admin) {
    $aa = $pdo->prepare('SELECT * FROM bf_users WHERE member_id = ? OR email = ? OR (? <> "" AND email = ?) LIMIT 1');
    $aa->execute([$id, $member['email'], $member['email_secondary'] ?? '', $member['email_secondary'] ?? '']);
    $admin_account = $aa->fetch() ?: null;
}

// Conturi din panou care nu sunt legate de niciun profil de membru — pentru
// „Conectează un cont existent" (ex. cineva adăugat manual din Setări cu
// email profesional + parolă deja setată, înainte să existe profilul).
$unlinked_accounts = [];
if ($is_admin) {
    $ua_sql = 'SELECT * FROM bf_users WHERE member_id IS NULL';
    $ua_params = [];
    if ($admin_account) { $ua_sql .= ' AND id <> ?'; $ua_params[] = $admin_account['id']; }
    $ua = $pdo->prepare($ua_sql . ' ORDER BY active DESC, name ASC');
    $ua->execute($ua_params);
    $unlinked_accounts = $ua->fetchAll();
}

// Alți membri (pentru selectorul de relație) — excludem membrul curent.
$others = $pdo->prepare('SELECT id, name FROM membership_requests WHERE id<>? ORDER BY name ASC');
$others->execute([$id]);
$others = $others->fetchAll();

// Cine altcineva se declară în relație cu acest membru (invers).
$reverse = $pdo->prepare("SELECT id, name, relation_type FROM membership_requests WHERE related_member_id=?");
$reverse->execute([$id]);
$reverse = $reverse->fetchAll();

// Toate proiectele (pentru adăugarea unei contribuții).
$all_projects = $pdo->query('SELECT id, title_ro FROM projects ORDER BY sort_order ASC, id ASC')->fetchAll();

// Contribuțiile curente ale acestui membru.
$contrib = $pdo->prepare(
    'SELECT mp.id, mp.status, mp.project_id, p.title_ro, p.status AS project_status
     FROM member_projects mp
     JOIN projects p ON p.id = mp.project_id
     WHERE mp.member_id = ?
     ORDER BY p.title_ro ASC'
);
$contrib->execute([$id]);
$contrib = $contrib->fetchAll();
$contrib_ids = array_column($contrib, 'project_id');

layout_head(t('member_profile_title'), 'members');
?>
<div class="content" style="max-width:900px">
  <?php if ($flash): ?><div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>

  <div style="margin-bottom:16px">
    <a href="/admin/members.php" style="font-size:13px;color:rgba(255,255,255,.45)"><?= e(t('back_to_requests')) ?></a>
  </div>

  <div class="page-head">
    <h1>
      <?php if (!empty($member['avatar'])): ?>
        <img src="/<?= e(ltrim($member['avatar'],'/')) ?>" style="width:28px;height:28px;border-radius:50%;object-fit:cover;vertical-align:middle;margin-right:6px" alt="">
      <?php endif; ?>
      <?= e($member['name']) ?>
    </h1>
  </div>

  <?php if (!$can_edit): ?>
    <div class="flash flash-error"><?= e(t('view_only_profile')) ?></div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data" <?= $can_edit ? '' : 'style="pointer-events:none;opacity:.65"' ?>>
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="update_profile">

    <div class="form-section">
      <p class="section-label"><?= e(t('profile_photo_section')) ?></p>
      <div style="display:flex;align-items:center;gap:20px">
        <div style="width:72px;height:72px;border-radius:50%;background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;font-size:26px;font-weight:700;color:#fff;overflow:hidden;flex-shrink:0">
          <?php if (!empty($member['avatar'])): ?>
            <img src="/<?= e(ltrim($member['avatar'],'/')) ?>" style="width:100%;height:100%;object-fit:cover" alt="">
          <?php else: ?>
            <?= e(mb_substr($member['name'],0,1)) ?>
          <?php endif; ?>
        </div>
        <div>
          <input type="file" name="avatar" accept="image/jpeg,image/png,image/webp" style="font-size:13px;color:rgba(255,255,255,.65)">
          <p style="font-size:11px;color:rgba(255,255,255,.45);margin-top:4px"><?= e(t('photo_hint')) ?></p>
        </div>
      </div>
    </div>

    <div class="form-section">
      <p class="section-label"><?= e(t('contact_data_section')) ?></p>
      <div class="grid-2" style="margin-bottom:14px">
        <div class="field"><label><?= e(t('name_field')) ?> *</label><input type="text" name="name" value="<?= e($member['name']) ?>" required></div>
        <div class="field"><label><?= e(t('personal_email_field')) ?> *</label><input type="email" name="email" value="<?= e($member['email']) ?>" required></div>
      </div>
      <div class="field" style="margin-bottom:14px">
        <label><?= e(t('association_email_field')) ?></label>
        <input type="email" name="email_secondary" value="<?= e($member['email_secondary'] ?? '') ?>" placeholder="prenume@foreningenfrontdoor.dk">
        <span class="field-hint"><?= e(t('association_email_hint')) ?></span>
      </div>
      <div class="grid-3">
        <div class="field"><label><?= e(t('phone_field')) ?></label><input type="text" name="phone" value="<?= e($member['phone'] ?? '') ?>"></div>
        <div class="field"><label><?= e(t('city_field')) ?></label><input type="text" name="city" value="<?= e($member['city'] ?? '') ?>"></div>
        <div class="field">
          <label><?= e(t('request_status_field')) ?></label>
          <select name="status">
            <?php foreach ($statuses as $k => $lbl): ?>
              <option value="<?= e($k) ?>" <?= $member['status']===$k?'selected':'' ?>><?= e($lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="field" style="margin-top:14px;max-width:260px">
        <label><?= e(t('member_lang_field')) ?></label>
        <select name="lang">
          <?php foreach (UI_LANGS as $lc => $lname): ?>
            <option value="<?= e($lc) ?>" <?= ($member['lang'] ?? 'da')===$lc?'selected':'' ?>><?= e($lname) ?></option>
          <?php endforeach; ?>
        </select>
        <span class="field-hint"><?= e(t('member_lang_hint')) ?></span>
      </div>
    </div>

    <div class="form-section">
      <p class="section-label"><?= e(t('member_section')) ?></p>
      <div class="grid-2">
        <div class="field"><label><?= e(t('member_number_field')) ?></label><input type="text" name="member_number" value="<?= e($member['member_number'] ?? '') ?>" placeholder="ex: FD-014"></div>
        <div class="field"><label><?= e(t('joined_date_field')) ?></label><input type="date" name="joined_date" value="<?= e($member['joined_date'] ?? '') ?>"></div>
      </div>
    </div>

    <div class="form-section">
      <p class="section-label"><?= e(t('kommune_report_section')) ?></p>
      <p class="field-hint" style="margin-bottom:14px">
        <?= e(t('kommune_report_hint')) ?>
      </p>
      <div class="grid-3" style="margin-bottom:14px">
        <div class="field">
          <label><?= e(t('birth_date_field')) ?></label>
          <input type="date" name="birth_date" value="<?= e($member['birth_date'] ?? '') ?>" max="<?= date('Y-m-d') ?>">
          <?php $age = age_on_dec31($member['birth_date'] ?? null); ?>
          <?php if ($age !== null): ?><span class="field-hint"><?= $age ?><?= e(t('age_at_dec31')) ?><?= date('Y') ?></span><?php endif; ?>
        </div>
        <div class="field">
          <label><?= e(t('gender_field')) ?></label>
          <select name="gender">
            <option value="">—</option>
            <?php foreach (GENDERS as $k => $lbl): ?>
              <option value="<?= e($k) ?>" <?= ($member['gender']??'')===$k?'selected':'' ?>><?= e($lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field"><label><?= e(t('postal_code_field')) ?></label><input type="text" name="postal_code" value="<?= e($member['postal_code'] ?? '') ?>" placeholder="2500"></div>
      </div>
      <div class="field" style="margin-bottom:14px">
        <label><?= e(t('address_field')) ?></label>
        <input type="text" name="address" value="<?= e($member['address'] ?? '') ?>" placeholder="ex: Valby Langgade 1">
        <span class="field-hint"><?= e(t('address_hint')) ?></span>
      </div>
      <label class="check-row" style="margin-bottom:10px">
        <input type="checkbox" name="special_needs" id="specialNeedsCb" value="1" <?= !empty($member['special_needs'])?'checked':'' ?> onchange="document.getElementById('specialNeedsNoteWrap').style.display=this.checked?'block':'none'">
        <?= e(t('special_needs_checkbox')) ?>
      </label>
      <div class="field" id="specialNeedsNoteWrap" style="<?= !empty($member['special_needs'])?'':'display:none' ?>">
        <label><?= e(t('note_field')) ?></label>
        <input type="text" name="special_needs_note" value="<?= e($member['special_needs_note'] ?? '') ?>" placeholder="ex: necesită asistent pentru participare">
        <span class="field-hint"><?= e(t('special_needs_note_hint')) ?></span>
      </div>
    </div>

    <div class="form-section">
      <p class="section-label"><?= e(t('dues_section')) ?></p>
      <label class="check-row" style="margin-bottom:14px">
        <input type="checkbox" name="dues_paid" value="1" <?= !empty($member['dues_paid'])?'checked':'' ?>>
        <?= e(t('dues_paid_checkbox')) ?>
      </label>
      <div class="grid-3" style="margin-bottom:14px">
        <div class="field"><label><?= e(t('payment_date_field')) ?></label><input type="date" name="dues_paid_date" value="<?= e($member['dues_paid_date'] ?? '') ?>"></div>
        <div class="field">
          <label><?= e(t('valid_until_field')) ?></label>
          <input type="date" name="dues_valid_until" value="<?= e($member['dues_valid_until'] ?? '') ?>">
          <span class="field-hint"><?= e(t('valid_until_hint')) ?></span>
        </div>
        <div class="field"><label><?= e(t('amount_paid_field')) ?></label><input type="number" step="0.01" min="0" name="dues_amount" value="<?= e($member['dues_amount'] !== null ? (string)$member['dues_amount'] : '') ?>" placeholder="260"></div>
      </div>
      <div class="field" style="max-width:260px;margin-bottom:18px">
        <label><?= e(t('payment_method_field')) ?></label>
        <select name="dues_method">
          <option value="">—</option>
          <?php foreach (DUES_METHODS as $k => $lbl): ?>
            <option value="<?= e($k) ?>" <?= ($member['dues_method']??'')===$k?'selected':'' ?>><?= e($lbl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <label class="check-row" style="margin-bottom:10px">
        <input type="checkbox" name="exempt" id="exemptCb" value="1" <?= !empty($member['exempt'])?'checked':'' ?> onchange="document.getElementById('exemptReasonWrap').style.display=this.checked?'block':'none'">
        <?= e(t('exempt_checkbox')) ?>
      </label>
      <div class="field" id="exemptReasonWrap" style="<?= !empty($member['exempt'])?'':'display:none' ?>">
        <label><?= e(t('exempt_reason_field')) ?></label>
        <input type="text" name="exempt_reason" value="<?= e($member['exempt_reason'] ?? '') ?>" placeholder="<?= e(t('exempt_reason_ph')) ?>">
      </div>
    </div>

    <div class="form-section">
      <p class="section-label"><?= e(t('relations_section')) ?></p>
      <div class="grid-2" style="margin-bottom:10px">
        <div class="field">
          <label><?= e(t('relation_field')) ?></label>
          <select name="relation_type" id="relTypeSel">
            <?php foreach (RELATION_TYPES as $k => $lbl): ?>
              <option value="<?= e($k) ?>" <?= ($member['relation_type']??'')===$k?'selected':'' ?>><?= e($lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label><?= e(t('related_member_field')) ?></label>
          <select name="related_member_id">
            <option value=""><?= e(t('choose_ellipsis')) ?></option>
            <?php foreach ($others as $o): ?>
              <option value="<?= (int)$o['id'] ?>" <?= (int)($member['related_member_id']??0)===(int)$o['id']?'selected':'' ?>><?= e($o['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <?php if ($reverse): ?>
        <div class="field-hint" style="margin-top:6px">
          <?= e(t('reverse_related_to')) ?>
          <?php foreach ($reverse as $rv): ?>
            <a href="/admin/member-profile.php?id=<?= (int)$rv['id'] ?>" style="color:rgba(255,255,255,.6);border-bottom:1px solid rgba(255,255,255,.15)"><?= e($rv['name']) ?></a><?= $rv !== end($reverse) ? ', ' : '' ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="form-section">
      <p class="section-label"><?= e(t('volunteering_section')) ?></p>
      <label class="check-row">
        <input type="checkbox" name="is_volunteer" value="1" <?= !empty($member['is_volunteer'])?'checked':'' ?>>
        <?= e(t('is_volunteer_checkbox')) ?>
      </label>
    </div>

    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <?php if ($can_edit): ?>
        <button class="btn btn-solid" type="submit"><?= e(t('save_profile')) ?></button>
      <?php endif; ?>
      <a class="btn btn-ghost" href="/admin/members.php" style="pointer-events:auto"><?= e(t('give_up')) ?></a>
    </div>
  </form>

  <?php if ($is_admin): ?>
  <div class="form-section" style="margin-top:24px">
    <p class="section-label"><?= e(t('admin_panel_account_section')) ?></p>

    <?php if ($admin_account): ?>
      <p style="font-size:13px;color:rgba(255,255,255,.65);margin-bottom:14px">
        <?= e(t('already_has_account_prefix')) ?>
        <strong style="color:rgba(255,255,255,.6)"><?= e(position_label($admin_account)) ?></strong>,
        <?= e(t('login_with')) ?> <strong style="color:rgba(255,255,255,.6)"><?= e($admin_account['email']) ?></strong>
        <?= $admin_account['active'] ? '' : ' <span style="color:#e65100">' . e(t('deactivated_paren')) . '</span>' ?>.
        <?= e(t('granular_access_edit_hint_prefix')) ?> <a href="/admin/settings.php?s=users" style="color:rgba(255,255,255,.6);border-bottom:1px solid rgba(255,255,255,.15)"><?= e(t('users_settings_link')) ?></a>.
      </p>

      <?php if (!empty($member['email_secondary']) && $admin_account['email'] !== $member['email_secondary']): ?>
        <p class="field-hint" style="margin-bottom:14px">
          <?= e(t('login_email_will_sync')) ?><?= e($member['email_secondary']) ?><?= e(t('next_time_you_save')) ?>
        </p>
      <?php endif; ?>

      <form method="post" onsubmit="return confirm('<?= e(t('reset_pwd_resend_confirm')) ?>')">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="resend_welcome">
        <button class="btn btn-warn btn-sm" type="submit"><?= e(t('reset_pwd_resend_btn')) ?></button>
      </form>
    <?php else: ?>
      <p style="font-size:13px;color:rgba(255,255,255,.65);margin-bottom:16px">
        <?= e(t('grant_role_intro')) ?>
      </p>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="grant_panel_account">
        <div class="grid-2" style="max-width:520px;margin-bottom:14px">
          <div class="field">
            <label><?= e(t('position_field')) ?> *</label>
            <select name="position" id="mpPosSelect" onchange="mpTogglePLabel()">
              <?php foreach (POSITIONS as $k => $p): ?>
                <option value="<?= e($k) ?>"><?= e($p['label']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field" id="mpPLabelWrap" style="display:none">
            <label><?= e(t('custom_role_field')) ?></label>
            <input type="text" name="position_label" placeholder="<?= e(t('custom_role_ph')) ?>">
          </div>
        </div>
        <p class="field-hint" style="margin-bottom:14px">
          <?= e(t('login_email_label')) ?> <strong style="color:rgba(255,255,255,.6)"><?= e(member_primary_email($member)) ?></strong>
          <?= !empty($member['email_secondary']) ? e(t('association_email_paren')) : e(t('personal_email_paren')) ?>
        </p>
        <div id="mpPermMatrixWrap" style="display:none;background:#0a0a0a;border:1px solid rgba(255,255,255,.08);padding:16px;margin-bottom:14px;max-width:520px">
          <p style="font-size:12px;font-weight:700;color:rgba(255,255,255,.65);text-transform:uppercase;letter-spacing:.08em;margin-bottom:12px"><?= e(t('access_checklist_label')) ?></p>
          <?php render_permission_matrix([], 'perm'); ?>
        </div>
        <label class="check-row" style="margin-bottom:14px">
          <input type="checkbox" name="send_welcome" value="1" checked>
          <?= e(t('send_welcome_checkbox')) ?>
        </label>
        <div>
          <button class="btn btn-solid" type="submit"><?= e(t('designate_btn')) ?></button>
        </div>
      </form>
      <script>
      function mpTogglePLabel(){
        var val = document.getElementById('mpPosSelect').value;
        var isCons = val === 'consilier';
        var isEditableRole = isCons || val === 'revizor';
        document.getElementById('mpPLabelWrap').style.display = isCons ? 'flex' : 'none';
        document.getElementById('mpPermMatrixWrap').style.display = isEditableRole ? 'block' : 'none';
      }
      </script>
    <?php endif; ?>

    <?php if ($unlinked_accounts): ?>
    <div style="border-top:1px solid rgba(255,255,255,.06);margin-top:20px;padding-top:18px">
      <p style="font-size:12px;font-weight:700;color:rgba(255,255,255,.65);text-transform:uppercase;letter-spacing:.08em;margin-bottom:8px"><?= e(t('link_existing_account_label')) ?></p>
      <p class="field-hint" style="margin-bottom:12px;max-width:560px">
        <?= e(t('link_existing_intro')) ?>
      </p>
      <form method="post" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="link_existing_account">
        <div class="field" style="min-width:260px">
          <label><?= e(t('account_field')) ?></label>
          <select name="existing_user_id">
            <?php foreach ($unlinked_accounts as $ua): ?>
              <option value="<?= (int)$ua['id'] ?>"><?= e($ua['name']) ?> — <?= e($ua['email']) ?> (<?= e(position_label($ua)) ?>)<?= $ua['active']?'':e(t('deactivated_suffix')) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php if ($admin_account): ?>
          <label class="check-row" style="margin-bottom:10px">
            <input type="checkbox" name="delete_duplicate" value="1" checked>
            <?= e(t('delete_connected_account_prefix')) ?><?= e($admin_account['email']) ?>)
          </label>
        <?php endif; ?>
        <button class="btn btn-ghost btn-sm" type="submit"><?= e(t('connect_btn')) ?></button>
      </form>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if (!empty($member['is_volunteer'])): ?>
  <div class="form-section" style="margin-top:24px">
    <p class="section-label"><?= e(t('contributing_projects_section')) ?></p>

    <?php if (empty($contrib)): ?>
      <p style="font-size:13px;color:rgba(255,255,255,.45);margin-bottom:16px"><?= e(t('no_contributions')) ?></p>
    <?php else: ?>
      <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:18px">
        <?php foreach ($contrib as $c): ?>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;background:#0a0a0a;border:1px solid rgba(255,255,255,.05);padding:10px 14px">
          <div style="flex:1;min-width:160px;font-size:14px"><?= e($c['title_ro']) ?></div>
          <?php if ($can_edit): ?>
          <form method="post" style="display:flex;align-items:center;gap:8px">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="update_contribution">
            <input type="hidden" name="contribution_id" value="<?= (int)$c['id'] ?>">
            <select name="contribution_status" onchange="this.form.submit()" style="padding:5px 9px;font-size:12px;background:#000;border:1.5px solid rgba(255,255,255,.1);color:#fff">
              <option value="activ"     <?= $c['status']==='activ'?'selected':'' ?>><?= e(t('contribution_status_active')) ?></option>
              <option value="finalizat" <?= $c['status']==='finalizat'?'selected':'' ?>><?= e(t('contribution_status_done')) ?></option>
            </select>
          </form>
          <form method="post" onsubmit="return confirm('<?= e(t('remove_contribution_confirm')) ?>')">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="remove_contribution">
            <input type="hidden" name="contribution_id" value="<?= (int)$c['id'] ?>">
            <button class="btn btn-danger btn-xs" type="submit"><?= e(t('remove_btn')) ?></button>
          </form>
          <?php else: ?>
            <span class="badge" style="background:rgba(255,255,255,.06);color:rgba(255,255,255,.6)"><?= $c['status']==='activ'?e(t('contribution_status_active')):e(t('contribution_status_done')) ?></span>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php $available = array_filter($all_projects, fn($p) => !in_array($p['id'], $contrib_ids)); ?>
    <?php if (!$can_edit): ?>
      <!-- doar vizualizare -->
    <?php elseif ($available): ?>
      <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="add_contribution">
        <div class="field" style="flex:1;min-width:200px">
          <label><?= e(t('project_field')) ?></label>
          <select name="project_id">
            <?php foreach ($available as $p): ?>
              <option value="<?= (int)$p['id'] ?>"><?= e($p['title_ro']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field" style="width:150px">
          <label><?= e(t('th_status')) ?></label>
          <select name="contribution_status">
            <option value="activ"><?= e(t('contribution_status_active')) ?></option>
            <option value="finalizat"><?= e(t('contribution_status_done')) ?></option>
          </select>
        </div>
        <button class="btn btn-solid btn-sm" type="submit">+ <?= e(t('add_short')) ?></button>
      </form>
    <?php else: ?>
      <p style="font-size:12px;color:rgba(255,255,255,.45)"><?= e(t('all_projects_added')) ?></p>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<?php layout_foot(); ?>
