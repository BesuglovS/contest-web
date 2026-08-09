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
$userGroupIds = Auth::getUserGroupIds($userId);
$groupPlaceholders = Auth::groupPlaceholders($userGroupIds);

// Проверка доступа к контесту
$stmtAccess = $db->prepare("
    SELECT 1 FROM contest_access ca
    WHERE ca.contest_id = ? AND (ca.user_id = ? OR ca.group_id IN ($groupPlaceholders))
    LIMIT 1
");
$stmtAccess->execute(array_merge([$contestId, $userId], $userGroupIds));
if (!$stmtAccess->fetch()) {
    http_response_code(403);
    echo json_encode(['error' => 'Нет доступа к контесту']);
    exit;
}

$stmt = $db->prepare("
    SELECT t.id, t.title,
           MAX(CASE WHEN s.status = 'accepted' THEN 1 ELSE 0 END) AS solved
    FROM contest_tasks ct
    JOIN tasks t ON t.id = ct.task_id
    LEFT JOIN submissions s
           ON s.task_id = t.id AND s.contest_id = ct.contest_id AND s.user_id = ?
    WHERE ct.contest_id = ?
    GROUP BY t.id, t.title
    ORDER BY ct.sort_order
");
$stmt->execute([$userId, $contestId]);
$tasks = $stmt->fetchAll();

if (empty($tasks)) {
    echo json_encode(['completed' => true, 'solved' => 0, 'total' => 0, 'tasks' => []]);
    exit;
}

$taskStatuses = [];
$solvedCount = 0;

foreach ($tasks as $task) {
    $isSolved = (bool) $task['solved'];

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
