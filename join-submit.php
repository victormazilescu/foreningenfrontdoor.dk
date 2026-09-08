<?php
ini_set('display_errors', '1');
error_reporting(E_ALL);
/* =============================================================
   Foreningen Front Door — join-submit.php
   ============================================================= */

// Suppress all errors — never leak PHP warnings into JSON
// error_reporting(0);
// ini_set('display_errors', '0');

// Always return JSON
header('Content-Type: application/json; charset=utf-8');

// DB_HOST / DB_NAME / DB_USER / DB_PASS live in /config.php (gitignored).
require_once __DIR__ . '/config.php';
define('NOTIFY_EMAIL', 'office@foreningenfrontdoor.dk');
define('FROM_EMAIL',   'office@foreningenfrontdoor.dk');

// Sursa de adevăr pentru cod poștal → oraș (Danemarca). Niciodată nu avem
// încredere în orașul trimis de client — orașul salvat vine mereu din
// acest tabel, pe baza codului poștal validat mai jos.
$DK_POSTAL_CODES = require __DIR__ . '/dk-postal-codes.php';

// POST only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'msg' => 'Method not allowed']);
    exit;
}

function clean(string $s): string {
    return htmlspecialchars(trim($s), ENT_QUOTES, 'UTF-8');
}

$name       = clean($_POST['name']       ?? '');
$email      = trim($_POST['email']       ?? '');
$phone      = clean($_POST['phone']      ?? '');
$source     = clean($_POST['source']     ?? '');
$message    = clean($_POST['message']    ?? '');
$consent    = !empty($_POST['consent']);
$lang_in    = $_POST['lang'] ?? 'da';
$lang       = in_array($lang_in, ['ro', 'en'], true) ? $lang_in : 'da';

$birth_date = trim($_POST['birth_date']  ?? '');
$gender     = trim($_POST['gender']      ?? '');
$address    = clean($_POST['address']    ?? '');
$postal_raw = preg_replace('/\D/', '', $_POST['postal_code'] ?? '');

// Validare — mesajele rămân trilingve (DA/RO/EN) indiferent de $lang,
// la fel ca înainte de adăugarea englezei — clientul alege ce arată.
$errors = [];
if (strlen($name) < 2)                          $errors[] = 'Navn er påkrævet / Numele e obligatoriu / Name is required.';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Ugyldig e-mail / Email invalid / Invalid email.';
if (!$consent)                                  $errors[] = 'Samtykke er påkrævet / Consimțământul e obligatoriu / Consent is required.';

// Dată naștere: format YYYY-MM-DD (input type=date), în trecut, vârstă rezonabilă.
$birth_date_ok = false;
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $birth_date)) {
    $ts = strtotime($birth_date);
    if ($ts !== false && $ts < time()) $birth_date_ok = true;
}
if (!$birth_date_ok) $errors[] = 'Ugyldig fødselsdato / Data nașterii este invalidă / Invalid date of birth.';

// Sex — doar valorile cunoscute (vezi GENDERS din admin/auth.php).
$genders_allowed = ['f', 'm', 'altul'];
if (!in_array($gender, $genders_allowed, true)) $errors[] = 'Køn er påkrævet / Sexul este obligatoriu / Gender is required.';

if (strlen($address) < 3) $errors[] = 'Adresse er påkrævet / Adresa e obligatorie / Address is required.';

