<?php
require_once __DIR__ . '/auth.php';
$user = require_perm('topics', 'manage');
$pdo  = get_db();
ensure_meeting_bucket_schema($pdo);
ensure_referat_schema($pdo);

$mid = isset($_GET['meeting']) ? (int)$_GET['meeting'] : 0;
$stmt = $pdo->prepare('SELECT * FROM bf_meetings WHERE id=?');
$stmt->execute([$mid]); $meeting = $stmt->fetch();
if (!$meeting) { header('Location: /admin/topics.php'); exit; }
$is_council = ($meeting['type'] ?? 'generalforsamling') === 'consiliu';

$langs = ['ro' => 'Română', 'da' => 'Dansk', 'en' => 'English'];
$referat = get_referat($pdo, $mid) ?: ['meeting_id' => $mid, 'lang' => ui_lang(), 'content' => '', 'status' => 'draft', 'document_id' => null, 'pdf_path' => null, 'finalized_at' => null];

$flash = get_flash();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action  = $_POST['action'] ?? '';
    $lang    = array_key_exists($_POST['lang'] ?? '', $langs) ? $_POST['lang'] : ($referat['lang'] ?: 'da');
    $content = trim($_POST['content'] ?? '');

    if ($action === 'save_draft' || $action === 'finalize') {
        $exists = $pdo->prepare('SELECT id FROM bf_referate WHERE meeting_id=?');
        $exists->execute([$mid]);
        if ($exists->fetch()) {
            $pdo->prepare('UPDATE bf_referate SET lang=?, content=?, updated_at=NOW() WHERE meeting_id=?')
                ->execute([$lang, $content ?: null, $mid]);
        } else {
            $pdo->prepare('INSERT INTO bf_referate (meeting_id, lang, content, status, created_by, updated_at) VALUES (?,?,?,?,?,NOW())')
                ->execute([$mid, $lang, $content ?: null, 'draft', $user['id']]);
        }
        $referat = get_referat($pdo, $mid);
    }

    if ($action === 'save_draft') {
        flash('ok', t('referat_saved_draft_ok'));
        header('Location: /admin/referat-edit.php?meeting=' . $mid); exit;
    }

    if ($action === 'finalize') {
        $items = bucket_items($pdo, $mid);
        if (empty($items)) {
            flash('error', t('referat_no_items'));
            header('Location: /admin/referat-edit.php?meeting=' . $mid); exit;
        }
        try {
            $annex = meeting_vote_annex($pdo, $items, $is_council);
            $html  = build_referat_html($meeting, $referat, $annex, $is_council, $lang);
            $pdf   = render_referat_pdf($html);

            $upload_dir = dirname(__DIR__) . '/assets/documents/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $filename  = 'referat_meeting_' . $mid . '.pdf';
            file_put_contents($upload_dir . $filename, $pdf);
            $file_path = '/assets/documents/' . $filename;
            $file_size = strlen($pdf);

            $title = ($is_council ? 'Referat (consiliu)' : 'Referat') . ' — ' . $meeting['title']
                   . ($meeting['date'] ? ' (' . date('d.m.Y', strtotime($meeting['date'])) . ')' : '');

            if (!empty($referat['document_id'])) {
                $pdo->prepare('UPDATE documents SET title_ro=?, title_da=?, meeting_date=?, file_path=?, file_size=? WHERE id=?')
                    ->execute([$title, $title, $meeting['date'] ?: null, $file_path, $file_size, $referat['document_id']]);
                $document_id = $referat['document_id'];
            } else {
                $pdo->prepare('INSERT INTO documents (title_ro,title_da,doc_type,meeting_date,file_path,file_size,is_public,sort_order) VALUES (?,?,?,?,?,?,0,0)')
                    ->execute([$title, $title, 'referat', $meeting['date'] ?: null, $file_path, $file_size]);
                $document_id = (int)$pdo->lastInsertId();
            }

            $pdo->prepare('UPDATE bf_referate SET status="final", document_id=?, pdf_path=?, finalized_at=NOW(), updated_at=NOW() WHERE meeting_id=?')
                ->execute([$document_id, $file_path, $mid]);

            flash('ok', t('referat_generated_ok'));
        } catch (\Throwable $e) {
            flash('error', t('referat_generated_error') . ': ' . $e->getMessage());
        }
        header('Location: /admin/referat-edit.php?meeting=' . $mid); exit;
    }

    header('Location: /admin/referat-edit.php?meeting=' . $mid); exit;
}

