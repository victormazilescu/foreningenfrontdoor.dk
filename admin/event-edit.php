<?php
require_once __DIR__ . '/auth.php';
$user   = require_perm('events', 'view');
$pdo    = get_db();
$id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$is_new = $id === 0;
$errors = [];

if ($is_new && !has_perm($user, 'events', 'create')) {
    flash('error', 'Nu poți crea evenimente noi.');
    header('Location: /admin/events.php'); exit;
}

$all_tags      = $pdo->query('SELECT * FROM tags ORDER BY sort_order ASC, name ASC')->fetchAll();
$selected_tags = [];

$ev = [
    'title_ro'=>'','title_da'=>'','description_ro'=>'','description_da'=>'',
    'category'=>'artistic','status'=>'active','date'=>'','time'=>'','location'=>'',
    'recurring'=>0,'recurring_rule'=>'','cover_image'=>'','signup_url'=>'',
];

if (!$is_new) {
    $stmt = $pdo->prepare('SELECT * FROM events WHERE id=?');
    $stmt->execute([$id]); $row = $stmt->fetch();
    if (!$row) { header('Location: /admin/events.php'); exit; }
    // Verifică ownership
    if (!can_edit_event($user, (int)($row['created_by'] ?? 0))) {
        flash('error','Nu poți edita acest eveniment.');
        header('Location: /admin/events.php'); exit;
    }
    $ev = $row;
    $tStmt = $pdo->prepare('SELECT tag_id FROM event_tags WHERE event_id=?');
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
        'category'       => $_POST['category']  ?? 'artistic',
        'status'         => $_POST['status']    ?? 'active',
        'date'           => $_POST['date']      ?? '',
        'time'           => $_POST['time']      ?: null,
        'location'       => trim($_POST['location']       ?? ''),
        'recurring'      => isset($_POST['recurring']) ? 1 : 0,
        'recurring_rule' => trim($_POST['recurring_rule'] ?? ''),
        'cover_image'    => $ev['cover_image'],
        'signup_url'     => trim($_POST['signup_url']     ?? ''),
    ];
    $tag_ids = array_map('intval', $_POST['tags'] ?? []);

    if (!$f['title_ro']) $errors[] = 'Titlu RO obligatoriu.';
    if (!$f['title_da']) $errors[] = 'Titlu DA obligatoriu.';
    if (!$f['date'])     $errors[] = 'Data obligatorie.';
    if (!in_array($f['category'], ['artistic','cultural','societate'])) $errors[] = 'Categorie invalidă.';
    if (!in_array($f['status'], ['active','suspended','cancelled'])) $errors[] = 'Status invalid.';

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
            $errors[] = 'Imaginea depășește 5 MB.';
        } elseif (!$info || !isset($allowed_types[$info[2]])) {
            $errors[] = 'Format imagine invalid (JPG, PNG, WebP).';
        } else {
            $ext   = $allowed_types[$info[2]];
            $fname = 'event-'.time().'-'.bin2hex(random_bytes(4)).'.'.$ext;
            $dest  = dirname(__DIR__) . '/assets/events/'.$fname;
            if (move_uploaded_file($file['tmp_name'], $dest)) {
                if ($ev['cover_image']) { @unlink(dirname(__DIR__) . '/' . ltrim($ev['cover_image'],'/')); }
                $f['cover_image'] = 'assets/events/'.$fname;
            } else {
                $errors[] = 'Eroare la salvarea imaginii.';
            }
        }
    }

    if (empty($errors)) {
        if ($is_new) {
            $pdo->prepare('INSERT INTO events (title_ro,title_da,description_ro,description_da,category,status,date,time,location,recurring,recurring_rule,cover_image,signup_url,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$f['title_ro'],$f['title_da'],$f['description_ro'],$f['description_da'],$f['category'],$f['status'],$f['date'],$f['time'],$f['location'],$f['recurring'],$f['recurring_rule'],$f['cover_image'],$f['signup_url'],(int)$user['id']]);
            $saved_id = (int)$pdo->lastInsertId();
        } else {
            $pdo->prepare('UPDATE events SET title_ro=?,title_da=?,description_ro=?,description_da=?,category=?,status=?,date=?,time=?,location=?,recurring=?,recurring_rule=?,cover_image=?,signup_url=? WHERE id=?')
                ->execute([$f['title_ro'],$f['title_da'],$f['description_ro'],$f['description_da'],$f['category'],$f['status'],$f['date'],$f['time'],$f['location'],$f['recurring'],$f['recurring_rule'],$f['cover_image'],$f['signup_url'],$id]);
            $saved_id = $id;
        }
        // Sync taguri
        $pdo->prepare('DELETE FROM event_tags WHERE event_id=?')->execute([$saved_id]);
        if ($tag_ids) {
            $ins = $pdo->prepare('INSERT IGNORE INTO event_tags (event_id,tag_id) VALUES (?,?)');
            foreach ($tag_ids as $tid) $ins->execute([$saved_id, $tid]);
        }
        flash('ok', $is_new ? 'Eveniment adăugat.' : 'Eveniment actualizat.');
        header('Location: /admin/events.php'); exit;
    }
    $ev = array_merge($ev, $f);
    $selected_tags = $tag_ids;
}