// Cod poștal — NICIODATĂ nu avem încredere în orașul trimis de client.
// Orașul e derivat exclusiv din tabelul server-side dk-postal-codes.php.
$city = null;
if (strlen($postal_raw) !== 4 || !isset($DK_POSTAL_CODES[$postal_raw])) {
    $errors[] = 'Ugyldigt dansk postnummer / Cod poștal danez invalid / Invalid Danish postal code.';
} else {
    $city = $DK_POSTAL_CODES[$postal_raw];
}

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
    // Migrare "lazy", ca peste tot în panoul admin — coloana poate lipsi pe
    // o instalare mai veche, iar formularul public nu trece prin auth.php.
    try { $pdo->exec("ALTER TABLE membership_requests ADD COLUMN lang VARCHAR(2) NOT NULL DEFAULT 'da'"); } catch (PDOException $e) {}
    $pdo->prepare(
        'INSERT INTO membership_requests
         (name, email, phone, city, source, message, status, consented_at,
          birth_date, gender, address, postal_code, lang)
         VALUES (?, ?, ?, ?, ?, ?, "new", NOW(), ?, ?, ?, ?, ?)'
    )->execute([
        $name, $email, $phone ?: null, $city, $source ?: null, $message ?: null,
        $birth_date, $gender, $address, $postal_raw, $lang,
    ]);
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
          . "Fødselsdato: $birth_date\n"
          . "Køn: $gender\n"
          . "Adresse: $address\n"
          . "Postnummer: $postal_raw ($city)\n"
          . ($source  ? "Kilde: $source\n" : '')
          . ($message ? "\nBesked:\n$message\n" : '')
          . "\nhttps://foreningenfrontdoor.dk/admin/members.php\n"
          . date('d.m.Y H:i') . "\n";
    $hdrs = "From: Front Door <".FROM_EMAIL.">\r\nReply-To: $email\r\nContent-Type: text/plain; charset=UTF-8\r\n";
    @mail(NOTIFY_EMAIL, $subj, $body, $hdrs);
} catch (Throwable $e) {}

// Email confirmare solicitant — eșecul nu afectează răspunsul
try {
    $csubjects = [
        'ro' => 'Cerere de membership — Front Door',
        'da' => 'Ansøgning om medlemskab — Front Door',
        'en' => 'Membership application — Front Door',
    ];
    $cbodies = [
        'ro' => "Bună ziua, $name,\n\nAm primit cererea ta de membership la Front Door.\n\nTe vom contacta în termen de 5 zile lucrătoare. Plata cotizației (260 DKK/an) se stabilește personal cu consiliul după aprobarea cererii.\n\nAm salvat și datele suplimentare din formular (dată naștere, sex, adresă) — ne sunt necesare pentru evidența oficială a membrilor, pe care comuna o cere asociațiilor conform legii daneze a educației populare (folkeoplysningsloven). Le folosim exclusiv în acest scop; detalii în politica noastră de date: https://foreningenfrontdoor.dk/datapolitik.html\n\nÎntrebări? Scrie-ne la office@foreningenfrontdoor.dk\n\nCu drag,\nFront Door\nforeningenfrontdoor.dk",
        'da' => "Hej $name,\n\nVi har modtaget din ansøgning om medlemskab i Front Door.\n\nVi kontakter dig inden for 5 hverdage. Betaling af kontingent (260 DKK/år) aftales personligt med bestyrelsen efter godkendelse.\n\nVi har også gemt de øvrige oplysninger fra formularen (fødselsdato, køn, adresse) — de er nødvendige til den officielle medlemsregistrering, som kommunen kræver af foreninger efter folkeoplysningsloven. Vi bruger dem udelukkende til dette formål; læs mere i vores datapolitik: https://foreningenfrontdoor.dk/datapolitik.html\n\nSpørgsmål? Skriv til os på office@foreningenfrontdoor.dk\n\nMed venlig hilsen,\nFront Door\nforeningenfrontdoor.dk",
        'en' => "Dear $name,\n\nWe've received your membership application for Front Door.\n\nWe'll get back to you within 5 business days. Payment of the fee (260 DKK/year) is arranged personally with the board after approval.\n\nWe've also saved the additional information from the form (date of birth, gender, address) — it's required for the official member registry, which the kommune requires of associations under the Danish Popular Education Act (folkeoplysningsloven). We use it exclusively for that purpose; read more in our privacy policy: https://foreningenfrontdoor.dk/datapolitik.html\n\nQuestions? Write to us at office@foreningenfrontdoor.dk\n\nBest regards,\nFront Door\nforeningenfrontdoor.dk",
    ];
    $csubj = '=?UTF-8?B?' . base64_encode($csubjects[$lang] ?? $csubjects['da']) . '?=';
    $cbody = $cbodies[$lang] ?? $cbodies['da'];
    $chdrs = "From: Front Door <".FROM_EMAIL.">\r\nContent-Type: text/plain; charset=UTF-8\r\n";
    @mail($email, $csubj, $cbody, $chdrs);
} catch (Throwable $e) {}

echo json_encode(['ok' => true]);
