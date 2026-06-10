<?php
$pageTitle = 'Генерация 10 задач';
$db = Database::getInstance();
$message = '';
$error = '';
$results = [];

$tasksData = [
    // 1. Периметр квадрата
    [
        'title' => 'Периметр квадрата',
        'condition' => '<p>Вычислите и выведите значение <strong>периметра</strong> квадрата со стороной, равной числу, подаваемому на ввод.</p>
<p><strong>Формула:</strong> \(P = 4 \times a\), где \(a\) — длина стороны квадрата.</p>',
        'input_format' => '<p>Дано одно целое число \(a\) (\(1 \le a \le 10^9\)) — длина стороны квадрата.</p>',
        'output_format' => '<p>Выведите одно целое число — периметр квадрата.</p>',
        'time_limit' => 1.0,
        'memory_limit' => 64,
        'tests' => [
            ['input' => "5",    'output' => "20",   'is_public' => 1],
            ['input' => "10",   'output' => "40",   'is_public' => 1],
            ['input' => "1",    'output' => "4",    'is_public' => 1],
            ['input' => "7",    'output' => "28",   'is_public' => 0],
            ['input' => "25",   'output' => "100",  'is_public' => 0],
            ['input' => "1000", 'output' => "4000", 'is_public' => 0],
            ['input' => "999",  'output' => "3996", 'is_public' => 0],
            ['input' => "50",   'output' => "200",  'is_public' => 0],
            ['input' => "33",   'output' => "132",  'is_public' => 0],
            ['input' => "2",    'output' => "8",    'is_public' => 0],
        ],
    ],
    // 2. Площадь квадрата
    [
        'title' => 'Площадь квадрата',
        'condition' => '<p>Вычислите и выведите значение <strong>площади</strong> квадрата со стороной, равной числу, подаваемому на ввод.</p>
<p><strong>Формула:</strong> \(S = a^2\), где \(a\) — длина стороны квадрата.</p>',
        'input_format' => '<p>Дано одно целое число \(a\) (\(1 \le a \le 10^9\)) — длина стороны квадрата.</p>',
        'output_format' => '<p>Выведите одно целое число — площадь квадрата.</p>',
        'time_limit' => 1.0,
        'memory_limit' => 64,
        'tests' => [
            ['input' => "5",    'output' => "25",     'is_public' => 1],
            ['input' => "10",   'output' => "100",    'is_public' => 1],
            ['input' => "1",    'output' => "1",      'is_public' => 1],
            ['input' => "7",    'output' => "49",     'is_public' => 0],
            ['input' => "25",   'output' => "625",    'is_public' => 0],
            ['input' => "1000", 'output' => "1000000",'is_public' => 0],
            ['input' => "999",  'output' => "998001", 'is_public' => 0],
            ['input' => "15",   'output' => "225",    'is_public' => 0],
            ['input' => "33",   'output' => "1089",   'is_public' => 0],
            ['input' => "2",    'output' => "4",      'is_public' => 0],
        ],
    ],
    // 3. Площадь и периметр прямоугольника
    [
        'title' => 'Площадь и периметр прямоугольника',
        'condition' => '<p>Вычислите и выведите значение <strong>площади и периметра</strong> прямоугольника со сторонами, равными числам, подаваемым на ввод.</p>
<p><strong>Формулы:</strong></p>
<ul>
<li>Площадь: \(S = a \times b\)</li>
<li>Периметр: \(P = 2 \times (a + b)\)</li>
</ul>',
        'input_format' => '<p>Даны два целых числа \(a\) и \(b\) (\(1 \le a, b \le 10^9\)) — длины сторон прямоугольника. Каждое число вводится на отдельной строке.</p>',
        'output_format' => '<p>Выведите два целых числа через пробел: сначала площадь, затем периметр прямоугольника.</p>',
        'time_limit' => 1.0,
        'memory_limit' => 64,
        'tests' => [
            ['input' => "5\n8",     'output' => "40 26",    'is_public' => 1],
            ['input' => "10\n10",   'output' => "100 40",   'is_public' => 1],
            ['input' => "1\n1",     'output' => "1 4",      'is_public' => 1],
            ['input' => "7\n3",     'output' => "21 20",    'is_public' => 0],
            ['input' => "100\n200", 'output' => "20000 600",'is_public' => 0],
            ['input' => "999\n1",   'output' => "999 2000", 'is_public' => 0],
            ['input' => "12\n15",   'output' => "180 54",   'is_public' => 0],
            ['input' => "9\n5",     'output' => "45 28",    'is_public' => 0],
            ['input' => "4\n4",     'output' => "16 16",    'is_public' => 0],
            ['input' => "6\n9",     'output' => "54 30",    'is_public' => 0],
        ],
    ],
    // 4. Длина окружности по диаметру
    [
        'title' => 'Длина окружности по диаметру',
        'condition' => '<p>Дан диаметр окружности \(d\). Найдите её <strong>длину</strong>.</p>
<p><strong>Формула:</strong> \(L = \pi \times d\).</p>
<p>В качестве значения \(\pi\) использовать <strong>3.14159</strong>.</p>',
        'input_format' => '<p>Дано одно целое число \(d\) (\(1 \le d \le 10^5\)) — диаметр окружности.</p>',
        'output_format' => '<p>Выведите одно вещественное число — длину окружности, округлённую до 5 знаков после запятой.</p>',
        'time_limit' => 1.0,
        'memory_limit' => 64,
        'tests' => [
            ['input' => "10",  'output' => "31.41590",  'is_public' => 1],
            ['input' => "1",   'output' => "3.14159",   'is_public' => 1],
            ['input' => "100", 'output' => "314.15900", 'is_public' => 1],
            ['input' => "7",   'output' => "21.99113",  'is_public' => 0],
            ['input' => "25",  'output' => "78.53975",  'is_public' => 0],
            ['input' => "999", 'output' => "3138.44841",'is_public' => 0],
            ['input' => "50",  'output' => "157.07950", 'is_public' => 0],
            ['input' => "33",  'output' => "103.67247", 'is_public' => 0],
            ['input' => "2",   'output' => "6.28318",   'is_public' => 0],
            ['input' => "75",  'output' => "235.61925", 'is_public' => 0],
        ],
    ],
    // 5. Объём и площадь поверхности куба
    [
        'title' => 'Объём и площадь поверхности куба',
        'condition' => '<p>Дана длина ребра куба \(a\). Найдите <strong>объём куба</strong> и <strong>площадь его поверхности</strong>.</p>
<p><strong>Формулы:</strong></p>
<ul>
<li>Объём: \(V = a^3\)</li>
<li>Площадь поверхности: \(S = 6 \times a^2\)</li>
</ul>',
        'input_format' => '<p>Дано одно целое число \(a\) (\(1 \le a \le 10^6\)) — длина ребра куба.</p>',
        'output_format' => '<p>Выведите два целых числа через пробел: сначала объём куба, затем площадь его поверхности.</p>',
        'time_limit' => 1.0,
        'memory_limit' => 64,
        'tests' => [
            ['input' => "3",   'output' => "27 54",       'is_public' => 1],
            ['input' => "1",   'output' => "1 6",         'is_public' => 1],
            ['input' => "10",  'output' => "1000 600",    'is_public' => 1],
            ['input' => "5",   'output' => "125 150",     'is_public' => 0],
            ['input' => "2",   'output' => "8 24",        'is_public' => 0],
            ['input' => "100", 'output' => "1000000 60000",'is_public' => 0],
            ['input' => "7",   'output' => "343 294",     'is_public' => 0],
            ['input' => "15",  'output' => "3375 1350",   'is_public' => 0],
            ['input' => "4",   'output' => "64 96",       'is_public' => 0],
            ['input' => "20",  'output' => "8000 2400",   'is_public' => 0],
        ],
    ],
    // 6. Объём и площадь поверхности прямоугольного параллелепипеда
    [
        'title' => 'Объём и площадь поверхности прямоугольного параллелепипеда',
        'condition' => '<p>Вычислите и выведите значение <strong>объёма</strong> и <strong>площади поверхности</strong> прямоугольного параллелепипеда по введённым длине, ширине и высоте.</p>
<p><strong>Формулы:</strong></p>
<ul>
<li>Объём: \(V = a \times b \times c\)</li>
<li>Площадь поверхности: \(S = 2 \times (a \cdot b + a \cdot c + b \cdot c)\)</li>
</ul>',
        'input_format' => '<p>Даны три целых числа \(a\), \(b\), \(c\) (\(1 \le a, b, c \le 10^6\)) — длина, ширина и высота прямоугольного параллелепипеда. Каждое число вводится на отдельной строке.</p>',
        'output_format' => '<p>Выведите два целых числа через пробел: сначала объём, затем площадь поверхности.</p>',
        'time_limit' => 1.0,
        'memory_limit' => 64,
        'tests' => [
            ['input' => "3\n4\n5",     'output' => "60 94",    'is_public' => 1],
            ['input' => "1\n1\n1",     'output' => "1 6",      'is_public' => 1],
            ['input' => "10\n10\n10",  'output' => "1000 600", 'is_public' => 1],
            ['input' => "2\n3\n4",     'output' => "24 52",    'is_public' => 0],
            ['input' => "5\n6\n7",     'output' => "210 214",  'is_public' => 0],
            ['input' => "1\n2\n3",     'output' => "6 22",     'is_public' => 0],
            ['input' => "4\n5\n6",     'output' => "120 148",  'is_public' => 0],
            ['input' => "7\n8\n9",     'output' => "504 382",  'is_public' => 0],
            ['input' => "10\n20\n30",  'output' => "6000 2200",'is_public' => 0],
            ['input' => "2\n2\n2",     'output' => "8 24",     'is_public' => 0],
        ],
    ],
    // 7. Длина окружности и площадь круга
    [
        'title' => 'Длина окружности и площадь круга',
        'condition' => '<p>Найдите <strong>длину окружности \(L\)</strong> и <strong>площадь круга \(S\)</strong> заданного радиуса \(R\).</p>
<p><strong>Формулы:</strong></p>
<ul>
<li>Длина окружности: \(L = 2 \times \pi \times R\)</li>
<li>Площадь круга: \(S = \pi \times R^2\)</li>
</ul>
<p>В качестве значения \(\pi\) использовать <strong>3.14159</strong>.</p>',
        'input_format' => '<p>Дано одно целое число \(R\) (\(1 \le R \le 10^5\)) — радиус окружности.</p>',
        'output_format' => '<p>Выведите два вещественных числа через пробел: сначала длину окружности, затем площадь круга. Каждое число округлите до 5 знаков после запятой.</p>',
        'time_limit' => 1.0,
        'memory_limit' => 64,
        'tests' => [
            ['input' => "5",   'output' => "31.41590 78.53975",    'is_public' => 1],
            ['input' => "1",   'output' => "6.28318 3.14159",      'is_public' => 1],
            ['input' => "10",  'output' => "62.83180 314.15900",   'is_public' => 1],
            ['input' => "3",   'output' => "18.84954 28.27431",    'is_public' => 0],
            ['input' => "7",   'output' => "43.98226 153.93791",   'is_public' => 0],
            ['input' => "25",  'output' => "157.07950 1963.49375", 'is_public' => 0],
            ['input' => "100", 'output' => "628.31800 31415.90000",'is_public' => 0],
            ['input' => "2",   'output' => "12.56636 12.56636",    'is_public' => 0],
            ['input' => "8",   'output' => "50.26544 201.06176",   'is_public' => 0],
            ['input' => "15",  'output' => "94.24770 706.85775",   'is_public' => 0],
        ],
    ],
    // 8. Среднее арифметическое
    [
        'title' => 'Среднее арифметическое',
        'condition' => '<p>Даны два числа \(a\) и \(b\). Найдите их <strong>среднее арифметическое</strong>.</p>
<p><strong>Формула:</strong></p>
<p>\[x = \frac{a + b}{2}\]</p>',
        'input_format' => '<p>Даны два целых числа \(a\) и \(b\) (\(-10^9 \le a, b \le 10^9\)). Каждое число вводится на отдельной строке.</p>',
        'output_format' => '<p>Выведите одно вещественное число — среднее арифметическое чисел \(a\) и \(b\).</p>',
        'time_limit' => 1.0,
        'memory_limit' => 64,
        'tests' => [
            ['input' => "4\n6",     'output' => "5.0",   'is_public' => 1],
            ['input' => "10\n20",   'output' => "15.0",  'is_public' => 1],
            ['input' => "0\n100",   'output' => "50.0",  'is_public' => 1],
            ['input' => "-5\n5",    'output' => "0.0",   'is_public' => 0],
            ['input' => "-10\n-20", 'output' => "-15.0", 'is_public' => 0],
            ['input' => "7\n3",     'output' => "5.0",   'is_public' => 0],
            ['input' => "99\n1",    'output' => "50.0",  'is_public' => 0],
            ['input' => "-100\n100",'output' => "0.0",   'is_public' => 0],
            ['input' => "17\n23",   'output' => "20.0",  'is_public' => 0],
            ['input' => "-30\n10",  'output' => "-10.0", 'is_public' => 0],
        ],
    ],
    // 9. Среднее геометрическое
    [
        'title' => 'Среднее геометрическое',
        'condition' => '<p>Даны два неотрицательных числа \(a\) и \(b\). Найдите их <strong>среднее геометрическое</strong>, то есть квадратный корень из их произведения.</p>
<p><strong>Формула:</strong></p>
<p>\[x = \sqrt{a \times b}\]</p>',
        'input_format' => '<p>Даны два неотрицательных целых числа \(a\) и \(b\) (\(0 \le a, b \le 10^9\)). Каждое число вводится на отдельной строке.</p>',
        'output_format' => '<p>Выведите одно вещественное число — среднее геометрическое, округлённое до 6 знаков после запятой.</p>',
        'time_limit' => 1.0,
        'memory_limit' => 64,
        'tests' => [
            ['input' => "4\n9",    'output' => "6.000000",  'is_public' => 1],
            ['input' => "0\n100",  'output' => "0.000000",  'is_public' => 1],
            ['input' => "2\n8",    'output' => "4.000000",  'is_public' => 1],
            ['input' => "10\n10",  'output' => "10.000000", 'is_public' => 0],
            ['input' => "1\n1",    'output' => "1.000000",  'is_public' => 0],
            ['input' => "16\n25",  'output' => "20.000000", 'is_public' => 0],
            ['input' => "36\n4",   'output' => "12.000000", 'is_public' => 0],
            ['input' => "100\n1",  'output' => "10.000000", 'is_public' => 0],
            ['input' => "9\n16",   'output' => "12.000000", 'is_public' => 0],
            ['input' => "49\n1",   'output' => "7.000000",  'is_public' => 0],
        ],
    ],
    // 10. Сумма, разность, произведение и частное квадратов
    [
        'title' => 'Сумма, разность, произведение и частное квадратов',
        'condition' => '<p>Даны два ненулевых числа \(a\) и \(b\). Найдите <strong>сумму квадратов</strong>, <strong>разность квадратов</strong>, <strong>произведение квадратов</strong> и <strong>частное квадратов</strong>.</p>
<p><strong>Формулы:</strong></p>
<ul>
<li>Сумма квадратов: \(a^2 + b^2\)</li>
<li>Разность квадратов: \(a^2 - b^2\)</li>
<li>Произведение квадратов: \(a^2 \times b^2\)</li>
<li>Частное квадратов: \(a^2 / b^2\)</li>
</ul>',
        'input_format' => '<p>Даны два ненулевых целых числа \(a\) и \(b\) (\(-10^4 \le a, b \le 10^4\), \(a \neq 0\), \(b \neq 0\)). Каждое число вводится на отдельной строке.</p>',
        'output_format' => '<p>Выведите четыре числа через пробел: сумму квадратов, разность квадратов, произведение квадратов и частное квадратов (вещественное число, округлённое до 6 знаков после запятой).</p>',
        'time_limit' => 1.0,
        'memory_limit' => 64,
        'tests' => [
            ['input' => "3\n4", 'output' => "25 -7 144 0.562500",  'is_public' => 1],
            ['input' => "5\n2", 'output' => "29 21 100 6.250000",  'is_public' => 1],
            ['input' => "1\n1", 'output' => "2 0 1 1.000000",      'is_public' => 1],
            ['input' => "4\n2", 'output' => "20 12 64 4.000000",   'is_public' => 0],
            ['input' => "6\n3", 'output' => "45 27 324 4.000000",  'is_public' => 0],
            ['input' => "2\n5", 'output' => "29 -21 100 0.160000", 'is_public' => 0],
            ['input' => "7\n2", 'output' => "53 45 196 12.250000", 'is_public' => 0],
            ['input' => "3\n3", 'output' => "18 0 81 1.000000",    'is_public' => 0],
            ['input' => "1\n2", 'output' => "5 -3 4 0.250000",     'is_public' => 0],
            ['input' => "4\n5", 'output' => "41 -9 400 0.640000",  'is_public' => 0],
        ],
    ],
];

