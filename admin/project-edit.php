<?php
require_once __DIR__ . '/auth.php';
$user   = require_perm('projects', 'view');
$pdo    = get_db();
$id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$is_new = $id === 0;
$errors = [];

if (!has_perm($user, 'projects', $is_new ? 'create' : 'edit')) {
    flash('error', $is_new ? 'Nu poți crea proiecte noi.' : 'Nu poți edita acest proiect.');
    header('Location: /admin/projects.php'); exit;
}

$all_tags      = $pdo->query('SELECT * FROM tags ORDER BY sort_order ASC, name ASC')->fetchAll();
$selected_tags = [];

$pr = ['title_ro'=>'','title_da'=>'','description_ro'=>'','description_da'=>'',
       'label_ro'=>'','label_da'=>'','category'=>'artistic','status'=>'active',
       'signup_url'=>'','sort_order'=>0];

if (!$is_new) {
    $stmt = $pdo->prepare('SELECT * FROM projects WHERE id=?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) { header('Location: /admin/projects.php'); exit; }
    $pr = $row;
    $tStmt = $pdo->prepare('SELECT tag_id FROM project_tags WHERE project_id=?');
    $tStmt->execute([$id]);
    $selected_tags = array_column($tStmt->fetchAll(), 'tag_id');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $f = [
        'title_ro'       => trim($_POST['title_ro']       ?? ''),
        'title_da'       => trim($_POST['title_da']       ?? ''),
        'description_ro' => trim($_POST['description_ro'] ?? ''),
        'description_da' => trim($_POST['description_da'] ?? ''),
        'label_ro'       => trim($_POST['label_ro']       ?? ''),
        'label_da'       => trim($_POST['label_da']       ?? ''),
        'category'       => $_POST['category']   ?? 'artistic',
        'status'         => $_POST['status']     ?? 'active',
        'signup_url'     => trim($_POST['signup_url']     ?? ''),
        'sort_order'     => (int)($_POST['sort_order']    ?? 0),
    ];
    $tag_ids = array_map('intval', $_POST['tags'] ?? []);

    if (!$f['title_ro']) $errors[] = 'Titlu RO obligatoriu.';
    if (!$f['title_da']) $errors[] = 'Titlu DA obligatoriu.';
    if (!in_array($f['category'], ['artistic','cultural','societate'])) $errors[] = 'Categorie invalidă.';
    if (!in_array($f['status'], ['draft','active','completed','cancelled'])) $errors[] = 'Status invalid.';

    if (empty($errors)) {
        if ($is_new) {
            $stmt = $pdo->prepare('INSERT INTO projects (title_ro,title_da,description_ro,description_da,label_ro,label_da,category,status,signup_url,sort_order) VALUES (?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute(array_values($f));
            $id = (int)$pdo->lastInsertId();
        } else {
            $stmt = $pdo->prepare('UPDATE projects SET title_ro=?,title_da=?,description_ro=?,description_da=?,label_ro=?,label_da=?,category=?,status=?,signup_url=?,sort_order=? WHERE id=?');
            $stmt->execute([...array_values($f), $id]);
        }
        $pdo->prepare('DELETE FROM project_tags WHERE project_id=?')->execute([$id]);
        if ($tag_ids) {
            $ins = $pdo->prepare('INSERT IGNORE INTO project_tags (project_id,tag_id) VALUES (?,?)');
            foreach ($tag_ids as $tid) $ins->execute([$id, $tid]);
        }
        flash('ok', $is_new ? 'Proiect adăugat.' : 'Proiect actualizat.');
        header('Location: /admin/projects.php'); exit;
    }
    $pr = array_merge($pr, $f);
    $selected_tags = $tag_ids;
}

layout_head($is_new ? 'Proiect nou' : 'Editează proiect', 'projects');
?>
<div class="content" style="max-width:860px">

  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:10px">
    <a href="/admin/projects.php" style="font-size:13px;color:rgba(255,255,255,.25)">← Înapoi la proiecte</a>
    <?php if (!$is_new): ?>
    <div style="display:flex;gap:8px">
      <a class="btn btn-ghost btn-sm" href="/admin/social.php?project=<?= $id ?>">📢 Generator Social</a>
      <a class="btn btn-ghost btn-sm" href="/admin/project-details.php?project=<?= $id ?>">📋 Detalii publice</a>
    </div>
    <?php endif; ?>
  </div>

  <h1 style="font-size:22px;font-weight:700;margin-bottom:24px"><?= $is_new ? 'Proiect nou' : 'Editează proiect' ?></h1>

  <?php if ($errors): ?>
    <div class="errors"><ul><?php foreach($errors as $e):?><li><?= e($e) ?></li><?php endforeach;?></ul></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

    <div class="form-section">
      <p class="section-label">Titlu</p>
      <div class="grid-2">
        <div class="field"><label>Titlu în română *</label><input type="text" name="title_ro" value="<?= e($pr['title_ro']) ?>" required></div>
        <div class="field"><label>Titlu în daneză *</label><input type="text" name="title_da" value="<?= e($pr['title_da']) ?>" required></div>
      </div>
    </div>

    <div class="form-section">
      <p class="section-label">Descriere</p>
      <div class="grid-2">
        <div class="field"><label>Descriere în română</label><textarea name="description_ro"><?= e($pr['description_ro']) ?></textarea></div>
        <div class="field"><label>Descriere în daneză</label><textarea name="description_da"><?= e($pr['description_da']) ?></textarea></div>
      </div>
    </div>

    <div class="form-section">
      <p class="section-label">Detalii</p>
      <div class="grid-3">
        <div class="field">
          <label>Categorie *</label>
          <select name="category">
            <option value="artistic"  <?=$pr['category']==='artistic' ?'selected':''?>>Artistic</option>
            <option value="cultural"  <?=$pr['category']==='cultural' ?'selected':''?>>Cultural</option>
            <option value="societate"    <?=$pr['category']==='societate'   ?'selected':''?>>Societate</option>
          </select>
        </div>
        <div class="field">
          <label>Status</label>
          <select name="status">
            <option value="draft"     <?=$pr['status']==='draft'     ?'selected':''?>>Draft</option>
            <option value="active"    <?=$pr['status']==='active'    ?'selected':''?>>Activ</option>
            <option value="completed" <?=$pr['status']==='completed' ?'selected':''?>>Finalizat</option>
            <option value="cancelled" <?=$pr['status']==='cancelled' ?'selected':''?>>Anulat</option>
          </select>
        </div>
        <div class="field">
          <label>Ordine în carousel</label>
          <input type="number" name="sort_order" value="<?= (int)$pr['sort_order'] ?>" min="0">
        </div>
      </div>
      <div class="grid-2" style="margin-top:14px">
        <div class="field"><label>Etichetă scurtă RO</label><input type="text" name="label_ro" value="<?= e($pr['label_ro']??'') ?>" placeholder="ex: Muzică, Film"></div>
        <div class="field"><label>Etichetă scurtă DA</label><input type="text" name="label_da" value="<?= e($pr['label_da']??'') ?>" placeholder="ex: Musik, Film"></div>
      </div>
    </div>

    <div class="form-section">
      <p class="section-label">Taguri</p>
      <?php if (empty($all_tags)): ?>
        <p style="color:rgba(255,255,255,.25);font-size:14px">Nu există taguri. <a href="/admin/settings.php?s=tags">Creează →</a></p>
      <?php else: ?>
        <div class="tags-wrap">
          <?php foreach ($all_tags as $tag): ?>
            <div>
              <input class="tag-cb" type="checkbox" name="tags[]"
                     id="ptag-<?= (int)$tag['id'] ?>" value="<?= (int)$tag['id'] ?>"
                     <?= in_array((int)$tag['id'], $selected_tags) ? 'checked' : '' ?>>
              <label class="tag-lbl" for="ptag-<?= (int)$tag['id'] ?>">
                <span class="tag-dot" style="background:<?= e($tag['color']) ?>"></span>
                <?= e($tag['name']) ?>
              </label>
            </div>
          <?php endforeach; ?>
        </div>
        <p style="font-size:11px;color:rgba(255,255,255,.25);margin-top:8px"><a href="/admin/settings.php?s=tags" style="color:rgba(255,255,255,.25)">Gestionează tagurile →</a></p>
      <?php endif; ?>
    </div>

    <div class="form-section">
      <p class="section-label">Link extern (opțional)</p>
      <div class="field" style="max-width:480px">
        <label>URL platformă externă</label>
        <input type="url" name="signup_url" value="<?= e($pr['signup_url']??'') ?>" placeholder="https://...">
        <span class="field-hint">Apare ca buton pe cardul proiectului.</span>
      </div>
    </div>

    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <button class="btn btn-solid" type="submit"><?= $is_new ? 'Adaugă proiectul' : 'Salvează' ?></button>
      <a class="btn btn-ghost" href="/admin/projects.php">Anulează</a>
      <?php if (!$is_new): ?>
        <a class="btn btn-ghost" href="/admin/project-details.php?project=<?= $id ?>" style="margin-left:auto">📋 Detalii publice →</a>
      <?php endif; ?>
    </div>
  </form>
</div>

<script>
document.querySelectorAll('.tag-cb').forEach(function(cb) {
  function upd() {
    var lbl = document.querySelector('label[for="' + cb.id + '"]');
    if (lbl) { lbl.style.borderColor = cb.checked ? '#fff' : ''; lbl.style.background = cb.checked ? 'rgba(255,255,255,.05)' : ''; }
  }
  upd(); cb.addEventListener('change', upd);
});
</script>
<?php layout_foot(); ?>
