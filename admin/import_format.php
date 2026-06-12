<?php
$pageTitle = 'Формат импорта задач';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            line-height: 1.7;
            color: #1f2937;
            background: #f9fafb;
            padding: 20px;
        }
        .container { max-width: 960px; margin: 0 auto; background: #fff; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,.1); padding: 40px; }
        h1 { font-size: 1.8rem; margin-bottom: 0.3rem; }
        h2 { font-size: 1.3rem; margin: 1.8rem 0 0.6rem; padding-bottom: 0.3rem; border-bottom: 2px solid #e5e7eb; }
        h3 { font-size: 1.1rem; margin: 1.4rem 0 0.4rem; }
        p  { margin-bottom: 0.8rem; }
        a  { color: #2563eb; text-decoration: none; }
        a:hover { text-decoration: underline; }
        pre {
            background: #1e293b; color: #e2e8f0; padding: 16px; border-radius: 8px;
            overflow-x: auto; font-size: 0.85rem; line-height: 1.5; margin: 0.8rem 0;
        }
        code { font-family: 'JetBrains Mono', 'Fira Code', monospace; }
        p code, li code { background: #f1f5f9; padding: 0.15rem 0.4rem; border-radius: 4px; font-size: 0.9em; color: #0f172a; }
        table { width: 100%; border-collapse: collapse; margin: 0.8rem 0; }
        th, td { text-align: left; padding: 8px 12px; border: 1px solid #d1d5db; }
        th { background: #f3f4f6; font-weight: 600; }
        tr:nth-child(even) td { background: #f9fafb; }
        ul { margin: 0.4rem 0 0.8rem 1.5rem; }
        li { margin-bottom: 0.3rem; }
        .back-link { display: inline-block; margin-bottom: 1.2rem; font-size: 0.9rem; }
        .badge { display: inline-block; background: #dbeafe; color: #1e40af; font-size: 0.75rem; padding: 0.15rem 0.5rem; border-radius: 4px; }
    </style>
</head>
<body>
<div class="container">
    <a href="?page=admin-import-tasks" class="back-link">← Вернуться к импорту задач</a>

    <h1>Формат импорта задач</h1>
    <p style="color:#6b7280;">JSON-формат для пакетного добавления задач в базу данных.</p>

    <!-- ============================================================ -->
    <h2>Общая структура</h2>
    <p>Файл импорта представляет собой <strong>JSON-массив</strong> объектов задач. Каждый объект описывает одну задачу со всеми тестами.</p>
    <pre><code>[
    {
        "title": "Название задачи",
        "given": "<p>HTML-условие задачи</p>",
        "input_format": "<p>HTML-описание формата входных данных</p>",
        "output_format": "<p>HTML-описание формата выходных данных</p>",
        "time_limit": 1.0,
        "memory_limit": 64,
        "tests": [
            {
                "input": "входные данные",
                "output": "ожидаемый вывод",
                "is_public": 1
            }
        ]
    }
]</code></pre>

    <!-- ============================================================ -->
    <h2>Поля задачи</h2>
    <table>
        <thead>
            <tr><th>Поле</th><th>Тип</th><th>Обязательность</th><th>Описание</th></tr>
        </thead>
        <tbody>
            <tr>
                <td><code>title</code></td>
                <td>строка</td>
                <td><span class="badge">да</span></td>
                <td>Название задачи (отображается в списке задач)</td>
            </tr>
            <tr>
                <td><code>given</code></td>
                <td>строка</td>
                <td><span class="badge">да</span></td>
                <td>Условие задачи в формате HTML. Допускается LaTeX-разметка: <code>\(...\)</code> для inline и <code>\[...\]</code> для display-формул</td>
            </tr>
            <tr>
                <td><code>input_format</code></td>
                <td>строка</td>
                <td><span class="badge">да</span></td>
                <td>Описание формата входных данных в HTML</td>
            </tr>
            <tr>
                <td><code>output_format</code></td>
                <td>строка</td>
                <td><span class="badge">да</span></td>
                <td>Описание формата выходных данных в HTML</td>
            </tr>
            <tr>
                <td><code>time_limit</code></td>
                <td>число (float)</td>
                <td><span class="badge">нет</span><br><small>по умолч. <code>1.0</code></small></td>
                <td>Лимит времени выполнения в секундах</td>
            </tr>
            <tr>
                <td><code>memory_limit</code></td>
                <td>число (int)</td>
                <td><span class="badge">нет</span><br><small>по умолч. <code>64</code></small></td>
                <td>Лимит памяти в мегабайтах</td>
            </tr>
            <tr>
                <td><code>tests</code></td>
                <td>массив объектов</td>
                <td><span class="badge">да</span></td>
                <td>Массив тестов (см. таблицу ниже)</td>
            </tr>
        </tbody>
    </table>

    <!-- ============================================================ -->
    <h2>Поля теста (<code>tests[]</code>)</h2>
    <table>
        <thead>
            <tr><th>Поле</th><th>Тип</th><th>Обязательность</th><th>Описание</th></tr>
        </thead>
        <tbody>
            <tr>
                <td><code>input</code></td>
                <td>строка</td>
                <td><span class="badge">да</span></td>
                <td>Входные данные для теста. Для многострочного ввода используйте символ <code>\n</code> (реальный перевод строки в JSON)</td>
            </tr>
            <tr>
                <td><code>output</code></td>
                <td>строка</td>
                <td><span class="badge">да</span></td>
                <td>Ожидаемый вывод программы. Сравнение посимвольное (с учётом пробелов и переводов строк)</td>
            </tr>
            <tr>
                <td><code>is_public</code></td>
                <td>число (0 или 1)</td>
                <td><span class="badge">нет</span><br><small>по умолч. <code>0</code></small></td>
                <td>Флаг публичности: <code>1</code> — тест виден пользователю, <code>0</code> — скрытый тест</td>
            </tr>
        </tbody>
    </table>

    <!-- ============================================================ -->
    <h2>Пример</h2>

    <h3>Минимальная задача (один тест)</h3>
    <pre><code>[
    {
        "title": "Сумма двух чисел",
        "given": "<p>Найдите сумму двух целых чисел.</p>",
        "input_format": "<p>Даны два целых числа, каждое на отдельной строке.</p>",
        "output_format": "<p>Выведите одно целое число — сумму.</p>",
        "tests": [
            {
                "input": "2\n3",
                "output": "5",
                "is_public": 1
            }
        ]
    }
]</code></pre>

    <h3>Полный пример (первая задача из набора)</h3>
    <pre><code>{
    "title": "Периметр квадрата",
    "given": "<p>Вычислите и выведите значение <strong>периметра</strong> квадрата со стороной, равной числу, подаваемому на ввод.</p>\n<p><strong>Формула:</strong> \\(P = 4 \\times a\\), где \\(a\\) — длина стороны квадрата.</p>",
    "input_format": "<p>Дано одно целое число \\(a\\) (\\(1 \\le a \\le 10^9\\)) — длина стороны квадрата.</p>",
    "output_format": "<p>Выведите одно целое число — периметр квадрата.</p>",
    "time_limit": 1.0,
    "memory_limit": 64,
    "tests": [
        { "input": "5",    "output": "20",   "is_public": 1 },
        { "input": "10",   "output": "40",   "is_public": 1 },
        { "input": "1",    "output": "4",    "is_public": 1 },
        { "input": "7",    "output": "28",   "is_public": 0 },
        { "input": "25",   "output": "100",  "is_public": 0 },
        { "input": "1000", "output": "4000", "is_public": 0 },
        { "input": "999",  "output": "3996", "is_public": 0 },
        { "input": "50",   "output": "200",  "is_public": 0 },
        { "input": "33",   "output": "132",  "is_public": 0 },
        { "input": "2",    "output": "8",    "is_public": 0 }
    ]
}</code></pre>

    <!-- ============================================================ -->
    <h2>Правила валидации</h2>
    <ol>
        <li><strong>Файл должен быть в кодировке UTF-8</strong> без BOM.</li>
        <li><strong>Корневой элемент</strong> должен быть <strong>массивом</strong> (JSON-массив).</li>
        <li><strong>Каждый элемент массива</strong> — объект с обязательными полями:
            <ul>
                <li><code>title</code> — непустая строка</li>
                <li><code>given</code> — строка (может быть пустой)</li>
                <li><code>input_format</code> — строка (может быть пустой)</li>
                <li><code>output_format</code> — строка (может быть пустой)</li>
                <li><code>tests</code> — непустой массив</li>
            </ul>
        </li>
        <li><strong>Каждый тест</strong> должен быть объектом с полями:
            <ul>
                <li><code>input</code> — строка (может быть пустой)</li>
                <li><code>output</code> — строка (может быть пустой)</li>
                <li><code>is_public</code> — число 0 или 1 (опционально, по умолчанию 0)</li>
            </ul>
        </li>
        <li><strong><code>time_limit</code></strong> — положительное число (float), не более 60 секунд.</li>
        <li><strong><code>memory_limit</code></strong> — положительное целое число, не более 1024 МБ.</li>
        <li><strong>Дубликаты названий задач</strong> допускаются, но не рекомендуются.</li>
    </ol>

    <!-- ============================================================ -->
    <h2>Рекомендации</h2>
    <ul>
        <li>Используйте <strong>UTF-8 без BOM</strong> для сохранения файла.</li>
        <li>Для многострочных входных данных используйте экранирование <code>\n</code> в JSON.</li>
        <li>Публичных тестов должно быть <strong>не менее 1</strong>, иначе пользователь не сможет проверить своё решение перед отправкой.</li>
        <li>Скрытые тесты используются для финальной проверки и не видны пользователю.</li>
        <li>Рекомендуется указывать от <strong>5 до 10 тестов</strong> на задачу для надёжной проверки решений.</li>
        <li>Для математических формул используйте LaTeX-разметку с <code>\(...\)</code> / <code>\[...\]</code>.</li>
    </ul>

    <hr style="margin:2rem 0; border:none; border-top:1px solid #e5e7eb;">
    <p style="color:#9ca3af; font-size:0.85rem;">
        Описание формата импорта задач · <a href="?page=admin-import-tasks">← Вернуться к импорту</a>
    </p>
</div>
</body>
</html>