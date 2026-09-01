<?php
require_once __DIR__ . '/auth.php';
$user = require_perm('members', 'view');
$pdo  = get_db();
ensure_member_schema($pdo);

// Câmpuri disponibile pentru export — cheie => [etichetă, formatter opțional].
$FIELDS = [
    'member_number'    => 'Nr. membru',
    'name'             => 'Nume',
    'email'            => 'Email',
    'phone'            => 'Telefon',
    'city'             => 'Oraș',
    'joined_date'      => 'Dată aderare',
    'dues_paid'        => 'Cotizație plătită',
    'dues_paid_date'   => 'Data plății',
    'dues_valid_until' => 'Valabil până la',
    'dues_amount'      => 'Sumă plătită (DKK)',
    'dues_method'      => 'Metodă plată',
    'exempt'           => 'Scutit',
    'exempt_reason'    => 'Motiv scutire',
    'is_volunteer'     => 'Voluntar',
    'notes'            => 'Note interne',
];

$selected = $_GET['fields'] ?? null;
$generate = isset($_GET['generate']) && is_array($selected) && $selected;

if ($generate) {
    // Doar câmpuri cunoscute, în ordinea definită mai sus (nu în ordinea trimisă de formular).
    $cols = array_keys(array_intersect_key($FIELDS, array_flip($selected)));
    if (!$cols) { $generate = false; }
}

if ($generate) {
    $rows = $pdo->query("SELECT * FROM membership_requests WHERE status='active' ORDER BY name ASC")->fetchAll();
    $today = date('d.m.Y H:i');
    ?>
<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Listă membri activi — Front Door</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Helvetica Neue',Arial,sans-serif;color:#111;background:#fff;padding:32px;font-size:13px}
.bar{display:flex;justify-content:space-between;align-items:center;margin-bottom:22px}
.bar h1{font-size:18px;font-weight:700}
.bar .meta{font-size:12px;color:#666;margin-top:2px}
.bar-actions{display:flex;gap:8px}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;font-size:13px;font-weight:600;border:1px solid #111;background:#111;color:#fff;cursor:pointer;text-decoration:none}
.btn-ghost{background:#fff;color:#111}
table{width:100%;border-collapse:collapse}
th{text-align:left;padding:7px 10px;font-size:10px;letter-spacing:.06em;text-transform:uppercase;color:#555;border-bottom:2px solid #111;white-space:nowrap}
td{padding:7px 10px;border-bottom:1px solid #ddd;font-size:12.5px;vertical-align:top}
tr:nth-child(even) td{background:#fafafa}
.empty{padding:40px;text-align:center;color:#888}
.footnote{margin-top:18px;font-size:11px;color:#999}
@media print {
  body{padding:0}
  .no-print{display:none !important}
  table{font-size:11px}
}
</style>
</head>
<body>
  <div class="bar no-print">
    <div>
      <h1>Listă membri activi — Foreningen Front Door</h1>
      <div class="meta"><?= count($rows) ?> membri · generat <?= e($today) ?></div>
    </div>
    <div class="bar-actions">
      <button class="btn" onclick="window.print()">🖨 Salvează ca PDF</button>
      <a class="btn btn-ghost" href="/admin/members-export.php">← Alege alte câmpuri</a>
    </div>
  </div>
  <div class="bar" style="display:none" id="printHeader">
    <div>
      <h1>Listă membri activi — Foreningen Front Door</h1>
      <div class="meta"><?= count($rows) ?> membri · generat <?= e($today) ?></div>
    </div>
  </div>
  <style>@media print { #printHeader{display:block !important;margin-bottom:16px} }</style>

  <?php if (empty($rows)): ?>
    <div class="empty">Niciun membru activ.</div>
  <?php else: ?>
  <table>
    <thead>
      <tr><?php foreach ($cols as $c): ?><th><?= e($FIELDS[$c]) ?></th><?php endforeach; ?></tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
      <tr>
        <?php foreach ($cols as $c):
          $v = $r[$c] ?? null;
          switch ($c) {
              case 'dues_paid':
              case 'exempt':
              case 'is_volunteer':
                  $out = $v ? 'Da' : 'Nu';
                  break;
              case 'joined_date':
              case 'dues_paid_date':
              case 'dues_valid_until':
                  $out = $v ? date('d.m.Y', strtotime($v)) : '—';
                  break;
              case 'dues_amount':
                  $out = $v !== null && $v !== '' ? number_format((float)$v, 0, ',', '.') . ' DKK' : '—';
                  break;
              case 'dues_method':
                  $out = $v ? (DUES_METHODS[$v] ?? $v) : '—';
                  break;
              default:
                  $out = $v !== null && $v !== '' ? $v : '—';
          }
        ?>
        <td><?= e((string)$out) ?></td>
        <?php endforeach; ?>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

  <div class="footnote no-print">Foreningen Front Door · foreningenfrontdoor.dk/admin</div>
</body>
</html>
    <?php
    exit;
}

layout_head('Export membri', 'members');
?>
<div class="content" style="max-width:640px">
  <div style="margin-bottom:16px">
    <a href="/admin/members.php" style="font-size:13px;color:rgba(255,255,255,.25)">← Înapoi la cereri</a>
  </div>
  <div class="page-head"><h1>Export listă membri activi</h1></div>
  <p style="font-size:13px;color:rgba(255,255,255,.4);margin-bottom:20px">
    Exportul include doar membrii cu statusul <strong>Activ</strong>. Alege ce coloane vrei în document —
    de exemplu doar nume + oraș pentru comuna (kommune), sau tot ce ține de cotizație pentru evidența internă.
  </p>
  <form method="get" action="/admin/members-export.php">
    <input type="hidden" name="generate" value="1">
    <div class="form-section">
      <p class="section-label">Câmpuri de inclus</p>
      <div class="tags-wrap">
        <?php foreach ($FIELDS as $k => $lbl): ?>
          <div>
            <input class="tag-cb" type="checkbox" name="fields[]" id="f-<?= e($k) ?>" value="<?= e($k) ?>"
                   <?= in_array($k, ['name','city','dues_paid'], true) ? 'checked' : '' ?>>
            <label class="tag-lbl" for="f-<?= e($k) ?>"><?= e($lbl) ?></label>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <div style="display:flex;gap:10px">
      <button class="btn btn-solid" type="submit">Generează →</button>
    </div>
  </form>
</div>
<?php layout_foot(); ?>
