<?php
$pageTitle = 'Главная';
$db = Database::getInstance();

$userId = Auth::getUserId();

// Статистика пользователя
$totalSubmissions = $db->prepare("SELECT COUNT(*) FROM submissions WHERE user_id=?")->execute([$userId]) ?
    $db->prepare("SELECT COUNT(*) FROM submissions WHERE user_id=?")->fetchColumn() : 0;
$stmt = $db->prepare("SELECT COUNT(*) FROM submissions WHERE user_id=?");
$stmt->execute([$userId]);
$totalSubmissions = $stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(DISTINCT task_id) FROM submissions WHERE user_id=? AND status='accepted'");
$stmt->execute([$userId]);
$solvedCount = $stmt->fetchColumn();

// Получаем общее количество доступных задач (из контестов, к которым есть доступ)
$stmt = $db->prepare("SELECT COUNT(DISTINCT t.id) FROM tasks t
    JOIN contest_tasks ct ON t.id = ct.task_id
    JOIN contest_access ca ON ct.contest_id = ca.contest_id
    LEFT JOIN user_groups ug ON ug.user_id = ? AND ca.group_id = ug.group_id
    WHERE ca.user_id = ? OR ug.group_id IS NOT NULL");
$stmt->execute([$userId, $userId]);
$totalTasks = $stmt->fetchColumn();

// Ближайшие контесты
$stmt = $db->prepare("SELECT DISTINCT c.* FROM contests c
    LEFT JOIN contest_access ca ON c.id = ca.contest_id
    WHERE (ca.user_id = ? OR ca.group_id IN (SELECT group_id FROM user_groups WHERE user_id=?))
    AND (c.end_time IS NULL OR c.end_time > datetime('now'))
    ORDER BY c.start_time LIMIT 5");
$stmt->execute([$userId, $userId]);
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
    <?php foreach ($contests as $c): ?>
    <div class="card">
        <h3><a href="?page=contest&id=<?= $c['id'] ?>"><?= htmlspecialchars($c['title']) ?></a></h3>
        <?php if ($c['description']): ?>
            <p><?= htmlspecialchars($c['description']) ?></p>
        <?php endif; ?>
        <p style="font-size:0.9em; color:var(--text-muted);">
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