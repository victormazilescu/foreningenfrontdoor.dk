<?php
require_once __DIR__ . '/auth.php';
$user   = require_perm('events', 'view');
$pdo    = get_db();
ensure_events_translation_schema($pdo);
ensure_tags_i18n_schema($pdo);
$id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$is_new = $id === 0;
$errors = [];

if ($is_new && !has_perm($user, 'events', 'create')) {
    flash('error', t('cannot_create_events'));
    header('Location: /admin/events.php'); exit;
}

$all_tags      = $pdo->query('SELECT * FROM tags ORDER BY sort_order ASC, name ASC')->fetchAll();
$selected_tags = [];

$ev = [
    'title_ro'=>'','title_da'=>'','title_en'=>'','description_ro'=>'','description_da'=>'','description_en'=>'','orig_lang'=>'ro','locked_langs'=>'',
    'category'=>'artistic','status'=>'active','date'=>'','time'=>'','location'=>'',
    'recurring'=>0,'recurring_rule'=>'','cover_image'=>'','signup_url'=>'',
];
$can_touch = $is_new;

if (!$is_new) {
    $stmt = $pdo->prepare('SELECT * FROM events WHERE id=?');
    $stmt->execute([$id]); $row = $stmt->fetch();
    if (!$row) { header('Location: /admin/events.php'); exit; }
    // Verifică ownership
    if (!can_edit_event($user, (int)($row['created_by'] ?? 0))) {
        flash('error', t('cannot_edit_event'));
        header('Location: /admin/events.php'); exit;
    }
    $can_touch = true;
    $ev = $row;
    if (empty($ev['orig_lang'])) $ev['orig_lang'] = 'ro';
    $tStmt = $pdo->prepare('SELECT tag_id FROM event_tags WHERE event_id=?');
    $tStmt->execute([$id]);
    $selected_tags = array_column($tStmt->fetchAll(), 'tag_id');
}

