<?php
$pageTitle = 'Контест';
$db = Database::getInstance();
$userId = Auth::getUserId();

$contestId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if (!$contestId) {
    header('Location: ?page=index');
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

// Проверяем доступ
$stmt = $db->prepare("SELECT 1 FROM contest_access WHERE contest_id = ? AND user_id = ?");
$stmt->execute([$contestId, $userId]);
$hasDirectAccess = (bool) $stmt->fetch();

$stmt = $db->prepare("SELECT 1 FROM contest_access ca
    JOIN user_groups ug ON ca.group_id = ug.group_id
    WHERE ca.contest_id = ? AND ug.user_id = ?");
$stmt->execute([$contestId, $userId]);
$hasGroupAccess = (bool) $stmt->fetch();

if (!$hasDirectAccess && !$hasGroupAccess) {
    echo '<p>У вас нет доступа к этому контесту.</p>';
    $content = ob_get_clean();
    require BASE_PATH . '/templates/layout.php';
    exit;
}

// Получаем задачи контеста
$stmt = $db->prepare("SELECT ct.*, t.title, t.time_limit, t.memory_limit,
    (SELECT COUNT(*) FROM submissions s WHERE s.task_id = t.id AND s.user_id = ? AND s.status = 'accepted') as solved
    FROM contest_tasks ct
    JOIN tasks t ON ct.task_id = t.id
    WHERE ct.contest_id = ?
    ORDER BY ct.sort_order, t.id");
$stmt->execute([$userId, $contestId]);
$tasks = $stmt->fetchAll() ?: [];

// Проверяем, активен ли контест
$now = utcNow();
$isActive = $contest['start_time'] <= $now && ($contest['end_time'] === null || $contest['end_time'] >= $now);
$isUpcoming = $contest['start_time'] > $now;
$isFinished = $contest['end_time'] !== null && $contest['end_time'] < $now;

$pageTitle = htmlspecialchars($contest['title']);

ob_start();
?>

<h1><?= htmlspecialchars($contest['title']) ?></h1>

<div class="card mb-20">
    <div style="display:flex; gap:24px; align-items:center;">
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
        <div>
            <?php if ($isActive): ?>
                <span class="submission-status status-accepted">● Активно</span>
            <?php elseif ($isUpcoming): ?>
                <span class="submission-status status-pending">○ Скоро начнётся</span>
            <?php else: ?>
                <span class="submission-status status-wrong_answer">● Завершено</span>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($contest['description']): ?>
        <div style="margin-top:12px; color: var(--text-muted);"><?= nl2br(htmlspecialchars($contest['description'])) ?></div>
    <?php endif; ?>
</div>

<?php if ($isUpcoming): ?>
    <div class="alert" style="background:#fef3c7; border:1px solid #f59e0b; padding:16px; border-radius:8px;">
        Контест ещё не начался. Задачи будут доступны после <?= htmlspecialchars(toDisplayTime($contest['start_time']) ?? '') ?>.
    </div>
<?php elseif ($isFinished): ?>
    <div class="alert" style="background:#f3f4f6; border:1px solid #9ca3af; padding:16px; border-radius:8px;">
        Контест завершён. Вы можете просматривать задачи, но новые решения не принимаются.
    </div>
<?php endif; ?>

<h2>Задачи</h2>

<table>
    <thead>
        <tr>
            <th>#</th>
            <th>Название</th>
            <th>Лимит</th>
            <th>Статус</th>
        </tr>
    </thead>
    <tbody>
        <?php $taskNum = 0; foreach ($tasks as $task): $taskNum++; ?>
        <tr>
            <td><?= $taskNum ?></td>
            <td>
                <a href="?page=task&id=<?= $task['task_id'] ?>&contest=<?= $contestId ?>">
                    <?= htmlspecialchars($task['title']) ?>
                </a>
            </td>
            <td style="font-size:0.9em;"><?= $task['time_limit'] ?>с / <?= $task['memory_limit'] ?>МБ</td>
            <td>
                <?php if ($task['solved'] > 0): ?>
                    <span class="submission-status status-accepted">Решена ✓</span>
                <?php else: ?>
                    <span class="submission-status status-pending">—</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php
$content = ob_get_clean();
require BASE_PATH . '/templates/layout.php';