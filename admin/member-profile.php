<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mailer.php';
$user     = require_perm('members', 'view');
$can_edit = has_perm($user, 'members', 'edit');
$is_admin = $user['role'] === 'admin';
$pdo      = get_db();
ensure_member_schema($pdo);
ensure_user_permissions_column($pdo);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) { header('Location: /admin/members.php'); exit; }

$stmt = $pdo->prepare('SELECT * FROM membership_requests WHERE id=?');
$stmt->execute([$id]);
$member = $stmt->fetch();
if (!$member) { flash('error', 'Membru inexistent.'); header('Location: /admin/members.php'); exit; }

$statuses = [
    'new'       => 'Nou',
    'contacted' => 'Contactat',
    'active'    => 'Activ',
    'pending'   => 'În așteptare',
    'declined'  => 'Refuzat',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (!$can_edit) {
        http_response_code(403);
        die('Nu ai permisiunea de a edita acest profil.');
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $name    = trim($_POST['name']  ?? '');
        $email   = trim($_POST['email'] ?? '');
        $phone   = trim($_POST['phone'] ?? '');
        $city    = trim($_POST['city']  ?? '');
        $status  = $_POST['status'] ?? $member['status'];
        $errors  = [];
        if (!$name)                                     $errors[] = 'Numele e obligatoriu.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL))  $errors[] = 'Email invalid.';
        if (!array_key_exists($status, $statuses))       $status = $member['status'];

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

        if ($errors) {
            flash('error', implode(' ', $errors));
        } else {
            $pdo->prepare('UPDATE membership_requests SET
                name=?, email=?, phone=?, city=?, status=?,
                member_number=?, joined_date=?,
                dues_paid=?, dues_paid_date=?, dues_valid_until=?, dues_amount=?, dues_method=?,
                exempt=?, exempt_reason=?,
                is_volunteer=?,
                relation_type=?, related_member_id=?
                WHERE id=?')
                ->execute([
                    $name, $email, $phone ?: null, $city ?: null, $status,
                    $member_number, $joined_date,
                    $dues_paid, $dues_paid_date, $dues_valid_until, $dues_amount, $dues_method,
                    $exempt, $exempt_reason,
                    $is_volunteer,
                    $relation_type ?: null, $related_member_id,
                    $id,
                ]);
            flash('ok', 'Profilul a fost actualizat.');
        }
        header('Location: /admin/member-profile.php?id=' . $id); exit;
    }

    if ($action === 'add_contribution') {
        $project_id = (int)($_POST['project_id'] ?? 0);
        $cstatus    = ($_POST['contribution_status'] ?? '') === 'finalizat' ? 'finalizat' : 'activ';
        if ($project_id) {
            $pdo->prepare('INSERT IGNORE INTO member_projects (member_id, project_id, status) VALUES (?,?,?)')
                ->execute([$id, $project_id, $cstatus]);
            flash('ok', 'Proiect adăugat la contribuții.');
        }
        header('Location: /admin/member-profile.php?id=' . $id); exit;
    }

    if ($action === 'update_contribution') {
        $cid     = (int)($_POST['contribution_id'] ?? 0);
        $cstatus = ($_POST['contribution_status'] ?? '') === 'finalizat' ? 'finalizat' : 'activ';
        $pdo->prepare('UPDATE member_projects SET status=? WHERE id=? AND member_id=?')->execute([$cstatus, $cid, $id]);
        flash('ok', 'Status contribuție actualizat.');
        header('Location: /admin/member-profile.php?id=' . $id); exit;
    }

    if ($action === 'remove_contribution') {
        $cid = (int)($_POST['contribution_id'] ?? 0);
        $pdo->prepare('DELETE FROM member_projects WHERE id=? AND member_id=?')->execute([$cid, $id]);
        flash('ok', 'Contribuție eliminată.');
        header('Location: /admin/member-profile.php?id=' . $id); exit;
    }

    // Desemnează membrul drept consilier — creează cont de admin panel
    // pentru el, cu permisiuni granulare, și (opțional) trimite un email
    // de welcome cu datele de prim login. Doar adminul poate face asta.
    if ($action === 'promote_to_consilier' && $is_admin) {
        $exists = $pdo->prepare('SELECT id FROM bf_users WHERE email = ?');
        $exists->execute([$member['email']]);
        if ($exists->fetch()) {
            flash('error', 'Există deja un cont pentru acest email.');
            header('Location: /admin/member-profile.php?id=' . $id); exit;
        }
        $count = (int)$pdo->query('SELECT COUNT(*) FROM bf_users WHERE active=1')->fetchColumn();
        if ($count >= 8) {
            flash('error', 'Limita de 8 conturi în panoul admin a fost atinsă.');
            header('Location: /admin/member-profile.php?id=' . $id); exit;
        }
        $perms_json = build_permissions_json($_POST['perm'] ?? []);
        $temp_pwd   = gen_temp_password();
        $hash       = password_hash($temp_pwd, PASSWORD_BCRYPT, ['cost' => 12]);
        try {
            $pdo->prepare('INSERT INTO bf_users (name,email,password,role,position,position_label,permissions,must_change_pwd,active) VALUES (?,?,?,\'member\',\'consilier\',NULL,?,1,1)')
                ->execute([$member['name'], $member['email'], $hash, $perms_json]);
        } catch (PDOException $e) {
            flash('error', 'Nu s-a putut crea contul (email deja folosit?).');
            header('Location: /admin/member-profile.php?id=' . $id); exit;
        }

        $msg = 'Cont consilier creat. Parolă inițială: ' . $temp_pwd;
        if (!empty($_POST['send_welcome'])) {
            $body = "Bună, {$member['name']},\n\n"
                . "Ai fost desemnat/ă consilier în panoul de administrare Foreningen Front Door.\n\n"
                . "Login: https://foreningenfrontdoor.dk/admin/\n"
                . "Email: {$member['email']}\n"
                . "Parolă inițială: {$temp_pwd}\n\n"
                . "La primul login ți se va cere să îți setezi o parolă nouă.\n\n"
                . "Cu drag,\nForeningen Front Door";
            $result = send_smtp_mail($member['email'], $member['name'], 'Foreningen Front Door — Acces panou admin', $body, false);
            $msg .= $result === true ? ' Email de welcome trimis.' : (' Eroare la trimiterea emailului: ' . $result);
        }
        flash('ok', $msg);
        header('Location: /admin/member-profile.php?id=' . $id); exit;
    }

    // Resetează parola unui consilier deja creat din profilul de membru și
    // (opțional) retrimite emailul de welcome cu noile date.
    if ($action === 'resend_welcome' && $is_admin) {
        $acct = $pdo->prepare('SELECT * FROM bf_users WHERE email = ?');
        $acct->execute([$member['email']]);
        $acct = $acct->fetch();
        if (!$acct) {
            flash('error', 'Acest membru nu are cont în panoul admin.');
            header('Location: /admin/member-profile.php?id=' . $id); exit;
        }
        $temp_pwd = gen_temp_password();
        $hash     = password_hash($temp_pwd, PASSWORD_BCRYPT, ['cost' => 12]);
        $pdo->prepare('UPDATE bf_users SET password=?, must_change_pwd=1 WHERE id=?')->execute([$hash, $acct['id']]);
        $body = "Bună, {$member['name']},\n\n"
            . "Datele tale de acces la panoul de administrare Foreningen Front Door au fost resetate.\n\n"
            . "Login: https://foreningenfrontdoor.dk/admin/\n"
            . "Email: {$member['email']}\n"
            . "Parolă nouă: {$temp_pwd}\n\n"
            . "La primul login ți se va cere să îți setezi o parolă nouă.\n\n"
            . "Cu drag,\nForeningen Front Door";
        $result = send_smtp_mail($member['email'], $member['name'], 'Foreningen Front Door — Acces panou admin', $body, false);
        $msg = 'Parolă resetată la: ' . $temp_pwd;
        $msg .= $result === true ? ' Email retrimis.' : (' Eroare la trimiterea emailului: ' . $result);
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
$admin_account = null;
if ($is_admin) {
    $aa = $pdo->prepare('SELECT * FROM bf_users WHERE email = ?');
    $aa->execute([$member['email']]);
    $admin_account = $aa->fetch() ?: null;
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

layout_head('Profil membru', 'members');
?>
<div class="content" style="max-width:900px">
  <?php if ($flash): ?><div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>

  <div style="margin-bottom:16px">
    <a href="/admin/members.php" style="font-size:13px;color:rgba(255,255,255,.25)">← Înapoi la cereri</a>
  </div>

  <div class="page-head">
    <h1>👤 <?= e($member['name']) ?></h1>
  </div>

  <?php if (!$can_edit): ?>
    <div class="flash flash-error">Doar vizualizare — nu ai permisiunea de a edita acest profil.</div>
  <?php endif; ?>

  <form method="post" <?= $can_edit ? '' : 'style="pointer-events:none;opacity:.65"' ?>>
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="update_profile">

    <div class="form-section">
      <p class="section-label">Date de contact</p>
      <div class="grid-2" style="margin-bottom:14px">
        <div class="field"><label>Nume *</label><input type="text" name="name" value="<?= e($member['name']) ?>" required></div>
        <div class="field"><label>Email *</label><input type="email" name="email" value="<?= e($member['email']) ?>" required></div>
      </div>
      <div class="grid-3">
        <div class="field"><label>Telefon</label><input type="text" name="phone" value="<?= e($member['phone'] ?? '') ?>"></div>
        <div class="field"><label>Oraș</label><input type="text" name="city" value="<?= e($member['city'] ?? '') ?>"></div>
        <div class="field">
          <label>Status cerere</label>
          <select name="status">
            <?php foreach ($statuses as $k => $lbl): ?>
              <option value="<?= e($k) ?>" <?= $member['status']===$k?'selected':'' ?>><?= e($lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>

    <div class="form-section">
      <p class="section-label">Membru</p>
      <div class="grid-2">
        <div class="field"><label>Număr membru</label><input type="text" name="member_number" value="<?= e($member['member_number'] ?? '') ?>" placeholder="ex: FD-014"></div>
        <div class="field"><label>Dată aderare</label><input type="date" name="joined_date" value="<?= e($member['joined_date'] ?? '') ?>"></div>
      </div>
    </div>

    <div class="form-section">
      <p class="section-label">Cotizație</p>
      <label class="check-row" style="margin-bottom:14px">
        <input type="checkbox" name="dues_paid" value="1" <?= !empty($member['dues_paid'])?'checked':'' ?>>
        Cotizația a fost plătită
      </label>
      <div class="grid-3" style="margin-bottom:14px">
        <div class="field"><label>Data plății</label><input type="date" name="dues_paid_date" value="<?= e($member['dues_paid_date'] ?? '') ?>"></div>
        <div class="field">
          <label>Valabil până la</label>
          <input type="date" name="dues_valid_until" value="<?= e($member['dues_valid_until'] ?? '') ?>">
          <span class="field-hint">Dacă rămâne gol și bifezi „plătită" cu o dată, presupunem 1 an de la plată.</span>
        </div>
        <div class="field"><label>Sumă plătită (DKK)</label><input type="number" step="0.01" min="0" name="dues_amount" value="<?= e($member['dues_amount'] !== null ? (string)$member['dues_amount'] : '') ?>" placeholder="260"></div>
      </div>
      <div class="field" style="max-width:260px;margin-bottom:18px">
        <label>Metodă de plată</label>
        <select name="dues_method">
          <option value="">—</option>
          <?php foreach (DUES_METHODS as $k => $lbl): ?>
            <option value="<?= e($k) ?>" <?= ($member['dues_method']??'')===$k?'selected':'' ?>><?= e($lbl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <label class="check-row" style="margin-bottom:10px">
        <input type="checkbox" name="exempt" id="exemptCb" value="1" <?= !empty($member['exempt'])?'checked':'' ?> onchange="document.getElementById('exemptReasonWrap').style.display=this.checked?'block':'none'">
        Scutit de cotizație
      </label>
      <div class="field" id="exemptReasonWrap" style="<?= !empty($member['exempt'])?'':'display:none' ?>">
        <label>Motiv scutire</label>
        <input type="text" name="exempt_reason" value="<?= e($member['exempt_reason'] ?? '') ?>" placeholder="ex: fondator, dificultăți financiare, minor...">
      </div>
    </div>

    <div class="form-section">
      <p class="section-label">Relații</p>
      <div class="grid-2" style="margin-bottom:10px">
        <div class="field">
          <label>Relație</label>
          <select name="relation_type" id="relTypeSel">
            <?php foreach (RELATION_TYPES as $k => $lbl): ?>
              <option value="<?= e($k) ?>" <?= ($member['relation_type']??'')===$k?'selected':'' ?>><?= e($lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label>Membru relaționat</label>
          <select name="related_member_id">
            <option value="">— Alege —</option>
            <?php foreach ($others as $o): ?>
              <option value="<?= (int)$o['id'] ?>" <?= (int)($member['related_member_id']??0)===(int)$o['id']?'selected':'' ?>><?= e($o['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <?php if ($reverse): ?>
        <div class="field-hint" style="margin-top:6px">
          Relaționat (invers) cu:
          <?php foreach ($reverse as $rv): ?>
            <a href="/admin/member-profile.php?id=<?= (int)$rv['id'] ?>" style="color:rgba(255,255,255,.5);border-bottom:1px solid rgba(255,255,255,.15)"><?= e($rv['name']) ?></a><?= $rv !== end($reverse) ? ', ' : '' ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="form-section">
      <p class="section-label">Voluntariat</p>
      <label class="check-row">
        <input type="checkbox" name="is_volunteer" value="1" <?= !empty($member['is_volunteer'])?'checked':'' ?>>
        Este și voluntar în asociație
      </label>
    </div>

    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <?php if ($can_edit): ?>
        <button class="btn btn-solid" type="submit">Salvează profilul</button>
      <?php endif; ?>
      <a class="btn btn-ghost" href="/admin/members.php" style="pointer-events:auto">Renunță</a>
    </div>
  </form>

  <?php if ($is_admin): ?>
  <div class="form-section" style="margin-top:24px">
    <p class="section-label">Cont panou admin (consilier)</p>

    <?php if ($admin_account): ?>
      <p style="font-size:13px;color:rgba(255,255,255,.4);margin-bottom:14px">
        Acest membru are deja cont în panoul admin —
        <strong style="color:rgba(255,255,255,.6)"><?= e(position_label($admin_account)) ?></strong>
        <?= $admin_account['active'] ? '' : ' <span style="color:#e65100">(dezactivat)</span>' ?>.
        Accesul granular se editează din <a href="/admin/settings.php?s=users" style="color:rgba(255,255,255,.5);border-bottom:1px solid rgba(255,255,255,.15)">Setări → Utilizatori</a>.
      </p>
      <form method="post" onsubmit="return confirm('Resetezi parola și retrimiți emailul de welcome?')">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="resend_welcome">
        <button class="btn btn-warn btn-sm" type="submit">Resetează parola + retrimite welcome</button>
      </form>
    <?php else: ?>
      <p style="font-size:13px;color:rgba(255,255,255,.25);margin-bottom:16px">
        Îl poți desemna consilier — primește cont propriu în panoul admin, cu accesul pe care i-l bifezi mai jos.
      </p>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="promote_to_consilier">
        <div style="background:#0a0a0a;border:1px solid rgba(255,255,255,.08);padding:16px;margin-bottom:14px;max-width:520px">
          <?php render_permission_matrix([], 'perm'); ?>
        </div>
        <label class="check-row" style="margin-bottom:14px">
          <input type="checkbox" name="send_welcome" value="1" checked>
          Trimite email de welcome cu datele de prim login
        </label>
        <div>
          <button class="btn btn-solid" type="submit">Desemnează consilier</button>
        </div>
      </form>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if (!empty($member['is_volunteer'])): ?>
  <div class="form-section" style="margin-top:24px">
    <p class="section-label">Proiecte la care contribuie</p>

    <?php if (empty($contrib)): ?>
      <p style="font-size:13px;color:rgba(255,255,255,.25);margin-bottom:16px">Nicio contribuție înregistrată încă.</p>
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
              <option value="activ"     <?= $c['status']==='activ'?'selected':'' ?>>Activ</option>
              <option value="finalizat" <?= $c['status']==='finalizat'?'selected':'' ?>>Finalizat</option>
            </select>
          </form>
          <form method="post" onsubmit="return confirm('Elimini această contribuție?')">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="remove_contribution">
            <input type="hidden" name="contribution_id" value="<?= (int)$c['id'] ?>">
            <button class="btn btn-danger btn-xs" type="submit">Elimină</button>
          </form>
          <?php else: ?>
            <span class="badge" style="background:rgba(255,255,255,.06);color:rgba(255,255,255,.6)"><?= $c['status']==='activ'?'Activ':'Finalizat' ?></span>
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
          <label>Proiect</label>
          <select name="project_id">
            <?php foreach ($available as $p): ?>
              <option value="<?= (int)$p['id'] ?>"><?= e($p['title_ro']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field" style="width:150px">
          <label>Status</label>
          <select name="contribution_status">
            <option value="activ">Activ</option>
            <option value="finalizat">Finalizat</option>
          </select>
        </div>
        <button class="btn btn-solid btn-sm" type="submit">+ Adaugă</button>
      </form>
    <?php else: ?>
      <p style="font-size:12px;color:rgba(255,255,255,.25)">Toate proiectele existente sunt deja adăugate.</p>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<?php layout_foot(); ?>
