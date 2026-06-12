<?php
$pageTitle = 'Импорт задач из JSON';
$db = Database::getInstance();
$message = '';
$error = '';
$previewData = null;

// ---------------------------------------------------------------------------
// Вспомогательные функции валидации
// ---------------------------------------------------------------------------

/**
 * Валидирует и нормализует задачу из импортированных данных.
 * Возвращает массив с полем 'valid' => bool и 'errors' (если невалидна).
 */
function validateTask(array $task, int $index): array
{
    $errors = [];

    // title
    if (empty($task['title']) || !is_string($task['title'])) {
        $errors[] = "Задача #{$index}: отсутствует или пустое поле 'title'.";
    }

    // given
    if (!isset($task['given']) || !is_string($task['given'])) {
        $task['given'] = '';
    }

    // input_format
    if (!isset($task['input_format']) || !is_string($task['input_format'])) {
        $task['input_format'] = '';
    }

    // output_format
    if (!isset($task['output_format']) || !is_string($task['output_format'])) {
        $task['output_format'] = '';
    }

    // time_limit
    $timeLimit = $task['time_limit'] ?? 1.0;
    if (!is_numeric($timeLimit) || $timeLimit <= 0 || $timeLimit > 60) {
        $errors[] = "Задача #{$index}: 'time_limit' должен быть положительным числом не более 60.";
    }

    // memory_limit
    $memoryLimit = $task['memory_limit'] ?? 64;
    if (!is_numeric($memoryLimit) || $memoryLimit <= 0 || $memoryLimit > 1024) {
        $errors[] = "Задача #{$index}: 'memory_limit' должен быть положительным целым числом не более 1024.";
    }

    // tests
    if (!isset($task['tests']) || !is_array($task['tests']) || count($task['tests']) === 0) {
        $errors[] = "Задача #{$index}: отсутствует или пуст массив 'tests'.";
    }

    $validatedTests = [];
    if (isset($task['tests']) && is_array($task['tests'])) {
        foreach ($task['tests'] as $tIdx => $test) {
            if (!is_array($test)) {
                $errors[] = "Задача #{$index}, тест #{$tIdx}: ожидается объект.";
                continue;
            }
            $testInput = $test['input'] ?? '';
            $testOutput = $test['output'] ?? '';
            $testPublic = (int) ($test['is_public'] ?? 0);
            if ($testPublic !== 0 && $testPublic !== 1) {
                $testPublic = 0;
            }
            $validatedTests[] = [
                'input'     => (string) $testInput,
                'output'    => (string) $testOutput,
                'is_public' => $testPublic,
            ];
        }
    }

    // Если нет ни одного валидного теста — ошибка
    if (count($validatedTests) === 0) {
        $errors[] = "Задача #{$index}: должен быть хотя бы один тест.";
    }

    $valid = count($errors) === 0;

    return [
        'valid'           => $valid,
        'title'           => (string) ($task['title'] ?? ''),
        'given'           => (string) ($task['given'] ?? ''),
        'input_format'    => (string) ($task['input_format'] ?? ''),
        'output_format'   => (string) ($task['output_format'] ?? ''),
        'time_limit'      => (float) ($timeLimit ?: 1.0),
        'memory_limit'    => (int) ($memoryLimit ?: 64),
        'tests'           => $validatedTests,
        'errors'          => $errors,
    ];
}

/**
 * Импортирует валидные задачи в базу данных.
 * tasks_data — массив результатов validateTask (только valid === true).
 * groupId — ID группы задач (0 = не привязывать).
 */
