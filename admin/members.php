<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mailer.php';
$user     = require_login();
$pdo      = get_db();
$is_admin = $user['role'] === 'admin';
$flash    = get_flash();

$statuses = [
    'new'       => ['label' => 'Nou',           'color' => 'rgba(255,255,255,.6)', 'bg' => 'rgba(255,255,255,.06)'],
    'contacted' => ['label' => 'Contactat',     'color' => '#ff8a50', 'bg' => 'rgba(230,81,0,.15)'],
    'active'    => ['label' => 'Activ',         'color' => '#66bb6a', 'bg' => 'rgba(46,125,50,.15)'],
    'pending'   => ['label' => 'În așteptare',  'color' => '#ffd54f', 'bg' => 'rgba(255,213,79,.1)'],
    'declined'  => ['label' => 'Refuzat',       'color' => 'rgba(255,255,255,.4)', 'bg' => 'rgba(86,96,108,.15)'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $rid    = (int)($_POST['rid'] ?? 0);
    $filter_back = $_POST['filter_back'] ?? 'new';

    if ($action === 'update_status' && $rid) {
        $status = $_POST['status'] ?? '';
        $notes  = trim($_POST['notes'] ?? '');
        if (array_key_exists($status, $statuses)) {
            $pdo->prepare('UPDATE membership_requests SET status=?, notes=? WHERE id=?')
                ->execute([$status, $notes ?: null, $rid]);
            flash('ok', 'Status actualizat: ' . $statuses[$status]['label']);
        }
        header('Location: /admin/members.php?s=' . $filter_back); exit;
    }

    if ($action === 'send_email' && $rid) {
        $to_email = trim($_POST['to_email'] ?? '');
        $to_name  = trim($_POST['to_name']  ?? '');
        $subject  = trim($_POST['subject']  ?? '');
        $body_txt = trim($_POST['body']     ?? '');

        if (!$subject || !$body_txt) {
            flash('error', 'Subiect și mesaj sunt obligatorii.');
            header('Location: /admin/members.php?s=' . $filter_back); exit;
        }

        $full_body = $body_txt . "\n\n---\nForeningen Front Door\nforeningenfrontdoor.dk\noffice@foreningenfrontdoor.dk";
        $result = send_smtp_mail($to_email, $to_name, $subject, $full_body, false);

        if ($result === true) {
            $en = $pdo->prepare('SELECT notes FROM membership_requests WHERE id=?');
            $en->execute([$rid]); $old = $en->fetchColumn() ?? '';
            $log = date('d.m.Y H:i') . ' — Email: "' . $subject . '"';
            $new_notes = $old ? $old . "\n" . $log : $log;
            $pdo->prepare('UPDATE membership_requests SET notes=?, status=CASE WHEN status="new" THEN "contacted" ELSE status END WHERE id=?')
                ->execute([$new_notes, $rid]);
            flash('ok', 'Email trimis cu succes către ' . $to_email . '.');
        } else {
            flash('error', 'Eroare SMTP: ' . $result);
        }
        header('Location: /admin/members.php?s=' . $filter_back); exit;
    }

    if ($action === 'delete' && $rid && $is_admin) {
        $pdo->prepare('DELETE FROM membership_requests WHERE id=?')->execute([$rid]);
        flash('ok', 'Cerere ștearsă.');
        header('Location: /admin/members.php?s=' . $filter_back); exit;
    }

    header('Location: /admin/members.php'); exit;
}

$filter = $_GET['s'] ?? 'new';
if (!array_key_exists($filter, $statuses) && $filter !== 'all') $filter = 'new';

$where  = $filter !== 'all' ? 'WHERE status = ?' : '';
$params = $filter !== 'all' ? [$filter] : [];
$stmt   = $pdo->prepare("SELECT * FROM membership_requests $where ORDER BY created_at DESC");
$stmt->execute($params); $requests = $stmt->fetchAll();

$counts = ['all' => 0];
foreach ($statuses as $k => $_) {
    $q = $pdo->prepare('SELECT COUNT(*) FROM membership_requests WHERE status=?');
    $q->execute([$k]); $counts[$k] = (int)$q->fetchColumn();
    $counts['all'] += $counts[$k];
}

layout_head('Cereri membership', 'members');
?>
<div class="content">
  <?php if ($flash): ?><div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>

  <div class="page-head">
    <h1>Cereri de membership</h1>
    <a class="btn btn-ghost btn-sm" href="/join.html" target="_blank">👁 Formularul public →</a>
  </div>

  <!-- Tabs -->
  <div style="display:flex;gap:0;border-bottom:1px solid rgba(255,255,255,.05);margin-bottom:24px;flex-wrap:wrap">
    <a href="/admin/members.php?s=all" style="padding:10px 16px;font-size:13px;font-weight:600;color:<?= $filter==='all'?'#fff':'rgba(255,255,255,.25)' ?>;border-bottom:2px solid <?= $filter==='all'?'rgba(255,255,255,.15)':'transparent' ?>;display:flex;align-items:center;gap:6px">
      Toate <span style="background:rgba(255,255,255,.05);color:rgba(255,255,255,.4);padding:1px 7px;font-size:11px;font-weight:700"><?= $counts['all'] ?></span>
    </a>
    <?php foreach ($statuses as $k => $sc): ?>
    <a href="/admin/members.php?s=<?= $k ?>"
       style="padding:10px 16px;font-size:13px;font-weight:600;color:<?= $filter===$k?'#fff':'rgba(255,255,255,.25)' ?>;border-bottom:2px solid <?= $filter===$k?'rgba(255,255,255,.15)':'transparent' ?>;display:flex;align-items:center;gap:6px">
      <?= e($sc['label']) ?>
      <?php if ($counts[$k] > 0): ?>
        <span style="background:<?= $k==='new'&&$counts[$k]>0?'rgba(255,255,255,.15)':'rgba(255,255,255,.05)' ?>;color:<?= $k==='new'&&$counts[$k]>0?'#fff':'rgba(255,255,255,.4)' ?>;padding:1px 7px;font-size:11px;font-weight:700"><?= $counts[$k] ?></span>
      <?php endif; ?>
    </a>
    <?php endforeach; ?>
  </div>

  <?php if (empty($requests)): ?>
    <div class="empty"><?= $filter==='new'?'Nicio cerere nouă.':'Nicio cerere în această categorie.' ?></div>
  <?php else: ?>
  <div style="display:flex;flex-direction:column;gap:12px">
    <?php foreach ($requests as $r):
      $sc = $statuses[$r['status']] ?? ['label'=>$r['status'],'color'=>'rgba(255,255,255,.4)','bg'=>'transparent'];
    ?>
    <div style="background:#0a0a0a;border:1px solid rgba(255,255,255,.05);padding:20px 24px">

      <!-- Header -->
      <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:10px">
        <div style="flex:1;min-width:180px">
          <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:4px">
            <strong style="font-size:16px"><?= e($r['name']) ?></strong>
            <span style="padding:2px 9px;font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;background:<?= e($sc['bg']) ?>;color:<?= e($sc['color']) ?>"><?= e($sc['label']) ?></span>
          </div>
          <div style="font-size:13px;color:rgba(255,255,255,.4);display:flex;flex-wrap:wrap;gap:12px;margin-bottom:2px">
            <span style="color:rgba(255,255,255,.6)"><?= e($r['email']) ?></span>
            <?php if ($r['phone']): ?><span><?= e($r['phone']) ?></span><?php endif; ?>
            <span>📍 <?= e($r['city']) ?></span>
            <?php if ($r['source']): ?><span style="color:rgba(255,255,255,.25)">via <?= e($r['source']) ?></span><?php endif; ?>
          </div>
          <div style="font-size:12px;color:rgba(255,255,255,.25)"><?= e(date('d.m.Y H:i', strtotime($r['created_at']))) ?></div>
        </div>
        <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;flex-shrink:0">
          <button class="btn btn-solid btn-sm" onclick="togglePanel(<?= (int)$r['id'] ?>,'email')" style="font-size:12px">✉ Scrie email</button>
          <button class="btn btn-ghost btn-xs" onclick="togglePanel(<?= (int)$r['id'] ?>,'status')">Status</button>
          <?php if ($is_admin): ?>
          <form method="post" style="display:inline" onsubmit="return confirm('Ștergi definitiv?')">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="rid" value="<?= (int)$r['id'] ?>">
            <input type="hidden" name="filter_back" value="<?= e($filter) ?>">
            <button class="btn btn-danger btn-xs" type="submit">Șterge</button>
          </form>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($r['message']): ?>
        <div style="background:#000;border:1px solid rgba(242,245,248,.07);padding:11px 15px;font-size:14px;color:rgba(255,255,255,.4);line-height:1.6;margin-bottom:10px"><?= nl2br(e($r['message'])) ?></div>
      <?php endif; ?>

      <?php if ($r['notes']): ?>
        <div style="font-size:12px;color:rgba(255,255,255,.25);border-left:2px solid rgba(255,255,255,.07);padding-left:10px;margin-bottom:10px;white-space:pre-line"><?= e($r['notes']) ?></div>
      <?php endif; ?>

      <!-- Status panel -->
      <div id="status-<?= (int)$r['id'] ?>" style="display:none;border-top:1px solid rgba(255,255,255,.04);padding-top:14px;margin-top:4px">
        <form method="post">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="update_status">
          <input type="hidden" name="rid" value="<?= (int)$r['id'] ?>">
          <input type="hidden" name="filter_back" value="<?= e($filter) ?>">
          <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
            <div class="field" style="margin-bottom:0">
              <label style="font-size:12px;font-weight:600;color:rgba(255,255,255,.4);margin-bottom:5px;display:block">Status</label>
              <select name="status" style="padding:8px 12px;font-size:13px;font-family:inherit;background:#000;border:1.5px solid rgba(255,255,255,.1);color:#fff">
                <?php foreach ($statuses as $k => $sc2): ?>
                  <option value="<?= e($k) ?>" <?= $r['status']===$k?'selected':'' ?>><?= e($sc2['label']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field" style="margin-bottom:0;flex:1;min-width:200px">
              <label style="font-size:12px;font-weight:600;color:rgba(255,255,255,.4);margin-bottom:5px;display:block">Note interne</label>
              <input type="text" name="notes" value="<?= e($r['notes']??'') ?>" placeholder="ex: Contactat 31.08, așteptăm confirmare..."
                     style="width:100%;padding:8px 12px;font-size:13px;font-family:inherit;background:#000;border:1.5px solid rgba(255,255,255,.1);color:#fff">
            </div>
            <button class="btn btn-solid btn-sm" type="submit">Salvează</button>
            <button class="btn btn-ghost btn-xs" type="button" onclick="togglePanel(<?= (int)$r['id'] ?>,'status')">✕</button>
          </div>
        </form>
      </div>

      <!-- Email panel -->
      <div id="email-<?= (int)$r['id'] ?>" style="display:none;border-top:1px solid rgba(29,83,129,.25);padding-top:18px;margin-top:8px">
        <div style="font-size:11px;letter-spacing:.16em;text-transform:uppercase;color:rgba(255,255,255,.6);margin-bottom:14px">
          Email către <?= e($r['name']) ?> · <span style="color:rgba(255,255,255,.25);text-transform:none;letter-spacing:0"><?= e($r['email']) ?></span>
        </div>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="send_email">
          <input type="hidden" name="rid" value="<?= (int)$r['id'] ?>">
          <input type="hidden" name="to_email" value="<?= e($r['email']) ?>">
          <input type="hidden" name="to_name" value="<?= e($r['name']) ?>">
          <input type="hidden" name="filter_back" value="<?= e($filter) ?>">
          <input type="hidden" name="subject" id="subj-final-<?= (int)$r['id'] ?>">

          <div class="field" style="margin-bottom:12px">
            <label style="font-size:12px;font-weight:600;color:rgba(255,255,255,.4);margin-bottom:6px;display:block">Șablon rapid</label>
            <select id="tmpl-<?= (int)$r['id'] ?>" style="width:100%;padding:10px 13px;font-size:14px;font-family:inherit;background:#000;border:1.5px solid rgba(255,255,255,.1);color:#fff;margin-bottom:8px"
                    onchange="applyTemplate(<?= (int)$r['id'] ?>,'<?= e(addslashes($r['name'])) ?>')">
              <option value="">— Alege un șablon —</option>
              <option value="confirm_received">📬 Cerere primită</option>
              <option value="confirm_active">✓ Membership activ</option>
              <option value="info_payment">💳 Informații cotizație</option>
              <option value="pending_info">⏳ În așteptare</option>
              <option value="blank">✏️ Mesaj liber</option>
            </select>
            <label style="font-size:12px;font-weight:600;color:rgba(255,255,255,.4);margin-bottom:5px;display:block">Subiect *</label>
            <input type="text" id="subj-<?= (int)$r['id'] ?>" placeholder="Subiectul emailului..."
                   style="width:100%;padding:10px 13px;font-size:14px;font-family:inherit;background:#000;border:1.5px solid rgba(255,255,255,.1);color:#fff">
          </div>

          <div class="field" style="margin-bottom:14px">
            <label style="font-size:12px;font-weight:600;color:rgba(255,255,255,.4);margin-bottom:5px;display:block">Mesaj *</label>
            <textarea id="body-<?= (int)$r['id'] ?>" name="body" rows="9"
                      style="width:100%;padding:12px 14px;font-size:14px;font-family:inherit;background:#000;border:1.5px solid rgba(255,255,255,.1);color:#fff;resize:vertical;line-height:1.65"
                      placeholder="Scrie mesajul..."></textarea>
            <span style="font-size:11px;color:rgba(255,255,255,.25);margin-top:3px;display:block">De la: <strong style="color:rgba(255,255,255,.6)">office@foreningenfrontdoor.dk</strong> · Semnătura se adaugă automat.</span>
          </div>

          <div style="display:flex;gap:8px">
            <button class="btn btn-solid btn-sm" type="submit" onclick="finalizeEmail(<?= (int)$r['id'] ?>)">✉ Trimite</button>
            <button class="btn btn-ghost btn-xs" type="button" onclick="togglePanel(<?= (int)$r['id'] ?>,'email')">Anulează</button>
          </div>
        </form>
      </div>

    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<script>
function togglePanel(id, panel) {
  var other = panel === 'email' ? 'status' : 'email';
  document.getElementById(other + '-' + id).style.display = 'none';
  var el = document.getElementById(panel + '-' + id);
  el.style.display = el.style.display === 'none' ? 'block' : 'none';
  if (el.style.display === 'block') el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

var tmpls = {
  confirm_received: {
    subj: 'Foreningen Front Door — Vi har modtaget din ansøgning',
    body: function(n){ return 'Kære ' + n + ',\n\nTak for din ansøgning om medlemskab i Foreningen Front Door!\n\nVi har modtaget din henvendelse og vender tilbage inden for 5 hverdage.\n\nMed venlig hilsen'; }
  },
  confirm_active: {
    subj: 'Foreningen Front Door — Velkommen som aktivt medlem',
    body: function(n){ return 'Kære ' + n + ',\n\nVi er glade for at bekræfte, at du nu er aktivt medlem af Foreningen Front Door. Velkommen!\n\nFølg os på Instagram (@frontdoor_dk) og Facebook for opdateringer om kommende arrangementer.\n\nMed venlig hilsen'; }
  },
  info_payment: {
    subj: 'Foreningen Front Door — Oplysninger om kontingent',
    body: function(n){ return 'Kære ' + n + ',\n\nDet årlige kontingent er 260 DKK. Vi aftaler betalingen personligt.\n\nSkriv gerne tilbage, så finder vi en løsning der passer for dig.\n\nMed venlig hilsen'; }
  },
  pending_info: {
    subj: 'Foreningen Front Door — Angående din ansøgning',
    body: function(n){ return 'Kære ' + n + ',\n\nTak for din tålmodighed. Vi behandler fortsat din ansøgning og vender tilbage snarest.\n\nMed venlig hilsen'; }
  },
  blank: {
    subj: '',
    body: function(n){ return 'Kære ' + n + ',\n\n'; }
  }
};

function applyTemplate(id, name) {
  var key = document.getElementById('tmpl-' + id).value;
  if (!key || !tmpls[key]) return;
  document.getElementById('subj-' + id).value = tmpls[key].subj;
  document.getElementById('body-' + id).value = tmpls[key].body(name);
}

function finalizeEmail(id) {
  var subj = document.getElementById('subj-' + id).value.trim();
  document.getElementById('subj-final-' + id).value = subj;
}
</script>
<?php layout_foot(); ?>
