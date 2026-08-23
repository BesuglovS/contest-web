<?php
// Защита от прямого доступа к файлу — только через фронт-контроллер (index.php)
if (!defined('BASE_PATH')) {
    http_response_code(403);
    exit('Forbidden');
}

$pageTitle = 'Контесты';
$db = Database::getInstance();
$userId = Auth::getUserId();
$userGroupIds = Auth::getUserGroupIds($userId);
$groupPlaceholders = Auth::groupPlaceholders($userGroupIds);

// Получаем все доступные контесты (с количеством задач и решённых — одним запросом)
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
    ORDER BY c.start_time DESC");
$stmt->execute(array_merge([$userId, $userId], $userGroupIds));
$contests = $stmt->fetchAll() ?: [];

ob_start();
?>

<h1>Контесты</h1>

<?php if (empty($contests)): ?>
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
            <p class="access-denied-message">Нет доступных контестов.</p>
            <p class="access-denied-hint">Когда вам предоставят доступ к контесту, он появится здесь.</p>
        </div>
    </div>
<?php else: ?>
    <div style="display:grid; gap:16px;">
        <?php
        $now = utcNow();
        foreach ($contests as $c):
            $isActive = $c['start_time'] <= $now && ($c['end_time'] === null || $c['end_time'] >= $now);
            $isUpcoming = $c['start_time'] > $now;
            $isFinished = $c['end_time'] !== null && $c['end_time'] < $now;

            $taskCount = (int) $c['task_count'];
            $solvedCount = (int) $c['solved_count'];
        ?>
        <div class="card">
            <div style="display:flex; justify-content:space-between; align-items:start;">
                <div>
                    <h3>
                        <a href="?page=contest&id=<?= $c['id'] ?>"><?= htmlspecialchars($c['title']) ?></a>
                        <?php if ($isActive): ?>
                            <span class="submission-status status-accepted" style="margin-left:8px;">Активно</span>
                        <?php elseif ($isUpcoming): ?>
                            <span class="submission-status status-pending" style="margin-left:8px;">Скоро</span>
                        <?php else: ?>
                            <span class="submission-status status-wrong_answer" style="margin-left:8px;">Завершено</span>
                        <?php endif; ?>
                    </h3>
                    <?php if ($c['description']): ?>
                        <p style="color: var(--text-muted);"><?= htmlspecialchars($c['description']) ?></p>
                    <?php endif; ?>
                </div>
                <div style="text-align:right; font-size:0.9em; color: var(--text-muted);">
                    <?php if ($taskCount > 0): ?>
                        <div>Решено: <?= $solvedCount ?>/<?= $taskCount ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <div style="font-size:0.9em; color: var(--text-muted); margin-top:8px;">
                Начало: <?= htmlspecialchars(toDisplayTime($c['start_time']) ?? '') ?>
                <?php if ($c['end_time']): ?> | Конец: <?= htmlspecialchars(toDisplayTime($c['end_time']) ?? '') ?><?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php
$content = ob_get_clean();
require BASE_PATH . '/templates/layout.php';