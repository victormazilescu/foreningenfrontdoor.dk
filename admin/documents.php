<?php
require_once __DIR__ . '/auth.php';
$user = require_director();
$pdo  = get_db();

define('UPLOAD_DIR', dirname(__DIR__) . '/assets/documents/');
define('UPLOAD_URL', '/assets/documents/');
define('MAX_SIZE',   20 * 1024 * 1024);

if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $title_ro     = trim($_POST['title_ro']     ?? '');
        $title_da     = trim($_POST['title_da']     ?? '');
        $doc_type     = trim($_POST['doc_type']     ?? 'referat');
        $meeting_date = trim($_POST['meeting_date'] ?? '') ?: null;
        $sort_order   = (int)($_POST['sort_order']  ?? 0);
        $is_public    = isset($_POST['is_public']) ? 1 : 0;
        $errors = [];
        if (!$title_ro) $errors[] = 'Titlu RO obligatoriu.';
        if (!$title_da) $errors[] = 'Titlu DA obligatoriu.';
        $file_path = null; $file_size = null;
        if (!empty($_FILES['pdf']['name'])) {
            $file = $_FILES['pdf'];
            $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if ($file['error'] !== UPLOAD_ERR_OK)  $errors[] = 'Eroare upload.';
            elseif ($file['size'] > MAX_SIZE)       $errors[] = 'Fișierul depășește 20MB.';
            elseif ($ext !== 'pdf')                 $errors[] = 'Doar fișiere PDF.';
            else {
                $safe  = preg_replace('/[^a-z0-9_\-]/i', '_', pathinfo($file['name'], PATHINFO_FILENAME));
                $fname = date('Ymd_His') . '_' . $safe . '.pdf';
                if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR . $fname)) {
                    $errors[] = 'Nu s-a putut salva fișierul.';
                } else {
                    $file_path = UPLOAD_URL . $fname;
                    $file_size = $file['size'];
                }
            }
        } else { $errors[] = 'Fișier PDF obligatoriu.'; }

        if (empty($errors)) {
            $pdo->prepare('INSERT INTO documents (title_ro,title_da,doc_type,meeting_date,file_path,file_size,is_public,sort_order) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$title_ro,$title_da,$doc_type,$meeting_date,$file_path,$file_size,$is_public,$sort_order]);
            flash('ok','Document adăugat.');
        } else {
            flash('error', implode(' ', $errors));
        }
        header('Location: /admin/documents.php'); exit;
    }

    if ($action === 'toggle_public') {
        $id = (int)($_POST['doc_id'] ?? 0);
        if ($id) $pdo->prepare('UPDATE documents SET is_public = 1 - is_public WHERE id=?')->execute([$id]);
        flash('ok','Vizibilitate actualizată.');
        header('Location: /admin/documents.php'); exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['doc_id'] ?? 0);
        if ($id) {
            $row = $pdo->prepare('SELECT file_path FROM documents WHERE id=?');
            $row->execute([$id]); $row = $row->fetch();
            if ($row && $row['file_path']) {
                $abs = dirname(__DIR__) . '/' . ltrim($row['file_path'], '/');
                if (file_exists($abs)) unlink($abs);
            }
            $pdo->prepare('DELETE FROM documents WHERE id=?')->execute([$id]);
            flash('ok','Document șters.');
        }
        header('Location: /admin/documents.php'); exit;
    }
}

$docs  = $pdo->query('SELECT * FROM documents ORDER BY meeting_date DESC, sort_order ASC, id DESC')->fetchAll();
$flash = get_flash();

function fmtSize($b) {
    if (!$b) return '—';
    return $b > 1048576 ? round($b/1048576,1).' MB' : round($b/1024).' KB';
}
$doc_types = ['referat'=>'Referat','raport'=>'Raport anual','statut'=>'Statut','altele'=>'Altele'];

