<?php
require_once __DIR__ . '/auth.php';
$user = require_login();
$pdo  = get_db();

$events = $pdo->query("SELECT id,title_ro,title_da,description_ro,description_da,date,time,location,category,signup_url FROM events WHERE status='active' AND date>=CURDATE() ORDER BY date ASC LIMIT 20")->fetchAll();
$projects = $pdo->query("SELECT id,title_ro,title_da,description_ro,description_da,label_ro,label_da,category,signup_url FROM projects WHERE status IN('active','completed') ORDER BY sort_order ASC LIMIT 20")->fetchAll();

// Preselect din URL
$preselect_event   = isset($_GET['event'])   ? (int)$_GET['event']   : 0;
$preselect_project = isset($_GET['project']) ? (int)$_GET['project'] : 0;

$cat_ro = ['artistic'=>'Artistic','cultural'=>'Cultural','social'=>'Social'];

layout_head('Generator Social Media', 'events');
?>
<div class="content">
  <div class="page-head">
    <h1>Generator Social Media</h1>
    <p style="font-size:14px;color:rgba(255,255,255,.25);margin-top:4px">Selectează un eveniment sau proiect, alege platforma și copiază textul.</p>
  </div>

  <!-- Tabs Evenimente / Proiecte -->
  <div style="display:flex;gap:0;border-bottom:1px solid rgba(255,255,255,.07);margin-bottom:24px">
    <button id="tabEvBtn" class="tab-trigger active" onclick="switchTab('events')">📅 Evenimente</button>
    <button id="tabPrBtn" class="tab-trigger" onclick="switchTab('projects')">🗂 Proiecte</button>
  </div>

  <!-- Evenimente -->
  <div id="tab-events">
    <?php if(empty($events)): ?>
      <div class="empty">Niciun eveniment viitor activ.</div>
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
          <div style="font-size:14px;font-weight:700"><?= e($ev['title_ro']) ?> <span style="color:rgba(255,255,255,.25);font-weight:400;font-size:12px">/ <?= e($ev['title_da']) ?></span></div>
          <div style="font-size:12px;color:rgba(255,255,255,.25)"><span style="padding:1px 7px;background:rgba(255,255,255,.06);color:rgba(255,255,255,.6);font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;margin-right:6px"><?= e($cat_ro[$ev['category']]??$ev['category']) ?></span><?= e(date('d.m.Y',strtotime($ev['date']))) ?><?= $ev['time']?' · '.e(substr($ev['time'],0,5)):'' ?><?= $ev['location']?' · '.e($ev['location']):'' ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- Proiecte -->
  <div id="tab-projects" style="display:none">
    <?php if(empty($projects)): ?>
      <div class="empty">Niciun proiect activ.</div>
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
          <div style="font-size:14px;font-weight:700"><?= e($pr['title_ro']) ?> <span style="color:rgba(255,255,255,.25);font-weight:400;font-size:12px">/ <?= e($pr['title_da']) ?></span></div>
          <div style="font-size:12px;color:rgba(255,255,255,.25)"><span style="padding:1px 7px;background:rgba(255,255,255,.06);color:rgba(255,255,255,.6);font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;margin-right:6px"><?= e($cat_ro[$pr['category']]??$pr['category']) ?></span><?= e($pr['label_ro']??'') ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- Generator -->
  <div id="generator" style="display:<?= ($preselect_event||$preselect_project)?'block':'none' ?>">
    <div style="background:#0a0a0a;border:1px solid rgba(255,255,255,.07);padding:24px;position:relative">
      <div style="position:absolute;top:0;left:0;width:56px;height:4px;background:rgba(255,255,255,.15);transform:skewX(-45deg) translateX(-12px)"></div>
      <div style="font-size:11px;letter-spacing:.18em;text-transform:uppercase;color:rgba(255,255,255,.6);margin-bottom:20px">Conținut generat</div>

      <!-- Opțiuni -->
      <div style="display:flex;gap:18px;flex-wrap:wrap;margin-bottom:20px">
        <label style="display:flex;align-items:center;gap:7px;font-size:13px;color:rgba(255,255,255,.4);cursor:pointer"><input type="checkbox" id="optEmoji" checked style="accent-color:rgba(255,255,255,.15)"> Emoji</label>
        <label style="display:flex;align-items:center;gap:7px;font-size:13px;color:rgba(255,255,255,.4);cursor:pointer"><input type="checkbox" id="optHash" checked style="accent-color:rgba(255,255,255,.15)"> Hashtag-uri</label>
        <label style="display:flex;align-items:center;gap:7px;font-size:13px;color:rgba(255,255,255,.4);cursor:pointer"><input type="checkbox" id="optLink" style="accent-color:rgba(255,255,255,.15)"> Link înscriere</label>
      </div>

      <!-- Platform tabs -->
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:20px">
        <button class="plat-btn active" onclick="switchPlat('fb',this)">f Facebook</button>
        <button class="plat-btn" onclick="switchPlat('ig',this)">📷 Instagram</button>
        <button class="plat-btn" onclick="switchPlat('tt',this)">♪ TikTok</button>
      </div>

      <?php foreach(['fb'=>['Facebook',63206],'ig'=>['Instagram',2200],'tt'=>['TikTok',150]] as $pk=>[$pname,$plim]): ?>
      <div class="plat-panel <?= $pk==='fb'?'':'hidden' ?>" id="panel-<?= $pk ?>">
        <div style="background:rgba(29,83,129,.08);border:1px solid rgba(255,255,255,.06);padding:12px 16px;font-size:13px;color:rgba(255,255,255,.4);margin-bottom:14px">
          <?php if($pk==='fb'): ?>Text lung ok, link-uri funcționează. Imaginea din cover eveniment.
          <?php elseif($pk==='ig'): ?>Caption scurt vizibil (sub 150 car.), hashtag-uri la final, link în bio.
          <?php else: ?>Max 150 caractere vizibile. Hook în prima linie.<?php endif; ?>
        </div>
        <div style="display:flex;gap:8px;margin-bottom:10px">
          <button class="lang-btn active" onclick="switchLang('<?= $pk ?>','ro',this)">🇷🇴 Română</button>
          <button class="lang-btn" onclick="switchLang('<?= $pk ?>','da',this)">🇩🇰 Daneză</button>
        </div>
        <div class="out-wrap"><textarea class="out-box" id="out-<?= $pk ?>-ro" readonly></textarea><div class="char-cnt" id="cnt-<?= $pk ?>-ro" data-limit="<?= $plim ?>"></div></div>
        <div class="out-wrap hidden"><textarea class="out-box" id="out-<?= $pk ?>-da" readonly></textarea><div class="char-cnt" id="cnt-<?= $pk ?>-da" data-limit="<?= $plim ?>"></div></div>
        <button class="copy-btn" onclick="doCopy('<?= $pk ?>')">📋 Copiază pentru <?= $pname ?></button>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<style>
