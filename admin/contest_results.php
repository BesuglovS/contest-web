<?php
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
    echo '<p>Контест не найден.</p>';
    $content = ob_get_clean();
    require BASE_PATH . '/templates/layout.php';
    exit;
}

// Получаем задачи контеста
$stmt = $db->prepare("SELECT ct.task_id, ct.sort_order, t.title
    FROM contest_tasks ct
    JOIN tasks t ON ct.task_id = t.id
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

// Получаем всех участников (пользователей, имеющих доступ к контесту)
$stmt = $db->prepare("SELECT DISTINCT u.id, u.login, u.display_name
    FROM users u
    LEFT JOIN contest_access ca_user ON ca_user.contest_id = ? AND ca_user.user_id = u.id
    LEFT JOIN user_groups ug ON ug.user_id = u.id
    LEFT JOIN contest_access ca_group ON ca_group.contest_id = ? AND ca_group.group_id = ug.group_id
    WHERE ca_user.user_id IS NOT NULL OR ca_group.group_id IS NOT NULL
    ORDER BY u.display_name, u.login");
$stmt->execute([$contestId, $contestId]);
$participants = $stmt->fetchAll() ?: [];

if (empty($participants)) {
    echo '<p>Нет участников с доступом к контесту.</p>';
    $content = ob_get_clean();
    require BASE_PATH . '/templates/layout.php';
    exit;
}

// Собираем статистику решений для каждого участника по каждой задаче
// userResults[user_id][task_id] = { attempts: N, solved: bool }
$userResults = [];

$stmt = $db->prepare("SELECT s.user_id, s.task_id,
    COUNT(*) as attempts,
    MAX(CASE WHEN s.status = 'accepted' THEN 1 ELSE 0 END) as solved
    FROM submissions s
    WHERE s.contest_id = ?
    GROUP BY s.user_id, s.task_id");
$stmt->execute([$contestId]);
$results = $stmt->fetchAll() ?: [];

foreach ($results as $row) {
    $uid = $row['user_id'];
    $tid = $row['task_id'];
    if (!isset($userResults[$uid])) {
        $userResults[$uid] = [];
    }
    $userResults[$uid][$tid] = [
        'attempts' => (int) $row['attempts'],
        'solved' => (bool) $row['solved'],
    ];
}

// Вычисляем количество решённых задач для каждого участника и сортируем
$participantStats = [];
foreach ($participants as $p) {
    $uid = $p['id'];
    $solved = 0;
    $hasAttempts = false;
    foreach ($taskIds as $tid) {
        if (isset($userResults[$uid][$tid])) {
            $hasAttempts = true;
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
        'total' => count($taskIds),
    ];
}

// Сортировка: по убыванию решённых задач, затем по имени
usort($participantStats, function ($a, $b) {
    if ($a['solved'] !== $b['solved']) {
        return $b['solved'] - $a['solved'];
    }
    return strcmp($a['user']['display_name'], $b['user']['display_name']);
});

$pageTitle = 'Результаты: ' . htmlspecialchars($contest['title']);

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
    <div style="margin-top:12px;">
        <a href="?page=admin-contests&edit=<?= $contestId ?>" class="btn btn-sm">← К редактированию контеста</a>
        <a href="?page=admin-contests" class="btn btn-sm">← Список контестов</a>
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
                ?>
                    <td style="text-align:center; background:var(--success-bg); color:var(--success); font-weight:600;">
                    ✓<br><a href="?page=admin-submissions&contest_id=<?= $contestId ?>&task_id=<?= $tid ?>&user_id=<?= $uid ?>" style="font-size:0.75em; font-weight:400; color:var(--success); text-decoration:none;"><?= $result['attempts'] ?></a>
                    </td>
                <?php elseif ($result && !$result['solved']): ?>
                    <td style="text-align:center; background:var(--danger-bg); color:var(--danger);">
                    ✗<br><a href="?page=admin-submissions&contest_id=<?= $contestId ?>&task_id=<?= $tid ?>&user_id=<?= $uid ?>" style="font-size:0.75em; font-weight:400; color:var(--danger); text-decoration:none;"><?= $result['attempts'] ?></a>
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

<?php
$content = ob_get_clean();
require BASE_PATH . '/templates/layout.php';