<?php
require_once __DIR__ . '/auth.php';
$user = require_perm('events', 'view');
$pdo  = get_db();
ensure_events_translation_schema($pdo);

// Adăugăm coloana created_by dacă nu există — trebuie să ruleze înainte de
// SELECT-ul de mai jos, altfel query-ul cu e.created_by eșuează pe o bază
// de date proaspătă unde coloana încă nu există.
try {
    $pdo->query('SELECT created_by FROM events LIMIT 1');
} catch (PDOException $e) {
    $pdo->exec('ALTER TABLE events ADD COLUMN created_by INT UNSIGNED NULL AFTER signup_url');
}

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
     . ' ORDER BY (e.date IS NULL) ASC, e.date ASC';
$stmt = $pdo->prepare($sql); $stmt->execute($params);
$events = $stmt->fetchAll();

$flash = get_flash();
$cat_labels = ['artistic'=>t('cat_artistic'),'cultural'=>t('cat_cultural'),'societate'=>t('cat_societate')];
$status_cfg = [
    'active'    => ['label'=>t('status_active'),    'color'=>'#2e7d32'],
    'suspended' => ['label'=>t('status_suspended'), 'color'=>'#e65100'],
    'cancelled' => ['label'=>t('status_cancelled'), 'color'=>'#b4242a'],
];

