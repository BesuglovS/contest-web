/**
 * Редактор кода Python для задач: подсветка синтаксиса, номера строк,
 * отступы, подсказки по PEP 8 и отправка решения.
 *
 * Подсветка — overlay: прозрачная textarea поверх div с подсвеченным кодом.
 * Перенос длинных строк в слое и в textarea обязан совпадать пиксель в пиксель
 * (см. SyntaxHighlight.update), иначе каретка «уезжает» от текста.
 */

const INDENT = '    ';          // шаг отступа Python (PEP 8)
const MAX_LINE_LENGTH = 79;     // предел длины строки (PEP 8)
const PAIRS = { '(': ')', '[': ']', '{': '}', '"': '"', "'": "'" };
const CLOSERS = new Set([')', ']', '}', '"', "'"]);

/**
 * Проблемы оформления строки, видимые сразу (те же, что ловит pycodestyle):
 * табуляция, пробелы в конце строки, длина больше 79 символов.
 */
function lineIssues(text) {
    return {
        tab: text.indexOf('\t') !== -1,
        trailing: /[ \t]+$/.test(text),
        long: text.length > MAX_LINE_LENGTH
    };
}

// Единое регулярное выражение токенов Python — собирается один раз.
// Порядок альтернатив важен: длинные совпадения идут раньше коротких.
const PY_TOKEN_RE = new RegExp([
    // строки в тройных кавычках (с префиксом r/b/u/f и их комбинациями)
    '(?:[rRbBuUfF]{0,2}"""[\\s\\S]*?""")',
    "(?:[rRbBuUfF]{0,2}'''[\\s\\S]*?''')",
    // обычные строки; \\[\s\S] — экранированный символ, в том числе перенос строки
    '(?:[rRbBuUfF]{0,2}"[^"\\\\]*(?:\\\\[\\s\\S][^"\\\\]*)*")',
    "(?:[rRbBuUfF]{0,2}'[^'\\\\]*(?:\\\\[\\s\\S][^'\\\\]*)*')",
    // комментарии
    '(?:#[^\\n]*)',
    // декораторы
    '(?:@[a-zA-Z_][a-zA-Z0-9_.]*)',
    // числа: 0x1F, 1_000, 3.5, .5, 1., 1e-3, 2j
    '(?:0[xX][0-9a-fA-F_]+|0[oO][0-7_]+|0[bB][01_]+|' +
        '(?:\\d[\\d_]*(?:\\.[\\d_]*)?|\\.[\\d_]+)(?:[eE][+-]?[\\d_]+)?[jJ]?)',
    // идентификаторы
    '(?:[a-zA-Z_][a-zA-Z0-9_]*)'
].join('|'), 'g');

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
     * CSS-класс токена (пустая строка — обычный текст).
     */
    tokenClass: function(token) {
        // Строка: необязательный префикс r/b/u/f и кавычки
        const stringMatch = /^([rRbBuUfF]{0,2})(["'])/.exec(token);
        if (stringMatch) {
            const prefix = stringMatch[1];
            const quote = stringMatch[2];
            const isF = /[fF]/.test(prefix);
            if (token.indexOf(quote + quote + quote) === prefix.length) {
                return 'hl-string hl-multiline' + (isF ? ' hl-fstring' : '');
            }
            return isF ? 'hl-string hl-fstring' : 'hl-string';
        }
        if (token.charAt(0) === '#') return 'hl-comment';
        if (token.charAt(0) === '@') return 'hl-decorator';
        if (/^[0-9.]/.test(token)) return 'hl-number';
        if (/^[a-zA-Z_]/.test(token)) {
            if (this.keywords.has(token)) return 'hl-keyword';
            if (this.builtins.has(token)) return 'hl-builtin';
        }
        // Обычный идентификатор — без класса
        return '';
    },

    /**
     * Подсветка кода Python с разбивкой по логическим строкам.
     * Возвращает массив HTML-фрагментов (по одному на строку): номер строки
     * рисуется внутри своей строки и не «съезжает» при переносе длинной строки.
     */
    highlightLines: function(code) {
        const lines = [];
        let current = '';

        // Добавляет экранированный фрагмент в текущую строку; переводы строк
        // внутри токена (многострочные строки) закрывают текущую строку
        const append = function(fragment, cssClass) {
            const parts = fragment.split('\n');
            for (let i = 0; i < parts.length; i++) {
                if (i > 0) {
                    lines.push(current);
                    current = '';
                }
                if (!parts[i]) continue;
                current += cssClass
                    ? '<span class="' + cssClass + '">' + parts[i] + '</span>'
                    : parts[i];
            }
        };

        if (!code) return [''];

        // Экранируем HTML
        const amp = String.fromCharCode(38);
        const escaped = code
            .replace(/&/g, amp + 'amp;')
            .replace(/</g, amp + 'lt;')
            .replace(/>/g, amp + 'gt;');

        PY_TOKEN_RE.lastIndex = 0;

        let pos = 0;
        let match;

        while ((match = PY_TOKEN_RE.exec(escaped)) !== null) {
            const matchStart = match.index;
            const token = match[0];

            // Добавляем текст до токена
            if (matchStart > pos) {
                append(escaped.substring(pos, matchStart), '');
            }

            append(token, this.tokenClass(token));
            pos = PY_TOKEN_RE.lastIndex;
        }

        // Добавляем оставшийся текст
        if (pos < escaped.length) {
            append(escaped.substring(pos), '');
        }

        // Завершаем последнюю строку (пустой код — одна пустая строка)
        lines.push(current);
        return lines;
    },

    /**
     * Подсветка кода одним HTML-блоком (просмотр посылки целиком,
     * без номеров строк и без оверлея редактора).
     */
    highlight: function(code) {
        return this.highlightLines(code).join('\n');
    },

    /**
     * Обновляет слой подсветки и номера строк.
     */
    update: function() {
        const textarea = document.getElementById('code-editor');
        const layer = document.getElementById('highlight-layer');
        if (!textarea || !layer) return;

        // Размер слоя повторяет клиентскую область textarea: полоса прокрутки
        // отнимает у textarea несколько пикселей ширины, и без синхронизации
        // переносы длинных строк в слое и в textarea не совпадают
        layer.style.width = textarea.clientWidth + 'px';
        layer.style.height = textarea.clientHeight + 'px';

        const code = textarea.value;
        const rawLines = code.split('\n');
        const htmlLines = this.highlightLines(code);

        let html = '';
        for (let i = 0; i < htmlLines.length; i++) {
            const issues = lineIssues(rawLines[i] || '');
            let lineClass = 'editor-line';
            if (issues.tab) lineClass += ' editor-line--tab';
            if (issues.trailing) lineClass += ' editor-line--space';
            if (issues.long) lineClass += ' editor-line--long';

            html += '<div class="' + lineClass + '">' +
                '<span class="editor-line-num">' + (i + 1) + '</span>' +
                htmlLines[i] + '</div>';
        }
        layer.innerHTML = html;

        // После перерисовки возвращаем прокрутку на место
        layer.scrollTop = textarea.scrollTop;
        layer.scrollLeft = textarea.scrollLeft;
    }
};

let isRunning = false;

// Отложенное сохранение черновика кода в localStorage — чтобы ввод не терялся
// при ошибке сети, rate-limit или случайном закрытии вкладки.
// Ключи черновика привязаны к пользователю (window.USER_ID) — на одном
// компьютере ученики не видят черновики друг друга
function draftKeys() {
    if (!window.USER_ID) return null;
    return {
        code: 'last_code_' + window.USER_ID + '_' + window.TASK_ID,
        time: 'last_code_time_' + window.USER_ID + '_' + window.TASK_ID
    };
}

let draftSaveTimer = null;

// Немедленная запись черновика (перед закрытием вкладки или отправкой)
function saveDraftNow() {
    const keys = draftKeys();
    const textarea = document.getElementById('code-editor');
    if (!keys || !textarea) return;
    clearTimeout(draftSaveTimer);
    draftSaveTimer = null;
    try {
        localStorage.setItem(keys.code, textarea.value);
        // Метка времени черновика — чтобы при загрузке страницы сравнить
        // его с последней посылкой и показать более новое
        localStorage.setItem(keys.time, String(Date.now()));
    } catch (e) { /* localStorage недоступен — не критично */ }
}

function scheduleDraftSave() {
    if (!window.TASK_ID || !draftKeys()) return;
    clearTimeout(draftSaveTimer);
    draftSaveTimer = setTimeout(saveDraftNow, 400);
}

// Перерисовка подсветки не чаще одного кадра: ввод не «дёргает» layout
let highlightFrame = 0;
function scheduleHighlight() {
    if (highlightFrame) return;
    highlightFrame = requestAnimationFrame(function() {
        highlightFrame = 0;
        SyntaxHighlight.update();
    });
}

/**
 * Заменяет диапазон [from, to) текстом text с сохранением нативного undo.
 * execCommand('insertText'/'delete') выполняется как пользовательский ввод
 * и сам порождает событие input — подсветка и черновик обновятся штатно.
 * Если браузер команду не поддерживает — обычная запись value (undo теряется).
 */
function replaceRange(textarea, from, to, text) {
    const value = textarea.value;
    if (from === to && text === '') return;

    textarea.focus();
    textarea.setSelectionRange(from, to);

    let done = false;
    try {
        if (text === '') {
            done = document.execCommand('delete');
        } else {
            done = document.execCommand('insertText', false, text);
        }
    } catch (err) {
        done = false;
    }
    if (done) {
        // Событие input обычно уже пришло, но в части браузеров его может
        // не быть — обновление идемпотентно, дублируем на всякий случай
        scheduleHighlight();
        scheduleDraftSave();
        return;
    }

    textarea.value = value.substring(0, from) + text + value.substring(to);
    const caret = from + text.length;
    textarea.setSelectionRange(caret, caret);
    // Событие input браузер не создаст — обновляем состояние вручную
    updateCursorPosition();
    scheduleHighlight();
    scheduleDraftSave();
}

/**
 * Сдвиг отступа строк, затронутых выделением: +1 — добавить шаг,
 * -1 — убрать (до 4 пробелов или один табулятор). Пустые строки не трогаем,
 * чтобы не получать от pycodestyle W293 («пробелы в пустой строке»).
 */
function shiftIndent(textarea, direction) {
    const value = textarea.value;
    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    const lineStart = value.lastIndexOf('\n', start - 1) + 1;

    // Строка, на начале которой заканчивается выделение, не затрагивается
    let blockEnd;
    if (end > lineStart && value.charAt(end - 1) === '\n') {
        blockEnd = end - 1;
    } else {
        const nl = value.indexOf('\n', end);
        blockEnd = nl === -1 ? value.length : nl;
    }
    if (blockEnd <= lineStart) return;

    const lines = value.substring(lineStart, blockEnd).split('\n');
    const caretLine = value.substring(lineStart, start).split('\n').length - 1;
    let caretShift = 0;
    let changed = false;

    for (let i = 0; i < lines.length; i++) {
        const before = lines[i];
        let after = before;

        if (before.trim() === '') {
            // Пустые строки не трогаем: пробелы в пустой строке — ошибка W293
            after = before;
        } else if (direction > 0) {
            after = INDENT + before;
        } else if (before.charAt(0) === '\t') {
            after = before.substring(1);
        } else {
            let n = 0;
            while (n < INDENT.length && before.charAt(n) === ' ') n++;
            after = before.substring(n);
        }

        if (i === caretLine) caretShift = after.length - before.length;
        lines[i] = after;
        if (after !== before) changed = true;
    }

    if (!changed) return;

    const newBlock = lines.join('\n');
    replaceRange(textarea, lineStart, blockEnd, newBlock);

    if (start === end) {
        const caret = Math.max(lineStart, start + caretShift);
        textarea.setSelectionRange(caret, caret);
    } else {
        textarea.setSelectionRange(lineStart, lineStart + newBlock.length);
    }
    updateCursorPosition();
}

// Tab: отступ до ближайшей позиции табуляции, на выделении — сдвиг блока
function insertIndent(textarea) {
    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;

    if (start !== end) {
        shiftIndent(textarea, 1);
        return;
    }

    const value = textarea.value;
    const lineStart = value.lastIndexOf('\n', start - 1) + 1;
    const spaces = INDENT.length - ((start - lineStart) % INDENT.length);
    replaceRange(textarea, start, start, ' '.repeat(spaces));
    updateCursorPosition();
}

// Ctrl+/ — закомментировать или раскомментировать выделенные строки
function toggleComment(textarea) {
    const value = textarea.value;
    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    const lineStart = value.lastIndexOf('\n', start - 1) + 1;

    let blockEnd;
    if (end > lineStart && value.charAt(end - 1) === '\n') {
        blockEnd = end - 1;
    } else {
        const nl = value.indexOf('\n', end);
        blockEnd = nl === -1 ? value.length : nl;
    }
    if (blockEnd <= lineStart) return;

    const block = value.substring(lineStart, blockEnd);
    const lines = block.split('\n');
    const nonEmpty = lines.filter(function(line) { return line.trim() !== ''; });
    if (nonEmpty.length === 0) return;

    const allCommented = nonEmpty.every(function(line) { return /^\s*#/.test(line); });
    const result = lines.map(function(line) {
        if (line.trim() === '') return line;
        return allCommented
            ? line.replace(/^(\s*)#[ \t]?/, '$1')
            : line.replace(/^(\s*)/, '$1# ');
    }).join('\n');

    if (result === block) return;

    replaceRange(textarea, lineStart, blockEnd, result);
    textarea.setSelectionRange(lineStart, lineStart + result.length);
    updateCursorPosition();
}

// Enter: сохраняем отступ строки, после «:» — плюс один шаг (начало блока)
function autoIndentNewline(textarea, e) {
    const value = textarea.value;
    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    const lineStart = value.lastIndexOf('\n', start - 1) + 1;
    const beforeCaret = value.substring(lineStart, start);

    const indent = /^[ \t]*/.exec(beforeCaret)[0];
    let insert = '\n' + indent;
    // «:» в конце строки (возможно с комментарием) открывает блок
    if (/:\s*(#.*)?$/.test(beforeCaret)) insert += INDENT;

    e.preventDefault();
    replaceRange(textarea, start, end, insert);
    updateCursorPosition();
}

// Можно ли открыть кавычку в этой позиции (эвристика как в редакторах)
function canOpenQuote(value, pos) {
    const prev = value.charAt(pos - 1);
    if (!prev) return true;
    if (!/[\w"')\]]/.test(prev)) return true;
    // исключение — префикс строки: f" rb" b" r" u"
    return /[rRbBuUfF]/.test(prev) && !/\w/.test(value.charAt(pos - 2));
}

/**
 * Автозакрытие скобок и кавычек: () [] {} "" ''. На выделении — обёртка.
 * Возвращает true, если клавиша обработана.
 */
function handleAutoPair(textarea, e) {
    const ch = e.key;
    if (ch.length !== 1) return false;

    const value = textarea.value;
    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    const isQuote = ch === '"' || ch === "'";

    // 1) Закрывающий символ уже стоит за кареткой — «перешагиваем» его
    if (start === end && CLOSERS.has(ch) && value.charAt(start) === ch) {
        e.preventDefault();
        textarea.setSelectionRange(start + 1, start + 1);
        updateCursorPosition();
        return true;
    }

    if (!PAIRS[ch]) return false;

    // 2) Кавычку не открываем внутри слова (апостроф в «don't»)
    if (isQuote && !canOpenQuote(value, start)) return false;

    e.preventDefault();
    if (start !== end) {
        // выделение оборачиваем в пару, оставляя его выделенным
        const selected = value.substring(start, end);
        replaceRange(textarea, start, end, ch + selected + PAIRS[ch]);
        textarea.setSelectionRange(start + 1, end + 1);
    } else {
        replaceRange(textarea, start, start, ch + PAIRS[ch]);
        textarea.setSelectionRange(start + 1, start + 1);
    }
    updateCursorPosition();
    return true;
}

// Backspace между парой () [] {} "" '' удаляет обе половины
function handlePairBackspace(textarea, e) {
    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    if (start !== end || start === 0) return false;

    const value = textarea.value;
    const before = value.charAt(start - 1);
    const after = value.charAt(start);
    if (!PAIRS[before] || PAIRS[before] !== after) return false;

    e.preventDefault();
    replaceRange(textarea, start - 1, start + 1, '');
    textarea.setSelectionRange(start - 1, start - 1);
    updateCursorPosition();
    return true;
}

// Дублирование строки (или выделенного блока строк) — Ctrl+D
function duplicateLine(textarea) {
    const value = textarea.value;
    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;

    if (start === end) {
        // Без выделения — копируем строку под курсором целиком
        const lineStart = value.lastIndexOf('\n', start - 1) + 1;
        const lineEnd = value.indexOf('\n', start);
        const line = value.substring(lineStart, lineEnd === -1 ? value.length : lineEnd);
        // Копия вставляется строкой ниже — после перевода строки
        // (если строка последняя и без \n — добавляем \n перед копией)
        const insertPos = lineEnd === -1 ? value.length : lineEnd + 1;
        const insertText = lineEnd === -1 ? '\n' + line : line + '\n';
        replaceRange(textarea, insertPos, insertPos, insertText);
        // Курсор — на копию строки (та же колонка); если перевод строки
        // добавлялся перед копией, копия начинается на символ позже
        const column = start - lineStart;
        const copyStart = insertPos + (insertText.charAt(0) === '\n' ? 1 : 0);
        textarea.setSelectionRange(copyStart + column, copyStart + column);
    } else {
        // С выделением — копируем затронутые строки целиком и вставляем копию ниже
        const lineStart = value.lastIndexOf('\n', start - 1) + 1;
        // Блок — все строки, затронутые выделением:
        // если выделение заканчивается на \n, граница уже на начале строки,
        // иначе расширяем до конца строки, содержащей конец выделения
        let blockEnd = end;
        if (value[end - 1] !== '\n') {
            const nl = value.indexOf('\n', end - 1);
            blockEnd = nl === -1 ? value.length : nl + 1;
        }
        const block = value.substring(lineStart, blockEnd);
        const insertPos = blockEnd;
        // Если блок — последняя строка без \n, копию отделяем добавленным \n
        const insertText = block.endsWith('\n') ? block : '\n' + block;
        replaceRange(textarea, insertPos, insertPos, insertText);
        textarea.setSelectionRange(blockEnd + insertText.length, blockEnd + insertText.length);
    }
    updateCursorPosition();
}

async function submitSolution() {
    if (isRunning) return;

    const textarea = document.getElementById('code-editor');
    const code = textarea ? textarea.value : '';
    if (!code.trim()) {
        alert('Введите код решения');
        return;
    }

    const btn = document.getElementById('submit-btn');
    const status = document.getElementById('submit-status');
    const resultsContainer = document.getElementById('results-container');

    isRunning = true;
    if (btn) {
        btn.disabled = true;
        btn.textContent = '⏳ Проверка...';
    }
    if (status) {
        status.innerHTML = '<span class="spinner" aria-hidden="true"></span> Выполняется...';
    }
    if (resultsContainer) resultsContainer.style.display = 'none';

    try {
        const response = await fetch('index.php?page=api&endpoint=submit', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                task_id: window.TASK_ID,
                code: code,
                contest_id: window.CONTEST_ID
            })
        });

        let data = null;
        try {
            data = await response.json();
        } catch (parseError) {
            data = null;
        }

        if (!response.ok || !data) {
            showSubmitError(data && data.error ? data.error : httpErrorMessage(response.status));
            return;
        }
        if (data.error) {
            showSubmitError(data.error);
            return;
        }

        // Код отправлен — черновик совпадает с последней посылкой
        saveDraftNow();

        // Обновляем список попыток под задачей и показываем результаты
        addAttemptRow(data);
        showResults(data);
        if (status) status.innerHTML = '';
        if (btn) btn.textContent = '▶ Отправить ещё раз';

    } catch (err) {
        showSubmitError('Не удалось связаться с сервером' +
            (err && err.message ? ': ' + err.message : ''));
    } finally {
        if (btn) btn.disabled = false;
        isRunning = false;
    }
}

// Понятное сообщение вместо «Unexpected token» при не-JSON ответе
function httpErrorMessage(status) {
    if (status === 429) return 'Слишком много посылок подряд — подождите немного и попробуйте снова.';
    if (status === 401 || status === 403) return 'Сессия истекла — обновите страницу и войдите заново.';
    if (status >= 500) return 'Ошибка на сервере (HTTP ' + status + '). Попробуйте позже.';
    if (!status) return 'Сервер вернул некорректный ответ. Обновите страницу и попробуйте снова.';
    return 'Сервер отклонил запрос (HTTP ' + status + ').';
}

function showSubmitError(message) {
    const status = document.getElementById('submit-status');
    const btn = document.getElementById('submit-btn');
    if (status) {
        status.innerHTML = '<span style="color:red;">Ошибка: ' + escapeHtml(message) + '</span>';
    }
    if (btn) btn.textContent = '▶ Отправить';
}

function showResults(data) {
    const container = document.getElementById('results-container');
    const summary = document.getElementById('results-summary');
    const detail = document.getElementById('results-detail');
    if (!container || !summary || !detail) return;

    // Канонические метки статусов приходят из includes/labels.php
    const labels = window.STATUS_LABELS || {};
    container.style.display = 'block';

    // Обработка ошибок линтинга
    if (Array.isArray(data.lint_errors) && data.lint_errors.length > 0) {
        summary.innerHTML = '<div class="alert alert-error results-summary-alert">⚠ Ошибки оформления кода (PEP 8)</div>';

        let detailHtml = '<h4>Ошибки оформления:</h4><div class="results-table-wrap">' +
            '<table><thead><tr><th>Строка</th><th>Столбец</th><th>Код</th><th>Описание</th></tr></thead><tbody>';

        data.lint_errors.forEach(function(err) {
            detailHtml += '<tr>' +
                '<td>' + (parseInt(err && err.line, 10) || 0) + '</td>' +
                '<td>' + (parseInt(err && err.column, 10) || 0) + '</td>' +
                '<td><code class="lint-code">' + escapeHtml(err && err.code) + '</code></td>' +
                '<td>' + escapeHtml(err && err.message) + '</td>' +
                '</tr>';
        });

        detailHtml += '</tbody></table></div>';
        detail.innerHTML = detailHtml;
        container.scrollIntoView({ behavior: 'smooth' });
        return;
    }

    // Сводка
    const passed = parseInt(data.passed, 10) || 0;
    const total = parseInt(data.total, 10) || 0;
    const totalTime = Number(data.total_time) || 0;

    let summaryHtml;
    if (data.all_passed) {
        summaryHtml = '<div class="alert alert-success results-summary-alert">✓ Все тесты пройдены (' +
            passed + '/' + total + ') за ' + totalTime + ' сек</div>';
    } else if (data.status === 'no_function') {
        summaryHtml = '<div class="alert alert-error">⚠ В решении не найдена функция, которую требовалось ' +
            'написать. Проверьте, что функция определена на верхнем уровне кода и названа точно так, как в условии задачи.</div>';
    } else {
        const label = labels[data.status] || data.status || 'Ошибка';
        summaryHtml = '<div class="alert alert-error">' + escapeHtml(label) +
            ' — пройдено ' + passed + '/' + total + ' тестов за ' + totalTime + ' сек</div>';
    }
    summary.innerHTML = summaryHtml;

    // Детали по публичным тестам
    const publicResults = Array.isArray(data.public_results) ? data.public_results : [];
    let detailHtml = '<h4>Публичные тесты:</h4>';

    if (publicResults.length === 0) {
        detailHtml += '<p class="results-empty">Нет публичных тестов для отображения.</p>';
    }

    publicResults.forEach(function(test) {
        const testNumber = parseInt(test && test.test_number, 10) || 0;
        const testStatus = (test && test.status) || '';
        const label = labels[testStatus] || testStatus;
        // Пустой вывод тоже показываем — иначе «неверный ответ» без объяснения
        const hasOutput = test && test.output !== undefined && test.output !== null;
        const outputText = hasOutput && String(test.output) === ''
            ? '<span class="test-empty">(пусто)</span>'
            : escapeHtml(hasOutput ? test.output : '');

        detailHtml += '<div class="test-result test-result-card">' +
            '<div class="test-header">' +
                '<strong>Тест #' + testNumber + '</strong>' +
                '<span class="submission-status ' + statusModifier(testStatus) + '">' +
                    escapeHtml(label) + '</span>' +
            '</div>' +
            '<div class="test-columns">' +
                '<div>' +
                    '<div class="test-label">Входные данные</div>' +
                    '<pre class="test-block">' + escapeHtml(test && test.input) + '</pre>' +
                '</div>' +
                '<div>' +
                    '<div class="test-label">Ожидаемый вывод</div>' +
                    '<pre class="test-block">' + escapeHtml(test && test.expected) + '</pre>' +
                '</div>' +
            '</div>' +
            (hasOutput
                ? '<div class="test-extra"><div class="test-label">Ваш вывод</div>' +
                  '<pre class="test-block">' + outputText + '</pre></div>'
                : '') +
            (test && test.error
                ? '<div class="test-extra"><div class="test-label test-label-error">Ошибка</div>' +
                  '<pre class="test-block error-block">' + escapeHtml(test.error) + '</pre></div>'
                : '') +
            '</div>';
    });

    detail.innerHTML = detailHtml;
    container.scrollIntoView({ behavior: 'smooth' });
}

// Класс статуса для CSS: 'status-' + безопасный идентификатор
function statusModifier(status) {
    return 'status-' + String(status || '').replace(/[^a-zA-Z0-9_-]/g, '');
}

/**
 * Добавляет строку с новой попыткой в список «Мои попытки» под задачей
 * (без перезагрузки страницы). Данные берутся из ответа submit-API:
 * submission_id, status, total_time.
 */
function addAttemptRow(data) {
    if (!data || !data.submission_id) return;
    const tbody = document.getElementById('attempts-tbody');
    if (!tbody) return;

    const submissionId = parseInt(data.submission_id, 10);
    if (!submissionId) return;

    // Убираем заглушку «Попыток ещё не было», если она есть
    const emptyRow = document.getElementById('attempts-empty-row');
    if (emptyRow) emptyRow.remove();

    const labels = window.STATUS_LABELS || {};
    const status = data.status || 'pending';
    const label = labels[status] || status;
    const time = (typeof data.total_time === 'number' && isFinite(data.total_time))
        ? data.total_time.toFixed(3)
        : '0.000';

    const tr = document.createElement('tr');
    tr.innerHTML =
        '<td>' + submissionId + '</td>' +
        '<td>' + escapeHtml(window.CONTEST_TITLE || '') + '</td>' +
        '<td><span class="submission-status ' + statusModifier(status) + '">' +
            escapeHtml(label) + '</span></td>' +
        '<td>' + time + '</td>' +
        '<td>только что</td>' +
        '<td><a href="?page=submission-detail&id=' + submissionId + '" class="btn btn-small">Просмотр</a></td>';

    tbody.insertBefore(tr, tbody.firstChild);

    // Обновляем бедж статуса у заголовка задачи
    const badge = document.getElementById('last-status-badge');
    if (badge) {
        badge.className = 'submission-status ' + statusModifier(status);
        badge.textContent = label;
        badge.style.display = '';
    }
}

// Экранирование для HTML-текста и для значений атрибутов (кавычки — тоже)
function escapeHtml(text) {
    return String(text === undefined || text === null ? '' : text)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

/**
 * Обновляет позицию курсора в статусбаре и предупреждения PEP 8
 * для текущей строки (длина, табуляция, пробелы в конце).
 */
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

    updatePep8Warning(text.split('\n')[line - 1] || '');
}

function updatePep8Warning(lineText) {
    const warning = document.getElementById('pep8-warning');
    if (!warning) return;

    const issues = lineIssues(lineText);
    const notes = [];
    if (issues.long) {
        notes.push('длина ' + lineText.length + ' — PEP 8: ≤' + MAX_LINE_LENGTH);
    }
    if (issues.tab) {
        notes.push('табуляция — нужны пробелы');
    }
    if (issues.trailing) {
        notes.push('пробелы в конце строки');
    }

    warning.textContent = notes.join(' · ');
    warning.classList.toggle('has-warning', notes.length > 0);
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

// Модальное окно со справкой по PEP 8
let pep8LastFocus = null;

function showPep8Help() {
    const modal = document.getElementById('pep8-modal');
    if (!modal) return;
    pep8LastFocus = document.activeElement;
    modal.style.display = 'flex';
    const closeBtn = modal.querySelector('.modal-close');
    if (closeBtn) closeBtn.focus();
}

function closePep8Help() {
    const modal = document.getElementById('pep8-modal');
    if (!modal) return;
    modal.style.display = 'none';
    // Возвращаем фокус на кнопку, с которой окно открыли
    if (pep8LastFocus && typeof pep8LastFocus.focus === 'function') {
        pep8LastFocus.focus();
    }
    pep8LastFocus = null;
}

// Escape закрывает окно PEP 8, Tab не уводит фокус за его пределы
document.addEventListener('keydown', function(e) {
    const modal = document.getElementById('pep8-modal');
    if (!modal || modal.style.display === 'none') return;

    if (e.key === 'Escape') {
        e.preventDefault();
        closePep8Help();
        return;
    }
    if (e.key !== 'Tab') return;

    const focusable = Array.prototype.filter.call(
        modal.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'),
        function(el) { return !el.disabled && el.offsetParent !== null; }
    );
    if (focusable.length === 0) return;

    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (e.shiftKey && document.activeElement === first) {
        e.preventDefault();
        last.focus();
    } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault();
        first.focus();
    }
});

/**
 * Инициализация редактора: синхронизация скролла, номеров строк, подсветки.
 * Вызывается после загрузки DOM.
 */
function initEditor() {
    const textarea = document.getElementById('code-editor');
    const highlightLayer = document.getElementById('highlight-layer');

    if (!textarea || !highlightLayer) return;

    // Первый рендер подсветки — до того, как спрячем текст textarea,
    // чтобы код не мигал пустым блоком
    SyntaxHighlight.update();
    updateCursorPosition();

    // Код в textarea делаем прозрачным только когда подсветка работает:
    // если JS не загрузился, текст остаётся видимым обычным цветом
    const container = textarea.closest('.editor-container');
    if (container) container.classList.add('editor-ready');

    // Подсветка (в ней же рисуются номера строк) при вводе
    textarea.addEventListener('input', function() {
        updateCursorPosition();
        scheduleHighlight();
        scheduleDraftSave();
    });

    // Позиция курсора и предупреждения PEP 8 — в том числе при выделении мышью
    textarea.addEventListener('click', updateCursorPosition);
    textarea.addEventListener('keyup', updateCursorPosition);
    textarea.addEventListener('select', updateCursorPosition);
    document.addEventListener('selectionchange', function() {
        if (document.activeElement === textarea) updateCursorPosition();
    });

    // Горячие клавиши редактора
    textarea.addEventListener('keydown', function(e) {
        // Не мешаем IME-вводу
        if (e.isComposing) return;

        // Ctrl+Enter → отправка решения; Ctrl+D → дублирование строки;
        // Ctrl+/ → комментарий
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
            e.preventDefault();
            submitSolution();
            return;
        }
        if ((e.ctrlKey || e.metaKey) && !e.altKey && !e.shiftKey && (e.key === 'd' || e.key === 'D')) {
            e.preventDefault();
            duplicateLine(textarea);
            return;
        }
        if ((e.ctrlKey || e.metaKey) && (e.key === '/' || e.code === 'Slash')) {
            e.preventDefault();
            toggleComment(textarea);
            return;
        }

        // Escape — выйти из редактора: Tab перехвачен, иначе не выбраться
        if (e.key === 'Escape') {
            textarea.blur();
            return;
        }

        if (e.ctrlKey || e.metaKey || e.altKey) return;

        // Tab → отступ, Shift+Tab → снять отступ (PEP 8)
        if (e.key === 'Tab') {
            e.preventDefault();
            if (e.shiftKey) {
                shiftIndent(textarea, -1);
            } else {
                insertIndent(textarea);
            }
            return;
        }

        // Enter → автоотступ (Shift+Enter — обычный перевод строки)
        if (e.key === 'Enter' && !e.shiftKey) {
            autoIndentNewline(textarea, e);
            return;
        }

        if (handleAutoPair(textarea, e)) return;
        if (e.key === 'Backspace') handlePairBackspace(textarea, e);
    });

    // Синхронизируем прокрутку highlight-слоя с textarea
    // (своих полос прокрутки у слоя нет, они скрыты в CSS)
    textarea.addEventListener('scroll', function() {
        highlightLayer.scrollTop = this.scrollTop;
        highlightLayer.scrollLeft = this.scrollLeft;
    });

    // Обновляем при paste (через setTimeout, чтобы value уже обновилось)
    textarea.addEventListener('paste', function() {
        setTimeout(function() {
            updateCursorPosition();
            scheduleHighlight();
            scheduleDraftSave();
        }, 0);
    });

    // Обновляем при cut (через setTimeout)
    textarea.addEventListener('cut', function() {
        setTimeout(function() {
            updateCursorPosition();
            scheduleHighlight();
            scheduleDraftSave();
        }, 0);
    });

    // Ширина редактора влияет на перенос строк — перерисовываем слой
    // при изменении размеров окна и ручном resize за угол
    if (typeof ResizeObserver !== 'undefined') {
        new ResizeObserver(function() {
            scheduleHighlight();
        }).observe(textarea);
    } else {
        window.addEventListener('resize', scheduleHighlight);
    }

    // Не терять последние нажатия при закрытии вкладки
    window.addEventListener('pagehide', saveDraftNow);
    document.addEventListener('visibilitychange', function() {
        if (document.visibilityState === 'hidden') saveDraftNow();
    });

    // Первоначальное заполнение позиции курсора и подсветки.
    // Небольшой setTimeout — чтобы значение из localStorage уже было установлено
    setTimeout(function() {
        updateCursorPosition();
        SyntaxHighlight.update();
        highlightLayer.scrollTop = textarea.scrollTop;
    }, 0);
}
