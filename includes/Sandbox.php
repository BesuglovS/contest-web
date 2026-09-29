<?php

/**
 * Класс для безопасного запуска Python-кода в изолированной среде.
 * Использует временные файлы, ограничение по времени и памяти.
 */
class Sandbox
{
    /** Кэш найденного интерпретатора и линтера (поиск через shell дорог) */
    private static ?string $cachedPythonCmd = null;
    private static ?string $cachedLintCmd = null;
    private static bool $lintCmdResolved = false;

    private $pythonCmd;
    private $tempDir;

    /**
     * Путь к изолирующему root-хелперу (scripts/sandbox-python.run из python-web)
     * или null, если он недоступен (локальная разработка / Windows).
     *
     * Режимы через env:
     *   SANDBOX_ISOLATION=1  — требовать хелпер (для лога, не критично);
     *   SANDBOX_ISOLATION=0  — отключить изоляцию (dev/CI);
     *   не задан             — авто: использовать хелпер, если установлен.
     */
    private static function isolationHelper(): ?string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return null;
        }
        $mode = getenv('SANDBOX_ISOLATION');
        if ($mode === '0') {
            return null;
        }
        $helper = getenv('SANDBOX_RUN_HELPER');
        if ($helper === false || $helper === '') {
            $helper = '/usr/local/sbin/sandbox-python.run';
        }
        if (!is_file($helper) || !is_executable($helper)) {
            if ($mode === '1') {
                error_log('[sandbox] SANDBOX_ISOLATION=1, но изолирующий хелпер недоступен: ' . $helper);
            }
            return null;
        }
        return $helper;
    }

    public function __construct()
    {
        // Используем python3 или python (путь ищется один раз за процесс)
        if (self::$cachedPythonCmd === null) {
            self::$cachedPythonCmd = $this->findPython();
        }
        $this->pythonCmd = self::$cachedPythonCmd;
        $this->tempDir = SANDBOX_DIR;
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0755, true);
        }
    }

    /**
     * AST-валидация кода (модель python-web): отдельный процесс
     * sandbox/ast_validator.py читает код из stdin и печатает JSON
     * {ok, error?}. Список разрешённых импортов задаётся argv.
     *
     * @return ?string null — код безопасен; строка — сообщение об ошибке.
     *         Сбой запуска валидатора вернёт null (не блокируем проверку —
     *         работают PHP-фильтры и фильтры обёртки).
     */
    public function validateCode(string $code): ?string
    {
        $validatorPath = BASE_PATH . '/sandbox/ast_validator.py';
        if (!is_file($validatorPath)) {
            return null;
        }
        $allowedImports = [
            'math', 'random', 'datetime', 'itertools', 'collections',
            'functools', 'json', 're', 'string', 'statistics',
            'decimal', 'fractions', 'copy', 'pprint', 'operator', 'csv',
        ];
        $importsJson = json_encode($allowedImports);
        $descriptorspec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open(
            [$this->pythonCmd, '-I', '-S', '-B', $validatorPath, $importsJson],
            $descriptorspec,
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            error_log('[sandbox] AST validator: не удалось запустить процесс');
            return null;
        }
        fwrite($pipes[0], $code);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        $result = json_decode(trim((string)$stdout), true);
        if (!is_array($result)) {
            error_log('[sandbox] AST validator: неверный формат результата');
            return null;
        }
        if (!empty($result['ok'])) {
            return null;
        }
        return (string)($result['error'] ?? 'Unknown AST error');
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
     * по времени и (на Linux) по памяти.
     *
     * Про память честно: RLIMIT_AS ограничивает ВСЮ виртуальную память процесса,
     * которой интерпретатору нужно больше фактического потребления даже на простых
     * задачах. Поэтому RLIMIT_AS ставится с запасом (грубая страховка от runaway),
     * а вердикт memory_limit выставляется по измеренному пику RSS против
     * настроенного лимита задачи. На Windows изоляции по памяти нет (мягкий режим).
     */
    private function buildWrapperCode(float $timeLimit, int $memoryLimit, string $codeFile, string $stdinBase64, string $sentinel): string
    {
        // Nowdoc: PHP не интерполирует содержимое, Python-код остаётся как есть
        $template = <<<'PYWRAPPER'
import sys
import subprocess
import time
import os
import base64

try:
    import resource
except ImportError:
    resource = None

time_limit = __TIME_LIMIT__
memory_limit = __MEMORY_LIMIT__

# Входные параметры — встроенные литералы (не через argv): обёртка
# выполняется root-хелпером изоляции без пользовательских аргументов.
# Код ученика — файл (создаёт PHP, читает обёртка/потомок); stdin и
# результаты передаются через sentinel-маркеры в stdout обёртки,
# никаких файлов вывода (пользователь sandbox не может их там создавать).
CODE_FILE = __CODE_FILE__
STDIN_B64 = __STDIN_B64__

OUT_BEG = "%OUT_BEG%"
OUT_END = "%OUT_END%"
ERR_BEG = "%ERR_BEG%"
ERR_END = "%ERR_END%"


def meta_line(**kv):
    parts = ["##META##"]
    for k, v in kv.items():
        parts.append(k + "=" + str(v))
    print("##META beg##" + "\n".join(parts) + "##META end##")


# Запас сверх лимита задачи для RLIMIT_AS (виртуальная память > RSS)
RLIMIT_SLACK = 64 * 1024 * 1024


def peak_rss_bytes():
    """Пик RSS дочерних процессов в байтах (Linux/Unix) или None."""
    if resource is None:
        return None
    try:
        raw = resource.getrusage(resource.RUSAGE_CHILDREN).ru_maxrss
        # Linux: килобайты; macOS: байты
        return raw if sys.platform == 'darwin' else raw * 1024
    except Exception:
        return None


def apply_limits():
    if resource is None or os.name == 'nt':
        return
    ceiling = memory_limit * 1024 * 1024 + RLIMIT_SLACK
    try:
        resource.setrlimit(resource.RLIMIT_AS, (ceiling, ceiling))
    except Exception:
        pass


def emit(stdout_b, stderr_b, **meta):
    out_text = stdout_b.decode('utf-8', errors='replace')
    err_text = stderr_b.decode('utf-8', errors='replace')
    sys.stdout.write(OUT_BEG + out_text + OUT_END)
    sys.stdout.write(ERR_BEG + err_text + ERR_END)
    sys.stdout.flush()
    meta_line(**meta)


try:
    stdin_data = base64.b64decode(STDIN_B64).decode('utf-8', errors='replace')
    if stdin_data == '':
        stdin_data = '\n'

    start_time = time.time()

    proc = subprocess.Popen(
        [sys.executable, CODE_FILE],
        stdin=subprocess.PIPE,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        preexec_fn=(apply_limits if os.name != 'nt' else None),
    )

    try:
        stdout_data, stderr_data = proc.communicate(
            input=stdin_data.encode('utf-8'),
            timeout=time_limit
        )
        elapsed = time.time() - start_time
        exit_code = proc.returncode
        mem_peak = peak_rss_bytes()

        # memory_limit: пик RSS превысил лимит задачи либо дочерний процесс
        # упал с MemoryError (упёрся в RLIMIT_AS)
        limit_bytes = memory_limit * 1024 * 1024
        hit_memory = (mem_peak is not None and mem_peak > limit_bytes) or (
            exit_code != 0 and stderr_data is not None and b'MemoryError' in stderr_data
        )
        if hit_memory:
            emit(stdout_data, stderr_data, status='memory_limit', elapsed="{:.3f}".format(elapsed), memory=int(mem_peak or 0))
        else:
            emit(stdout_data, stderr_data, exit_code=exit_code, elapsed="{:.3f}".format(elapsed), memory=int(mem_peak or 0))

    except subprocess.TimeoutExpired:
        proc.kill()
        try:
            proc.wait(timeout=5)
        except Exception:
            pass
        elapsed = time.time() - start_time
        emit(b"", b"Time Limit Exceeded", status='time_limit', elapsed="{:.3f}".format(elapsed), memory=int(peak_rss_bytes() or 0))

except MemoryError:
    emit(b"", b"Memory Limit Exceeded", status='memory_limit', memory=int(peak_rss_bytes() or 0))

except Exception as e:
    emit(b"", f"System Error: {str(e)}".encode('utf-8', errors='replace'), status='error')
PYWRAPPER;

        return str_replace(
            ['__TIME_LIMIT__', '__MEMORY_LIMIT__', '__CODE_FILE__', '__STDIN_B64__'],
            [var_export($timeLimit, true), var_export($memoryLimit, true), var_export($codeFile, true), var_export($stdinBase64, true)],
            $template
        );
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
        // Создаём уникальный временный файл с кодом. При изолированном
        // запуске (root-хелпер) файл лежит в /tmp: обёртка и потомок
        // (пользователь sandbox) читают его оттуда. stdin и все результаты
        // передаются через sentinel-маркеры (см. buildWrapperCode), файлов
        // вывода не существует вовсе — пользователю sandbox нечего портить.
        $workspace = (PHP_OS_FAMILY !== 'Windows' && self::isolationHelper() !== null)
            ? sys_get_temp_dir()
            : $this->tempDir;
        $id = uniqid('run_', true);
        $codeFile = $workspace . '/' . $id . '.py';

        // Записываем код
        file_put_contents($codeFile, $code);

        // Генерируем обёртку. При изолированном запуске хелпер принимает скрипт
        // только из /tmp|/var/tmp|/var/lib/python-sandbox — пишем обёртку в
        // системный tmp; при legacy-запуске (без хелпера) остаётся SANDBOX_DIR.
        $sentinel = uniqid('sbx_', true);
        $stdinBase64 = base64_encode($input);
        $wrapperCode = $this->buildWrapperCode($timeLimit, $memoryLimit, $codeFile, $stdinBase64, $sentinel);
        if (PHP_OS_FAMILY !== 'Windows' && self::isolationHelper() !== null) {
            $wrapperTmp = tempnam(sys_get_temp_dir(), 'sandbox_wrapper_');
            if ($wrapperTmp === false) {
                $wrapperTmp = $this->tempDir . '/' . $id . '_wrapper.py';
            }
            $wrapperFile = $wrapperTmp;
        } else {
            $wrapperFile = $this->tempDir . '/' . $id . '_wrapper.py';
        }
        file_put_contents($wrapperFile, $wrapperCode);

        // Запускаем Python-обёртку через proc_open (без shell).
        // Если установлен изолирующий root-хелпер /usr/local/sbin/sandbox-python.run
        // (вызывной сценарий auth-web/python-web), обёртка выполняется в
        // отдельном net/pid namespace от непривилегированного пользователя
        // sandbox: процесс не может ни выйти в сеть, ни писать в webroot,
        // ни читать секреты сайта даже при обходе фильтров обёртки.
        $descriptorspec = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $helper = self::isolationHelper();
        if ($helper !== null) {
            // RLIMIT хелпер распространяется на родителя и каждого потомка
            // отдельно, поэтому даём запас вдвое (обёртка + код ученика).
            // Запас CPU/времени: +3 сек на старт интерпретатора обёртки —
            // внутренний таймаут кода ученика (в обёртке) не изменяется.
            $cmd = [
                '/usr/bin/sudo', '-n', '--', $helper,
                $wrapperFile,
                (string) ($memoryLimit * 2),
                (string) ((int) ceil($timeLimit) + 3),
                $this->pythonCmd,
            ];
            // Скрипт создаёт www-data (0600); хелпер читает его от sandbox-пользователя.
            @chmod($wrapperFile, 0644);
        } else {
            $cmd = [
                $this->pythonCmd,
                $wrapperFile,
            ];
        }

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
            // (только в legacy-режиме без хелпера: с хелпером путь shell-запуска отключён)
            if ($helper !== null) {
                $result = [
                    'output' => '',
                    'error' => 'Sandbox execution failure',
                    'status' => 'error',
                    'time' => $wallTime,
                    'memory' => 0,
                ];
                foreach ([$codeFile, $wrapperFile] as $file) {
                    if (file_exists($file)) {
                        @unlink($file);
                    }
                }
                return $result;
            }
            $escWrapper = escapeshellarg($wrapperFile);
            $escCode = escapeshellarg($codeFile);

            $shellCmd = "\"{$this->pythonCmd}\" {$escWrapper} {$escCode} 2>&1";
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

        // Ограничение размера без разрезания UTF-8 символа посередине
        $truncateUtf8 = function (string $s): string {
            if (strlen($s) <= MAX_OUTPUT_SIZE) {
                return $s;
            }
            return function_exists('mb_strcut')
                ? mb_strcut($s, 0, MAX_OUTPUT_SIZE, 'UTF-8')
                : substr($s, 0, MAX_OUTPUT_SIZE);
        };

        // ─── Разбор sentinel-канала обёртки (out/err + ##META##) ───
        // Первый arg = sentinel этого запуска, чтобы не путать вывод,
        // случайно похожий на маркеры, с реальными границами.
        $outBeg = "%OUT_BEG%";
        $outEnd = "%OUT_END%";
        $errBeg = "%ERR_BEG%";
        $errEnd = "%ERR_END%";

        $p1 = strpos($wrapperStdout, $outBeg);
        $p2 = strpos($wrapperStdout, $outEnd);
        $p3 = strpos($wrapperStdout, $errBeg);
        $p4 = strpos($wrapperStdout, $errEnd);

        $stdoutData = ($p1 !== false && $p2 !== false && $p1 < $p2)
            ? substr($wrapperStdout, $p1 + strlen($outBeg), $p2 - $p1 - strlen($outBeg))
            : '';
        $stderrData = ($p3 !== false && $p4 !== false && $p3 < $p4)
            ? substr($wrapperStdout, $p3 + strlen($errBeg), $p4 - $p3 - strlen($errBeg))
            : '';

        $result['output'] = $truncateUtf8($stdoutData);
        $result['error'] = $stderrData;

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

        // Если sentinel-канал пуст — обёртка упала до emit(): диагностика из stderr/stdout
        if ($stdoutData === '' && $stderrData === '' && !empty($wrapperStderr)) {
            $result['error'] = $cleanPyTraceback($wrapperStderr);
        } elseif ($stdoutData === '' && !empty($wrapperStdout)) {
            // stdout без sentinels и stderr пуст: суррогатный вывод как диагностика
            $result['error'] = $cleanPyTraceback('Wrapper stdout: ' . $wrapperStdout);
        }

        // ─── Разбор ##META## из stdout (рекомандации по elapsed/time/memory/status) ───
        if (preg_match('/##META beg##(.*?)##META end##/s', $wrapperStdout, $mm)) {
            $metaBlock = $mm[1];
            foreach (explode("\n", $metaBlock) as $line) {
                $line = trim($line);
                if ($line === '##META##' || $line === '' || strpos($line, '=') === false) continue;
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                if ($key === 'exit_code') {
                    $metaExitCode = (int) $value;
                    if ($metaExitCode === 0) {
                        $result['status'] = empty($result['error']) ? 'accepted' : 'runtime_error';
                    } else {
                        $result['status'] = 'runtime_error';
                    }
                }
                if ($key === 'elapsed') {
                    $result['time'] = (float) $value;
                }
                if ($key === 'memory') {
                    $result['memory'] = (int) $value;
                }
                if ($key === 'status') {
                    $result['status'] = $value;
                }
            }
        }

        // Очистка временных файлов
        foreach ([$codeFile, $wrapperFile] as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }

        return $result;
    }

    /**
     * Запускает проверку задачи в режиме «функции»: из кода ученика извлекается
     * функция с заданным именем, вызывается с аргументами теста, возвращаемое
     * значение сравнивается с ожидаемым.
     *
     * Используется готовый Python-runner, который выполняется через run()
     * (та же изоляция, таймаут и очистка временных файлов). Main-блок ученика
     * не исполняется — проверяется только сама функция (плюс топ-уровневые import).
     *
     * @param string $code Код решения на Python
     * @param string $functionName Имя обязательной функции
     * @param string $argsLiteral Аргументы вызова (Python-литералы через запятую, либо текст строки)
     * @param string $expectedLiteral Ожидаемый результат (Python-литерал, либо текст строки)
     * @param float $timeLimit Лимит времени в секундах
     * @param int $memoryLimit Лимит памяти в МБ
     * @return array ['status' => string, 'result' => string, 'expected' => string, 'error' => string, 'time' => float]
     */
    public function runFunctionTest(string $code, string $functionName, string $argsLiteral, string $expectedLiteral, float $timeLimit = 2.0, int $memoryLimit = 128): array
    {
        $codeJson = json_encode($code, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $fnJson = json_encode($functionName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $argsJson = json_encode($argsLiteral, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $expectedJson = json_encode($expectedLiteral, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $nl = "\n";

        $runner = "import ast" . $nl .
            "import sys" . $nl .
            "" . $nl .
            "source = {$codeJson}" . $nl .
            "fn_name = {$fnJson}" . $nl .
            "args_src = {$argsJson}" . $nl .
            "expected_src = {$expectedJson}" . $nl .
            "" . $nl .
            "# Запрещённые встроенные функции и корни модулей (дублируют PHP-пре-чек," . $nl .
            "# но ловят обходные пути: __import__, импорт после ';' и т.п.)." . $nl .
            "# Сообщения — только ASCII (кодировка stdout дочернего процесса не гарантирована)." . $nl .
            "BANNED_CALLS = {'__import__', 'eval', 'exec', 'compile', 'open'}" . $nl .
            "BANNED_ROOTS = {'os', 'subprocess', 'sys', 'shutil', 'ctypes', 'signal'," . $nl .
            "               'multiprocessing', 'threading', 'socket'}" . $nl .
            "" . $nl .
            "def forbid(what):" . $nl .
            '    print("VERDICT:FORBIDDEN")' . $nl .
            '    print("ERROR:" + what)' . $nl .
            "    sys.exit(0)" . $nl .
            "" . $nl .
            "def to_value(s):" . $nl .
            "    try:" . $nl .
            "        return ast.literal_eval(s)" . $nl .
            "    except Exception:" . $nl .
            "        return s" . $nl .
            "" . $nl .
            "try:" . $nl .
            "    tree = ast.parse(source)" . $nl .
            "    for n in ast.walk(tree):" . $nl .
            "        if isinstance(n, ast.Call) and isinstance(n.func, ast.Name) and n.func.id in BANNED_CALLS:" . $nl .
            '            forbid("FORBIDDEN_FUNCTION: " + n.func.id)' . $nl .
            "        if isinstance(n, ast.Import):" . $nl .
            "            for alias in n.names:" . $nl .
            "                root = alias.name.split('.')[0].lower()" . $nl .
            "                if root in BANNED_ROOTS:" . $nl .
            '                    forbid("FORBIDDEN_MODULE: " + root)' . $nl .
            "        if isinstance(n, ast.ImportFrom) and n.module:" . $nl .
            "            root = n.module.split('.')[0].lower()" . $nl .
            "            if root in BANNED_ROOTS:" . $nl .
            '                forbid("FORBIDDEN_MODULE: " + root)' . $nl .
            "    node = next((n for n in tree.body if isinstance(n, ast.FunctionDef) and n.name == fn_name), None)" . $nl .
            "    if node is None:" . $nl .
            '        print("VERDICT:NO_FUNCTION")' . $nl .
            "        sys.exit(0)" . $nl .
            "    imports = [n for n in tree.body if isinstance(n, (ast.Import, ast.ImportFrom))]" . $nl .
            "    ns = {}" . $nl .
            "    exec(compile(ast.Module(body=imports + [node], type_ignores=[]), '<solution>', 'exec'), ns)" . $nl .
            "    args = to_value(args_src)" . $nl .
            "    if not isinstance(args, tuple):" . $nl .
            "        args = (args,)" . $nl .
            "    expected = to_value(expected_src)" . $nl .
            "    result = ns[fn_name](*args)" . $nl .
            "    if isinstance(result, (int, float)) and isinstance(expected, (int, float)):" . $nl .
            "        ok = abs(float(result) - float(expected)) < 1e-6" . $nl .
            "    else:" . $nl .
            "        ok = result == expected" . $nl .
            '    print("VERDICT:" + ("OK" if ok else "FAIL"))' . $nl .
            '    print("RESULT:" + repr(result))' . $nl .
            '    print("EXPECTED:" + repr(expected))' . $nl .
            "except Exception as e:" . $nl .
            '    print("VERDICT:ERROR")' . $nl .
            '    print("ERROR:" + repr(e))' . $nl;

        $runResult = $this->run($runner, '', $timeLimit, $memoryLimit);

        $result = [
            'status' => 'wrong_answer',
            'result' => '',
            'expected' => '',
            'error' => '',
            'time' => (float)($runResult['time'] ?? 0),
        ];

        if (in_array(($runResult['status'] ?? ''), ['time_limit', 'memory_limit', 'runtime_error', 'error'], true)) {
            $result['status'] = $runResult['status'] === 'time_limit' ? 'time_limit'
                : ($runResult['status'] === 'memory_limit' ? 'memory_limit' : 'runtime_error');
            $result['error'] = $runResult['error'] ?? '';
            return $result;
        }

        $output = $runResult['output'] ?? '';
        $verdict = 'FAIL';
        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'VERDICT:')) {
                $verdict = substr($line, strlen('VERDICT:'));
            } elseif (str_starts_with($line, 'RESULT:')) {
                $result['result'] = substr($line, strlen('RESULT:'));
            } elseif (str_starts_with($line, 'EXPECTED:')) {
                $result['expected'] = substr($line, strlen('EXPECTED:'));
            } elseif (str_starts_with($line, 'ERROR:')) {
                $result['error'] = substr($line, strlen('ERROR:'));
            }
        }

        if ($verdict === 'NO_FUNCTION') {
            $result['status'] = 'no_function';
            $result['error'] = 'В коде не найдена функция ' . $functionName;
        } elseif ($verdict === 'FORBIDDEN') {
            // Запрещённый модуль/функция обнаружены на этапе AST-анализа.
            // Из раннера приходит служебная ASCII-строка — переводим в человекочитаемый текст
            $result['status'] = 'runtime_error';
            if (preg_match('/FORBIDDEN_MODULE:\s*(\S+)/', $result['error'], $fm)) {
                $result['error'] = "Запрещённый модуль: {$fm[1]} — его использование в решениях не допускается";
            } elseif (preg_match('/FORBIDDEN_FUNCTION:\s*(\S+)/', $result['error'], $ff)) {
                $result['error'] = "Использование встроенной функции {$ff[1]} в решениях не допускается";
            }
        } elseif ($verdict === 'OK') {
            $result['status'] = 'accepted';
        } elseif ($verdict === 'ERROR') {
            $result['status'] = 'runtime_error';
        } else {
            $result['status'] = 'wrong_answer';
            if (empty($result['error'])) {
                $result['error'] = 'Ожидалось ' . ($result['expected'] !== '' ? $result['expected'] : $expectedLiteral)
                    . ', получено ' . ($result['result'] !== '' ? $result['result'] : '(без результата)');
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
        // Дорогой поиск (shell-процессы) выполняем один раз за процесс PHP
        if (self::$lintCmdResolved) {
            return self::$cachedLintCmd;
        }
        self::$lintCmdResolved = true;

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
                self::$cachedLintCmd = $cmd;
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