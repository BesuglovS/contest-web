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
    echo '<p>У вас нет доступа к этой задаче.</p>';
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
                <?= $task['condition'] ?>
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
                        <div>
                            <div style="font-size: 0.85em; color: var(--text-muted); margin-bottom: 4px;">Входные данные</div>
                            <pre class="test-block"><?= htmlspecialchars($test['input']) ?></pre>
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
                    <textarea id="code-editor" class="code-editor" placeholder="print('Hello, World!')"><?= htmlspecialchars($_SESSION['last_code_' . $taskId] ?? '') ?></textarea>
                </div>
                <div class="editor-statusbar">
                    <span class="cursor-position" id="cursor-position">1:1</span>
                </div>
            </div>

            <div class="form-actions" style="display:flex; gap:12px;">
                <button id="submit-btn" class="btn btn-primary" onclick="submitSolution()">
                    ▶ Отправить
                </button>
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

<script>
let isRunning = false;

async function submitSolution() {
    if (isRunning) return;

    const code = document.getElementById('code-editor').value;
    if (!code.trim()) {
        alert('Введите код решения');
        return;
    }

    isRunning = true;
    const btn = document.getElementById('submit-btn');
    const status = document.getElementById('submit-status');
    const resultsContainer = document.getElementById('results-container');

    btn.disabled = true;
    btn.textContent = '⏳ Проверка...';
    status.innerHTML = '<span class="spinner"></span> Выполняется...';
    resultsContainer.style.display = 'none';

    try {
        const response = await fetch('index.php?page=api&endpoint=submit', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                task_id: <?= $taskId ?>,
                code: code,
                contest_id: <?= $contestId ?? 'null' ?>
            })
        });

        const data = await response.json();

        if (data.error) {
            status.innerHTML = '<span style="color:red;">Ошибка: ' + data.error + '</span>';
            btn.disabled = false;
            btn.textContent = '▶ Отправить';
            isRunning = false;
            return;
        }

        // Сохраняем код в сессию через скрытый iframe или localStorage
        try {
            localStorage.setItem('last_code_<?= $taskId ?>', code);
        } catch(e) {}

        // Отображаем результаты
        showResults(data);
        status.innerHTML = '';
        btn.textContent = '▶ Отправить ещё раз';

    } catch (err) {
        status.innerHTML = '<span style="color:red;">Ошибка соединения: ' + err.message + '</span>';
    }

    btn.disabled = false;
    isRunning = false;
}

