<?php
/**
 * API для проверки прогресса пользователя по контесту
 * GET ?contest_id=N
 * Возвращает JSON: { completed, solved, total, tasks }
 */

header('Access-Control-Allow-Origin: https://python.nayanovaacademy.ru');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

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
    echo json_encode(['error' => 'Требуется авторизация']);
    exit;
}

$contestId = isset($_GET['contest_id']) ? (int) $_GET['contest_id'] : 0;
if (!$contestId) {
    http_response_code(400);
    echo json_encode(['error' => 'Не указан contest_id']);
    exit;
}

$userId = Auth::getUserId();
$db = Database::getInstance();

$stmt = $db->prepare("
    SELECT t.id, t.title
    FROM contest_tasks ct
    JOIN tasks t ON t.id = ct.task_id
    WHERE ct.contest_id = ?
    ORDER BY ct.sort_order
");
$stmt->execute([$contestId]);
$tasks = $stmt->fetchAll();

if (empty($tasks)) {
    echo json_encode(['completed' => false, 'solved' => 0, 'total' => 0, 'tasks' => []]);
    exit;
}

$taskStatuses = [];
$solvedCount = 0;

foreach ($tasks as $task) {
    $stmtCheck = $db->prepare("
        SELECT 1 FROM submissions
        WHERE user_id = ? AND task_id = ? AND contest_id = ? AND status = 'accepted'
        LIMIT 1
    ");
    $stmtCheck->execute([$userId, $task['id'], $contestId]);
    $isSolved = (bool) $stmtCheck->fetch();

    $taskStatuses[] = [
        'task_id' => (int) $task['id'],
        'title' => $task['title'],
        'solved' => $isSolved,
    ];

    if ($isSolved) $solvedCount++;
}

$total = count($tasks);
echo json_encode([
    'completed' => $solvedCount === $total,
    'solved' => $solvedCount,
    'total' => $total,
    'tasks' => $taskStatuses,
]);
