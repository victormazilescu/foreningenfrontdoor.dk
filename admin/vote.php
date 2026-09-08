<?php
require_once __DIR__ . '/auth.php';
$pdo = get_db();
ensure_meeting_bucket_schema($pdo);
ensure_membership_lang_column($pdo);
$cur_lang = ui_lang();

$code = trim($_GET['cod'] ?? $_POST['cod'] ?? '');
$code = strtoupper($code);
$error = '';
$voter = null;
$meeting = null;

if ($code) {
    $stmt = $pdo->prepare('SELECT * FROM bf_vote_codes WHERE code=?');
    $stmt->execute([$code]); $voter = $stmt->fetch();
    if ($voter) {
        $m = $pdo->prepare('SELECT * FROM bf_meetings WHERE id=?');
        $m->execute([$voter['meeting_id']]); $meeting = $m->fetch();
    }
    if (!$voter || !$meeting) {
        $error = t('vote_invalid_code');
        $code = ''; $voter = null; $meeting = null;
    } else {
        // Odată identificat prin cod, pagina trece automat pe limba lui de
        // profil — nu pe limba browserului/sesiunii — indiferent cine a
        // deschis linkul înainte.
        $ml = $pdo->prepare('SELECT lang FROM membership_requests WHERE id=?');
        $ml->execute([$voter['member_id']]);
        $mlang = $ml->fetchColumn();
        $cur_lang = in_array($mlang, ['ro','da','en'], true) ? $mlang : $cur_lang;
    }
}

// ── vot POST ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cast_vote' && $voter && $meeting) {
    csrf_verify();
    $pid    = (int)($_POST['proposal_id'] ?? 0);
    $option = $_POST['option'] ?? '';
    if (in_array($option, ['da','nu','abtinere'], true)) {
        $p = $pdo->prepare("SELECT * FROM bf_proposals WHERE id=? AND meeting_id=? AND vote_state='open'");
        $p->execute([$pid, $meeting['id']]); $p = $p->fetch();
        if ($p) {
            $already = $pdo->prepare('SELECT 1 FROM bf_vote_participation WHERE proposal_id=? AND code=?');
            $already->execute([$pid, $voter['code']]);
            if (!$already->fetch()) {
                $pdo->prepare('INSERT INTO bf_vote_participation (proposal_id, code) VALUES (?,?)')->execute([$pid, $voter['code']]);
                $pdo->prepare('INSERT INTO bf_vote_ballots (proposal_id, option) VALUES (?,?)')->execute([$pid, $option]);
            }
        }
    }
    header('Location: /admin/vote.php?cod=' . urlencode($code) . '#p' . $pid); exit;
}

$open_topic = null; $ended_topics = []; $closed_topics = []; $my_votes = [];
if ($meeting) {
    $items = bucket_items($pdo, (int)$meeting['id']);
    foreach ($items as $it) {
        $t_stmt = $pdo->prepare('SELECT 1 FROM bf_vote_participation WHERE proposal_id=? AND code=?');
        $t_stmt->execute([$it['id'], $voter['code']]);
        $my_votes[$it['id']] = (bool)$t_stmt->fetch();

        if ($it['vote_state'] === 'open') $open_topic = $it;
        elseif ($it['vote_state'] === 'ended') $ended_topics[] = $it;
        else $closed_topics[] = $it;
    }
}

