<?php
// Защита от прямого доступа к файлу — только через фронт-контроллер (index.php)
if (!defined('BASE_PATH')) {
    http_response_code(403);
    exit('Forbidden');
}

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
    ob_start();
    echo '<p>Задача не найдена.</p>';
    $content = ob_get_clean();
    require BASE_PATH . '/templates/layout.php';
    exit;
}

// Проверяем доступ к задаче строго в контексте указанного контеста
$userId = Auth::getUserId();
$userGroupIds = Auth::getUserGroupIds($userId);
$groupPlaceholders = Auth::groupPlaceholders($userGroupIds);
$stmt = $db->prepare("SELECT 1 FROM tasks t
    INNER JOIN contest_tasks ct ON t.id = ct.task_id
    INNER JOIN contest_access ca ON ct.contest_id = ca.contest_id
    WHERE t.id = ? AND ct.contest_id = ? AND (ca.user_id = ? OR ca.group_id IN ($groupPlaceholders))
    LIMIT 1");
$stmt->execute(array_merge([$taskId, $contestId, $userId], $userGroupIds));
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
    INNER JOIN tasks t ON ct.task_id = t.id
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

// Последняя посылка пользователя по этой задаче — предзаполняем редактор её кодом
$stmt = $db->prepare("SELECT id, code, status, executed_at FROM submissions
    WHERE user_id = ? AND task_id = ?
    ORDER BY id DESC LIMIT 1");
$stmt->execute([$userId, $taskId]);
$lastSubmission = $stmt->fetch() ?: null;

// Время последней посылки как Unix-время в миллисекундах (UTC) —
// для сравнения с меткой времени черновика в localStorage
$lastSubmitTs = null;
if ($lastSubmission && !empty($lastSubmission['executed_at'])) {
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $lastSubmission['executed_at'], new DateTimeZone('UTC'));
    if ($dt !== false) {
        $lastSubmitTs = $dt->getTimestamp() * 1000;
    }
}

// Список попыток пользователя по этой задаче (все контесты)
$stmt = $db->prepare("SELECT s.id, s.status, s.execution_time, s.executed_at, c.title AS contest_title
    FROM submissions s
    LEFT JOIN contests c ON s.contest_id = c.id
    WHERE s.user_id = ? AND s.task_id = ?
    ORDER BY s.id DESC");
$stmt->execute([$userId, $taskId]);
$taskAttempts = $stmt->fetchAll() ?: [];

// Заголовок текущего контеста — для строки попытки, добавляемой без перезагрузки
$stmt = $db->prepare("SELECT title FROM contests WHERE id = ?");
$stmt->execute([$contestId]);
$contestTitle = (string)($stmt->fetchColumn() ?: '');

require_once BASE_PATH . '/includes/labels.php';

$pageTitle = $task['title']; // layout сам экранирует title

// Условия задач могут содержать LaTeX — подключаем KaTeX
$useKaTeX = true;

ob_start();
?>

<div style="display: flex; justify-content: space-between; align-items: center;">
    <div style="display: flex; align-items: center; gap: 12px;">
        <h1><?= htmlspecialchars($task['title']) ?></h1>
        <!-- Бедж статуса последней посылки; обновляется без перезагрузки после отправки -->
        <span id="last-status-badge" class="submission-status<?php if ($lastSubmission): ?> status-<?= $lastSubmission['status'] ?><?php endif; ?>"<?php if (!$lastSubmission): ?> style="display:none;"<?php endif; ?>><?= $lastSubmission ? ($statusLabels[$lastSubmission['status']] ?? $lastSubmission['status']) : '' ?></span>
    </div>
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
                        <textarea id="code-editor" class="code-editor" placeholder="print('Hello, World!')" spellcheck="false" autocomplete="off" autocorrect="off" autocapitalize="off"><?= htmlspecialchars($lastSubmission['code'] ?? '') ?></textarea>
                    </div>
                </div>
                <div class="editor-statusbar">
                    <span class="cursor-position" id="cursor-position">1:1</span>
                </div>
            </div>

            <div class="form-actions" style="display:flex; justify-content:space-between; align-items:center; gap:12px;">
                <div style="display:flex; gap:12px; align-items:center;">
                    <button id="submit-btn" class="btn btn-primary" onclick="submitSolution()">
                        ▶ Отправить
                    </button>
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <span id="submit-status" style="display:flex; align-items:center; gap:8px; color:var(--text-muted);"></span>
                </div>
                <button type="button" class="btn btn-secondary" onclick="showPep8Help()">PEP 8</button>
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

<!-- Мои попытки по этой задаче -->
<div class="card mt-20">
    <h3>Мои попытки</h3>
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Контест</th>
                <th>Статус</th>
                <th>Время (сек)</th>
                <th>Дата</th>
                <th>Действия</th>
            </tr>
        </thead>
        <tbody id="attempts-tbody">
            <?php if (empty($taskAttempts)): ?>
            <tr id="attempts-empty-row">
                <td colspan="6" style="color:var(--text-muted); text-align:center; padding:16px;">
                    Попыток ещё не было. Отправьте первое решение.
                </td>
            </tr>
            <?php else: ?>
            <?php foreach ($taskAttempts as $a): ?>
            <tr>
                <td><?= $a['id'] ?></td>
                <td><?= $a['contest_title'] !== null ? htmlspecialchars($a['contest_title']) : '—' ?></td>
                <td>
                    <span class="submission-status status-<?= $a['status'] ?>">
                        <?= $statusLabels[$a['status']] ?? $a['status'] ?>
                    </span>
                </td>
                <td><?= number_format((float)($a['execution_time'] ?? 0), 3) ?></td>
                <td><?= htmlspecialchars(toDisplayTime($a['executed_at'] ?? '')) ?></td>
                <td><a href="?page=submission-detail&id=<?= $a['id'] ?>" class="btn btn-small">Просмотр</a></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    <p style="margin-top:12px;">
        <a href="?page=submissions&task_id=<?= $taskId ?>">Все мои решения →</a>
    </p>
</div>

<!-- Модальное окно: основы PEP 8 -->
<div id="pep8-modal" class="modal-overlay" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="pep8-title" onclick="if(event.target===this)closePep8Help()">
    <div class="modal-box">
        <div class="modal-header">
            <h3 id="pep8-title" style="margin:0;">Основы PEP 8</h3>
            <button type="button" class="modal-close" onclick="closePep8Help()" aria-label="Закрыть">×</button>
        </div>
        <div class="modal-body">
            <p style="margin-top:0;">Основные правила оформления кода Python, которые проверяются при отправке решения:</p>
            <ul>
                <li><strong>Отступы</strong> — 4 пробела, без табуляций.</li>
                <li><strong>Пробелы вокруг операторов</strong> — <code>a + b</code>, <code>n % 2 == 0</code>, <code>total = 0</code>, а не <code>a+b</code>.</li>
                <li><strong>Пробел после запятой</strong> — <code>add(a, b)</code>, <code>print(a, b)</code>, а не <code>add(a,b)</code>.</li>
                <li><strong>Без лишних пробелов в скобках</strong> — <code>(a, b)</code>, а не <code>( a, b )</code>.</li>
                <li><strong>Пустые строки</strong> — 2 пустые строки после определения функции, 1 — между логическими блоками.</li>
                <li><strong>Имена функций и переменных</strong> — нижний регистр с подчёркиванием: <code>max_of_two</code>, <code>my_abs</code>.</li>
                <li><strong>Комментарии</strong> — пробел после <code>#</code>.</li>
                <li><strong>Длина строки</strong> — не более 79 символов.</li>
                <li><strong>Конец файла</strong> — обязателен перевод строки, без пробелов в конце строк и пустых строк в конце файла.</li>
                <li><strong>Импорты</strong> — по одному в строке, в начале файла.</li>
            </ul>
            <p class="modal-note">Оформление проверяется автоматически — при ошибках стиля решение не засчитывается.</p>
        </div>
    </div>
</div>

<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/editor.css?v=6">
<script src="<?= BASE_URL ?>/assets/js/editor.js?v=9"></script>
<script>
// Передаём taskId и contestId из PHP в JS
window.TASK_ID = <?= $taskId ?>;
// Текущий пользователь — для изоляции черновиков в localStorage между учениками
window.USER_ID = <?= (int)Auth::getUserId() ?>;
window.CONTEST_ID = <?= $contestId ?? 'null' ?>;
// JSON_HEX_TAG — защита от «</script>» внутри строк при выводе в inline-скрипт
window.CONTEST_TITLE = <?= json_encode($contestTitle, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
// Время последней посылки (мс с эпохи, UTC) — для сравнения с меткой черновика
window.LAST_SUBMIT_TS = <?= $lastSubmitTs !== null ? (int)$lastSubmitTs : 'null' ?>;
// Канонические метки статусов (includes/labels.php)
window.STATUS_LABELS = <?= json_encode($statusLabels, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
</script>
<script>
// Восстановление кода: побеждает более новое — черновик из localStorage
// или последняя посылка (её код уже подставлен в textarea на сервере).
// Ключи черновика привязаны к user_id — чтобы на одном компьютере
// второй ученик не увидел черновик первого (_legacy-ключи игнорируются).
(function() {
    var ta = document.getElementById('code-editor');
    if (ta) {
        var draft = null, draftTs = 0;
        try {
            draft = localStorage.getItem('last_code_' + window.USER_ID + '_<?= $taskId ?>');
            draftTs = parseInt(localStorage.getItem('last_code_time_' + window.USER_ID + '_<?= $taskId ?>'), 10) || 0;
        } catch (e) { /* localStorage недоступен — не критично */ }

        var lastSubmitTs = window.LAST_SUBMIT_TS || 0;
        if (draft && draftTs > lastSubmitTs) {
            ta.value = draft;
        }
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