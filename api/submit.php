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
        $msg = preg_replace('/ in .+?(\d+)$/', ' at line $1', $error['message']);
        $msg = preg_replace('/ in .+? (on line \d+)$/', ' $1', $msg);
        echo json_encode(['error' => 'Внутренняя ошибка сервера: ' . $msg]);
        ob_end_flush();
    }
});

// Подключаем зависимости
try {
    require_once __DIR__ . '/../config.php';
    require_once BASE_PATH . '/includes/Database.php';
    require_once BASE_PATH . '/includes/Auth.php';
    require_once BASE_PATH . '/includes/Sandbox.php';
} catch (Throwable $e) {
    sendJsonError('Ошибка загрузки модулей: ' . cleanErrorMessage($e), 500);
}

// Отключаем вывод ошибок в браузер для чистого JSON
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);

// Превращаем обычные ошибки в исключения (уважаем оператор @)
set_error_handler(function ($severity, $message, $file, $line) {
    if (error_reporting() === 0) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// Очищаем буфер на случай пробелов/BOM в подключаемых файлах
ob_clean();
header('Content-Type: application/json; charset=utf-8');

// --- Проверка авторизации ---
try {
    if (!Auth::isLoggedIn()) {
        sendJsonError('Требуется авторизация', 401);
    }
} catch (Throwable $e) {
    sendJsonError('Ошибка проверки авторизации: ' . cleanErrorMessage($e), 500);
}

// --- Проверка метода ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonError('Метод не поддерживается', 405);
}

// --- Проверка CSRF-токена ---
try {
    $csrfToken = sanitizeString($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (empty($csrfToken) || !hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
        sendJsonError('Неверный CSRF-токен', 403);
    }
} catch (Throwable $e) {
    sendJsonError('Ошибка проверки CSRF: ' . cleanErrorMessage($e), 500);
}

// --- Получение и валидация входных данных ---
try {
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    if (!$input) {
        sendJsonError('Неверный формат данных', 400);
    }

    $taskId = (int) (sanitizeString((string)($input['task_id'] ?? '')));
    $code = sanitizeString($input['code'] ?? '');
    $contestId = isset($input['contest_id']) ? (int) sanitizeString((string)$input['contest_id']) : null;

    if (!$taskId || empty($code)) {
        sendJsonError('Не указана задача или код', 400);
    }

    if (!$contestId) {
        sendJsonError('Решение можно отправлять только в рамках контеста', 400);
    }
} catch (Throwable $e) {
    sendJsonError('Ошибка обработки входных данных: ' . cleanErrorMessage($e), 500);
}

// --- Rate limiting через БД ---
$userId = Auth::getUserId();
try {
    $dbRL = Database::getInstance();
    $stmt = $dbRL->prepare("SELECT timestamps FROM rate_limits WHERE user_id = ?");
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    $now = time();
    $windowStart = $now - 60;

    $timestamps = $row ? json_decode($row['timestamps'], true) : [];
    if (!is_array($timestamps)) {
        $timestamps = [];
    }

    $timestamps = array_values(array_filter($timestamps, fn($t) => $t > $windowStart));
    if (count($timestamps) >= 10) {
        sendJsonError('Слишком много отправок. Подождите минуту и попробуйте снова.', 429);
    }
    $timestamps[] = $now;

    $stmt = $dbRL->prepare("INSERT INTO rate_limits (user_id, timestamps, updated_at) VALUES (?, ?, datetime('now'))
        ON CONFLICT(user_id) DO UPDATE SET timestamps = excluded.timestamps, updated_at = datetime('now')");
    $stmt->execute([$userId, json_encode($timestamps)]);
} catch (PDOException $e) {
    sendJsonError('Ошибка базы данных (rate limit): ' . cleanErrorMessage($e), 500);
}

// --- Проверка задачи ---
try {
    $db = Database::getInstance();
    $stmt = $db->prepare("SELECT * FROM tasks WHERE id = ?");
    $stmt->execute([$taskId]);
    $task = $stmt->fetch();

    if (!$task) {
        sendJsonError('Задача не найдена', 404);
    }
} catch (PDOException $e) {
    sendJsonError('Ошибка базы данных (задача): ' . cleanErrorMessage($e), 500);
}

// --- Проверка доступа к задаче ---
try {
    $stmt = $db->prepare("SELECT 1 FROM tasks t
        INNER JOIN contest_tasks ct ON t.id = ct.task_id
        INNER JOIN contest_access ca ON ct.contest_id = ca.contest_id
        LEFT JOIN user_groups ug ON ug.user_id = ? AND ca.group_id = ug.group_id
        WHERE t.id = ? AND ct.contest_id = ? AND (ca.user_id = ? OR ug.group_id IS NOT NULL)
        LIMIT 1");
    $stmt->execute([$userId, $taskId, $contestId, $userId]);
    $hasAccess = (bool) $stmt->fetch();

    if (!$hasAccess) {
        sendJsonError('У вас нет доступа к этой задаче в указанном контесте', 403);
    }
} catch (PDOException $e) {
    sendJsonError('Ошибка базы данных (доступ): ' . cleanErrorMessage($e), 500);
}

// --- Проверка завершения контеста ---
try {
    $stmt = $db->prepare("SELECT end_time FROM contests WHERE id = ? LIMIT 1");
    $stmt->execute([$contestId]);
    $contest = $stmt->fetch();
    if ($contest && $contest['end_time']) {
        $endTime = strtotime($contest['end_time']);
        if ($endTime && $endTime < time()) {
            sendJsonError('Контест завершён', 403);
        }
    }
} catch (PDOException $e) {
    sendJsonError('Ошибка базы данных (контест): ' . cleanErrorMessage($e), 500);
}

// --- Проверка наличия тестов ---
try {
    $stmt = $db->prepare("SELECT * FROM tests WHERE task_id = ? ORDER BY test_number");
    $stmt->execute([$taskId]);
    $tests = $stmt->fetchAll();

    if (empty($tests)) {
        sendJsonError('У задачи нет тестов', 500);
    }
} catch (PDOException $e) {
    sendJsonError('Ошибка базы данных (тесты): ' . cleanErrorMessage($e), 500);
}

// --- Создание записи о попытке ---
try {
    $stmt = $db->prepare("INSERT INTO submissions (user_id, task_id, contest_id, code, status) VALUES (?, ?, ?, ?, 'pending')");
    $stmt->execute([$userId, $taskId, $contestId, $code]);
    $submissionId = $db->lastInsertId();
} catch (PDOException $e) {
    sendJsonError('Ошибка базы данных (создание попытки): ' . cleanErrorMessage($e), 500);
}

// --- Запуск тестов ---
try {
    require_once BASE_PATH . '/includes/TestingEngine.php';
    $testResult = TestingEngine::runTests($code, $taskId, $db);
} catch (Throwable $e) {
    sendJsonError('Ошибка выполнения тестов: ' . cleanErrorMessage($e), 500);
}

if (isset($testResult['error'])) {
    sendJsonError($testResult['error'], 500);
}

// --- Обработка ошибок линтинга ---
if ($testResult['lint_errors']) {
    try {
        $stmt = $db->prepare("UPDATE submissions SET status = 'lint_error', lint_errors = ? WHERE id = ?");
        $stmt->execute([$testResult['lint_errors_json'], $submissionId]);
    } catch (PDOException $e) {
        sendJsonError('Ошибка сохранения ошибок линтинга: ' . cleanErrorMessage($e), 500);
    }

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

// --- Сохранение результатов тестов ---
$results = $testResult['test_results'];
$overallStatus = $testResult['overall_status'];
$totalTime = $testResult['total_time'];

try {
    foreach ($results as $tr) {
        $testNumber = $tr instanceof TestResult ? $tr->number : (int)$tr['test_number'];
        $testStatus = $tr instanceof TestResult ? $tr->status : $tr['status'];
        $testTime = $tr instanceof TestResult ? round($tr->time, 3) : round($tr['time'], 3);
        $testMemory = $tr instanceof TestResult ? $tr->memory : (int)($tr['memory'] ?? 0);
        $testOutput = $tr instanceof TestResult ? $tr->output : $tr['output'];

        $stmt = $db->prepare("INSERT INTO submission_test_results (submission_id, test_number, status, execution_time, memory_used, output) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $submissionId,
            $testNumber,
            $testStatus,
            $testTime,
            $testMemory,
            $testOutput
        ]);
    }
} catch (PDOException $e) {
    sendJsonError('Ошибка сохранения результатов тестов: ' . cleanErrorMessage($e), 500);
}

// --- Обновление статуса попытки ---
try {
    $stmt = $db->prepare("UPDATE submissions SET status = ?, execution_time = ? WHERE id = ?");
    $stmt->execute([$overallStatus, $totalTime, $submissionId]);
} catch (PDOException $e) {
    sendJsonError('Ошибка обновления статуса: ' . cleanErrorMessage($e), 500);
}

// --- Формирование ответа ---
try {
    $publicResults = [];
    $passedCount = 0;
    $totalCount = 0;

    foreach ($results as $tr) {
        $totalCount++;
        $isPublic = $tr instanceof TestResult ? $tr->isPublic : $tr['is_public'];
        $status = $tr instanceof TestResult ? $tr->status : $tr['status'];

        if ($status === 'accepted') {
            $passedCount++;
        }

        if ($isPublic) {
            $publicResults[] = $tr instanceof TestResult ? [
                'test_number' => $tr->number,
                'is_public' => $tr->isPublic,
                'status' => $tr->status,
                'output' => $tr->output,
                'error' => $tr->error,
                'time' => $tr->time,
                'memory' => $tr->memory,
                'input' => $tr->input,
                'expected' => $tr->expected,
            ] : $tr;
        }
    }

    $allPassed = $overallStatus === 'accepted';

    echo json_encode([
        'submission_id' => $submissionId,
        'status' => $overallStatus,
        'all_passed' => $allPassed,
        'passed' => $passedCount,
        'total' => $totalCount,
        'total_time' => $totalTime,
        'public_results' => array_values($publicResults),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    sendJsonError('Ошибка формирования ответа: ' . cleanErrorMessage($e), 500);
}

ob_end_flush();

// =============================================
// Вспомогательные функции
// =============================================

/**
 * Отправляет JSON-ошибку, очищает буфер и завершает скрипт.
 */
function sendJsonError(string $message, int $httpCode): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    ob_start();
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($httpCode);
    echo json_encode(['error' => $message], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    ob_end_flush();
    exit;
}

/**
 * Очищает сообщение ошибки от путей к файлам.
 */
function cleanErrorMessage(Throwable $e): string
{
    $msg = $e->getMessage();
    $msg = preg_replace('/ in .+?(\d+)$/', ' at line $1', $msg);
    $msg = preg_replace('/ in .+? (on line \d+)$/', ' $1', $msg);
    return sanitizeString($msg);
}