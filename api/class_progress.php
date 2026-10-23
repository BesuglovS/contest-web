<?php
/**
 * API: сводка прогресса класса (группы auth-web) по контестам.
 * Используется j-web (электронный журнал) для страницы учёта успеваемости.
 *
 * GET ?group_id=N
 * GET ?group_id=N&until=YYYY-MM-DD[ HH:MM:SS] — прогресс на конец дня / момент
 * Ответ: { students: [ { id, name, contests: { "<contest_id>": {solved, total} } } ], until }
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

// Сервер-к-сервер сводка по классу (j-web — кабинет тьютора): допустимо
// только для GET без Origin с доверенных IP (ALLOWED_SERVER_IPS). Иначе —
// как раньше: нужна сессия auth-web с правами администратора.
$serverClassGroupId = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
    && ($_SERVER['HTTP_ORIGIN'] ?? '') === ''
    && in_array($_SERVER['REMOTE_ADDR'] ?? '', ALLOWED_SERVER_IPS, true)) {
    $gid = isset($_GET['group_id']) ? (int) $_GET['group_id'] : 0;
    if ($gid > 0) {
        $serverClassGroupId = $gid;
    }
}
if ($serverClassGroupId === null) {
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
}

$groupId = $serverClassGroupId ?? (isset($_GET['group_id']) ? (int) $_GET['group_id'] : 0);
if ($groupId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Не указан group_id'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Оценка прогресса на дату и время: until=YYYY-MM-DD (конец дня) или
// until=YYYY-MM-DD HH:MM:SS (точка отсчёта). В БД даты хранятся в UTC.
$until = trim((string) ($_GET['until'] ?? ''));
$untilEnd = null;
if ($until !== '') {
    $untilExact = DateTime::createFromFormat('!Y-m-d H:i:s', $until);
    if ($untilExact && $untilExact->format('Y-m-d H:i:s') === $until) {
        $untilEnd = $until;
    } else {
        $untilDate = DateTime::createFromFormat('!Y-m-d', $until);
        if (!$untilDate || $untilDate->format('Y-m-d') !== $until) {
            http_response_code(400);
            echo json_encode(['error' => 'until должен быть в формате YYYY-MM-DD или YYYY-MM-DD HH:MM:SS'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $untilEnd = $until . ' 23:59:59';
    }
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
    echo json_encode(['students' => [], 'until' => $until !== '' ? $until : null], JSON_UNESCAPED_UNICODE);
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

// Общее количество задач в каждом контесте (при until — только задачи,
// существовавшие на конец выбранного дня)
$tasksSql = 'SELECT ct.contest_id, ct.task_id FROM contest_tasks ct';
$tasksParams = [];
if ($untilEnd !== null) {
    $tasksSql .= ' JOIN tasks t ON t.id = ct.task_id WHERE t.created_at <= ?';
    $tasksParams[] = $untilEnd;
}
$tasksStmt = $db->prepare($tasksSql);
$tasksStmt->execute($tasksParams);
$contestTotals = [];
foreach ($tasksStmt->fetchAll() as $row) {
    $cid = (int) $row['contest_id'];
    $contestTotals[$cid] = ($contestTotals[$cid] ?? 0) + 1;
}

// Решённые задачи участников (при until — только отправленные до конца дня)
$placeholders = implode(',', array_fill(0, count($userIds), '?'));
$solvedSql = "
    SELECT DISTINCT user_id, contest_id, task_id
    FROM submissions
    WHERE user_id IN ($placeholders) AND status = 'accepted'
";
$solvedParams = $userIds;
if ($untilEnd !== null) {
    $solvedSql .= ' AND executed_at <= ?';
    $solvedParams[] = $untilEnd;
}
$solvedStmt = $db->prepare($solvedSql);
$solvedStmt->execute($solvedParams);
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

echo json_encode([
    'students' => array_values($students),
    'until' => $until !== '' ? $until : null,
], JSON_UNESCAPED_UNICODE);
exit;