layout_head($is_new ? 'Eveniment nou' : 'Editează eveniment', 'events');
?>
<div class="content" style="max-width:860px">

  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:10px">
    <a href="/admin/events.php" style="font-size:13px;color:rgba(255,255,255,.25)">← Înapoi la evenimente</a>
    <?php if (!$is_new): ?>
      <a class="btn btn-ghost btn-sm" href="/admin/social.php?event=<?= $id ?>">📢 Generator Social</a>
    <?php endif; ?>
  </div>

  <h1 style="font-size:22px;font-weight:700;margin-bottom:24px"><?= $is_new ? 'Eveniment nou' : 'Editează eveniment' ?></h1>

  <?php if ($errors): ?>
    <div class="errors"><ul><?php foreach($errors as $er):?><li><?= e($er) ?></li><?php endforeach;?></ul></div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

    <div class="form-section">
      <p class="section-label">Titlu</p>
      <div class="grid-2">
        <div class="field"><label>Titlu în română *</label><input type="text" name="title_ro" value="<?= e($ev['title_ro']) ?>" required></div>
        <div class="field"><label>Titlu în daneză *</label><input type="text" name="title_da" value="<?= e($ev['title_da']) ?>" required></div>
      </div>
    </div>

    <div class="form-section">
      <p class="section-label">Descriere</p>
      <div class="grid-2">
        <div class="field"><label>Descriere în română</label><textarea name="description_ro"><?= e($ev['description_ro']) ?></textarea></div>
        <div class="field"><label>Descriere în daneză</label><textarea name="description_da"><?= e($ev['description_da']) ?></textarea></div>
      </div>
    </div>

    <div class="form-section">
      <p class="section-label">Detalii eveniment</p>
      <div class="grid-3">
        <div class="field">
          <label>Categorie *</label>
          <select name="category">
            <option value="artistic" <?=$ev['category']==='artistic'?'selected':''?>>Artistic</option>
            <option value="cultural" <?=$ev['category']==='cultural'?'selected':''?>>Cultural</option>
            <option value="societate"   <?=$ev['category']==='societate'  ?'selected':''?>>Societate</option>
          </select>
        </div>
        <div class="field">
          <label>Status</label>
          <select name="status">
            <option value="active"    <?=$ev['status']==='active'   ?'selected':''?>>Activ</option>
            <option value="suspended" <?=$ev['status']==='suspended'?'selected':''?>>Suspendat</option>
            <option value="cancelled" <?=$ev['status']==='cancelled'?'selected':''?>>Anulat</option>
          </select>
        </div>
        <div class="field"><label>Locație</label><input type="text" name="location" value="<?= e($ev['location']??'') ?>" placeholder="ex: København, Café X"></div>
      </div>
      <div class="grid-2" style="margin-top:14px">
        <div class="field"><label>Data *</label><input type="date" name="date" value="<?= e($ev['date']) ?>" required></div>
        <div class="field"><label>Ora (opțional)</label><input type="time" name="time" value="<?= e(substr($ev['time']??'',0,5)) ?>"></div>
      </div>
      <div style="margin-top:14px;display:flex;flex-direction:column;gap:10px">
        <label class="check-row">
          <input type="checkbox" name="recurring" <?=$ev['recurring']?'checked':''?>>
          Eveniment recurent
        </label>
        <div class="field" style="max-width:400px">
          <label>Regulă recurență (opțional)</label>
          <input type="text" name="recurring_rule" value="<?= e($ev['recurring_rule']??'') ?>" placeholder="ex: Lunar, a doua vineri">
        </div>
      </div>
    </div>

    <div class="form-section">
      <p class="section-label">Copertă (imagine pătrată recomandată · JPG, PNG, WebP · max 5 MB)</p>
      <?php if ($ev['cover_image']): ?>
        <img class="cover-preview" src="/<?= e(ltrim($ev['cover_image'],'/')) ?>" alt="">
        <p style="font-size:11px;color:rgba(255,255,255,.25);margin-bottom:10px">Imagine curentă. Urcă una nouă pentru a o înlocui.</p>
      <?php endif; ?>
      <input type="file" name="cover_image" accept="image/jpeg,image/png,image/webp" style="font-size:13px;color:rgba(255,255,255,.4)">
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
                     id="etag-<?= (int)$tag['id'] ?>" value="<?= (int)$tag['id'] ?>"
                     <?= in_array((int)$tag['id'], $selected_tags)?'checked':'' ?>>
              <label class="tag-lbl" for="etag-<?= (int)$tag['id'] ?>">
                <span class="tag-dot" style="background:<?= e($tag['color']) ?>"></span>
                <?= e($tag['name']) ?>
              </label>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="form-section">
      <p class="section-label">Link înscriere (opțional)</p>
      <div class="field" style="max-width:480px">
        <label>URL platformă externă (minforening, Eventbrite etc.)</label>
        <input type="url" name="signup_url" value="<?= e($ev['signup_url']??'') ?>" placeholder="https://...">
        <span class="field-hint">Dacă e completat, apare butonul „Înscrie-te" pe site.</span>
      </div>
    </div>

    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <button class="btn btn-solid" type="submit"><?= $is_new ? 'Adaugă evenimentul' : 'Salvează' ?></button>
      <a class="btn btn-ghost" href="/admin/events.php">Anulează</a>
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
