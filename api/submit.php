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

    // Проверяем код на соответствие PEP8 через pycodestyle
    $sandbox = new Sandbox();
    // Проверка PEP8: все E и W правила, включая отключённое по умолчанию E226 (пробелы вокруг операторов)
    $lintResult = $sandbox->lint($code, '--select=E,E226,W');

    if ($lintResult['has_errors']) {
        // Сохраняем ошибки линтинга в JSON
        $lintErrorsJson = json_encode($lintResult['errors'], JSON_UNESCAPED_UNICODE);
        $stmt = $db->prepare("UPDATE submissions SET status = 'lint_error', lint_errors = ? WHERE id = ?");
        $stmt->execute([$lintErrorsJson, $submissionId]);

        echo json_encode([
            'submission_id' => $submissionId,
            'status' => 'lint_error',
            'all_passed' => false,
            'passed' => 0,
            'total' => count($tests),
            'total_time' => 0,
            'lint_errors' => $lintResult['errors'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        ob_end_flush();
        exit;
    }

    $timeLimit = (float) ($task['time_limit'] ?? 2.0);
    $memoryLimit = (int) ($task['memory_limit'] ?? 128);

    $results = [];
    $overallStatus = 'accepted';
    $totalTime = 0;

    // Функция для очистки Python traceback от имён файлов (номер строки сохраняем)
    $cleanTraceback = function (string $error): string {
        $lines = explode("\n", $error);
        $filtered = [];
        foreach ($lines as $line) {
            // Убираем строку "Traceback (most recent call last)"
            if (strpos($line, 'Traceback (most recent call last)') !== false) {
                continue;
            }
            // В строках вида '  File "путь", line N, in ...' — убираем File "путь", но оставляем 'line N, in ...'
            $line = preg_replace('/^\s*File\s+"[^"]*",\s*/', '', $line);
            $filtered[] = $line;
        }
        return trim(implode("\n", $filtered));
    };

    foreach ($tests as $test) {
        $runResult = $sandbox->run($code, $test['input'], $timeLimit, $memoryLimit);

        $testResult = [
            'test_number' => (int) $test['test_number'],
            'is_public' => (bool) $test['is_public'],
            'status' => '',
            'input' => $test['input'],
            'expected' => $test['expected_output'],
            'output' => $runResult['output'] ?? '',
            'error' => $cleanTraceback($runResult['error'] ?? ''),
            'time' => $runResult['time'] ?? 0,
        ];

        if (($runResult['status'] ?? 'error') === 'time_limit') {
            $testResult['status'] = 'time_limit';
            $overallStatus = 'time_limit';
        } elseif (($runResult['status'] ?? 'error') === 'memory_limit') {
            $testResult['status'] = 'memory_limit';
            if ($overallStatus === 'accepted') {
                $overallStatus = 'memory_limit';
            }
        } elseif (in_array(($runResult['status'] ?? 'error'), ['runtime_error', 'error'], true)) {
            $testResult['status'] = 'runtime_error';
            if ($overallStatus === 'accepted') {
                $overallStatus = 'runtime_error';
            }
        } elseif (Sandbox::compareOutput($runResult['output'] ?? '', $test['expected_output'])) {
            $testResult['status'] = 'accepted';
        } else {
            $testResult['status'] = 'wrong_answer';
            if ($overallStatus === 'accepted') {
                $overallStatus = 'wrong_answer';
            }
        }

        $results[] = $testResult;
        $totalTime += ($runResult['time'] ?? 0);

        // Сохраняем результат теста в БД
        $stmt = $db->prepare("INSERT INTO submission_test_results (submission_id, test_number, status, execution_time, memory_used, output) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $submissionId,
            (int)$test['test_number'],
            $testResult['status'],
            round($testResult['time'], 3),
            (int)($runResult['memory'] ?? 0),
            $testResult['output']
        ]);
    }

    // Обновляем статус попытки
    $stmt = $db->prepare("UPDATE submissions SET status = ?, execution_time = ? WHERE id = ?");
    $stmt->execute([$overallStatus, round($totalTime, 3), $submissionId]);

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
        'total_time' => round($totalTime, 3),
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