layout_head('Documente', 'documents');
?>
<div class="content">
  <?php if ($flash): ?>
    <div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div>
  <?php endif; ?>

  <div class="page-head">
    <h1>Documente transparență</h1>
    <a class="btn btn-ghost btn-sm" href="/transparenta.html" target="_blank">🌐 Vezi pagina →</a>
  </div>

  <!-- UPLOAD -->
  <div class="form-section">
    <div class="section-label">Document nou</div>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="add">
      <div class="grid-2" style="margin-bottom:14px">
        <div class="field">
          <label>Titlu în română *</label>
          <input type="text" name="title_ro" required placeholder="ex: Referat stiftende generalforsamling">
        </div>
        <div class="field">
          <label>Titlu în daneză *</label>
          <input type="text" name="title_da" required placeholder="ex: Referat fra stiftende generalforsamling">
        </div>
      </div>
      <div class="grid-3" style="margin-bottom:14px">
        <div class="field">
          <label>Tip document</label>
          <select name="doc_type">
            <?php foreach ($doc_types as $val => $lbl): ?>
              <option value="<?= $val ?>"><?= $lbl ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label>Data ședinței</label>
          <input type="date" name="meeting_date">
        </div>
        <div class="field">
          <label>Ordine</label>
          <input type="number" name="sort_order" value="0" min="0">
        </div>
      </div>
      <div class="field" style="margin-bottom:14px">
        <label>Fișier PDF *</label>
        <input type="file" name="pdf" accept=".pdf" required>
        <span class="field-hint">Maxim 20MB, doar PDF.</span>
      </div>
      <label class="check-row" style="margin-bottom:18px">
        <input type="checkbox" name="is_public" checked> Vizibil public pe pagina de transparență
      </label>
      <button class="btn btn-solid" type="submit">Încarcă documentul</button>
    </form>
  </div>

  <!-- LISTA -->
  <div class="form-section">
    <div class="section-label">Documente existente (<?= count($docs) ?>)</div>
    <?php if (empty($docs)): ?>
      <div class="empty">Niciun document încărcat încă.</div>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Titlu</th><th>Tip</th><th>Data</th><th>Mărime</th><th>Vizibil</th><th>Acțiuni</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($docs as $doc): ?>
            <tr>
              <td>
                <strong><?= e($doc['title_ro']) ?></strong><br>
                <small style="color:rgba(255,255,255,.4)"><?= e($doc['title_da']) ?></small>
              </td>
              <td><span class="badge" style="background:rgba(255,255,255,.06);color:rgba(255,255,255,.6)"><?= e($doc_types[$doc['doc_type']] ?? $doc['doc_type']) ?></span></td>
              <td style="color:rgba(255,255,255,.4);white-space:nowrap"><?= $doc['meeting_date'] ? date('d.m.Y', strtotime($doc['meeting_date'])) : '—' ?></td>
              <td style="color:rgba(255,255,255,.4)"><?= fmtSize($doc['file_size']) ?></td>
              <td>
                <?php if ($doc['is_public']): ?>
                  <span class="badge" style="color:rgba(120,200,120,.9);background:rgba(60,150,60,.1)">Public</span>
                <?php else: ?>
                  <span class="badge" style="color:rgba(255,255,255,.4);background:rgba(255,255,255,.04)">Ascuns</span>
                <?php endif; ?>
              </td>
              <td>
                <div class="actions">
                  <a class="btn btn-ghost btn-xs" href="<?= e($doc['file_path']) ?>" target="_blank">↗ PDF</a>
                  <form method="post" style="display:inline">
                    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="toggle_public">
                    <input type="hidden" name="doc_id" value="<?= (int)$doc['id'] ?>">
                    <button class="btn btn-ghost btn-xs" type="submit"><?= $doc['is_public'] ? 'Ascunde' : 'Publică' ?></button>
                  </form>
                  <form method="post" style="display:inline" onsubmit="return confirm('Ștergi documentul și fișierul PDF?')">
                    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="doc_id" value="<?= (int)$doc['id'] ?>">
                    <button class="btn btn-danger btn-xs" type="submit">Șterge</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php layout_foot(); ?>
