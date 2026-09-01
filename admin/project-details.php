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
  `headline_ro` VARCHAR(255) NULL, `headline_da` VARCHAR(255) NULL,
  `story_ro` TEXT NULL, `story_da` TEXT NULL,
  `budget_needed` DECIMAL(10,2) NULL, `budget_raised` DECIMAL(10,2) NULL DEFAULT 0,
  `budget_breakdown_ro` TEXT NULL, `budget_breakdown_da` TEXT NULL,
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

// Asigură tabelul project_donors (failsafe)
try { $pdo->exec("CREATE TABLE IF NOT EXISTS `project_donors` (
  `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `project_id` INT UNSIGNED  NOT NULL,
  `name`       VARCHAR(255)  NOT NULL DEFAULT 'Donație anonimă',
  `donor_type` ENUM('membru','extern','anonim') NOT NULL DEFAULT 'extern',
  `method`     ENUM('transfer','bunuri_servicii','voluntariat') NOT NULL DEFAULT 'transfer',
  `details`    VARCHAR(500)  NULL,
  `value_dkk`  DECIMAL(10,2) NULL,
  `sort_order` SMALLINT      NOT NULL DEFAULT 0,
  `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `idx_project` (`project_id`),
  FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"); } catch(PDOException $e) {}

// Încarcă detalii existente
$det = $pdo->prepare('SELECT * FROM project_details WHERE project_id=?');
$det->execute([$project_id]);
$det = $det->fetch() ?: [
    'headline_ro' => '', 'headline_da' => '',
    'story_ro' => '', 'story_da' => '',
    'budget_needed' => '', 'budget_raised' => '0',
    'budget_breakdown_ro' => '', 'budget_breakdown_da' => '',
    'photo_1' => null, 'photo_2' => null, 'photo_3' => null, 'photo_4' => null,
];

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (!$can_edit_details) {
        http_response_code(403);
        die('Nu ai permisiunea de a edita detaliile proiectului.');
    }

    $f = [
        'headline_ro'          => trim($_POST['headline_ro']          ?? ''),
        'headline_da'          => trim($_POST['headline_da']          ?? ''),
        'story_ro'             => trim($_POST['story_ro']             ?? ''),
        'story_da'             => trim($_POST['story_da']             ?? ''),
        'budget_needed'        => $_POST['budget_needed'] !== '' ? (float)str_replace(',','.', $_POST['budget_needed'] ?? '') : null,
        'budget_raised'        => (float)str_replace(',','.', $_POST['budget_raised'] ?? '0'),
        'budget_breakdown_ro'  => trim($_POST['budget_breakdown_ro']  ?? ''),
        'budget_breakdown_da'  => trim($_POST['budget_breakdown_da']  ?? ''),
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
            if ($file['error'] !== UPLOAD_ERR_OK)          $errors[] = "Eroare upload foto $n.";
            elseif ($file['size'] > MAX_PHOTO)              $errors[] = "Foto $n depășește 5MB.";
            elseif (!in_array($ext, ['jpg','jpeg','png','webp'])) $errors[] = "Foto $n: doar JPG/PNG/WEBP.";
            else {
                // Șterge vechea poză dacă există
                if ($photos[$key]) {
                    $abs = dirname(__DIR__) . '/' . ltrim($photos[$key], '/');
                    if (file_exists($abs)) unlink($abs);
                }
                $fname = 'proj_' . $project_id . '_' . $n . '_' . time() . '.' . $ext;
                if (!move_uploaded_file($file['tmp_name'], PHOTO_DIR . $fname)) {
                    $errors[] = "Nu s-a putut salva foto $n.";
                } else {
                    $photos[$key] = PHOTO_URL . $fname;
                }
            }
        }
    }

    // Donatori — acțiuni separate (add/delete), nu blochează salvarea detaliilor
    if (isset($_POST['donor_action'])) {
        $da = $_POST['donor_action'];
        if ($da === 'add_donor') {
            $dname  = trim($_POST['donor_name'] ?? '') ?: 'Donație anonimă';
            $dtype  = in_array($_POST['donor_type'] ?? '', ['membru','extern','anonim']) ? $_POST['donor_type'] : 'extern';
            $dmethod= in_array($_POST['donor_method'] ?? '', ['transfer','bunuri_servicii','voluntariat']) ? $_POST['donor_method'] : 'transfer';
            $ddetails = trim($_POST['donor_details'] ?? '');
            $dvalue   = $_POST['donor_value'] !== '' ? (float)str_replace(',','.', $_POST['donor_value'] ?? '') : null;
            $dsort    = (int)($_POST['donor_sort'] ?? 0);
            $pdo->prepare('INSERT INTO project_donors (project_id,name,donor_type,method,details,value_dkk,sort_order) VALUES (?,?,?,?,?,?,?)')
                ->execute([$project_id, $dname, $dtype, $dmethod, $ddetails ?: null, $dvalue, $dsort]);
            flash('ok', 'Donator adăugat.');
            header('Location: /admin/project-details.php?project=' . $project_id); exit;
        }
        if ($da === 'delete_donor') {
            $did = (int)($_POST['donor_id'] ?? 0);
            if ($did) $pdo->prepare('DELETE FROM project_donors WHERE id=? AND project_id=?')->execute([$did, $project_id]);
            flash('ok', 'Donator șters.');
            header('Location: /admin/project-details.php?project=' . $project_id); exit;
        }
    }

    if (empty($errors)) {
        $merged = array_merge($f, $photos);
        // UPSERT
        $existing = $pdo->prepare('SELECT id FROM project_details WHERE project_id=?');
        $existing->execute([$project_id]);
        if ($existing->fetch()) {
            $pdo->prepare('UPDATE project_details SET
                headline_ro=?, headline_da=?, story_ro=?, story_da=?,
                budget_needed=?, budget_raised=?, budget_breakdown_ro=?, budget_breakdown_da=?,
                photo_1=?, photo_2=?, photo_3=?, photo_4=?
                WHERE project_id=?')
                ->execute([
                    $merged['headline_ro'], $merged['headline_da'],
                    $merged['story_ro'], $merged['story_da'],
                    $merged['budget_needed'], $merged['budget_raised'],
                    $merged['budget_breakdown_ro'], $merged['budget_breakdown_da'],
                    $merged['photo_1'], $merged['photo_2'], $merged['photo_3'], $merged['photo_4'],
                    $project_id
                ]);
        } else {
            $pdo->prepare('INSERT INTO project_details
                (project_id, headline_ro, headline_da, story_ro, story_da,
                 budget_needed, budget_raised, budget_breakdown_ro, budget_breakdown_da,
                 photo_1, photo_2, photo_3, photo_4)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([
                    $project_id,
                    $merged['headline_ro'], $merged['headline_da'],
                    $merged['story_ro'], $merged['story_da'],
                    $merged['budget_needed'], $merged['budget_raised'],
                    $merged['budget_breakdown_ro'], $merged['budget_breakdown_da'],
                    $merged['photo_1'], $merged['photo_2'], $merged['photo_3'], $merged['photo_4'],
                ]);
        }
        $_SESSION['flash'] = ['type' => 'ok', 'msg' => 'Detalii actualizate.'];
        header('Location: /admin/project-details.php?project=' . $project_id); exit;
    }
    $det = array_merge($det, $f, $photos);
}


$donors = $pdo->prepare('SELECT * FROM project_donors WHERE project_id=? ORDER BY sort_order ASC, id ASC');
$donors->execute([$project_id]);
$donors = $donors->fetchAll();

$flash = get_flash();
layout_head('Detalii proiect', 'projects');
?>
<div class="content">
<div class="page-head">
      <h1>Detalii transparență</h1>
    </div>
    <div class="project-meta">
      Proiect: <strong><?= e($project['title_ro']) ?></strong> · <?= e($project['title_da']) ?>
      · <a href="/admin/project-edit.php?id=<?= $project_id ?>">← Înapoi la editare</a>
    </div>

    <?php if ($flash): ?>
      <div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div>
    <?php endif; ?>
    <?php if ($errors): ?>
      <div class="errors"><strong>Erori:</strong><ul><?php foreach($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <?php if (!$can_edit_details): ?>
      <div class="flash flash-error">Doar vizualizare — nu ai permisiunea de a edita aceste detalii.</div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" <?= $can_edit_details ? '' : 'style="pointer-events:none;opacity:.65"' ?>>
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

      <!-- TITLU SCURT -->
      <div class="card">
        <div class="card-title">Titlu pagina transparență</div>
        <div class="grid-2">
          <div class="field">
            <label>Titlu scurt RO</label>
            <input type="text" name="headline_ro" value="<?= e($det['headline_ro']) ?>" placeholder="ex: Teatru Nonformal — Pilot 2026">
          </div>
          <div class="field">
            <label>Titlu scurt DA</label>
            <input type="text" name="headline_da" value="<?= e($det['headline_da']) ?>" placeholder="ex: Uformel teater — Pilot 2026">
          </div>
        </div>
      </div>

      <!-- POVESTE -->
      <div class="card">
        <div class="card-title">Povestea proiectului</div>
        <div class="grid-2">
          <div class="field">
            <label>Descriere extinsă RO</label>
            <textarea name="story_ro" rows="8"><?= e($det['story_ro']) ?></textarea>
          </div>
          <div class="field">
            <label>Descriere extinsă DA</label>
            <textarea name="story_da" rows="8"><?= e($det['story_da']) ?></textarea>
          </div>
        </div>
      </div>

      <!-- BUGET -->
      <div class="card">
        <div class="card-title">Buget (DKK)</div>
        <div class="grid-2" style="margin-bottom:16px">
          <div class="field">
            <label>Buget necesar total</label>
            <input type="number" name="budget_needed" value="<?= e($det['budget_needed']) ?>" min="0" step="0.01" placeholder="0.00">
            <span class="hint">Lasă gol dacă nu e relevant.</span>
          </div>
          <div class="field">
            <label>Buget strâns până acum</label>
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
          <div style="font-size:12px;color:rgba(255,255,255,.4);margin-top:6px"><?= $pct ?>% finanțat (<?= number_format($raised, 0, ',', '.') ?> / <?= number_format($needed, 0, ',', '.') ?> DKK)</div>
        <?php endif; ?>
        <div class="grid-2" style="margin-top:16px">
          <div class="field">
            <label>Detaliere cheltuieli RO</label>
            <textarea name="budget_breakdown_ro" rows="5" placeholder="ex:&#10;- Materiale costume: 2.000 DKK&#10;- Închiriere sală: 1.500 DKK&#10;- Transport: 500 DKK"><?= e($det['budget_breakdown_ro']) ?></textarea>
          </div>
          <div class="field">
            <label>Detaliere cheltuieli DA</label>
            <textarea name="budget_breakdown_da" rows="5" placeholder="ex:&#10;- Kostumematerialer: 2.000 DKK&#10;- Lejl lokale: 1.500 DKK&#10;- Transport: 500 DKK"><?= e($det['budget_breakdown_da']) ?></textarea>
          </div>
        </div>
      </div>

      <!-- POZE -->
      <div class="card">
        <div class="card-title">Galerie foto (max 4 poze)</div>
        <div class="grid-4">
          <?php foreach ([1,2,3,4] as $n):
            $pkey = 'photo_' . $n;
            $existing_photo = $det[$pkey] ?? null;
          ?>
            <div class="photo-slot">
              <?php if ($existing_photo): ?>
                <img class="photo-preview" src="<?= e($existing_photo) ?>" alt="Foto <?= $n ?>">
                <label class="delete-check">
                  <input type="checkbox" name="delete_<?= $pkey ?>" value="1"> Șterge foto
                </label>
              <?php else: ?>
                <div class="photo-empty">Foto <?= $n ?></div>
              <?php endif; ?>
              <input type="file" name="<?= $pkey ?>" accept=".jpg,.jpeg,.png,.webp">
              <span class="hint">Max 5MB</span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- BUTOANE -->
      <div style="display:flex;gap:12px;flex-wrap:wrap">
        <?php if ($can_edit_details): ?>
          <button class="btn btn-solid" type="submit">Salvează detaliile</button>
        <?php endif; ?>
        <a class="btn btn-ghost" href="/admin/projects.php" style="pointer-events:auto">← Înapoi la proiecte</a>
      </div>
    </form>

    <!-- DONATORI -->
    <?php
    $method_labels = ['transfer'=>'Transfer bancar','bunuri_servicii'=>'Bunuri / Servicii','voluntariat'=>'Ore voluntariat'];
    $type_labels   = ['membru'=>'Membru asociație','extern'=>'Extern','anonim'=>'Anonim'];
    ?>
    <div class="card" style="margin-top:20px">
      <div class="card-title">Donatori & Contribuții</div>

      <!-- Lista donatori existenți -->
      <?php if ($donors): ?>
        <div class="table-wrap" style="margin-bottom:24px">
          <table>
            <thead>
              <tr>
                <th>Nume</th><th>Tip</th><th>Modalitate</th><th>Detalii</th><th>Valoare</th><th></th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($donors as $d): ?>
              <tr>
                <td><strong><?= e($d['name']) ?></strong></td>
                <td><span class="badge" style="background:rgba(255,255,255,.06);color:rgba(255,255,255,.6);font-size:11px"><?= e($type_labels[$d['donor_type']] ?? $d['donor_type']) ?></span></td>
                <td style="font-size:13px;color:rgba(255,255,255,.4)"><?= e($method_labels[$d['method']] ?? $d['method']) ?></td>
                <td style="font-size:13px;color:rgba(255,255,255,.4)"><?= e($d['details'] ?? '—') ?></td>
                <td style="font-size:13px;color:rgba(255,255,255,.4);white-space:nowrap"><?= $d['value_dkk'] !== null ? number_format((float)$d['value_dkk'],0,',',' ').' DKK' : '—' ?></td>
                <td>
                  <?php if ($can_edit_details): ?>
                  <form method="post" style="display:inline" onsubmit="return confirm('Ștergi acest donator?')">
                    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="donor_action" value="delete_donor">
                    <input type="hidden" name="donor_id" value="<?= (int)$d['id'] ?>">
                    <button class="btn btn-danger btn-sm" type="submit" style="padding:4px 10px;font-size:11px">Șterge</button>
                  </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <p style="font-size:13px;color:rgba(255,255,255,.25);margin-bottom:20px">Nicio contribuție înregistrată încă.</p>
      <?php endif; ?>

      <!-- Adaugă donator nou -->
      <?php if ($can_edit_details): ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="donor_action" value="add_donor">
        <div class="grid-2" style="margin-bottom:12px">
          <div class="field">
            <label>Nume donator</label>
            <input type="text" name="donor_name" placeholder="Lasă gol pentru anonim">
          </div>
          <div class="field">
            <label>Tip</label>
            <select name="donor_type">
              <option value="extern">Extern</option>
              <option value="membru">Membru asociație</option>
              <option value="anonim">Anonim</option>
            </select>
          </div>
        </div>
        <div class="grid-2" style="margin-bottom:12px">
          <div class="field">
            <label>Modalitate</label>
            <select name="donor_method">
              <option value="transfer">Transfer bancar</option>
              <option value="bunuri_servicii">Bunuri / Servicii</option>
              <option value="voluntariat">Ore voluntariat</option>
            </select>
          </div>
          <div class="field">
            <label>Valoare estimată (DKK)</label>
            <input type="number" name="donor_value" min="0" step="0.01" placeholder="opțional">
          </div>
        </div>
        <div class="field" style="margin-bottom:16px">
          <label>Detalii</label>
          <input type="text" name="donor_details" placeholder="ex: sală gratuită 3h, 20 ore design, transport materiale">
        </div>
        <button class="btn btn-solid" type="submit" style="font-size:13px;padding:8px 16px">+ Adaugă contribuție</button>
      </form>
      <?php endif; ?>
    </div>
</div>
<?php layout_foot(); ?>
