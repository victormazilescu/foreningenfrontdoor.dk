<?php
require_once __DIR__ . '/auth.php';
$user = require_perm('projects', 'view');
$pdo  = get_db();

$filter_cat    = $_GET['category'] ?? '';
$filter_status = $_GET['status']   ?? '';
$where = []; $params = [];
if ($filter_cat && in_array($filter_cat,['artistic','cultural','societate'])) { $where[]='category=:cat'; $params[':cat']=$filter_cat; }
if ($filter_status && in_array($filter_status,['draft','active','completed','cancelled'])) { $where[]='status=:st'; $params[':st']=$filter_status; }
$sql='SELECT * FROM projects'.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY sort_order ASC, id ASC';
$stmt=$pdo->prepare($sql); $stmt->execute($params);
$projects=$stmt->fetchAll();
$flash=get_flash();
$cat_labels=['artistic'=>t('cat_artistic'),'cultural'=>t('cat_cultural'),'societate'=>t('cat_societate')];
$status_cfg=['draft'=>['label'=>t('status_draft'),'color'=>'rgba(255,255,255,.6)'],'active'=>['label'=>t('status_active'),'color'=>'#2e7d32'],'completed'=>['label'=>t('status_completed'),'color'=>'rgba(255,255,255,.45)'],'cancelled'=>['label'=>t('status_cancelled'),'color'=>'#b4242a']];

layout_head(t('nav_projects'),'projects');
?>
<div class="content">
  <?php if ($flash): ?><div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>
  <div class="page-head">
    <h1><?= e(t('nav_projects')) ?></h1>
    <?php if (has_perm($user, 'projects', 'create')): ?>
      <a class="btn btn-solid" href="/admin/project-edit.php">+ <?= e(t('new_project')) ?></a>
    <?php endif; ?>
  </div>
  <div class="filters">
    <form style="display:contents" method="get">
      <select name="category" onchange="this.form.submit()">
        <option value=""><?= e(t('all_categories')) ?></option>
        <option value="artistic"  <?=$filter_cat==='artistic'?'selected':''?>><?= e(t('cat_artistic')) ?></option>
        <option value="cultural"  <?=$filter_cat==='cultural'?'selected':''?>><?= e(t('cat_cultural')) ?></option>
        <option value="societate"    <?=$filter_cat==='societate'?'selected':''?>><?= e(t('cat_societate')) ?></option>
      </select>
      <select name="status" onchange="this.form.submit()">
        <option value=""><?= e(t('all_statuses')) ?></option>
        <option value="draft"     <?=$filter_status==='draft'?'selected':''?>><?= e(t('status_draft')) ?></option>
        <option value="active"    <?=$filter_status==='active'?'selected':''?>><?= e(t('status_active')) ?></option>
        <option value="completed" <?=$filter_status==='completed'?'selected':''?>><?= e(t('status_completed')) ?></option>
        <option value="cancelled" <?=$filter_status==='cancelled'?'selected':''?>><?= e(t('status_cancelled')) ?></option>
      </select>
      <?php if($filter_cat||$filter_status): ?><a href="/admin/projects.php" class="btn btn-ghost btn-sm">✕ <?= e(t('reset_filters')) ?></a><?php endif; ?>
    </form>
  </div>
  <?php if(empty($projects)): ?>
    <div class="empty"><?= e(t('no_projects')) ?>
      <?php if (has_perm($user, 'projects', 'create')): ?><a href="/admin/project-edit.php"><?= e(t('add_first')) ?></a><?php endif; ?>
    </div>
  <?php else: ?>
  <div class="table-wrap">
    <table>
      <thead><tr><th><?= e(t('th_order')) ?></th><th><?= e(t('th_title')) ?></th><th><?= e(t('th_category')) ?></th><th><?= e(t('th_label')) ?></th><th><?= e(t('th_status')) ?></th><th><?= e(t('th_actions')) ?></th></tr></thead>
      <tbody>
      <?php foreach($projects as $pr):
        $s=$status_cfg[$pr['status']]??['label'=>$pr['status'],'color'=>'rgba(255,255,255,.6)'];
      ?>
        <tr>
          <td><span style="display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;background:#0a0a0a;border:1px solid rgba(255,255,255,.1);font-size:13px;font-weight:700;color:rgba(255,255,255,.6)"><?=(int)$pr['sort_order']?></span></td>
          <td><strong><?=e($pr['title_ro'])?></strong><br><small style="color:rgba(255,255,255,.65)"><?=e($pr['title_da'])?></small></td>
          <td><span class="badge" style="background:rgba(255,255,255,.06);color:rgba(255,255,255,.6)"><?=e($cat_labels[$pr['category']]??$pr['category'])?></span></td>
          <td style="font-size:13px;color:rgba(255,255,255,.65)"><?=e($pr['label_ro']??'—')?></td>
          <td><span class="badge" style="color:<?=$s['color']?>;background:<?=$s['color']?>22"><?=e($s['label'])?></span></td>
          <td>
            <div class="actions">
              <?php if (has_perm($user, 'projects', 'edit')): ?>
                <a class="btn btn-ghost btn-xs" href="/admin/project-edit.php?id=<?=(int)$pr['id']?>"><?= e(t('edit')) ?></a>
              <?php endif; ?>
              <a class="btn btn-ghost btn-xs" href="/admin/social.php?project=<?=(int)$pr['id']?>"><?= e(t('social_short')) ?></a>
              <?php if (has_perm($user, 'projects', 'manage')): ?>
                <?php if($pr['status']!=='completed'): ?>
                  <a class="btn btn-green btn-xs" href="/admin/project-action.php?action=complete&id=<?=(int)$pr['id']?>&csrf=<?=csrf_token()?>" onclick="return confirm('<?= e(t('complete_confirm')) ?>')"><?= e(t('complete_project')) ?></a>
                <?php endif; ?>
                <?php if($pr['status']!=='cancelled'): ?>
                  <a class="btn btn-danger btn-xs" href="/admin/project-action.php?action=cancel&id=<?=(int)$pr['id']?>&csrf=<?=csrf_token()?>" onclick="return confirm('<?= e(t('cancel_event_confirm')) ?>')"><?= e(t('cancel')) ?></a>
                <?php endif; ?>
                <a class="btn btn-danger btn-xs" href="/admin/project-action.php?action=delete&id=<?=(int)$pr['id']?>&csrf=<?=csrf_token()?>" onclick="return confirm('<?= e(t('delete_confirm')) ?>')"><?= e(t('delete')) ?></a>
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
