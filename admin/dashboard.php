<?php
require_once __DIR__ . '/auth.php';
$user = require_login();

// Secțiunile posibile — apar doar cele la care userul are măcar 'view'.
$sections = [
    'events'    => ['label' => t('nav_events'),    'desc' => t('dash_events_desc'),    'url' => '/admin/events.php'],
    'projects'  => ['label' => t('nav_projects'),  'desc' => t('dash_projects_desc'),  'url' => '/admin/projects.php'],
    'members'   => ['label' => t('nav_members'),   'desc' => t('dash_members_desc'),   'url' => '/admin/members.php'],
    'topics'    => ['label' => t('nav_topics'),    'desc' => t('dash_topics_desc'),    'url' => '/admin/topics.php'],
    'documents' => ['label' => t('nav_documents'), 'desc' => t('dash_documents_desc'), 'url' => '/admin/documents.php'],
    'regnskab'  => ['label' => t('nav_regnskab'),  'desc' => t('dash_regnskab_desc'),  'url' => '/admin/regnskab.php'],
];

layout_head(t('nav_dashboard'), 'dashboard');
?>
<style>
.dash-hero{display:flex;align-items:center;gap:22px;flex-wrap:wrap;border-radius:22px;padding:36px 40px;margin-bottom:32px;border:1px solid rgba(255,255,255,.09);background:rgba(255,255,255,.03);backdrop-filter:blur(22px) saturate(150%);-webkit-backdrop-filter:blur(22px) saturate(150%);box-shadow:0 20px 60px rgba(0,0,0,.35);position:relative;overflow:hidden}
.dash-hero::before{content:'';position:absolute;inset:0;background:radial-gradient(circle at 15% 20%,rgba(255,255,255,.1),transparent 55%);pointer-events:none}
.dash-hero-logo{height:52px;width:auto;flex-shrink:0;object-fit:contain;position:relative}
.dash-hero-text{position:relative}
.dash-hero-text h1{font-family:'Nunito',sans-serif;font-weight:900;font-size:26px;letter-spacing:-.01em;margin-bottom:6px}
.dash-hero-text p{font-size:14px;font-weight:300;color:rgba(255,255,255,.6)}
.dash-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:16px}
.dash-card{display:flex;flex-direction:column;gap:8px;padding:22px;border-radius:18px;border:1px solid rgba(255,255,255,.08);background:rgba(255,255,255,.03);backdrop-filter:blur(16px) saturate(140%);-webkit-backdrop-filter:blur(16px) saturate(140%);box-shadow:0 8px 30px rgba(0,0,0,.2);transition:border-color .15s,background .15s,transform .15s}
.dash-card:hover{border-color:rgba(255,255,255,.25);background:rgba(255,255,255,.06);transform:translateY(-2px)}
.dash-card h3{font-family:'Nunito',sans-serif;font-weight:700;font-size:15px}
.dash-card p{font-size:12.5px;font-weight:300;color:rgba(255,255,255,.55);line-height:1.5}
@media(max-width:600px){.dash-hero{padding:28px 24px}}
</style>
<div class="content">
  <div class="dash-hero">
    <img class="dash-hero-logo" src="/assets/logos/white_logo_square_transparent_background.png" alt="Front Door">
    <div class="dash-hero-text">
      <h1><?= e(t('dashboard_welcome_prefix')) ?><?= e($user['name']) ?>!</h1>
      <p><?= e(t('dashboard_subtitle')) ?></p>
    </div>
  </div>

  <div class="dash-grid">
    <?php foreach ($sections as $key => $s): ?>
      <?php if (!has_perm($user, $key, 'view')) continue; ?>
      <a class="dash-card" href="<?= e($s['url']) ?>">
        <h3><?= e($s['label']) ?></h3>
        <p><?= e($s['desc']) ?></p>
      </a>
    <?php endforeach; ?>
    <a class="dash-card" href="/admin/settings.php">
      <h3><?= e(t('nav_settings')) ?></h3>
      <p><?= e(t('dash_settings_desc')) ?></p>
    </a>
  </div>
</div>
<?php layout_foot(); ?>