layout_head(t('nav_events'), 'events');
?>
<div class="content">
  <?php if ($flash): ?>
    <div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div>
  <?php endif; ?>
  <?php if (isset($_GET['err'])): ?>
    <div class="flash flash-error"><?= e(t('access_restricted')) ?></div>
  <?php endif; ?>

  <div class="page-head">
    <h1><?= e(t('nav_events')) ?></h1>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <a class="btn btn-ghost btn-sm" href="/admin/social.php"><?= e(t('social_generator')) ?></a>
      <?php if (has_perm($user, 'events', 'create')): ?>
        <a class="btn btn-solid" href="/admin/event-edit.php">+ <?= e(t('new_event')) ?></a>
      <?php endif; ?>
    </div>
  </div>

  <div class="filters">
    <form style="display:contents" method="get">
      <select name="category" onchange="this.form.submit()">
        <option value=""><?= e(t('all_categories')) ?></option>
        <option value="artistic"  <?= $filter_cat==='artistic'  ?'selected':'' ?>><?= e(t('cat_artistic')) ?></option>
        <option value="cultural"  <?= $filter_cat==='cultural'  ?'selected':'' ?>><?= e(t('cat_cultural')) ?></option>
        <option value="societate"    <?= $filter_cat==='societate'    ?'selected':'' ?>><?= e(t('cat_societate')) ?></option>
      </select>
      <select name="status" onchange="this.form.submit()">
        <option value=""><?= e(t('all_statuses')) ?></option>
        <option value="active"    <?= $filter_status==='active'    ?'selected':'' ?>><?= e(t('status_active')) ?></option>
        <option value="suspended" <?= $filter_status==='suspended' ?'selected':'' ?>><?= e(t('status_suspended')) ?></option>
        <option value="cancelled" <?= $filter_status==='cancelled' ?'selected':'' ?>><?= e(t('status_cancelled')) ?></option>
      </select>
      <?php if ($filter_cat || $filter_status): ?>
        <a href="/admin/events.php" class="btn btn-ghost btn-sm">✕ <?= e(t('reset_filters')) ?></a>
      <?php endif; ?>
    </form>
  </div>

  <?php if (empty($events)): ?>
    <div class="empty"><?= e(t('no_events_found')) ?>
      <?php if (has_perm($user, 'events', 'create')): ?><a href="/admin/event-edit.php"><?= e(t('add_first')) ?></a><?php endif; ?>
    </div>
  <?php else: ?>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th><?= e(t('th_cover')) ?></th>
          <th><?= e(t('th_title')) ?></th>
          <th><?= e(t('th_category')) ?></th>
          <th><?= e(t('th_date')) ?></th>
          <th><?= e(t('th_status')) ?></th>
          <th><?= e(t('th_created_by')) ?></th>
          <th><?= e(t('th_actions')) ?></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($events as $ev):
        $can_edit   = can_edit_event($user, (int)($ev['created_by'] ?? 0));
        $can_manage = has_perm($user, 'events', 'manage');
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
            <small style="color:rgba(255,255,255,.65)"><?= e($ev['title_da']) ?></small>
          </td>
          <td>
            <span class="badge" style="background:rgba(255,255,255,.06);color:rgba(255,255,255,.6)">
              <?= e($cat_labels[$ev['category']] ?? $ev['category']) ?>
            </span>
          </td>
          <td style="white-space:nowrap">
            <?php if ($ev['date']): ?>
              <?= e(date('d.m.Y', strtotime($ev['date']))) ?>
            <?php else: ?>
              <span style="color:rgba(255,255,255,.45)"><?= e(t('date_tbd')) ?></span>
            <?php endif; ?>
            <?php if ($ev['time']): ?>
              <br><small style="color:rgba(255,255,255,.65)"><?= e(substr($ev['time'],0,5)) ?></small>
            <?php endif; ?>
          </td>
          <td>
            <?php $s = $status_cfg[$ev['status']] ?? ['label'=>$ev['status'],'color'=>'rgba(255,255,255,.4)']; ?>
            <span class="badge" style="color:<?= $s['color'] ?>;background:<?= $s['color'] ?>22">
              <?= e($s['label']) ?>
            </span>
          </td>
          <td style="font-size:12px;color:rgba(255,255,255,.45)"><?= e($ev['creator_name'] ?? '—') ?></td>
          <td>
            <div class="actions">
              <!-- Social — toți -->
              <a class="btn btn-ghost btn-xs" href="/admin/social.php?event=<?= (int)$ev['id'] ?>"><?= e(t('social_short')) ?></a>
              <?php if ($can_edit): ?>
                <a class="btn btn-ghost btn-xs" href="/admin/event-social.php?id=<?= (int)$ev['id'] ?>"><?= e(t('auto_post_link')) ?></a>
              <?php endif; ?>
              <?php if ($can_edit): ?>
                <a class="btn btn-ghost btn-xs" href="/admin/event-edit.php?id=<?= (int)$ev['id'] ?>"><?= e(t('edit')) ?></a>
              <?php endif; ?>
              <?php if ($can_manage): ?>
                <?php if ($ev['status']==='active'): ?>
                  <a class="btn btn-warn btn-xs" href="/admin/event-delete.php?action=suspend&id=<?= (int)$ev['id'] ?>&csrf=<?= csrf_token() ?>"><?= e(t('suspend')) ?></a>
                <?php elseif ($ev['status']==='suspended'): ?>
                  <a class="btn btn-ghost btn-xs" href="/admin/event-delete.php?action=activate&id=<?= (int)$ev['id'] ?>&csrf=<?= csrf_token() ?>"><?= e(t('reactivate')) ?></a>
                <?php endif; ?>
                <?php if ($ev['status']!=='cancelled'): ?>
                  <a class="btn btn-danger btn-xs" href="/admin/event-delete.php?action=cancel&id=<?= (int)$ev['id'] ?>&csrf=<?= csrf_token() ?>" onclick="return confirm('<?= e(t('cancel_event_confirm')) ?>')"><?= e(t('cancel')) ?></a>
                <?php endif; ?>
                <a class="btn btn-danger btn-xs" href="/admin/event-delete.php?action=delete&id=<?= (int)$ev['id'] ?>&csrf=<?= csrf_token() ?>" onclick="return confirm('<?= e(t('delete_confirm')) ?>')"><?= e(t('delete')) ?></a>
              <?php endif; ?>
              <?php if (!$can_edit && !$can_manage): ?>
                <span style="font-size:11px;color:rgba(255,255,255,.45)"><?= e(t('view_only')) ?></span>
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
