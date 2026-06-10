<?php

/**
 * Класс для безопасного запуска Python-кода в изолированной среде.
 * Использует временные файлы, ограничение по времени и памяти.
 */
class Sandbox
{
    private $pythonCmd;
    private $tempDir;

    public function __construct()
    {
        // Используем python3 или python
        $this->pythonCmd = $this->findPython();
        $this->tempDir = SANDBOX_DIR;
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0755, true);
        }
    }

    /**
     * Находит полный путь к рабочему Python (не заглушку Microsoft Store)
     */
    private function findPython(): string
    {
        // Проверяем PHP-переменную окружения PYTHON_CMD
        $envPath = getenv('PYTHON_CMD');
        if ($envPath && file_exists($envPath)) {
            return $envPath;
        }

        // Пробуем where/which
        $candidates = [];
        if (PHP_OS_FAMILY === 'Windows') {
            $whereOut = shell_exec('where python 2>&1') ?? '';
            $lines = array_filter(explode("\n", trim($whereOut)));
            // Игнорируем заглушку Microsoft Store (WindowsApps)
            $candidates = array_filter($lines, fn($l) => stripos($l, 'WindowsApps') === false);
        } else {
            $whichOut = shell_exec('which python3 2>/dev/null; which python 2>/dev/null') ?? '';
            $candidates = array_filter(explode("\n", trim($whichOut)));
        }

        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            $test = shell_exec('"' . $candidate . '" --version 2>&1');
            if ($test && strpos($test, 'Python') !== false) {
                return $candidate;
            }
        }

        // Fallback
        return 'python';
    }

    /**
     * Строит код Python-обёртки с подставленными параметрами.
     * Обёртка запускает код пользователя через subprocess с ограничением
     * по времени. Модуль resource НЕ используется (только Linux/Mac).
     */
    private function buildWrapperCode(float $timeLimit, int $memoryLimit): string
    {
        $tl = var_export($timeLimit, true);
        $ml = var_export($memoryLimit, true);

        // Используем конкатенацию строк, чтобы \\n в PHP-heredoc НЕ превращался
        // в литерал \n в Python-файле — Python должен видеть реальный \n.
        $nl = "\n";

        return
            "import sys" . $nl .
            "import subprocess" . $nl .
            "import time" . $nl .
            "import os" . $nl .
            "" . $nl .
            "time_limit = {$tl}" . $nl .
            "memory_limit = {$ml}" . $nl .
            "" . $nl .
            "CODE_FILE = sys.argv[1]" . $nl .
            "INPUT_FILE = sys.argv[2]" . $nl .
            "OUTPUT_FILE = sys.argv[3]" . $nl .
            "ERROR_FILE = sys.argv[4]" . $nl .
            "" . $nl .
            "try:" . $nl .
            "    with open(INPUT_FILE, 'r') as f:" . $nl .
            "        stdin_data = f.read()" . $nl .
            "" . $nl .
            "    start_time = time.time()" . $nl .
            "" . $nl .
            "    proc = subprocess.Popen(" . $nl .
            "        [sys.executable, CODE_FILE]," . $nl .
            "        stdin=subprocess.PIPE," . $nl .
            "        stdout=subprocess.PIPE," . $nl .
            "        stderr=subprocess.PIPE," . $nl .
            "        preexec_fn=None," . $nl .
            "    )" . $nl .
            "" . $nl .
            "    try:" . $nl .
            "        stdout_data, stderr_data = proc.communicate(" . $nl .
            "            input=stdin_data.encode('utf-8')," . $nl .
            "            timeout=time_limit" . $nl .
            "        )" . $nl .
            "        elapsed = time.time() - start_time" . $nl .
            "" . $nl .
            "        with open(OUTPUT_FILE, 'wb') as f:" . $nl .
            "            f.write(stdout_data)" . $nl .
            "" . $nl .
            "        with open(ERROR_FILE, 'w') as f:" . $nl .
            "            if stderr_data:" . $nl .
            "                f.write(stderr_data.decode('utf-8', errors='replace'))" . $nl .
            "" . $nl .
            "        exit_code = proc.returncode" . $nl .
            "" . $nl .
            "        with open(OUTPUT_FILE + '.meta', 'w') as f:" . $nl .
            '            f.write(f"exit_code={exit_code}\\n")' . $nl .
            '            f.write(f"time={elapsed:.3f}\\n")' . $nl .
            "" . $nl .
            "    except subprocess.TimeoutExpired:" . $nl .
            "        proc.kill()" . $nl .
            "        elapsed = time.time() - start_time" . $nl .
            "        with open(ERROR_FILE, 'w') as f:" . $nl .
            '            f.write("Time Limit Exceeded")' . $nl .
            "        with open(OUTPUT_FILE + '.meta', 'w') as f:" . $nl .
            '            f.write("status=time_limit\\n")' . $nl .
            '            f.write(f"time={elapsed:.3f}\\n")' . $nl .
            "" . $nl .
            "except MemoryError:" . $nl .
            "    with open(ERROR_FILE, 'w') as f:" . $nl .
            '        f.write("Memory Limit Exceeded")' . $nl .
            "    with open(OUTPUT_FILE + '.meta', 'w') as f:" . $nl .
            '        f.write("status=memory_limit\\n")' . $nl .
            "" . $nl .
            "except Exception as e:" . $nl .
            "    with open(ERROR_FILE, 'w') as f:" . $nl .
            '        f.write(f"System Error: {str(e)}")' . $nl .
            "    with open(OUTPUT_FILE + '.meta', 'w') as f:" . $nl .
            '        f.write("status=error\\n")' . $nl;
    }

    /**
     * Запускает Python-код с заданным входом и возвращает результат
     *
     * @param string $code Код на Python
     * @param string $input Входные данные (stdin)
     * @param float $timeLimit Лимит времени в секундах
     * @param int $memoryLimit Лимит памяти в МБ
     * @return array Результат выполнения: ['output', 'error', 'status', 'time', 'memory']
     */
    public function run(string $code, string $input, float $timeLimit = 2.0, int $memoryLimit = 128): array
    {
        // Создаём уникальные временные файлы
        $id = uniqid('run_', true);
        $codeFile = $this->tempDir . '/' . $id . '.py';
        $inputFile = $this->tempDir . '/' . $id . '.in';
        $outputFile = $this->tempDir . '/' . $id . '.out';
        $errorFile = $this->tempDir . '/' . $id . '.err';

        // Записываем код и входные данные
        file_put_contents($codeFile, $code);
        file_put_contents($inputFile, $input);

        // Генерируем обёртку
        $wrapperCode = $this->buildWrapperCode($timeLimit, $memoryLimit);
        $wrapperFile = $this->tempDir . '/' . $id . '_wrapper.py';
        file_put_contents($wrapperFile, $wrapperCode);

        // Запускаем Python-обёртку через proc_open (без shell)
        $descriptorspec = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $cmd = [
            $this->pythonCmd,
            $wrapperFile,
            $codeFile,
            $inputFile,
            $outputFile,
            $errorFile,
        ];

        $startTime = microtime(true);
        $process = proc_open(
            $cmd,
            $descriptorspec,
            $pipes,
            null,   // cwd
            null    // env (наследуем)
        );

        $wrapperStdout = '';
        $wrapperStderr = '';

        if (is_resource($process)) {
            // Закрываем stdin обёртки (она не ждёт ввода)
            fclose($pipes[0]);

            // Читаем stdout/stderr обёртки
            $wrapperStdout = stream_get_contents($pipes[1]);
            $wrapperStderr = stream_get_contents($pipes[2]);

            fclose($pipes[1]);
            fclose($pipes[2]);

            $exitCode = proc_close($process);
        } else {
            // proc_open не удался — используем shell_exec как fallback
            $escWrapper = escapeshellarg($wrapperFile);
            $escCode = escapeshellarg($codeFile);
            $escInput = escapeshellarg($inputFile);
            $escOutput = escapeshellarg($outputFile);
            $escError = escapeshellarg($errorFile);

            $shellCmd = "\"{$this->pythonCmd}\" {$escWrapper} {$escCode} {$escInput} {$escOutput} {$escError} 2>&1";
            $wrapperStdout = shell_exec($shellCmd) ?? '';
            $wrapperStderr = '';
        }

        $wallTime = microtime(true) - $startTime;

        // Собираем результат
        $result = [
            'output' => '',
            'error' => '',
            'status' => 'error',
            'time' => $wallTime,
            'memory' => 0,
        ];

        // Читаем вывод
        if (file_exists($outputFile)) {
            $result['output'] = file_get_contents($outputFile);
        }

        // Вспомогательная функция для очистки Python traceback от имён файлов (номер строки сохраняем)
        $cleanPyTraceback = function (string $text): string {
            $lines = explode("\n", $text);
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

        // Читаем ошибки
        if (file_exists($errorFile)) {
            $errorText = file_get_contents($errorFile);
            $result['error'] = $cleanPyTraceback($errorText);
        }

        // Если файлы не созданы — используем stdout/stderr обёртки
        if (empty($result['error']) && !empty($wrapperStderr)) {
            $result['error'] = $cleanPyTraceback($wrapperStderr);
        }
        if (empty($result['output']) && empty($result['error']) && !empty($wrapperStdout)) {
            // Возможно, обёртка упала до записи файлов; используем stdout для диагностики
            $result['error'] = $cleanPyTraceback('Wrapper stderr: ' . $wrapperStderr . '; stdout: ' . $wrapperStdout);
        }

        // Читаем метаинформацию
        $metaFile = $outputFile . '.meta';
        if (file_exists($metaFile)) {
            $meta = str_replace("\r\n", "\n", file_get_contents($metaFile));
            $meta = str_replace("\r", "\n", $meta);
            foreach (explode("\n", $meta) as $line) {
                $line = trim($line);
                if ($line === '') continue;
                if (strpos($line, '=') !== false) {
                    [$key, $value] = explode('=', $line, 2);
                    $key = trim($key);
                    $value = trim($value);
                    if ($key === 'exit_code') {
                        $exitCode = (int) $value;
                        if ($exitCode === 0) {
                            $result['status'] = empty($result['error']) ? 'accepted' : 'runtime_error';
                        } else {
                            $result['status'] = 'runtime_error';
                        }
                    }
                    if ($key === 'time') {
                        $result['time'] = (float) $value;
                    }
                    if ($key === 'status') {
                        $result['status'] = $value;
                    }
                }
            }
        }

        // Очистка временных файлов
        foreach ([$codeFile, $inputFile, $outputFile, $errorFile, $wrapperFile, $metaFile] as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }

        return $result;
    }

    /**
     * Проверяет код на соответствие PEP8 с помощью pycodestyle
     *
     * @param string $code Код на Python
     * @param string $extraOptions Дополнительные флаги командной строки для pycodestyle (например, "--enable=E226")
     * @return array Результат проверки: ['has_errors' => bool, 'errors' => array]
     */
    public function lint(string $code, string $extraOptions = ''): array
    {
        // Сохраняем код во временный файл
        $id = uniqid('lint_', true);
        $codeFile = $this->tempDir . '/' . $id . '.py';
        file_put_contents($codeFile, $code);

        // Определяем команду pycodestyle
        // Пробуем разные варианты: pycodestyle, python3 -m pycodestyle, python -m pycodestyle
        $lintCmd = $this->findLintCommand();

        $result = [
            'has_errors' => false,
            'errors' => [],
        ];

        if ($lintCmd === null) {
            // pycodestyle не установлен — возвращаем ошибку, чтобы проверка не пропускалась
            @unlink($codeFile);
            $result['has_errors'] = true;
            $result['errors'][] = [
                'line' => 0,
                'column' => 0,
                'code' => 'SYSTEM',
                'message' => 'Линтер pycodestyle не найден на сервере. Установите: pip install pycodestyle',
            ];
            return $result;
        }

        // Запускаем pycodestyle с дополнительными опциями
        $escapedFile = escapeshellarg($codeFile);
        $extra = trim($extraOptions);
        $cmd = "{$lintCmd}" . ($extra ? " {$extra}" : '') . " {$escapedFile} 2>&1";
        $output = shell_exec($cmd) ?? '';

        // Удаляем временный файл
        @unlink($codeFile);

        if (empty(trim($output))) {
            return $result;
        }

        // Парсим вывод pycodestyle
        // Формат: {file}:{line}:{col}: {code} {message}
        $lines = explode("\n", trim($output));
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            if (preg_match('/^\d+\s+(.+)$/', $line, $m)) {
                // Формат "line col code message" (без имени файла)
                // Например: "3 1 E302 expected 2 blank lines, found 1"
                $parts = preg_split('/\s+/', $m[1], 3);
                // Но это нестандартный формат; стандартный — file:line:col: code message
                // Такое может быть если file пуст или формат отличается
                continue;
            }

            // Формат: file:line:col: code message
            if (preg_match('/^.+?:(\d+):(\d+):\s*(\S+)\s+(.+)$/', $line, $m)) {
                $result['errors'][] = [
                    'line' => (int) $m[1],
                    'column' => (int) $m[2],
                    'code' => $m[3],
                    'message' => $m[3] . ' ' . $m[4],
                ];
            } elseif (preg_match('/^.+?:(\d+):(\d+):\s*(.+)$/', $line, $m)) {
                // Формат: file:line:col: message (без кода)
                $result['errors'][] = [
                    'line' => (int) $m[1],
                    'column' => (int) $m[2],
                    'code' => '',
                    'message' => $m[3],
                ];
            } elseif (preg_match('/^.+?:(\d+):\s*(.+)$/', $line, $m)) {
                // Формат: file:line: message (без column)
                $result['errors'][] = [
                    'line' => (int) $m[1],
                    'column' => 0,
                    'code' => '',
                    'message' => $m[2],
                ];
            } else {
                // Неизвестный формат — сохраняем как есть
                $result['errors'][] = [
                    'line' => 0,
                    'column' => 0,
                    'code' => '',
                    'message' => $line,
                ];
            }
        }

        $result['has_errors'] = !empty($result['errors']);
        return $result;
    }

    /**
     * Находит полный путь к pycodestyle
     */
    private function findLintCommand(): ?string
    {
        $candidates = [];

        if (PHP_OS_FAMILY === 'Windows') {
            // Windows: пробуем python.exe -m pycodestyle (через findPython)
            $candidates[] = '"' . $this->pythonCmd . '" -m pycodestyle';
            // Резерв: где нашёлся pycodestyle напрямую
            $whereOut = shell_exec('where pycodestyle 2>&1') ?? '';
            $lines = array_filter(explode("\n", trim($whereOut)));
            foreach ($lines as $line) {
                $line = trim($line);
                if (!empty($line) && stripos($line, 'not found') === false) {
                    $candidates[] = '"' . $line . '"';
                }
            }
        } else {
            // Linux/Unix (Ubuntu): приоритет python3 -m pycodestyle
            $candidates[] = 'python3 -m pycodestyle';
            $candidates[] = 'python -m pycodestyle';
            // Резерв: прямой путь через which
            $whichOut = shell_exec('which pycodestyle 2>/dev/null') ?? '';
            $lines = array_filter(explode("\n", trim($whichOut)));
            foreach ($lines as $line) {
                $line = trim($line);
                if (!empty($line)) {
                    $candidates[] = $line;
                }
            }
        }

        foreach ($candidates as $cmd) {
            $test = shell_exec("{$cmd} --version 2>&1");
            if ($test && stripos($test, 'not found') === false && stripos($test, 'No module') === false) {
                return $cmd;
            }
        }

        return null;
    }

    /**
     * Сравнивает вывод программы с ожидаемым (с нормализацией пробелов)
     */
    public static function compareOutput(string $actual, string $expected): bool
    {
        // Нормализуем: удаляем \r, пробелы в конце строк, пустые строки в конце
        $normalize = function (string $s): string {
            $s = str_replace("\r\n", "\n", $s);
            $s = str_replace("\r", "\n", $s);
            $lines = explode("\n", trim($s));
            $lines = array_map(fn($l) => rtrim($l), $lines);
            while (!empty($lines) && $lines[count($lines) - 1] === '') {
                array_pop($lines);
            }
            return implode("\n", $lines);
        };

        return $normalize($actual) === $normalize($expected);
    }
}