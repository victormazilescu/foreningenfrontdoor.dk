<?php
require_once __DIR__ . '/auth.php';
$user   = require_perm('topics', 'view');
$pdo    = get_db();
ensure_meeting_bucket_schema($pdo);
$id     = isset($_GET['id'])      ? (int)$_GET['id']      : 0;
$mid    = isset($_GET['meeting']) ? (int)$_GET['meeting']  : 0;
$is_new = $id === 0;
$errors = [];
$is_manager = has_perm($user, 'topics', 'manage');

if ($is_new && !has_perm($user, 'topics', 'propose')) {
    flash('error', t('cannot_propose'));
    header('Location: /admin/topics.php'); exit;
}

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
    flash('error', t('no_open_meeting'));
    header('Location: /admin/topics.php'); exit;
}

$pr = ['title'=>'','description'=>'','category'=>'altul','orig_lang'=>ui_lang()];

if (!$is_new) {
    $stmt = $pdo->prepare('SELECT * FROM bf_proposals WHERE id=?');
    $stmt->execute([$id]); $row = $stmt->fetch();
    $can_touch = $row && ((int)$row['user_id'] === (int)$user['id'] && has_perm($user, 'topics', 'edit_own') || $is_manager);
    if (!$can_touch) {
        flash('error', t('cannot_edit_proposal'));
        header('Location: /admin/topics.php?meeting='.$mid); exit;
    }
    $pr  = $row;
    $mid = (int)$row['meeting_id'];
}

$langs = ['ro'=>'Română','da'=>'Dansk','en'=>'English'];
$other_langs = array_keys(array_diff_key($langs, [($pr['orig_lang'] ?: 'ro') => 1]));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $f = [
        'title'       => trim($_POST['title']       ?? ''),
        'description' => trim($_POST['description'] ?? ''),
        'category'    => $_POST['category']         ?? 'altul',
        'orig_lang'   => array_key_exists($_POST['orig_lang'] ?? '', $langs) ? $_POST['orig_lang'] : ui_lang(),
    ];
    $allowed = ['administrativ','proiecte','financiar','cultural','societate','artistic','altul'];
    if (!$f['title']) $errors[] = t('title_required');
    if (!in_array($f['category'], $allowed)) $errors[] = t('invalid_category');

    if (empty($errors)) {
        // Determină ce limbi păstrăm neatinse (editate manual de admin în
        // acest submit) vs. ce limbi retraducem automat din text.
        $keep = [];
        $manual = [];
        if (!$is_new && $is_manager) {
            foreach (array_keys($langs) as $l) {
                if ($l === $f['orig_lang']) continue;
                if (!empty($_POST['locked_' . $l])) {
                    $keep[] = $l;
                    $manual[$l] = [
                        'title_' . $l       => trim($_POST['title_' . $l] ?? ''),
                        'description_' . $l => trim($_POST['description_' . $l] ?? ''),
                    ];
                }
            }
        }
        $existing = $manual ? array_merge(...array_values($manual)) : [];
        $tr = translate_proposal_fields($f['title'], $f['description'], $f['orig_lang'], $existing, $keep);
        $translations_locked = $keep ? 1 : 0;

        if ($is_new) {
            $pdo->prepare(
                'INSERT INTO bf_proposals
                 (meeting_id,user_id,title,description,category,orig_lang,
                  title_ro,title_da,title_en,description_ro,description_da,description_en,translations_locked)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $meeting['id'], $user['id'], $f['title'], $f['description'] ?: null, $f['category'], $tr['orig_lang'],
                $tr['title_ro'], $tr['title_da'], $tr['title_en'],
                $tr['description_ro'] ?: null, $tr['description_da'] ?: null, $tr['description_en'] ?: null,
                $translations_locked,
            ]);
        } else {
            $pdo->prepare(
                'UPDATE bf_proposals SET
                 title=?,description=?,category=?,orig_lang=?,
                 title_ro=?,title_da=?,title_en=?,description_ro=?,description_da=?,description_en=?,translations_locked=?
                 WHERE id=?'
            )->execute([
                $f['title'], $f['description'] ?: null, $f['category'], $tr['orig_lang'],
                $tr['title_ro'], $tr['title_da'], $tr['title_en'],
                $tr['description_ro'] ?: null, $tr['description_da'] ?: null, $tr['description_en'] ?: null,
                $translations_locked, $id,
            ]);
        }
        flash('ok', $is_new ? t('proposal_added') : t('proposal_updated_short'));
        header('Location: /admin/topics.php?meeting=' . $meeting['id']); exit;
    }
    $pr = array_merge($pr, $f);
}

