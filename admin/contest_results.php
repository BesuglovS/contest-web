<?php
// Защита от прямого доступа к файлу — только через фронт-контроллер (index.php)
if (!defined('BASE_PATH')) {
    http_response_code(403);
    exit('Forbidden');
}

$pageTitle = 'Результаты контеста';
$db = Database::getInstance();

$contestId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if (!$contestId) {
    header('Location: ?page=admin-contests');
    exit;
}

// Получаем информацию о контесте
$stmt = $db->prepare("SELECT * FROM contests WHERE id = ?");
$stmt->execute([$contestId]);
$contest = $stmt->fetch();

if (!$contest) {
    ob_start();
    echo '<p>Контест не найден.</p>';
    $content = ob_get_clean();
    require BASE_PATH . '/templates/layout.php';
    exit;
}

// Получаем задачи контеста
$stmt = $db->prepare("SELECT ct.task_id, ct.sort_order, t.title
    FROM contest_tasks ct
    INNER JOIN tasks t ON ct.task_id = t.id
    WHERE ct.contest_id = ?
    ORDER BY ct.sort_order, t.id");
$stmt->execute([$contestId]);
$tasks = $stmt->fetchAll() ?: [];

if (empty($tasks)) {
    echo '<p>В контесте нет задач.</p>';
    $content = ob_get_clean();
    require BASE_PATH . '/templates/layout.php';
    exit;
}

$taskIds = array_column($tasks, 'task_id');

// Фильтр по классу (группа из auth-web): ?page=admin-contest-results&id=X&group_id=Y
$filterGroup = isset($_GET['group_id']) ? (int) $_GET['group_id'] : 0;
$allGroups = Auth::getAllGroups();

// Получаем всех участников: прямые назначения + пользователи из групп (группы — из auth-web)
$stmt = $db->prepare("SELECT DISTINCT user_id FROM contest_access WHERE contest_id = ? AND user_id IS NOT NULL");
$stmt->execute([$contestId]);
$participantIds = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

$stmt = $db->prepare("SELECT DISTINCT group_id FROM contest_access WHERE contest_id = ? AND group_id IS NOT NULL");
$stmt->execute([$contestId]);
$groupIds = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

if ($groupIds) {
    $groupUsers = Auth::getGroupUsersByGroupIds(array_map('intval', $groupIds));
    $participantIds = array_values(array_unique(array_merge($participantIds, $groupUsers)));
}

// Ограничиваем участников выбранным классом
if ($filterGroup) {
    $groupUserIds = array_map('intval', Auth::getGroupUsersByGroupIds([$filterGroup]));
    $participantIds = array_values(array_intersect($participantIds, $groupUserIds));
}

// Получаем данные участников из auth-web
$usersById = [];
foreach (Auth::getAllUsers() as $u) {
    $usersById[(int)$u['id']] = $u;
}

$participants = [];
foreach ($participantIds as $uid) {
    $uid = (int)$uid;
    if (!isset($usersById[$uid])) continue;
    $participants[] = [
        'id' => $uid,
        'login' => $usersById[$uid]['login'],
        'display_name' => $usersById[$uid]['display_name'] ?: $usersById[$uid]['login'],
    ];
}

usort($participants, function ($a, $b) {
    return strcmp($a['display_name'], $b['display_name']) ?: strcmp($a['login'], $b['login']);
});

if (empty($participants)) {
    ob_start();
    echo '<p>';
    echo $filterGroup ? 'В этом контесте нет участников из выбранного класса.' : 'Нет участников с доступом к контесту.';
    echo '</p>';
    $content = ob_get_clean();
    require BASE_PATH . '/templates/layout.php';
    exit;
}

require_once BASE_PATH . '/includes/labels.php';

// Собираем статистику решений для каждого участника по каждой задаче
// userResults[user_id][task_id] = { attempts: N, solved: bool, last_id: int }
$userResults = [];

$stmt = $db->prepare("SELECT s.user_id, s.task_id,
    COUNT(*) as attempts,
    MAX(CASE WHEN s.status = 'accepted' THEN 1 ELSE 0 END) as solved,
    MAX(s.id) as last_id
    FROM submissions s
    WHERE s.contest_id = ?
    GROUP BY s.user_id, s.task_id");
$stmt->execute([$contestId]);
$results = $stmt->fetchAll() ?: [];

// Статусы последних посылок одним запросом
$lastStatuses = [];
$lastIds = array_filter(array_column($results, 'last_id'));
if ($lastIds) {
    $placeholders = implode(',', array_fill(0, count($lastIds), '?'));
    $stmt = $db->prepare("SELECT id, status FROM submissions WHERE id IN ($placeholders)");
    $stmt->execute(array_map('intval', $lastIds));
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $lastStatuses[(int)$row['id']] = $row['status'];
    }
}

foreach ($results as $row) {
    $uid = $row['user_id'];
    $tid = $row['task_id'];
    $lastId = (int)$row['last_id'];
    $userResults[$uid][$tid] = [
        'attempts' => (int) $row['attempts'],
        'solved' => (bool) $row['solved'],
        'last_status' => $lastStatuses[$lastId] ?? 'pending',
    ];
}

// Вычисляем количество решённых задач для каждого участника и сортируем
$participantStats = [];
foreach ($participants as $p) {
    $uid = $p['id'];
    $solved = 0;
    $attempts = 0;
    $hasAttempts = false;
    foreach ($taskIds as $tid) {
        if (isset($userResults[$uid][$tid])) {
            $hasAttempts = true;
            $attempts += $userResults[$uid][$tid]['attempts'];
            if ($userResults[$uid][$tid]['solved']) {
                $solved++;
            }
        }
    }
    // Пропускаем участников без попыток
    if (!$hasAttempts) continue;

    $participantStats[] = [
        'user' => $p,
        'solved' => $solved,
        'attempts' => $attempts,
        'total' => count($taskIds),
    ];
}

// Сортировка: по убыванию решённых задач, затем по возрастанию попыток, затем по имени
usort($participantStats, function ($a, $b) {
    if ($a['solved'] !== $b['solved']) {
        return $b['solved'] - $a['solved'];
    }
    if ($a['attempts'] !== $b['attempts']) {
        return $a['attempts'] - $b['attempts'];
    }
    return strcmp($a['user']['display_name'], $b['user']['display_name']);
});

$pageTitle = 'Результаты: ' . $contest['title']; // layout сам экранирует title

// Отображение статуса ячейки: символ + цвета (фон/текст)
// Метки статусов — только из $statusLabels (includes/labels.php)
$cellStatusStyles = [
    'accepted'      => ['symbol' => '✓', 'bg' => 'var(--success-bg)', 'fg' => 'var(--success)'],
    'wrong_answer'  => ['symbol' => '✗', 'bg' => 'var(--danger-bg)', 'fg' => 'var(--danger)'],
    'runtime_error' => ['symbol' => '⚠', 'bg' => '#fff3cd', 'fg' => '#856404'],
    'time_limit'    => ['symbol' => '⏱', 'bg' => '#fff3cd', 'fg' => '#856404'],
    'memory_limit'  => ['symbol' => '⚠', 'bg' => '#fff3cd', 'fg' => '#856404'],
    'lint_error'    => ['symbol' => '✎', 'bg' => '#e2e3e5', 'fg' => '#383d41'],
    'no_function'   => ['symbol' => '?', 'bg' => '#d1ecf1', 'fg' => '#0c5460'],
    'pending'       => ['symbol' => '…', 'bg' => '#f0f0f0', 'fg' => '#666'],
];

ob_start();
?>

<h1>Результаты контеста</h1>

<div class="card mb-20">
    <div style="display:flex; gap:24px; align-items:center; flex-wrap:wrap;">
        <h2 style="margin:0;"><?= htmlspecialchars($contest['title']) ?></h2>
        <div>
            <span style="color: var(--text-muted);">Начало:</span>
            <strong><?= htmlspecialchars(toDisplayTime($contest['start_time']) ?? '') ?></strong>
        </div>
        <?php if ($contest['end_time']): ?>
        <div>
            <span style="color: var(--text-muted);">Окончание:</span>
            <strong><?= htmlspecialchars(toDisplayTime($contest['end_time']) ?? '') ?></strong>
        </div>
        <?php endif; ?>
    </div>
    <div style="margin-top:12px; display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
        <a href="?page=admin-contests&edit=<?= $contestId ?>" class="btn btn-sm">← К редактированию контеста</a>
        <a href="?page=admin-contests" class="btn btn-sm">← Список контестов</a>
        <form method="get" action="" id="group-filter-form" class="filter-bar">
            <input type="hidden" name="page" value="admin-contest-results">
            <input type="hidden" name="id" value="<?= $contestId ?>">
            <div class="filter-group">
                <label for="group-filter-select">Класс</label>
                <select name="group_id" id="group-filter-select" onchange="document.getElementById('group-filter-form').submit()">
                    <option value="0">Все</option>
                    <?php foreach ($allGroups as $g): ?>
                    <option value="<?= (int)$g['id'] ?>" <?= $filterGroup === (int)$g['id'] ? 'selected' : '' ?>><?= htmlspecialchars($g['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-secondary btn-sm">Обновить</button>
        </form>
    </div>
</div>

<div class="table-wrapper" style="overflow-x:auto;">
    <table class="results-table">
        <thead>
            <tr>
                <th style="position:sticky; left:0; background:var(--bg); z-index:1;">Участник</th>
                <?php $taskNum = 0; foreach ($tasks as $task): $taskNum++; ?>
                <th style="text-align:center; min-width:60px;" title="<?= htmlspecialchars($task['title']) ?>">
                    <a href="?page=admin-tasks&edit=<?= $task['task_id'] ?>" style="color:inherit; text-decoration:none;">
                        <?= $taskNum ?>
                    </a>
                </th>
                <?php endforeach; ?>
                <th style="text-align:center;">Решено</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($participantStats as $ps): 
                $uid = $ps['user']['id'];
                $displayName = htmlspecialchars($ps['user']['display_name'] ?: $ps['user']['login']);
            ?>
            <tr>
                <td style="position:sticky; left:0; background:var(--surface); z-index:1; font-weight:500;">
                    <?= $displayName ?>
                </td>
                <?php foreach ($taskIds as $tid):
                    $result = $userResults[$uid][$tid] ?? null;
                    if ($result && $result['solved']):
                        $cellUrl = '?page=admin-submissions&contest_id=' . $contestId . '&task_id=' . $tid . '&user_id=' . $uid;
                ?>
                    <td style="padding:0; text-align:center;">
                        <a href="<?= $cellUrl ?>" style="display:block; padding:10px 12px; background:var(--success-bg); color:var(--success); font-weight:600; text-decoration:none;">
                        ✓<br><span style="font-size:0.75em; font-weight:400;"><?= $result['attempts'] ?></span>
                        </a>
                    </td>
                <?php elseif ($result):
                    $st = $result['last_status'];
                    $style = $cellStatusStyles[$st] ?? $cellStatusStyles['pending'];
                    $stLabel = htmlspecialchars($statusLabels[$st] ?? $st);
                    $cellTitle = 'Попыток: ' . $result['attempts'] . '. Последняя: ' . $stLabel;
                    $cellUrl = '?page=admin-submissions&contest_id=' . $contestId . '&task_id=' . $tid . '&user_id=' . $uid;
                ?>
                    <td style="padding:0; text-align:center;" title="<?= $cellTitle ?>">
                        <a href="<?= $cellUrl ?>" style="display:block; padding:10px 12px; background:<?= $style['bg'] ?>; color:<?= $style['fg'] ?>; text-decoration:none;">
                        <?= $style['symbol'] ?><br><span style="font-size:0.75em; font-weight:400;"><?= $result['attempts'] ?></span>
                        </a>
                    </td>
                <?php else: ?>
                    <td style="text-align:center; color:var(--text-muted);">
                        —
                    </td>
                <?php endif; endforeach; ?>
                <td style="text-align:center; font-weight:700;">
                    <?= $ps['solved'] ?> / <?= $ps['total'] ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="card mb-20" style="margin-top:16px;">
    <div style="display:flex; gap:16px; flex-wrap:wrap; align-items:center; font-size:0.9em;">
        <span><strong>Легенда:</strong></span>
        <?php foreach ($cellStatusStyles as $stKey => $stStyle): ?>
        <span style="background:<?= $stStyle['bg'] ?>; color:<?= $stStyle['fg'] ?>; padding:2px 8px; border-radius:4px;">
            <?= $stStyle['symbol'] ?> <?= htmlspecialchars($statusLabels[$stKey] ?? $stKey) ?>
        </span>
        <?php endforeach; ?>
        <span style="color:var(--text-muted);">— нет попыток</span>
    </div>
</div>

<?php
$content = ob_get_clean();
require BASE_PATH . '/templates/layout.php';