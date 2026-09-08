<?php
require_once __DIR__ . '/auth.php';
$user = require_perm('events', 'view');
$pdo  = get_db();
ensure_events_social_schema($pdo);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$stmt = $pdo->prepare('SELECT * FROM events WHERE id=?');
$stmt->execute([$id]); $ev = $stmt->fetch();
if (!$ev) { header('Location: /admin/events.php'); exit; }

if (!can_edit_event($user, (int)($ev['created_by'] ?? 0))) {
    flash('error', t('cannot_edit_event'));
    header('Location: /admin/events.php'); exit;
}
$can_publish = has_perm($user, 'events', 'manage');

$flash = get_flash();

// ── Ce limbi intră în textul postării — admin alege de fiecare dată,
// implicit RO+DA (ca înainte), EN opțional. Se poate combina orice set.
$lang_labels = ['ro' => 'Română', 'da' => 'Dansk', 'en' => 'English'];
$selected_langs = isset($_GET['langs']) && is_array($_GET['langs'])
    ? array_values(array_intersect($_GET['langs'], array_keys($lang_labels)))
    : ['ro', 'da'];
if (empty($selected_langs)) $selected_langs = ['ro', 'da'];

// ── Text generat automat, pentru limbile alese — admin poate edita înainte de postare ──
function event_social_date(array $ev, string $lang): string {
    if (empty($ev['date'])) return '';
    $months = [
        'ro' => ['ianuarie','februarie','martie','aprilie','mai','iunie','iulie','august','septembrie','octombrie','noiembrie','decembrie'],
        'da' => ['januar','februar','marts','april','maj','juni','juli','august','september','oktober','november','december'],
        'en' => ['January','February','March','April','May','June','July','August','September','October','November','December'],
    ];
    $ts = strtotime($ev['date']);
    $m  = $months[$lang][(int)date('n', $ts) - 1];
    if ($lang === 'da') {
        $s = (int)date('j', $ts) . '. ' . $m . ' ' . date('Y', $ts);
        if (!empty($ev['time'])) $s .= ' kl. ' . substr($ev['time'], 0, 5);
    } elseif ($lang === 'en') {
        $s = $m . ' ' . (int)date('j', $ts) . ', ' . date('Y', $ts);
        if (!empty($ev['time'])) $s .= ' · ' . substr($ev['time'], 0, 5);
    } else {
        $s = (int)date('j', $ts) . ' ' . $m . ' ' . date('Y', $ts);
        if (!empty($ev['time'])) $s .= ' · ' . substr($ev['time'], 0, 5);
    }
    return $s;
}

$loc  = $ev['location'] ?? '';
$tags = '#FrontDoorDK #ForeningenFrontDoor #RomâniDinDanemarca #RomænerIDanmark';

$fb_blocks = [];
$ig_blocks = [];
$copy_titles = [];
$copy_descs  = [];
foreach ($selected_langs as $l) {
    $title = $ev['title_' . $l] ?? '';
    $desc  = $ev['description_' . $l] ?? '';
    $date  = event_social_date($ev, $l);
    $fb_blocks[] = "📅 " . mb_strtoupper($title) . "\n\n🗓 " . $date . ($loc ? "\n📍 " . $loc : '') . "\n\n" . $desc;
    $ig_blocks[] = "📅 " . $title . "\n" . ($desc ? mb_substr($desc, 0, 150) : '');
    $copy_titles[] = $title;
    $copy_descs[]  = $desc;
}

$fb_default = implode("\n\n———\n\n", $fb_blocks) . "\n\n" . $tags;
if (!empty($ev['fb_event_url'])) $fb_default .= "\n\n🔗 " . $ev['fb_event_url'];
if (!empty($ev['signup_url']))   $fb_default .= "\n🔗 " . $ev['signup_url'];

$ig_default = implode("\n\n———\n\n", $ig_blocks)
    . "\n\n" . t('ig_facebook_mention')
    . "\n\n" . $tags;

$copy_details_text = implode(" / ", $copy_titles)
    . "\n" . t('date_label') . ': ' . ($ev['date'] ?: '') . ($ev['time'] ? ' ' . substr($ev['time'], 0, 5) : '')
    . "\n" . t('location_label') . ': ' . $loc
    . "\n\n" . implode("\n\n———\n\n", $copy_descs);

