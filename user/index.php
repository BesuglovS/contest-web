<?php
// Защита от прямого доступа к файлу — только через фронт-контроллер (index.php)
if (!defined('BASE_PATH')) {
    http_response_code(403);
    exit('Forbidden');
}

$pageTitle = 'Главная';
$db = Database::getInstance();

$userId = Auth::getUserId();
$userGroupIds = Auth::getUserGroupIds($userId);
$groupPlaceholders = Auth::groupPlaceholders($userGroupIds);

// Статистика пользователя
$stmt = $db->prepare("SELECT COUNT(*) FROM submissions WHERE user_id=?");
$stmt->execute([$userId]);
$totalSubmissions = $stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(DISTINCT task_id) FROM submissions WHERE user_id=? AND status='accepted'");
$stmt->execute([$userId]);
$solvedCount = $stmt->fetchColumn();

// Получаем общее количество доступных задач (из контестов, к которым есть доступ)
$stmt = $db->prepare("SELECT COUNT(DISTINCT t.id) FROM tasks t
    INNER JOIN contest_tasks ct ON t.id = ct.task_id
    INNER JOIN contest_access ca ON ct.contest_id = ca.contest_id
    WHERE ca.user_id = ? OR ca.group_id IN ($groupPlaceholders)");
$stmt->execute(array_merge([$userId], $userGroupIds));
$totalTasks = $stmt->fetchColumn();

// Ближайшие контесты (с количеством задач и решённых — одним запросом)
$stmt = $db->prepare("SELECT c.*,
    (SELECT COUNT(*) FROM contest_tasks ct WHERE ct.contest_id = c.id) AS task_count,
    (SELECT COUNT(DISTINCT s.task_id) FROM submissions s
        INNER JOIN contest_tasks cts ON s.task_id = cts.task_id
        WHERE s.user_id = ? AND cts.contest_id = c.id AND s.status = 'accepted') AS solved_count
    FROM contests c
    WHERE EXISTS (
        SELECT 1 FROM contest_access ca
        WHERE ca.contest_id = c.id AND (ca.user_id = ? OR ca.group_id IN ($groupPlaceholders))
    )
    AND (c.end_time IS NULL OR c.end_time > datetime('now'))
    ORDER BY c.start_time ASC");
$stmt->execute(array_merge([$userId, $userId], $userGroupIds));
$contests = $stmt->fetchAll() ?: [];

ob_start();
?>

<h1>Добро пожаловать, <?= htmlspecialchars(Auth::getUserName()) ?>!</h1>

<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 16px; margin: 24px 0;">
    <div class="card" style="text-align: center;">
        <h3 style="font-size: 2em; color: var(--primary);"><?= $solvedCount ?></h3>
        <p style="color: var(--text-muted);">Решено задач</p>
    </div>
    <div class="card" style="text-align: center;">
        <h3 style="font-size: 2em; color: var(--primary);"><?= $totalTasks ?></h3>
        <p style="color: var(--text-muted);">Всего задач</p>
    </div>
    <div class="card" style="text-align: center;">
        <h3 style="font-size: 2em; color: var(--primary);"><?= $totalSubmissions ?></h3>
        <p style="color: var(--text-muted);">Отправлено решений</p>
    </div>
</div>

<?php if ($contests): ?>
<h2>Доступные контесты</h2>
<div style="display: grid; gap: 12px;">
    <?php foreach ($contests as $c):
        $taskCount = (int) $c['task_count'];
        $contestSolvedCount = (int) $c['solved_count'];

        $pct = $taskCount > 0 ? round($contestSolvedCount / $taskCount * 100) : 0;
    ?>
    <div class="card">
        <div style="display:flex; justify-content:space-between; align-items:start;">
            <h3><a href="?page=contest&id=<?= $c['id'] ?>"><?= htmlspecialchars($c['title']) ?></a></h3>
            <?php if ($taskCount > 0): ?>
                <div style="font-size:0.9em; color:var(--text-muted); text-align:right;">
                    Решено: <?= $contestSolvedCount ?>/<?= $taskCount ?>
                </div>
            <?php endif; ?>
        </div>
        <?php if ($c['description']): ?>
            <p><?= htmlspecialchars($c['description']) ?></p>
        <?php endif; ?>
        <?php if ($taskCount > 0): ?>
            <div style="margin:10px 0 4px; height:8px; border-radius:4px; background:var(--surface, #eee); overflow:hidden;">
                <div style="height:100%; width:<?= $pct ?>%; border-radius:4px; background:var(--primary);"></div>
            </div>
        <?php endif; ?>
        <p style="font-size:0.9em; color:var(--text-muted); margin-bottom:0;">
            Начало: <?= htmlspecialchars(toDisplayTime($c['start_time']) ?? '') ?>
            <?php if ($c['end_time']): ?> | Конец: <?= htmlspecialchars(toDisplayTime($c['end_time']) ?? '') ?><?php endif; ?>
        </p>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($solvedCount > 0): ?>
<p class="mt-20">
    Прогресс: <?= round($solvedCount / max($totalTasks, 1) * 100) ?>% задач решено.
</p>
<?php endif; ?>

<?php
$content = ob_get_clean();
require BASE_PATH . '/templates/layout.php';