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

        // Линтинг
        $lintResult = $sandbox->lint($code, '--select=E,E226,W');

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
            $runResult = $sandbox->run($code, $test['input'], $timeLimit, $memoryLimit);

            $status = '';
            $output = $runResult['output'] ?? '';
            $error = $cleanTraceback($runResult['error'] ?? '');
            $time = $runResult['time'] ?? 0;
            $memory = $runResult['memory'] ?? 0;

            if (($runResult['status'] ?? 'error') === 'time_limit') {
                $status = 'time_limit';
                $overallStatus = 'time_limit';
            } elseif (($runResult['status'] ?? 'error') === 'memory_limit') {
                $status = 'memory_limit';
                if ($overallStatus === 'accepted') {
                    $overallStatus = 'memory_limit';
                }
            } elseif (in_array(($runResult['status'] ?? 'error'), ['runtime_error', 'error'], true)) {
                $status = 'runtime_error';
                if ($overallStatus === 'accepted') {
                    $overallStatus = 'runtime_error';
                }
            } elseif (Sandbox::compareOutput($output, $test['expected_output'])) {
                $status = 'accepted';
            } else {
                $status = 'wrong_answer';
                if ($overallStatus === 'accepted') {
                    $overallStatus = 'wrong_answer';
                }
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
}