function showResults(data) {
    const container = document.getElementById('results-container');
    const summary = document.getElementById('results-summary');
    const detail = document.getElementById('results-detail');

    container.style.display = 'block';

    // Обработка ошибок линтинга
    if (data.lint_errors && data.lint_errors.length > 0) {
        summary.innerHTML = '<div class="alert alert-error" style="font-size:1.1em;">⚠ Ошибки оформления кода (PEP8)</div>';
        let detailHtml = '<h4>Ошибки оформления:</h4>';
        detailHtml += '<table><thead><tr><th>Строка</th><th>Столбец</th><th>Код</th><th>Описание</th></tr></thead><tbody>';
        data.lint_errors.forEach(function(err) {
            detailHtml += '<tr>';
            detailHtml += '<td>' + (err.line || 0) + '</td>';
            detailHtml += '<td>' + (err.column || 0) + '</td>';
            detailHtml += '<td><code style="font-family:Consolas,monospace;">' + escapeHtml(err.code || '') + '</code></td>';
            detailHtml += '<td>' + escapeHtml(err.message || '') + '</td>';
            detailHtml += '</tr>';
        });
        detailHtml += '</tbody></table>';
        detail.innerHTML = detailHtml;
        container.scrollIntoView({ behavior: 'smooth' });
        return;
    }

    // Сводка
    let summaryHtml = '';
    if (data.all_passed) {
        summaryHtml = '<div class="alert alert-success" style="font-size:1.1em;">✓ Все тесты пройдены (' + data.passed + '/' + data.total + ') за ' + data.total_time + ' сек</div>';
    } else {
        const statusLabels = {
            'accepted': '✓ Принято',
            'wrong_answer': '✗ Неверный ответ',
            'runtime_error': '⚠ Ошибка выполнения',
            'time_limit': '⏱ Превышен лимит времени',
            'memory_limit': '📦 Превышен лимит памяти'
        };
        const statusLabel = statusLabels[data.status] || data.status;
        summaryHtml = '<div class="alert alert-error">' + statusLabel + ' — пройдено ' + data.passed + '/' + data.total + ' тестов за ' + data.total_time + ' сек</div>';
    }
    summary.innerHTML = summaryHtml;

    // Детали по публичным тестам
    let detailHtml = '<h4>Публичные тесты:</h4>';
    const publicResults = data.public_results || [];

    if (publicResults.length === 0) {
        detailHtml += '<p style="color:var(--text-muted);">Нет публичных тестов для отображения.</p>';
    }

    publicResults.forEach(function(test, idx) {
        const statusClass = 'status-' + test.status;
        const statusLabels = {
            'accepted': '✓ Пройден',
            'wrong_answer': '✗ Неверный ответ',
            'runtime_error': '⚠ Ошибка выполнения',
            'time_limit': '⏱ Превышен лимит времени',
            'memory_limit': '📦 Превышен лимит памяти'
        };
        const label = statusLabels[test.status] || test.status;

        detailHtml += `
            <div class="test-result" style="margin-bottom:16px; padding:12px; border:1px solid var(--border); border-radius:8px;">
                <div class="test-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                    <strong>Тест #${test.test_number}</strong>
                    <span class="submission-status ${statusClass}">${label}</span>
                </div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                    <div>
                        <div style="font-size:0.85em; color:var(--text-muted);">Входные данные</div>
                        <pre class="test-block">${escapeHtml(test.input)}</pre>
                    </div>
                    <div>
                        <div style="font-size:0.85em; color:var(--text-muted);">Ожидаемый вывод</div>
                        <pre class="test-block">${escapeHtml(test.expected)}</pre>
                    </div>
                </div>
                ${test.output ? `
                <div style="margin-top:8px;">
                    <div style="font-size:0.85em; color:var(--text-muted);">Ваш вывод</div>
                    <pre class="test-block">${escapeHtml(test.output)}</pre>
                </div>` : ''}
                ${test.error ? `
                <div style="margin-top:8px;">
                    <div style="font-size:0.85em; color:var(--error);">Ошибка</div>
                    <pre class="test-block error-block">${escapeHtml(test.error)}</pre>
                </div>` : ''}
            </div>
        `;
    });

    detail.innerHTML = detailHtml;

    // Прокрутка к результатам
    container.scrollIntoView({ behavior: 'smooth' });
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Восстановление кода из localStorage
(function() {
    const saved = localStorage.getItem('last_code_<?= $taskId ?>');
    if (saved) {
        document.getElementById('code-editor').value = saved;
    }
})();

// Функция обновления номеров строк
function updateLineNumbers() {
    const textarea = document.getElementById('code-editor');
    const lineNumbers = document.getElementById('line-numbers');
    const lines = textarea.value.split('\n');
    const count = lines.length;
    lineNumbers.textContent = Array.from({ length: count }, (_, i) => i + 1).join('\n');
}

// Функция обновления позиции курсора
function updateCursorPosition() {
    const textarea = document.getElementById('code-editor');
    const cursorPos = document.getElementById('cursor-position');
    const text = textarea.value;
    const start = textarea.selectionStart;
    // Считаем строки и столбцы: до позиции курсора
    const textBefore = text.substring(0, start);
    const lines = textBefore.split('\n');
    const line = lines.length;
    const column = lines[lines.length - 1].length + 1;
    cursorPos.textContent = line + ':' + column;
}

// Синхронизация скролла между gutter и textarea и обновление позиции курсора
(function() {
    const textarea = document.getElementById('code-editor');
    const lineNumbers = document.getElementById('line-numbers');

    // Обновляем номера строк при вводе
    textarea.addEventListener('input', function() {
        updateLineNumbers();
        updateCursorPosition();
    });

    // Обновляем позицию курсора при клике, навигации с клавиатуры и изменениях выделения
    textarea.addEventListener('click', updateCursorPosition);
    textarea.addEventListener('keyup', updateCursorPosition);

    // Синхронизируем вертикальный скролл gutter с textarea
    textarea.addEventListener('scroll', function() {
        lineNumbers.scrollTop = this.scrollTop;
    });

    // Обновляем при paste (через setTimeout, чтобы value уже обновилось)
    textarea.addEventListener('paste', function() {
        setTimeout(function() {
            updateLineNumbers();
            updateCursorPosition();
        }, 0);
    });

    // Обновляем при cut (через setTimeout)
    textarea.addEventListener('cut', function() {
        setTimeout(function() {
            updateLineNumbers();
            updateCursorPosition();
        }, 0);
    });

    // Первоначальное заполнение номеров строк и позиции курсора
    // Используем небольшой setTimeout, чтобы значение из localStorage уже было установлено
    setTimeout(function() {
        updateLineNumbers();
        updateCursorPosition();
    }, 0);
})();
</script>

<style>
.editor-statusbar {
    display: flex;
    justify-content: flex-end;
    padding: 4px 10px;
    background: #141428;
    border: 2px solid var(--primary);
    border-top: none;
    border-radius: 0 0 8px 8px;
    font-family: 'Consolas', 'Monaco', 'Courier New', monospace;
    font-size: 12px;
    color: #6c6c8a;
    user-select: none;
}

.cursor-position {
    font-family: 'Consolas', 'Monaco', 'Courier New', monospace;
    font-size: 12px;
    color: #6c6c8a;
}

.editor-container {
    position: relative;
    border: 2px solid var(--primary);
    border-top: 2px solid var(--primary);
    border-radius: 8px 8px 0 0;
    background: #1a1a2e;
    overflow: hidden;
}

.editor-line-numbers {
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 44px;
    padding: 12px 8px 12px 12px;
    font-family: 'Consolas', 'Monaco', 'Courier New', monospace;
    font-size: 14px;
    line-height: 1.5;
    color: #6c6c8a;
    background: #141428;
    user-select: none;
    overflow: hidden;
    text-align: right;
    border-right: 1px solid #2a2a4a;
    white-space: pre;
    pointer-events: none;
    box-sizing: border-box;
}

.form-group textarea.code-editor,
.form-group textarea.code-editor:focus {
    width: 100%;
    min-height: 300px;
    font-family: 'Consolas', 'Monaco', 'Courier New', monospace;
    font-size: 14px;
    padding: 12px 12px 12px 56px;
    border: none !important;
    border-radius: 0;
    background: transparent !important;
    color: #ffffff !important;
    caret-color: #ffffff;
    resize: vertical;
    tab-size: 4;
    outline: none;
    line-height: 1.5;
    box-sizing: border-box;
}

.test-block {
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 8px 12px;
    font-family: 'Consolas', 'Monaco', 'Courier New', monospace;
    font-size: 13px;
    white-space: pre-wrap;
    word-break: break-all;
    margin: 0;
}

.error-block {
    background: #fff5f5;
    color: var(--error);
    border-color: var(--error);
}

.task-content {
    line-height: 1.7;
    font-size: 1.05em;
}

.result-item {
    padding: 12px;
    border-radius: 8px;
    margin-bottom: 12px;
}

.result-item.pass {
    background: #f0fdf4;
    border: 1px solid #86efac;
}

.result-item.fail {
    background: #fef2f2;
    border: 1px solid #fca5a5;
}

.spinner {
    display: inline-block;
    width: 16px;
    height: 16px;
    border: 2px solid var(--border);
    border-top-color: var(--primary);
    border-radius: 50%;
    animation: spin 0.6s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}
</style>

<?php
$content = ob_get_clean();
require BASE_PATH . '/templates/layout.php';