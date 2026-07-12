<?php
/**
 * API для отправки кода и проверки решений
 * Принимает POST-запросы с JSON: { task_id, code }
 * Возвращает JSON с результатами тестов
 */

// Буферизация вывода для предотвращения случайного HTML от PHP-ошибок
ob_start();

// Регистрация shutdown-функции для перехвата фатальных ошибок
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        // Очищаем буфер от мусора
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        ob_start();
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        // Убираем имя файла из сообщения об ошибке (номер строки оставляем)
        // PHP 8+: "Fatal error: ... in C:\path\file.php:42" -> "Fatal error: ... at line 42"
        $msg = preg_replace('/ in .+?(\d+)$/', ' at line $1', $error['message']);
        // PHP 7-: "Fatal error: ... in C:\path\file.php on line 42" -> "Fatal error: ... on line 42"
        $msg = preg_replace('/ in .+? (on line \d+)$/', ' $1', $msg);
        echo json_encode(['error' => 'Внутренняя ошибка сервера: ' . $msg]);
        ob_end_flush();
    }
});

try {
    require_once __DIR__ . '/../config.php';
    require_once BASE_PATH . '/includes/Database.php';
    require_once BASE_PATH . '/includes/Auth.php';
    require_once BASE_PATH . '/includes/Sandbox.php';

    // Отключаем вывод ошибок в браузер для чистого JSON
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);

    // Превращаем обычные ошибки в исключения (уважаем оператор @)
    set_error_handler(function ($severity, $message, $file, $line) {
        // Если error_reporting() === 0 — сработал оператор @, не выбрасываем исключение
        if (error_reporting() === 0) {
            return false;
        }
        throw new ErrorException($message, 0, $severity, $file, $line);
    });

    // Очищаем буфер на случай пробелов/BOM в подключаемых файлах
    ob_clean();

    header('Content-Type: application/json; charset=utf-8');

    // Проверка авторизации
    if (!Auth::isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['error' => 'Требуется авторизация']);
        ob_end_flush();
        exit;
    }

    // Только POST-запросы
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Метод не поддерживается']);
        ob_end_flush();
        exit;
    }

    // Получаем данные
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    if (!$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Неверный формат данных', 'raw' => $rawInput]);
        ob_end_flush();
        exit;
    }

    $taskId = (int) ($input['task_id'] ?? 0);
    $code = $input['code'] ?? '';
    $contestId = isset($input['contest_id']) ? (int) $input['contest_id'] : null;

    if (!$taskId || empty($code)) {
        http_response_code(400);
        echo json_encode(['error' => 'Не указана задача или код']);
        ob_end_flush();
        exit;
    }

    if (!$contestId) {
        http_response_code(400);
        echo json_encode(['error' => 'Решение можно отправлять только в рамках контеста']);
        ob_end_flush();
        exit;
    }

    // Проверяем существование задачи
    $db = Database::getInstance();
    $stmt = $db->prepare("SELECT * FROM tasks WHERE id = ?");
    $stmt->execute([$taskId]);
    $task = $stmt->fetch();

    if (!$task) {
        http_response_code(404);
        echo json_encode(['error' => 'Задача не найдена']);
        ob_end_flush();
        exit;
    }

    // Проверяем доступ к задаче: пользователь должен иметь доступ к контесту, и задача должна быть в этом контесте
    $userId = Auth::getUserId();
    $stmt = $db->prepare("SELECT 1 FROM tasks t
        JOIN contest_tasks ct ON t.id = ct.task_id
        JOIN contest_access ca ON ct.contest_id = ca.contest_id
        LEFT JOIN user_groups ug ON ug.user_id = ? AND ca.group_id = ug.group_id
        WHERE t.id = ? AND ct.contest_id = ? AND (ca.user_id = ? OR ug.group_id IS NOT NULL)
        LIMIT 1");
    $stmt->execute([$userId, $taskId, $contestId, $userId]);
    $hasAccess = (bool) $stmt->fetch();

    if (!$hasAccess) {
        http_response_code(403);
        echo json_encode(['error' => 'У вас нет доступа к этой задаче в указанном контесте']);
        ob_end_flush();
        exit;
    }

    // Проверяем, не завершён ли контест
    $stmt = $db->prepare("SELECT end_time FROM contests WHERE id = ? LIMIT 1");
    $stmt->execute([$contestId]);
    $contest = $stmt->fetch();
    if ($contest && $contest['end_time']) {
        $endTime = strtotime($contest['end_time']);
        if ($endTime && $endTime < time()) {
            http_response_code(403);
            echo json_encode(['error' => 'Контест завершён']);
            ob_end_flush();
            exit;
        }
    }

    // Получаем тесты
    $stmt = $db->prepare("SELECT * FROM tests WHERE task_id = ? ORDER BY test_number");
    $stmt->execute([$taskId]);
    $tests = $stmt->fetchAll();

    if (empty($tests)) {
        http_response_code(500);
        echo json_encode(['error' => 'У задачи нет тестов']);
        ob_end_flush();
        exit;
    }

    // Создаём запись о попытке
    $userId = Auth::getUserId();
    $stmt = $db->prepare("INSERT INTO submissions (user_id, task_id, contest_id, code, status) VALUES (?, ?, ?, ?, 'pending')");
    $stmt->execute([$userId, $taskId, $contestId, $code]);
    $submissionId = $db->lastInsertId();

    require_once BASE_PATH . '/includes/TestingEngine.php';
    $testResult = TestingEngine::runTests($code, $taskId, $db);

    if (isset($testResult['error'])) {
        echo json_encode(['error' => $testResult['error']], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        ob_end_flush();
        exit;
    }

    if ($testResult['lint_errors']) {
        // Сохраняем ошибки линтинга в JSON
        $stmt = $db->prepare("UPDATE submissions SET status = 'lint_error', lint_errors = ? WHERE id = ?");
        $stmt->execute([$testResult['lint_errors_json'], $submissionId]);

        echo json_encode([
            'submission_id' => $submissionId,
            'status' => 'lint_error',
            'all_passed' => false,
            'passed' => 0,
            'total' => count($tests),
            'total_time' => 0,
            'lint_errors' => $testResult['lint_errors_array'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        ob_end_flush();
        exit;
    }

    $results = $testResult['test_results'];
    $overallStatus = $testResult['overall_status'];
    $totalTime = $testResult['total_time'];

    // Сохраняем результат каждого теста в БД
    foreach ($results as $tr) {
        $stmt = $db->prepare("INSERT INTO submission_test_results (submission_id, test_number, status, execution_time, memory_used, output) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $submissionId,
            (int)$tr['test_number'],
            $tr['status'],
            round($tr['time'], 3),
            (int)($tr['memory'] ?? 0),
            $tr['output']
        ]);
    }

    // Обновляем статус попытки
    $stmt = $db->prepare("UPDATE submissions SET status = ?, execution_time = ? WHERE id = ?");
    $stmt->execute([$overallStatus, $totalTime, $submissionId]);

    // Формируем ответ
    $publicResults = array_filter($results, fn($r) => $r['is_public']);
    $allPassed = $overallStatus === 'accepted';
    $passedCount = count(array_filter($results, fn($r) => $r['status'] === 'accepted'));
    $totalCount = count($results);

    echo json_encode([
        'submission_id' => $submissionId,
        'status' => $overallStatus,
        'all_passed' => $allPassed,
        'passed' => $passedCount,
        'total' => $totalCount,
        'total_time' => $totalTime,
        'public_results' => array_values($publicResults),
        'all_results' => $results,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    ob_end_flush();
} catch (Throwable $e) {
    // Очищаем буфер от возможного мусора
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    ob_start();
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    // Убираем имя файла из сообщения об ошибке (номер строки оставляем)
    // PHP 8+: "Fatal error: ... in C:\path\file.php:42" -> "Fatal error: ... at line 42"
    $msg = preg_replace('/ in .+?(\d+)$/', ' at line $1', $e->getMessage());
    // PHP 7-: "Fatal error: ... in C:\path\file.php on line 42" -> "Fatal error: ... on line 42"
    $msg = preg_replace('/ in .+? (on line \d+)$/', ' $1', $msg);
    echo json_encode([
        'error' => 'Ошибка сервера: ' . $msg,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    ob_end_flush();
}
