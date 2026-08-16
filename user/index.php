<?php
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

// Ближайшие контесты
$stmt = $db->prepare("SELECT DISTINCT c.* FROM contests c
    LEFT JOIN contest_access ca ON c.id = ca.contest_id
    WHERE (ca.user_id = ? OR ca.group_id IN ($groupPlaceholders))
    AND (c.end_time IS NULL OR c.end_time > datetime('now'))
    ORDER BY c.start_time DESC");
$stmt->execute(array_merge([$userId], $userGroupIds));
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
        // Количество задач в контесте
        $stmt2 = $db->prepare("SELECT COUNT(*) FROM contest_tasks WHERE contest_id = ?");
        $stmt2->execute([$c['id']]);
        $taskCount = (int) $stmt2->fetchColumn();

        // Количество решённых пользователем
        $stmt3 = $db->prepare("SELECT COUNT(DISTINCT s.task_id) FROM submissions s INNER JOIN contest_tasks ct ON s.task_id = ct.task_id WHERE s.user_id = ? AND ct.contest_id = ? AND s.status = 'accepted'");
        $stmt3->execute([$userId, $c['id']]);
        $solvedCount = (int) $stmt3->fetchColumn();

        $pct = $taskCount > 0 ? round($solvedCount / $taskCount * 100) : 0;
    ?>
    <div class="card">
        <div style="display:flex; justify-content:space-between; align-items:start;">
            <h3><a href="?page=contest&id=<?= $c['id'] ?>"><?= htmlspecialchars($c['title']) ?></a></h3>
            <?php if ($taskCount > 0): ?>
                <div style="font-size:0.9em; color:var(--text-muted); text-align:right;">
                    Решено: <?= $solvedCount ?>/<?= $taskCount ?>
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