$items = bucket_items($pdo, $mid);
$items_timed = agenda_with_times($items, $meeting['start_time'] ?? null);
$annex = $items ? meeting_vote_annex($pdo, $items, $is_council) : [];
$cur_lang = array_key_exists($referat['lang'] ?? '', $langs) ? $referat['lang'] : 'da';

layout_head(t('referat_h1'), 'topics');
?>
<div class="content" style="max-width:820px">
  <?php if ($flash): ?><div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>

  <div style="display:flex;gap:16px;margin-bottom:16px;flex-wrap:wrap">
    <a href="/admin/topics.php?meeting=<?= $mid ?>" style="font-size:13px;color:rgba(255,255,255,.6)"><?= e(t('referat_back_to_meeting')) ?></a>
    <a href="/admin/meeting-bucket.php?id=<?= $mid ?>" style="font-size:13px;color:rgba(255,255,255,.6)"><?= e(t('manage_meeting_btn')) ?></a>
  </div>

  <div class="page-head">
    <div>
      <h1><?= e(t('referat_h1')) ?></h1>
      <div style="font-size:13px;color:rgba(255,255,255,.65);margin-top:4px">
        <?= e($meeting['title']) ?><?php if ($meeting['date']): ?> · <?= e(date('d.m.Y', strtotime($meeting['date']))) ?><?php endif; ?>
        <span style="margin-left:8px;padding:2px 7px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;background:<?= $referat['status']==='final' ? 'rgba(60,150,60,.15)' : 'rgba(255,255,255,.06)' ?>;color:<?= $referat['status']==='final' ? 'rgba(120,200,120,.9)' : 'rgba(255,255,255,.6)' ?>">
          <?= $referat['status']==='final' ? e(t('referat_status_final')) : e(t('referat_status_draft')) ?>
        </span>
      </div>
    </div>
  </div>

  <?php if ($referat['status'] === 'final'): ?>
  <div class="form-section">
    <p style="font-size:13px;color:rgba(255,255,255,.65);margin-bottom:12px">
      <?= e(t('referat_finalized_at_label')) ?>: <?= e(date('d.m.Y H:i', strtotime($referat['finalized_at']))) ?>
    </p>
    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <a class="btn btn-solid btn-sm" href="<?= e($referat['pdf_path']) ?>" target="_blank"><?= e(t('referat_download_btn')) ?></a>
      <a class="btn btn-ghost btn-sm" href="/admin/documents.php"><?= e(t('referat_view_in_documents_btn')) ?></a>
    </div>
    <p class="field-hint" style="margin-top:10px"><?= e(t('referat_not_public_notice')) ?></p>
  </div>
  <?php endif; ?>

  <?php if (empty($items)): ?>
    <div class="flash flash-error"><?= e(t('referat_no_items')) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

    <div class="form-section">
      <p class="section-label"><?= e(t('referat_content_label')) ?></p>
      <div class="field" style="margin-bottom:16px;max-width:220px">
        <label><?= e(t('referat_lang_label')) ?></label>
        <select name="lang">
          <?php foreach ($langs as $lc => $lname): ?>
            <option value="<?= e($lc) ?>" <?= $cur_lang===$lc?'selected':'' ?>><?= e($lname) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <textarea name="content" rows="14" placeholder="<?= e(t('referat_content_ph')) ?>"><?= e($referat['content'] ?? '') ?></textarea>
      </div>
      <p class="field-hint" style="margin-top:10px"><?= e(t('referat_content_hint')) ?></p>
    </div>

    <div class="form-section">
      <p class="section-label"><?= e(t('referat_agenda_preview_h')) ?></p>
      <?php if (empty($items_timed)): ?>
        <div class="empty"><?= e(t('referat_no_items')) ?></div>
      <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead><tr><th>#</th><th><?= e(t('export_md_agenda_col_topic')) ?></th><th><?= e(t('export_md_agenda_col_time')) ?></th><th><?= e(t('export_md_agenda_col_moderator')) ?></th></tr></thead>
          <tbody>
          <?php $i=1; foreach ($items_timed as $it): ?>
            <tr>
              <td><?= $i ?></td>
              <td><?= e(proposal_title($it)) ?><br><small style="color:rgba(255,255,255,.5)"><?= $it['majority_type']==='doua_treimi' ? e(t('majority_2_3')) : e(t('majority_simple')) ?></small></td>
              <td><?= e($it['time_slot'] ?? '—') ?> · <?= (int)$it['est_minutes'] ?> min</td>
              <td><?= e($it['moderator_name'] ?? '—') ?></td>
            </tr>
          <?php $i++; endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <?php if ($annex): ?>
    <div class="form-section">
      <p class="section-label"><?= e(t('referat_votes_annex_h')) ?></p>
      <div style="display:flex;flex-direction:column;gap:10px">
        <?php foreach ($annex as $entry): $it = $entry['item']; $result = $entry['result']; ?>
        <div style="background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:14px 16px">
          <div style="font-weight:600;font-size:14px;margin-bottom:6px"><?= e(proposal_title($it)) ?></div>
          <div style="display:flex;gap:18px;font-size:13px;margin-bottom:6px">
            <span><?= e(t('votes_da')) ?>: <strong><?= $entry['tally']['da'] ?></strong></span>
            <span><?= e(t('votes_nu')) ?>: <strong><?= $entry['tally']['nu'] ?></strong></span>
            <span><?= e(t('votes_abtinere')) ?>: <strong><?= $entry['tally']['abtinere'] ?></strong></span>
          </div>
          <?php if ($is_council && !empty($entry['votes'])): ?>
            <div style="font-size:11px;color:rgba(255,255,255,.5);margin-bottom:6px">
              <?= e(t('referat_council_vote_detail_h')) ?>:
              <?php $parts=[]; foreach ($entry['votes'] as $v) { $parts[] = e($v['name']) . ': ' . e(t('votes_' . $v['option'])); } echo implode(' · ', $parts); ?>
            </div>
            <?php if (!empty($entry['is_tie'])): ?>
              <div style="font-size:11px;color:rgba(255,180,80,.85);margin-bottom:6px"><?= e(t('referat_tie_president_label')) ?></div>
            <?php endif; ?>
          <?php endif; ?>
          <span style="font-weight:700;color:<?= $result===true?'rgba(120,200,120,.9)':($result===false?'rgba(255,120,120,.9)':'rgba(255,255,255,.5)') ?>">
            <?= $result === null ? e(t('result_pending')) : ($result ? e(t('result_passed')) : e(t('result_rejected'))) ?>
          </span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <div style="display:flex;gap:10px;margin-top:4px">
      <button class="btn btn-ghost" type="submit" name="action" value="save_draft"><?= e(t('referat_save_draft_btn')) ?></button>
      <button class="btn btn-solid" type="submit" name="action" value="finalize" <?= empty($items) ? 'disabled' : '' ?>>
        <?= $referat['status']==='final' ? e(t('referat_regenerate_btn')) : e(t('referat_finalize_btn')) ?>
      </button>
    </div>
  </form>
</div>
<?php layout_foot(); ?>
