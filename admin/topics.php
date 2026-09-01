<?php
require_once __DIR__ . '/auth.php';
$user     = require_login();
$pdo      = get_db();
$is_admin = $user['role'] === 'admin';
$flash    = get_flash();

// ── ACȚIUNI POST ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    // Vot toggle
    if ($action === 'vote') {
        $pid = (int)($_POST['proposal_id'] ?? 0);
        $exists = $pdo->prepare('SELECT 1 FROM bf_votes WHERE proposal_id=? AND user_id=?');
        $exists->execute([$pid, $user['id']]);
        if ($exists->fetch()) {
            $pdo->prepare('DELETE FROM bf_votes WHERE proposal_id=? AND user_id=?')->execute([$pid, $user['id']]);
        } else {
            $pdo->prepare('INSERT IGNORE INTO bf_votes (proposal_id,user_id) VALUES (?,?)')->execute([$pid, $user['id']]);
        }
        header('Location: /admin/topics.php?meeting=' . (int)($_POST['meeting_id'] ?? 0) . '#p' . $pid);
        exit;
    }

    // Editează propunere proprie
    if ($action === 'edit_proposal') {
        $pid   = (int)($_POST['proposal_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $desc  = trim($_POST['description'] ?? '');
        $cat   = $_POST['category'] ?? 'altul';
        $stmt  = $pdo->prepare('SELECT * FROM bf_proposals p JOIN bf_meetings m ON m.id=p.meeting_id WHERE p.id=?');
        $stmt->execute([$pid]); $pr = $stmt->fetch();
        if ($pr && (int)$pr['user_id'] === (int)$user['id'] && $pr['status'] === 'open' && $title) {
            $pdo->prepare('UPDATE bf_proposals SET title=?,description=?,category=? WHERE id=?')
                ->execute([$title, $desc ?: null, $cat, $pid]);
            flash('ok', 'Propunerea a fost actualizată.');
        }
        header('Location: /admin/topics.php?meeting=' . (int)($_POST['meeting_id'] ?? 0));
        exit;
    }

    // Șterge propunere proprie
    if ($action === 'delete_proposal') {
        $pid  = (int)($_POST['proposal_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT p.*,m.status as mstatus FROM bf_proposals p JOIN bf_meetings m ON m.id=p.meeting_id WHERE p.id=?');
        $stmt->execute([$pid]); $pr = $stmt->fetch();
        if ($pr && (int)$pr['user_id'] === (int)$user['id'] && $pr['mstatus'] === 'open') {
            $pdo->prepare('DELETE FROM bf_proposals WHERE id=?')->execute([$pid]);
            flash('ok', 'Propunerea a fost ștearsă.');
        }
        header('Location: /admin/topics.php?meeting=' . (int)($_POST['meeting_id'] ?? 0));
        exit;
    }

    // Director: adaugă meeting
    if ($action === 'add_meeting' && $is_admin) {
        $title    = trim($_POST['meeting_title'] ?? '');
        $date     = $_POST['meeting_date'] ?? '';
        $location = trim($_POST['meeting_location'] ?? '');
        $type     = $_POST['meeting_type'] ?? 'generalforsamling';
        $open     = isset($_POST['meeting_open']) ? 1 : 0;
        $visible  = isset($_POST['meeting_visible']) ? 1 : 0;
        if ($title) {
            // adaugă coloanele dacă nu există
            foreach (['type VARCHAR(50) DEFAULT "generalforsamling"', 'is_open TINYINT(1) DEFAULT 1', 'is_visible TINYINT(1) DEFAULT 1'] as $col) {
                try { $pdo->exec("ALTER TABLE bf_meetings ADD COLUMN $col"); } catch(PDOException $e) {}
            }
            $pdo->prepare('INSERT INTO bf_meetings (title,date,location,status,type,is_open,is_visible) VALUES (?,?,?,?,?,?,?)')
                ->execute([$title, $date ?: null, $location ?: null, 'open', $type, $open, $visible]);
            flash('ok', 'Adunare creată: ' . $title);
        }
        header('Location: /admin/topics.php'); exit;
    }

    // Director: toggle open/visible
    if ($action === 'toggle_meeting' && $is_admin) {
        $mid   = (int)($_POST['meeting_id'] ?? 0);
        $field = $_POST['field'] ?? '';
        if (in_array($field, ['is_open','is_visible'])) {
            $cur = $pdo->prepare("SELECT $field FROM bf_meetings WHERE id=?");
            $cur->execute([$mid]); $cur = $cur->fetchColumn();
            $pdo->prepare("UPDATE bf_meetings SET $field=? WHERE id=?")->execute([$cur ? 0 : 1, $mid]);
            flash('ok', 'Actualizat.');
        }
        header('Location: /admin/topics.php?meeting=' . $mid); exit;
    }

    header('Location: /admin/topics.php'); exit;
}

// ── DATE ──────────────────────────────────────────────────────
// Asigură coloanele noi
foreach (['type VARCHAR(50) DEFAULT "generalforsamling"','is_open TINYINT(1) DEFAULT 1','is_visible TINYINT(1) DEFAULT 1'] as $col) {
    try { $pdo->exec("ALTER TABLE bf_meetings ADD COLUMN $col"); } catch(PDOException $e) {}
}

$meetings = $pdo->query('SELECT * FROM bf_meetings ORDER BY date DESC, id DESC')->fetchAll();
$mid = isset($_GET['meeting']) ? (int)$_GET['meeting'] : 0;
if (!$mid) {
    foreach ($meetings as $m) {
        $open = $m['is_open'] ?? 1;
        $vis  = $m['is_visible'] ?? 1;
        if ($is_admin || ($open && $vis)) { $mid = (int)$m['id']; break; }
    }
    if (!$mid && $meetings) $mid = (int)$meetings[0]['id'];
}
$meeting = null;
foreach ($meetings as $m) { if ((int)$m['id'] === $mid) { $meeting = $m; break; } }

$proposals = [];
if ($mid) {
    $stmt = $pdo->prepare(
        'SELECT p.*, u.name as author_name, u.position as author_pos,
                COUNT(v.user_id) as vote_count,
                MAX(CASE WHEN v.user_id=:uid THEN 1 ELSE 0 END) as my_vote
         FROM bf_proposals p
         JOIN bf_users u ON u.id=p.user_id
         LEFT JOIN bf_votes v ON v.proposal_id=p.id
         WHERE p.meeting_id=:mid
         GROUP BY p.id ORDER BY vote_count DESC, p.created_at ASC'
    );
    $stmt->execute([':uid'=>$user['id'],':mid'=>$mid]);
    $proposals = $stmt->fetchAll();
}

$cat_labels = ['administrativ'=>'Administrativ','proiecte'=>'Proiecte','financiar'=>'Financiar','cultural'=>'Cultural','societate'=>'Societate','artistic'=>'Artistic','altul'=>'Altul'];

layout_head('Propuneri', 'topics');
?>
<div class="content">
  <?php if ($flash): ?>
    <div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div>
  <?php endif; ?>

  <div class="page-head">
    <div>
      <h1>Propuneri subiecte</h1>
      <?php if ($meeting): ?>
        <div style="font-size:13px;color:rgba(255,255,255,.25);margin-top:4px">
          <?= e($meeting['title']) ?>
          <?php if ($meeting['date']): ?> · <?= e(date('d.m.Y',strtotime($meeting['date']))) ?><?php endif; ?>
          <span style="margin-left:8px;padding:2px 7px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;background:<?= ($meeting['is_open']??1)?'rgba(255,255,255,.06)':'rgba(86,96,108,.2)' ?>;color:<?= ($meeting['is_open']??1)?'rgba(255,255,255,.6)':'rgba(255,255,255,.25)' ?>">
            <?= ($meeting['is_open']??1) ? 'Deschis' : 'Închis' ?>
          </span>
        </div>
      <?php endif; ?>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <?php if ($meeting && ($meeting['is_open']??1)): ?>
        <a class="btn btn-solid btn-sm" href="/admin/topic-edit.php?meeting=<?= $mid ?>">+ Propunere nouă</a>
      <?php endif; ?>
      <?php if ($meeting): ?>
        <a class="btn btn-ghost btn-sm" href="/admin/topics-export.php?meeting=<?= $mid ?>">↓ Export AI</a>
      <?php endif; ?>
    </div>
  </div>

  <div style="display:flex;gap:24px;align-items:flex-start;flex-wrap:wrap">

    <!-- ── STÂNGA: meetings list ── -->
    <div style="width:220px;flex-shrink:0">
      <div style="font-size:11px;letter-spacing:.16em;text-transform:uppercase;color:rgba(255,255,255,.25);margin-bottom:10px">Adunări</div>
      <div style="display:flex;flex-direction:column;gap:6px">
        <?php foreach ($meetings as $m):
          $isOpen = $m['is_open'] ?? 1;
          $isVis  = $m['is_visible'] ?? 1;
          $show   = $is_admin || ($isOpen && $isVis);
          if (!$show) continue;
          $isActive = ((int)$m['id'] === $mid);
        ?>
        <a href="/admin/topics.php?meeting=<?= (int)$m['id'] ?>"
           style="display:block;padding:10px 14px;font-size:13px;font-weight:<?= $isActive?'700':'500' ?>;
                  background:<?= $isActive?'rgba(255,255,255,.06)':'#0a0a0a' ?>;
                  border:1px solid <?= $isActive?'rgba(255,255,255,.15)':'rgba(255,255,255,.05)' ?>;
                  color:<?= $isActive?'#fff':'rgba(255,255,255,.4)' ?>;transition:all .15s">
          <?= e($m['title']) ?>
          <?php if (!$isOpen): ?><span style="font-size:10px;color:rgba(255,255,255,.25);margin-left:4px">(închis)</span><?php endif; ?>
          <?php if (!$isVis && $is_admin): ?><span style="font-size:10px;color:rgba(255,255,255,.25);margin-left:4px">(ascuns)</span><?php endif; ?>
        </a>
        <?php endforeach; ?>
      </div>

      <?php if ($is_admin && $meeting): ?>
      <!-- toggles director -->
      <div style="margin-top:16px;padding:12px;background:#0a0a0a;border:1px solid rgba(255,255,255,.05)">
        <div style="font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:rgba(255,255,255,.25);margin-bottom:10px">Setări adunare</div>
        <form method="post" style="display:flex;flex-direction:column;gap:8px">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="meeting_id" value="<?= $mid ?>">
          <input type="hidden" name="action" value="toggle_meeting">
          <input type="hidden" name="field" value="is_open">
          <button class="btn btn-sm <?= ($meeting['is_open']??1)?'btn-warn':'btn-green' ?>" type="submit">
            <?= ($meeting['is_open']??1) ? '🔒 Închide' : '🔓 Deschide' ?>
          </button>
        </form>
        <form method="post" style="margin-top:6px">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="meeting_id" value="<?= $mid ?>">
          <input type="hidden" name="action" value="toggle_meeting">
          <input type="hidden" name="field" value="is_visible">
          <button class="btn btn-ghost btn-sm" type="submit">
            <?= ($meeting['is_visible']??1) ? '👁 Ascunde' : '👁 Afișează' ?>
          </button>
        </form>
      </div>
      <?php endif; ?>

      <?php if ($is_admin): ?>
      <!-- adaugă meeting -->
      <div style="margin-top:12px">
        <button class="btn btn-ghost btn-sm" style="width:100%" onclick="toggleNewMeeting()">+ Adunare nouă</button>
        <div id="newMeetingForm" style="display:none;margin-top:10px;background:#0a0a0a;border:1px solid rgba(255,255,255,.05);padding:14px">
          <form method="post">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="add_meeting">
            <div style="margin-bottom:10px">
              <label style="font-size:11px;color:rgba(255,255,255,.4);display:block;margin-bottom:4px">Titlu *</label>
              <input type="text" name="meeting_title" required placeholder="ex: GF 2027" style="width:100%;padding:8px 10px;font-size:13px;background:#000;border:1.5px solid rgba(255,255,255,.1);color:#fff;font-family:inherit">
            </div>
            <div style="margin-bottom:10px">
              <label style="font-size:11px;color:rgba(255,255,255,.4);display:block;margin-bottom:4px">Tip</label>
              <select name="meeting_type" style="width:100%;padding:8px 10px;font-size:13px;background:#000;border:1.5px solid rgba(255,255,255,.1);color:#fff;font-family:inherit">
                <option value="generalforsamling">Generalforsamling</option>
                <option value="extraordinar">Adunare extraordinară</option>
              </select>
            </div>
            <div style="margin-bottom:10px">
              <label style="font-size:11px;color:rgba(255,255,255,.4);display:block;margin-bottom:4px">Data</label>
              <input type="date" name="meeting_date" style="width:100%;padding:8px 10px;font-size:13px;background:#000;border:1.5px solid rgba(255,255,255,.1);color:#fff;font-family:inherit">
            </div>
            <div style="margin-bottom:12px;display:flex;flex-direction:column;gap:6px">
              <label style="display:flex;align-items:center;gap:7px;font-size:12px;color:rgba(255,255,255,.4);cursor:pointer">
                <input type="checkbox" name="meeting_open" checked style="accent-color:rgba(255,255,255,.15)"> Deschis pentru propuneri
              </label>
              <label style="display:flex;align-items:center;gap:7px;font-size:12px;color:rgba(255,255,255,.4);cursor:pointer">
                <input type="checkbox" name="meeting_visible" checked style="accent-color:rgba(255,255,255,.15)"> Vizibil pentru toți
              </label>
            </div>
            <button class="btn btn-solid btn-sm" type="submit" style="width:100%">Creează</button>
          </form>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <!-- ── DREAPTA: propuneri ── -->
    <div style="flex:1;min-width:0">
      <?php if (empty($proposals)): ?>
        <div class="empty">
          <p style="margin-bottom:16px">Nicio propunere încă pentru această adunare.</p>
          <?php if ($meeting && ($meeting['is_open']??1)): ?>
            <a class="btn btn-solid btn-sm" href="/admin/topic-edit.php?meeting=<?= $mid ?>">+ Adaugă prima propunere</a>
          <?php endif; ?>
        </div>
      <?php else: ?>
      <div style="display:flex;flex-direction:column;gap:12px">
        <?php foreach ($proposals as $p):
          $isMe = ((int)$p['user_id'] === (int)$user['id']);
          $canEdit = $isMe && ($meeting['is_open'] ?? 1);
        ?>
        <div id="p<?= (int)$p['id'] ?>" style="background:#0a0a0a;border:1px solid rgba(255,255,255,.05);padding:18px 20px;display:flex;gap:16px;transition:border-color .2s" onmouseover="this.style.borderColor='rgba(255,255,255,.12)'" onmouseout="this.style.borderColor='rgba(255,255,255,.05)'">
          <!-- vot -->
          <div style="display:flex;flex-direction:column;align-items:center;gap:5px;flex-shrink:0;width:48px">
            <form method="post">
              <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="action" value="vote">
              <input type="hidden" name="proposal_id" value="<?= (int)$p['id'] ?>">
              <input type="hidden" name="meeting_id" value="<?= $mid ?>">
              <button type="submit" style="width:42px;height:42px;border:1.5px solid <?= $p['my_vote']?'rgba(255,255,255,.15)':'rgba(255,255,255,.1)' ?>;background:<?= $p['my_vote']?'rgba(255,255,255,.06)':'transparent' ?>;color:<?= $p['my_vote']?'rgba(255,255,255,.6)':'rgba(255,255,255,.4)' ?>;font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .15s" title="<?= $p['my_vote']?'Retrage':'Susține' ?>">▲</button>
            </form>
            <span style="font-size:18px;font-weight:700;color:#fff;line-height:1"><?= (int)$p['vote_count'] ?></span>
            <span style="font-size:10px;color:rgba(255,255,255,.25);letter-spacing:.06em;text-transform:uppercase">voturi</span>
          </div>
          <!-- body -->
          <div style="flex:1;min-width:0">
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:7px">
              <span style="padding:2px 8px;font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;border:1px solid rgba(143,182,214,.3);color:rgba(255,255,255,.6)"><?= e($cat_labels[$p['category']] ?? $p['category']) ?></span>
              <span style="font-size:12px;color:rgba(255,255,255,.25)"><?= e($p['author_name']) ?></span>
              <?php if ($isMe): ?><span style="font-size:10px;padding:2px 6px;border:1px solid rgba(255,255,255,.12);color:rgba(255,255,255,.6)">Tu</span><?php endif; ?>
            </div>
            <h3 style="font-size:16px;font-weight:700;margin-bottom:5px"><?= e($p['title']) ?></h3>
            <?php if ($p['description']): ?>
              <p style="font-size:13px;color:rgba(255,255,255,.4);line-height:1.6"><?= nl2br(e($p['description'])) ?></p>
            <?php endif; ?>
            <p style="font-size:11px;color:rgba(255,255,255,.25);margin-top:6px"><?= e(date('d.m.Y H:i',strtotime($p['created_at']))) ?></p>

            <?php if ($canEdit): ?>
            <div style="display:flex;gap:7px;margin-top:10px">
              <a class="btn btn-ghost btn-xs" href="/admin/topic-edit.php?id=<?= (int)$p['id'] ?>&meeting=<?= $mid ?>">Editează</a>
              <form method="post" style="display:inline" onsubmit="return confirm('Ștergi?')">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="delete_proposal">
                <input type="hidden" name="proposal_id" value="<?= (int)$p['id'] ?>">
                <input type="hidden" name="meeting_id" value="<?= $mid ?>">
                <button class="btn btn-danger btn-xs" type="submit">Șterge</button>
              </form>
            </div>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

  </div><!-- /flex -->
</div>
<script>
function toggleNewMeeting() {
  var el = document.getElementById('newMeetingForm');
  el.style.display = el.style.display === 'none' ? 'block' : 'none';
}
</script>
<?php layout_foot(); ?>