function importTasks(array $tasksData, int $groupId, PDO $db): array
{
    $stmtTask = $db->prepare("INSERT INTO tasks (title, given, input_format, output_format, time_limit, memory_limit) VALUES (?, ?, ?, ?, ?, ?)");
    $stmtTest = $db->prepare("INSERT INTO tests (task_id, test_number, input, expected_output, is_public) VALUES (?, ?, ?, ?, ?)");

    // Если группа указана — подготовим запрос на привязку
    $stmtLink = null;
    if ($groupId > 0) {
        // Определим текущий максимальный sort_order в группе, чтобы новые задачи добавить в конец
        $stmtMaxOrder = $db->prepare("SELECT COALESCE(MAX(sort_order), 0) FROM task_to_groups WHERE task_group_id = ?");
        $stmtMaxOrder->execute([$groupId]);
        $nextOrder = (int) $stmtMaxOrder->fetchColumn() + 1;

        $stmtLink = $db->prepare("INSERT OR IGNORE INTO task_to_groups (task_id, task_group_id, sort_order) VALUES (?, ?, ?)");
    }

    $results = [];
    $successCount = 0;
    $failCount   = 0;

    foreach ($tasksData as $task) {
        try {
            $db->beginTransaction();

            $stmtTask->execute([
                $task['title'],
                $task['given'],
                $task['input_format'],
                $task['output_format'],
                $task['time_limit'],
                $task['memory_limit'],
            ]);
            $taskId = $db->lastInsertId();

            foreach ($task['tests'] as $testNum => $test) {
                $stmtTest->execute([
                    $taskId,
                    $testNum + 1,
                    $test['input'],
                    $test['output'],
                    $test['is_public'],
                ]);
            }

            if ($stmtLink !== null) {
                $stmtLink->execute([$taskId, $groupId, $nextOrder]);
                $nextOrder++;
            }

            $db->commit();

            $results[] = [
                'title'  => $task['title'],
                'status' => 'success',
                'id'     => $taskId,
            ];
            $successCount++;
        } catch (Exception $e) {
            $db->rollBack();
            $results[] = [
                'title'  => $task['title'],
                'status' => 'error',
                'error'  => $e->getMessage(),
            ];
            $failCount++;
        }
    }

    return [
        'results'      => $results,
        'successCount' => $successCount,
        'failCount'    => $failCount,
    ];
}

// ---------------------------------------------------------------------------
// Обработка запросов
// ---------------------------------------------------------------------------

// Загрузка файла и показ предпросмотра
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'preview') {
    if (!isset($_FILES['json_file']) || $_FILES['json_file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Ошибка загрузки файла. Попробуйте ещё раз.';
    } else {
        $raw = file_get_contents($_FILES['json_file']['tmp_name']);
        if ($raw === false || trim($raw) === '') {
            $error = 'Файл пуст или не удалось прочитать.';
        } else {
            $data = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $error = 'Ошибка парсинга JSON: ' . json_last_error_msg();
            } elseif (!is_array($data)) {
                $error = 'Корневой элемент должен быть массивом задач.';
            } else {
                $previewData = [];
                foreach ($data as $idx => $task) {
                    $previewData[] = validateTask($task, $idx + 1);
                }

                $validCount   = count(array_filter($previewData, fn($t) => $t['valid']));
                $invalidCount = count($previewData) - $validCount;

                if ($validCount === 0) {
                    $error = 'Нет ни одной валидной задачи для импорта.';
                } else {
                    $message = "Файл загружен: всего задач — " . count($previewData)
                             . ", валидных — {$validCount}"
                             . ($invalidCount > 0 ? ", с ошибками — {$invalidCount}." : ".");
                }
            }
        }
    }
}

// Непосредственный импорт (POST с action=import)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import') {
    $jsonRaw = $_POST['json_raw'] ?? '';
    $groupId = (int) ($_POST['group_id'] ?? 0);

    if (trim($jsonRaw) === '') {
        $error = 'Нет данных для импорта. Пожалуйста, загрузите файл сначала.';
    } else {
        $data = json_decode($jsonRaw, true);
        if (!is_array($data)) {
            $error = 'Ошибка данных импорта. Пожалуйста, загрузите файл заново.';
        } else {
            $validated = [];
            foreach ($data as $idx => $task) {
                $v = validateTask($task, $idx + 1);
                if ($v['valid']) {
                    $validated[] = $v;
                }
            }

            if (count($validated) === 0) {
                $error = 'Нет валидных задач для импорта.';
            } else {
                $result = importTasks($validated, $groupId, $db);
                $message = "Импорт завершён: успешно — {$result['successCount']}";
                if ($result['failCount'] > 0) {
                    $error = "Ошибок импорта: {$result['failCount']}";
                }
                $previewData = []; // очищаем предпросмотр после импорта
            }
        }
    }
}

// ---------------------------------------------------------------------------
// Получаем список групп задач для выпадающего списка
// ---------------------------------------------------------------------------
$taskGroups = $db->query("SELECT id, name FROM task_groups ORDER BY name")->fetchAll();

ob_start();
?>

<h1>Импорт задач из JSON</h1>

