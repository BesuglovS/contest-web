<?php

/**
 * Класс для запуска тестов Python-кода.
 * Содержит общую логику тестирования: линтинг, прогон по тестам, запись результатов в БД.
 */
class TestingEngine
{
    /**
     * Запускает тесты для кода задачи.
     * @param string $code Код решения
     * @param int $taskId ID задачи
     * @param PDO $db Экземпляр базы данных
     * @return array Результаты тестирования:
     *   - lint_errors: bool
     *   - lint_errors_json: string|null (JSON или null)
     *   - overall_status: string
     *   - total_time: float
     *   - test_results: TestResult[]
     */
    public static function runTests(string $code, int $taskId, PDO $db): array
    {
        // Загружаем задачу
        $stmt = $db->prepare("SELECT * FROM tasks WHERE id = ?");
        $stmt->execute([$taskId]);
        $task = $stmt->fetch();

        if (!$task) {
            return ['error' => 'Задача не найдена'];
        }

        // Загружаем тесты
        $stmt = $db->prepare("SELECT * FROM tests WHERE task_id = ? ORDER BY test_number");
        $stmt->execute([$taskId]);
        $tests = $stmt->fetchAll();

        if (empty($tests)) {
            return ['error' => 'У задачи нет тестов'];
        }

        require_once __DIR__ . '/Sandbox.php';
        $sandbox = new Sandbox();

        // Проверка запрещённых модулей (os, subprocess, socket, ...) —
        // до запуска кода; оформляется как ошибки линтинга
        $forbiddenErrors = self::findForbiddenModules($code);
        if (!empty($forbiddenErrors)) {
            return [
                'lint_errors' => true,
                'lint_errors_json' => json_encode($forbiddenErrors, JSON_UNESCAPED_UNICODE),
                'lint_errors_array' => $forbiddenErrors,
                'overall_status' => 'lint_error',
                'total_time' => 0,
                'test_results' => [],
            ];
        }

        // Линтинг — с настройками pycodestyle по умолчанию (без --select,
        // чтобы не включать коды, которые pycodestyle игнорирует по умолчанию:
        // E121/E123/E126/E226/E24/E704/W503/W504)
        $lintResult = $sandbox->lint($code);

        if ($lintResult['has_errors']) {
            return [
                'lint_errors' => true,
                'lint_errors_json' => json_encode($lintResult['errors'], JSON_UNESCAPED_UNICODE),
                'lint_errors_array' => $lintResult['errors'],
                'overall_status' => 'lint_error',
                'total_time' => 0,
                'test_results' => [],
            ];
        }

        $timeLimit = (float)($task['time_limit'] ?? 2.0);
        $memoryLimit = (int)($task['memory_limit'] ?? 128);
        $checkMode = (string)($task['check_mode'] ?? 'program');
        $functionName = isset($task['function_name']) ? trim((string)$task['function_name']) : '';
        $functionMode = $checkMode === 'function' && $functionName !== '';
        $overallStatus = 'accepted';
        $totalTime = 0;
        $results = [];

        // Функция для очистки Python traceback от имён файлов (оптимизирована)
        $cleanTraceback = function (string $error): string {
            $lines = explode("\n", $error);
            $filtered = array_map(function ($line) {
                if (str_contains($line, 'Traceback (most recent call last)')) {
                    return '';
                }
                return preg_replace('/^\s*File\s+"[^"]*",\s*/', '', $line);
            }, $lines);
            return trim(implode("\n", array_filter($filtered, fn($line) => $line !== '')));
        };

        foreach ($tests as $test) {
            if ($functionMode) {
                $runResult = $sandbox->runFunctionTest($code, $functionName, $test['input'], $test['expected_output'], $timeLimit, $memoryLimit);
            } else {
                $runResult = $sandbox->run($code, $test['input'], $timeLimit, $memoryLimit);
            }

            $status = '';
            $output = $runResult['output'] ?? '';
            $error = $functionMode
                ? ($runResult['error'] ?? '')
                : $cleanTraceback($runResult['error'] ?? '');
            $time = $runResult['time'] ?? 0;
            $memory = $runResult['memory'] ?? 0;

            if (($runResult['status'] ?? 'error') === 'time_limit') {
                $status = 'time_limit';
            } elseif (($runResult['status'] ?? 'error') === 'memory_limit') {
                $status = 'memory_limit';
            } elseif (in_array(($runResult['status'] ?? 'error'), ['runtime_error', 'error'], true)) {
                $status = 'runtime_error';
            } elseif (($runResult['status'] ?? 'error') === 'no_function') {
                $status = 'no_function';
            } elseif ($functionMode && ($runResult['status'] ?? 'error') === 'accepted') {
                $status = 'accepted';
                $output = $runResult['result'] ?? '';
            } elseif ($functionMode) {
                $status = 'wrong_answer';
                $output = $runResult['result'] ?? '';
            } elseif (Sandbox::compareOutput($output, $test['expected_output'])) {
                $status = 'accepted';
            } else {
                $status = 'wrong_answer';
            }

            // Итоговый вердикт — по первому провальному тесту: последующие
            // тесты не «перебивают» уже выставленный статус (в т.ч. time_limit)
            if ($overallStatus === 'accepted' && $status !== 'accepted') {
                $overallStatus = $status;
            }

            $results[] = new TestResult(
                number: (int)$test['test_number'],
                isPublic: (bool)$test['is_public'],
                status: $status,
                output: $output,
                error: $error,
                time: $time,
                memory: $memory,
                input: $test['input'],
                expected: $test['expected_output'],
            );

            $totalTime += $time;
        }

        return [
            'lint_errors' => false,
            'lint_errors_json' => null,
            'lint_errors_array' => [],
            'overall_status' => $overallStatus,
            'total_time' => round($totalTime, 3),
            'test_results' => $results,
        ];
    }

    /**
     * Ищет импорты запрещённых модулей (FORBIDDEN_MODULES) в коде решения.
     * Возвращает массив ошибок в формате lint-ошибок:
     * ['line' => int, 'column' => int, 'code' => 'FORBIDDEN', 'message' => string]
     */
    private static function findForbiddenModules(string $code): array
    {
        $errors = [];
        $forbidden = array_map('strtolower', FORBIDDEN_MODULES);
        $lines = explode("\n", $code);

        foreach ($lines as $i => $line) {
            // Разбираем все сегменты строки (ловит "x = 1; import os")
            foreach (explode(';', $line) as $segment) {
                // import os, sys  |  import os.path as osp  |  from os.path import join
                if (!preg_match('/^\s*(?:from\s+([.\w]+)\s+import\b|import\s+([^#\n]+))/', $segment, $m)) {
                    continue;
                }

                $roots = [];
                if (($m[1] ?? '') !== '') {
                    $roots[] = $m[1];
                } else {
                    foreach (explode(',', (string) $m[2]) as $part) {
                        $part = trim($part);
                        if ($part === '') {
                            continue;
                        }
                        // Убираем алиас: "os as o"
                        $part = trim(preg_split('/\s+as\s+/i', $part)[0]);
                        if ($part !== '') {
                            $roots[] = $part;
                        }
                    }
                }

                foreach ($roots as $root) {
                    // Берём корневой модуль до первой точки ("os.path" -> "os")
                    $root = strtolower(explode('.', $root)[0]);
                    if (in_array($root, $forbidden, true)) {
                        $errors[] = [
                            'line' => $i + 1,
                            'column' => 0,
                            'code' => 'FORBIDDEN',
                            'message' => "Запрещённый модуль: {$root} — его использование в решениях не допускается",
                        ];
                    }
                }
            }
        }

        return $errors;
    }
}