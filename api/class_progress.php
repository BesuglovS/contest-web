<?php
/**
 * API: сводка прогресса класса (группы auth-web) по контестам.
 * Используется j-web (электронный журнал) для страницы учёта успеваемости.
 *
 * GET ?group_id=N
 * Ответ: { students: [ { id, name, contests: { "<contest_id>": {solved, total} } } ] }
 *
 * Аутентификация: сессия auth-web (кука auth_session, может быть проксирована
 * вызовом с другого поддомена) + права администратора.
 */
if (!defined('BASE_PATH')) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once BASE_PATH . '/includes/Database.php';
require_once BASE_PATH . '/includes/Auth.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if (!Auth::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Не авторизован'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!Auth::isAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Доступ только для администратора'], JSON_UNESCAPED_UNICODE);
    exit;
}

$groupId = isset($_GET['group_id']) ? (int) $_GET['group_id'] : 0;
if ($groupId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Не указан group_id'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Участники группы из auth-web
$memberships = AuthClient::getMemberships() ?? [];
$userIds = [];
foreach ($memberships as $m) {
    if ((int) $m['group_id'] === $groupId) {
        $uid = (int) $m['user_id'];
        if ($uid > 0 && !in_array($uid, $userIds, true)) {
            $userIds[] = $uid;
        }
    }
}

if (empty($userIds)) {
    echo json_encode(['students' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$users = AuthClient::getUsers() ?? [];
$userNames = [];
foreach ($users as $u) {
    $uid = (int) $u['id'];
    $userNames[$uid] = $u['display_name'] ?? $u['login'] ?? ('ID:' . $uid);
}

$students = [];
foreach ($userIds as $uid) {
    $students[$uid] = [
        'id' => $uid,
        'name' => $userNames[$uid] ?? ('ID:' . $uid),
        'contests' => [],
    ];
}

$db = Database::getInstance();

// Общее количество задач в каждом контесте
$tasksStmt = $db->prepare("SELECT contest_id, task_id FROM contest_tasks");
$tasksStmt->execute();
$contestTotals = [];
foreach ($tasksStmt->fetchAll() as $row) {
    $cid = (int) $row['contest_id'];
    $contestTotals[$cid] = ($contestTotals[$cid] ?? 0) + 1;
}

// Решённые задачи участников
$placeholders = implode(',', array_fill(0, count($userIds), '?'));
$solvedStmt = $db->prepare("
    SELECT DISTINCT user_id, contest_id, task_id
    FROM submissions
    WHERE user_id IN ($placeholders) AND status = 'accepted'
");
$solvedStmt->execute($userIds);
$solved = [];
foreach ($solvedStmt->fetchAll() as $row) {
    $uid = (int) $row['user_id'];
    $cid = (int) $row['contest_id'];
    $tid = (int) $row['task_id'];
    $solved[$uid][$cid][$tid] = true;
}

foreach ($userIds as $uid) {
    $contests = [];
    foreach ($contestTotals as $cid => $total) {
        $solvedCount = count($solved[$uid][$cid] ?? []);
        $contests[(string) $cid] = [
            'solved' => $solvedCount,
            'total' => $total,
        ];
    }
    $students[$uid]['contests'] = $contests;
}

echo json_encode(['students' => array_values($students)], JSON_UNESCAPED_UNICODE);
exit;
