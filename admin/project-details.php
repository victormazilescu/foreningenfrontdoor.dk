<?php
require_once __DIR__ . '/auth.php';
$user = require_perm('projects', 'view');
$can_edit_details = has_perm($user, 'projects', 'edit');
$pdo = get_db();

$project_id = isset($_GET['project']) ? (int)$_GET['project'] : 0;
if (!$project_id) { header('Location: /admin/projects.php'); exit; }

$project = $pdo->prepare('SELECT * FROM projects WHERE id=?');
$project->execute([$project_id]);
$project = $project->fetch();
if (!$project) { header('Location: /admin/projects.php'); exit; }

// Asigură tabelul project_details (failsafe)
try { $pdo->exec("CREATE TABLE IF NOT EXISTS `project_details` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` INT UNSIGNED NOT NULL,
  `headline_ro` VARCHAR(255) NULL, `headline_da` VARCHAR(255) NULL, `headline_en` VARCHAR(255) NULL,
  `story_ro` TEXT NULL, `story_da` TEXT NULL, `story_en` TEXT NULL,
  `budget_needed` DECIMAL(10,2) NULL, `budget_raised` DECIMAL(10,2) NULL DEFAULT 0,
  `budget_breakdown_ro` TEXT NULL, `budget_breakdown_da` TEXT NULL, `budget_breakdown_en` TEXT NULL,
  `orig_lang` VARCHAR(2) NOT NULL DEFAULT 'ro', `locked_langs` VARCHAR(10) NOT NULL DEFAULT '',
  `photo_1` VARCHAR(500) NULL, `photo_2` VARCHAR(500) NULL,
  `photo_3` VARCHAR(500) NULL, `photo_4` VARCHAR(500) NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_project` (`project_id`),
  FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"); } catch(PDOException $e) {}

define('PHOTO_DIR', dirname(__DIR__) . '/assets/projects/');
define('PHOTO_URL', '/assets/projects/');
define('MAX_PHOTO', 5 * 1024 * 1024); // 5MB
if (!is_dir(PHOTO_DIR)) mkdir(PHOTO_DIR, 0755, true);

// Asigură tabelul project_donors (failsafe) — acum „Finanțare”, nu doar donatori.
try { $pdo->exec("CREATE TABLE IF NOT EXISTS `project_donors` (
  `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `project_id` INT UNSIGNED  NOT NULL,
  `name`       VARCHAR(255)  NOT NULL DEFAULT 'Donație anonimă',
  `donor_type` ENUM('membru','extern','anonim') NOT NULL DEFAULT 'extern',
  `method`     ENUM('transfer','bunuri_servicii','voluntariat') NOT NULL DEFAULT 'transfer',
  `entry_type` VARCHAR(30)   NOT NULL DEFAULT 'donatie',
  `entry_type_custom` VARCHAR(100) NULL,
  `funder_name`      VARCHAR(255) NULL,
  `reference_number` VARCHAR(100) NULL,
  `period_start` DATE NULL, `period_end` DATE NULL,
  `details`    VARCHAR(500)  NULL,
  `value_dkk`  DECIMAL(10,2) NULL,
  `sort_order` SMALLINT      NOT NULL DEFAULT 0,
  `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `idx_project` (`project_id`),
  FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"); } catch(PDOException $e) {}

// Lazy-migrează tabele deja existente pe server (create table if not exists nu
// adaugă coloane noi la un tabel care există deja de dinainte de acest update).
ensure_project_details_translation_schema($pdo);

// Încarcă detalii existente
$det = $pdo->prepare('SELECT * FROM project_details WHERE project_id=?');
$det->execute([$project_id]);
$det_row = $det->fetch();
$det_exists = (bool)$det_row;
$det = $det_row ?: [
    'headline_ro' => '', 'headline_da' => '', 'headline_en' => '',
    'story_ro' => '', 'story_da' => '', 'story_en' => '',
    'budget_needed' => '', 'budget_raised' => '0',
    'budget_breakdown_ro' => '', 'budget_breakdown_da' => '', 'budget_breakdown_en' => '',
    'orig_lang' => 'ro', 'locked_langs' => '',
    'photo_1' => null, 'photo_2' => null, 'photo_3' => null, 'photo_4' => null,
];

$lang_names   = ['ro'=>'Română','da'=>'Dansk','en'=>'English'];
$orig_lang    = in_array($det['orig_lang'] ?? '', array_keys($lang_names), true) ? $det['orig_lang'] : 'ro';
$other_langs  = array_values(array_diff(array_keys($lang_names), [$orig_lang]));
$locked_list  = array_filter(explode(',', $det['locked_langs'] ?? ''));

$entry_type_options = ['donatie','contributie','linie_finantare','grant','sponsorizare','cotizatie','custom'];
$entry_type_extra_types = ['grant', 'linie_finantare']; // au câmpuri suplimentare (finanțator, referință, perioadă)

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (!$can_edit_details) {
        http_response_code(403);
        die(t('no_edit_details_perm'));
    }

    // Finanțare — acțiuni separate (add/delete), nu blochează salvarea detaliilor.
    if (isset($_POST['donor_action'])) {
        $da = $_POST['donor_action'];
        if ($da === 'add_donor') {
            $dname   = trim($_POST['donor_name'] ?? '') ?: 'Donație anonimă';
            $dtype   = in_array($_POST['donor_type'] ?? '', ['membru','extern','anonim']) ? $_POST['donor_type'] : 'extern';
            $dmethod = in_array($_POST['donor_method'] ?? '', ['transfer','bunuri_servicii','voluntariat']) ? $_POST['donor_method'] : 'transfer';
            $etype   = in_array($_POST['entry_type'] ?? '', $entry_type_options, true) ? $_POST['entry_type'] : 'donatie';
            $etype_custom = $etype === 'custom' ? trim($_POST['entry_type_custom'] ?? '') : null;
            $funder  = in_array($etype, $entry_type_extra_types, true) ? (trim($_POST['funder_name'] ?? '') ?: null) : null;
            $refnum  = in_array($etype, $entry_type_extra_types, true) ? (trim($_POST['reference_number'] ?? '') ?: null) : null;
            $pstart  = in_array($etype, $entry_type_extra_types, true) && !empty($_POST['period_start']) ? $_POST['period_start'] : null;
            $pend    = in_array($etype, $entry_type_extra_types, true) && !empty($_POST['period_end'])   ? $_POST['period_end']   : null;
            $ddetails = trim($_POST['donor_details'] ?? '');
            $dvalue   = $_POST['donor_value'] !== '' ? (float)str_replace(',','.', $_POST['donor_value'] ?? '') : null;
            $dsort    = (int)($_POST['donor_sort'] ?? 0);
            $pdo->prepare('INSERT INTO project_donors (project_id,name,donor_type,method,entry_type,entry_type_custom,funder_name,reference_number,period_start,period_end,details,value_dkk,sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$project_id, $dname, $dtype, $dmethod, $etype, $etype_custom, $funder, $refnum, $pstart, $pend, $ddetails ?: null, $dvalue, $dsort]);
            flash('ok', t('donor_added'));
            header('Location: /admin/project-details.php?project=' . $project_id); exit;
        }
        if ($da === 'delete_donor') {
            $did = (int)($_POST['donor_id'] ?? 0);
            if ($did) $pdo->prepare('DELETE FROM project_donors WHERE id=? AND project_id=?')->execute([$did, $project_id]);
            flash('ok', t('donor_deleted'));
            header('Location: /admin/project-details.php?project=' . $project_id); exit;
        }
    }

    $posted_orig_lang = in_array($_POST['orig_lang'] ?? '', array_keys($lang_names), true) ? $_POST['orig_lang'] : $orig_lang;
    $posted_others    = array_values(array_diff(array_keys($lang_names), [$posted_orig_lang]));

    $f = [
        'headline'             => trim($_POST['headline']             ?? ''),
        'story'                => trim($_POST['story']                ?? ''),
        'budget_needed'        => $_POST['budget_needed'] !== '' ? (float)str_replace(',','.', $_POST['budget_needed'] ?? '') : null,
        'budget_raised'        => (float)str_replace(',','.', $_POST['budget_raised'] ?? '0'),
        'budget_breakdown'     => trim($_POST['budget_breakdown']     ?? ''),
    ];

    // Procesare poze
    $photos = [
        'photo_1' => $det['photo_1'],
        'photo_2' => $det['photo_2'],
        'photo_3' => $det['photo_3'],
        'photo_4' => $det['photo_4'],
    ];

    foreach ([1,2,3,4] as $n) {
        $key = 'photo_' . $n;
        // Ștergere foto existentă
        if (isset($_POST['delete_' . $key])) {
            if ($photos[$key]) {
                $abs = dirname(__DIR__) . '/' . ltrim($photos[$key], '/');
                if (file_exists($abs)) unlink($abs);
            }
            $photos[$key] = null;
        }
        // Upload foto nouă
        if (!empty($_FILES[$key]['name'])) {
            $file = $_FILES[$key];
            $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if ($file['error'] !== UPLOAD_ERR_OK)          $errors[] = t('photo_upload_error') . " $n.";
            elseif ($file['size'] > MAX_PHOTO)              $errors[] = t('photo_word') . " $n " . t('exceeds_5mb') . '.';
            elseif (!in_array($ext, ['jpg','jpeg','png','webp'])) $errors[] = t('photo_word') . " $n: " . t('jpg_png_webp_only') . '.';
            else {
                // Șterge vechea poză dacă există
                if ($photos[$key]) {
                    $abs = dirname(__DIR__) . '/' . ltrim($photos[$key], '/');
                    if (file_exists($abs)) unlink($abs);
                }
                $fname = 'proj_' . $project_id . '_' . $n . '_' . time() . '.' . $ext;
                if (!move_uploaded_file($file['tmp_name'], PHOTO_DIR . $fname)) {
                    $errors[] = t('could_not_save_photo') . " $n.";
                } else {
                    $photos[$key] = PHOTO_URL . $fname;
                }
            }
        }
    }

    if (empty($errors)) {
        // Traducere automată (headline + story + budget_breakdown) — RO/DA/EN,
        // ca la evenimente/proiecte. „am editat manual” per limbă păstrează ce
        // a scris admin acolo, fără retraducere.
        $existing = [];
        $keep     = [];
        foreach ($posted_others as $ol) {
            $existing['headline_' . $ol]         = trim($_POST['headline_' . $ol]         ?? '');
            $existing['story_' . $ol]            = trim($_POST['story_' . $ol]            ?? '');
            $existing['budget_breakdown_' . $ol] = trim($_POST['budget_breakdown_' . $ol] ?? '');
            if ($det_exists && !empty($_POST['locked_' . $ol])) $keep[] = $ol;
        }
        $tr = translate_project_fields(
            ['headline' => $f['headline'], 'story' => $f['story'], 'budget_breakdown' => $f['budget_breakdown']],
            $posted_orig_lang, $existing, $keep
        );
        $locked_langs = implode(',', $keep);

        $merged = array_merge($photos, [
            'budget_needed' => $f['budget_needed'],
            'budget_raised' => $f['budget_raised'],
        ]);
        // UPSERT
        $existing_row = $pdo->prepare('SELECT id FROM project_details WHERE project_id=?');
        $existing_row->execute([$project_id]);
        if ($existing_row->fetch()) {
            $pdo->prepare('UPDATE project_details SET
                headline_ro=?, headline_da=?, headline_en=?, story_ro=?, story_da=?, story_en=?,
                budget_needed=?, budget_raised=?, budget_breakdown_ro=?, budget_breakdown_da=?, budget_breakdown_en=?,
                orig_lang=?, locked_langs=?,
                photo_1=?, photo_2=?, photo_3=?, photo_4=?
                WHERE project_id=?')
                ->execute([
                    $tr['headline_ro'], $tr['headline_da'], $tr['headline_en'],
                    $tr['story_ro'] ?: null, $tr['story_da'] ?: null, $tr['story_en'] ?: null,
                    $merged['budget_needed'], $merged['budget_raised'],
                    $tr['budget_breakdown_ro'] ?: null, $tr['budget_breakdown_da'] ?: null, $tr['budget_breakdown_en'] ?: null,
                    $tr['orig_lang'], $locked_langs,
                    $merged['photo_1'], $merged['photo_2'], $merged['photo_3'], $merged['photo_4'],
                    $project_id
                ]);
        } else {
            $pdo->prepare('INSERT INTO project_details
                (project_id, headline_ro, headline_da, headline_en, story_ro, story_da, story_en,
                 budget_needed, budget_raised, budget_breakdown_ro, budget_breakdown_da, budget_breakdown_en,
                 orig_lang, locked_langs,
                 photo_1, photo_2, photo_3, photo_4)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([
                    $project_id,
                    $tr['headline_ro'], $tr['headline_da'], $tr['headline_en'],
                    $tr['story_ro'] ?: null, $tr['story_da'] ?: null, $tr['story_en'] ?: null,
                    $merged['budget_needed'], $merged['budget_raised'],
                    $tr['budget_breakdown_ro'] ?: null, $tr['budget_breakdown_da'] ?: null, $tr['budget_breakdown_en'] ?: null,
                    $tr['orig_lang'], $locked_langs,
                    $merged['photo_1'], $merged['photo_2'], $merged['photo_3'], $merged['photo_4'],
                ]);
        }
        $_SESSION['flash'] = ['type' => 'ok', 'msg' => t('details_updated')];
        header('Location: /admin/project-details.php?project=' . $project_id); exit;
    }
    // Repopulăm formularul cu ce a scris admin.
    $det = array_merge($det, $photos, [
        'budget_needed' => $f['budget_needed'], 'budget_raised' => $f['budget_raised'],
        'orig_lang' => $posted_orig_lang,
        'headline_' . $posted_orig_lang         => $f['headline'],
        'story_' . $posted_orig_lang            => $f['story'],
        'budget_breakdown_' . $posted_orig_lang => $f['budget_breakdown'],
    ]);
    foreach ($posted_others as $ol) {
        $det['headline_' . $ol]         = trim($_POST['headline_' . $ol]         ?? '');
        $det['story_' . $ol]            = trim($_POST['story_' . $ol]            ?? '');
        $det['budget_breakdown_' . $ol] = trim($_POST['budget_breakdown_' . $ol] ?? '');
    }
    $locked_posted = [];
    foreach ($posted_others as $ol) { if (!empty($_POST['locked_' . $ol])) $locked_posted[] = $ol; }
    $det['locked_langs'] = implode(',', $locked_posted);
    $orig_lang   = $posted_orig_lang;
    $other_langs = $posted_others;
    $locked_list = $locked_posted;
}


$donors = $pdo->prepare('SELECT * FROM project_donors WHERE project_id=? ORDER BY sort_order ASC, id ASC');
$donors->execute([$project_id]);
$donors = $donors->fetchAll();

$flash = get_flash();
layout_head(t('details_title'), 'projects');
?>
<div class="content">
<div class="page-head">
      <h1><?= e(t('details_title')) ?></h1>
    </div>
    <div class="project-meta">
      <?= e(t('project_word')) ?>: <strong><?= e($project['title_ro']) ?></strong> · <?= e($project['title_da']) ?>
      · <a href="/admin/project-edit.php?id=<?= $project_id ?>"><?= e(t('back_to_edit')) ?></a>
    </div>

    <?php if ($flash): ?>
      <div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div>
    <?php endif; ?>
    <?php if ($errors): ?>
      <div class="errors"><strong><?= e(t('errors_label')) ?></strong><ul><?php foreach($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <?php if (!$can_edit_details): ?>
      <div class="flash flash-error"><?= e(t('view_only_details')) ?></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" <?= $can_edit_details ? '' : 'style="pointer-events:none;opacity:.65"' ?>>
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

      <!-- TITLU SCURT -->
      <div class="card">
        <div class="card-title"><?= e(t('transparency_title_label')) ?></div>
        <?php if (!$det_exists): ?>
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
        <div class="field">
          <label><?= e(t('title_label')) ?></label>
          <input type="text" name="headline" value="<?= e($det['headline_' . $orig_lang] ?? '') ?>" placeholder="ex: Teatru Nonformal — Pilot 2026">
        </div>
      </div>

      <!-- POVESTE -->
      <div class="card">
        <div class="card-title"><?= e(t('project_story_title')) ?></div>
        <div class="field">
          <label><?= e(t('description_label')) ?></label>
          <textarea name="story" rows="8"><?= e($det['story_' . $orig_lang] ?? '') ?></textarea>
        </div>
      </div>

      <!-- BUGET -->
      <div class="card">
        <div class="card-title"><?= e(t('budget_title')) ?></div>
        <div class="grid-2" style="margin-bottom:16px">
          <div class="field">
            <label><?= e(t('budget_needed_label')) ?></label>
            <input type="number" name="budget_needed" value="<?= e($det['budget_needed']) ?>" min="0" step="0.01" placeholder="0.00">
            <span class="hint"><?= e(t('budget_needed_hint')) ?></span>
          </div>
          <div class="field">
            <label><?= e(t('budget_raised_label')) ?></label>
            <input type="number" name="budget_raised" value="<?= e($det['budget_raised'] ?? 0) ?>" min="0" step="0.01">
          </div>
        </div>
        <?php
          $needed = (float)($det['budget_needed'] ?? 0);
          $raised = (float)($det['budget_raised'] ?? 0);
          $pct    = $needed > 0 ? min(100, round($raised / $needed * 100)) : 0;
        ?>
        <?php if ($needed > 0): ?>
          <div class="progress-bar">
            <div class="progress-fill" style="width:<?= $pct ?>%"></div>
          </div>
          <div style="font-size:12px;color:rgba(255,255,255,.65);margin-top:6px"><?= $pct ?>% <?= e(t('funded_label')) ?> (<?= number_format($raised, 0, ',', '.') ?> / <?= number_format($needed, 0, ',', '.') ?> DKK)</div>
        <?php endif; ?>
        <div class="field" style="margin-top:16px">
          <label><?= e(t('expense_breakdown_ro_label')) ?></label>
          <textarea name="budget_breakdown" rows="5" placeholder="ex:&#10;- Materiale costume: 2.000 DKK&#10;- Închiriere sală: 1.500 DKK&#10;- Transport: 500 DKK"><?= e($det['budget_breakdown_' . $orig_lang] ?? '') ?></textarea>
        </div>
      </div>

      <?php if ($det_exists): ?>
      <!-- TRADUCERI AUTOMATE -->
      <div class="card">
        <div class="card-title"><?= e(t('translations_h')) ?></div>
        <p class="field-hint" style="margin-bottom:16px"><?= e(t('translations_sub')) ?></p>
        <?php foreach ($other_langs as $ol): ?>
        <div style="border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:14px 16px;margin-bottom:12px">
          <div style="font-size:12px;font-weight:700;color:rgba(255,255,255,.6);margin-bottom:10px"><?= e($lang_names[$ol]) ?></div>
          <div class="field" style="margin-bottom:10px">
            <label><?= e(t('transparency_title_label')) ?></label>
            <input type="text" name="headline_<?= e($ol) ?>" value="<?= e($det['headline_' . $ol] ?? '') ?>">
          </div>
          <div class="field" style="margin-bottom:10px">
            <label><?= e(t('project_story_title')) ?></label>
            <textarea name="story_<?= e($ol) ?>" rows="5"><?= e($det['story_' . $ol] ?? '') ?></textarea>
          </div>
          <div class="field" style="margin-bottom:10px">
            <label><?= e(t('expense_breakdown_ro_label')) ?></label>
            <textarea name="budget_breakdown_<?= e($ol) ?>" rows="4"><?= e($det['budget_breakdown_' . $ol] ?? '') ?></textarea>
          </div>
          <label class="check-row" style="font-size:12px">
            <input type="checkbox" name="locked_<?= e($ol) ?>" value="1" <?= in_array($ol, $locked_list, true) ? 'checked' : '' ?>>
            <?= e(t('manually_edited_checkbox')) ?>
          </label>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <!-- POZE -->
      <div class="card">
        <div class="card-title"><?= e(t('photo_gallery_title')) ?></div>
        <div class="grid-4">
          <?php foreach ([1,2,3,4] as $n):
            $pkey = 'photo_' . $n;
            $existing_photo = $det[$pkey] ?? null;
          ?>
            <div class="photo-slot">
              <?php if ($existing_photo): ?>
                <img class="photo-preview" src="<?= e($existing_photo) ?>" alt="<?= e(t('photo_word')) ?> <?= $n ?>">
                <label class="delete-check">
                  <input type="checkbox" name="delete_<?= $pkey ?>" value="1"> <?= e(t('delete_photo_label')) ?>
                </label>
              <?php else: ?>
                <div class="photo-empty"><?= e(t('photo_word')) ?> <?= $n ?></div>
              <?php endif; ?>
              <input type="file" name="<?= $pkey ?>" accept=".jpg,.jpeg,.png,.webp">
              <span class="hint"><?= e(t('max_5mb')) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- BUTOANE -->
      <div style="display:flex;gap:12px;flex-wrap:wrap">
        <?php if ($can_edit_details): ?>
          <button class="btn btn-solid" type="submit"><?= e(t('save_details_btn')) ?></button>
        <?php endif; ?>
        <a class="btn btn-ghost" href="/admin/projects.php" style="pointer-events:auto"><?= e(t('back_to_projects')) ?></a>
      </div>
    </form>

    <!-- FINANȚARE (fost „Donatori & Contribuții”) -->
    <?php
    $method_labels = ['transfer'=>t('method_transfer'),'bunuri_servicii'=>t('method_bunuri'),'voluntariat'=>t('method_voluntariat')];
    $type_labels   = ['membru'=>t('type_membru'),'extern'=>t('type_extern'),'anonim'=>t('type_anonim')];
    $entry_type_labels = [
        'donatie'         => t('entry_type_donatie'),
        'contributie'     => t('entry_type_contributie'),
        'linie_finantare' => t('entry_type_linie'),
        'grant'           => t('entry_type_grant'),
        'sponsorizare'    => t('entry_type_sponsorizare'),
        'cotizatie'       => t('entry_type_cotizatie'),
        'custom'          => t('entry_type_custom'),
    ];
    ?>
    <div class="card" style="margin-top:20px">
      <div class="card-title"><?= e(t('donors_title')) ?></div>

      <!-- Lista intrărilor existente -->
      <?php if ($donors): ?>
        <div class="table-wrap" style="margin-bottom:24px">
          <table>
            <thead>
              <tr>
                <th><?= e(t('th_name')) ?></th>
                <th><?= e(t('entry_type_label')) ?></th>
                <th><?= e(t('th_type')) ?></th>
                <th><?= e(t('th_method')) ?></th>
                <th><?= e(t('th_reference')) ?></th>
                <th><?= e(t('th_details')) ?></th>
                <th><?= e(t('th_value')) ?></th>
                <th></th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($donors as $d):
              $etype = $d['entry_type'] ?? 'donatie';
              $etype_label = $etype === 'custom' ? ($d['entry_type_custom'] ?: $entry_type_labels['custom']) : ($entry_type_labels[$etype] ?? $etype);
              $ref_parts = [];
              if (!empty($d['funder_name']))      $ref_parts[] = e($d['funder_name']);
              if (!empty($d['reference_number']))  $ref_parts[] = '#' . e($d['reference_number']);
              if (!empty($d['period_start']) || !empty($d['period_end'])) {
                  $ref_parts[] = e(($d['period_start'] ? date('d.m.Y', strtotime($d['period_start'])) : '…') . ' – ' . ($d['period_end'] ? date('d.m.Y', strtotime($d['period_end'])) : '…'));
              }
            ?>
              <tr>
                <td><strong><?= e($d['name']) ?></strong></td>
                <td><span class="badge" style="background:rgba(255,255,255,.06);color:rgba(255,255,255,.6);font-size:11px"><?= e($etype_label) ?></span></td>
                <td><span class="badge" style="background:rgba(255,255,255,.06);color:rgba(255,255,255,.6);font-size:11px"><?= e($type_labels[$d['donor_type']] ?? $d['donor_type']) ?></span></td>
                <td style="font-size:13px;color:rgba(255,255,255,.65)"><?= e($method_labels[$d['method']] ?? $d['method']) ?></td>
                <td style="font-size:12px;color:rgba(255,255,255,.65)"><?= $ref_parts ? implode('<br>', $ref_parts) : '—' ?></td>
                <td style="font-size:13px;color:rgba(255,255,255,.65)"><?= e($d['details'] ?? '—') ?></td>
                <td style="font-size:13px;color:rgba(255,255,255,.65);white-space:nowrap"><?= $d['value_dkk'] !== null ? number_format((float)$d['value_dkk'],0,',',' ').' DKK' : '—' ?></td>
                <td>
                  <?php if ($can_edit_details): ?>
                  <form method="post" style="display:inline" onsubmit="return confirm('<?= e(t('delete_donor_confirm')) ?>')">
                    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="donor_action" value="delete_donor">
                    <input type="hidden" name="donor_id" value="<?= (int)$d['id'] ?>">
                    <button class="btn btn-danger btn-sm" type="submit" style="padding:4px 10px;font-size:11px"><?= e(t('delete')) ?></button>
                  </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <p style="font-size:13px;color:rgba(255,255,255,.45);margin-bottom:20px"><?= e(t('no_contributions')) ?></p>
      <?php endif; ?>

      <!-- Adaugă intrare de finanțare nouă -->
      <?php if ($can_edit_details): ?>
      <form method="post" id="addFinancingForm">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="donor_action" value="add_donor">
        <div class="grid-2" style="margin-bottom:12px">
          <div class="field">
            <label><?= e(t('donor_name_label')) ?></label>
            <input type="text" name="donor_name" placeholder="<?= e(t('donor_name_ph')) ?>">
          </div>
          <div class="field">
            <label><?= e(t('entry_type_label')) ?></label>
            <select name="entry_type" id="entryTypeSelect">
              <?php foreach ($entry_type_options as $et): ?>
                <option value="<?= e($et) ?>"><?= e($entry_type_labels[$et]) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="field" id="entryTypeCustomField" style="margin-bottom:12px;display:none">
          <label><?= e(t('entry_type_custom_label')) ?></label>
          <input type="text" name="entry_type_custom" placeholder="<?= e(t('entry_type_custom_ph')) ?>">
        </div>
        <div class="grid-2" style="margin-bottom:12px">
          <div class="field">
            <label><?= e(t('th_type')) ?></label>
            <select name="donor_type">
              <option value="extern"><?= e(t('type_extern')) ?></option>
              <option value="membru"><?= e(t('type_membru')) ?></option>
              <option value="anonim"><?= e(t('type_anonim')) ?></option>
            </select>
          </div>
          <div class="field">
            <label><?= e(t('th_method')) ?></label>
            <select name="donor_method">
              <option value="transfer"><?= e(t('method_transfer')) ?></option>
              <option value="bunuri_servicii"><?= e(t('method_bunuri')) ?></option>
              <option value="voluntariat"><?= e(t('method_voluntariat')) ?></option>
            </select>
          </div>
        </div>

        <div id="entryTypeExtraFields" style="display:none;border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:14px 16px;margin-bottom:12px">
          <p class="field-hint" style="margin-bottom:12px"><?= e(t('financing_extra_hint')) ?></p>
          <div class="grid-2" style="margin-bottom:12px">
            <div class="field">
              <label><?= e(t('funder_name_label')) ?></label>
              <input type="text" name="funder_name" placeholder="<?= e(t('funder_name_ph')) ?>">
            </div>
            <div class="field">
              <label><?= e(t('reference_number_label')) ?></label>
              <input type="text" name="reference_number">
            </div>
          </div>
          <div class="grid-2">
            <div class="field">
              <label><?= e(t('period_start_label')) ?></label>
              <input type="date" name="period_start">
            </div>
            <div class="field">
              <label><?= e(t('period_end_label')) ?></label>
              <input type="date" name="period_end">
            </div>
          </div>
        </div>

        <div class="grid-2" style="margin-bottom:12px">
          <div class="field">
            <label><?= e(t('estimated_value_label')) ?></label>
            <input type="number" name="donor_value" min="0" step="0.01" placeholder="<?= e(t('optional_ph')) ?>">
          </div>
        </div>
        <div class="field" style="margin-bottom:16px">
          <label><?= e(t('th_details')) ?></label>
          <input type="text" name="donor_details" placeholder="<?= e(t('donor_details_ph')) ?>">
        </div>
        <button class="btn btn-solid" type="submit" style="font-size:13px;padding:8px 16px"><?= e(t('add_contribution_btn')) ?></button>
      </form>
      <script>
      (function() {
        var sel = document.getElementById('entryTypeSelect');
        var customField = document.getElementById('entryTypeCustomField');
        var extraFields = document.getElementById('entryTypeExtraFields');
        var extraTypes = <?= json_encode($entry_type_extra_types) ?>;
        function upd() {
          customField.style.display = sel.value === 'custom' ? '' : 'none';
          extraFields.style.display = extraTypes.indexOf(sel.value) !== -1 ? '' : 'none';
        }
        sel.addEventListener('change', upd);
        upd();
      })();
      </script>
      <?php endif; ?>
    </div>
</div>
<?php layout_foot(); ?>