.tab-trigger{padding:10px 18px;font-size:14px;font-weight:600;color:rgba(255,255,255,.25);border:none;background:transparent;cursor:pointer;font-family:inherit;border-bottom:2px solid transparent;transition:color .15s,border-color .15s}
.tab-trigger:hover{color:rgba(255,255,255,.4)}
.tab-trigger.active{color:#fff;border-bottom-color:rgba(255,255,255,.15)}
.social-item{background:#0a0a0a;border:1px solid rgba(255,255,255,.05);padding:14px 16px;cursor:pointer;transition:border-color .15s,background .15s;display:flex;align-items:center;gap:12px}
.social-item:hover,.social-item.selected{border-color:rgba(255,255,255,.15);background:rgba(29,83,129,.08)}
.plat-btn{padding:8px 16px;font-size:13px;font-weight:700;border:1.5px solid rgba(255,255,255,.1);background:transparent;color:rgba(255,255,255,.4);cursor:pointer;font-family:inherit;transition:all .15s}
.plat-btn:hover{border-color:rgba(255,255,255,.3);color:#fff}
.plat-btn.active{border-color:rgba(255,255,255,.15);background:rgba(255,255,255,.06);color:#fff}
.plat-panel.hidden,.out-wrap.hidden{display:none}
.lang-btn{padding:5px 12px;font-size:12px;font-weight:700;border:1.5px solid rgba(255,255,255,.1);background:transparent;color:rgba(255,255,255,.4);cursor:pointer;font-family:inherit;transition:all .15s}
.lang-btn.active{border-color:rgba(255,255,255,.15);color:#fff;background:rgba(255,255,255,.06)}
.out-wrap{margin-bottom:12px}
.out-box{width:100%;padding:14px;font-size:14px;font-family:'Nunito',sans-serif;background:#000;border:1.5px solid rgba(255,255,255,.1);color:#fff;resize:vertical;min-height:140px;line-height:1.65}
.char-cnt{font-size:11px;color:rgba(255,255,255,.25);text-align:right;margin-top:3px}
.char-cnt.warn{color:#e65100}.char-cnt.over{color:rgba(255,100,100,.8)}
.copy-btn{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;font-size:13px;font-weight:700;background:rgba(255,255,255,.15);border:1.5px solid rgba(255,255,255,.15);color:#fff;cursor:pointer;font-family:inherit;transition:background .15s}
.copy-btn:hover{background:#000;border-color:#000}
.copy-btn.ok{background:#2e7d32;border-color:rgba(120,200,120,.9)}
</style>

<script>
var cur=null, platLangs={fb:'ro',ig:'ro',tt:'ro'}, curPlat='fb';

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
    cnt.textContent=len+' / '+lim+' caractere';
    cnt.className='char-cnt'+(len>lim?' over':len>lim*.85?' warn':'');
  })});
}

function doCopy(p){
  var l=platLangs[p],el=document.getElementById('out-'+p+'-'+l);
  navigator.clipboard.writeText(el.value).then(function(){
    var btn=document.querySelector('#panel-'+p+' .copy-btn'),orig=btn.innerHTML;
    btn.innerHTML='✓ Copiat!';btn.classList.add('ok');
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
