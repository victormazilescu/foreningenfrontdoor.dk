<?php
/* =============================================================
   api/transparency.php — date publice pentru pagina Transparență
   GET ?section=documents|projects|all (default: all)
   ============================================================= */

define('DB_HOST', 'localhost');
define('DB_NAME', 'dzppntag_evenimente_dk');
define('DB_USER', 'dzppntag_eventmaster');
define('DB_PASS', 'asociatiaFrontDoor2026!');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$section = $_GET['section'] ?? 'all';

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

$result = [];

// ── DOCUMENTE ────────────────────────────────────────────────
if (in_array($section, ['all', 'documents'])) {
    $stmt = $pdo->query('
        SELECT id, title_ro, title_da, doc_type, meeting_date, file_path, file_size
        FROM documents
        WHERE is_public = 1
        ORDER BY meeting_date DESC, sort_order ASC, id DESC
    ');
    $docs = $stmt->fetchAll();
    foreach ($docs as &$d) {
        $d['id']        = (int)$d['id'];
        $d['file_size'] = $d['file_size'] ? (int)$d['file_size'] : null;
        // URL public
        $d['file_url']  = '/' . ltrim($d['file_path'], '/');
    }
    unset($d);
    $result['documents'] = $docs;
}

// ── PROIECTE CU DETALII ───────────────────────────────────────
if (in_array($section, ['all', 'projects'])) {
    $stmt = $pdo->query("
        SELECT p.id, p.title_ro, p.title_da, p.description_ro, p.description_da,
               p.label_ro, p.label_da, p.category, p.status, p.sort_order,
               pd.headline_ro, pd.headline_da,
               pd.story_ro, pd.story_da,
               pd.budget_needed, pd.budget_raised,
               pd.budget_breakdown_ro, pd.budget_breakdown_da,
               pd.photo_1, pd.photo_2, pd.photo_3, pd.photo_4
        FROM projects p
        LEFT JOIN project_details pd ON pd.project_id = p.id
        WHERE p.status IN ('active', 'seeking_funding', 'completed')
        ORDER BY
          FIELD(p.status, 'seeking_funding', 'active', 'completed'),
          p.sort_order ASC, p.id ASC
    ");
    $projects = $stmt->fetchAll();

    // Taguri per proiect
    if ($projects) {
        $ids          = array_column($projects, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $tagStmt      = $pdo->prepare("
            SELECT pt.project_id, t.name, t.slug, t.color
            FROM project_tags pt
            JOIN tags t ON t.id = pt.tag_id
            WHERE pt.project_id IN ($placeholders)
            ORDER BY t.sort_order ASC
        ");
        $tagStmt->execute($ids);
        $tagMap = [];
        foreach ($tagStmt->fetchAll() as $tr) {
            $tagMap[$tr['project_id']][] = ['name' => $tr['name'], 'slug' => $tr['slug'], 'color' => $tr['color']];
        }
        foreach ($projects as &$pr) {
            $pr['id']             = (int)$pr['id'];
            $pr['budget_needed']  = $pr['budget_needed']  !== null ? (float)$pr['budget_needed']  : null;
            $pr['budget_raised']  = $pr['budget_raised']  !== null ? (float)$pr['budget_raised']  : null;
            $pr['tags']           = $tagMap[$pr['id']] ?? [];
            // Curăță foto null
            $pr['photos'] = array_values(array_filter([
                $pr['photo_1'] ? '/' . ltrim($pr['photo_1'], '/') : null,
                $pr['photo_2'] ? '/' . ltrim($pr['photo_2'], '/') : null,
                $pr['photo_3'] ? '/' . ltrim($pr['photo_3'], '/') : null,
                $pr['photo_4'] ? '/' . ltrim($pr['photo_4'], '/') : null,
            ]));
            unset($pr['photo_1'], $pr['photo_2'], $pr['photo_3'], $pr['photo_4']);
        }
        unset($pr);
    }
    $result['projects'] = $projects;
}

// Donatori per proiect
if (!empty($result['projects'])) {
    $proj_ids = array_column($result['projects'], 'id');
    $ph = implode(',', array_fill(0, count($proj_ids), '?'));
    $dStmt = $pdo->prepare("
        SELECT id, project_id, name, donor_type, method, details, value_dkk
        FROM project_donors
        WHERE project_id IN ($ph)
        ORDER BY project_id ASC, sort_order ASC, id ASC
    ");
    $dStmt->execute($proj_ids);
    $donorMap = [];
    foreach ($dStmt->fetchAll() as $d) {
        $donorMap[$d['project_id']][] = [
            'name'       => $d['name'],
            'donor_type' => $d['donor_type'],
            'method'     => $d['method'],
            'details'    => $d['details'],
            'value_dkk'  => $d['value_dkk'] !== null ? (float)$d['value_dkk'] : null,
        ];
    }
    foreach ($result['projects'] as &$pr) {
        $pr['donors'] = $donorMap[$pr['id']] ?? [];
    }
    unset($pr);
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
