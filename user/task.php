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
/**
 * Подсветка синтаксиса Python для редактора кода.
 * Использует технику overlay: прозрачная textarea поверх div с подсвеченным кодом.
 */
const SyntaxHighlight = {
    // Ключевые слова Python
    keywords: new Set([
        'False', 'None', 'True', 'and', 'as', 'assert', 'async', 'await',
        'break', 'class', 'continue', 'def', 'del', 'elif', 'else', 'except',
        'finally', 'for', 'from', 'global', 'if', 'import', 'in', 'is',
        'lambda', 'nonlocal', 'not', 'or', 'pass', 'raise', 'return',
        'try', 'while', 'with', 'yield'
    ]),

    // Встроенные функции Python (наиболее часто используемые)
    builtins: new Set([
        'abs', 'all', 'any', 'bin', 'bool', 'bytearray', 'bytes', 'callable',
        'chr', 'classmethod', 'compile', 'complex', 'delattr', 'dict', 'dir',
        'divmod', 'enumerate', 'eval', 'exec', 'filter', 'float', 'format',
        'frozenset', 'getattr', 'globals', 'hasattr', 'hash', 'hex', 'id',
        'input', 'int', 'isinstance', 'issubclass', 'iter', 'len', 'list',
        'locals', 'map', 'max', 'memoryview', 'min', 'next', 'object', 'oct',
        'open', 'ord', 'pow', 'print', 'property', 'range', 'repr', 'reversed',
        'round', 'set', 'setattr', 'slice', 'sorted', 'staticmethod', 'str',
        'sum', 'super', 'tuple', 'type', 'vars', 'zip', '__import__'
    ]),

    /**
     * Основная функция подсветки кода Python.
     * Возвращает HTML с цветовыми span-ами.
     */
    highlight: function(code) {
        if (!code) return '';
        
        // Экранируем HTML
        let amp = String.fromCharCode(38);
        let escaped = code
            .replace(/&/g, amp + 'amp;')
            .replace(/</g, amp + 'lt;')
            .replace(/>/g, amp + 'gt;');

        // Подсветка: применяем токены последовательно
        // Используем одну строку с регулярными выражениями для производительности
        let result = '';
        let pos = 0;
        
        // Составляем единое регулярное выражение для всех токенов
        const tokenRegex = new RegExp([
            // Строки: тройные кавычки (самые длинные — в начале)
            '(?:\"\"\"[\\s\\S]*?\"\"\")',
            '(?:\'\'\'[\\s\\S]*?\'\'\')',
            '(?:f\"[^\"\\\\]*(?:\\\\.[^\"\\\\]*)*\")',
            '(?:f\'[^\'\\\\]*(?:\\\\.[^\'\\\\]*)*\')',
            '(?:\"[^\"\\\\]*(?:\\\\.[^\"\\\\]*)*\")',
            '(?:\'[^\'\\\\]*(?:\\\\.[^\'\\\\]*)*\')',
            // Комментарии
            '(?:#[^\\n]*)',
            // Декораторы
            '(?:@[a-zA-Z_][a-zA-Z0-9_.]*)',
            // Числа
            '(?:\\b(?:0[xX][0-9a-fA-F]+|0[oO][0-7]+|0[bB][01]+|\\d+(?:\\.\\d+)?(?:[eE][+-]?\\d+)?(?:j|J)?)\\b)',
            // Идентификаторы (слова)
            '(?:[a-zA-Z_][a-zA-Z0-9_]*)'
        ].join('|'), 'g');

        // Сохраняем последний match для контекстной подсветки
        let lastMatch = null;

        // Разбиваем код на токены
        let match;
        tokenRegex.lastIndex = 0;
        
        // Сбрасываем lastIndex
        const re = new RegExp(tokenRegex.source, 'g');
        
        while ((match = re.exec(escaped)) !== null) {
            const matchStart = match.index;
            const matchEnd = re.lastIndex;
            const token = match[0];
            
            // Добавляем текст до токена
            if (matchStart > pos) {
                result += escaped.substring(pos, matchStart);
            }
            
            // Определяем тип токена
            let tokenClass = '';
            
            // Проверяем тип
            if (/^"""[\s\S]*"""$/.test(token) || /^'''[\s\S]*'''$/.test(token)) {
                tokenClass = 'hl-string hl-multiline';
            } else if (/^f["']/.test(token)) {
                tokenClass = 'hl-string hl-fstring';
            } else if (/^["']/.test(token)) {
                tokenClass = 'hl-string';
            } else if (/^#/.test(token)) {
                tokenClass = 'hl-comment';
            } else if (/^@/.test(token)) {
                tokenClass = 'hl-decorator';
            } else if (/^\d/.test(token) || /^0[xXoObB]/.test(token)) {
                tokenClass = 'hl-number';
            } else if (/^[a-zA-Z_]/.test(token)) {
                if (this.keywords.has(token)) {
                    tokenClass = 'hl-keyword';
                } else if (this.builtins.has(token)) {
                    tokenClass = 'hl-builtin';
                }
                // else: обычный идентификатор — без класса
            }
            
            if (tokenClass) {
                result += '<span class="' + tokenClass + '">' + token + '</span>';
            } else {
                result += token;
            }
            
            pos = matchEnd;
            lastMatch = match;
        }
        
        // Добавляем оставшийся текст
        if (pos < escaped.length) {
            result += escaped.substring(pos);
        }
        
        return result;
    },

    /**
     * Обновляет подсветку в слое highlight-layer
     */
    update: function() {
        const textarea = document.getElementById('code-editor');
        const layer = document.getElementById('highlight-layer');
        if (!textarea || !layer) return;
        
        const code = textarea.value;
        const highlighted = this.highlight(code);
        
        // Добавляем trailing newline для позиционирования курсора на пустой строке
        const html = highlighted + (code.endsWith('\n') ? '\n' : '');
        layer.innerHTML = html;
    }
};

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

// Синхронизация скролла между gutter, highlight-слоем и textarea + обновление подсветки
(function() {
    const textarea = document.getElementById('code-editor');
    const lineNumbers = document.getElementById('line-numbers');
    const highlightLayer = document.getElementById('highlight-layer');

    // Обновляем номера строк и подсветку при вводе
    textarea.addEventListener('input', function() {
        updateLineNumbers();
        updateCursorPosition();
        SyntaxHighlight.update();
    });

    // Обновляем позицию курсора при клике, навигации с клавиатуры и изменениях выделения
    textarea.addEventListener('click', updateCursorPosition);
    textarea.addEventListener('keyup', updateCursorPosition);

    // Синхронизируем вертикальный скролл gutter и highlight-слоя с textarea
    textarea.addEventListener('scroll', function() {
        lineNumbers.scrollTop = this.scrollTop;
        if (highlightLayer) {
            highlightLayer.scrollTop = this.scrollTop;
        }
    });

    // Обновляем при paste (через setTimeout, чтобы value уже обновилось)
    textarea.addEventListener('paste', function() {
        setTimeout(function() {
            updateLineNumbers();
            updateCursorPosition();
            SyntaxHighlight.update();
        }, 0);
    });

    // Обновляем при cut (через setTimeout)
    textarea.addEventListener('cut', function() {
        setTimeout(function() {
            updateLineNumbers();
            updateCursorPosition();
            SyntaxHighlight.update();
        }, 0);
    });

    // Первоначальное заполнение номеров строк, позиции курсора и подсветки
    // Используем небольшой setTimeout, чтобы значение из localStorage уже было установлено
    setTimeout(function() {
        updateLineNumbers();
        updateCursorPosition();
        SyntaxHighlight.update();
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
    z-index: 3;
}

.editor-overlay-wrapper {
    position: relative;
    min-height: 300px;
}

/* Слой с подсвеченным кодом (фон) */
.editor-highlight-layer {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    padding: 12px 12px 12px 56px;
    font-family: 'Consolas', 'Monaco', 'Courier New', monospace;
    font-size: 14px;
    line-height: 1.5;
    color: #ffffff;
    white-space: pre-wrap;
    word-wrap: break-word;
    overflow: auto;
    pointer-events: none;
    background: transparent;
    z-index: 1;
    /* Скрываем полосу прокрутки, так как textarea управляет скроллом */
    scrollbar-width: none;
    -ms-overflow-style: none;
}

.editor-highlight-layer::-webkit-scrollbar {
    display: none;
}

/* Textarea (прозрачный ввод) */
.form-group textarea.code-editor,
.form-group textarea.code-editor:focus {
    position: relative;
    z-index: 2;
    width: 100%;
    min-height: 300px;
    font-family: 'Consolas', 'Monaco', 'Courier New', monospace;
    font-size: 14px;
    padding: 12px 12px 12px 56px;
    border: none !important;
    border-radius: 0;
    background: transparent !important;
    color: transparent !important;
    caret-color: #ffffff;
    resize: vertical;
    tab-size: 4;
    outline: none;
    line-height: 1.5;
    box-sizing: border-box;
    overflow: auto;
}

/* Плейсхолдер для textarea виден, когда нет ввода */
.form-group textarea.code-editor::placeholder {
    color: #6c6c8a;
}

/* Стили подсветки синтаксиса */
.hl-keyword {
    color: #c678dd; /* фиолетовый — ключевые слова */
    font-weight: 500;
}

.hl-builtin {
    color: #e5c07b; /* жёлтый — встроенные функции */
}

.hl-string {
    color: #98c379; /* зелёный — строки */
}

.hl-multiline {
    color: #98c379; /* зелёный — многострочные строки */
    font-style: italic;
}

.hl-fstring {
    color: #98c379; /* зелёный — f-строки */
}

.hl-comment {
    color: #5c6370; /* серый — комментарии */
    font-style: italic;
}

.hl-number {
    color: #d19a66; /* оранжевый — числа */
}

.hl-decorator {
    color: #61afef; /* голубой — декораторы */
    font-weight: 500;
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

<script>
function copyToClipboard(btn, idx) {
    const source = document.getElementById('copy-source-' + idx);
    if (!source) return;
    const text = source.textContent;
    navigator.clipboard.writeText(text).then(function() {
        const originalText = btn.textContent;
        btn.textContent = '✓';
        btn.classList.add('copied');
        setTimeout(function() {
            btn.textContent = originalText;
            btn.classList.remove('copied');
        }, 1500);
    }).catch(function(err) {
        // Fallback: select and copy
        const range = document.createRange();
        range.selectNodeContents(source);
        const selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(range);
        document.execCommand('copy');
        selection.removeAllRanges();
        const originalText = btn.textContent;
        btn.textContent = '✓';
        btn.classList.add('copied');
        setTimeout(function() {
            btn.textContent = originalText;
            btn.classList.remove('copied');
        }, 1500);
    });
}
</script>

<style>
.copy-block-wrapper {
    position: relative;
}

.copy-btn {
    position: absolute;
    top: 4px;
    right: 4px;
    z-index: 10;
    background: var(--bg, #1a1a2e);
    border: 1px solid var(--border, #2a2a4a);
    border-radius: 4px;
    padding: 2px 6px;
    font-size: 13px;
    cursor: pointer;
    opacity: 0.6;
    transition: opacity 0.2s, background 0.2s;
    line-height: 1.4;
    color: var(--text-muted, #aaa);
}

.copy-btn:hover {
    opacity: 1;
    background: var(--primary, #4a6cf7);
    color: #fff;
}

.copy-btn.copied {
    background: #22c55e;
    color: #fff;
    opacity: 1;
}
</style>

<?php
$content = ob_get_clean();
require BASE_PATH . '/templates/layout.php';