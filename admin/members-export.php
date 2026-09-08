<?php
require_once __DIR__ . '/auth.php';
$user = require_perm('members', 'view');
$pdo  = get_db();
ensure_member_schema($pdo);

// Câmpuri disponibile pentru export — cheie => [etichetă, formatter opțional].
// Etichetele urmează limba panoului (t()) — doar pentru ecranul de selecție;
// documentul generat mai jos ($FIELDS_DA) rămâne mereu în daneză, vezi nota
// de acolo.
$FIELDS = [
    'member_number'    => t('field_member_number'),
    'name'             => t('field_name'),
    'email'            => t('field_email'),
    'phone'            => t('field_phone'),
    'birth_date'       => t('field_birth_date'),
    'age'              => t('field_age'),
    'gender'           => t('field_gender'),
    'address'          => t('field_address'),
    'postal_code'      => t('field_postal_code'),
    'city'             => t('field_city'),
    'special_needs'    => t('field_special_needs'),
    'special_needs_note' => t('field_special_needs_note'),
    'joined_date'      => t('field_joined_date'),
    'dues_paid'        => t('field_dues_paid'),
    'dues_paid_date'   => t('field_dues_paid_date'),
    'dues_valid_until' => t('field_dues_valid_until'),
    'dues_amount'      => t('field_dues_amount'),
    'dues_method'      => t('field_dues_method'),
    'exempt'           => t('field_exempt'),
    'exempt_reason'    => t('field_exempt_reason'),
    'is_volunteer'     => t('field_is_volunteer'),
    'notes'            => t('field_notes'),
];

// Preset gata făcut pentru medlemsliste-ul cerut de comună (folkeoplysningsloven).
define('EXPORT_PRESET_KOMMUNE', ['name', 'birth_date', 'age', 'address', 'postal_code', 'city']);

// Documentul generat (mai jos, $generate) pleacă din asociație — către comună
// sau alte autorități, sau spre uz intern al bestyrelsen — deci e în daneză,
// spre deosebire de restul panoului admin (rămâne română). Etichetele de
// coloană + valorile enum (metodă plată, sex) au aici traducerea daneză,
// separat de $FIELDS/DUES_METHODS/GENDERS folosite pe ecranul de selecție.
$FIELDS_DA = [
    'member_number'       => 'Medlemsnr.',
    'name'                => 'Navn',
    'email'               => 'E-mail',
    'phone'               => 'Telefon',
    'birth_date'          => 'Fødselsdato',
    'age'                 => 'Alder (pr. 31.12)',
    'gender'              => 'Køn',
    'address'             => 'Adresse',
    'postal_code'         => 'Postnummer',
    'city'                => 'By',
    'special_needs'       => 'Særlige behov',
    'special_needs_note'  => 'Note (særlige behov)',
    'joined_date'         => 'Indmeldelsesdato',
    'dues_paid'           => 'Kontingent betalt',
    'dues_paid_date'      => 'Betalingsdato',
    'dues_valid_until'    => 'Gyldig til',
    'dues_amount'         => 'Beløb (DKK)',
    'dues_method'         => 'Betalingsmetode',
    'exempt'              => 'Fritaget',
    'exempt_reason'       => 'Årsag til fritagelse',
    'is_volunteer'        => 'Frivillig',
    'notes'               => 'Interne noter',
];
$DUES_METHODS_DA = [
    'numerar'   => 'Kontant',
    'transfer'  => 'Bankoverførsel',
    'mobilepay' => 'MobilePay',
    'altul'     => 'Andet',
];
$GENDERS_DA = [
    'f'     => 'Kvinde',
    'm'     => 'Mand',
    'altul' => 'Andet / ønsker ikke at oplyse',
];