// ── ACȚIUNI POST ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    // Păstrăm selecția de limbi și după redirect, ca să nu revină la RO/DA implicit
    // (event-social.php citește langs[] ca array la GET).
    $posted_langs = array_values(array_intersect(explode(',', $_POST['langs_str'] ?? ''), array_keys($lang_labels)));
    $redirect_qs  = 'id=' . $id . ($posted_langs ? '&' . implode('&', array_map(fn($l) => 'langs[]=' . rawurlencode($l), $posted_langs)) : '');

    if ($action === 'save_fb_event_url') {
        $url = trim($_POST['fb_event_url'] ?? '');
        if ($url !== '' && !preg_match('#^https://(www\.)?facebook\.com/#', $url)) {
            flash('error', t('invalid_url') ?: 'Link invalid.');
        } else {
            $pdo->prepare('UPDATE events SET fb_event_url=? WHERE id=?')->execute([$url ?: null, $id]);
            flash('ok', t('updated_short'));
        }
        header('Location: /admin/event-social.php?' . $redirect_qs); exit;
    }

    if ($action === 'post_facebook' && $can_publish) {
        $message = trim($_POST['fb_message'] ?? $fb_default);
        $res = fb_post_to_page($message);
        if ($res['ok']) {
            $pdo->prepare('UPDATE events SET fb_post_id=?, fb_posted_at=NOW() WHERE id=?')->execute([$res['id'], $id]);
            flash('ok', t('fb_posted_label') . ' ✓');
        } else {
            flash('error', $res['error']);
        }
        header('Location: /admin/event-social.php?' . $redirect_qs); exit;
    }

    if ($action === 'post_instagram' && $can_publish) {
        $caption = trim($_POST['ig_caption'] ?? $ig_default);
        if (empty($ev['cover_image'])) {
            flash('error', t('ig_needs_cover_notice'));
        } else {
            $image_url = 'https://foreningenfrontdoor.dk/' . ltrim($ev['cover_image'], '/');
            $res = ig_publish_post($image_url, $caption);
            if ($res['ok']) {
                $pdo->prepare('UPDATE events SET ig_post_id=?, ig_posted_at=NOW() WHERE id=?')->execute([$res['id'], $id]);
                flash('ok', t('ig_posted_label') . ' ✓');
            } else {
                flash('error', $res['error']);
            }
        }
        header('Location: /admin/event-social.php?' . $redirect_qs); exit;
    }

    header('Location: /admin/event-social.php?' . $redirect_qs); exit;
}