$cats = ['administrativ'=>t('cat_administrativ'),'proiecte'=>t('cat_proiecte'),'financiar'=>t('cat_financiar'),'cultural'=>t('cat_cultural'),'societate'=>t('cat_societate'),'artistic'=>t('cat_artistic'),'altul'=>t('cat_altul')];
$orig_lang = $pr['orig_lang'] ?: 'ro';
$other_langs = array_keys(array_diff_key($langs, [$orig_lang => 1]));

layout_head($is_new ? t('new_proposal') : t('edit_proposal_h1'), 'topics');
?>
<div class="content" style="max-width:680px">
  <div style="margin-bottom:20px">
    <a href="/admin/topics.php?meeting=<?= (int)$meeting['id'] ?>" style="font-size:13px;color:rgba(255,255,255,.45)"><?= e(t('back_to_proposals')) ?></a>
  </div>
  <h1 style="font-size:22px;font-weight:700;margin-bottom:6px"><?= $is_new ? e(t('new_proposal')) : e(t('edit_proposal_h1')) ?></h1>
  <p style="font-size:13px;color:rgba(255,255,255,.65);margin-bottom:24px"><?= e(t('for_label')) ?> <strong style="color:rgba(255,255,255,.65)"><?= e($meeting['title']) ?></strong></p>

  <?php if ($errors): ?>
    <div class="errors"><ul><?php foreach($errors as $err):?><li><?= e($err) ?></li><?php endforeach;?></ul></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

    <div class="form-section">
      <p class="section-label"><?= e(t('proposed_topic_label')) ?></p>
      <?php if ($is_new): ?>
      <div class="field" style="margin-bottom:16px;max-width:220px">
        <label><?= e(t('orig_lang_field')) ?></label>
        <select name="orig_lang">
          <?php foreach ($langs as $lc => $lname): ?>
            <option value="<?= e($lc) ?>" <?= $orig_lang===$lc?'selected':'' ?>><?= e($lname) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="field" style="margin-bottom:16px">
        <label><?= e(t('title_label')) ?> *</label>
        <input type="text" name="title" value="<?= e($pr['title']) ?>" required placeholder="<?= e(t('proposal_title_ph')) ?>">
      </div>
      <div class="field">
        <label><?= e(t('description_context_label')) ?></label>
        <textarea name="description" rows="4" placeholder="<?= e(t('proposal_description_ph')) ?>"><?= e($pr['description']??'') ?></textarea>
      </div>
      <?php if ($is_new): ?>
        <p class="field-hint" style="margin-top:10px"><?= e(t('translating_notice')) ?></p>
      <?php endif; ?>
    </div>

    <?php if (!$is_new && $is_manager): ?>
    <div class="form-section">
      <p class="section-label"><?= e(t('translations_h')) ?></p>
      <p class="field-hint" style="margin-bottom:16px"><?= e(t('translations_sub')) ?></p>
      <?php foreach ($other_langs as $ol): ?>
        <div style="border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:14px 16px;margin-bottom:12px">
          <div style="font-size:12px;font-weight:700;color:rgba(255,255,255,.6);margin-bottom:10px"><?= e($langs[$ol]) ?></div>
          <div class="field" style="margin-bottom:10px">
            <label><?= e(t('title_label')) ?></label>
            <input type="text" name="title_<?= e($ol) ?>" value="<?= e($pr['title_' . $ol] ?? '') ?>">
          </div>
          <div class="field" style="margin-bottom:10px">
            <label><?= e(t('description_context_label')) ?></label>
            <textarea name="description_<?= e($ol) ?>" rows="3"><?= e($pr['description_' . $ol] ?? '') ?></textarea>
          </div>
          <label class="check-row" style="font-size:12px">
            <input type="checkbox" name="locked_<?= e($ol) ?>" value="1" <?= !empty($pr['translations_locked']) ? 'checked' : '' ?>>
            <?= e(t('manually_edited_checkbox')) ?>
          </label>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="form-section">
      <p class="section-label"><?= e(t('category_label')) ?></p>
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
      <button class="btn btn-solid" type="submit"><?= $is_new ? e(t('add_proposal_btn')) : e(t('save')) ?></button>
      <a class="btn btn-ghost" href="/admin/topics.php?meeting=<?= (int)$meeting['id'] ?>"><?= e(t('cancel')) ?></a>
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
