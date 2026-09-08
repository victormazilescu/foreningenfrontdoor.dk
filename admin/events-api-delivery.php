<?php
/* =============================================================
   Foreningen Front Door — api/events.php
   Returnează evenimentele ca JSON către paginile HTML.

   Parametri GET opționali:
     ?category=artistic|cultural|social   filtrează după categorie
     ?status=active|suspended|cancelled   implicit: active
     ?upcoming=1                          doar evenimente viitoare (implicit 1)

   Configurare: editează blocul DB_* de mai jos cu datele
   din cPanel → MySQL Databases.
   ============================================================= */

// ── CONFIGURARE BAZĂ DE DATE ──────────────────────────────────
// DB_HOST / DB_NAME / DB_USER / DB_PASS live in /config.php (gitignored).
require_once __DIR__ . '/../config.php';
// ─────────────────────────────────────────────────────────────

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// Parametri
$category = isset($_GET['category']) ? $_GET['category'] : null;
$status   = isset($_GET['status'])   ? $_GET['status']   : 'active';
$upcoming = isset($_GET['upcoming'])  ? (bool)$_GET['upcoming'] : true;

$allowed_cats    = ['artistic', 'cultural', 'social'];
$allowed_status  = ['active', 'suspended', 'cancelled'];

if ($category && !in_array($category, $allowed_cats)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid category']);
    exit;
}
if (!in_array($status, $allowed_status)) {
    $status = 'active';
}

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// Migrări idempotente — acest fișier nu include auth.php, așa că
// verificăm/adăugăm coloanele direct aici, ca site-ul public să nu pice
// dacă panoul de admin nu a rulat încă aceste ALTER-uri.
try { $pdo->exec("ALTER TABLE events ADD COLUMN title_en VARCHAR(255) NULL"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE events ADD COLUMN description_en TEXT NULL"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE tags ADD COLUMN name_ro VARCHAR(60) NULL AFTER name"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE tags ADD COLUMN name_da VARCHAR(60) NULL AFTER name_ro"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE tags ADD COLUMN name_en VARCHAR(60) NULL AFTER name_da"); } catch (PDOException $e) {}
try { $pdo->exec("UPDATE tags SET name_ro=COALESCE(name_ro,name), name_da=COALESCE(name_da,name), name_en=COALESCE(name_en,name) WHERE name_ro IS NULL OR name_da IS NULL OR name_en IS NULL"); } catch (PDOException $e) {}

$where  = ['status = :status'];
$params = [':status' => $status];

if ($category) {
    $where[]            = 'category = :category';
    $params[':category'] = $category;
}
if ($upcoming) {
    // Un eveniment fără dată stabilită încă ("TBD") e tot un eveniment
    // viitor — nu trebuie exclus doar pentru că data e necunoscută.
    $where[]          = '(date IS NULL OR date >= CURDATE())';
}

$sql = 'SELECT id, title_ro, title_da, title_en, description_ro, description_da, description_en,
               category, status, date, time, location,
               recurring, recurring_rule, cover_image, signup_url
        FROM events
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY (date IS NULL) ASC, date ASC, time ASC';

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $events = $stmt->fetchAll();
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Query failed']);
    exit;
}

// Taguri per eveniment
if ($events) {
    $ids = array_column($events, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $tagStmt = $pdo->prepare(
        'SELECT et.event_id, t.name, t.name_ro, t.name_da, t.name_en, t.slug, t.color
         FROM event_tags et
         JOIN tags t ON t.id = et.tag_id
         WHERE et.event_id IN (' . $placeholders . ')
         ORDER BY t.sort_order ASC'
    );
    $tagStmt->execute($ids);
    $tagRows = $tagStmt->fetchAll();
    $tagMap = [];
    foreach ($tagRows as $tr) {
        $tagMap[$tr['event_id']][] = [
            'name'    => $tr['name'],
            'name_ro' => $tr['name_ro'] ?: $tr['name'],
            'name_da' => $tr['name_da'] ?: $tr['name'],
            'name_en' => $tr['name_en'] ?: $tr['name'],
            'slug'    => $tr['slug'],
            'color'   => $tr['color'],
        ];
    }
    foreach ($events as &$ev) {
        $ev['id']        = (int)$ev['id'];
        $ev['recurring'] = (bool)$ev['recurring'];
        $ev['cover_image'] = $ev['cover_image'] ? '/' . ltrim($ev['cover_image'], '/') : null;
        $ev['tags'] = $tagMap[$ev['id']] ?? [];
    }
    unset($ev);
}

echo json_encode(['events' => $events], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
