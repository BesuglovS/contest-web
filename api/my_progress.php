<?php
/**
 * API прогресса текущего пользователя по всем контестам
 * GET index.php?page=api&endpoint=my_progress
 * Возвращает JSON: { contests: { "<contest_id>": { solved, total } } }
 * Считаются только контесты, где у пользователя есть принятые посылки.
 */

// Защита от прямого доступа к файлу — только через роутер (index.php?page=api).
if (!defined('BASE_PATH')) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Access-Control-Allow-Origin: ' . CORS_ALLOWED_ORIGIN);
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-TOKEN');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config.php';
require_once BASE_PATH . '/includes/Database.php';
require_once BASE_PATH . '/includes/Auth.php';

header('Content-Type: application/json; charset=utf-8');

if (!Auth::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Требуется авторизация'], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = Auth::getUserId();
$db = Database::getInstance();

$stmt = $db->prepare("
    SELECT s.contest_id,
           COUNT(DISTINCT s.task_id) AS solved,
           (SELECT COUNT(*) FROM contest_tasks ct WHERE ct.contest_id = s.contest_id) AS total
    FROM submissions s
    WHERE s.user_id = ? AND s.status = 'accepted' AND s.contest_id IS NOT NULL
    GROUP BY s.contest_id
    ORDER BY s.contest_id
");
$stmt->execute([$userId]);
$rows = $stmt->fetchAll();

$contests = [];
foreach ($rows as $row) {
    $contests[(string)(int) $row['contest_id']] = [
        'solved' => (int) $row['solved'],
        'total'  => (int) $row['total'],
    ];
}

echo json_encode(['contests' => $contests], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