<div class="admin-nav">
    <a href="?page=admin">Дашборд</a>
    <a href="?page=admin-users">Пользователи</a>
    <a href="?page=admin-groups">Группы</a>
    <a href="?page=admin-tasks">Задачи</a>
    <a href="?page=admin-task-groups">Группы задач</a>
    <a href="?page=admin-contests">Контесты</a>
    <a href="?page=admin-submissions">Решения</a>
    <a href="?page=admin-import-tasks" class="active">Импорт задач</a>
</div>

<?php if ($message): ?>
    <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if ($previewData === null): ?>
    <!-- ------------------------- ФОРМА ЗАГРУЗКИ ФАЙЛА ------------------------- -->
    <div class="card" style="margin-bottom:20px;">
        <h2>1. Выберите JSON-файл</h2>
        <p style="color:var(--text-muted);">
            Загрузите файл в формате <code>UTF-8</code> без BOM.
            <a href="?page=admin-import-format" target="_blank">Описание формата</a>
        </p>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="preview">

            <div class="form-group">
                <label for="json_file">JSON-файл с задачами</label>
                <input type="file" id="json_file" name="json_file" accept=".json,application/json" required>
            </div>

            <button type="submit" class="btn btn-primary">
                📄 Загрузить и предпросмотреть
            </button>
        </form>
    </div>

    <!-- Документация прямо на странице -->
    <div class="card" style="margin-bottom:20px;">
        <h3>Краткий формат JSON</h3>
        <pre style="background:var(--bg-secondary,#f4f4f5); padding:16px; border-radius:6px; overflow-x:auto; font-size:0.9em;"><code>[
    {
        "title": "Название задачи",
        "given": "<p>HTML-условие</p>",
        "input_format": "<p>Формат ввода</p>",
        "output_format": "<p>Формат вывода</p>",
        "time_limit": 1.0,
        "memory_limit": 64,
        "tests": [
            { "input": "2\n3", "output": "5", "is_public": 1 },
            ...
        ]
    },
    ...
]</code></pre>
        <p><a href="?page=admin-import-format" target="_blank">📖 Полная документация формата импорта</a></p>
    </div>