// Sortarea exportului — listă restrânsă de coloane sigure (whitelist), ca să
// putem interpola direct numele coloanei în ORDER BY (PDO nu parametrizează
// nume de coloane). „name" e mereu sortarea secundară, pentru ordine stabilă
// când mai mulți membri au aceeași valoare pe coloana aleasă (ex. mai mulți
// fără dată de naștere).
$SORT_FIELDS = [
    'name'           => t('field_name'),
    'city'           => t('field_city'),
    'member_number'  => t('field_member_number'),
    'joined_date'    => t('field_joined_date'),
    'birth_date'     => t('field_birth_date'),
    'dues_paid_date' => t('field_dues_paid_date'),
];
$sort_field = $_GET['sort'] ?? 'name';
if (!array_key_exists($sort_field, $SORT_FIELDS)) { $sort_field = 'name'; }
$sort_dir = ($_GET['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';

$order_sql = "`$sort_field` $sort_dir";
if ($sort_field !== 'name') { $order_sql .= ', `name` ASC'; }

$format = ($_GET['format'] ?? 'pdf') === 'csv' ? 'csv' : 'pdf';

$selected = $_GET['fields'] ?? null;
$generate = isset($_GET['generate']) && is_array($selected) && $selected;

if ($generate) {
    // Doar câmpuri cunoscute, în ordinea definită mai sus (nu în ordinea trimisă de formular).
    $cols = array_keys(array_intersect_key($FIELDS, array_flip($selected)));
    if (!$cols) { $generate = false; }
}

if ($generate) {
    $rows = $pdo->query("SELECT * FROM membership_requests WHERE status='active' ORDER BY $order_sql")->fetchAll();
    $today = date('d.m.Y H:i');

    // Formatarea unei valori pentru o coloană — comună pentru PDF și CSV, ca
    // cele două exporturi să arate identic (aceleași „Ja"/„Nej", aceleași
    // formate de dată, aceeași etichetă daneză pentru enum-uri).
    $format_value = function (string $c, array $r) use ($DUES_METHODS_DA, $GENDERS_DA) {
        $v = $c === 'age' ? age_on_dec31($r['birth_date'] ?? null) : ($r[$c] ?? null);
        switch ($c) {
            case 'dues_paid':
            case 'exempt':
            case 'is_volunteer':
            case 'special_needs':
                return $v ? 'Ja' : 'Nej';
            case 'joined_date':
            case 'dues_paid_date':
            case 'dues_valid_until':
            case 'birth_date':
                return $v ? date('d.m.Y', strtotime($v)) : '—';
            case 'dues_amount':
                return $v !== null && $v !== '' ? number_format((float)$v, 0, ',', '.') . ' DKK' : '—';
            case 'dues_method':
                return $v ? ($DUES_METHODS_DA[$v] ?? $v) : '—';
            case 'gender':
                return $v ? ($GENDERS_DA[$v] ?? $v) : '—';
            case 'age':
                return $v !== null ? (string)$v : '—';
            default:
                return $v !== null && $v !== '' ? (string)$v : '—';
        }
    };

    if ($format === 'csv') {
        // Excel (CSV) — separator „;" (convenția daneză/europeană, deschisă
        // corect implicit de Excel local danez/românesc) + BOM UTF-8, ca
        // diacriticele (æøå, ăâîșț) să se afișeze corect la deschidere.
        $fname = 'medlemsliste-' . date('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        echo "\xEF\xBB\xBF"; // BOM UTF-8
        $out = fopen('php://output', 'w');
        fputcsv($out, array_map(fn($c) => $FIELDS_DA[$c] ?? $FIELDS[$c], $cols), ';');
        foreach ($rows as $r) {
            fputcsv($out, array_map(fn($c) => $format_value($c, $r), $cols), ';');
        }
        fclose($out);
        exit;
    }
    ?>
<!DOCTYPE html>
<html lang="da">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Medlemsliste — Foreningen Front Door</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{-webkit-print-color-adjust:exact;print-color-adjust:exact}
body{font-family:'Helvetica Neue',Arial,sans-serif;color:#111;background:#fff;padding:32px;font-size:13px;position:relative}

.sheet{position:relative;z-index:1}
.bar{display:flex;justify-content:space-between;align-items:flex-end;gap:16px;margin-bottom:14px}
.bar-brand{display:flex;align-items:center;gap:12px}
.bar-brand img{height:44px;width:auto;flex-shrink:0}
.bar h1{font-size:18px;font-weight:700}
.bar .meta{font-size:12px;color:#666;margin-top:2px}
.bar-actions{display:flex;gap:8px}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;font-size:13px;font-weight:600;border:1px solid #111;background:#111;color:#fff;cursor:pointer;text-decoration:none}
.btn-ghost{background:#fff;color:#111}
.confidential-banner{border:1.5px solid #111;background:#fff;padding:10px 14px;font-size:11.5px;font-weight:600;line-height:1.5;letter-spacing:.01em;margin-bottom:20px}
.confidential-banner b{letter-spacing:.06em}
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse}
th{text-align:left;padding:7px 10px;font-size:10px;letter-spacing:.06em;text-transform:uppercase;color:#555;border-bottom:2px solid #111;white-space:nowrap}
td{padding:7px 10px;border-bottom:1px solid #ddd;font-size:12.5px;vertical-align:top}
tr:nth-child(even) td{background:#fafafa}
.empty{padding:40px;text-align:center;color:#888}
.footnote{margin-top:18px;font-size:11px;color:#999}
@page { margin: 1.5cm; }
@media print {
  body{padding:0 8px}
  .no-print{display:none !important}
  table{font-size:11px}
}
</style>
</head>
<body>
  <div class="sheet">
  <div class="bar">
    <div class="bar-brand">
      <img src="/assets/logos/black_logo_square_transparent_background.png" alt="Foreningen Front Door">
      <div>
        <h1>Medlemsliste — Foreningen Front Door</h1>
        <div class="meta"><?= count($rows) ?> aktive medlemmer · genereret <?= e($today) ?></div>
      </div>
    </div>
    <div class="bar-actions no-print">
      <button class="btn" onclick="window.print()">Gem som PDF</button>
      <a class="btn btn-ghost" href="/admin/members-export.php?<?= e(http_build_query(['fields'=>$cols,'sort'=>$sort_field,'dir'=>strtolower($sort_dir)])) ?>">← Vælg andre felter</a>
    </div>
  </div>

  <div class="confidential-banner">
    <b>FORTROLIGT.</b> Dette dokument er kun til internt brug i Foreningen Front Door og til brug for
    offentlige myndigheder (fx kommunen, i forbindelse med folkeoplysningsloven). Må ikke videregives til
    tredjepart eller offentliggøres.
  </div>

  <?php if (empty($rows)): ?>
    <div class="empty">Ingen aktive medlemmer.</div>
  <?php else: ?>
  <div class="table-wrap">
  <table>
    <thead>
      <tr><?php foreach ($cols as $c): ?><th><?= e($FIELDS_DA[$c] ?? $FIELDS[$c]) ?></th><?php endforeach; ?></tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
      <tr>
        <?php foreach ($cols as $c): ?>
        <td><?= e($format_value($c, $r)) ?></td>
        <?php endforeach; ?>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>

  <div class="footnote">Foreningen Front Door · foreningenfrontdoor.dk · Fortroligt dokument — kun til internt brug og til myndigheder.</div>
  </div>
</body>
</html>
    <?php
    exit;
}

layout_head(t('export_members'), 'members');
?>
<div class="content" style="max-width:640px">
  <div style="margin-bottom:16px">
    <a href="/admin/members.php" style="font-size:13px;color:rgba(255,255,255,.45)"><?= e(t('back_to_requests')) ?></a>
  </div>
  <div class="page-head"><h1><?= e(t('export_active_list_h1')) ?></h1></div>
  <p style="font-size:13px;color:rgba(255,255,255,.65);margin-bottom:20px">
    <?= t('export_intro') ?>
  </p>
  <div style="margin-bottom:14px">
    <button type="button" class="btn btn-ghost btn-sm" onclick="fdExportPreset()"><?= e(t('kommune_preset_btn')) ?></button>
  </div>
  <form method="get" action="/admin/members-export.php">
    <input type="hidden" name="generate" value="1">
    <div class="form-section">
      <p class="section-label"><?= e(t('fields_to_include')) ?></p>
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

    <div class="form-section">
      <p class="section-label"><?= e(t('sort_by_label')) ?></p>
      <div class="grid-2" style="max-width:420px">
        <div class="field">
          <select name="sort">
            <?php foreach ($SORT_FIELDS as $k => $lbl): ?>
              <option value="<?= e($k) ?>" <?= $sort_field===$k?'selected':'' ?>><?= e($lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <select name="dir">
            <option value="asc"  <?= strtolower($sort_dir)==='asc' ?'selected':'' ?>><?= e(t('sort_dir_asc')) ?></option>
            <option value="desc" <?= strtolower($sort_dir)==='desc'?'selected':'' ?>><?= e(t('sort_dir_desc')) ?></option>
          </select>
        </div>
      </div>
    </div>

    <div class="form-section">
      <p class="section-label"><?= e(t('export_format_label')) ?></p>
      <div style="display:flex;gap:16px;flex-wrap:wrap">
        <label class="check-row"><input type="radio" name="format" value="pdf" checked><?= e(t('format_pdf')) ?></label>
        <label class="check-row"><input type="radio" name="format" value="csv"><?= e(t('format_csv')) ?></label>
      </div>
    </div>

    <div style="display:flex;gap:10px">
      <button class="btn btn-solid" type="submit"><?= e(t('generate_arrow')) ?></button>
    </div>
  </form>
  <script>
  function fdExportPreset() {
    var preset = <?= json_encode(EXPORT_PRESET_KOMMUNE) ?>;
    document.querySelectorAll('.tag-cb').forEach(function (cb) {
      cb.checked = preset.indexOf(cb.value) !== -1;
    });
  }
  </script>
</div>
<?php layout_foot(); ?>
