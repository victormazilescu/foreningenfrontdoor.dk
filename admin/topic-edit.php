<?php
require_once __DIR__ . '/auth.php';
$user   = require_login();
$pdo    = get_db();
$id     = isset($_GET['id'])      ? (int)$_GET['id']      : 0;
$mid    = isset($_GET['meeting']) ? (int)$_GET['meeting']  : 0;
$is_new = $id === 0;
$errors = [];

// Verifică meeting deschis
$meeting = null;
if ($mid) {
    $stmt = $pdo->prepare('SELECT * FROM bf_meetings WHERE id=?');
    $stmt->execute([$mid]); $meeting = $stmt->fetch();
}
if (!$meeting) {
    $meeting = $pdo->query('SELECT * FROM bf_meetings WHERE (is_open IS NULL OR is_open=1) LIMIT 1')->fetch();
}
if (!$meeting) {
    flash('error','Nicio adunare deschisă.');
    header('Location: /admin/topics.php'); exit;
}

$pr = ['title'=>'','description'=>'','category'=>'altul'];

if (!$is_new) {
    $stmt = $pdo->prepare('SELECT * FROM bf_proposals WHERE id=?');
    $stmt->execute([$id]); $row = $stmt->fetch();
    if (!$row || (int)$row['user_id'] !== (int)$user['id']) {
        flash('error','Nu poți edita această propunere.');
        header('Location: /admin/topics.php?meeting='.$mid); exit;
    }
    $pr  = $row;
    $mid = (int)$row['meeting_id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $f = [
        'title'       => trim($_POST['title']       ?? ''),
        'description' => trim($_POST['description'] ?? ''),
        'category'    => $_POST['category']         ?? 'altul',
    ];
    $allowed = ['administrativ','proiecte','financiar','cultural','societate','artistic','altul'];
    if (!$f['title']) $errors[] = 'Titlul e obligatoriu.';
    if (!in_array($f['category'], $allowed)) $errors[] = 'Categorie invalidă.';

    if (empty($errors)) {
        if ($is_new) {
            $pdo->prepare('INSERT INTO bf_proposals (meeting_id,user_id,title,description,category) VALUES (?,?,?,?,?)')
                ->execute([$meeting['id'], $user['id'], $f['title'], $f['description'] ?: null, $f['category']]);
        } else {
            $pdo->prepare('UPDATE bf_proposals SET title=?,description=?,category=? WHERE id=?')
                ->execute([$f['title'], $f['description'] ?: null, $f['category'], $id]);
        }
        flash('ok', $is_new ? 'Propunere adăugată.' : 'Propunere actualizată.');
        header('Location: /admin/topics.php?meeting=' . $meeting['id']); exit;
    }
    $pr = $f;
}

$cats = ['administrativ'=>'Administrativ','proiecte'=>'Proiecte','financiar'=>'Financiar','cultural'=>'Cultural','societate'=>'Societate','artistic'=>'Artistic','altul'=>'Altul'];

layout_head($is_new ? 'Propunere nouă' : 'Editează propunere', 'topics');
?>
<div class="content" style="max-width:680px">
  <div style="margin-bottom:20px">
    <a href="/admin/topics.php?meeting=<?= (int)$meeting['id'] ?>" style="font-size:13px;color:rgba(255,255,255,.25)">← Înapoi la propuneri</a>
  </div>
  <h1 style="font-size:22px;font-weight:700;margin-bottom:6px"><?= $is_new ? 'Propunere nouă' : 'Editează propunerea' ?></h1>
  <p style="font-size:13px;color:rgba(255,255,255,.25);margin-bottom:24px">Pentru: <strong style="color:rgba(255,255,255,.4)"><?= e($meeting['title']) ?></strong></p>

  <?php if ($errors): ?>
    <div class="errors"><ul><?php foreach($errors as $err):?><li><?= e($err) ?></li><?php endforeach;?></ul></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

    <div class="form-section">
      <p class="section-label">Topicul propus</p>
      <div class="field" style="margin-bottom:16px">
        <label>Titlu *</label>
        <input type="text" name="title" value="<?= e($pr['title']) ?>" required placeholder="Un titlu clar și concis">
      </div>
      <div class="field">
        <label>Descriere / context</label>
        <textarea name="description" rows="4" placeholder="De ce propui acest topic? Ce vrei să se discute sau decidă?"><?= e($pr['description']??'') ?></textarea>
      </div>
    </div>

    <div class="form-section">
      <p class="section-label">Categorie</p>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:8px">
        <?php foreach ($cats as $k=>$v): ?>
          <div>
            <input type="radio" name="category" id="cat-<?= e($k) ?>" value="<?= e($k) ?>" <?= ($pr['category']??'altul')===$k?'checked':'' ?> style="display:none">
            <label for="cat-<?= e($k) ?>" style="display:block;padding:9px 12px;border:1.5px solid rgba(255,255,255,.08);font-size:13px;font-weight:600;cursor:pointer;text-align:center;transition:all .15s" onmouseover="this.style.borderColor='rgba(255,255,255,.3)'" onmouseout="updateCatStyle()"><?= e($v) ?></label>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div style="display:flex;gap:10px;margin-top:4px">
      <button class="btn btn-solid" type="submit"><?= $is_new ? 'Adaugă propunerea' : 'Salvează' ?></button>
      <a class="btn btn-ghost" href="/admin/topics.php?meeting=<?= (int)$meeting['id'] ?>">Anulează</a>
    </div>
  </form>
</div>
<script>
// Vizual pentru radio categorie
function updateCatStyle() {
  document.querySelectorAll('input[name="category"]').forEach(function(r) {
    var lbl = document.querySelector('label[for="' + r.id + '"]');
    if (lbl) {
      lbl.style.borderColor = r.checked ? 'rgba(255,255,255,.15)' : 'rgba(255,255,255,.08)';
      lbl.style.background  = r.checked ? 'rgba(255,255,255,.06)' : 'transparent';
      lbl.style.color       = r.checked ? '#fff' : '';
    }
  });
}
document.querySelectorAll('input[name="category"]').forEach(function(r) {
  r.addEventListener('change', updateCatStyle);
});
updateCatStyle();
</script>
<?php layout_foot(); ?>
