<?php
/* =============================================================
   Front Door DK — admin/auth.php
   ============================================================= */

// DB_HOST / DB_NAME / DB_USER / DB_PASS live in /config.php (gitignored).
// Copy config.sample.php → config.php and fill in real values.
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'name'            => 'fd_session',
        'cookie_httponly' => true,
        'cookie_secure'   => isset($_SERVER['HTTPS']),
        'cookie_samesite' => 'Strict',
    ]);
}

function get_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
             PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }
    return $pdo;
}

function require_login(): array {
    if (empty($_SESSION['fd_user'])) {
        header('Location: /admin/index.php?r=1'); exit;
    }
    if (!empty($_SESSION['fd_user']['must_change_pwd'])) {
        $cur = basename($_SERVER['PHP_SELF']);
        if (!in_array($cur, ['index.php','change-pwd.php','logout.php'])) {
            header('Location: /admin/change-pwd.php'); exit;
        }
    }
    return $_SESSION['fd_user'];
}

function require_director(): array {
    $u = require_login();
    if ($u['role'] !== 'admin') {
        header('Location: /admin/events.php?err=noaccess'); exit;
    }
    return $u;
}

/* =============================================================
   PERMISIUNI — 3 roluri:
     admin   (președinte/vicepreședinte/trezorier) — acces total.
     revizor — acces fix (nu se configurează din UI), în 4 secțiuni:
              • Evenimente — doar vizualizare (inclusiv o eventuală parte
                financiară, dacă/când va exista pe evenimente).
              • Proiecte — doar vizualizare, inclusiv detaliile/bugetul
                proiectului (project-details.php e vizibil cu 'view', dar
                doar salvabil cu 'edit' — vezi has_perm()).
              • Propuneri — vizualizare + poate propune subiecte noi +
                poate edita/șterge propriile propuneri. Nu votează și nu
                gestionează adunările.
              • Regnskab — doar vizualizare.
     member  (consilier) — nimic implicit; adminul bifează per-tab și
              per-acțiune ce poate face fiecare, salvat ca JSON în
              bf_users.permissions.
   ============================================================= */

define('CONSILIER_PERMISSION_SCHEMA', [
    'events'    => ['view' => 'Vede evenimente', 'create' => 'Poate crea evenimente noi', 'edit_own' => 'Poate edita evenimentele proprii', 'edit_any' => 'Poate edita orice eveniment', 'manage' => 'Poate suspenda/reactiva/anula/șterge evenimente'],
    'projects'  => ['view' => 'Vede proiecte (inclusiv detalii/buget)', 'create' => 'Poate crea proiecte noi', 'edit' => 'Poate edita proiecte (inclusiv detalii/buget)', 'manage' => 'Poate finaliza/anula/șterge proiecte'],
    'members'   => ['view' => 'Vede cererile de membership', 'edit' => 'Poate schimba status, trimite email, edita profil membru', 'delete' => 'Poate șterge cereri de membership'],
    'topics'    => ['view' => 'Vede propuneri și adunări', 'propose' => 'Poate propune subiecte', 'edit_own' => 'Poate edita/șterge propriile propuneri', 'vote' => 'Poate vota', 'manage' => 'Poate crea/gestiona adunări'],
    'documents' => ['view' => 'Vede documentele de transparență', 'manage' => 'Poate încărca/șterge documente'],
    'regnskab'  => ['view' => 'Vede regnskab', 'manage' => 'Poate gestiona regnskab'],
]);

// Setul fix de permisiuni al unui revizor — nu e configurabil din UI, spre
// deosebire de consilier. Aceeași formă (tab => [action => 1]) ca JSON-ul
// din bf_users.permissions, ca să poată fi citit tot prin has_perm().
const REVIZOR_PERMISSIONS = [
    'events'   => ['view' => 1],
    'projects' => ['view' => 1],
    'topics'   => ['view' => 1, 'propose' => 1, 'edit_own' => 1],
    'regnskab' => ['view' => 1],
];

