<?php
/**
 * API для проверки статуса попытки решения
 * GET /index.php?page=api&endpoint=status&submission_id=123
 */

require_once __DIR__ . '/../config.php';
require_once BASE_PATH . '/includes/Database.php';
require_once BASE_PATH . '/includes/Auth.php';

header('Content-Type: application/json; charset=utf-8');

if (!Auth::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Требуется авторизация']);
    exit;
}

$submissionId = isset($_GET['submission_id']) ? (int) $_GET['submission_id'] : 0;

if (!$submissionId) {
    http_response_code(400);
    echo json_encode(['error' => 'Не указан ID попытки']);
    exit;
}

$db = Database::getInstance();

// Проверяем, что попытка принадлежит пользователю или пользователь — админ
$userId = Auth::getUserId();
if (Auth::isAdmin()) {
    $stmt = $db->prepare("SELECT * FROM submissions WHERE id = ?");
    $stmt->execute([$submissionId]);
} else {
    $stmt = $db->prepare("SELECT * FROM submissions WHERE id = ? AND user_id = ?");
    $stmt->execute([$submissionId, $userId]);
}

$submission = $stmt->fetch();

if (!$submission) {
    http_response_code(404);
    echo json_encode(['error' => 'Попытка не найдена']);
    exit;
}

echo json_encode([
    'id' => $submission['id'],
    'task_id' => $submission['task_id'],
    'status' => $submission['status'],
    'execution_time' => $submission['execution_time'],
    'executed_at' => $submission['executed_at'],
    'code' => $submission['code'], // Отдаём код только владельцу
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);