<?php elseif (!empty($previewData)): ?>
    <!-- ------------------------- ПРЕДПРОСМОТР ------------------------- -->
    <?php
    $validTasks   = array_filter($previewData, fn($t) => $t['valid']);
    $invalidTasks = array_filter($previewData, fn($t) => !$t['valid']);
    $importJson   = json_encode(
        array_map(function ($t) {
            return [
                'title'         => $t['title'],
                'given'         => $t['given'],
                'input_format'  => $t['input_format'],
                'output_format' => $t['output_format'],
                'time_limit'    => $t['time_limit'],
                'memory_limit'  => $t['memory_limit'],
                'tests'         => $t['tests'],
            ];
        }, $validTasks),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    ?>

    <form method="POST" onsubmit="return confirm('Импортировать выбранные задачи в базу данных?');">
        <input type="hidden" name="action" value="import">
        <input type="hidden" name="json_raw" value="<?= htmlspecialchars($importJson) ?>">

        <div class="card" style="margin-bottom:20px;">
            <h2>2. Настройки импорта</h2>

            <div class="form-group">
                <label for="group_id">Привязать к группе задач (опционально)</label>
                <select id="group_id" name="group_id">
                    <option value="0">— Без группы —</option>
                    <?php foreach ($taskGroups as $g): ?>
                        <option value="<?= $g['id'] ?>"><?= htmlspecialchars($g['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <p style="color:var(--text-muted); font-size:0.9em; margin-top:4px;">
                    Если выбрана группа, задачи будут добавлены в конец этой группы.
                </p>
            </div>

            <button type="submit" class="btn btn-primary" style="font-size:1.1em; padding:12px 32px;">
                ⚡ Импортировать <?= count($validTasks) ?> задач
            </button>
        </div>
    </form>

    <!-- Валидные задачи -->
    <?php if (count($validTasks) > 0): ?>
        <h3>✅ Валидные задачи (<?= count($validTasks) ?>)</h3>
        <?php foreach ($validTasks as $idx => $task): ?>
            <?php $publicCount = count(array_filter($task['tests'], fn($t) => $t['is_public'])); ?>
            <?php $hiddenCount = count($task['tests']) - $publicCount; ?>
            <div class="card" style="margin-bottom:16px; padding:20px; border-left:4px solid var(--success);">
                <h4 style="margin-top:0; color:var(--success);">
                    Задача <?= $idx + 1 ?>: <?= htmlspecialchars($task['title']) ?>
                </h4>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:12px;">
                    <div><strong>Лимит времени:</strong> <?= $task['time_limit'] ?> сек</div>
                    <div><strong>Лимит памяти:</strong> <?= $task['memory_limit'] ?> МБ</div>
                </div>
                <div style="margin-bottom:8px;">
                    <strong>Тестов:</strong> <?= count($task['tests']) ?>
                    (<?= $publicCount ?> публичных, <?= $hiddenCount ?> скрытых)
                </div>
                <details style="margin-bottom:4px;">
                    <summary style="cursor:pointer; color:var(--primary);">Условие</summary>
                    <div style="background:var(--bg-secondary,#f4f4f5); padding:12px; border-radius:6px; margin-top:4px;">
                        <?= $task['given'] ?: '<em>пусто</em>' ?>
                    </div>
                </details>
                <details style="margin-bottom:4px;">
                    <summary style="cursor:pointer; color:var(--primary);">Формат ввода / вывода</summary>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-top:4px;">
                        <div style="background:var(--bg-secondary,#f4f4f5); padding:8px; border-radius:6px;">
                            <strong>Ввод:</strong><br><?= $task['input_format'] ?: '<em>пусто</em>' ?>
                        </div>
                        <div style="background:var(--bg-secondary,#f4f4f5); padding:8px; border-radius:6px;">
                            <strong>Вывод:</strong><br><?= $task['output_format'] ?: '<em>пусто</em>' ?>
                        </div>
                    </div>
                </details>
                <details>
                    <summary style="cursor:pointer; color:var(--primary);">Тесты (<?= count($task['tests']) ?>)</summary>
                    <table style="margin-top:8px;">
                        <thead><tr><th>#</th><th>Входные данные</th><th>Ожидаемый вывод</th><th>Статус</th></tr></thead>
                        <tbody>
                            <?php foreach ($task['tests'] as $tNum => $test): ?>
                            <tr>
                                <td><?= $tNum + 1 ?></td>
                                <td><code><?= htmlspecialchars($test['input']) ?></code></td>
                                <td><code><?= htmlspecialchars($test['output']) ?></code></td>
                                <td><?= $test['is_public'] ? '✓ Публичный' : '<span style="color:var(--text-muted);">🔒 Скрытый</span>' ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </details>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <!-- Задачи с ошибками -->
    <?php if (count($invalidTasks) > 0): ?>
        <h3>❌ Задачи с ошибками (<?= count($invalidTasks) ?>)</h3>
        <?php foreach ($invalidTasks as $idx => $task): ?>
            <div class="card" style="margin-bottom:12px; padding:16px; border-left:4px solid var(--danger);">
                <h4 style="margin-top:0; color:var(--danger);">
                    Задача <?= $idx + 1 ?>: <?= htmlspecialchars($task['title'] ?: '(без названия)') ?>
                </h4>
                <ul style="color:var(--danger); margin:0;">
                    <?php foreach ($task['errors'] as $err): ?>
                        <li><?= htmlspecialchars($err) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <p><a href="?page=admin-import-tasks" class="btn">← Загрузить другой файл</a></p>

<?php endif; ?>

<?php if (empty($previewData) && empty($message) && empty($error)): ?>
    <!-- Ничего не загружено и нет сообщений — показываем пустую информацию -->
    <div class="card" style="border-left:4px solid var(--primary);">
        <h3>📋 Формат импорта</h3>
        <p>Загрузите JSON-файл, содержащий массив задач. Каждая задача включает:</p>
        <ul>
            <li><strong>title</strong> — название задачи (обязательно)</li>
            <li><strong>given</strong> — условие задачи в HTML (обязательно)</li>
            <li><strong>input_format</strong> — описание формата ввода (обязательно)</li>
            <li><strong>output_format</strong> — описание формата вывода (обязательно)</li>
            <li><strong>time_limit</strong> — лимит времени, сек (опционально, по умолч. 1.0)</li>
            <li><strong>memory_limit</strong> — лимит памяти, МБ (опционально, по умолч. 64)</li>
            <li><strong>tests</strong> — массив тестов, каждый с полями <code>input</code>, <code>output</code>, <code>is_public</code> (0 или 1)</li>
        </ul>
        <p><a href="?page=admin-import-format" target="_blank">📖 Полная документация с примерами</a></p>
    </div>
<?php endif; ?>

<?php
$content = ob_get_clean();
require BASE_PATH . '/templates/layout.php';