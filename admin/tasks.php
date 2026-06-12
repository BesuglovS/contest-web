<?php
$pageTitle = 'Управление задачами';
$db = Database::getInstance();
$message = '';
$error = '';

// Обработка действий
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $title = trim($_POST['title']);
        $given = $_POST['given'] ?? '';
        $inputFormat = $_POST['input_format'] ?? '';
        $outputFormat = $_POST['output_format'] ?? '';
        $timeLimit = (float) ($_POST['time_limit'] ?? 2.0);
        $memoryLimit = (int) ($_POST['memory_limit'] ?? 128);

        if ($title) {
            $stmt = $db->prepare("INSERT INTO tasks (title, given, input_format, output_format, time_limit, memory_limit) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$title, $given, $inputFormat, $outputFormat, $timeLimit, $memoryLimit]);
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

        $stmt = $db->prepare("UPDATE tasks SET title=?, given=?, input_format=?, output_format=?, time_limit=?, memory_limit=? WHERE id=?");
        $stmt->execute([$title, $given, $inputFormat, $outputFormat, $timeLimit, $memoryLimit, $id]);

        // Удаляем старые тесты и вставляем новые
        $db->prepare("DELETE FROM tests WHERE task_id=?")->execute([$id]);

        $testInputs = $_POST['test_input'] ?? [];
        $testOutputs = $_POST['test_output'] ?? [];
        $testPublic = $_POST['test_is_public'] ?? [];

        foreach ($testInputs as $idx => $input) {
            $output = $testOutputs[$idx] ?? '';
            $isPublic = in_array((string) $idx, $testPublic) ? 1 : 0;
            $stmt = $db->prepare("INSERT INTO tests (task_id, test_number, input, expected_output, is_public) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$id, $idx + 1, $input, $output, $isPublic]);
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

<?php
// Проверка целостности схемы БД
$schemaChecks = [];
// Колонка execution_time в submissions
$cols = $db->query("PRAGMA table_info(submissions)")->fetchAll();
$hasExecTime = false;
foreach ($cols as $col) { if ($col['name'] === 'execution_time') { $hasExecTime = true; break; } }
if (!$hasExecTime) $schemaChecks[] = ['col' => 'execution_time', 'table' => 'submissions', 'sql' => 'ALTER TABLE submissions ADD COLUMN execution_time REAL DEFAULT 0'];

// Обработка нажатия «Исправить схему»
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'fix_schema') {
    foreach ($schemaChecks as $check) {
        try {
            $db->exec($check['sql']);
            $message = "Схема БД исправлена: добавлена колонка {$check['col']} в таблицу {$check['table']}.";
        } catch (Exception $e) {
            $error = "Ошибка исправления схемы: " . $e->getMessage();
        }
    }
    // Перезагружаем статус проверок
    $schemaChecks = [];
    $cols = $db->query("PRAGMA table_info(submissions)")->fetchAll();
    $hasExecTime = false;
    foreach ($cols as $col) { if ($col['name'] === 'execution_time') { $hasExecTime = true; break; } }
    if (!$hasExecTime) $schemaChecks[] = ['col' => 'execution_time', 'table' => 'submissions', 'sql' => 'ALTER TABLE submissions ADD COLUMN execution_time REAL DEFAULT 0'];
}
?>

<?php if (!empty($schemaChecks)): ?>
<div class="card" style="margin-bottom:20px; border-left:4px solid var(--warning); padding:16px;">
    <h3 style="margin-top:0;">⚠ Требуется обновление схемы БД</h3>
    <?php foreach ($schemaChecks as $check): ?>
    <p style="color:var(--text-muted);">
        В таблице <code><?= htmlspecialchars($check['table']) ?></code> отсутствует колонка <code><?= htmlspecialchars($check['col']) ?></code>.
        Это приведёт к ошибкам при отправке решений.
    </p>
    <?php endforeach; ?>
    <form method="POST">
        <input type="hidden" name="action" value="fix_schema">
        <button type="submit" class="btn" style="background:var(--warning); color:#000; border-color:var(--warning);">
            🔧 Исправить схему БД
        </button>
    </form>
</div>
<?php endif; ?>

<div class="admin-nav">
    <a href="?page=admin">Дашборд</a>
    <a href="?page=admin-users">Пользователи</a>
    <a href="?page=admin-groups">Группы</a>
    <a href="?page=admin-tasks" class="active">Задачи</a>
    <a href="?page=admin-task-groups">Группы задач</a>
    <a href="?page=admin-contests">Контесты</a>
    <a href="?page=admin-submissions">Решения</a>
    <a href="?page=admin-import-tasks">Импорт задач</a>
</div>

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
                    <form method="POST" style="display:inline" onsubmit="return confirm('Удалить задачу?')">
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

        <h3 class="mt-20">Тесты</h3>
        <p style="color: var(--text-muted); font-size: 0.9em;">Добавьте тесты. Отметьте галочкой публичные тесты (первые 3 будут видны пользователям).</p>

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
                        <label style="display:block; font-weight:600; margin-bottom:4px;">Входные данные</label>
                        <textarea name="test_input[]" style="min-height:80px;"><?= htmlspecialchars($test['input'] ?? '') ?></textarea>
                    </div>
                    <div>
                        <label style="display:block; font-weight:600; margin-bottom:4px;">Ожидаемый вывод</label>
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
    function addTest() {
        const container = document.getElementById('tests-container');
        const div = document.createElement('div');
        div.className = 'test-entry card';
        div.style.cssText = 'margin-bottom:12px; padding:16px;';
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
                    <label style="display:block; font-weight:600; margin-bottom:4px;">Входные данные</label>
                    <textarea name="test_input[]" style="min-height:80px;"></textarea>
                </div>
                <div>
                    <label style="display:block; font-weight:600; margin-bottom:4px;">Ожидаемый вывод</label>
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