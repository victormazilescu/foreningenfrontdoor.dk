<?php
require_once __DIR__ . '/auth.php';
$user = require_perm('topics', 'manage');
$pdo  = get_db();
ensure_meeting_bucket_schema($pdo);

$mid = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$stmt = $pdo->prepare('SELECT * FROM bf_meetings WHERE id=?');
$stmt->execute([$mid]); $meeting = $stmt->fetch();
if (!$meeting) { flash('error', t('no_open_meeting')); header('Location: /admin/topics.php'); exit; }
$is_council = ($meeting['type'] ?? 'generalforsamling') === 'consiliu';

$moderators = $pdo->query('SELECT id, name FROM bf_users WHERE active=1 ORDER BY name ASC')->fetchAll();

// ── ACȚIUNI POST ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'update_logistics') {
        $location   = trim($_POST['location'] ?? '');
        $date       = $_POST['date'] ?? '';
        $start_time = trim($_POST['start_time'] ?? '');
        $online     = trim($_POST['online_link'] ?? '');
        $pemail     = trim($_POST['proposals_email'] ?? '');
        $deadline   = trim($_POST['proposal_deadline'] ?? '');
        $confirmed  = isset($_POST['date_confirmed']) ? 1 : 0;

        $changed_after_send = !empty($meeting['convocation_sent_at']) && (
            $location !== $meeting['location'] || $date !== ($meeting['date'] ?? '') ||
            $start_time !== ($meeting['start_time'] ?? '') || $online !== ($meeting['online_link'] ?? '')
        );

        $pdo->prepare('UPDATE bf_meetings SET location=?, date=?, start_time=?, online_link=?, proposals_email=?, proposal_deadline=?, date_confirmed=? WHERE id=?')
            ->execute([$location ?: null, $date ?: null, $start_time ?: null, $online ?: null, $pemail ?: null, $deadline ?: null, $confirmed, $mid]);

        if ($changed_after_send) {
            flash('error', t('logistics_changed_after_send_warn'));
        } else {
            flash('ok', t('updated_short'));
        }
        header('Location: /admin/meeting-bucket.php?id=' . $mid); exit;
    }

    if ($action === 'toggle_bucket') {
        $pid = (int)($_POST['proposal_id'] ?? 0);
        if (!$meeting['agenda_locked']) {
            $p = $pdo->prepare('SELECT * FROM bf_proposals WHERE id=? AND meeting_id=?');
            $p->execute([$pid, $mid]); $p = $p->fetch();
            if ($p) {
                $new_state = $p['in_bucket'] ? 0 : 1;
                if ($new_state && (int)$p['est_minutes'] <= 0) {
                    $est = estimate_topic_minutes($p['description'] ?? '', $p['category'] ?? 'altul');
                    $pdo->prepare('UPDATE bf_proposals SET in_bucket=?, est_minutes=? WHERE id=?')->execute([$new_state, $est, $pid]);
                } else {
                    $pdo->prepare('UPDATE bf_proposals SET in_bucket=? WHERE id=?')->execute([$new_state, $pid]);
                }
            }
        }
        header('Location: /admin/meeting-bucket.php?id=' . $mid . '#bucket'); exit;
    }

    if ($action === 'update_item' && !$meeting['agenda_locked']) {
        $pid = (int)($_POST['proposal_id'] ?? 0);
        $maj = ($_POST['majority_type'] ?? 'simplu') === 'doua_treimi' ? 'doua_treimi' : 'simplu';
        $est = max(1, min(120, (int)($_POST['est_minutes'] ?? 5)));
        $mod = (int)($_POST['moderator_user_id'] ?? 0);
        $pdo->prepare('UPDATE bf_proposals SET majority_type=?, est_minutes=?, moderator_user_id=? WHERE id=? AND meeting_id=?')
            ->execute([$maj, $est, $mod ?: null, $pid, $mid]);
        flash('ok', t('updated_short'));
        header('Location: /admin/meeting-bucket.php?id=' . $mid . '#bucket'); exit;
    }

    if ($action === 'move_item' && !$meeting['agenda_locked']) {
        $pid = (int)($_POST['proposal_id'] ?? 0);
        $dir = $_POST['dir'] ?? '';
        $items = bucket_items($pdo, $mid);
        // Renumerotează secvențial ca să existe mereu o ordine distinctă
        // de swap, indiferent dacă bucket_order era încă 0 pentru toate.
        foreach ($items as $i => $it) {
            $pdo->prepare('UPDATE bf_proposals SET bucket_order=? WHERE id=?')->execute([$i, $it['id']]);
            $items[$i]['bucket_order'] = $i;
        }
        $idx = null;
        foreach ($items as $i => $it) { if ((int)$it['id'] === $pid) { $idx = $i; break; } }
        if ($idx !== null) {
            $swap = $dir === 'up' ? $idx - 1 : $idx + 1;
            if (isset($items[$swap])) {
                $pdo->prepare('UPDATE bf_proposals SET bucket_order=? WHERE id=?')->execute([$swap, $items[$idx]['id']]);
                $pdo->prepare('UPDATE bf_proposals SET bucket_order=? WHERE id=?')->execute([$idx, $items[$swap]['id']]);
            }
        }
        header('Location: /admin/meeting-bucket.php?id=' . $mid . '#bucket'); exit;
    }

    if ($action === 'vote_open') {
        $pid = (int)($_POST['proposal_id'] ?? 0);
        $already = $pdo->prepare("SELECT id FROM bf_proposals WHERE meeting_id=? AND vote_state='open' AND id<>?");
        $already->execute([$mid, $pid]);
        if ($already->fetch()) {
            flash('error', t('another_topic_open_error'));
        } else {
            $pdo->prepare("UPDATE bf_proposals SET vote_state='open', vote_opened_at=NOW() WHERE id=? AND meeting_id=?")->execute([$pid, $mid]);
        }
        header('Location: /admin/meeting-bucket.php?id=' . $mid . '#live'); exit;
    }

    if ($action === 'vote_close') {
        $pid = (int)($_POST['proposal_id'] ?? 0);
        $pdo->prepare("UPDATE bf_proposals SET vote_state='ended', vote_ended_at=NOW() WHERE id=? AND meeting_id=?")->execute([$pid, $mid]);
        header('Location: /admin/meeting-bucket.php?id=' . $mid . '#live'); exit;
    }

    if ($action === 'vote_reopen') {
        $pid = (int)($_POST['proposal_id'] ?? 0);
        $pdo->prepare('DELETE FROM bf_vote_ballots WHERE proposal_id=?')->execute([$pid]);
        $pdo->prepare('DELETE FROM bf_vote_participation WHERE proposal_id=?')->execute([$pid]);
        $pdo->prepare('DELETE FROM bf_council_votes WHERE proposal_id=?')->execute([$pid]);
        $pdo->prepare("UPDATE bf_proposals SET vote_state='closed', vote_opened_at=NULL, vote_ended_at=NULL WHERE id=? AND meeting_id=?")->execute([$pid, $mid]);
        flash('ok', t('updated_short'));
        header('Location: /admin/meeting-bucket.php?id=' . $mid . '#live'); exit;
    }

    // Vot nominal de consiliu — direct din panel, doar pentru bestyrelse
    // (nu revizor), doar cât timp subiectul e „open". Se poate schimba
    // (UPSERT), spre deosebire de votul anonim de la generalforsamling.
    if ($action === 'council_vote' && $is_council && council_can_vote($pdo, $mid, $user)) {
        $pid    = (int)($_POST['proposal_id'] ?? 0);
        $option = $_POST['option'] ?? '';
        if (in_array($option, ['da','nu','abtinere'], true)) {
            $p = $pdo->prepare("SELECT id FROM bf_proposals WHERE id=? AND meeting_id=? AND vote_state='open'");
            $p->execute([$pid, $mid]);
            if ($p->fetch()) {
                $pdo->prepare('INSERT INTO bf_council_votes (proposal_id, user_id, option) VALUES (?,?,?)
                                ON DUPLICATE KEY UPDATE option=VALUES(option), created_at=NOW()')
                    ->execute([$pid, $user['id'], $option]);
            }
        }
        header('Location: /admin/meeting-bucket.php?id=' . $mid . '#live'); exit;
    }

    if ($action === 'update_council_voters' && $is_council) {
        $consilier_ids = array_column(array_filter(board_members($pdo), fn($m) => $m['position'] === 'consilier'), 'id');
        $posted = array_map('intval', $_POST['voter_ids'] ?? []);
        $valid  = array_values(array_intersect($posted, $consilier_ids));
        set_council_voters($pdo, $mid, $valid);
        flash('ok', t('updated_short'));
        header('Location: /admin/meeting-bucket.php?id=' . $mid); exit;
    }

    if ($action === 'lock_agenda') {
        $pdo->prepare('UPDATE bf_meetings SET agenda_locked=1 WHERE id=?')->execute([$mid]);
        flash('ok', t('updated_short'));
        header('Location: /admin/meeting-bucket.php?id=' . $mid); exit;
    }

    if ($action === 'unlock_agenda') {
        $pdo->prepare('UPDATE bf_meetings SET agenda_locked=0 WHERE id=?')->execute([$mid]);
        header('Location: /admin/meeting-bucket.php?id=' . $mid); exit;
    }

    if ($action === 'send_convocation') {
        if (!$meeting['date_confirmed']) {
            flash('error', t('meeting_not_confirmed_error'));
        } elseif (empty($meeting['proposal_deadline'])) {
            flash('error', t('deadline_required_error'));
        } else {
            $res = send_convocation_email_to_all($pdo, $meeting);
            $pdo->prepare('UPDATE bf_meetings SET convocation_sent_at=NOW() WHERE id=?')->execute([$mid]);
            flash('ok', $res['failed']
                ? sprintf(t('emails_sent_partial'), $res['sent'], count($res['failed']))
                : sprintf(t('emails_sent_ok'), $res['sent']));
        }
        header('Location: /admin/meeting-bucket.php?id=' . $mid); exit;
    }

    if ($action === 'send_final') {
        $res = send_final_email_to_all($pdo, $meeting);
        $pdo->prepare('UPDATE bf_meetings SET final_sent_at=NOW(), agenda_locked=1 WHERE id=?')->execute([$mid]);
        flash('ok', $res['failed']
            ? sprintf(t('emails_sent_partial'), $res['sent'], count($res['failed']))
            : sprintf(t('emails_sent_ok'), $res['sent']));
        header('Location: /admin/meeting-bucket.php?id=' . $mid); exit;
    }

    if ($action === 'gen_late_code') {
        $member_id = (int)($_POST['member_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT id, name, email, email_secondary FROM membership_requests WHERE id=? AND status="active"');
        $stmt->execute([$member_id]); $m = $stmt->fetch();
        if ($m) {
            $c = $pdo->prepare('SELECT code FROM bf_vote_codes WHERE meeting_id=? AND member_id=?');
            $c->execute([$mid, $member_id]); $code = $c->fetchColumn();
            if (!$code) {
                do { $code = gen_vote_code(); $dup = $pdo->prepare('SELECT 1 FROM bf_vote_codes WHERE code=?'); $dup->execute([$code]); } while ($dup->fetch());
                $pdo->prepare('INSERT INTO bf_vote_codes (meeting_id,member_id,member_name,code) VALUES (?,?,?,?)')->execute([$mid, $member_id, $m['name'], $code]);
            }
            flash('ok', $m['name'] . ': ' . $code);
        }
        header('Location: /admin/meeting-bucket.php?id=' . $mid . '#late'); exit;
    }

    header('Location: /admin/meeting-bucket.php?id=' . $mid); exit;
}

// ── DATE ──────────────────────────────────────────────────────
$flash = get_flash();
$stmt = $pdo->prepare(
    'SELECT p.*, u.name as author_name FROM bf_proposals p JOIN bf_users u ON u.id=p.user_id
     WHERE p.meeting_id=? ORDER BY p.in_bucket DESC, p.bucket_order ASC, p.created_at ASC'
);
$stmt->execute([$mid]); $all_proposals = $stmt->fetchAll();
$items = array_values(array_filter($all_proposals, fn($p) => $p['in_bucket']));
$items_with_mod = bucket_items($pdo, $mid);
$active_members = assembly_active_recipients($pdo);
$cat_labels = ['administrativ'=>t('cat_administrativ'),'proiecte'=>t('cat_proiecte'),'financiar'=>t('cat_financiar'),'cultural'=>t('cat_cultural'),'societate'=>t('cat_societate'),'artistic'=>t('cat_artistic'),'altul'=>t('cat_altul')];

// tally curent pentru fiecare item din bucket
$tallies = [];
foreach ($items_with_mod as $it) {
    $t_stmt = $pdo->prepare("SELECT option, COUNT(*) c FROM bf_vote_ballots WHERE proposal_id=? GROUP BY option");
    $t_stmt->execute([$it['id']]);
    $tally = ['da'=>0,'nu'=>0,'abtinere'=>0];
    foreach ($t_stmt->fetchAll() as $row) { $tally[$row['option']] = (int)$row['c']; }
    $tallies[$it['id']] = $tally;
}

layout_head(t('bucket_h1') . ' — ' . $meeting['title'], 'topics');
?>
<div class="content">
  <?php if ($flash): ?><div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>

  <div style="margin-bottom:16px">
    <a href="/admin/topics.php?meeting=<?= $mid ?>" style="font-size:13px;color:rgba(255,255,255,.6)"><?= e(t('bucket_back_to_topics')) ?></a>
  </div>
  <div class="page-head">
    <div>
      <h1><?= e($meeting['title']) ?></h1>
      <div style="font-size:13px;color:rgba(255,255,255,.65);margin-top:4px">
        <?= $meeting['date'] ? e(date('d.m.Y',strtotime($meeting['date']))) : '—' ?>
        <?php if ($meeting['start_time']): ?>, <?= e($meeting['start_time']) ?><?php endif; ?>
        <?php if ($meeting['location']): ?> · <?= e($meeting['location']) ?><?php endif; ?>
      </div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <a class="btn btn-ghost btn-sm" href="/admin/agenda-print.php?id=<?= $mid ?>" target="_blank"><?= e(t('agenda_preview_btn')) ?></a>
      <a class="btn btn-ghost btn-sm" href="/admin/referat-edit.php?meeting=<?= $mid ?>"><?= e(t('referat_btn')) ?></a>
    </div>
  </div>

  <!-- ── LOGISTICĂ ── -->
  <div class="form-section">
    <p class="section-label"><?= e(t('bucket_logistics_h')) ?></p>
    <?php if ($meeting['agenda_locked']): ?>
      <div class="flash flash-error" style="margin-bottom:14px"><?= e(t('agenda_locked_notice')) ?>
        <form method="post" style="display:inline;margin-left:10px">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="unlock_agenda">
          <button class="btn btn-ghost btn-xs" type="submit"><?= e(t('unlock_agenda_btn')) ?></button>
        </form>
      </div>
    <?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="update_logistics">
      <div class="grid-2" style="margin-bottom:14px">
        <div class="field">
          <label><?= e(t('bucket_location_label')) ?></label>
          <input type="text" name="location" value="<?= e($meeting['location'] ?? '') ?>">
        </div>
        <div class="field">
          <label><?= e(t('online_link_label')) ?></label>
          <input type="url" name="online_link" placeholder="https://..." value="<?= e($meeting['online_link'] ?? '') ?>">
        </div>
      </div>
      <div class="grid-3" style="margin-bottom:14px">
        <div class="field">
          <label><?= e(t('bucket_date_label')) ?></label>
          <input type="date" name="date" value="<?= e($meeting['date'] ?? '') ?>">
        </div>
        <div class="field">
          <label><?= e(t('bucket_time_label')) ?></label>
          <input type="time" name="start_time" value="<?= e($meeting['start_time'] ?? '') ?>">
        </div>
        <div class="field">
          <label><?= e(t('proposal_deadline_label')) ?></label>
          <input type="datetime-local" name="proposal_deadline" value="<?= e($meeting['proposal_deadline'] ? str_replace(' ','T',substr($meeting['proposal_deadline'],0,16)) : '') ?>">
        </div>
      </div>
      <div class="field" style="margin-bottom:14px">
        <label><?= e(t('proposals_email_label')) ?></label>
        <input type="email" name="proposals_email" placeholder="office@foreningenfrontdoor.dk" value="<?= e($meeting['proposals_email'] ?? '') ?>">
      </div>
      <label class="check-row" style="margin-bottom:16px">
        <input type="checkbox" name="date_confirmed" value="1" <?= $meeting['date_confirmed'] ? 'checked' : '' ?>>
        <?= e(t('date_confirmed_checkbox')) ?>
      </label>
      <button class="btn btn-solid btn-sm" type="submit"><?= e(t('save_logistics_btn')) ?></button>
    </form>
  </div>

  <!-- ── VOTANȚI DE CONSILIU (per ședință) ── -->
  <?php if ($is_council):
    $council_consilieri = array_values(array_filter(board_members($pdo), fn($m) => $m['position'] === 'consilier'));
    $council_permanent  = array_values(array_filter(board_members($pdo), fn($m) => $m['position'] !== 'consilier'));
    $council_revizori   = revisor_members($pdo);
    $selected_voter_ids = council_voter_ids($pdo, $mid);
  ?>
  <div class="form-section">
    <p class="section-label"><?= e(t('council_voters_h')) ?></p>
    <p style="font-size:13px;color:rgba(255,255,255,.6);margin-bottom:16px"><?= e(t('council_voters_sub')) ?></p>
    <div style="display:flex;flex-direction:column;gap:6px;margin-bottom:14px">
      <?php foreach ($council_permanent as $m): ?>
        <div style="font-size:13px;color:rgba(255,255,255,.7)"><?= e($m['name']) ?> — <span style="font-size:11px;color:rgba(255,255,255,.45)"><?= e(t('council_permanent_voters_label')) ?></span></div>
      <?php endforeach; ?>
      <?php foreach ($council_revizori as $m): ?>
        <div style="font-size:13px;color:rgba(255,255,255,.7)"><?= e($m['name']) ?> — <span style="font-size:11px;color:rgba(255,180,80,.7)"><?= e(t('council_observer_badge')) ?></span></div>
      <?php endforeach; ?>
    </div>
    <?php if ($council_consilieri): ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="update_council_voters">
      <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:14px">
        <?php foreach ($council_consilieri as $m): ?>
          <label class="check-row" style="gap:8px">
            <input type="checkbox" name="voter_ids[]" value="<?= (int)$m['id'] ?>" <?= in_array($m['id'], $selected_voter_ids, true) ? 'checked' : '' ?>>
            <?= e($m['name']) ?>
          </label>
        <?php endforeach; ?>
      </div>
      <button class="btn btn-solid btn-sm" type="submit"><?= e(t('council_voters_save_btn')) ?></button>
    </form>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- ── BUCKET ── -->
  <div class="form-section" id="bucket">
    <p class="section-label"><?= e(t('bucket_topics_h')) ?></p>
    <p style="font-size:13px;color:rgba(255,255,255,.6);margin-bottom:16px"><?= e(t('bucket_topics_sub')) ?></p>

    <?php if (empty($all_proposals)): ?>
      <div class="empty"><?= e(t('no_proposals_for_bucket')) ?></div>
    <?php else: ?>
    <div style="display:flex;flex-direction:column;gap:10px">
      <?php foreach ($all_proposals as $p): $inB = (bool)$p['in_bucket']; ?>
      <div style="background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:14px 16px">
        <div style="display:flex;align-items:flex-start;gap:12px;flex-wrap:wrap">
          <form method="post" style="flex-shrink:0">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="toggle_bucket">
            <input type="hidden" name="proposal_id" value="<?= (int)$p['id'] ?>">
            <label class="check-row" style="gap:8px">
              <input type="checkbox" onchange="this.form.submit()" <?= $inB ? 'checked' : '' ?> <?= $meeting['agenda_locked'] ? 'disabled' : '' ?>>
              <?= e(t('in_bucket_checkbox')) ?>
            </label>
          </form>
          <div style="flex:1;min-width:200px">
            <div style="font-size:14px;font-weight:600"><?= e(proposal_title($p)) ?></div>
            <div style="font-size:12px;color:rgba(255,255,255,.5)"><?= e($cat_labels[$p['category']] ?? $p['category']) ?> · <?= e($p['author_name']) ?></div>
          </div>
          <?php if ($inB): ?>
          <form method="post" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="update_item">
            <input type="hidden" name="proposal_id" value="<?= (int)$p['id'] ?>">
            <div class="field">
              <label style="font-size:10px"><?= e(t('majority_type_label')) ?></label>
              <select name="majority_type" style="font-size:12px;padding:6px 10px" <?= $meeting['agenda_locked'] ? 'disabled' : '' ?>>
                <option value="simplu" <?= $p['majority_type']==='simplu'?'selected':'' ?>><?= e(t('majority_simple')) ?></option>
                <option value="doua_treimi" <?= $p['majority_type']==='doua_treimi'?'selected':'' ?>><?= e(t('majority_2_3')) ?></option>
              </select>
            </div>
            <div class="field">
              <label style="font-size:10px"><?= e(t('est_minutes_label')) ?></label>
              <input type="number" name="est_minutes" min="1" max="120" value="<?= (int)$p['est_minutes'] ?>" style="width:64px;font-size:12px;padding:6px 10px" <?= $meeting['agenda_locked'] ? 'disabled' : '' ?>>
            </div>
            <div class="field">
              <label style="font-size:10px"><?= e(t('moderator_label')) ?></label>
              <select name="moderator_user_id" style="font-size:12px;padding:6px 10px" <?= $meeting['agenda_locked'] ? 'disabled' : '' ?>>
                <option value=""><?= e(t('moderator_none')) ?></option>
                <?php foreach ($moderators as $mo): ?>
                  <option value="<?= (int)$mo['id'] ?>" <?= (int)$p['moderator_user_id']===(int)$mo['id']?'selected':'' ?>><?= e($mo['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <?php if (!$meeting['agenda_locked']): ?><button class="btn btn-ghost btn-xs" type="submit"><?= e(t('save')) ?></button><?php endif; ?>
          </form>
          <?php if (!$meeting['agenda_locked']): ?>
          <div style="display:flex;gap:4px">
            <form method="post"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="move_item"><input type="hidden" name="dir" value="up"><input type="hidden" name="proposal_id" value="<?= (int)$p['id'] ?>"><button class="btn btn-ghost btn-xs" type="submit"><?= e(t('move_up_btn')) ?></button></form>
            <form method="post"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="move_item"><input type="hidden" name="dir" value="down"><input type="hidden" name="proposal_id" value="<?= (int)$p['id'] ?>"><button class="btn btn-ghost btn-xs" type="submit"><?= e(t('move_down_btn')) ?></button></form>
          </div>
          <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!$meeting['agenda_locked'] && $items): ?>
      <form method="post" style="margin-top:18px" onsubmit="return confirm('<?= e(t('lock_agenda_btn')) ?>?')">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="lock_agenda">
        <button class="btn btn-warn btn-sm" type="submit"><?= e(t('lock_agenda_btn')) ?></button>
      </form>
    <?php endif; ?>
  </div>

  <!-- ── EMAILURI ── -->
  <div class="form-section">
    <p class="section-label"><?= e(t('nav_topics')) ?> — Email</p>
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
      <form method="post" onsubmit="return confirm('?')">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="send_convocation">
        <button class="btn btn-solid btn-sm" type="submit"><?= $meeting['convocation_sent_at'] ? e(t('resend_convocation_btn')) : ($is_council ? e(t('send_convocation_council_btn')) : e(t('send_convocation_btn'))) ?></button>
      </form>
      <?php if ($meeting['convocation_sent_at']): ?>
        <span style="font-size:12px;color:rgba(255,255,255,.5)"><?= e(t('convocation_sent_label')) ?>: <?= e(date('d.m.Y H:i',strtotime($meeting['convocation_sent_at']))) ?></span>
      <?php endif; ?>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:12px">
      <form method="post" onsubmit="return confirm('?')">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="send_final">
        <button class="btn btn-solid btn-sm" type="submit"><?= $meeting['final_sent_at'] ? e(t('resend_final_btn')) : ($is_council ? e(t('send_final_council_btn')) : e(t('send_final_btn'))) ?></button>
      </form>
      <?php if ($meeting['final_sent_at']): ?>
        <span style="font-size:12px;color:rgba(255,255,255,.5)"><?= e(t('final_sent_label')) ?>: <?= e(date('d.m.Y H:i',strtotime($meeting['final_sent_at']))) ?></span>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── VOT LIVE ── -->
  <?php if ($items_with_mod): ?>
  <div class="form-section" id="live">
    <p class="section-label"><?= $is_council ? e(t('council_vote_h')) : e(t('live_vote_h')) ?></p>
    <p style="font-size:13px;color:rgba(255,255,255,.6);margin-bottom:16px"><?= $is_council ? e(t('council_vote_sub')) : e(t('live_vote_sub')) ?></p>
    <?php if ($is_council && !council_can_vote($pdo, $mid, $user)): ?>
      <p style="font-size:12px;color:rgba(255,180,80,.85);margin-bottom:16px"><?= e(t('not_board_member_notice')) ?></p>
    <?php endif; ?>
    <div style="display:flex;flex-direction:column;gap:10px">
      <?php foreach ($items_with_mod as $it):
        $badge_color = $it['vote_state']==='open' ? 'rgba(60,150,60,.9)' : ($it['vote_state']==='ended' ? 'rgba(255,255,255,.5)' : 'rgba(255,255,255,.3)');
        if ($is_council) {
            $ct = council_tally($pdo, (int)$it['id']);
            $tally = $ct['tally'];
            $my_vote = null;
            foreach ($ct['votes'] as $v) { if ((int)$v['user_id'] === (int)$user['id']) { $my_vote = $v['option']; break; } }
            $result = council_result($tally, $ct['president_option']);
            $is_tie = ($it['vote_state']==='ended' && $tally['da'] === $tally['nu'] && ($tally['da']+$tally['nu']) > 0);
        } else {
            $tally = $tallies[$it['id']];
            $passed = majority_passed($tally['da'], $tally['nu'], $it['majority_type']);
        }
      ?>
      <div style="background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:14px 16px">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
          <div>
            <span style="font-weight:600;font-size:14px"><?= e(proposal_title($it)) ?></span>
            <span style="font-size:11px;font-weight:700;letter-spacing:.08em;color:<?= $badge_color ?>;margin-left:8px"><?= e(t('vote_state_'.$it['vote_state'])) ?></span>
          </div>
          <div style="display:flex;gap:8px">
            <?php if ($it['vote_state']==='closed'): ?>
              <form method="post"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="vote_open"><input type="hidden" name="proposal_id" value="<?= (int)$it['id'] ?>"><button class="btn btn-green btn-xs" type="submit"><?= e(t('open_vote_btn')) ?></button></form>
            <?php elseif ($it['vote_state']==='open'): ?>
              <form method="post"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="vote_close"><input type="hidden" name="proposal_id" value="<?= (int)$it['id'] ?>"><button class="btn btn-warn btn-xs" type="submit"><?= e(t('close_vote_btn')) ?></button></form>
            <?php else: ?>
              <form method="post" onsubmit="return confirm('?')"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="vote_reopen"><input type="hidden" name="proposal_id" value="<?= (int)$it['id'] ?>"><button class="btn btn-ghost btn-xs" type="submit"><?= e(t('reopen_vote_btn')) ?></button></form>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($is_council): ?>
          <?php if ($it['vote_state']==='open' && council_can_vote($pdo, $mid, $user)): ?>
          <div style="display:flex;gap:8px;margin-top:12px;align-items:center">
            <span style="font-size:11px;color:rgba(255,255,255,.5)"><?= e(t('your_vote_label')) ?>:</span>
            <?php foreach (['da'=>'btn-green','nu'=>'btn-danger','abtinere'=>'btn-ghost'] as $opt=>$cls): ?>
            <form method="post"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="council_vote"><input type="hidden" name="proposal_id" value="<?= (int)$it['id'] ?>"><input type="hidden" name="option" value="<?= $opt ?>">
              <button class="btn <?= $my_vote===$opt ? 'btn-solid' : $cls ?> btn-xs" type="submit"><?= e(t('votes_'.$opt)) ?></button>
            </form>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
          <?php if ($it['vote_state'] !== 'closed'): ?>
          <div style="margin-top:12px;font-size:13px">
            <div style="display:flex;gap:18px;margin-bottom:8px">
              <span><?= e(t('votes_da')) ?>: <strong><?= $tally['da'] ?></strong></span>
              <span><?= e(t('votes_nu')) ?>: <strong><?= $tally['nu'] ?></strong></span>
              <span><?= e(t('votes_abtinere')) ?>: <strong><?= $tally['abtinere'] ?></strong></span>
            </div>
            <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px">
              <?php foreach ($ct['votes'] as $v): ?>
                <span style="font-size:11px;padding:3px 9px;border:1px solid rgba(255,255,255,.12);border-radius:999px;color:rgba(255,255,255,.6)"><?= e($v['name']) ?>: <?= e(t('votes_'.$v['option'])) ?></span>
              <?php endforeach; ?>
            </div>
            <?php if ($it['vote_state']==='ended'): ?>
              <?php if ($is_tie): ?>
                <div style="font-size:12px;color:rgba(255,180,80,.85);margin-bottom:4px"><?= e(t('tie_president_decides')) ?> — <?= $ct['president_option'] ? e(t('president_voted_prefix')).' '.e(t('votes_'.$ct['president_option'])) : e(t('president_not_voted_yet')) ?></div>
              <?php endif; ?>
              <span style="font-weight:700;color:<?= $result===true?'rgba(120,200,120,.9)':($result===false?'rgba(255,120,120,.9)':'rgba(255,255,255,.5)') ?>">
                <?= $result===null ? e(t('result_pending')) : ($result ? e(t('result_passed')) : e(t('result_rejected'))) ?>
              </span>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        <?php else: ?>
          <?php if ($it['vote_state'] !== 'closed'): ?>
          <div style="display:flex;gap:18px;margin-top:10px;font-size:13px">
            <span><?= e(t('votes_da')) ?>: <strong><?= $tally['da'] ?></strong></span>
            <span><?= e(t('votes_nu')) ?>: <strong><?= $tally['nu'] ?></strong></span>
            <span><?= e(t('votes_abtinere')) ?>: <strong><?= $tally['abtinere'] ?></strong></span>
            <?php if ($it['vote_state']==='ended'): ?>
              <span style="font-weight:700;color:<?= $passed===true?'rgba(120,200,120,.9)':($passed===false?'rgba(255,120,120,.9)':'rgba(255,255,255,.5)') ?>">
                <?= $passed===null ? e(t('result_pending')) : ($passed ? e(t('result_passed')) : e(t('result_rejected'))) ?>
              </span>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── COD PENTRU MEMBRU TÂRZIU ── -->
  <?php if (!$is_council): ?>
  <div class="form-section" id="late">
    <p class="section-label"><?= e(t('late_code_h')) ?></p>
    <form method="post" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="gen_late_code">
      <div class="field" style="min-width:220px">
        <label><?= e(t('late_code_select_label')) ?></label>
        <select name="member_id">
          <?php foreach ($active_members as $m): ?>
            <option value="<?= (int)$m['id'] ?>"><?= e($m['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="btn btn-solid btn-sm" type="submit"><?= e(t('gen_code_btn')) ?></button>
    </form>
  </div>
  <?php endif; ?>

</div>
<?php layout_foot(); ?>