layout_head(t('social_auto_h') . ' — ' . ($ev['title_ro'] ?: ''), 'events');
?>
<div class="content" style="max-width:760px">
  <div style="margin-bottom:20px">
    <a href="/admin/events.php" style="font-size:13px;color:rgba(255,255,255,.45)"><?= e(t('back_to_events')) ?></a>
  </div>

  <?php if ($flash): ?><div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>

  <h1 style="font-size:22px;font-weight:700;margin-bottom:6px"><?= e(t('social_auto_h')) ?></h1>
  <p style="font-size:13px;color:rgba(255,255,255,.65);margin-bottom:6px"><strong><?= e($ev['title_ro']) ?></strong> · <?= e(event_social_date($ev, 'ro')) ?></p>
  <p style="font-size:13px;color:rgba(255,255,255,.6);margin-bottom:24px"><?= e(t('social_auto_sub')) ?></p>

  <!-- ── PASUL 1: Eveniment Facebook real ── -->
  <div class="form-section">
    <p class="section-label"><?= e(t('fb_event_step_h')) ?></p>
    <p style="font-size:13px;color:rgba(255,255,255,.6);margin-bottom:16px"><?= e(t('fb_event_step_sub')) ?></p>
    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px">
      <a class="btn btn-solid btn-sm" href="https://www.facebook.com/events/create" target="_blank" rel="noopener"><?= e(t('open_fb_create_event_btn')) ?></a>
      <button type="button" class="btn btn-ghost btn-sm" id="copyDetailsBtn"><?= e(t('copy_details_btn')) ?></button>
    </div>
    <textarea id="copyDetailsSrc" style="position:absolute;left:-9999px" readonly><?= e($copy_details_text) ?></textarea>
    <form method="post" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="save_fb_event_url">
      <div class="field" style="flex:1;min-width:260px">
        <label><?= e(t('fb_event_url_label')) ?></label>
        <input type="url" name="fb_event_url" placeholder="https://www.facebook.com/events/..." value="<?= e($ev['fb_event_url'] ?? '') ?>">
      </div>
      <button class="btn btn-ghost btn-sm" type="submit"><?= e(t('save_fb_event_url_btn')) ?></button>
    </form>
  </div>

  <!-- ── Alegere limbi pentru textul postărilor (Facebook + Instagram) ── -->
  <div class="form-section">
    <p class="section-label"><?= e(t('choose_langs_label')) ?></p>
    <form method="get" style="display:flex;gap:16px;align-items:center;flex-wrap:wrap">
      <input type="hidden" name="id" value="<?= (int)$id ?>">
      <?php foreach ($lang_labels as $lc => $lname): ?>
        <label class="check-row" style="font-size:13px">
          <input type="checkbox" name="langs[]" value="<?= e($lc) ?>" <?= in_array($lc, $selected_langs, true) ? 'checked' : '' ?>>
          <?= e($lname) ?>
        </label>
      <?php endforeach; ?>
      <button class="btn btn-ghost btn-sm" type="submit"><?= e(t('generate_text_btn')) ?></button>
    </form>
  </div>

  <!-- ── PASUL 2: Postare Pagină Facebook ── -->
  <div class="form-section">
    <p class="section-label"><?= e(t('fb_post_step_h')) ?></p>
    <p style="font-size:13px;color:rgba(255,255,255,.6);margin-bottom:16px"><?= e(t('fb_post_step_sub')) ?></p>
    <?php if (!fb_social_configured()): ?>
      <p style="font-size:12px;color:rgba(255,180,80,.85);margin-bottom:14px"><?= e(t('fb_not_configured_notice')) ?></p>
    <?php endif; ?>
    <?php if (!empty($ev['fb_posted_at'])): ?>
      <p style="font-size:12px;color:rgba(120,200,120,.9);margin-bottom:10px">
        <?= e(t('fb_posted_label')) ?>: <?= e(date('d.m.Y H:i', strtotime($ev['fb_posted_at']))) ?>
        <?php if ($ev['fb_post_id']): ?> · <a href="https://www.facebook.com/<?= e($ev['fb_post_id']) ?>" target="_blank" rel="noopener" style="color:rgba(255,255,255,.6)"><?= e(t('view_post_link')) ?></a><?php endif; ?>
      </p>
    <?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="post_facebook">
      <input type="hidden" name="langs_str" value="<?= e(implode(',', $selected_langs)) ?>">
      <div class="field" style="margin-bottom:12px">
        <textarea name="fb_message" rows="10"><?= e($fb_default) ?></textarea>
      </div>
      <?php if ($can_publish): ?>
        <button class="btn btn-solid btn-sm" type="submit" <?= fb_social_configured() ? '' : 'disabled' ?>><?= e($ev['fb_posted_at'] ? t('repost_btn') : t('post_to_facebook_btn')) ?></button>
      <?php else: ?>
        <p style="font-size:12px;color:rgba(255,255,255,.45)"><?= e(t('view_only')) ?></p>
      <?php endif; ?>
    </form>
  </div>

  <!-- ── PASUL 3: Postare Instagram ── -->
  <div class="form-section">
    <p class="section-label"><?= e(t('ig_post_step_h')) ?></p>
    <p style="font-size:13px;color:rgba(255,255,255,.6);margin-bottom:16px"><?= e(t('ig_post_step_sub')) ?></p>
    <?php if (!ig_social_configured()): ?>
      <p style="font-size:12px;color:rgba(255,180,80,.85);margin-bottom:14px"><?= e(t('ig_not_configured_notice')) ?></p>
    <?php endif; ?>
    <?php if (empty($ev['cover_image'])): ?>
      <p style="font-size:12px;color:rgba(255,180,80,.85);margin-bottom:14px"><?= e(t('ig_needs_cover_notice')) ?></p>
    <?php else: ?>
      <img class="cover-preview" src="/<?= e(ltrim($ev['cover_image'],'/')) ?>" alt="" style="max-width:200px;border-radius:10px;margin-bottom:14px">
    <?php endif; ?>
    <?php if (!empty($ev['ig_posted_at'])): ?>
      <p style="font-size:12px;color:rgba(120,200,120,.9);margin-bottom:10px"><?= e(t('ig_posted_label')) ?>: <?= e(date('d.m.Y H:i', strtotime($ev['ig_posted_at']))) ?></p>
    <?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="post_instagram">
      <input type="hidden" name="langs_str" value="<?= e(implode(',', $selected_langs)) ?>">
      <div class="field" style="margin-bottom:12px">
        <textarea name="ig_caption" rows="8"><?= e($ig_default) ?></textarea>
      </div>
      <?php if ($can_publish): ?>
        <button class="btn btn-solid btn-sm" type="submit" <?= (ig_social_configured() && !empty($ev['cover_image'])) ? '' : 'disabled' ?>><?= e($ev['ig_posted_at'] ? t('repost_btn') : t('post_to_instagram_btn')) ?></button>
      <?php else: ?>
        <p style="font-size:12px;color:rgba(255,255,255,.45)"><?= e(t('view_only')) ?></p>
      <?php endif; ?>
    </form>
  </div>
</div>
<script>
document.getElementById('copyDetailsBtn').addEventListener('click', function() {
  var ta = document.getElementById('copyDetailsSrc');
  navigator.clipboard.writeText(ta.value).then(function() {
    var btn = document.getElementById('copyDetailsBtn'), orig = btn.textContent;
    btn.textContent = <?= json_encode(t('copied_label')) ?>;
    setTimeout(function() { btn.textContent = orig; }, 2000);
  });
});
</script>
<?php layout_foot(); ?>
