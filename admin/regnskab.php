<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/google-sheets.php';
$user = require_perm('regnskab', 'view');

/* -------------------------------------------------------------
   Citire date din Google Sheets. Toată logica e read-only — v1:
   editarea rândurilor se face în continuare direct în Sheets de
   către trezorier, admin panel-ul doar afișează.

   Structura așteptată (fiecare cu antet pe rândul 1) — tab-urile
   și coloanele sunt în daneză, pentru compliance cu restul
   documentelor (folkeoplysningsloven, raportare către kommune):
     Bilag!A:J         Dato | Bilagsnr. | Beskrivelse | Kategori |
                        Projekt | Indtægt (DKK) | Udgift (DKK) |
                        Betalingsmetode | Kvittering (link) | Indtastet af
     Donationer!A:G    Dato | Donor | Beløb (DKK) | Projekt/formål |
                        Metode | Kvittering udstedt | Note
     Projekter!A:F     Projekt | Budget (DKK) | Indsamlet indtil nu (DKK) |
                        Brugt (DKK) | Status | Note
     Kontingent!A:F    Medlemsnavn | Beløb (DKK) | Betalingsdato |
                        Gyldigt år | Betalingsmetode | Note
   ------------------------------------------------------------- */

function parse_dkk($raw): float {
    $s = trim((string)$raw);
    if ($s === '') return 0.0;
    $s = preg_replace('/[^0-9,.\-]/', '', $s); // scoate "kr", "DKK", spații etc.
    if ($s === '') return 0.0;
    if (strpos($s, ',') !== false && strpos($s, '.') !== false) {
        // "1.500,50" — punctul e separator de mii, virgula e zecimală
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
    } elseif (strpos($s, ',') !== false) {
        $s = str_replace(',', '.', $s);
    }
    return is_numeric($s) ? (float)$s : 0.0;
}

function parse_ro_date(string $s): ?DateTime {
    $s = trim($s);
    if ($s === '') return null;
    foreach (['d.m.Y', 'd-m-Y', 'Y-m-d', 'd/m/Y'] as $fmt) {
        $d = DateTime::createFromFormat($fmt, $s);
        if ($d !== false) return $d;
    }
    $ts = strtotime($s);
    return $ts !== false ? (new DateTime())->setTimestamp($ts) : null;
}

$gsError = null;
$bilag = $donatii = $proiecte = $kontingent = [];

try {
    $ranges = gs_ranges(['Bilag!A2:J2000', 'Donationer!A2:G2000', 'Projekter!A2:F200', 'Kontingent!A2:F2000']);
    $bilag      = $ranges['Bilag!A2:J2000'] ?? [];
    $donatii    = $ranges['Donationer!A2:G2000'] ?? [];
    $proiecte   = $ranges['Projekter!A2:F200'] ?? [];
    $kontingent = $ranges['Kontingent!A2:F2000'] ?? [];
} catch (GoogleSheetsException $e) {
    $gsError = $e->getMessage();
}

// ── Bilag: normalizează + agregă ──────────────────────────────
$rows = [];
$totalVenit = $totalCheltuiala = 0.0;
$perCategorieVenit = $perCategorieCheltuiala = [];
foreach ($bilag as $r) {
    $descriere = trim($r[2] ?? '');
    if ($descriere === '' && trim($r[0] ?? '') === '') continue; // rând complet gol
    $venit      = parse_dkk($r[5] ?? '');
    $cheltuiala = parse_dkk($r[6] ?? '');
    $categorie  = trim($r[3] ?? '') !== '' ? trim($r[3]) : '(fără categorie)';
    $date       = parse_ro_date($r[0] ?? '');
    $rows[] = [
        'date'       => $date,
        'date_raw'   => $r[0] ?? '',
        'nr'         => $r[1] ?? '',
        'descriere'  => $descriere,
        'categorie'  => $categorie,
        'proiect'    => trim($r[4] ?? ''),
        'venit'      => $venit,
        'cheltuiala' => $cheltuiala,
        'metoda'     => $r[7] ?? '',
        'chitanta'   => trim($r[8] ?? ''),
        'de'         => $r[9] ?? '',
    ];
    $totalVenit      += $venit;
    $totalCheltuiala += $cheltuiala;
    if ($venit > 0)      $perCategorieVenit[$categorie]      = ($perCategorieVenit[$categorie] ?? 0) + $venit;
    if ($cheltuiala > 0) $perCategorieCheltuiala[$categorie] = ($perCategorieCheltuiala[$categorie] ?? 0) + $cheltuiala;
}

