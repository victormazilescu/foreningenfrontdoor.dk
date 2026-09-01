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
$cat_labels=['artistic'=>'Artistic','cultural'=>'Cultural','societate'=>'Societate'];
$status_cfg=['draft'=>['label'=>'Draft','color'=>'rgba(255,255,255,.4)'],'active'=>['label'=>'Activ','color'=>'#2e7d32'],'completed'=>['label'=>'Finalizat','color'=>'rgba(255,255,255,.15)'],'cancelled'=>['label'=>'Anulat','color'=>'#b4242a']];

layout_head('Proiecte','projects');
?>
<div class="content">
  <?php if ($flash): ?><div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>
  <div class="page-head">
    <h1>Proiecte</h1>
    <?php if (has_perm($user, 'projects', 'create')): ?>
      <a class="btn btn-solid" href="/admin/project-edit.php">+ Proiect nou</a>
    <?php endif; ?>
  </div>
  <div class="filters">
    <form style="display:contents" method="get">
      <select name="category" onchange="this.form.submit()">
        <option value="">Toate categoriile</option>
        <option value="artistic"  <?=$filter_cat==='artistic'?'selected':''?>>Artistic</option>
        <option value="cultural"  <?=$filter_cat==='cultural'?'selected':''?>>Cultural</option>
        <option value="societate"    <?=$filter_cat==='societate'?'selected':''?>>Societate</option>
      </select>
      <select name="status" onchange="this.form.submit()">
        <option value="">Toate statusurile</option>
        <option value="draft"     <?=$filter_status==='draft'?'selected':''?>>Draft</option>
        <option value="active"    <?=$filter_status==='active'?'selected':''?>>Activ</option>
        <option value="completed" <?=$filter_status==='completed'?'selected':''?>>Finalizat</option>
        <option value="cancelled" <?=$filter_status==='cancelled'?'selected':''?>>Anulat</option>
      </select>
      <?php if($filter_cat||$filter_status): ?><a href="/admin/projects.php" class="btn btn-ghost btn-sm">✕ Resetează</a><?php endif; ?>
    </form>
  </div>
  <?php if(empty($projects)): ?>
    <div class="empty">Niciun proiect.
      <?php if (has_perm($user, 'projects', 'create')): ?><a href="/admin/project-edit.php">Adaugă primul →</a><?php endif; ?>
    </div>
  <?php else: ?>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Ord.</th><th>Titlu</th><th>Categorie</th><th>Etichetă</th><th>Status</th><th>Acțiuni</th></tr></thead>
      <tbody>
      <?php foreach($projects as $pr):
        $s=$status_cfg[$pr['status']]??['label'=>$pr['status'],'color'=>'rgba(255,255,255,.4)'];
      ?>
        <tr>
          <td><span style="display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;background:#0a0a0a;border:1px solid rgba(255,255,255,.1);font-size:13px;font-weight:700;color:rgba(255,255,255,.4)"><?=(int)$pr['sort_order']?></span></td>
          <td><strong><?=e($pr['title_ro'])?></strong><br><small style="color:rgba(255,255,255,.4)"><?=e($pr['title_da'])?></small></td>
          <td><span class="badge" style="background:rgba(255,255,255,.06);color:rgba(255,255,255,.6)"><?=e($cat_labels[$pr['category']]??$pr['category'])?></span></td>
          <td style="font-size:13px;color:rgba(255,255,255,.4)"><?=e($pr['label_ro']??'—')?></td>
          <td><span class="badge" style="color:<?=$s['color']?>;background:<?=$s['color']?>22"><?=e($s['label'])?></span></td>
          <td>
            <div class="actions">
              <?php if (has_perm($user, 'projects', 'edit')): ?>
                <a class="btn btn-ghost btn-xs" href="/admin/project-edit.php?id=<?=(int)$pr['id']?>">Editează</a>
              <?php endif; ?>
              <a class="btn btn-ghost btn-xs" href="/admin/social.php?project=<?=(int)$pr['id']?>">📢 Social</a>
              <?php if (has_perm($user, 'projects', 'manage')): ?>
                <?php if($pr['status']!=='completed'): ?>
                  <a class="btn btn-green btn-xs" href="/admin/project-action.php?action=complete&id=<?=(int)$pr['id']?>&csrf=<?=csrf_token()?>" onclick="return confirm('Marchezi ca finalizat?')">Finalizează</a>
                <?php endif; ?>
                <?php if($pr['status']!=='cancelled'): ?>
                  <a class="btn btn-danger btn-xs" href="/admin/project-action.php?action=cancel&id=<?=(int)$pr['id']?>&csrf=<?=csrf_token()?>" onclick="return confirm('Anulezi?')">Anulează</a>
                <?php endif; ?>
                <a class="btn btn-danger btn-xs" href="/admin/project-action.php?action=delete&id=<?=(int)$pr['id']?>&csrf=<?=csrf_token()?>" onclick="return confirm('Ștergi definitiv?')">Șterge</a>
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
