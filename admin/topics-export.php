<?php
require_once __DIR__ . '/auth.php';
$user = require_perm('topics', 'view');
$pdo  = get_db();
$mid  = isset($_GET['meeting']) ? (int)$_GET['meeting'] : 0;

$meeting = $pdo->prepare('SELECT * FROM bf_meetings WHERE id=?');
$meeting->execute([$mid]); $meeting = $meeting->fetch();
if (!$meeting) { header('Location: /admin/topics.php'); exit; }

$stmt = $pdo->prepare(
    'SELECT p.*, u.name as author_name, u.position as author_pos
     FROM bf_proposals p JOIN bf_users u ON u.id=p.user_id
     WHERE p.meeting_id=? ORDER BY p.created_at ASC'
);
$stmt->execute([$mid]); $proposals = $stmt->fetchAll();
$total = (int)$pdo->query('SELECT COUNT(*) FROM bf_users WHERE active=1')->fetchColumn();
$members = $pdo->query('SELECT name, position, position_label FROM bf_users WHERE active=1 ORDER BY id ASC')->fetchAll();

$pos_labels = ['presedinte'=>t('pos_presedinte'),'vicepresedinte'=>t('pos_vicepresedinte'),'trezorier'=>t('pos_trezorier'),'consilier'=>t('pos_consilier')];
$cat_labels = ['administrativ'=>t('cat_administrativ'),'proiecte'=>t('cat_proiecte'),'financiar'=>t('cat_financiar'),'cultural'=>t('cat_cultural'),'societate'=>t('cat_societate'),'artistic'=>t('cat_artistic'),'altul'=>t('cat_altul')];

$date_str = $meeting['date'] ? date('Y-m-d',strtotime($meeting['date'])) : date('Y-m-d');
header('Content-Type: text/markdown; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $date_str . '_' . preg_replace('/[^a-zA-Z0-9]+/','_',$meeting['title']) . '.md"');

$md  = "# {$meeting['title']}\n";
if ($meeting['date']) $md .= "**" . t('export_md_date_label') . ":** " . date('d.m.Y',strtotime($meeting['date'])) . "\n";
if ($meeting['location']) $md .= "**" . t('export_md_location_label') . ":** {$meeting['location']}\n";
$md .= "**" . t('export_md_active_members') . ":** $total\n";
$md .= "**" . t('export_md_proposals') . ":** " . count($proposals) . "\n";
$md .= "**" . t('export_md_export_label') . ":** " . date('d.m.Y H:i') . "\n\n---\n\n";

$md .= "## " . t('export_md_ai_instructions_h') . "\n\n";
$md .= t('export_md_ai_intro') . "\n\n";
$md .= t('export_md_ai_based_on') . "\n\n";
$md .= "1. " . t('export_md_ai_step1') . "\n";
$md .= "2. " . t('export_md_ai_step2') . "\n";
$md .= "3. " . t('export_md_ai_step3') . "\n";
$md .= "4. " . t('export_md_ai_step4') . "\n";
$md .= "5. " . t('export_md_ai_step5') . "\n\n";

$md .= "### " . t('export_md_board_members_h') . "\n\n";
foreach ($members as $m) {
    $pos = $pos_labels[$m['position']] ?? $m['position'];
    if ($m['position']==='consilier' && $m['position_label']) $pos .= ' — ' . $m['position_label'];
    $md .= "- **{$m['name']}** ($pos)\n";
}
$md .= "\n---\n\n## " . t('export_md_proposals_by_votes_h') . "\n\n";

$r = 1;
foreach ($proposals as $p) {
    $pos = $pos_labels[$p['author_pos']] ?? $p['author_pos'];
    $cat = $cat_labels[$p['category']] ?? $p['category'];
    $md .= "### $r. {$p['title']}\n\n";
    $md .= "| | |\n|---|---|\n";
    $md .= "| " . t('export_md_category_row') . " | $cat |\n";
    $md .= "| " . t('export_md_proposed_by_row') . " | {$p['author_name']} ($pos) |\n";
    $md .= "| " . t('export_md_time_est_row') . " | _AI_ |\n";
    $md .= "| " . t('export_md_moderator_row') . " | _AI_ |\n";
    $md .= "| " . t('export_md_type_row') . " | " . t('export_md_type_ai_hint') . " |\n\n";
    if ($p['description']) $md .= "**" . t('export_md_description_label') . ":** {$p['description']}\n\n";
    $md .= "---\n\n";
    $r++;
}

$md .= "## " . t('export_md_agenda_h') . "\n\n";
$md .= "| " . t('export_md_agenda_col_nr') . " | " . t('export_md_agenda_col_topic') . " | " . t('export_md_agenda_col_time') . " | " . t('export_md_agenda_col_moderator') . " | " . t('export_md_agenda_col_type') . " |\n|---|---|---|---|---|\n";
for ($i=1;$i<=count($proposals);$i++) $md .= "| $i | | | | |\n";
$md .= "\n**" . t('export_md_total_duration') . ":** _AI_\n\n";
$md .= "_" . t('export_md_generated_by') . " foreningenfrontdoor.dk/admin · " . date('d.m.Y H:i') . "_\n";

echo $md;
