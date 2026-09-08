<?php
require_once __DIR__ . '/auth.php';
$user       = require_perm('documents', 'view');
$can_manage = has_perm($user, 'documents', 'manage');
$pdo        = get_db();

define('UPLOAD_DIR', dirname(__DIR__) . '/assets/documents/');
define('UPLOAD_URL', '/assets/documents/');
define('MAX_SIZE',   20 * 1024 * 1024);

if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (!$can_manage) {
        http_response_code(403);
        die(t('no_perm_manage_documents'));
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $title_ro     = trim($_POST['title_ro']     ?? '');
        $title_da     = trim($_POST['title_da']     ?? '');
        $doc_type     = trim($_POST['doc_type']     ?? 'referat');
        $meeting_date = trim($_POST['meeting_date'] ?? '') ?: null;
        $sort_order   = (int)($_POST['sort_order']  ?? 0);
        $is_public    = isset($_POST['is_public']) ? 1 : 0;
        $errors = [];
        if (!$title_ro) $errors[] = t('title_ro_required');
        if (!$title_da) $errors[] = t('title_da_required');
        $file_path = null; $file_size = null;
        if (!empty($_FILES['pdf']['name'])) {
            $file = $_FILES['pdf'];
            $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if ($file['error'] !== UPLOAD_ERR_OK)  $errors[] = t('upload_error');
            elseif ($file['size'] > MAX_SIZE)       $errors[] = t('file_too_large_20mb');
            elseif ($ext !== 'pdf')                 $errors[] = t('pdf_only_error');
            else {
                $safe  = preg_replace('/[^a-z0-9_\-]/i', '_', pathinfo($file['name'], PATHINFO_FILENAME));
                $fname = date('Ymd_His') . '_' . $safe . '.pdf';
                if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR . $fname)) {
                    $errors[] = t('file_save_failed');
                } else {
                    $file_path = UPLOAD_URL . $fname;
                    $file_size = $file['size'];
                }
            }
        } else { $errors[] = t('pdf_file_required'); }

        if (empty($errors)) {
            $pdo->prepare('INSERT INTO documents (title_ro,title_da,doc_type,meeting_date,file_path,file_size,is_public,sort_order) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$title_ro,$title_da,$doc_type,$meeting_date,$file_path,$file_size,$is_public,$sort_order]);
            flash('ok', t('document_added'));
        } else {
            flash('error', implode(' ', $errors));
        }
        header('Location: /admin/documents.php'); exit;
    }

    if ($action === 'toggle_public') {
        $id = (int)($_POST['doc_id'] ?? 0);
        if ($id) $pdo->prepare('UPDATE documents SET is_public = 1 - is_public WHERE id=?')->execute([$id]);
        flash('ok', t('visibility_updated'));
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
            flash('ok', t('document_deleted'));
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
$doc_types = ['referat'=>t('doc_type_referat'),'raport'=>t('doc_type_raport'),'statut'=>t('doc_type_statut'),'altele'=>t('doc_type_altele')];

layout_head(t('nav_documents'), 'documents');
?>
<div class="content">
  <?php if ($flash): ?>
    <div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div>
  <?php endif; ?>

  <div class="page-head">
    <h1><?= e(t('nav_documents_h1')) ?></h1>
    <a class="btn btn-ghost btn-sm" href="/transparenta.html" target="_blank"><?= e(t('view_page_btn')) ?></a>
  </div>

  <!-- UPLOAD -->
  <?php if ($can_manage): ?>
  <div class="form-section">
    <div class="section-label"><?= e(t('new_document_label')) ?></div>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="add">
      <div class="grid-2" style="margin-bottom:14px">
        <div class="field">
          <label><?= e(t('title_ro_label')) ?></label>
          <input type="text" name="title_ro" required placeholder="ex: Referat stiftende generalforsamling">
        </div>
        <div class="field">
          <label><?= e(t('title_da_label')) ?></label>
          <input type="text" name="title_da" required placeholder="ex: Referat fra stiftende generalforsamling">
        </div>
      </div>
      <div class="grid-3" style="margin-bottom:14px">
        <div class="field">
          <label><?= e(t('doc_type_label')) ?></label>
          <select name="doc_type">
            <?php foreach ($doc_types as $val => $lbl): ?>
              <option value="<?= $val ?>"><?= e($lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label><?= e(t('meeting_date_field_label')) ?></label>
          <input type="date" name="meeting_date">
        </div>
        <div class="field">
          <label><?= e(t('sort_order_label')) ?></label>
          <input type="number" name="sort_order" value="0" min="0">
        </div>
      </div>
      <div class="field" style="margin-bottom:14px">
        <label><?= e(t('pdf_file_label')) ?></label>
        <input type="file" name="pdf" accept=".pdf" required>
        <span class="field-hint"><?= e(t('pdf_file_hint')) ?></span>
      </div>
      <label class="check-row" style="margin-bottom:18px">
        <input type="checkbox" name="is_public" checked> <?= e(t('visible_public_checkbox')) ?>
      </label>
      <button class="btn btn-solid" type="submit"><?= e(t('upload_document_btn')) ?></button>
    </form>
  </div>
  <?php endif; ?>

  <!-- LISTA -->
  <div class="form-section">
    <div class="section-label"><?= e(t('existing_documents_label')) ?> (<?= count($docs) ?>)</div>
    <?php if (empty($docs)): ?>
      <div class="empty"><?= e(t('no_documents_yet')) ?></div>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th><?= e(t('th_title')) ?></th><th><?= e(t('th_type')) ?></th><th><?= e(t('th_date')) ?></th><th><?= e(t('th_size')) ?></th><th><?= e(t('th_visible')) ?></th><th><?= e(t('th_actions')) ?></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($docs as $doc): ?>
            <tr>
              <td>
                <strong><?= e($doc['title_ro']) ?></strong><br>
                <small style="color:rgba(255,255,255,.6)"><?= e($doc['title_da']) ?></small>
              </td>
              <td><span class="badge" style="background:rgba(255,255,255,.06);color:rgba(255,255,255,.6)"><?= e($doc_types[$doc['doc_type']] ?? $doc['doc_type']) ?></span></td>
              <td style="color:rgba(255,255,255,.6);white-space:nowrap"><?= $doc['meeting_date'] ? date('d.m.Y', strtotime($doc['meeting_date'])) : '—' ?></td>
              <td style="color:rgba(255,255,255,.6)"><?= fmtSize($doc['file_size']) ?></td>
              <td>
                <?php if ($doc['is_public']): ?>
                  <span class="badge" style="color:rgba(120,200,120,.9);background:rgba(60,150,60,.1)"><?= e(t('public_badge')) ?></span>
                <?php else: ?>
                  <span class="badge" style="color:rgba(255,255,255,.6);background:rgba(255,255,255,.04)"><?= e(t('hidden_badge')) ?></span>
                <?php endif; ?>
              </td>
              <td>
                <div class="actions">
                  <a class="btn btn-ghost btn-xs" href="<?= e($doc['file_path']) ?>" target="_blank">↗ PDF</a>
                  <?php if ($can_manage): ?>
                  <form method="post" style="display:inline">
                    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="toggle_public">
                    <input type="hidden" name="doc_id" value="<?= (int)$doc['id'] ?>">
                    <button class="btn btn-ghost btn-xs" type="submit"><?= $doc['is_public'] ? e(t('hide_btn')) : e(t('publish_btn')) ?></button>
                  </form>
                  <form method="post" style="display:inline" onsubmit="return confirm('<?= e(t('delete_document_confirm')) ?>')">
                    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="doc_id" value="<?= (int)$doc['id'] ?>">
                    <button class="btn btn-danger btn-xs" type="submit"><?= e(t('delete')) ?></button>
                  </form>
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
</div>
<?php layout_foot(); ?>