// ── Kontingent: se adaugă la totalul de venituri, separat de Bilag ──
$kontRows = [];
$totalKontingent = 0.0;
foreach ($kontingent as $r) {
    $nume = trim($r[0] ?? '');
    $suma = parse_dkk($r[1] ?? '');
    if ($nume === '' && $suma === 0.0) continue;
    $kontRows[] = [
        'nume'   => $nume,
        'suma'   => $suma,
        'data'   => $r[2] ?? '',
        'an'     => $r[3] ?? '',
        'metoda' => $r[4] ?? '',
        'nota'   => $r[5] ?? '',
    ];
    $totalKontingent += $suma;
}
if ($totalKontingent > 0) {
    $totalVenit += $totalKontingent;
    $perCategorieVenit['Kontingent'] = ($perCategorieVenit['Kontingent'] ?? 0) + $totalKontingent;
}

arsort($perCategorieVenit);
arsort($perCategorieCheltuiala);
usort($rows, function ($a, $b) {
    $ta = $a['date'] ? $a['date']->getTimestamp() : 0;
    $tb = $b['date'] ? $b['date']->getTimestamp() : 0;
    return $tb <=> $ta;
});
$sold = $totalVenit - $totalCheltuiala;

// ── Donații ──────────────────────────────────────────────────
$donRows = [];
$totalDonatii = 0.0;
$donatiiPerProiect = [];
foreach ($donatii as $r) {
    $donator = trim($r[1] ?? '');
    $suma    = parse_dkk($r[2] ?? '');
    if ($donator === '' && $suma === 0.0 && trim($r[0] ?? '') === '') continue;
    $scop = trim($r[3] ?? '') !== '' ? trim($r[3]) : '(general)';
    $donRows[] = [
        'date'     => $r[0] ?? '',
        'donator'  => $donator !== '' ? $donator : 'Anonim',
        'suma'     => $suma,
        'scop'     => $scop,
        'metoda'   => $r[4] ?? '',
        'chitanta' => $r[5] ?? '',
        'nota'     => $r[6] ?? '',
    ];
    $totalDonatii += $suma;
    $donatiiPerProiect[$scop] = ($donatiiPerProiect[$scop] ?? 0) + $suma;
}
arsort($donatiiPerProiect);

// ── Proiecte (buget vs. realizat) ───────────────────────────────
$projRows = [];
foreach ($proiecte as $r) {
    $nume = trim($r[0] ?? '');
    if ($nume === '') continue;
    $buget    = parse_dkk($r[1] ?? '');
    $stransa  = parse_dkk($r[2] ?? '');
    $cheltuit = parse_dkk($r[3] ?? '');
    $pct = $buget > 0 ? min(100, round($stransa / $buget * 100)) : 0;
    $projRows[] = [
        'nume'     => $nume,
        'buget'    => $buget,
        'stransa'  => $stransa,
        'cheltuit' => $cheltuit,
        'pct'      => $pct,
        'status'   => trim($r[4] ?? ''),
        'nota'     => trim($r[5] ?? ''),
    ];
}

function dkk($n): string {
    return number_format((float)$n, 0, ',', '.') . ' DKK';
}

