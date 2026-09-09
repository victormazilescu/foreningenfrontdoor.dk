<?php
/* =============================================================
   Front Door DK — admin/lib/google-sheets.php

   Minimal Google Sheets API v4 client using a service-account JWT
   (RS256, signed with openssl — no Composer / google/apiclient
   dependency, so this runs on plain shared hosting).

   Credentials come from config.php:
     GOOGLE_SA_CLIENT_EMAIL
     GOOGLE_SA_PRIVATE_KEY
     GOOGLE_SHEETS_SPREADSHEET_ID

   Scope requested is read-only (spreadsheets.readonly) since v1 of
   regnskab is a read-only dashboard — the actual ledger stays edited
   directly in Google Sheets by the treasurer. Bump the scope in
   gs_access_token() if a future round needs write access.
   ============================================================= */

class GoogleSheetsException extends Exception {}

/**
 * Builds and signs a JWT assertion for the service account, then
 * exchanges it for a short-lived OAuth2 access token. Cached for the
 * lifetime of the request (tokens are valid 1h, we only need one).
 */
function gs_access_token(): string {
    static $token = null;
    if ($token !== null) return $token;

    if (!defined('GOOGLE_SA_CLIENT_EMAIL') || !defined('GOOGLE_SA_PRIVATE_KEY') || GOOGLE_SA_CLIENT_EMAIL === '' || GOOGLE_SA_PRIVATE_KEY === '') {
        throw new GoogleSheetsException('Google Sheets nu e configurat (lipsesc credențialele din config.php).');
    }

    $now = time();
    $header  = ['alg' => 'RS256', 'typ' => 'JWT'];
    $claims  = [
        'iss'   => GOOGLE_SA_CLIENT_EMAIL,
        'scope' => 'https://www.googleapis.com/auth/spreadsheets.readonly',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
    ];

    $b64 = fn(string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    $segments = $b64(json_encode($header)) . '.' . $b64(json_encode($claims));

    $privateKey = openssl_pkey_get_private(GOOGLE_SA_PRIVATE_KEY);
    if ($privateKey === false) {
        throw new GoogleSheetsException('Cheia privată Google din config.php nu a putut fi citită.');
    }
    $signature = '';
    $ok = openssl_sign($segments, $signature, $privateKey, OPENSSL_ALGO_SHA256);
    if (!$ok) {
        throw new GoogleSheetsException('Semnarea JWT-ului pentru Google a eșuat.');
    }
    $jwt = $segments . '.' . $b64($signature);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]),
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        throw new GoogleSheetsException('Nu s-a putut contacta Google (token): ' . $err);
    }
    $data = json_decode($resp, true);
    if ($code !== 200 || empty($data['access_token'])) {
        throw new GoogleSheetsException('Google a refuzat autentificarea (cod ' . $code . '): ' . ($data['error_description'] ?? $resp));
    }

    $token = $data['access_token'];
    return $token;
}

/**
 * Low-level GET against the Sheets API for the configured spreadsheet.
 * $path is appended after /v4/spreadsheets/{id}, e.g.
 * '/values/Bilag!A2:I' or '/values:batchGet?ranges=...'.
 */
function gs_get(string $path): array {
    if (!defined('GOOGLE_SHEETS_SPREADSHEET_ID') || GOOGLE_SHEETS_SPREADSHEET_ID === '') {
        throw new GoogleSheetsException('Nu e configurat ID-ul foii de calcul (GOOGLE_SHEETS_SPREADSHEET_ID).');
    }
    $token = gs_access_token();
    $url = 'https://sheets.googleapis.com/v4/spreadsheets/' . GOOGLE_SHEETS_SPREADSHEET_ID . $path;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        throw new GoogleSheetsException('Nu s-a putut contacta Google Sheets: ' . $err);
    }
    $data = json_decode($resp, true);
    if ($code !== 200) {
        throw new GoogleSheetsException('Google Sheets a răspuns cu eroare (cod ' . $code . '): ' . ($data['error']['message'] ?? $resp));
    }
    return $data;
}

/**
 * Fetches a single A1-notation range and returns it as a plain array
 * of rows (each row an array of cell strings), never null. Ragged
 * rows from Sheets (trailing empty cells dropped) are NOT padded here
 * — callers should use $row[$i] ?? '' when reading by column index.
 */
function gs_range(string $a1Range): array {
    $data = gs_get('/values/' . rawurlencode($a1Range));
    return $data['values'] ?? [];
}

/**
 * Fetches several ranges in one round-trip. Returns [rangeName => rows].
 */
function gs_ranges(array $a1Ranges): array {
    $qs = implode('&', array_map(fn($r) => 'ranges=' . rawurlencode($r), $a1Ranges));
    $data = gs_get('/values:batchGet?' . $qs);
    $out = [];
    foreach (($data['valueRanges'] ?? []) as $i => $vr) {
        $out[$a1Ranges[$i]] = $vr['values'] ?? [];
    }
    return $out;
}
