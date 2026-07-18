<?php
$pageTitle = 'Контесты';
$db = Database::getInstance();
$userId = Auth::getUserId();

// Получаем все доступные контесты
$stmt = $db->prepare("SELECT DISTINCT c.* FROM contests c
    LEFT JOIN contest_access ca ON c.id = ca.contest_id
    WHERE (ca.user_id = ? OR ca.group_id IN (SELECT group_id FROM user_groups WHERE user_id=?))
    ORDER BY c.start_time DESC");
$stmt->execute([$userId, $userId]);
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

            // Количество задач
            $stmt2 = $db->prepare("SELECT COUNT(*) FROM contest_tasks WHERE contest_id = ?");
            $stmt2->execute([$c['id']]);
            $taskCount = $stmt2->fetchColumn();

            // Количество решённых пользователем
            $stmt3 = $db->prepare("SELECT COUNT(DISTINCT s.task_id) FROM submissions s INNER JOIN contest_tasks ct ON s.task_id = ct.task_id WHERE s.user_id = ? AND ct.contest_id = ? AND s.status = 'accepted'");
            $stmt3->execute([$userId, $c['id']]);
            $solvedCount = $stmt3->fetchColumn();
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