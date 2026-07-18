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

    // Значения taskId и contestId передаются через data-атрибуты или глобальные переменные
    const taskId = window.TASK_ID;
    const contestId = window.CONTEST_ID;

    try {
        const response = await fetch('index.php?page=api&endpoint=submit', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                task_id: taskId,
                code: code,
                contest_id: contestId
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

        // Сохраняем код в localStorage
        try {
            localStorage.setItem('last_code_' + taskId, code);
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

// Функция обновления номеров строк
function updateLineNumbers() {
    const textarea = document.getElementById('code-editor');
    const lineNumbers = document.getElementById('line-numbers');
    if (!textarea || !lineNumbers) return;
    const lines = textarea.value.split('\n');
    const count = lines.length;
    lineNumbers.textContent = Array.from({ length: count }, (_, i) => i + 1).join('\n');
}

// Функция обновления позиции курсора
function updateCursorPosition() {
    const textarea = document.getElementById('code-editor');
    const cursorPos = document.getElementById('cursor-position');
    if (!textarea || !cursorPos) return;
    const text = textarea.value;
    const start = textarea.selectionStart;
    // Считаем строки и столбцы: до позиции курсора
    const textBefore = text.substring(0, start);
    const lines = textBefore.split('\n');
    const line = lines.length;
    const column = lines[lines.length - 1].length + 1;
    cursorPos.textContent = line + ':' + column;
}

// Копирование в буфер обмена
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

/**
 * Инициализация редактора: синхронизация скролла, номеров строк, подсветки.
 * Вызывается после загрузки DOM.
 */
function initEditor() {
    const textarea = document.getElementById('code-editor');
    const lineNumbers = document.getElementById('line-numbers');
    const highlightLayer = document.getElementById('highlight-layer');

    if (!textarea || !lineNumbers) return;

    // Обновляем номера строк и подсветку при вводе
    textarea.addEventListener('input', function() {
        updateLineNumbers();
        updateCursorPosition();
        SyntaxHighlight.update();
    });

    // Обновляем позицию курсора при клике, навигации с клавиатуры и изменениях выделения
    textarea.addEventListener('click', updateCursorPosition);
    textarea.addEventListener('keyup', updateCursorPosition);

    // Ctrl+Enter → отправка решения
    textarea.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
            e.preventDefault();
            submitSolution();
        }
    });

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
}