$lang_names  = ['ro'=>'Română','da'=>'Dansk','en'=>'English'];
$orig_lang   = in_array($ev['orig_lang'] ?? '', array_keys($lang_names), true) ? $ev['orig_lang'] : 'ro';
$other_langs = array_values(array_diff(array_keys($lang_names), [$orig_lang]));
$locked_list = array_filter(explode(',', $ev['locked_langs'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $posted_orig_lang = in_array($_POST['orig_lang'] ?? '', array_keys($lang_names), true) ? $_POST['orig_lang'] : $orig_lang;
    $posted_others    = array_values(array_diff(array_keys($lang_names), [$posted_orig_lang]));
    $f = [
        'title'          => trim($_POST['title']       ?? ''),
        'description'    => trim($_POST['description'] ?? ''),
        'category'       => $_POST['category']  ?? 'artistic',
        'status'         => $_POST['status']    ?? 'active',
        'date'           => trim($_POST['date'] ?? '') ?: null,
        'time'           => $_POST['time']      ?: null,
        'location'       => trim($_POST['location']       ?? ''),
        'recurring'      => isset($_POST['recurring']) ? 1 : 0,
        'recurring_rule' => trim($_POST['recurring_rule'] ?? ''),
        'cover_image'    => $ev['cover_image'],
        'signup_url'     => trim($_POST['signup_url']     ?? ''),
    ];
    $tag_ids = array_map('intval', $_POST['tags'] ?? []);

    if (!$f['title'])    $errors[] = t('title_required');
    if (!in_array($f['category'], ['artistic','cultural','societate'])) $errors[] = t('invalid_category');
    if (!in_array($f['status'], ['active','suspended','cancelled'])) $errors[] = t('invalid_status');

    // Upload copertă
    // Nu avem încredere în Content-Type-ul trimis de browser (poate fi
    // falsificat) și nu folosim extensia din numele fișierului urcat —
    // amândouă sunt controlate de client. Verificăm conținutul real cu
    // getimagesize() și alegem noi extensia, pe baza tipului detectat.
    if (!empty($_FILES['cover_image']['tmp_name']) && $_FILES['cover_image']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['cover_image'];
        $allowed_types = [
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG  => 'png',
            IMAGETYPE_WEBP => 'webp',
        ];
        $info = @getimagesize($file['tmp_name']);
        if ($file['size'] > 5*1024*1024) {
            $errors[] = t('image_too_large');
        } elseif (!$info || !isset($allowed_types[$info[2]])) {
            $errors[] = t('invalid_image_format');
        } else {
            $ext   = $allowed_types[$info[2]];
            $fname = 'event-'.time().'-'.bin2hex(random_bytes(4)).'.'.$ext;
            $dest  = dirname(__DIR__) . '/assets/events/'.$fname;
            if (move_uploaded_file($file['tmp_name'], $dest)) {
                if ($ev['cover_image']) { @unlink(dirname(__DIR__) . '/' . ltrim($ev['cover_image'],'/')); }
                $f['cover_image'] = 'assets/events/'.$fname;
            } else {
                $errors[] = t('image_save_error');
            }
        }
    }

    if (empty($errors)) {
        // Traducere automată titlu+descriere din limba originală în celelalte
        // două (RO/DA/EN) — ca la propuneri. „am editat manual" (per limbă)
        // păstrează varianta scrisă de admin pentru limba respectivă, fără
        // s-o retraducem.
        $existing = [];
        $keep     = [];
        foreach ($posted_others as $ol) {
            $existing['title_' . $ol]       = trim($_POST['title_' . $ol] ?? '');
            $existing['description_' . $ol] = trim($_POST['description_' . $ol] ?? '');
            if ($can_touch && !empty($_POST['locked_' . $ol])) $keep[] = $ol;
        }
        $tr = translate_event_fields($f['title'], $f['description'], $posted_orig_lang, $existing, $keep);
        $locked_langs = implode(',', $keep);

        if ($is_new) {
            $pdo->prepare('INSERT INTO events (title_ro,title_da,title_en,description_ro,description_da,description_en,orig_lang,locked_langs,category,status,date,time,location,recurring,recurring_rule,cover_image,signup_url,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$tr['title_ro'],$tr['title_da'],$tr['title_en'],$tr['description_ro'] ?: null,$tr['description_da'] ?: null,$tr['description_en'] ?: null,$tr['orig_lang'],$locked_langs,$f['category'],$f['status'],$f['date'],$f['time'],$f['location'],$f['recurring'],$f['recurring_rule'],$f['cover_image'],$f['signup_url'],(int)$user['id']]);
            $saved_id = (int)$pdo->lastInsertId();
        } else {
            $pdo->prepare('UPDATE events SET title_ro=?,title_da=?,title_en=?,description_ro=?,description_da=?,description_en=?,orig_lang=?,locked_langs=?,category=?,status=?,date=?,time=?,location=?,recurring=?,recurring_rule=?,cover_image=?,signup_url=? WHERE id=?')
                ->execute([$tr['title_ro'],$tr['title_da'],$tr['title_en'],$tr['description_ro'] ?: null,$tr['description_da'] ?: null,$tr['description_en'] ?: null,$tr['orig_lang'],$locked_langs,$f['category'],$f['status'],$f['date'],$f['time'],$f['location'],$f['recurring'],$f['recurring_rule'],$f['cover_image'],$f['signup_url'],$id]);
            $saved_id = $id;
        }
        // Sync taguri
        $pdo->prepare('DELETE FROM event_tags WHERE event_id=?')->execute([$saved_id]);
        if ($tag_ids) {
            $ins = $pdo->prepare('INSERT IGNORE INTO event_tags (event_id,tag_id) VALUES (?,?)');
            foreach ($tag_ids as $tid) $ins->execute([$saved_id, $tid]);
        }
        flash('ok', $is_new ? t('event_added') : t('event_updated'));
        header('Location: /admin/events.php'); exit;
    }
    // Repopulăm formularul cu ce a scris admin (fără să retraducem —
    // traducerea are loc doar la salvare reușită, mai sus).
    $ev = array_merge($ev, $f, [
        'orig_lang' => $posted_orig_lang,
        'title_' . $posted_orig_lang       => $f['title'],
        'description_' . $posted_orig_lang => $f['description'],
    ]);
    foreach ($posted_others as $ol) {
        $ev['title_' . $ol]       = trim($_POST['title_' . $ol] ?? '');
        $ev['description_' . $ol] = trim($_POST['description_' . $ol] ?? '');
    }
    $locked_posted = [];
    foreach ($posted_others as $ol) { if (!empty($_POST['locked_' . $ol])) $locked_posted[] = $ol; }
    $ev['locked_langs'] = implode(',', $locked_posted);
    $orig_lang   = $posted_orig_lang;
    $other_langs = $posted_others;
    $locked_list = $locked_posted;
    $selected_tags = $tag_ids;
}

layout_head($is_new ? t('new_event') : t('edit_event'), 'events');
?>
<div class="content" style="max-width:860px">

  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:10px">
    <a href="/admin/events.php" style="font-size:13px;color:rgba(255,255,255,.45)"><?= e(t('back_to_events')) ?></a>
    <?php if (!$is_new): ?>
      <a class="btn btn-ghost btn-sm" href="/admin/social.php?event=<?= $id ?>"><?= e(t('social_generator')) ?></a>
      <a class="btn btn-ghost btn-sm" href="/admin/event-social.php?id=<?= $id ?>"><?= e(t('auto_post_link')) ?></a>
    <?php endif; ?>
  </div>

  <h1 style="font-size:22px;font-weight:700;margin-bottom:24px"><?= $is_new ? e(t('new_event')) : e(t('edit_event')) ?></h1>

  <?php if ($errors): ?>
    <div class="errors"><ul><?php foreach($errors as $er):?><li><?= e($er) ?></li><?php endforeach;?></ul></div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data">
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
        <input type="text" name="title" value="<?= e($ev['title_' . $orig_lang] ?? '') ?>" required>
      </div>
      <div class="field">
        <label><?= e(t('description_label')) ?></label>
        <textarea name="description"><?= e($ev['description_' . $orig_lang] ?? '') ?></textarea>
      </div>
      <p class="field-hint" style="margin-top:10px"><?= e(t('translating_notice_event')) ?></p>
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
          <input type="text" name="title_<?= e($ol) ?>" value="<?= e($ev['title_' . $ol] ?? '') ?>">
        </div>
        <div class="field" style="margin-bottom:10px">
          <label><?= e(t('description_label')) ?></label>
          <textarea name="description_<?= e($ol) ?>"><?= e($ev['description_' . $ol] ?? '') ?></textarea>
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
      <p class="section-label"><?= e(t('event_details')) ?></p>
      <div class="grid-3">
        <div class="field">
          <label><?= e(t('category_label')) ?> *</label>
          <select name="category">
            <option value="artistic" <?=$ev['category']==='artistic'?'selected':''?>><?= e(t('cat_artistic')) ?></option>
            <option value="cultural" <?=$ev['category']==='cultural'?'selected':''?>><?= e(t('cat_cultural')) ?></option>
            <option value="societate"   <?=$ev['category']==='societate'  ?'selected':''?>><?= e(t('cat_societate')) ?></option>
          </select>
        </div>
        <div class="field">
          <label><?= e(t('th_status')) ?></label>
          <select name="status">
            <option value="active"    <?=$ev['status']==='active'   ?'selected':''?>><?= e(t('status_active')) ?></option>
            <option value="suspended" <?=$ev['status']==='suspended'?'selected':''?>><?= e(t('status_suspended')) ?></option>
            <option value="cancelled" <?=$ev['status']==='cancelled'?'selected':''?>><?= e(t('status_cancelled')) ?></option>
          </select>
        </div>
        <div class="field"><label><?= e(t('location_label')) ?></label><input type="text" name="location" value="<?= e($ev['location']??'') ?>" placeholder="<?= e(t('location_placeholder')) ?>"></div>
      </div>
      <div class="grid-2" style="margin-top:14px">
        <div class="field"><label><?= e(t('date_label')) ?></label><input type="date" name="date" value="<?= e($ev['date'] ?? '') ?>" placeholder="<?= e(t('date_tbd')) ?>"><span class="field-hint"><?= e(t('date_tbd_hint')) ?></span></div>
        <div class="field"><label><?= e(t('time_optional')) ?></label><input type="time" name="time" value="<?= e(substr($ev['time']??'',0,5)) ?>"></div>
      </div>
      <div style="margin-top:14px;display:flex;flex-direction:column;gap:10px">
        <label class="check-row">
          <input type="checkbox" name="recurring" <?=$ev['recurring']?'checked':''?>>
          <?= e(t('recurring_event')) ?>
        </label>
        <div class="field" style="max-width:400px">
          <label><?= e(t('recurring_rule_label')) ?></label>
          <input type="text" name="recurring_rule" value="<?= e($ev['recurring_rule']??'') ?>" placeholder="<?= e(t('recurring_rule_ph')) ?>">
        </div>
      </div>
    </div>

    <div class="form-section">
      <p class="section-label"><?= e(t('cover_section_label')) ?></p>
      <?php if ($ev['cover_image']): ?>
        <img class="cover-preview" src="/<?= e(ltrim($ev['cover_image'],'/')) ?>" alt="">
        <p style="font-size:11px;color:rgba(255,255,255,.45);margin-bottom:10px"><?= e(t('current_image_hint')) ?></p>
      <?php endif; ?>
      <input type="file" name="cover_image" accept="image/jpeg,image/png,image/webp" style="font-size:13px;color:rgba(255,255,255,.65)">
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
                     id="etag-<?= (int)$tag['id'] ?>" value="<?= (int)$tag['id'] ?>"
                     <?= in_array((int)$tag['id'], $selected_tags)?'checked':'' ?>>
              <label class="tag-lbl" for="etag-<?= (int)$tag['id'] ?>">
                <span class="tag-dot" style="background:<?= e($tag['color']) ?>"></span>
                <?= e($tag['name_' . ui_lang()] ?: $tag['name']) ?>
              </label>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="form-section">
      <p class="section-label"><?= e(t('signup_link_label')) ?></p>
      <div class="field" style="max-width:480px">
        <label><?= e(t('signup_url_label')) ?></label>
        <input type="url" name="signup_url" value="<?= e($ev['signup_url']??'') ?>" placeholder="https://...">
        <span class="field-hint"><?= e(t('signup_hint')) ?></span>
      </div>
    </div>

    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <button class="btn btn-solid" type="submit"><?= $is_new ? e(t('add_event_btn')) : e(t('save')) ?></button>
      <a class="btn btn-ghost" href="/admin/events.php"><?= e(t('cancel')) ?></a>
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
