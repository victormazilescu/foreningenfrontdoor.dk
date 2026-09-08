<?php
require_once __DIR__ . '/auth.php';
$user = require_perm('topics', 'manage');
$pdo  = get_db();
ensure_meeting_bucket_schema($pdo);
$cur_lang = array_key_exists($_GET['lang'] ?? '', UI_LANGS) ? $_GET['lang'] : ui_lang();

$mid = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$stmt = $pdo->prepare('SELECT * FROM bf_meetings WHERE id=?');
$stmt->execute([$mid]); $meeting = $stmt->fetch();
if (!$meeting) { header('Location: /admin/topics.php'); exit; }

$items = agenda_with_times(bucket_items($pdo, $mid), $meeting['start_time'] ?? null);
$final = (bool)$meeting['agenda_locked'];
?>
<!DOCTYPE html>
<html lang="<?= e($cur_lang) ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e(t('agenda_print_title')) ?> — <?= e($meeting['title']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@700;900&family=Jost:wght@300;400;500&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Jost',system-ui,sans-serif;color:#111;padding:40px;max-width:820px;margin:0 auto}
h1{font-family:'Nunito',sans-serif;font-weight:900;font-size:24px;margin-bottom:4px}
.sub{font-size:13px;color:#555;margin-bottom:6px}
.meta{font-size:13px;color:#333;margin-bottom:22px;line-height:1.7}
table{width:100%;border-collapse:collapse;margin-top:10px}
th{text-align:left;padding:10px 8px;font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#555;border-bottom:2px solid #111}
td{padding:10px 8px;border-bottom:1px solid #ddd;font-size:14px;vertical-align:top}
.maj{font-size:11px;color:#888}
.toolbar{margin-bottom:24px}
button{font-family:'Nunito',sans-serif;font-weight:700;padding:9px 18px;border-radius:8px;border:1px solid #111;background:#111;color:#fff;cursor:pointer;font-size:13px}
.footer{margin-top:30px;font-size:11px;color:#888}
.draft-flag{display:inline-block;padding:2px 10px;font-size:10px;font-weight:700;letter-spacing:.1em;border:1px solid #c80;color:#c80;border-radius:999px;margin-left:8px}
@media print{.toolbar{display:none}}
</style>
</head>
<body>
<div class="toolbar">
  <button onclick="window.print()"><?= e(t('print_btn', $cur_lang)) ?></button>
  <span style="margin-left:12px;font-size:12px">
    <?php foreach (UI_LANGS as $lc => $lname): $qs = $_GET; $qs['lang'] = $lc; ?>
      <a href="?<?= e(http_build_query($qs)) ?>" style="margin-right:8px;<?= $cur_lang===$lc?'font-weight:700':'color:#888' ?>"><?= strtoupper($lc) ?></a>
    <?php endforeach; ?>
  </span>
</div>
<h1><?= e($meeting['title']) ?> <?php if (!$final): ?><span class="draft-flag">DRAFT</span><?php endif; ?></h1>
<div class="meta">
  <?= e(t('export_md_date_label', $cur_lang)) ?>: <?= $meeting['date'] ? e(date('d.m.Y',strtotime($meeting['date']))) : '—' ?><?php if ($meeting['start_time']): ?>, <?= e($meeting['start_time']) ?><?php endif; ?><br>
  <?= e(t('export_md_location_label', $cur_lang)) ?>: <?= e($meeting['location'] ?: '—') ?><br>
  <?php if ($meeting['online_link']): ?><?= e(t('online_link_label', $cur_lang)) ?>: <?= e($meeting['online_link']) ?><br><?php endif; ?>
</div>

<table>
  <tr>
    <th>#</th>
    <th><?= e(t('export_md_agenda_col_topic', $cur_lang)) ?></th>
    <th><?= e(t('export_md_agenda_col_time', $cur_lang)) ?></th>
    <th><?= e(t('export_md_agenda_col_moderator', $cur_lang)) ?></th>
  </tr>
  <?php $i=1; foreach ($items as $it): ?>
  <tr>
    <td><?= $i ?></td>
    <td><?= e(proposal_title($it, $cur_lang)) ?><br><span class="maj"><?= $it['majority_type']==='doua_treimi' ? e(t('majority_2_3', $cur_lang)) : e(t('majority_simple', $cur_lang)) ?></span></td>
    <td><?= e($it['time_slot'] ?? '—') ?> · <?= (int)$it['est_minutes'] ?> min</td>
    <td><?= e($it['moderator_name'] ?? '—') ?></td>
  </tr>
  <?php $i++; endforeach; ?>
</table>

<p class="footer">Foreningen Front Door · foreningenfrontdoor.dk · <?= e(date('d.m.Y H:i')) ?></p>
</body>
</html>