layout_head('Regnskab', 'regnskab');
?>
<style>
.rk-cards{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:36px}
@media(max-width:760px){.rk-cards{grid-template-columns:1fr}}
.rk-card{background:rgba(255,255,255,.03);backdrop-filter:blur(16px) saturate(140%);-webkit-backdrop-filter:blur(16px) saturate(140%);border:1px solid rgba(255,255,255,.08);border-radius:18px;padding:26px 24px;box-shadow:0 8px 30px rgba(0,0,0,.25)}
.rk-card-label{font-size:10px;font-weight:500;letter-spacing:.2em;text-transform:uppercase;color:rgba(255,255,255,.45);margin-bottom:10px}
.rk-card-value{font-family:'Nunito',sans-serif;font-weight:900;font-size:28px;letter-spacing:-.01em}
.rk-card-value.pos{color:#7fd88f}
.rk-card-value.neg{color:#ff8f8f}

.rk-section{margin-bottom:44px}
.rk-section-title{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:18px;flex-wrap:wrap}
.rk-section-title h2{font-family:'Nunito',sans-serif;font-weight:900;font-size:16px;letter-spacing:-.01em}
.rk-section-hint{font-size:12px;color:rgba(255,255,255,.45);font-weight:300}

.rk-bars{display:flex;flex-direction:column;gap:10px}
.rk-bar-row{display:grid;grid-template-columns:150px 1fr auto;gap:12px;align-items:center;font-size:13px}
@media(max-width:600px){.rk-bar-row{grid-template-columns:110px 1fr auto;font-size:12px}}
.rk-bar-label{color:rgba(255,255,255,.65);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.rk-bar-track{background:rgba(255,255,255,.07);height:8px;border-radius:999px;position:relative;overflow:hidden}
.rk-bar-fill{background:#fff;height:100%;border-radius:999px}
.rk-bar-fill.cheltuiala{background:rgba(255,140,140,.7)}
.rk-bar-value{color:rgba(255,255,255,.55);font-weight:300;white-space:nowrap}

.rk-progress{background:rgba(255,255,255,.09);height:8px;border-radius:999px;margin-top:8px;position:relative;overflow:hidden}
.rk-progress-fill{background:#fff;height:100%;border-radius:999px;transition:width .3s}

.rk-two-col{display:grid;grid-template-columns:1fr 1fr;gap:32px}
@media(max-width:820px){.rk-two-col{grid-template-columns:1fr}}

.rk-proj-card{background:rgba(255,255,255,.03);backdrop-filter:blur(14px) saturate(140%);-webkit-backdrop-filter:blur(14px) saturate(140%);border:1px solid rgba(255,255,255,.08);border-radius:16px;padding:20px 22px;margin-bottom:14px}
.rk-proj-head{display:flex;align-items:baseline;justify-content:space-between;gap:12px;margin-bottom:4px;flex-wrap:wrap}
.rk-proj-head h3{font-family:'Nunito',sans-serif;font-weight:700;font-size:15px}
.rk-proj-meta{font-size:12px;color:rgba(255,255,255,.55);font-weight:300}
.rk-proj-nota{font-size:12px;color:rgba(255,255,255,.5);font-weight:300;margin-top:8px}

</style>
<div class="content">
  <div class="page-head">
    <h1>Regnskab</h1>
  </div>

  <?php if ($gsError): ?>
    <div class="empty">
      <?= e(t('gs_error_intro')) ?><br>
      <span style="font-size:12px;color:rgba(255,120,120,.8)"><?= e($gsError) ?></span><br><br>
      <?= t('gs_error_check_config') ?>
    </div>
  <?php elseif (empty($bilag) && empty($donatii) && empty($proiecte) && empty($kontingent)): ?>
    <div class="empty">
      <?= e(t('gs_empty_intro')) ?><br>
      <?= t('gs_empty_hint') ?>
    </div>
  <?php else: ?>

    <!-- ── PREZENTARE GENERALĂ ── -->
    <div class="rk-cards">
      <div class="rk-card">
        <div class="rk-card-label"><?= e(t('total_income_label')) ?></div>
        <div class="rk-card-value pos"><?= dkk($totalVenit) ?></div>
      </div>
      <div class="rk-card">
        <div class="rk-card-label"><?= e(t('total_expenses_label')) ?></div>
        <div class="rk-card-value neg"><?= dkk($totalCheltuiala) ?></div>
      </div>
      <div class="rk-card">
        <div class="rk-card-label"><?= e(t('balance_label')) ?></div>
        <div class="rk-card-value <?= $sold >= 0 ? 'pos' : 'neg' ?>"><?= dkk($sold) ?></div>
      </div>
    </div>

    <?php if ($perCategorieVenit || $perCategorieCheltuiala): ?>
    <div class="rk-section">
      <div class="rk-section-title"><h2><?= e(t('by_category_h')) ?></h2></div>
      <div class="rk-two-col">
        <div>
          <div class="rk-section-hint" style="margin-bottom:10px"><?= e(t('rk_income_label')) ?></div>
          <div class="rk-bars">
            <?php $max = $perCategorieVenit ? max($perCategorieVenit) : 1; foreach ($perCategorieVenit as $cat => $val): ?>
            <div class="rk-bar-row">
              <div class="rk-bar-label"><?= e($cat) ?></div>
              <div class="rk-bar-track"><div class="rk-bar-fill" style="width:<?= $max > 0 ? round($val / $max * 100) : 0 ?>%"></div></div>
              <div class="rk-bar-value"><?= dkk($val) ?></div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <div>
          <div class="rk-section-hint" style="margin-bottom:10px"><?= e(t('rk_expenses_label')) ?></div>
          <div class="rk-bars">
            <?php $max = $perCategorieCheltuiala ? max($perCategorieCheltuiala) : 1; foreach ($perCategorieCheltuiala as $cat => $val): ?>
            <div class="rk-bar-row">
              <div class="rk-bar-label"><?= e($cat) ?></div>
              <div class="rk-bar-track"><div class="rk-bar-fill cheltuiala" style="width:<?= $max > 0 ? round($val / $max * 100) : 0 ?>%"></div></div>
              <div class="rk-bar-value"><?= dkk($val) ?></div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- ── PROIECTE: BUGET VS. REALIZAT ── -->
    <?php if ($projRows): ?>
    <div class="rk-section">
      <div class="rk-section-title"><h2><?= e(t('projects_budget_h')) ?></h2></div>
      <?php foreach ($projRows as $p): ?>
        <div class="rk-proj-card">
          <div class="rk-proj-head">
            <h3><?= e($p['nume']) ?></h3>
            <?php if ($p['status'] !== ''): ?><span class="badge"><?= e($p['status']) ?></span><?php endif; ?>
          </div>
          <div class="rk-proj-meta">
            <?= dkk($p['stransa']) ?> <?= e(t('raised_of')) ?> <?= dkk($p['buget']) ?> (<?= $p['pct'] ?>%)
            <?php if ($p['cheltuit'] > 0): ?> · <?= dkk($p['cheltuit']) ?><?= e(t('spent_suffix')) ?><?php endif; ?>
          </div>
          <?php if ($p['buget'] > 0): ?>
            <div class="rk-progress"><div class="rk-progress-fill" style="width:<?= $p['pct'] ?>%"></div></div>
          <?php endif; ?>
          <?php if ($p['nota'] !== ''): ?><div class="rk-proj-nota"><?= e($p['nota']) ?></div><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ── DONAȚII & FUNDRAISING ── -->
    <?php if ($donRows): ?>
    <div class="rk-section">
      <div class="rk-section-title">
        <h2><?= e(t('donations_h')) ?></h2>
        <span class="rk-section-hint"><?= e(sprintf(t('donations_summary'), dkk($totalDonatii), count($donRows))) ?></span>
      </div>
      <?php if ($donatiiPerProiect): ?>
        <div class="rk-bars" style="margin-bottom:22px">
          <?php $max = max($donatiiPerProiect); foreach ($donatiiPerProiect as $scop => $val): ?>
          <div class="rk-bar-row">
            <div class="rk-bar-label"><?= e($scop) ?></div>
            <div class="rk-bar-track"><div class="rk-bar-fill" style="width:<?= $max > 0 ? round($val / $max * 100) : 0 ?>%"></div></div>
            <div class="rk-bar-value"><?= dkk($val) ?></div>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <div class="table-wrap">
        <table>
          <thead><tr><th><?= e(t('th_date')) ?></th><th><?= e(t('th_donor')) ?></th><th><?= e(t('th_amount')) ?></th><th><?= e(t('th_project_purpose')) ?></th><th><?= e(t('th_method')) ?></th><th><?= e(t('th_receipt')) ?></th></tr></thead>
          <tbody>
            <?php foreach ($donRows as $d): ?>
            <tr>
              <td><?= e($d['date']) ?></td>
              <td><?= e($d['donator']) ?></td>
              <td><?= dkk($d['suma']) ?></td>
              <td><?= e($d['scop']) ?></td>
              <td><?= e($d['metoda']) ?></td>
              <td><?= e($d['chitanta']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <!-- ── COTIZAȚII (KONTINGENT) ── -->
    <?php if ($kontRows): ?>
    <div class="rk-section">
      <div class="rk-section-title">
        <h2>Kontingent</h2>
        <span class="rk-section-hint"><?= e(sprintf(t('kontingent_summary'), dkk($totalKontingent), count($kontRows))) ?></span>
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr><th><?= e(t('th_member')) ?></th><th><?= e(t('th_amount')) ?></th><th><?= e(t('th_payment_date')) ?></th><th><?= e(t('th_valid_year')) ?></th><th><?= e(t('th_method')) ?></th></tr></thead>
          <tbody>
            <?php foreach ($kontRows as $k): ?>
            <tr>
              <td><?= e($k['nume']) ?></td>
              <td><?= dkk($k['suma']) ?></td>
              <td><?= e($k['data']) ?></td>
              <td><?= e($k['an']) ?></td>
              <td><?= e($k['metoda']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <!-- ── BILAG: TOATE TRANZACȚIILE ── -->
    <?php if ($rows): ?>
    <div class="rk-section">
      <div class="rk-section-title">
        <h2><?= e(t('bilag_h')) ?></h2>
        <span class="rk-section-hint"><?= e(sprintf(t('bilag_rows_count'), count($rows))) ?></span>
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr><th><?= e(t('th_date')) ?></th><th><?= e(t('th_nr')) ?></th><th><?= e(t('th_description')) ?></th><th><?= e(t('th_category')) ?></th><th><?= e(t('th_project')) ?></th><th><?= e(t('th_income')) ?></th><th><?= e(t('th_expense')) ?></th><th><?= e(t('th_method')) ?></th><th><?= e(t('th_receipt')) ?></th></tr></thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
            <tr>
              <td style="white-space:nowrap"><?= e($r['date_raw']) ?></td>
              <td><?= e($r['nr']) ?></td>
              <td><?= e($r['descriere']) ?></td>
              <td><?= e($r['categorie']) ?></td>
              <td><?= e($r['proiect']) ?></td>
              <td><?= $r['venit'] > 0 ? dkk($r['venit']) : '—' ?></td>
              <td><?= $r['cheltuiala'] > 0 ? dkk($r['cheltuiala']) : '—' ?></td>
              <td><?= e($r['metoda']) ?></td>
              <td><?= $r['chitanta'] !== '' ? '<a href="' . e($r['chitanta']) . '" target="_blank" rel="noopener">↗</a>' : '—' ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

  <?php endif; ?>
</div>
<?php layout_foot(); ?>
