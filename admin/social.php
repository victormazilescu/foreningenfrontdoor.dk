<?php
require_once __DIR__ . '/auth.php';
$user = require_login();
$pdo  = get_db();

$events = $pdo->query("SELECT id,title_ro,title_da,description_ro,description_da,date,time,location,category,signup_url FROM events WHERE status='active' AND date>=CURDATE() ORDER BY date ASC LIMIT 20")->fetchAll();
$projects = $pdo->query("SELECT id,title_ro,title_da,description_ro,description_da,label_ro,label_da,category,signup_url FROM projects WHERE status IN('active','completed') ORDER BY sort_order ASC LIMIT 20")->fetchAll();

// Preselect din URL
$preselect_event   = isset($_GET['event'])   ? (int)$_GET['event']   : 0;
$preselect_project = isset($_GET['project']) ? (int)$_GET['project'] : 0;

$cat_ro = ['artistic'=>t('cat_artistic'),'cultural'=>t('cat_cultural'),'social'=>t('cat_social')];

layout_head(t('social_gen_h'), 'events');
?>
<div class="content">
  <div class="page-head">
    <h1><?= e(t('social_gen_h')) ?></h1>
    <p style="font-size:14px;color:rgba(255,255,255,.65);margin-top:4px"><?= e(t('social_gen_sub')) ?></p>
  </div>

  <!-- Tabs Evenimente / Proiecte -->
  <div style="display:flex;gap:0;border-bottom:1px solid rgba(255,255,255,.07);margin-bottom:24px">
    <button id="tabEvBtn" class="tab-trigger active" onclick="switchTab('events')"><?= e(t('nav_events')) ?></button>
    <button id="tabPrBtn" class="tab-trigger" onclick="switchTab('projects')"><?= e(t('nav_projects')) ?></button>
  </div>

  <!-- Evenimente -->
  <div id="tab-events">
    <?php if(empty($events)): ?>
      <div class="empty"><?= e(t('no_upcoming_events')) ?></div>
    <?php else: ?>
    <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:28px">
      <?php foreach($events as $ev): ?>
      <div class="social-item <?= $preselect_event===$ev['id']?'selected':'' ?>"
           onclick="selectItem('event',this)"
           data-type="event"
           data-title-ro="<?= e($ev['title_ro']) ?>"
           data-title-da="<?= e($ev['title_da']) ?>"
           data-desc-ro="<?= e($ev['description_ro']) ?>"
           data-desc-da="<?= e($ev['description_da']) ?>"
           data-date="<?= e($ev['date']) ?>"
           data-time="<?= e($ev['time']??'') ?>"
           data-location="<?= e($ev['location']??'') ?>"
           data-category="<?= e($ev['category']) ?>"
           data-signup="<?= e($ev['signup_url']??'') ?>">
        <input type="radio" style="accent-color:rgba(255,255,255,.15)" <?= $preselect_event===$ev['id']?'checked':'' ?>>
        <div style="flex:1">
          <div style="font-size:14px;font-weight:700"><?= e($ev['title_ro']) ?> <span style="color:rgba(255,255,255,.6);font-weight:400;font-size:12px">/ <?= e($ev['title_da']) ?></span></div>
          <div style="font-size:12px;color:rgba(255,255,255,.6)"><span style="padding:1px 7px;background:rgba(255,255,255,.06);color:rgba(255,255,255,.6);border-radius:999px;font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;margin-right:6px"><?= e($cat_ro[$ev['category']]??$ev['category']) ?></span><?= e(date('d.m.Y',strtotime($ev['date']))) ?><?= $ev['time']?' · '.e(substr($ev['time'],0,5)):'' ?><?= $ev['location']?' · '.e($ev['location']):'' ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- Proiecte -->
  <div id="tab-projects" style="display:none">
    <?php if(empty($projects)): ?>
      <div class="empty"><?= e(t('no_active_projects_social')) ?></div>
    <?php else: ?>
    <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:28px">
      <?php foreach($projects as $pr): ?>
      <div class="social-item <?= $preselect_project===$pr['id']?'selected':'' ?>"
           onclick="selectItem('project',this)"
           data-type="project"
           data-title-ro="<?= e($pr['title_ro']) ?>"
           data-title-da="<?= e($pr['title_da']) ?>"
           data-desc-ro="<?= e($pr['description_ro']) ?>"
           data-desc-da="<?= e($pr['description_da']) ?>"
           data-label-ro="<?= e($pr['label_ro']??'') ?>"
           data-label-da="<?= e($pr['label_da']??'') ?>"
           data-category="<?= e($pr['category']) ?>"
           data-signup="<?= e($pr['signup_url']??'') ?>">
        <input type="radio" style="accent-color:rgba(255,255,255,.15)" <?= $preselect_project===$pr['id']?'checked':'' ?>>
        <div style="flex:1">
          <div style="font-size:14px;font-weight:700"><?= e($pr['title_ro']) ?> <span style="color:rgba(255,255,255,.6);font-weight:400;font-size:12px">/ <?= e($pr['title_da']) ?></span></div>
          <div style="font-size:12px;color:rgba(255,255,255,.6)"><span style="padding:1px 7px;background:rgba(255,255,255,.06);color:rgba(255,255,255,.6);border-radius:999px;font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;margin-right:6px"><?= e($cat_ro[$pr['category']]??$pr['category']) ?></span><?= e($pr['label_ro']??'') ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- Generator -->
  <div id="generator" style="display:<?= ($preselect_event||$preselect_project)?'block':'none' ?>">
    <div style="background:rgba(255,255,255,.03);backdrop-filter:blur(16px) saturate(140%);-webkit-backdrop-filter:blur(16px) saturate(140%);border:1px solid rgba(255,255,255,.08);border-radius:18px;padding:24px;position:relative;box-shadow:0 8px 30px rgba(0,0,0,.25)">
      <div style="font-size:11px;letter-spacing:.18em;text-transform:uppercase;color:rgba(255,255,255,.65);margin-bottom:20px"><?= e(t('generated_content_label')) ?></div>

      <!-- Opțiuni -->
      <div style="display:flex;gap:18px;flex-wrap:wrap;margin-bottom:20px">
        <label style="display:flex;align-items:center;gap:7px;font-size:13px;color:rgba(255,255,255,.65);cursor:pointer"><input type="checkbox" id="optEmoji" checked style="accent-color:rgba(255,255,255,.15)"> <?= e(t('opt_emoji')) ?></label>
        <label style="display:flex;align-items:center;gap:7px;font-size:13px;color:rgba(255,255,255,.65);cursor:pointer"><input type="checkbox" id="optHash" checked style="accent-color:rgba(255,255,255,.15)"> <?= e(t('opt_hashtags')) ?></label>
        <label style="display:flex;align-items:center;gap:7px;font-size:13px;color:rgba(255,255,255,.65);cursor:pointer"><input type="checkbox" id="optLink" style="accent-color:rgba(255,255,255,.15)"> <?= e(t('opt_signup_link')) ?></label>
      </div>

      <!-- Platform tabs -->
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:20px">
        <button class="plat-btn active" onclick="switchPlat('fb',this)">Facebook</button>
        <button class="plat-btn" onclick="switchPlat('ig',this)">Instagram</button>
        <button class="plat-btn" onclick="switchPlat('tt',this)">TikTok</button>
      </div>

      <?php foreach(['fb'=>['Facebook',63206],'ig'=>['Instagram',2200],'tt'=>['TikTok',150]] as $pk=>[$pname,$plim]): ?>
      <div class="plat-panel <?= $pk==='fb'?'':'hidden' ?>" id="panel-<?= $pk ?>">
        <div style="background:rgba(29,83,129,.1);border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:12px 16px;font-size:13px;color:rgba(255,255,255,.7);margin-bottom:14px">
          <?php if($pk==='fb'): ?><?= e(t('fb_hint')) ?>
          <?php elseif($pk==='ig'): ?><?= e(t('ig_hint')) ?>
          <?php else: ?><?= e(t('tt_hint')) ?><?php endif; ?>
        </div>
        <div style="display:flex;gap:8px;margin-bottom:10px">
          <button class="lang-btn active" onclick="switchLang('<?= $pk ?>','ro',this)">Română</button>
          <button class="lang-btn" onclick="switchLang('<?= $pk ?>','da',this)">Daneză</button>
        </div>
        <div class="out-wrap"><textarea class="out-box" id="out-<?= $pk ?>-ro" readonly></textarea><div class="char-cnt" id="cnt-<?= $pk ?>-ro" data-limit="<?= $plim ?>"></div></div>
        <div class="out-wrap hidden"><textarea class="out-box" id="out-<?= $pk ?>-da" readonly></textarea><div class="char-cnt" id="cnt-<?= $pk ?>-da" data-limit="<?= $plim ?>"></div></div>
        <button class="copy-btn" onclick="doCopy('<?= $pk ?>')"><?= e(t('copy_for_prefix')) ?><?= $pname ?></button>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<style>
