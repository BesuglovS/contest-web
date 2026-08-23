<?php
// Защита от прямого доступа к файлу — только через фронт-контроллер (index.php)
if (!defined('BASE_PATH')) {
    http_response_code(403);
    exit('Forbidden');
}

$pageTitle = 'Управление задачами';
$db = Database::getInstance();
$message = '';
$error = '';

// Обработка действий
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // CSRF-защита обязательна для всех POST-действий
    if (!validateCsrf()) {
        $error = 'Недействительный CSRF-токен. Обновите страницу и повторите действие.';
        $action = null;
    }

    if ($action === 'create') {
        $title = trim($_POST['title']);
        $given = $_POST['given'] ?? '';
        $inputFormat = $_POST['input_format'] ?? '';
        $outputFormat = $_POST['output_format'] ?? '';
        $timeLimit = (float) ($_POST['time_limit'] ?? 2.0);
        $memoryLimit = (int) ($_POST['memory_limit'] ?? 128);
        $checkMode = ($_POST['check_mode'] ?? 'program') === 'function' ? 'function' : 'program';
        $functionName = $checkMode === 'function' ? trim($_POST['function_name'] ?? '') : '';

        if ($title) {
            $stmt = $db->prepare("INSERT INTO tasks (title, given, input_format, output_format, time_limit, memory_limit, check_mode, function_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$title, $given, $inputFormat, $outputFormat, $timeLimit, $memoryLimit, $checkMode, $functionName]);
            $taskId = $db->lastInsertId();

            // Сохраняем тесты
            $testInputs = $_POST['test_input'] ?? [];
            $testOutputs = $_POST['test_output'] ?? [];
            $testPublic = $_POST['test_is_public'] ?? [];

            foreach ($testInputs as $idx => $input) {
                $output = $testOutputs[$idx] ?? '';
                $isPublic = in_array((string) $idx, $testPublic) ? 1 : 0;
                $stmt = $db->prepare("INSERT INTO tests (task_id, test_number, input, expected_output, is_public) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$taskId, $idx + 1, $input, $output, $isPublic]);
            }

            $message = 'Задача создана';
        } else {
            $error = 'Введите название задачи';
        }
    }

    if ($action === 'update') {
        $id = (int) $_POST['id'];
        $title = trim($_POST['title']);
        $given = $_POST['given'] ?? '';
        $inputFormat = $_POST['input_format'] ?? '';
        $outputFormat = $_POST['output_format'] ?? '';
        $timeLimit = (float) ($_POST['time_limit'] ?? 2.0);
        $memoryLimit = (int) ($_POST['memory_limit'] ?? 128);
        $checkMode = ($_POST['check_mode'] ?? 'program') === 'function' ? 'function' : 'program';
        $functionName = $checkMode === 'function' ? trim($_POST['function_name'] ?? '') : '';

        $stmt = $db->prepare("UPDATE tasks SET title=?, given=?, input_format=?, output_format=?, time_limit=?, memory_limit=?, check_mode=?, function_name=? WHERE id=?");
        $stmt->execute([$title, $given, $inputFormat, $outputFormat, $timeLimit, $memoryLimit, $checkMode, $functionName, $id]);

        // Сохраняем тесты, по возможности не пересоздавая записи: UPDATE для
        // существующих (по позиции), INSERT для новых. Так ID тестов остаются
        // стабильными, и исторические результаты посылок остаются осмысленными.
        // Удаляются только реально лишние хвостовые тесты.
        $stmt = $db->prepare("SELECT * FROM tests WHERE task_id=? ORDER BY test_number");
        $stmt->execute([$id]);
        $existingTests = $stmt->fetchAll();

        $updateStmt = $db->prepare("UPDATE tests SET input = ?, expected_output = ?, is_public = ? WHERE id = ?");
        $insertStmt = $db->prepare("INSERT INTO tests (task_id, test_number, input, expected_output, is_public) VALUES (?, ?, ?, ?, ?)");

        $testInputs = $_POST['test_input'] ?? [];
        $testOutputs = $_POST['test_output'] ?? [];
        $testPublic = $_POST['test_is_public'] ?? [];

        $keptIds = [];
        foreach ($testInputs as $idx => $input) {
            $output = $testOutputs[$idx] ?? '';
            $isPublic = in_array((string) $idx, $testPublic) ? 1 : 0;

            if (isset($existingTests[$idx])) {
                $updateStmt->execute([$input, $output, $isPublic, $existingTests[$idx]['id']]);
                $keptIds[] = (int) $existingTests[$idx]['id'];
            } else {
                $insertStmt->execute([$id, $idx + 1, $input, $output, $isPublic]);
            }
        }

        if (!empty($keptIds)) {
            $placeholders = implode(',', array_fill(0, count($keptIds), '?'));
            $db->prepare("DELETE FROM tests WHERE task_id = ? AND id NOT IN ($placeholders)")
               ->execute(array_merge([$id], $keptIds));
        } else {
            $db->prepare("DELETE FROM tests WHERE task_id=?")->execute([$id]);
        }

        $message = 'Задача обновлена';
    }

    if ($action === 'delete') {
        $id = (int) $_POST['id'];
        $db->prepare("DELETE FROM tasks WHERE id=?")->execute([$id]);
        $message = 'Задача удалена';
    }
}

$tasks = $db->query("SELECT * FROM tasks ORDER BY id DESC")->fetchAll();

$editTask = null;
$editTests = [];
if (isset($_GET['edit'])) {
    if ($_GET['edit'] === 'new') {
        $editTask = ['_new' => true]; // создание новой задачи (truthy, но без ключа 'id')
        $editTests = [];
    } else {
        $taskId = (int) $_GET['edit'];
        $stmt = $db->prepare("SELECT * FROM tasks WHERE id=?");
        $stmt->execute([$taskId]);
        $editTask = $stmt->fetch();

        $stmt = $db->prepare("SELECT * FROM tests WHERE task_id=? ORDER BY test_number");
        $stmt->execute([$taskId]);
        $editTests = $stmt->fetchAll() ?: [];
    }
}

ob_start();
?>

<h1>Управление задачами</h1>

<?php $activePage = 'tasks'; require BASE_PATH . '/templates/admin_nav.php'; ?>

<?php if ($message): ?>
    <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<a href="?page=admin-tasks<?= $editTask ? '' : '&edit=new' ?>" class="btn btn-primary mb-20">
    <?= $editTask ? '← К списку' : '+ Новая задача' ?>
</a>

<?php if (!$editTask): ?>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Название</th>
                <th>Лимит времени</th>
                <th>Лимит памяти</th>
                <th>Действия</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($tasks as $task): ?>
            <tr>
                <td><?= $task['id'] ?></td>
                <td><?= htmlspecialchars($task['title']) ?></td>
                <td><?= $task['time_limit'] ?> сек</td>
                <td><?= $task['memory_limit'] ?> МБ</td>
                <td>
                    <a href="?page=admin-tasks&edit=<?= $task['id'] ?>" class="btn btn-sm">Ред.</a>
                    <form method="POST" style="display:inline" onsubmit="return confirm('Удалить задачу вместе со ВСЕМИ решениями учеников по ней? Действие необратимо.')">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= $task['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-danger">Удалить</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php else: ?>
    <form method="POST" class="card">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="<?= isset($editTask['id']) ? 'update' : 'create' ?>">
        <?php if (isset($editTask['id'])): ?>
            <input type="hidden" name="id" value="<?= $editTask['id'] ?>">
        <?php endif; ?>

        <div class="form-group">
            <label for="title">Название задачи</label>
            <input type="text" id="title" name="title" required value="<?= htmlspecialchars($editTask['title'] ?? '') ?>">
        </div>

        <div class="form-group">
            <label for="given">Условие задачи (HTML)</label>
            <textarea id="given" name="given" style="min-height:200px;"><?= htmlspecialchars($editTask['given'] ?? '') ?></textarea>
        </div>

        <div class="form-group">
            <label for="input_format">Формат входных данных</label>
            <textarea id="input_format" name="input_format" style="min-height:80px;"><?= htmlspecialchars($editTask['input_format'] ?? '') ?></textarea>
        </div>

        <div class="form-group">
            <label for="output_format">Формат выходных данных</label>
            <textarea id="output_format" name="output_format" style="min-height:80px;"><?= htmlspecialchars($editTask['output_format'] ?? '') ?></textarea>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
            <div class="form-group">
                <label for="time_limit">Лимит времени (сек)</label>
                <input type="number" id="time_limit" name="time_limit" step="0.1" value="<?= $editTask['time_limit'] ?? 2.0 ?>">
            </div>
            <div class="form-group">
                <label for="memory_limit">Лимит памяти (МБ)</label>
                <input type="number" id="memory_limit" name="memory_limit" value="<?= $editTask['memory_limit'] ?? 128 ?>">
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
            <div class="form-group">
                <label for="check_mode">Тип проверки</label>
                <select id="check_mode" name="check_mode" onchange="toggleFunctionName()">
                    <option value="program" <?= ($editTask['check_mode'] ?? 'program') === 'program' ? 'selected' : '' ?>>Программа (ввод/вывод)</option>
                    <option value="function" <?= ($editTask['check_mode'] ?? 'program') === 'function' ? 'selected' : '' ?>>Функция (прямой вызов)</option>
                </select>
                <p style="color: var(--text-muted); font-size: 0.85em; margin-top:4px;">
                    «Функция» — тест извлекает функцию из кода, вызывает её с аргументами и сравнивает возвращаемое значение.
                </p>
            </div>
            <div class="form-group" id="function-name-group" <?= ($editTask['check_mode'] ?? 'program') === 'function' ? '' : 'style="display:none;"' ?>>
                <label for="function_name">Имя функции</label>
                <input type="text" id="function_name" name="function_name" value="<?= htmlspecialchars($editTask['function_name'] ?? '') ?>" placeholder="например, greet">
                <p style="color: var(--text-muted); font-size: 0.85em; margin-top:4px;">Функция должна быть определена в коде на верхнем уровне.</p>
            </div>
        </div>

        <h3 class="mt-20">Тесты</h3>
        <p style="color: var(--text-muted); font-size: 0.9em;">Добавьте тесты. Отметьте галочкой публичные тесты (первые 3 будут видны пользователям).</p>

        <?php
        $isFunctionMode = ($editTask['check_mode'] ?? 'program') === 'function';
        $testInputLabel = $isFunctionMode ? 'Аргументы функции' : 'Входные данные';
        $testOutputLabel = $isFunctionMode ? 'Ожидаемый результат' : 'Ожидаемый вывод';
        ?>
        <p style="color: var(--text-muted); font-size: 0.85em;">
            <?= $isFunctionMode
                ? 'Аргументы — Python-литералы через запятую (например, <code>5, 3</code> или <code>Анна</code>). Ожидаемый результат — значение (например, <code>8</code>, <code>Привет, Анна!</code>, <code>True</code>). Строки можно писать без кавычек.'
                : 'Для режима «Программа» задаются входные и ожидаемые выходные данные.' ?>
        </p>

        <div id="tests-container">
            <?php
            $existingTests = !empty($editTests) ? $editTests : [['input' => '', 'expected_output' => '', 'is_public' => 0]];
            foreach ($existingTests as $idx => $test):
            ?>
            <div class="test-entry card" style="margin-bottom:12px; padding:16px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                    <strong>Тест #<?= $idx + 1 ?></strong>
                    <label>
                        <input type="checkbox" name="test_is_public[]" value="<?= $idx ?>" <?= $test['is_public'] ? 'checked' : '' ?>>
                        Публичный (виден пользователю)
                    </label>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <div>
                        <label style="display:block; font-weight:600; margin-bottom:4px;"><?= $testInputLabel ?></label>
                        <textarea name="test_input[]" style="min-height:80px;"><?= htmlspecialchars($test['input'] ?? '') ?></textarea>
                    </div>
                    <div>
                        <label style="display:block; font-weight:600; margin-bottom:4px;"><?= $testOutputLabel ?></label>
                        <textarea name="test_output[]" style="min-height:80px;"><?= htmlspecialchars($test['expected_output'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <button type="button" class="btn" onclick="addTest()">+ Добавить тест</button>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= isset($editTask['id']) ? 'Сохранить' : 'Создать задачу' ?></button>
            <a href="?page=admin-tasks" class="btn">Отмена</a>
        </div>
    </form>

    <script>
    let testCount = <?= count($existingTests) ?>;
    let checkMode = '<?= ($editTask['check_mode'] ?? 'program') === 'function' ? 'function' : 'program' ?>';
    function toggleFunctionName() {
        const mode = document.getElementById('check_mode').value;
        checkMode = mode;
        document.getElementById('function-name-group').style.display = mode === 'function' ? '' : 'none';
        document.querySelectorAll('#tests-container .test-entry').forEach(function(entry, i) {
            const labels = entry.querySelectorAll('label');
            if (labels.length >= 2) {
                labels[0].textContent = mode === 'function' ? 'Аргументы функции' : 'Входные данные';
                labels[1].textContent = mode === 'function' ? 'Ожидаемый результат' : 'Ожидаемый вывод';
            }
        });
    }
    function addTest() {
        const container = document.getElementById('tests-container');
        const div = document.createElement('div');
        div.className = 'test-entry card';
        div.style.cssText = 'margin-bottom:12px; padding:16px;';
        const inputLabel = checkMode === 'function' ? 'Аргументы функции' : 'Входные данные';
        const outputLabel = checkMode === 'function' ? 'Ожидаемый результат' : 'Ожидаемый вывод';
        div.innerHTML = `
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                <strong>Тест #${testCount + 1}</strong>
                <label>
                    <input type="checkbox" name="test_is_public[]" value="${testCount}">
                    Публичный (виден пользователю)
                </label>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <div>
                    <label style="display:block; font-weight:600; margin-bottom:4px;">${inputLabel}</label>
                    <textarea name="test_input[]" style="min-height:80px;"></textarea>
                </div>
                <div>
                    <label style="display:block; font-weight:600; margin-bottom:4px;">${outputLabel}</label>
                    <textarea name="test_output[]" style="min-height:80px;"></textarea>
                </div>
            </div>
        `;
        container.appendChild(div);
        testCount++;
    }
    </script>
<?php endif; ?>

<?php
$content = ob_get_clean();
require BASE_PATH . '/templates/layout.php';