function vt_tally(PDO $pdo, int $pid): array {
    $t = $pdo->prepare('SELECT option, COUNT(*) c FROM bf_vote_ballots WHERE proposal_id=? GROUP BY option');
    $t->execute([$pid]);
    $out = ['da'=>0,'nu'=>0,'abtinere'=>0];
    foreach ($t->fetchAll() as $r) $out[$r['option']] = (int)$r['c'];
    return $out;
}
?>
<!DOCTYPE html>
<html lang="<?= e($cur_lang) ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e(t('vote_page_title', $cur_lang)) ?></title>
<?php if ($voter && $meeting): ?><meta http-equiv="refresh" content="15"><?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@700;900&family=Jost:wght@300;400;500&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Jost',system-ui,sans-serif;background-color:#000;background-image:radial-gradient(circle at 15% -10%,rgba(255,255,255,.1),transparent 45%),radial-gradient(circle at 100% 10%,rgba(255,255,255,.06),transparent 40%);background-attachment:fixed;color:#fff;min-height:100vh;padding:24px;-webkit-font-smoothing:antialiased}
.wrap{max-width:520px;margin:0 auto}
h1{font-family:'Nunito',sans-serif;font-weight:900;font-size:22px;margin-bottom:6px}
.sub{font-size:13px;color:rgba(255,255,255,.5);margin-bottom:24px}
.card{background:rgba(255,255,255,.03);backdrop-filter:blur(18px) saturate(140%);-webkit-backdrop-filter:blur(18px) saturate(140%);border:1px solid rgba(255,255,255,.09);border-radius:18px;padding:24px;margin-bottom:16px;box-shadow:0 10px 30px rgba(0,0,0,.3)}
label{display:block;font-size:11px;font-weight:500;letter-spacing:.1em;text-transform:uppercase;color:rgba(255,255,255,.45);margin-bottom:7px}
input[type=text]{width:100%;padding:12px 15px;font-size:20px;letter-spacing:.2em;text-align:center;font-family:'Nunito',sans-serif;font-weight:900;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.16);border-radius:12px;color:#fff;margin-bottom:14px;text-transform:uppercase}
button{width:100%;padding:14px;background:#fff;color:#000;border:none;border-radius:999px;font-family:'Nunito',sans-serif;font-size:14px;font-weight:900;letter-spacing:.04em;cursor:pointer}
button:hover{opacity:.88}
.err{border:1px solid rgba(200,50,50,.35);color:rgba(255,150,150,.9);background:rgba(200,50,50,.08);border-radius:12px;padding:12px 15px;font-size:13px;margin-bottom:18px}
.badge-open{display:inline-block;padding:3px 10px;font-size:10px;font-weight:700;letter-spacing:.1em;color:#000;background:#fff;border-radius:999px;margin-bottom:10px}
.vote-opt{display:flex;flex-direction:column;gap:10px;margin-top:16px}
.vote-opt button{border-radius:12px}
.btn-da{background:rgba(80,180,80,.9)}
.btn-nu{background:rgba(200,70,70,.9)}
.btn-abt{background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.3)}
.tally{display:flex;gap:16px;margin-top:12px;font-size:13px;color:rgba(255,255,255,.7)}
.small{font-size:12px;color:rgba(255,255,255,.45)}
.topic-title{font-size:15px;font-weight:600;margin-bottom:4px}
a{color:rgba(255,255,255,.6)}
</style>
</head>
<body>
<div class="wrap">

<?php if (!$voter || !$meeting): ?>
  <h1><?= e(t('vote_page_title', $cur_lang)) ?></h1>
  <?php if ($error): ?><div class="err"><?= e($error) ?></div><?php endif; ?>
  <div class="card">
    <form method="get">
      <label><?= e(t('vote_code_prompt', $cur_lang)) ?></label>
      <input type="text" name="cod" maxlength="8" autofocus required placeholder="XXXXXXXX">
      <button type="submit"><?= e(t('vote_code_submit_btn', $cur_lang)) ?></button>
    </form>
  </div>

<?php else: ?>
  <h1><?= e(t('vote_welcome_prefix', $cur_lang)) ?>, <?= e($voter['member_name'] ?: '') ?></h1>
  <p class="sub"><?= e($meeting['title']) ?><?php if ($meeting['date']): ?> · <?= e(date('d.m.Y',strtotime($meeting['date']))) ?><?php endif; ?></p>

  <?php if ($open_topic): $tally = vt_tally($pdo, $open_topic['id']); $voted = $my_votes[$open_topic['id']]; $od = proposal_description($open_topic, $cur_lang); ?>
  <div class="card" id="p<?= (int)$open_topic['id'] ?>">
    <span class="badge-open"><?= e(t('vote_state_open', $cur_lang)) ?></span>
    <div class="topic-title"><?= e(proposal_title($open_topic, $cur_lang)) ?></div>
    <?php if ($od): ?><p class="small"><?= nl2br(e($od)) ?></p><?php endif; ?>

    <?php if ($voted): ?>
      <p style="margin-top:16px;font-weight:600"><?= e(t('vote_already_cast', $cur_lang)) ?></p>
    <?php else: ?>
      <p style="margin-top:14px"><?= e(t('vote_cast_prompt', $cur_lang)) ?></p>
      <div class="vote-opt">
        <form method="post"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="cod" value="<?= e($code) ?>"><input type="hidden" name="action" value="cast_vote"><input type="hidden" name="proposal_id" value="<?= (int)$open_topic['id'] ?>"><input type="hidden" name="option" value="da"><button class="btn-da" type="submit"><?= e(t('votes_da', $cur_lang)) ?></button></form>
        <form method="post"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="cod" value="<?= e($code) ?>"><input type="hidden" name="action" value="cast_vote"><input type="hidden" name="proposal_id" value="<?= (int)$open_topic['id'] ?>"><input type="hidden" name="option" value="nu"><button class="btn-nu" type="submit"><?= e(t('votes_nu', $cur_lang)) ?></button></form>
        <form method="post"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="cod" value="<?= e($code) ?>"><input type="hidden" name="action" value="cast_vote"><input type="hidden" name="proposal_id" value="<?= (int)$open_topic['id'] ?>"><input type="hidden" name="option" value="abtinere"><button class="btn-abt" type="submit"><?= e(t('votes_abtinere', $cur_lang)) ?></button></form>
      </div>
    <?php endif; ?>
  </div>
  <?php else: ?>
  <div class="card"><p class="small"><?= e(t('vote_no_topic_open', $cur_lang)) ?></p></div>
  <?php endif; ?>

  <?php if ($ended_topics): ?>
  <h2 style="font-size:13px;letter-spacing:.14em;text-transform:uppercase;color:rgba(255,255,255,.5);margin:20px 0 10px"><?= e(t('vote_ended_topics_h', $cur_lang)) ?></h2>
  <?php foreach ($ended_topics as $it): $tally = vt_tally($pdo, $it['id']); $passed = majority_passed($tally['da'],$tally['nu'],$it['majority_type']); ?>
  <div class="card" id="p<?= (int)$it['id'] ?>">
    <div class="topic-title"><?= e(proposal_title($it, $cur_lang)) ?></div>
    <div class="tally">
      <span><?= e(t('votes_da', $cur_lang)) ?>: <strong><?= $tally['da'] ?></strong></span>
      <span><?= e(t('votes_nu', $cur_lang)) ?>: <strong><?= $tally['nu'] ?></strong></span>
      <span><?= e(t('votes_abtinere', $cur_lang)) ?>: <strong><?= $tally['abtinere'] ?></strong></span>
    </div>
    <p style="margin-top:8px;font-weight:700;color:<?= $passed===true?'rgba(120,200,120,.9)':($passed===false?'rgba(255,120,120,.9)':'rgba(255,255,255,.5)') ?>">
      <?= $passed===null ? e(t('result_pending', $cur_lang)) : ($passed ? e(t('result_passed', $cur_lang)) : e(t('result_rejected', $cur_lang))) ?>
    </p>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>

  <?php if ($closed_topics): ?>
  <h2 style="font-size:13px;letter-spacing:.14em;text-transform:uppercase;color:rgba(255,255,255,.5);margin:20px 0 10px"><?= e(t('vote_upcoming_h', $cur_lang)) ?></h2>
  <?php foreach ($closed_topics as $it): ?>
    <div class="card" style="padding:14px 18px"><span class="small"><?= e(proposal_title($it, $cur_lang)) ?></span></div>
  <?php endforeach; ?>
  <?php endif; ?>

  <p style="margin-top:20px"><a href="/admin/vote.php" class="small"><?= e(t('change_code_btn', $cur_lang)) ?></a></p>
<?php endif; ?>

</div>
</body>
</html>
