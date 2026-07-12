<?php
$pageTitle = 'Задача';
$db = Database::getInstance();

$taskId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$contestId = isset($_GET['contest']) ? (int) $_GET['contest'] : null;

if (!$taskId || !$contestId) {
    header('Location: ?page=contests');
    exit;
}

$stmt = $db->prepare("SELECT * FROM tasks WHERE id = ?");
$stmt->execute([$taskId]);
$task = $stmt->fetch();

if (!$task) {
    echo '<p>Задача не найдена.</p>';
    $content = ob_get_clean();
    require BASE_PATH . '/templates/layout.php';
    exit;
}

// Проверяем доступ к задаче: пользователь должен иметь доступ к контесту,
// в которое входит эта задача, либо задача должна быть запрошена в контексте контеста
$userId = Auth::getUserId();
$stmt = $db->prepare("SELECT 1 FROM tasks t
    JOIN contest_tasks ct ON t.id = ct.task_id
    JOIN contest_access ca ON ct.contest_id = ca.contest_id
    LEFT JOIN user_groups ug ON ug.user_id = ? AND ca.group_id = ug.group_id
    WHERE t.id = ? AND (ca.user_id = ? OR ug.group_id IS NOT NULL)
    LIMIT 1");
$stmt->execute([$userId, $taskId, $userId]);
$hasAccess = (bool) $stmt->fetch();

if (!$hasAccess) {
    ob_start();
    ?>
    <div class="access-denied">
        <div class="access-denied-card">
            <div class="access-denied-icon">
                <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                    <circle cx="12" cy="16" r="1"/>
                </svg>
            </div>
            <h2>Доступ ограничен</h2>
            <p class="access-denied-message">У вас нет доступа к этой задаче.</p>
            <p class="access-denied-hint">Если вы считаете, что это ошибка, обратитесь к администратору.</p>
            <a href="?page=contest&id=<?= $contestId ?>" class="btn btn-primary">← Вернуться к контесту</a>
        </div>
    </div>
    <?php
    $content = ob_get_clean();
    require BASE_PATH . '/templates/layout.php';
    exit;
}

// Получаем все задачи контеста для навигации
$stmt = $db->prepare("SELECT ct.task_id, ct.sort_order, t.title
    FROM contest_tasks ct
    JOIN tasks t ON ct.task_id = t.id
    WHERE ct.contest_id = ?
    ORDER BY ct.sort_order, ct.task_id");
$stmt->execute([$contestId]);
$contestTasks = $stmt->fetchAll() ?: [];

$prevTask = null;
$nextTask = null;
$currentIndex = -1;

foreach ($contestTasks as $index => $ct) {
    if ((int)$ct['task_id'] === $taskId) {
        $currentIndex = $index;
        if ($index > 0) {
            $prevTask = $contestTasks[$index - 1];
        }
        if ($index < count($contestTasks) - 1) {
            $nextTask = $contestTasks[$index + 1];
        }
        break;
    }
}

// Получаем публичные тесты (первые 3)
$stmt = $db->prepare("SELECT * FROM tests WHERE task_id = ? AND is_public = 1 ORDER BY test_number LIMIT 3");
$stmt->execute([$taskId]);
$publicTests = $stmt->fetchAll() ?: [];

$pageTitle = htmlspecialchars($task['title']);

ob_start();
?>

<div style="display: flex; justify-content: space-between; align-items: center;">
    <h1><?= htmlspecialchars($task['title']) ?></h1>
    <div>
        <span style="color: var(--text-muted); font-size: 0.9em;">
            Лимит времени: <?= $task['time_limit'] ?> сек |
            Лимит памяти: <?= $task['memory_limit'] ?> МБ
        </span>
    </div>
</div>

<!-- Навигация по задачам контеста -->
<div class="card mb-20" style="padding: 12px 16px; display: flex; justify-content: space-between; align-items: center;">
    <div style="display: flex; gap: 16px; align-items: center;">
        <?php if ($prevTask): ?>
            <a href="?page=task&id=<?= $prevTask['task_id'] ?>&contest=<?= $contestId ?>" class="btn btn-secondary" style="font-size: 0.9em;">
                ← <?= htmlspecialchars($prevTask['title']) ?>
            </a>
        <?php else: ?>
            <span class="btn btn-secondary" style="font-size: 0.9em; opacity: 0.4; cursor: not-allowed;">← Предыдущая</span>
        <?php endif; ?>

        <?php if ($nextTask): ?>
            <a href="?page=task&id=<?= $nextTask['task_id'] ?>&contest=<?= $contestId ?>" class="btn btn-secondary" style="font-size: 0.9em;">
                <?= htmlspecialchars($nextTask['title']) ?> →
            </a>
        <?php else: ?>
            <span class="btn btn-secondary" style="font-size: 0.9em; opacity: 0.4; cursor: not-allowed;">Следующая →</span>
        <?php endif; ?>
    </div>
    <div>
        <a href="?page=contest&id=<?= $contestId ?>" class="btn btn-primary" style="font-size: 0.9em;">
            ☰ Список задач
        </a>
    </div>
</div>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-top: 20px;">
    <!-- Левая колонка: условие -->
    <div>
        <div class="card">
            <h3>Условие задачи</h3>
            <div class="task-content">
                <?= $task['given'] ?>
            </div>
        </div>

        <div class="card mt-20">
            <h3>Формат входных данных</h3>
            <div class="task-content">
                    <?= $task['input_format'] ?>
            </div>
        </div>

        <div class="card mt-20">
            <h3>Формат выходных данных</h3>
            <div class="task-content">
                    <?= $task['output_format'] ?>
            </div>
        </div>

        <?php if ($publicTests): ?>
        <div class="card mt-20">
            <h3>Примеры</h3>
            <?php foreach ($publicTests as $idx => $test): ?>
                <div style="margin-bottom: 16px;">
                    <strong>Пример <?= $idx + 1 ?></strong>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 8px;">
                        <div class="copy-block-wrapper">
                            <div style="font-size: 0.85em; color: var(--text-muted); margin-bottom: 4px;">Входные данные</div>
                            <button class="copy-btn" onclick="copyToClipboard(this, '<?= $idx ?>')" title="Скопировать">📋</button>
                            <pre class="test-block" id="copy-source-<?= $idx ?>"><?= htmlspecialchars($test['input']) ?></pre>
                        </div>
                        <div>
                            <div style="font-size: 0.85em; color: var(--text-muted); margin-bottom: 4px;">Выходные данные</div>
                            <pre class="test-block"><?= htmlspecialchars($test['expected_output']) ?></pre>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Правая колонка: редактор кода -->
    <div>
        <div class="card">
            <h3>Решение</h3>
            <p style="color: var(--text-muted); font-size: 0.9em;">Напишите программу на Python. Используйте <code>input()</code> для чтения данных и <code>print()</code> для вывода.</p>

            <div class="form-group">
                <div class="editor-container">
                    <div class="editor-line-numbers" id="line-numbers">1</div>
                    <div class="editor-overlay-wrapper">
                        <div class="editor-highlight-layer" id="highlight-layer"></div>
                        <textarea id="code-editor" class="code-editor" placeholder="print('Hello, World!')" spellcheck="false" autocomplete="off" autocorrect="off" autocapitalize="off"><?= htmlspecialchars($_SESSION['last_code_' . $taskId] ?? '') ?></textarea>
                    </div>
                </div>
                <div class="editor-statusbar">
                    <span class="cursor-position" id="cursor-position">1:1</span>
                </div>
            </div>

            <div class="form-actions" style="display:flex; gap:12px;">
                <button id="submit-btn" class="btn btn-primary" onclick="submitSolution()">
                    ▶ Отправить
                </button>
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <span id="submit-status" style="display:flex; align-items:center; gap:8px; color:var(--text-muted);"></span>
            </div>
        </div>

        <!-- Результаты тестов -->
        <div id="results-container" class="card mt-20" style="display:none;">
            <h3>Результаты проверки</h3>
            <div id="results-summary" style="margin-bottom: 16px;"></div>
            <div id="results-detail"></div>
        </div>
    </div>
</div>

<link rel="stylesheet" href="/contest-web/assets/css/editor.css">
<script src="/contest-web/assets/js/editor.js"></script>
<script>
// Передаём taskId и contestId из PHP в JS
window.TASK_ID = <?= $taskId ?>;
window.CONTEST_ID = <?= $contestId ?? 'null' ?>;
</script>
<script>
// Восстановление кода из localStorage и инициализация редактора
(function() {
    var saved = localStorage.getItem('last_code_<?= $taskId ?>');
    if (saved) {
        var ta = document.getElementById('code-editor');
        if (ta) ta.value = saved;
    }
    // Инициализация редактора после загрузки DOM
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initEditor);
    } else {
        initEditor();
    }
})();
</script>

<?php
$content = ob_get_clean();
require BASE_PATH . '/templates/layout.php';