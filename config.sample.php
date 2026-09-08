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

// ── Google Sheets (regnskab) ────────────────────────────────────
// From the service-account JSON key (Google Cloud Console → IAM & admin →
// Service accounts → your account → Keys → Add key → JSON). Paste
// client_email as-is, and private_key exactly as it appears in the JSON
// (keep the \n escape sequences — do not turn them into real line breaks).
// The spreadsheet itself must be shared with client_email as Editor (or
// Viewer, if the admin panel should only read regnskab, not write to it).
define('GOOGLE_SA_CLIENT_EMAIL', '');
define('GOOGLE_SA_PRIVATE_KEY', '');
define('GOOGLE_SHEETS_SPREADSHEET_ID', ''); // the id in the sheet's URL, /d/<this>/edit
