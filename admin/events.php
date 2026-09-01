<?php
require_once __DIR__ . '/auth.php';
$user = require_login();
$pdo  = get_db();
$is_admin = $user['role'] === 'admin';

$filter_cat    = $_GET['category'] ?? '';
$filter_status = $_GET['status']   ?? '';
$where  = []; $params = [];
if ($filter_cat && in_array($filter_cat, ['artistic','cultural','societate'])) {
    $where[] = 'e.category = :cat'; $params[':cat'] = $filter_cat;
}
if ($filter_status && in_array($filter_status, ['active','suspended','cancelled'])) {
    $where[] = 'e.status = :st'; $params[':st'] = $filter_status;
}
// Consilierii văd toate evenimentele, dar pot edita doar ale lor
$sql = 'SELECT e.*, u.name as creator_name
        FROM events e
        LEFT JOIN bf_users u ON u.id = e.created_by'
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . ' ORDER BY e.date ASC';
$stmt = $pdo->prepare($sql); $stmt->execute($params);
$events = $stmt->fetchAll();

// Adăugăm coloana created_by dacă nu există
try {
    $pdo->query('SELECT created_by FROM events LIMIT 1');
} catch (PDOException $e) {
    $pdo->exec('ALTER TABLE events ADD COLUMN created_by INT UNSIGNED NULL AFTER signup_url');
}

$flash = get_flash();
$cat_labels = ['artistic'=>'Artistic','cultural'=>'Cultural','societate'=>'Societate'];
$status_cfg = [
    'active'    => ['label'=>'Activ',    'color'=>'#2e7d32'],
    'suspended' => ['label'=>'Suspendat','color'=>'#e65100'],
    'cancelled' => ['label'=>'Anulat',   'color'=>'#b4242a'],
];

layout_head('Evenimente', 'events');
?>
<div class="content">
  <?php if ($flash): ?>
    <div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div>
  <?php endif; ?>
  <?php if (isset($_GET['err'])): ?>
    <div class="flash flash-error">Acces restricționat.</div>
  <?php endif; ?>

  <div class="page-head">
    <h1>Evenimente</h1>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <a class="btn btn-ghost btn-sm" href="/admin/social.php">📢 Generator Social</a>
      <a class="btn btn-solid" href="/admin/event-edit.php">+ Eveniment nou</a>
    </div>
  </div>

  <div class="filters">
    <form style="display:contents" method="get">
      <select name="category" onchange="this.form.submit()">
        <option value="">Toate categoriile</option>
        <option value="artistic"  <?= $filter_cat==='artistic'  ?'selected':'' ?>>Artistic</option>
        <option value="cultural"  <?= $filter_cat==='cultural'  ?'selected':'' ?>>Cultural</option>
        <option value="societate"    <?= $filter_cat==='societate'    ?'selected':'' ?>>Societate</option>
      </select>
      <select name="status" onchange="this.form.submit()">
        <option value="">Toate statusurile</option>
        <option value="active"    <?= $filter_status==='active'    ?'selected':'' ?>>Activ</option>
        <option value="suspended" <?= $filter_status==='suspended' ?'selected':'' ?>>Suspendat</option>
        <option value="cancelled" <?= $filter_status==='cancelled' ?'selected':'' ?>>Anulat</option>
      </select>
      <?php if ($filter_cat || $filter_status): ?>
        <a href="/admin/events.php" class="btn btn-ghost btn-sm">✕ Resetează</a>
      <?php endif; ?>
    </form>
  </div>

  <?php if (empty($events)): ?>
    <div class="empty">Niciun eveniment găsit. <a href="/admin/event-edit.php">Adaugă primul →</a></div>
  <?php else: ?>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Copertă</th>
          <th>Titlu</th>
          <th>Categorie</th>
          <th>Data</th>
          <th>Status</th>
          <th>Creat de</th>
          <th>Acțiuni</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($events as $ev):
        $can_edit = can_edit_event($user, (int)($ev['created_by'] ?? 0));
      ?>
        <tr>
          <td>
            <?php if ($ev['cover_image']): ?>
              <img class="cover-thumb" src="/<?= e(ltrim($ev['cover_image'],'/')) ?>" alt="">
            <?php else: ?>
              <div class="cover-empty">foto</div>
            <?php endif; ?>
          </td>
          <td>
            <strong><?= e($ev['title_ro']) ?></strong><br>
            <small style="color:rgba(255,255,255,.4)"><?= e($ev['title_da']) ?></small>
          </td>
          <td>
            <span class="badge" style="background:rgba(255,255,255,.06);color:rgba(255,255,255,.6)">
              <?= e($cat_labels[$ev['category']] ?? $ev['category']) ?>
            </span>
          </td>
          <td style="white-space:nowrap">
            <?= e(date('d.m.Y', strtotime($ev['date']))) ?>
            <?php if ($ev['time']): ?>
              <br><small style="color:rgba(255,255,255,.4)"><?= e(substr($ev['time'],0,5)) ?></small>
            <?php endif; ?>
          </td>
          <td>
            <?php $s = $status_cfg[$ev['status']] ?? ['label'=>$ev['status'],'color'=>'rgba(255,255,255,.4)']; ?>
            <span class="badge" style="color:<?= $s['color'] ?>;background:<?= $s['color'] ?>22">
              <?= e($s['label']) ?>
            </span>
          </td>
          <td style="font-size:12px;color:rgba(255,255,255,.25)"><?= e($ev['creator_name'] ?? '—') ?></td>
          <td>
            <div class="actions">
              <!-- Social — toți -->
              <a class="btn btn-ghost btn-xs" href="/admin/social.php?event=<?= (int)$ev['id'] ?>">📢</a>
              <?php if ($can_edit): ?>
                <a class="btn btn-ghost btn-xs" href="/admin/event-edit.php?id=<?= (int)$ev['id'] ?>">Editează</a>
                <?php if ($is_admin): ?>
                  <?php if ($ev['status']==='active'): ?>
                    <a class="btn btn-warn btn-xs" href="/admin/event-delete.php?action=suspend&id=<?= (int)$ev['id'] ?>&csrf=<?= csrf_token() ?>">Suspendă</a>
                  <?php elseif ($ev['status']==='suspended'): ?>
                    <a class="btn btn-ghost btn-xs" href="/admin/event-delete.php?action=activate&id=<?= (int)$ev['id'] ?>&csrf=<?= csrf_token() ?>">Reactivează</a>
                  <?php endif; ?>
                  <?php if ($ev['status']!=='cancelled'): ?>
                    <a class="btn btn-danger btn-xs" href="/admin/event-delete.php?action=cancel&id=<?= (int)$ev['id'] ?>&csrf=<?= csrf_token() ?>" onclick="return confirm('Anulezi?')">Anulează</a>
                  <?php endif; ?>
                  <a class="btn btn-danger btn-xs" href="/admin/event-delete.php?action=delete&id=<?= (int)$ev['id'] ?>&csrf=<?= csrf_token() ?>" onclick="return confirm('Ștergi definitiv?')">Șterge</a>
                <?php endif; ?>
              <?php else: ?>
                <span style="font-size:11px;color:rgba(255,255,255,.25)">doar vizibil</span>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php layout_foot(); ?>