.tab-trigger{padding:10px 18px;font-size:14px;font-weight:600;color:rgba(255,255,255,.55);border:none;background:transparent;cursor:pointer;font-family:inherit;border-bottom:2px solid transparent;transition:color .15s,border-color .15s}
.tab-trigger:hover{color:rgba(255,255,255,.75)}
.tab-trigger.active{color:#fff;border-bottom-color:rgba(255,255,255,.4)}
.social-item{background:rgba(255,255,255,.03);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);border:1px solid rgba(255,255,255,.07);border-radius:14px;padding:14px 16px;cursor:pointer;transition:border-color .15s,background .15s;display:flex;align-items:center;gap:12px}
.social-item:hover,.social-item.selected{border-color:rgba(255,255,255,.2);background:rgba(29,83,129,.12)}
.plat-btn{padding:9px 18px;font-size:13px;font-weight:700;border:1.5px solid rgba(255,255,255,.12);border-radius:999px;background:rgba(255,255,255,.02);color:rgba(255,255,255,.65);cursor:pointer;font-family:inherit;transition:all .15s}
.plat-btn:hover{border-color:rgba(255,255,255,.35);color:#fff}
.plat-btn.active{border-color:rgba(255,255,255,.2);background:rgba(255,255,255,.09);color:#fff}
.plat-panel.hidden,.out-wrap.hidden{display:none}
.lang-btn{padding:6px 14px;font-size:12px;font-weight:700;border:1.5px solid rgba(255,255,255,.12);border-radius:999px;background:rgba(255,255,255,.02);color:rgba(255,255,255,.65);cursor:pointer;font-family:inherit;transition:all .15s}
.lang-btn.active{border-color:rgba(255,255,255,.2);color:#fff;background:rgba(255,255,255,.09)}
.out-wrap{margin-bottom:12px}
.out-box{width:100%;padding:14px;font-size:14px;font-family:'Nunito',sans-serif;background:rgba(255,255,255,.02);border:1.5px solid rgba(255,255,255,.12);border-radius:14px;color:#fff;resize:vertical;min-height:140px;line-height:1.65}
.char-cnt{font-size:11px;color:rgba(255,255,255,.55);text-align:right;margin-top:3px}
.char-cnt.warn{color:#e65100}.char-cnt.over{color:rgba(255,100,100,.8)}
.copy-btn{display:inline-flex;align-items:center;gap:7px;padding:10px 20px;font-size:13px;font-weight:700;border-radius:999px;background:rgba(255,255,255,.16);border:1.5px solid rgba(255,255,255,.18);color:#fff;cursor:pointer;font-family:inherit;transition:background .15s,box-shadow .15s}
.copy-btn:hover{background:#fff;color:#000;box-shadow:0 6px 20px rgba(255,255,255,.16)}
.copy-btn.ok{background:#2e7d32;border-color:rgba(120,200,120,.9);color:#fff}
</style>

<script>
var cur=null, platLangs={fb:'ro',ig:'ro',tt:'ro'}, curPlat='fb';
var I18N_CHARACTERS = <?= json_encode(t('characters_word')) ?>, I18N_COPIED = <?= json_encode(t('copied_label')) ?>;

function switchTab(t){
  document.getElementById('tab-events').style.display=t==='events'?'block':'none';
  document.getElementById('tab-projects').style.display=t==='projects'?'block':'none';
  document.getElementById('tabEvBtn').classList.toggle('active',t==='events');
  document.getElementById('tabPrBtn').classList.toggle('active',t==='projects');
}

function switchPlat(p,btn){
  curPlat=p;
  document.querySelectorAll('.plat-btn').forEach(function(b){b.classList.remove('active')});
  document.querySelectorAll('.plat-panel').forEach(function(x){x.classList.add('hidden')});
  btn.classList.add('active');
  document.getElementById('panel-'+p).classList.remove('hidden');
}

function switchLang(p,l,btn){
  platLangs[p]=l;
  var pan=document.getElementById('panel-'+p);
  pan.querySelectorAll('.lang-btn').forEach(function(b){b.classList.remove('active')});
  btn.classList.add('active');
  var ws=pan.querySelectorAll('.out-wrap');
  ws[0].classList.toggle('hidden',l!=='ro');
  ws[1].classList.toggle('hidden',l!=='da');
}

function selectItem(type,card){
  document.querySelectorAll('.social-item').forEach(function(c){c.classList.remove('selected');c.querySelector('input').checked=false});
  card.classList.add('selected');
  card.querySelector('input').checked=true;
  cur=card.dataset;
  document.getElementById('generator').style.display='block';
  document.getElementById('generator').scrollIntoView({behavior:'smooth',block:'start'});
  gen();
}

document.querySelectorAll('#optEmoji,#optHash,#optLink').forEach(function(i){i.addEventListener('change',function(){if(cur)gen()})});

function gen(){
  if(!cur)return;
  var d=cur,em=document.getElementById('optEmoji').checked,hsh=document.getElementById('optHash').checked,lnk=document.getElementById('optLink').checked,isEv=d.type==='event';
  var tc='#FrontDoorDK #ForeningenFrontDoor #RomâniDinDanemarca #RomænerIDanmark';
  var tcat={artistic:'#Kunst #KulturDK',cultural:'#Kultur #DanskRumænsk',social:'#Fællesskab #Integration'};
  var ds='',dsd='';
  if(isEv&&d.date){var dt=new Date(d.date+'T00:00:00');var mro=['ianuarie','februarie','martie','aprilie','mai','iunie','iulie','august','septembrie','octombrie','noiembrie','decembrie'];var mda=['januar','februar','marts','april','maj','juni','juli','august','september','oktober','november','december'];ds=dt.getDate()+' '+mro[dt.getMonth()]+' '+dt.getFullYear()+(d.time?' · '+d.time.substring(0,5):'');dsd=dt.getDate()+'. '+mda[dt.getMonth()]+' '+dt.getFullYear()+(d.time?' kl. '+d.time.substring(0,5):'')}
  var sro=lnk&&d.signup?'\n\n🔗 Înscrie-te: '+d.signup:'',sda=lnk&&d.signup?'\n\n🔗 Tilmeld dig: '+d.signup:'';
  var hro=tc+' '+(tcat[d.category]||''),hda=tc+' '+(tcat[d.category]||'');
  var o={fb:{ro:'',da:''},ig:{ro:'',da:''},tt:{ro:'',da:''}};
  if(isEv){
    o.fb.ro=(em?'📅 ':'')+d.titleRo.toUpperCase()+'\n\n'+(ds?(em?'🗓 ':'')+ds+'\n':'')+(d.location?(em?'📍 ':'')+d.location+'\n':'')+'\n'+d.descRo+sro+(hsh?'\n\n'+hro:'');
    o.fb.da=(em?'📅 ':'')+d.titleDa.toUpperCase()+'\n\n'+(dsd?(em?'🗓 ':'')+dsd+'\n':'')+(d.location?(em?'📍 ':'')+d.location+'\n':'')+'\n'+d.descDa+sda+(hsh?'\n\n'+hda:'');
    o.ig.ro=(em?'📅 ':'')+d.titleRo+'\n'+(ds?(em?'🗓 ':'')+ds+'\n':'')+(d.location?(em?'📍 ':'')+d.location+'\n':'')+'\n'+d.descRo.substring(0,180)+(lnk?'\n\n🔗 Link în bio':'')+(hsh?'\n\n.\n.\n.\n'+hro:'');
    o.ig.da=(em?'📅 ':'')+d.titleDa+'\n'+(dsd?(em?'🗓 ':'')+dsd+'\n':'')+(d.location?(em?'📍 ':'')+d.location+'\n':'')+'\n'+d.descDa.substring(0,180)+(lnk?'\n\n🔗 Link i bio':'')+(hsh?'\n\n.\n.\n.\n'+hda:'');
    o.tt.ro=(em?'👀 ':'')+d.titleRo+(ds?' — '+ds:'')+'!'+(d.location?' '+(em?'📍':'')+d.location:'')+(hsh?'\n#FrontDoorDK #EventDK #RomâniDanemarca':'');
    o.tt.da=(em?'👀 ':'')+d.titleDa+(dsd?' — '+dsd:'')+'!'+(d.location?' '+(em?'📍':'')+d.location:'')+(hsh?'\n#FrontDoorDK #EventDK #RumænereIDanmark':'');
  } else {
    o.fb.ro=(em?'🚀 ':'')+('PROIECT NOU: ')+d.titleRo.toUpperCase()+'\n\n'+d.descRo+(d.labelRo?'\n\n'+(em?'🏷 ':'')+d.labelRo:'')+sro+(hsh?'\n\n'+hro:'');
    o.fb.da=(em?'🚀 ':'')+('NYT PROJEKT: ')+d.titleDa.toUpperCase()+'\n\n'+d.descDa+(d.labelDa?'\n\n'+(em?'🏷 ':'')+d.labelDa:'')+sda+(hsh?'\n\n'+hda:'');
    o.ig.ro=(em?'✨ ':'')+d.titleRo+'\n\n'+d.descRo.substring(0,200)+(lnk?'\n\n🔗 Link în bio':'')+(hsh?'\n\n.\n.\n.\n'+hro:'');
    o.ig.da=(em?'✨ ':'')+d.titleDa+'\n\n'+d.descDa.substring(0,200)+(lnk?'\n\n🔗 Link i bio':'')+(hsh?'\n\n.\n.\n.\n'+hda:'');
    o.tt.ro=(em?'🚀 ':'')+('Proiect nou: ')+d.titleRo+'!'+(hsh?'\n#FrontDoorDK #ProiectNou #RomâniDanemarca':'');
    o.tt.da=(em?'🚀 ':'')+('Nyt projekt: ')+d.titleDa+'!'+(hsh?'\n#FrontDoorDK #NytProjekt #RumænereIDanmark':'');
  }
  ['fb','ig','tt'].forEach(function(p){['ro','da'].forEach(function(l){
    var el=document.getElementById('out-'+p+'-'+l),cnt=document.getElementById('cnt-'+p+'-'+l);
    el.value=o[p][l];
    var len=o[p][l].length,lim=parseInt(cnt.dataset.limit);
    cnt.textContent=len+' / '+lim+' '+I18N_CHARACTERS;
    cnt.className='char-cnt'+(len>lim?' over':len>lim*.85?' warn':'');
  })});
}

function doCopy(p){
  var l=platLangs[p],el=document.getElementById('out-'+p+'-'+l);
  navigator.clipboard.writeText(el.value).then(function(){
    var btn=document.querySelector('#panel-'+p+' .copy-btn'),orig=btn.innerHTML;
    btn.innerHTML=I18N_COPIED;btn.classList.add('ok');
    setTimeout(function(){btn.innerHTML=orig;btn.classList.remove('ok')},2000);
  });
}

// Preselect dacă vine din link
<?php if ($preselect_event || $preselect_project): ?>
window.addEventListener('DOMContentLoaded', function() {
  var sel = document.querySelector('.social-item.selected');
  if (sel) { cur = sel.dataset; gen(); }
  <?php if ($preselect_project): ?>switchTab('projects');<?php endif; ?>
});
<?php endif; ?>
</script>
<?php layout_foot(); ?>
