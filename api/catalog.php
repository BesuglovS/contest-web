<?php
/**
 * API: каталог контестов и задач (для выбора задач в домашнем задании j-web).
 *
 * GET index.php?page=api&endpoint=admin_catalog
 * Ответ: { contests: [ { id, title, tasks: [ { id, title } ] } ] }
 *
 * Аутентификация: как у class_progress.php — сессия auth-web с правами
 * администратора либо сервер-к-сервер GET без Origin с доверенного IP.
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

// Сервер-к-сервер чтение каталога (j-web): допустимо только для GET без
// Origin с доверенных IP (ALLOWED_SERVER_IPS). Иначе — SSO-сессия админа.
$serverMode = false;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
    && ($_SERVER['HTTP_ORIGIN'] ?? '') === ''
    && in_array($_SERVER['REMOTE_ADDR'] ?? '', ALLOWED_SERVER_IPS, true)) {
    $serverMode = true;
}
if (!$serverMode) {
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

$db = Database::getInstance();

// tasks.sort_order пишется импортом, но в базовой схеме колонки может не быть —
// не обращаемся к ней здесь, сортируем по contest_tasks.sort_order и id задачи.
$stmt = $db->query("
    SELECT c.id AS contest_id, c.title AS contest_title,
           t.id AS task_id, t.title AS task_title
    FROM contests c
    LEFT JOIN contest_tasks ct ON ct.contest_id = c.id
    LEFT JOIN tasks t ON t.id = ct.task_id
    ORDER BY c.id, ct.sort_order, t.id
");

$contests = [];
foreach ($stmt->fetchAll() as $row) {
    $cid = (int) $row['contest_id'];
    if (!isset($contests[$cid])) {
        $contests[$cid] = [
            'id' => $cid,
            'title' => (string) $row['contest_title'],
            'tasks' => [],
        ];
    }
    if ($row['task_id'] !== null) {
        $contests[$cid]['tasks'][] = [
            'id' => (int) $row['task_id'],
            'title' => (string) $row['task_title'],
        ];
    }
}

echo json_encode(
    ['contests' => array_values($contests)],
    JSON_UNESCAPED_UNICODE
);
exit;
