<?php
require_once __DIR__ . '/auth.php';
$user = require_perm('regnskab', 'view');

layout_head('Regnskab', 'regnskab');
?>
<div class="content">
  <div class="page-head">
    <h1>Regnskab</h1>
  </div>
  <div class="empty">
    Această secțiune există deocamdată doar ca loc rezervat — conținutul (contabilitate,
    posibil conectat cu Google Sheets sau alt sistem) urmează să fie decis într-o iterație viitoare.
  </div>
</div>
<?php layout_foot(); ?>
