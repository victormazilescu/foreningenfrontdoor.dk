<?php
/* =============================================================
   Foreningen Front Door — config.sample.php

   Copy this file to config.php (same folder) and fill in the
   real values. config.php is listed in .gitignore and must
   NEVER be committed — it holds live database and email
   credentials.

   Recommended permissions on the server: chmod 600 config.php
   ============================================================= */

// ── Database (cPanel → MySQL Databases) ────────────────────────
define('DB_HOST', 'localhost');
define('DB_NAME', '');
define('DB_USER', '');
define('DB_PASS', '');

// ── SMTP (admin panel outgoing email) ──────────────────────────
define('SMTP_HOST',      'mail.foreningenfrontdoor.dk');
define('SMTP_PORT',      465);       // 465 for ssl, 587 for tls
define('SMTP_SECURE',    'ssl');
define('SMTP_USER',      'office@foreningenfrontdoor.dk');
define('SMTP_PASS',      '');
define('SMTP_FROM',      'office@foreningenfrontdoor.dk');
define('SMTP_FROM_NAME', 'Foreningen Front Door');
