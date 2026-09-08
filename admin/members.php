<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mailer.php';
$user       = require_perm('members', 'view');
$pdo        = get_db();
$can_edit   = has_perm($user, 'members', 'edit');
$can_delete = has_perm($user, 'members', 'delete');
$flash      = get_flash();
ensure_member_schema($pdo);
ensure_user_member_link_column($pdo);

$statuses = [
    'new'       => ['label' => t('status_new'),       'color' => 'rgba(255,255,255,.6)', 'bg' => 'rgba(255,255,255,.06)'],
    'contacted' => ['label' => t('status_contacted'),  'color' => '#ff8a50', 'bg' => 'rgba(230,81,0,.15)'],
    'active'    => ['label' => t('status_active'),     'color' => '#66bb6a', 'bg' => 'rgba(46,125,50,.15)'],
    'pending'   => ['label' => t('status_pending'),    'color' => '#ffd54f', 'bg' => 'rgba(255,213,79,.1)'],
    'declined'  => ['label' => t('status_declined'),   'color' => 'rgba(255,255,255,.6)', 'bg' => 'rgba(86,96,108,.15)'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $rid    = (int)($_POST['rid'] ?? 0);
    $filter_back = $_POST['filter_back'] ?? 'new';

    if ($action === 'update_status' && $rid && $can_edit) {
        $status = $_POST['status'] ?? '';
        $notes  = trim($_POST['notes'] ?? '');
        if (array_key_exists($status, $statuses)) {
            $pdo->prepare('UPDATE membership_requests SET status=?, notes=? WHERE id=?')
                ->execute([$status, $notes ?: null, $rid]);
            flash('ok', t('status_updated_prefix') . $statuses[$status]['label']);
        }
        header('Location: /admin/members.php?s=' . $filter_back); exit;
    }

    if ($action === 'send_email' && $rid && $can_edit) {
        // Emailul de destinație e derivat server-side din profil (nu din
        // hidden field-ul postat) — merge automat pe emailul de asociație
        // dacă membrul are unul completat, ca orice altă comunicare.
        $r_row = $pdo->prepare('SELECT name, email, email_secondary FROM membership_requests WHERE id=?');
        $r_row->execute([$rid]); $r_row = $r_row->fetch();
        if (!$r_row) { header('Location: /admin/members.php?s=' . $filter_back); exit; }
        $to_email = member_primary_email($r_row);
        $to_name  = $r_row['name'];
        $subject  = trim($_POST['subject']  ?? '');
        $body_txt = trim($_POST['body']     ?? '');

        if (!$subject || !$body_txt) {
            flash('error', t('subject_body_required'));
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
            flash('ok', t('email_sent_prefix') . $to_email . '.');
        } else {
            flash('error', t('smtp_error_prefix') . $result);
        }
        header('Location: /admin/members.php?s=' . $filter_back); exit;
    }

    if ($action === 'delete' && $rid && $can_delete) {
        $pdo->prepare('DELETE FROM membership_requests WHERE id=?')->execute([$rid]);
        flash('ok', t('request_deleted'));
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

// Membrii afișați care au deja un cont în panoul admin — pentru badge-ul
// "profil conectat" de pe fiecare card.
$panel_accounts = [];
if ($requests) {
    $ids = array_column($requests, 'id');
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $pa  = $pdo->prepare("SELECT * FROM bf_users WHERE member_id IN ($ph)");
    $pa->execute($ids);
    foreach ($pa->fetchAll() as $row) { $panel_accounts[(int)$row['member_id']] = $row; }
}

layout_head(t('membership_requests'), 'members');
?>
<div class="content">
  <?php if ($flash): ?><div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>

  <div class="page-head">
    <h1><?= e(t('membership_requests_h1')) ?></h1>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <a class="btn btn-ghost btn-sm" href="/admin/members-export.php"><?= e(t('export_pdf_active')) ?></a>
      <a class="btn btn-ghost btn-sm" href="/join.html" target="_blank"><?= e(t('public_form_link')) ?></a>
    </div>
  </div>

  <!-- Tabs -->
  <div style="display:flex;gap:0;border-bottom:1px solid rgba(255,255,255,.05);margin-bottom:24px;flex-wrap:wrap">
    <a href="/admin/members.php?s=all" style="padding:10px 16px;font-size:13px;font-weight:600;color:<?= $filter==='all'?'#fff':'rgba(255,255,255,.45)' ?>;border-bottom:2px solid <?= $filter==='all'?'rgba(255,255,255,.15)':'transparent' ?>;display:flex;align-items:center;gap:6px">
      <?= e(t('tab_all')) ?> <span style="background:rgba(255,255,255,.05);color:rgba(255,255,255,.65);padding:1px 7px;font-size:11px;font-weight:700"><?= $counts['all'] ?></span>
    </a>
    <?php foreach ($statuses as $k => $sc): ?>
    <a href="/admin/members.php?s=<?= $k ?>"
       style="padding:10px 16px;font-size:13px;font-weight:600;color:<?= $filter===$k?'#fff':'rgba(255,255,255,.45)' ?>;border-bottom:2px solid <?= $filter===$k?'rgba(255,255,255,.15)':'transparent' ?>;display:flex;align-items:center;gap:6px">
      <?= e($sc['label']) ?>
      <?php if ($counts[$k] > 0): ?>
        <span style="background:<?= $k==='new'&&$counts[$k]>0?'rgba(255,255,255,.15)':'rgba(255,255,255,.05)' ?>;color:<?= $k==='new'&&$counts[$k]>0?'#fff':'rgba(255,255,255,.6)' ?>;padding:1px 7px;font-size:11px;font-weight:700"><?= $counts[$k] ?></span>
      <?php endif; ?>
    </a>
    <?php endforeach; ?>
  </div>

  <?php if (empty($requests)): ?>
    <div class="empty"><?= $filter==='new' ? e(t('no_new_requests')) : e(t('no_requests_category')) ?></div>
  <?php else: ?>
  <div style="display:flex;flex-direction:column;gap:12px">
    <?php foreach ($requests as $r):
      $sc = $statuses[$r['status']] ?? ['label'=>$r['status'],'color'=>'rgba(255,255,255,.4)','bg'=>'transparent'];
    ?>
    <div style="background:#0a0a0a;border:1px solid rgba(255,255,255,.05);padding:20px 24px">

      <!-- Header -->
      <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:10px">
        <div style="flex:1;min-width:180px;display:flex;gap:12px">
          <div style="width:36px;height:36px;border-radius:50%;background:rgba(255,255,255,.1);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;color:rgba(255,255,255,.6);overflow:hidden;flex-shrink:0">
            <?php if (!empty($r['avatar'])): ?><img src="/<?= e(ltrim($r['avatar'],'/')) ?>" style="width:100%;height:100%;object-fit:cover" alt=""><?php else: ?><?= e(mb_substr($r['name'],0,1)) ?><?php endif; ?>
          </div>
          <div style="flex:1;min-width:140px">
          <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:4px">
            <strong style="font-size:16px"><?= e($r['name']) ?></strong>
            <span style="padding:2px 9px;font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;background:<?= e($sc['bg']) ?>;color:<?= e($sc['color']) ?>"><?= e($sc['label']) ?></span>
            <?php if (!empty($r['exempt'])): ?>
              <span class="badge" style="color:rgba(255,213,79,.9);background:rgba(255,213,79,.1)"><?= e(t('exempt_badge')) ?></span>
            <?php elseif (!empty($r['dues_paid'])): ?>
              <?php $duesOk = empty($r['dues_valid_until']) || strtotime($r['dues_valid_until']) >= strtotime('today'); ?>
              <span class="badge" style="color:<?= $duesOk?'rgba(120,200,120,.9)':'rgba(255,150,150,.9)' ?>;background:<?= $duesOk?'rgba(60,150,60,.1)':'rgba(200,50,50,.06)' ?>"><?= $duesOk ? e(t('dues_paid')) : e(t('dues_expired')) ?></span>
            <?php endif; ?>
            <?php if (!empty($r['is_volunteer'])): ?>
              <span class="badge" style="color:rgba(255,255,255,.6);background:rgba(255,255,255,.06)"><?= e(t('volunteer_badge')) ?></span>
            <?php endif; ?>
            <?php if (!empty($r['special_needs'])): ?>
              <span class="badge" style="color:rgba(160,200,255,.9);background:rgba(80,140,255,.1)"><?= e(t('special_needs_badge')) ?></span>
            <?php endif; ?>
            <?php if (!empty($panel_accounts[(int)$r['id']])): $pa = $panel_accounts[(int)$r['id']]; ?>
              <span class="badge" style="color:<?= $pa['active']?'rgba(255,255,255,.75)':'rgba(255,255,255,.5)' ?>;background:rgba(255,255,255,.06)"><?= e(position_label($pa)) ?><?= $pa['active']?'':e(t('deactivated_suffix')) ?></span>
            <?php endif; ?>
          </div>
          <div style="font-size:13px;color:rgba(255,255,255,.65);display:flex;flex-wrap:wrap;gap:12px;margin-bottom:2px">
            <span style="color:rgba(255,255,255,.6)"><?= e($r['email']) ?></span>
            <?php if (!empty($r['email_secondary'])): ?>
              <span style="color:rgba(255,255,255,.5)"><?= e($r['email_secondary']) ?></span>
            <?php endif; ?>
            <?php if ($r['phone']): ?><span><?= e($r['phone']) ?></span><?php endif; ?>
            <?php if ($r['city']): ?><span><?= e($r['city']) ?></span><?php endif; ?>
            <?php if ($r['source']): ?><span style="color:rgba(255,255,255,.65)">via <?= e($r['source']) ?></span><?php endif; ?>
          </div>
          <div style="font-size:12px;color:rgba(255,255,255,.65)"><?= e(date('d.m.Y H:i', strtotime($r['created_at']))) ?></div>
          </div>
        </div>
        <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;flex-shrink:0">
          <a class="btn btn-ghost btn-xs" href="/admin/member-profile.php?id=<?= (int)$r['id'] ?>"><?= e(t('profile_btn')) ?></a>
          <?php if ($can_edit): ?>
          <button class="btn btn-solid btn-sm" onclick="togglePanel(<?= (int)$r['id'] ?>,'email')" style="font-size:12px"><?= e(t('write_email_btn')) ?></button>
          <button class="btn btn-ghost btn-xs" onclick="togglePanel(<?= (int)$r['id'] ?>,'status')"><?= e(t('status_btn')) ?></button>
          <?php endif; ?>
          <?php if ($can_delete): ?>
          <form method="post" style="display:inline" onsubmit="return confirm('<?= e(t('delete_confirm')) ?>')">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="rid" value="<?= (int)$r['id'] ?>">
            <input type="hidden" name="filter_back" value="<?= e($filter) ?>">
            <button class="btn btn-danger btn-xs" type="submit"><?= e(t('delete')) ?></button>
          </form>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($r['message']): ?>
        <div style="background:#000;border:1px solid rgba(242,245,248,.07);padding:11px 15px;font-size:14px;color:rgba(255,255,255,.65);line-height:1.6;margin-bottom:10px"><?= nl2br(e($r['message'])) ?></div>
      <?php endif; ?>

      <?php if ($r['notes']): ?>
        <div style="font-size:12px;color:rgba(255,255,255,.45);border-left:2px solid rgba(255,255,255,.07);padding-left:10px;margin-bottom:10px;white-space:pre-line"><?= e($r['notes']) ?></div>
      <?php endif; ?>

      <!-- Status panel -->
      <?php if ($can_edit): ?>
      <div id="status-<?= (int)$r['id'] ?>" style="display:none;border-top:1px solid rgba(255,255,255,.04);padding-top:14px;margin-top:4px">
        <form method="post">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="update_status">
          <input type="hidden" name="rid" value="<?= (int)$r['id'] ?>">
          <input type="hidden" name="filter_back" value="<?= e($filter) ?>">
          <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
            <div class="field" style="margin-bottom:0">
              <label style="font-size:12px;font-weight:600;color:rgba(255,255,255,.65);margin-bottom:5px;display:block"><?= e(t('th_status')) ?></label>
              <select name="status" style="padding:8px 12px;font-size:13px;font-family:inherit;background:#000;border:1.5px solid rgba(255,255,255,.1);color:#fff">
                <?php foreach ($statuses as $k => $sc2): ?>
                  <option value="<?= e($k) ?>" <?= $r['status']===$k?'selected':'' ?>><?= e($sc2['label']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field" style="margin-bottom:0;flex:1;min-width:200px">
              <label style="font-size:12px;font-weight:600;color:rgba(255,255,255,.65);margin-bottom:5px;display:block"><?= e(t('internal_notes_label')) ?></label>
              <input type="text" name="notes" value="<?= e($r['notes']??'') ?>" placeholder="<?= e(t('internal_notes_ph')) ?>"
                     style="width:100%;padding:8px 12px;font-size:13px;font-family:inherit;background:#000;border:1.5px solid rgba(255,255,255,.1);color:#fff">
            </div>
            <button class="btn btn-solid btn-sm" type="submit"><?= e(t('save')) ?></button>
            <button class="btn btn-ghost btn-xs" type="button" onclick="togglePanel(<?= (int)$r['id'] ?>,'status')">✕</button>
          </div>
        </form>
      </div>
      <?php endif; ?>

      <!-- Email panel -->
      <?php if ($can_edit): ?>
      <div id="email-<?= (int)$r['id'] ?>" style="display:none;border-top:1px solid rgba(29,83,129,.25);padding-top:18px;margin-top:8px">
        <div style="font-size:11px;letter-spacing:.16em;text-transform:uppercase;color:rgba(255,255,255,.6);margin-bottom:14px">
          <?= e(t('email_to_prefix')) ?> <?= e($r['name']) ?> · <span style="color:rgba(255,255,255,.65);text-transform:none;letter-spacing:0"><?= e(member_primary_email($r)) ?><?= !empty($r['email_secondary']) ? e(t('association_email_suffix')) : '' ?></span>
        </div>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="send_email">
          <input type="hidden" name="rid" value="<?= (int)$r['id'] ?>">
          <input type="hidden" name="filter_back" value="<?= e($filter) ?>">
          <input type="hidden" name="subject" id="subj-final-<?= (int)$r['id'] ?>">

          <div class="field" style="margin-bottom:12px">
            <label style="font-size:12px;font-weight:600;color:rgba(255,255,255,.65);margin-bottom:6px;display:block"><?= e(t('quick_template_label')) ?></label>
            <div style="display:flex;gap:8px;margin-bottom:8px">
              <select id="tmpl-<?= (int)$r['id'] ?>" style="flex:1;min-width:0;padding:10px 13px;font-size:14px;font-family:inherit;background:#000;border:1.5px solid rgba(255,255,255,.1);color:#fff"
                      onchange="applyTemplate(<?= (int)$r['id'] ?>,'<?= e(addslashes($r['name'])) ?>')">
                <option value=""><?= e(t('choose_template_ph')) ?></option>
                <option value="confirm_received"><?= e(t('tmpl_confirm_received')) ?></option>
                <option value="confirm_active"><?= e(t('tmpl_confirm_active')) ?></option>
                <option value="info_payment"><?= e(t('tmpl_info_payment')) ?></option>
                <option value="pending_info"><?= e(t('tmpl_pending_info')) ?></option>
                <option value="blank"><?= e(t('tmpl_blank')) ?></option>
              </select>
              <select id="tmplLang-<?= (int)$r['id'] ?>" title="<?= e(t('template_lang_title')) ?>" style="width:78px;flex-shrink:0;padding:10px 8px;font-size:13px;font-weight:600;font-family:inherit;background:#000;border:1.5px solid rgba(255,255,255,.1);color:#fff"
                      onchange="applyTemplate(<?= (int)$r['id'] ?>,'<?= e(addslashes($r['name'])) ?>')">
                <option value="da">DA</option>
                <option value="ro">RO</option>
                <option value="en">🇬🇧 EN</option>
              </select>
            </div>
            <label style="font-size:12px;font-weight:600;color:rgba(255,255,255,.65);margin-bottom:5px;display:block"><?= e(t('subject_label')) ?> *</label>
            <input type="text" id="subj-<?= (int)$r['id'] ?>" placeholder="<?= e(t('subject_ph')) ?>"
                   style="width:100%;padding:10px 13px;font-size:14px;font-family:inherit;background:#000;border:1.5px solid rgba(255,255,255,.1);color:#fff">
          </div>

          <div class="field" style="margin-bottom:14px">
            <label style="font-size:12px;font-weight:600;color:rgba(255,255,255,.65);margin-bottom:5px;display:block"><?= e(t('message_label')) ?> *</label>
            <textarea id="body-<?= (int)$r['id'] ?>" name="body" rows="9"
                      style="width:100%;padding:12px 14px;font-size:14px;font-family:inherit;background:#000;border:1.5px solid rgba(255,255,255,.1);color:#fff;resize:vertical;line-height:1.65"
                      placeholder="<?= e(t('message_ph')) ?>"></textarea>
            <span style="font-size:11px;color:rgba(255,255,255,.65);margin-top:3px;display:block"><?= e(t('from_label')) ?> <strong style="color:rgba(255,255,255,.6)">office@foreningenfrontdoor.dk</strong> · <?= e(t('signature_auto_hint')) ?></span>
          </div>

          <div style="display:flex;gap:8px">
            <button class="btn btn-solid btn-sm" type="submit" onclick="finalizeEmail(<?= (int)$r['id'] ?>)"><?= e(t('send_btn')) ?></button>
            <button class="btn btn-ghost btn-xs" type="button" onclick="togglePanel(<?= (int)$r['id'] ?>,'email')"><?= e(t('cancel')) ?></button>
          </div>
        </form>
      </div>
      <?php endif; ?>

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

// Fiecare șablon are câte o variantă pentru fiecare limbă (da/ro/en).
// Adminul alege manual limba din selectorul de lângă șablon — nu păstrăm
// o limbă preferată per membru, se poate schimba la fiecare trimitere.
var tmpls = {
  confirm_received: {
    da: {
      subj: 'Foreningen Front Door — Vi har modtaget din ansøgning',
      body: function(n){ return 'Kære ' + n + ',\n\nTak for din ansøgning om medlemskab i Foreningen Front Door!\n\nVi har modtaget din henvendelse og vender tilbage inden for 5 hverdage.\n\nMed venlig hilsen'; }
    },
    ro: {
      subj: 'Foreningen Front Door — Am primit cererea ta',
      body: function(n){ return 'Bună, ' + n + ',\n\nMulțumim pentru cererea de membership la Foreningen Front Door!\n\nAm primit cererea ta și revenim în termen de 5 zile lucrătoare.\n\nCu drag,'; }
    },
    en: {
      subj: 'Foreningen Front Door — We’ve received your application',
      body: function(n){ return 'Dear ' + n + ',\n\nThank you for applying for membership at Foreningen Front Door!\n\nWe’ve received your application and will get back to you within 5 business days.\n\nBest regards,'; }
    }
  },
  confirm_active: {
    da: {
      subj: 'Foreningen Front Door — Velkommen som aktivt medlem',
      body: function(n){ return 'Kære ' + n + ',\n\nVi er glade for at bekræfte, at du nu er aktivt medlem af Foreningen Front Door. Velkommen!\n\nFølg os på Instagram (@frontdoor_dk) og Facebook for opdateringer om kommende arrangementer.\n\nMed venlig hilsen'; }
    },
    ro: {
      subj: 'Foreningen Front Door — Bine ai venit ca membru activ',
      body: function(n){ return 'Bună, ' + n + ',\n\nSuntem bucuroși să confirmăm că ești acum membru activ al Foreningen Front Door. Bine ai venit!\n\nUrmărește-ne pe Instagram (@frontdoor_dk) și Facebook pentru noutăți despre evenimentele viitoare.\n\nCu drag,'; }
    },
    en: {
      subj: 'Foreningen Front Door — Welcome as an active member',
      body: function(n){ return 'Dear ' + n + ',\n\nWe’re happy to confirm that you are now an active member of Foreningen Front Door. Welcome!\n\nFollow us on Instagram (@frontdoor_dk) and Facebook for updates on upcoming events.\n\nBest regards,'; }
    }
  },
  info_payment: {
    da: {
      subj: 'Foreningen Front Door — Oplysninger om kontingent',
      body: function(n){ return 'Kære ' + n + ',\n\nDet årlige kontingent er 260 DKK. Vi aftaler betalingen personligt.\n\nSkriv gerne tilbage, så finder vi en løsning der passer for dig.\n\nMed venlig hilsen'; }
    },
    ro: {
      subj: 'Foreningen Front Door — Informații despre cotizație',
      body: function(n){ return 'Bună, ' + n + ',\n\nCotizația anuală este de 260 DKK. Stabilim plata personal.\n\nScrie-ne oricând ca să găsim o soluție potrivită pentru tine.\n\nCu drag,'; }
    },
    en: {
      subj: 'Foreningen Front Door — Membership fee information',
      body: function(n){ return 'Dear ' + n + ',\n\nThe annual membership fee is 260 DKK. We arrange payment individually.\n\nFeel free to write back so we can find a solution that works for you.\n\nBest regards,'; }
    }
  },
  pending_info: {
    da: {
      subj: 'Foreningen Front Door — Angående din ansøgning',
      body: function(n){ return 'Kære ' + n + ',\n\nTak for din tålmodighed. Vi behandler fortsat din ansøgning og vender tilbage snarest.\n\nMed venlig hilsen'; }
    },
    ro: {
      subj: 'Foreningen Front Door — Referitor la cererea ta',
      body: function(n){ return 'Bună, ' + n + ',\n\nMulțumim pentru răbdare. Continuăm să procesăm cererea ta și revenim cât mai curând.\n\nCu drag,'; }
    },
    en: {
      subj: 'Foreningen Front Door — Regarding your application',
      body: function(n){ return 'Dear ' + n + ',\n\nThank you for your patience. We’re still processing your application and will get back to you soon.\n\nBest regards,'; }
    }
  },
  blank: {
    da: { subj: '', body: function(n){ return 'Kære ' + n + ',\n\n'; } },
    ro: { subj: '', body: function(n){ return 'Bună, ' + n + ',\n\n'; } },
    en: { subj: '', body: function(n){ return 'Dear ' + n + ',\n\n'; } }
  }
};

function applyTemplate(id, name) {
  var key  = document.getElementById('tmpl-' + id).value;
  var lang = document.getElementById('tmplLang-' + id).value || 'da';
  if (!key || !tmpls[key] || !tmpls[key][lang]) return;
  document.getElementById('subj-' + id).value = tmpls[key][lang].subj;
  document.getElementById('body-' + id).value = tmpls[key][lang].body(name);
}

function finalizeEmail(id) {
  var subj = document.getElementById('subj-' + id).value.trim();
  document.getElementById('subj-final-' + id).value = subj;
}
</script>
<?php layout_foot(); ?>
