<?php
require_once __DIR__ . '/auth.php';
$user   = require_perm('projects', 'view');
$pdo    = get_db();
ensure_projects_translation_schema($pdo);
$id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$is_new = $id === 0;
$errors = [];

if (!has_perm($user, 'projects', $is_new ? 'create' : 'edit')) {
    flash('error', $is_new ? t('cannot_create_projects') : t('cannot_edit_project'));
    header('Location: /admin/projects.php'); exit;
}

$all_tags      = $pdo->query('SELECT * FROM tags ORDER BY sort_order ASC, name ASC')->fetchAll();
$selected_tags = [];

$pr = [
    'title_ro'=>'','title_da'=>'','title_en'=>'','description_ro'=>'','description_da'=>'','description_en'=>'',
    'orig_lang'=>'ro','locked_langs'=>'',
    'label_ro'=>'','label_da'=>'','category'=>'artistic','status'=>'active',
    'signup_url'=>'','sort_order'=>0,
];

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

$lang_names  = ['ro'=>'Română','da'=>'Dansk','en'=>'English'];
$orig_lang   = in_array($pr['orig_lang'] ?? '', array_keys($lang_names), true) ? $pr['orig_lang'] : 'ro';
$other_langs = array_values(array_diff(array_keys($lang_names), [$orig_lang]));
$locked_list = array_filter(explode(',', $pr['locked_langs'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $posted_orig_lang = in_array($_POST['orig_lang'] ?? '', array_keys($lang_names), true) ? $_POST['orig_lang'] : $orig_lang;
    $posted_others    = array_values(array_diff(array_keys($lang_names), [$posted_orig_lang]));
    $f = [
        'title'          => trim($_POST['title']       ?? ''),
        'description'    => trim($_POST['description'] ?? ''),
        'label_ro'       => trim($_POST['label_ro']       ?? ''),
        'label_da'       => trim($_POST['label_da']       ?? ''),
        'category'       => $_POST['category']   ?? 'artistic',
        'status'         => $_POST['status']     ?? 'active',
        'signup_url'     => trim($_POST['signup_url']     ?? ''),
        'sort_order'     => (int)($_POST['sort_order']    ?? 0),
    ];
    $tag_ids = array_map('intval', $_POST['tags'] ?? []);

    if (!$f['title']) $errors[] = t('title_required');
    if (!in_array($f['category'], ['artistic','cultural','societate'])) $errors[] = t('invalid_category');
    if (!in_array($f['status'], ['draft','active','completed','cancelled'])) $errors[] = t('invalid_status');

    if (empty($errors)) {
        // Traducere automată titlu+descriere din limba originală în celelalte
        // două (RO/DA/EN) — ca la evenimente/propuneri. „am editat manual"
        // (per limbă) păstrează varianta scrisă de admin, fără s-o retraducem.
        $existing = [];
        $keep     = [];
        foreach ($posted_others as $ol) {
            $existing['title_' . $ol]       = trim($_POST['title_' . $ol] ?? '');
            $existing['description_' . $ol] = trim($_POST['description_' . $ol] ?? '');
            if (!$is_new && !empty($_POST['locked_' . $ol])) $keep[] = $ol;
        }
        $tr = translate_project_fields(['title' => $f['title'], 'description' => $f['description']], $posted_orig_lang, $existing, $keep);
        $locked_langs = implode(',', $keep);

        if ($is_new) {
            $stmt = $pdo->prepare('INSERT INTO projects (title_ro,title_da,title_en,description_ro,description_da,description_en,orig_lang,locked_langs,label_ro,label_da,category,status,signup_url,sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$tr['title_ro'],$tr['title_da'],$tr['title_en'],$tr['description_ro'] ?: null,$tr['description_da'] ?: null,$tr['description_en'] ?: null,$tr['orig_lang'],$locked_langs,$f['label_ro'],$f['label_da'],$f['category'],$f['status'],$f['signup_url'],$f['sort_order']]);
            $id = (int)$pdo->lastInsertId();
        } else {
            $stmt = $pdo->prepare('UPDATE projects SET title_ro=?,title_da=?,title_en=?,description_ro=?,description_da=?,description_en=?,orig_lang=?,locked_langs=?,label_ro=?,label_da=?,category=?,status=?,signup_url=?,sort_order=? WHERE id=?');
            $stmt->execute([$tr['title_ro'],$tr['title_da'],$tr['title_en'],$tr['description_ro'] ?: null,$tr['description_da'] ?: null,$tr['description_en'] ?: null,$tr['orig_lang'],$locked_langs,$f['label_ro'],$f['label_da'],$f['category'],$f['status'],$f['signup_url'],$f['sort_order'],$id]);
        }
        $pdo->prepare('DELETE FROM project_tags WHERE project_id=?')->execute([$id]);
        if ($tag_ids) {
            $ins = $pdo->prepare('INSERT IGNORE INTO project_tags (project_id,tag_id) VALUES (?,?)');
            foreach ($tag_ids as $tid) $ins->execute([$id, $tid]);
        }
        flash('ok', $is_new ? t('project_added') : t('project_updated'));
        header('Location: /admin/projects.php'); exit;
    }
    // Repopulăm formularul cu ce a scris admin (fără să retraducem — traducerea
    // are loc doar la salvare reușită, mai sus).
    $pr = array_merge($pr, $f, [
        'orig_lang' => $posted_orig_lang,
        'title_' . $posted_orig_lang       => $f['title'],
        'description_' . $posted_orig_lang => $f['description'],
    ]);
    foreach ($posted_others as $ol) {
        $pr['title_' . $ol]       = trim($_POST['title_' . $ol] ?? '');
        $pr['description_' . $ol] = trim($_POST['description_' . $ol] ?? '');
    }
    $locked_posted = [];
    foreach ($posted_others as $ol) { if (!empty($_POST['locked_' . $ol])) $locked_posted[] = $ol; }
    $pr['locked_langs'] = implode(',', $locked_posted);
    $orig_lang   = $posted_orig_lang;
    $other_langs = $posted_others;
    $locked_list = $locked_posted;
    $selected_tags = $tag_ids;
}

layout_head($is_new ? t('new_project') : t('edit_project'), 'projects');
?>
<div class="content" style="max-width:860px">

  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:10px">
    <a href="/admin/projects.php" style="font-size:13px;color:rgba(255,255,255,.45)"><?= e(t('back_to_projects')) ?></a>
    <?php if (!$is_new): ?>
    <div style="display:flex;gap:8px">
      <a class="btn btn-ghost btn-sm" href="/admin/social.php?project=<?= $id ?>"><?= e(t('social_generator')) ?></a>
      <a class="btn btn-ghost btn-sm" href="/admin/project-details.php?project=<?= $id ?>"><?= e(t('public_details_arrow')) ?></a>
    </div>
    <?php endif; ?>
  </div>

  <h1 style="font-size:22px;font-weight:700;margin-bottom:24px"><?= $is_new ? e(t('new_project')) : e(t('edit_project')) ?></h1>

  <?php if ($errors): ?>
    <div class="errors"><ul><?php foreach($errors as $er):?><li><?= e($er) ?></li><?php endforeach;?></ul></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

    <div class="form-section">
      <p class="section-label"><?= e(t('title_label')) ?></p>
      <?php if ($is_new): ?>
      <div class="field" style="margin-bottom:16px;max-width:220px">
        <label><?= e(t('orig_lang_field')) ?></label>
        <select name="orig_lang">
          <?php foreach ($lang_names as $lc => $lname): ?>
            <option value="<?= e($lc) ?>" <?= $orig_lang===$lc?'selected':'' ?>><?= e($lname) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php else: ?>
      <input type="hidden" name="orig_lang" value="<?= e($orig_lang) ?>">
      <?php endif; ?>
      <div class="field" style="margin-bottom:16px">
        <label><?= e(t('title_label')) ?> *</label>
        <input type="text" name="title" value="<?= e($pr['title_' . $orig_lang] ?? '') ?>" required>
      </div>
      <div class="field">
        <label><?= e(t('description_label')) ?></label>
        <textarea name="description"><?= e($pr['description_' . $orig_lang] ?? '') ?></textarea>
      </div>
      <p class="field-hint" style="margin-top:10px"><?= e(t('translating_notice')) ?></p>
    </div>

    <?php if (!$is_new): ?>
    <div class="form-section">
      <p class="section-label"><?= e(t('translations_h')) ?></p>
      <p class="field-hint" style="margin-bottom:16px"><?= e(t('translations_sub')) ?></p>
      <?php foreach ($other_langs as $ol): ?>
      <div style="border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:14px 16px;margin-bottom:12px">
        <div style="font-size:12px;font-weight:700;color:rgba(255,255,255,.6);margin-bottom:10px"><?= e($lang_names[$ol]) ?></div>
        <div class="field" style="margin-bottom:10px">
          <label><?= e(t('title_label')) ?></label>
          <input type="text" name="title_<?= e($ol) ?>" value="<?= e($pr['title_' . $ol] ?? '') ?>">
        </div>
        <div class="field" style="margin-bottom:10px">
          <label><?= e(t('description_label')) ?></label>
          <textarea name="description_<?= e($ol) ?>"><?= e($pr['description_' . $ol] ?? '') ?></textarea>
        </div>
        <label class="check-row" style="font-size:12px">
          <input type="checkbox" name="locked_<?= e($ol) ?>" value="1" <?= in_array($ol, $locked_list, true) ? 'checked' : '' ?>>
          <?= e(t('manually_edited_checkbox')) ?>
        </label>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="form-section">
      <p class="section-label"><?= e(t('details_label')) ?></p>
      <div class="grid-3">
        <div class="field">
          <label><?= e(t('category_label')) ?> *</label>
          <select name="category">
            <option value="artistic"  <?=$pr['category']==='artistic' ?'selected':''?>><?= e(t('cat_artistic')) ?></option>
            <option value="cultural"  <?=$pr['category']==='cultural' ?'selected':''?>><?= e(t('cat_cultural')) ?></option>
            <option value="societate"    <?=$pr['category']==='societate'   ?'selected':''?>><?= e(t('cat_societate')) ?></option>
          </select>
        </div>
        <div class="field">
          <label><?= e(t('th_status')) ?></label>
          <select name="status">
            <option value="draft"     <?=$pr['status']==='draft'     ?'selected':''?>><?= e(t('status_draft')) ?></option>
            <option value="active"    <?=$pr['status']==='active'    ?'selected':''?>><?= e(t('status_active')) ?></option>
            <option value="completed" <?=$pr['status']==='completed' ?'selected':''?>><?= e(t('status_completed')) ?></option>
            <option value="cancelled" <?=$pr['status']==='cancelled' ?'selected':''?>><?= e(t('status_cancelled')) ?></option>
          </select>
        </div>
        <div class="field">
          <label><?= e(t('carousel_order_label')) ?></label>
          <input type="number" name="sort_order" value="<?= (int)$pr['sort_order'] ?>" min="0">
        </div>
      </div>
      <div class="grid-2" style="margin-top:14px">
        <div class="field"><label><?= e(t('label_ro_label')) ?></label><input type="text" name="label_ro" value="<?= e($pr['label_ro']??'') ?>" placeholder="ex: Muzică, Film"></div>
        <div class="field"><label><?= e(t('label_da_label')) ?></label><input type="text" name="label_da" value="<?= e($pr['label_da']??'') ?>" placeholder="ex: Musik, Film"></div>
      </div>
    </div>

    <div class="form-section">
      <p class="section-label"><?= e(t('tags_label')) ?></p>
      <?php if (empty($all_tags)): ?>
        <p style="color:rgba(255,255,255,.45);font-size:14px"><?= e(t('no_tags')) ?> <a href="/admin/settings.php?s=tags"><?= e(t('create_arrow')) ?></a></p>
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
        <p style="font-size:11px;color:rgba(255,255,255,.45);margin-top:8px"><a href="/admin/settings.php?s=tags" style="color:rgba(255,255,255,.45)"><?= e(t('manage_tags_arrow')) ?></a></p>
      <?php endif; ?>
    </div>

    <div class="form-section">
      <p class="section-label"><?= e(t('external_link_label')) ?></p>
      <div class="field" style="max-width:480px">
        <label><?= e(t('external_url_label')) ?></label>
        <input type="url" name="signup_url" value="<?= e($pr['signup_url']??'') ?>" placeholder="https://...">
        <span class="field-hint"><?= e(t('appears_as_button_hint')) ?></span>
      </div>
    </div>

    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <button class="btn btn-solid" type="submit"><?= $is_new ? e(t('add_project_btn')) : e(t('save')) ?></button>
      <a class="btn btn-ghost" href="/admin/projects.php"><?= e(t('cancel')) ?></a>
      <?php if (!$is_new): ?>
        <a class="btn btn-ghost" href="/admin/project-details.php?project=<?= $id ?>" style="margin-left:auto"><?= e(t('public_details_arrow')) ?></a>
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
