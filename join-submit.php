<?php
/* =============================================================
   Foreningen Front Door — join-submit.php
   ============================================================= */

// Suppress all errors — never leak PHP warnings into JSON
error_reporting(0);
ini_set('display_errors', '0');

// Always return JSON
header('Content-Type: application/json; charset=utf-8');

define('DB_HOST', 'localhost');
define('DB_NAME', 'dzppntag_evenimente_dk');
define('DB_USER', 'dzppntag_eventmaster');
define('DB_PASS', 'asociatiaFrontDoor2026!');
define('NOTIFY_EMAIL', 'office@foreningenfrontdoor.dk');
define('FROM_EMAIL',   'office@foreningenfrontdoor.dk');

// POST only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'msg' => 'Method not allowed']);
    exit;
}

function clean(string $s): string {
    return htmlspecialchars(trim($s), ENT_QUOTES, 'UTF-8');
}

$name    = clean($_POST['name']    ?? '');
$email   = trim($_POST['email']    ?? '');
$phone   = clean($_POST['phone']   ?? '');
$city    = clean($_POST['city']    ?? '');
$source  = clean($_POST['source']  ?? '');
$message = clean($_POST['message'] ?? '');
$consent = !empty($_POST['consent']);
$lang    = ($_POST['lang'] ?? 'da') === 'ro' ? 'ro' : 'da';

// Validare
$errors = [];
if (strlen($name) < 2)                          $errors[] = 'Navn er påkrævet / Numele e obligatoriu.';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Ugyldig e-mail / Email invalid.';
if (strlen($city) < 2)                          $errors[] = 'By er påkrævet / Orașul e obligatoriu.';
if (!$consent)                                  $errors[] = 'Samtykke er påkrævet / Consimțământul e obligatoriu.';

if ($errors) {
    echo json_encode(['ok' => false, 'msg' => implode(' ', $errors)]);
    exit;
}

// Salvare în DB
try {
    $pdo = new PDO(
        'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $pdo->prepare(
        'INSERT INTO membership_requests
         (name, email, phone, city, source, message, status, consented_at)
         VALUES (?, ?, ?, ?, ?, ?, "new", NOW())'
    )->execute([$name, $email, $phone ?: null, $city, $source ?: null, $message ?: null]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => 'Database error. Please try again.']);
    exit;
}

// Email notificare intern — eșecul nu afectează răspunsul
try {
    $subj = '=?UTF-8?B?' . base64_encode('Ny membership-ansøgning — ' . $name) . '?=';
    $body = "Ny ansøgning modtaget\n\nNavn: $name\nE-mail: $email\n"
          . ($phone   ? "Telefon: $phone\n" : '')
          . "By: $city\n"
          . ($source  ? "Kilde: $source\n" : '')
          . ($message ? "\nBesked:\n$message\n" : '')
          . "\nhttps://foreningenfrontdoor.dk/admin/members.php\n"
          . date('d.m.Y H:i') . "\n";
    $hdrs = "From: Front Door <".FROM_EMAIL.">\r\nReply-To: $email\r\nContent-Type: text/plain; charset=UTF-8\r\n";
    @mail(NOTIFY_EMAIL, $subj, $body, $hdrs);
} catch (Throwable $e) {}

// Email confirmare solicitant — eșecul nu afectează răspunsul
try {
    $csubj = '=?UTF-8?B?' . base64_encode($lang === 'ro'
        ? 'Cerere de membership — Front Door'
        : 'Ansøgning om medlemskab — Front Door') . '?=';
    $cbody = $lang === 'ro'
        ? "Bună ziua, $name,\n\nAm primit cererea ta de membership la Front Door.\n\nTe vom contacta în termen de 5 zile lucrătoare. Plata cotizației (260 DKK/an) se stabilește personal cu consiliul după aprobarea cererii.\n\nÎntrebări? Scrie-ne la office@foreningenfrontdoor.dk\n\nCu drag,\nFront Door\nforeningenfrontdoor.dk"
        : "Hej $name,\n\nVi har modtaget din ansøgning om medlemskab i Front Door.\n\nVi kontakter dig inden for 5 hverdage. Betaling af kontingent (260 DKK/år) aftales personligt med bestyrelsen efter godkendelse.\n\nSpørgsmål? Skriv til os på office@foreningenfrontdoor.dk\n\nMed venlig hilsen,\nFront Door\nforeningenfrontdoor.dk";
    $chdrs = "From: Front Door <".FROM_EMAIL.">\r\nContent-Type: text/plain; charset=UTF-8\r\n";
    @mail($email, $csubj, $cbody, $chdrs);
} catch (Throwable $e) {}

echo json_encode(['ok' => true]);
