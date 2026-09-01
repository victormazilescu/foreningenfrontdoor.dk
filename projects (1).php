<?php
/* =============================================================
   Foreningen Front Door — api/projects.php
   Returnează proiectele publice ca JSON.

   Parametri GET:
     ?category=artistic|cultural|social
       → proiecte unde category=X SAU au un tag cu categories LIKE X
     ?status=active            (default: active,seeking_funding)
     ?all_statuses=1           → toate statusurile (admin only)
   ============================================================= */

define('DB_HOST', 'localhost');
define('DB_NAME', 'dzppntag_evenimente_dk');
define('DB_USER', 'dzppntag_eventmaster');
define('DB_PASS', 'asociatiaFrontDoor2026!');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$category = $_GET['category'] ?? null;
$all      = isset($_GET['all_statuses']);

$allowed_cats = ['artistic', 'cultural', 'social'];

if ($category && !in_array($category, $allowed_cats)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid category']);
    exit;
}

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

$params = [];
$where  = [];

// Status: implicit active + seeking_funding; all_statuses pentru admin
if (!$all) {
    $where[] = "p.status IN ('active', 'seeking_funding')";
}

// Filtrare după categorie SAU taguri asociate categoriei
if ($category) {
    // Un proiect apare dacă:
    // (a) categoria lui e cea cerută, SAU
    // (b) are cel puțin un tag cu FIND_IN_SET(category, tag.categories)
    $where[] = "(
        p.category = :category
        OR EXISTS (
            SELECT 1 FROM project_tags pt
            JOIN tags t ON t.id = pt.tag_id
            WHERE pt.project_id = p.id
              AND FIND_IN_SET(:category2, t.categories) > 0
        )
    )";
    $params[':category']  = $category;
    $params[':category2'] = $category;
}

$sql = 'SELECT p.id, p.title_ro, p.title_da, p.description_ro, p.description_da,
               p.label_ro, p.label_da, p.category, p.status,
               p.signup_url, p.sort_order
        FROM projects p'
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . ' ORDER BY p.sort_order ASC, p.id ASC';

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $projects = $stmt->fetchAll();
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Query failed']);
    exit;
}

// Taguri per proiect
if ($projects) {
    $ids          = array_column($projects, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $tagStmt      = $pdo->prepare(
        'SELECT pt.project_id, t.name, t.slug, t.color
         FROM project_tags pt
         JOIN tags t ON t.id = pt.tag_id
         WHERE pt.project_id IN (' . $placeholders . ')
         ORDER BY t.sort_order ASC'
    );
    $tagStmt->execute($ids);
    $tagMap = [];
    foreach ($tagStmt->fetchAll() as $tr) {
        $tagMap[$tr['project_id']][] = [
            'name'  => $tr['name'],
            'slug'  => $tr['slug'],
            'color' => $tr['color'],
        ];
    }
    foreach ($projects as &$pr) {
        $pr['id']       = (int)$pr['id'];
        $pr['tags']     = $tagMap[$pr['id']] ?? [];
    }
    unset($pr);
}

echo json_encode(['projects' => $projects], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