// Обработчик добавления столбца sort_order
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_sort_order_column') {
    try {
        // Проверяем, существует ли уже столбец
        $cols = $db->query("PRAGMA table_info(task_to_groups)")->fetchAll();
        $hasSortOrder = false;
        foreach ($cols as $col) {
            if ($col['name'] === 'sort_order') { $hasSortOrder = true; break; }
        }
        if (!$hasSortOrder) {
            $db->exec("ALTER TABLE task_to_groups ADD COLUMN sort_order INTEGER DEFAULT 0");
            $message = 'Столбец sort_order добавлен в таблицу task_to_groups.';
        } else {
            $message = 'Столбец sort_order уже существует.';
        }
    } catch (Exception $e) {
        $error = 'Ошибка при добавлении столбца: ' . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_all') {
    $successCount = 0;
    $failCount = 0;
    $groupName = 'Ввод и вывод данных, оператор присваивания';
    $groupDescription = 'Базовые задачи на ввод/вывод данных и оператор присваивания';
    $createdTaskIds = [];

    // Создать или найти группу задач
    $stmtGroup = $db->prepare("SELECT id FROM task_groups WHERE name = ?");
    $stmtGroup->execute([$groupName]);
    $groupId = $stmtGroup->fetchColumn();

    if (!$groupId) {
        $db->prepare("INSERT INTO task_groups (name, description) VALUES (?, ?)")->execute([$groupName, $groupDescription]);
        $groupId = $db->lastInsertId();
    }

    $stmtTask = $db->prepare("INSERT INTO tasks (title, condition, input_format, output_format, time_limit, memory_limit) VALUES (?, ?, ?, ?, ?, ?)");
    $stmtTest = $db->prepare("INSERT INTO tests (task_id, test_number, input, expected_output, is_public) VALUES (?, ?, ?, ?, ?)");
    $stmtLink = $db->prepare("INSERT OR IGNORE INTO task_to_groups (task_id, task_group_id, sort_order) VALUES (?, ?, ?)");
    $orderNum = 0;

    foreach ($tasksData as $task) {
        $orderNum++;
        try {
            $db->beginTransaction();

            $stmtTask->execute([
                $task['title'],
                $task['condition'],
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

            // Привязать задачу к группе с сохранением порядка
            $stmtLink->execute([$taskId, $groupId, $orderNum]);

            $db->commit();
            $createdTaskIds[] = $taskId;
            $results[] = ['title' => $task['title'], 'status' => 'success', 'id' => $taskId];
            $successCount++;
        } catch (Exception $e) {
            $db->rollBack();
            $results[] = ['title' => $task['title'], 'status' => 'error', 'error' => $e->getMessage()];
            $failCount++;
        }
    }

    $message = "Создано задач: {$successCount}. Группа: «{$groupName}» (ID: {$groupId})";
    if ($failCount > 0) {
        $error = "Ошибок: {$failCount}";
    }
}

ob_start();
?>

<h1>Генерация 10 задач с тестами</h1>

<div class="admin-nav">
    <a href="?page=admin">Дашборд</a>
    <a href="?page=admin-users">Пользователи</a>
    <a href="?page=admin-groups">Группы</a>
    <a href="?page=admin-tasks">Задачи</a>
    <a href="?page=admin-task-groups">Группы задач</a>
    <a href="?page=admin-contests">Контесты</a>
    <a href="?page=admin-submissions">Решения</a>
    <a href="?page=admin-generate-tasks" class="active">Генерация задач</a>
</div>

<?php if ($message): ?>
    <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if (!empty($results)): ?>
<div class="card" style="margin-bottom:20px;">
    <h3>Результаты генерации</h3>
    <table>
        <thead><tr><th>#</th><th>Название задачи</th><th>Статус</th></tr></thead>
        <tbody>
        <?php foreach ($results as $i => $r): ?>
        <tr>
            <td><?= $i + 1 ?></td>
            <td><?= htmlspecialchars($r['title']) ?></td>
            <td>
                <?php if ($r['status'] === 'success'): ?>
                    <span style="color:green;">✓ Создана (ID: <?= $r['id'] ?>)</span>
                <?php else: ?>
                    <span style="color:red;">✗ Ошибка: <?= htmlspecialchars($r['error']) ?></span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php
// Проверим, есть ли уже столбец sort_order
$migrationNeeded = true;
try {
    $cols = $db->query("PRAGMA table_info(task_to_groups)")->fetchAll();
    foreach ($cols as $col) {
        if ($col['name'] === 'sort_order') { $migrationNeeded = false; break; }
    }
} catch (Exception $e) {}
?>

<?php if ($migrationNeeded): ?>
<div class="card" style="margin-bottom:20px; border-left:4px solid var(--warning);">
    <h3 style="margin-top:0;">⚠ Подготовка базы данных</h3>
    <p style="color:var(--text-muted);">
        Для сохранения порядка задач в группе требуется добавить столбец <code>sort_order</code> в таблицу <code>task_to_groups</code>.
        Нажмите кнопку ниже <strong>один раз</strong> перед генерацией задач.
    </p>
    <form method="POST">
        <input type="hidden" name="action" value="add_sort_order_column">
        <button type="submit" class="btn" style="background:var(--warning); color:#000; border-color:var(--warning);">
            🔧 Добавить столбец sort_order
        </button>
    </form>
</div>
<?php else: ?>
<div class="card" style="margin-bottom:20px; border-left:4px solid var(--success); padding:16px;">
    <span style="color:var(--success);">✓ База данных готова: столбец <code>sort_order</code> присутствует.</span>
</div>
<?php endif; ?>

<p style="margin-bottom:20px; color:var(--text-muted);">
    Нажмите кнопку, чтобы добавить все 10 задач. Каждая задача содержит по <strong>10 тестов</strong>: 3 публичных (видны пользователю) и 7 скрытых.
</p>

<div class="card" style="margin-bottom:20px;">
    <h2>Предпросмотр задач (10 шт.)</h2>

    <form method="POST" onsubmit="return confirm('Добавить все 10 задач в базу данных?');">
        <input type="hidden" name="action" value="generate_all">
        <button type="submit" class="btn btn-primary" style="font-size:1.1em; padding:12px 32px; margin-bottom:24px;">
            ⚡ Добавить все 10 задач в базу данных
        </button>
    </form>

    <?php foreach ($tasksData as $idx => $task): ?>
    <?php $publicCount = count(array_filter($task['tests'], fn($t) => $t['is_public'])); ?>
    <?php $hiddenCount = count($task['tests']) - $publicCount; ?>
    <div class="card" style="margin-bottom:16px; padding:20px; border-left:4px solid var(--primary);">
        <h3 style="margin-top:0;">Задача <?= $idx + 1 ?>: <?= htmlspecialchars($task['title']) ?></h3>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:12px;">
            <div><strong>Лимит времени:</strong> <?= $task['time_limit'] ?> сек</div>
            <div><strong>Лимит памяти:</strong> <?= $task['memory_limit'] ?> МБ</div>
        </div>

        <div style="margin-bottom:12px;">
            <strong>Условие:</strong>
            <div style="background:var(--bg-secondary,#f4f4f5); padding:12px; border-radius:6px; margin-top:4px;"><?= $task['condition'] ?></div>
        </div>
        <div style="margin-bottom:12px;">
            <strong>Формат входных данных:</strong>
            <div style="background:var(--bg-secondary,#f4f4f5); padding:12px; border-radius:6px; margin-top:4px;"><?= $task['input_format'] ?></div>
        </div>
        <div style="margin-bottom:12px;">
            <strong>Формат выходных данных:</strong>
            <div style="background:var(--bg-secondary,#f4f4f5); padding:12px; border-radius:6px; margin-top:4px;"><?= $task['output_format'] ?></div>
        </div>

        <div>
            <strong>Тесты (<?= count($task['tests']) ?> шт.: <?= $publicCount ?> публичных, <?= $hiddenCount ?> скрытых):</strong>
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
        </div>
    </div>
    <?php endforeach; ?>

    <form method="POST" onsubmit="return confirm('Добавить все 10 задач в базу данных?');">
        <input type="hidden" name="action" value="generate_all">
        <button type="submit" class="btn btn-primary" style="font-size:1.1em; padding:12px 32px;">
            ⚡ Добавить все 10 задач в базу данных
        </button>
    </form>
</div>

<p><a href="?page=admin-tasks" class="btn">← К списку задач</a></p>

<?php
$content = ob_get_clean();
require BASE_PATH . '/templates/layout.php';