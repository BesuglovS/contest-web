<?php
/**
 * API: пакетная сводка прогресса класса (группы) по контестам.
 *
 * GET ?group_id=N&untils=YYYY-MM-DD HH:MM:SS|YYYY-MM-DD HH:MM:SS|...
 * Ответ: { snapshots: { "<until>": { students: [...], until: "<until>" } }, untils: [...] }
 *
 * Один запрос для нескольких моментов: j-web строит прогресс ученика
 * «на начало каждого урока» без десятков последовательных вызовов.
 *
 * Аутентификация: сессия auth-web с правами администратора либо
 * сервер-к-сервер GET без Origin с доверенных IP (ALLOWED_SERVER_IPS).
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

// Сервер-к-сервер сводка (j-web): только GET без Origin с доверенных IP.
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

// Моменты в UTC: YYYY-MM-DD HH:MM:SS через |.
$untils = [];
foreach (explode('|', (string) ($_GET['untils'] ?? '')) as $u) {
    $u = trim($u);
    if ($u === '') {
        continue;
    }
    $dt = DateTime::createFromFormat('!Y-m-d H:i:s', $u);
    if ($dt && $dt->format('Y-m-d H:i:s') === $u) {
        $untils[] = $u;
    }
}
$untils = array_values(array_unique($untils));
if (!$untils) {
    http_response_code(400);
    echo json_encode(['error' => 'untils должен содержать моменты YYYY-MM-DD HH:MM:SS через |'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (count($untils) > 80) {
    http_response_code(400);
    echo json_encode(['error' => 'Слишком много untils (максимум 80)'], JSON_UNESCAPED_UNICODE);
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

$users = AuthClient::getUsers() ?? [];
$userNames = [];
foreach ($users as $u) {
    $uid = (int) $u['id'];
    $userNames[$uid] = $u['display_name'] ?? $u['login'] ?? ('ID:' . $uid);
}

if (empty($userIds)) {
    $snapshots = [];
    foreach ($untils as $until) {
        $snapshots[$until] = ['students' => [], 'until' => $until];
    }
    echo json_encode(['snapshots' => $snapshots, 'untils' => $untils], JSON_UNESCAPED_UNICODE);
    exit;
}

$db = Database::getInstance();

// Все задачи контестов с датой создания: для среза считаем только
// существовавшие на момент until (как в одиночном class_progress).
$tasksStmt = $db->prepare(
    'SELECT ct.contest_id, ct.task_id, t.created_at
     FROM contest_tasks ct
     JOIN tasks t ON t.id = ct.task_id'
);
$tasksStmt->execute();
$contestTasks = $tasksStmt->fetchAll();

// Первое успешное решение каждой задачи участником.
$placeholders = implode(',', array_fill(0, count($userIds), '?'));
$solvedStmt = $db->prepare(
    "SELECT user_id, contest_id, task_id, MIN(executed_at) AS first_solved_at
     FROM submissions
     WHERE user_id IN ($placeholders) AND status = 'accepted'
     GROUP BY user_id, contest_id, task_id"
);
$solvedStmt->execute($userIds);
$solutions = $solvedStmt->fetchAll();

$snapshots = [];
foreach ($untils as $until) {
    $contestTotals = [];
    foreach ($contestTasks as $row) {
        if ((string) $row['created_at'] <= $until) {
            $cid = (int) $row['contest_id'];
            $contestTotals[$cid] = ($contestTotals[$cid] ?? 0) + 1;
        }
    }

    $solved = [];
    foreach ($solutions as $row) {
        if ((string) $row['first_solved_at'] > $until) {
            continue;
        }
        $uid = (int) $row['user_id'];
        $cid = (int) $row['contest_id'];
        $tid = (int) $row['task_id'];
        $solved[$uid][$cid][$tid] = true;
    }

    $students = [];
    foreach ($userIds as $uid) {
        $contests = [];
        foreach ($contestTotals as $cid => $total) {
            $contests[(string) $cid] = [
                'solved' => count($solved[$uid][$cid] ?? []),
                'total' => $total,
            ];
        }
        $students[] = [
            'id' => $uid,
            'name' => $userNames[$uid] ?? ('ID:' . $uid),
            'contests' => $contests,
        ];
    }

    $snapshots[$until] = [
        'students' => $students,
        'until' => $until,
    ];
}

echo json_encode(['snapshots' => $snapshots, 'untils' => $untils], JSON_UNESCAPED_UNICODE);
exit;
