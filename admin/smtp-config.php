<?php
/* =============================================================
   Foreningen Front Door — admin/smtp-config.php
   Configurare SMTP pentru trimiterea emailurilor din admin panel.
   
   IMPORTANT: Acest fișier conține credențiale sensibile.
   - Nu îl urca niciodată pe GitHub sau alt sistem public.
   - Permisiuni recomandate: chmod 600
   ============================================================= */

define('SMTP_HOST',     'mail.foreningenfrontdoor.dk');
define('SMTP_PORT',     465);
define('SMTP_SECURE',   'ssl');   // 'ssl' pentru port 465, 'tls' pentru 587
define('SMTP_USER',     'office@foreningenfrontdoor.dk');
define('SMTP_PASS',     'prolaemailofficefrontDoor2027!');  // ← înlocuiește cu parola contului office@
define('SMTP_FROM',     'office@foreningenfrontdoor.dk');
define('SMTP_FROM_NAME','Foreningen Front Door');
