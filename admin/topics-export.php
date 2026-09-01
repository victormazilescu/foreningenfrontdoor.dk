<?php
require_once __DIR__ . '/auth.php';
$user = require_perm('topics', 'view');
$pdo  = get_db();
$mid  = isset($_GET['meeting']) ? (int)$_GET['meeting'] : 0;

$meeting = $pdo->prepare('SELECT * FROM bf_meetings WHERE id=?');
$meeting->execute([$mid]); $meeting = $meeting->fetch();
if (!$meeting) { header('Location: /admin/topics.php'); exit; }

$stmt = $pdo->prepare(
    'SELECT p.*, u.name as author_name, u.position as author_pos, COUNT(v.user_id) as vote_count
     FROM bf_proposals p JOIN bf_users u ON u.id=p.user_id
     LEFT JOIN bf_votes v ON v.proposal_id=p.id
     WHERE p.meeting_id=? GROUP BY p.id ORDER BY vote_count DESC, p.created_at ASC'
);
$stmt->execute([$mid]); $proposals = $stmt->fetchAll();
$total = (int)$pdo->query('SELECT COUNT(*) FROM bf_users WHERE active=1')->fetchColumn();
$members = $pdo->query('SELECT name, position, position_label FROM bf_users WHERE active=1 ORDER BY id ASC')->fetchAll();

$pos_labels = ['presedinte'=>'Președinte','vicepresedinte'=>'Vicepreședinte','trezorier'=>'Trezorier','consilier'=>'Consilier'];
$cat_labels = ['administrativ'=>'Administrativ','proiecte'=>'Proiecte','financiar'=>'Financiar','cultural'=>'Cultural','societate'=>'Societate','artistic'=>'Artistic','altul'=>'Altul'];

$date_str = $meeting['date'] ? date('Y-m-d',strtotime($meeting['date'])) : date('Y-m-d');
header('Content-Type: text/markdown; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $date_str . '_' . preg_replace('/[^a-zA-Z0-9]+/','_',$meeting['title']) . '.md"');

$md  = "# {$meeting['title']}\n";
if ($meeting['date']) $md .= "**Data:** " . date('d.m.Y',strtotime($meeting['date'])) . "\n";
if ($meeting['location']) $md .= "**Locație:** {$meeting['location']}\n";
$md .= "**Membri activi:** $total\n";
$md .= "**Propuneri:** " . count($proposals) . "\n";
$md .= "**Export:** " . date('d.m.Y H:i') . "\n\n---\n\n";

$md .= "## Instrucțiuni pentru AI\n\n";
$md .= "Ești asistentul pentru organizarea adunării generale a **Foreningen Front Door**, o asociație culturală romano-daneză din Danemarca.\n\n";
$md .= "Pe baza propunerilor de mai jos:\n\n";
$md .= "1. **Estimează timpul** pentru fiecare topic (minute), ținând cont de complexitate și voturi.\n";
$md .= "2. **Propune un moderator** din lista membrilor, bazat pe relevanța rolului.\n";
$md .= "3. **Sugerează o ordine de zi** logică: administrativ → financiar → proiecte → altele.\n";
$md .= "4. **Estimează durata totală** și sugerează o pauză dacă e cazul.\n";
$md .= "5. **Clasifică** fiecare topic: decizie formală / informare / dezbatere.\n\n";

$md .= "### Membrii bestyrelse\n\n";
foreach ($members as $m) {
    $pos = $pos_labels[$m['position']] ?? $m['position'];
    if ($m['position']==='consilier' && $m['position_label']) $pos .= ' — ' . $m['position_label'];
    $md .= "- **{$m['name']}** ($pos)\n";
}
$md .= "\n---\n\n## Propuneri (ordonate după voturi)\n\n";

$r = 1;
foreach ($proposals as $p) {
    $pos = $pos_labels[$p['author_pos']] ?? $p['author_pos'];
    $cat = $cat_labels[$p['category']] ?? $p['category'];
    $md .= "### $r. {$p['title']}\n\n";
    $md .= "| | |\n|---|---|\n";
    $md .= "| Voturi | {$p['vote_count']} / $total |\n";
    $md .= "| Categorie | $cat |\n";
    $md .= "| Propus de | {$p['author_name']} ($pos) |\n";
    $md .= "| Timp estimat | _AI_ |\n";
    $md .= "| Moderator propus | _AI_ |\n";
    $md .= "| Tip | _AI: decizie / informare / dezbatere_ |\n\n";
    if ($p['description']) $md .= "**Descriere:** {$p['description']}\n\n";
    $md .= "---\n\n";
    $r++;
}

$md .= "## Ordine de zi propusă (de completat de AI)\n\n";
$md .= "| Nr. | Topic | Timp (min) | Moderator | Tip |\n|---|---|---|---|---|\n";
for ($i=1;$i<=count($proposals);$i++) $md .= "| $i | | | | |\n";
$md .= "\n**Durată totală estimată:** _AI_\n\n";
$md .= "_Generat de foreningenfrontdoor.dk/admin · " . date('d.m.Y H:i') . "_\n";

echo $md;