/**
 * Asigură coloana bf_users.permissions (JSON cu accesul custom al unui
 * consilier). Migrare "lazy", ca restul aplicației.
 */
function ensure_user_permissions_column(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    try { $pdo->exec('ALTER TABLE bf_users ADD COLUMN permissions TEXT NULL'); } catch (PDOException $e) {}
    $done = true;
}

function user_permissions(array $user): array {
    if (empty($user['permissions'])) return [];
    $decoded = json_decode($user['permissions'], true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Construiește JSON-ul de permisiuni dintr-un array $_POST['perm'][tab][action]=1,
 * validat strict față de CONSILIER_PERMISSION_SCHEMA (nu acceptăm chei arbitrare).
 */
function build_permissions_json(array $posted): string {
    $out = [];
    foreach (CONSILIER_PERMISSION_SCHEMA as $tab => $actions) {
        foreach (array_keys($actions) as $action) {
            if (!empty($posted[$tab][$action])) $out[$tab][$action] = 1;
        }
    }
    return json_encode($out);
}

/**
 * Singurul punct de decizie pentru "poate userul X face acțiunea Y pe tab-ul Z".
 * Folosit atât pentru vizibilitatea tab-urilor (action implicit 'view'), cât
 * și pentru gating-ul acțiunilor concrete din fiecare pagină.
 */
function has_perm(array $user, string $tab, string $action = 'view'): bool {
    $role = $user['role'] ?? '';
    if ($role === 'admin') return true;
    if ($tab === 'settings' && $action === 'view') return true; // profilul propriu, mereu vizibil
    if ($role === 'revizor') {
        return !empty(REVIZOR_PERMISSIONS[$tab][$action]);
    }
    $perms = user_permissions($user);
    return !empty($perms[$tab][$action]);
}

/**
 * require_login() + verificare permisiune. Înlocuiește require_login()/
 * require_director() pe paginile ale căror acces variază acum pe rol.
 */
function require_perm(string $tab, string $action = 'view'): array {
    $u = require_login();
    if (!has_perm($u, $tab, $action)) {
        flash('error', 'Nu ai acces la această secțiune.');
        header('Location: /admin/settings.php'); exit;
    }
    return $u;
}

function can_edit_event(array $user, int $event_owner_id): bool {
    if (has_perm($user, 'events', 'edit_any')) return true;
    return (int)$user['id'] === $event_owner_id && has_perm($user, 'events', 'edit_own');
}

/**
 * Randează matricea de bife tab × acțiune pentru un consilier. Reutilizată
 * din settings.php (formularul de user nou / editare poziție) și din
 * member-profile.php (desemnarea unui membru ca și consilier).
 * $current = array deja decodat (din user_permissions()) pentru pre-bifare.
 * $prefix  = numele câmpului HTML, ex. "perm" → perm[events][view].
 */
function render_permission_matrix(array $current = [], string $name = 'perm', ?string $id_prefix = null): void {
    $id_prefix = $id_prefix ?? $name;
    foreach (CONSILIER_PERMISSION_SCHEMA as $tab => $actions): ?>
      <div style="margin-bottom:14px">
        <div style="font-size:12px;font-weight:700;color:rgba(255,255,255,.6);margin-bottom:6px;text-transform:capitalize"><?= e($tab) ?></div>
        <div style="display:flex;flex-direction:column;gap:5px">
          <?php foreach ($actions as $action => $label): $id = $id_prefix.'-'.$tab.'-'.$action; ?>
            <label class="check-row" style="font-size:13px">
              <input type="checkbox" name="<?= e($name) ?>[<?= e($tab) ?>][<?= e($action) ?>]" id="<?= e($id) ?>" value="1" <?= !empty($current[$tab][$action]) ? 'checked' : '' ?>>
              <?= e($label) ?>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach;
}

function csrf_token(): string {
    if (empty($_SESSION['fd_csrf'])) {
        $_SESSION['fd_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['fd_csrf'];
}

function csrf_verify(): void {
    if (!hash_equals($_SESSION['fd_csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(403); die('Token invalid.');
    }
}

/**
 * Generează o parolă temporară aleatorie (pentru useri noi / resetare parolă).
 * Nu mai folosim un string fix — asta era vizibil oricui citea codul sursă.
 */
function gen_temp_password(): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $pwd = '';
    for ($i = 0; $i < 12; $i++) {
        $pwd .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $pwd;
}

function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/**
 * Asigură coloanele/tabelele pentru profilul de membru (cotizație, scutire,
 * voluntariat, relații de familie, contribuții la proiecte). Apelată din
 * members.php, member-profile.php și members-export.php — pattern-ul de
 * migrare "lazy" e deja folosit în restul aplicației (events.php, topics.php,
 * settings.php), îl păstrăm consistent aici.
 */
function ensure_member_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;

    $cols = [
        'member_number'     => 'VARCHAR(20) NULL',
        'joined_date'       => 'DATE NULL',
        'dues_paid'         => 'TINYINT(1) NOT NULL DEFAULT 0',
        'dues_paid_date'    => 'DATE NULL',
        'dues_valid_until'  => 'DATE NULL',
        'dues_amount'       => 'DECIMAL(8,2) NULL',
        'dues_method'       => 'VARCHAR(30) NULL',
        'exempt'            => 'TINYINT(1) NOT NULL DEFAULT 0',
        'exempt_reason'     => 'TEXT NULL',
        'is_volunteer'      => 'TINYINT(1) NOT NULL DEFAULT 0',
        'relation_type'     => 'VARCHAR(20) NULL',
        'related_member_id' => 'INT UNSIGNED NULL',
    ];
    foreach ($cols as $name => $def) {
        try { $pdo->exec("ALTER TABLE membership_requests ADD COLUMN $name $def"); } catch (PDOException $e) {}
    }

    try { $pdo->exec("CREATE TABLE IF NOT EXISTS `member_projects` (
        `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `member_id`  INT UNSIGNED NOT NULL,
        `project_id` INT UNSIGNED NOT NULL,
        `status`     ENUM('activ','finalizat') NOT NULL DEFAULT 'activ',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`), UNIQUE KEY `uq_member_project` (`member_id`,`project_id`),
        KEY `idx_project` (`project_id`),
        FOREIGN KEY (`member_id`)  REFERENCES `membership_requests`(`id`) ON DELETE CASCADE,
        FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"); } catch (PDOException $e) {}

    $done = true;
}

define('DUES_METHODS', [
    'numerar'  => 'Numerar',
    'transfer' => 'Transfer bancar',
    'mobilepay'=> 'MobilePay',
    'altul'    => 'Altul',
]);

define('RELATION_TYPES', [
    ''         => '— Fără relație —',
    'parinte'  => 'Este părintele lui',
    'copil'    => 'Este copilul lui',
]);

function flash(string $type, string $msg): void {
    $_SESSION['fd_flash'] = ['type' => $type, 'msg' => $msg];
}

function get_flash(): ?array {
    $f = $_SESSION['fd_flash'] ?? null;
    unset($_SESSION['fd_flash']);
    return $f;
}

define('POSITIONS', [
    'presedinte'     => ['label' => 'Președinte',    'role' => 'admin'],
    'vicepresedinte' => ['label' => 'Vicepreședinte','role' => 'admin'],
    'trezorier'      => ['label' => 'Trezorier',     'role' => 'admin'],
    'revizor'        => ['label' => 'Revizor',       'role' => 'revizor'],
    'consilier'      => ['label' => 'Consilier',     'role' => 'member'],
]);

function position_label(array $user): string {
    $pos = POSITIONS[$user['position']] ?? null;
    if (!$pos) return $user['position'] ?? '';
    if (($user['position'] ?? '') === 'consilier' && !empty($user['position_label'])) {
        return $pos['label'] . ' — ' . $user['position_label'];
    }
    return $pos['label'];
}

function layout_head(string $title, string $active_tab): void {
    $user = $_SESSION['fd_user'] ?? [];
    $tabs = [
        'events'    => ['label' => 'Evenimente', 'icon' => '📅', 'url' => '/admin/events.php'],
        'projects'  => ['label' => 'Proiecte',   'icon' => '🗂',  'url' => '/admin/projects.php'],
        'members'   => ['label' => 'Membri',     'icon' => '👥',  'url' => '/admin/members.php'],
        'topics'    => ['label' => 'Propuneri',  'icon' => '🗳',  'url' => '/admin/topics.php'],
        'documents' => ['label' => 'Documente',  'icon' => '📄',  'url' => '/admin/documents.php'],
        'regnskab'  => ['label' => 'Regnskab',   'icon' => '💰',  'url' => '/admin/regnskab.php'],
        'settings'  => ['label' => 'Setări',     'icon' => '⚙️',  'url' => '/admin/settings.php'],
    ];
    ?>
<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($title) ?> — Front Door Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@700;900&family=Jost:wght@300;400;500&display=swap" rel="stylesheet">
<style>
/* ── RESET ── */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Jost',system-ui,sans-serif;font-weight:400;background:#000;color:#fff;font-size:15px;min-height:100vh;-webkit-font-smoothing:antialiased}
a{color:#fff;text-decoration:none}
button{font-family:inherit;cursor:pointer}
img{max-width:100%;display:block}

/* ── TOPBAR ── */
.topbar{background:#000;border-bottom:1px solid rgba(255,255,255,.1);height:60px;display:flex;align-items:center;justify-content:space-between;padding:0 28px;position:sticky;top:0;z-index:100}
.topbar-brand{font-family:'Nunito',sans-serif;font-weight:900;font-size:16px;letter-spacing:-.01em;color:#fff;display:flex;align-items:center;gap:10px}
.topbar-brand span{font-family:'Jost',sans-serif;font-weight:300;font-size:11px;letter-spacing:.2em;text-transform:uppercase;color:rgba(255,255,255,.35)}
.topbar-user{display:flex;align-items:center;gap:14px}
.topbar-avatar{width:30px;height:30px;background:#fff;display:flex;align-items:center;justify-content:center;font-family:'Nunito',sans-serif;font-size:12px;font-weight:900;color:#000;overflow:hidden;flex-shrink:0}
.topbar-avatar img{width:100%;height:100%;object-fit:cover}
.topbar-name{font-size:12px;font-weight:300;color:rgba(255,255,255,.4)}
.topbar-logout{font-size:11px;font-weight:500;letter-spacing:.08em;color:rgba(255,255,255,.35);padding:5px 10px;border:1px solid rgba(255,255,255,.15);transition:color .15s,border-color .15s}
.topbar-logout:hover{color:#fff;border-color:rgba(255,255,255,.5)}

/* ── TABS ── */
.tabs-bar{background:#000;border-bottom:1px solid rgba(255,255,255,.1);padding:0 28px;display:flex;gap:0;overflow-x:auto;scrollbar-width:none}
.tabs-bar::-webkit-scrollbar{display:none}
.tab-btn{padding:14px 18px;font-family:'Jost',sans-serif;font-size:13px;font-weight:400;letter-spacing:.04em;color:rgba(255,255,255,.35);border:none;background:transparent;cursor:pointer;border-bottom:2px solid transparent;transition:color .15s,border-color .15s;display:flex;align-items:center;gap:7px;white-space:nowrap;flex-shrink:0}
.tab-btn:hover{color:rgba(255,255,255,.7)}
.tab-btn.active{color:#fff;border-bottom-color:#fff}
.tab-btn.disabled{opacity:.25;cursor:not-allowed;pointer-events:none}

/* ── CONTENT ── */
.content{padding:32px 28px;max-width:1200px;margin:0 auto}

/* ── FLASH ── */
.flash{padding:12px 16px;margin-bottom:24px;font-size:14px;font-weight:300;border:1px solid}
.flash-ok{border-color:rgba(255,255,255,.2);color:rgba(255,255,255,.7);background:rgba(255,255,255,.04)}
.flash-error{border-color:rgba(200,50,50,.4);color:rgba(255,150,150,.9);background:rgba(200,50,50,.06)}

/* ── PAGE HEAD ── */
.page-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:28px;flex-wrap:wrap;gap:14px}
.page-head h1{font-family:'Nunito',sans-serif;font-weight:900;font-size:22px;letter-spacing:-.01em}

/* ── BUTTONS ── */
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;font-family:'Jost',sans-serif;font-size:12px;font-weight:500;letter-spacing:.06em;border:1px solid;cursor:pointer;transition:background .15s,color .15s,border-color .15s;text-decoration:none;white-space:nowrap}
.btn-solid{background:#fff;color:#000;border-color:#fff}.btn-solid:hover{opacity:.85}
.btn-ghost{background:transparent;color:rgba(255,255,255,.6);border-color:rgba(255,255,255,.2)}.btn-ghost:hover{color:#fff;border-color:rgba(255,255,255,.5)}
.btn-danger{border-color:rgba(200,50,50,.4);color:rgba(255,120,120,.9);background:transparent}.btn-danger:hover{background:rgba(200,50,50,.1);border-color:rgba(200,50,50,.6)}
.btn-warn{border-color:rgba(220,120,0,.4);color:rgba(255,180,80,.9);background:transparent}.btn-warn:hover{background:rgba(220,120,0,.1)}
.btn-green{border-color:rgba(60,150,60,.4);color:rgba(120,200,120,.9);background:transparent}.btn-green:hover{background:rgba(60,150,60,.1)}
.btn-sm{padding:6px 12px;font-size:11px}
.btn-xs{padding:4px 9px;font-size:11px}

/* ── TABLE ── */
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse}
th{text-align:left;padding:10px 14px;font-size:10px;font-weight:500;letter-spacing:.18em;text-transform:uppercase;color:rgba(255,255,255,.3);border-bottom:1px solid rgba(255,255,255,.1);white-space:nowrap}
td{padding:13px 14px;border-bottom:1px solid rgba(255,255,255,.06);vertical-align:middle;font-weight:300;font-size:14px}
tr:hover td{background:rgba(255,255,255,.02)}

/* ── BADGE ── */
.badge{display:inline-block;padding:2px 8px;font-size:10px;font-weight:500;letter-spacing:.1em;text-transform:uppercase;border:1px solid currentColor}

/* ── FORM ── */
.form-section{background:#0a0a0a;border:1px solid rgba(255,255,255,.08);padding:24px;margin-bottom:18px}
.section-label{font-family:'Jost',sans-serif;font-size:10px;font-weight:500;letter-spacing:.2em;text-transform:uppercase;color:rgba(255,255,255,.3);margin-bottom:16px}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px}
@media(max-width:640px){.grid-2,.grid-3{grid-template-columns:1fr}}
.field{display:flex;flex-direction:column;gap:5px}
.field label{font-size:11px;font-weight:500;letter-spacing:.08em;color:rgba(255,255,255,.4)}
.field input,.field select,.field textarea{padding:10px 13px;font-size:14px;font-family:'Jost',sans-serif;font-weight:400;background:transparent;border:1px solid rgba(255,255,255,.15);color:#fff;transition:border-color .15s}
.field input:focus,.field select:focus,.field textarea:focus{outline:none;border-color:#fff}
.field textarea{resize:vertical;min-height:90px}
.field select option{background:#111}
.field-hint{font-size:11px;color:rgba(255,255,255,.25);font-weight:300}
.check-row{display:flex;align-items:center;gap:8px;font-size:14px;font-weight:300;cursor:pointer}
.check-row input{width:15px;height:15px;accent-color:#fff;cursor:pointer}

/* ── FILTERS ── */
.filters{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px;align-items:center}
.filters select{padding:7px 11px;background:transparent;border:1px solid rgba(255,255,255,.15);color:#fff;font-size:13px;font-family:'Jost',sans-serif;font-weight:300}
.filters select:focus{outline:none;border-color:#fff}
.filters select option{background:#111}

/* ── COVER ── */
.cover-thumb{width:44px;height:44px;object-fit:cover;border:1px solid rgba(255,255,255,.1)}
.cover-empty{width:44px;height:44px;background:rgba(255,255,255,.04);border:1px dashed rgba(255,255,255,.1);display:flex;align-items:center;justify-content:center;font-size:9px;color:rgba(255,255,255,.2);letter-spacing:.08em}
.cover-preview{width:90px;height:90px;object-fit:cover;border:1px solid rgba(255,255,255,.15);display:block;margin-bottom:8px}

/* ── EMPTY ── */
.empty{padding:56px;text-align:center;color:rgba(255,255,255,.2);border:1px dashed rgba(255,255,255,.1);font-weight:300}
.empty a{color:rgba(255,255,255,.4);border-bottom:1px solid rgba(255,255,255,.15)}

/* ── ACTIONS ROW ── */
.actions{display:flex;gap:6px;flex-wrap:wrap;align-items:center}

/* ── TAGS ── */
.tags-wrap{display:flex;flex-wrap:wrap;gap:8px}
.tag-cb{display:none}
.tag-lbl{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;cursor:pointer;border:1px solid rgba(255,255,255,.12);font-size:12px;font-weight:400;transition:border-color .15s,background .15s;user-select:none}
.tag-lbl:hover{border-color:rgba(255,255,255,.35)}
.tag-cb:checked + .tag-lbl{border-color:#fff;background:rgba(255,255,255,.06)}
.tag-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0}

/* ── ERRORS ── */
.errors{background:rgba(200,50,50,.06);border:1px solid rgba(200,50,50,.3);padding:12px 16px;margin-bottom:18px}
.errors li{font-size:13px;font-weight:300;color:rgba(255,150,150,.9);margin-left:16px;margin-top:3px}

/* ── MISC ── */
.access-denied{padding:48px 24px;text-align:center;color:rgba(255,255,255,.2);border:1px dashed rgba(200,50,50,.2)}
.access-denied h2{font-size:18px;color:rgba(255,100,100,.7);margin-bottom:8px}

/* ── MOBILE ── */
@media(max-width:600px){
  .content{padding:20px 16px}
  .topbar{padding:0 16px}
  .tabs-bar{padding:0 12px}
  .tab-btn{padding:12px 12px;font-size:12px}
}

/* ── FOCUS ── */
a:focus-visible,button:focus-visible,input:focus-visible,select:focus-visible,textarea:focus-visible{outline:2px solid #fff;outline-offset:2px}
</style>
</head>
<body>
<header class="topbar">
  <div class="topbar-brand">
    Front Door
    <span>Admin</span>
  </div>
  <div class="topbar-user">
    <div class="topbar-avatar">
      <?php if (!empty($user['avatar'])): ?>
        <img src="/<?= e(ltrim($user['avatar'],'/')) ?>" alt="">
      <?php else: ?>
        <?= e(mb_substr($user['name'] ?? 'U', 0, 1)) ?>
      <?php endif; ?>
    </div>
    <span class="topbar-name"><?= e($user['name'] ?? '') ?> · <?= e(position_label($user)) ?></span>
    <a class="topbar-logout" href="/admin/logout.php">Ieși</a>
  </div>
</header>
<nav class="tabs-bar">
  <?php foreach ($tabs as $key => $tab):
    $show = has_perm($user, $key, 'view');
    $isActive = $active_tab === $key;
  ?>
  <button
    class="tab-btn <?= $isActive ? 'active' : '' ?> <?= !$show ? 'disabled' : '' ?>"
    onclick="<?= $show ? "location.href='{$tab['url']}'" : '' ?>"
  ><?= $tab['icon'] ?> <?= $tab['label'] ?></button>
  <?php endforeach; ?>
</nav>
    <?php
}

function layout_foot(): void {
    echo '</body></html>';
}
