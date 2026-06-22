<?php
$pageTitle = 'Мои решения';
$db = Database::getInstance();

$userId = Auth::getUserId();

// Фильтры
$filterTask = $_GET['task_id'] ?? '';
$filterStatus = $_GET['status'] ?? '';

$where = ["s.user_id = ?"];
$params = [$userId];

if ($filterTask) { $where[] = "s.task_id = ?"; $params[] = (int)$filterTask; }
if ($filterStatus) { $where[] = "s.status = ?"; $params[] = $filterStatus; }

$sql = "SELECT s.*, t.title as task_title,
        c.id as contest_id, c.start_time as contest_start, c.end_time as contest_end
        FROM submissions s
        JOIN tasks t ON s.task_id = t.id
        LEFT JOIN contests c ON s.contest_id = c.id
        WHERE " . implode(" AND ", $where) . "
        ORDER BY s.id DESC LIMIT 100";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$submissions = $stmt->fetchAll() ?: [];

// Определяем активность контестов для каждой посылки
$now = utcNow();
foreach ($submissions as &$s) {
    $s['contest_active'] = false;
    if (!empty($s['contest_id'])) {
        $started = $s['contest_start'] <= $now;
        $notEnded = $s['contest_end'] === null || $s['contest_end'] > $now;
        $s['contest_active'] = $started && $notEnded;
    }
}
unset($s);

$stmt = $db->prepare("SELECT DISTINCT t.id, t.title FROM tasks t
    JOIN contest_tasks ct ON t.id = ct.task_id
    JOIN contest_access ca ON ct.contest_id = ca.contest_id
    LEFT JOIN user_groups ug ON ug.user_id = ? AND ca.group_id = ug.group_id
    WHERE ca.user_id = ? OR ug.group_id IS NOT NULL
    ORDER BY t.title");
$stmt->execute([$userId, $userId]);
$tasks = $stmt->fetchAll() ?: [];

$statusLabels = [
    'pending' => 'Ожидает',
    'lint_error' => 'Ошибка оформления',
    'accepted' => 'Принято',
    'wrong_answer' => 'Неверный ответ',
    'runtime_error' => 'Ошибка выполнения',
    'time_limit' => 'Превышен лимит времени',
    'memory_limit' => 'Превышен лимит памяти',
];

ob_start();
?>

<h1>Мои решения</h1>

<div class="card mb-20">
    <form method="GET" style="display:flex; gap:12px; align-items:end; flex-wrap:wrap;">
        <input type="hidden" name="page" value="submissions">
        <div class="form-group" style="margin-bottom:0;">
            <label>Задача</label>
            <select name="task_id">
                <option value="">Все</option>
                <?php foreach ($tasks as $t): ?>
                <option value="<?= $t['id'] ?>" <?= $filterTask == $t['id'] ? 'selected' : '' ?>><?= htmlspecialchars($t['title']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin-bottom:0;">
            <label>Статус</label>
            <select name="status">
                <option value="">Все</option>
                <?php foreach ($statusLabels as $key => $label): ?>
                <option value="<?= $key ?>" <?= $filterStatus === $key ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-primary">Фильтр</button>
    </form>
</div>

<table>
    <thead>
        <tr>
            <th>#</th>
            <th>Задача</th>
            <th>Статус</th>
            <th>Время (сек)</th>
            <th>Дата</th>
            <th>Действия</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($submissions as $s): ?>
        <tr>
            <td><?= $s['id'] ?></td>
            <td>
                <a href="?page=task&id=<?= $s['task_id'] ?>"><?= htmlspecialchars($s['task_title']) ?></a>
                <?php if ($s['contest_id']): ?>
                    <span style="font-size:0.8em; color:var(--text-muted);">(контест #<?= $s['contest_id'] ?>)</span>
                <?php endif; ?>
            </td>
            <td>
                <span class="submission-status status-<?= $s['status'] ?>">
                    <?= $statusLabels[$s['status']] ?? $s['status'] ?>
                </span>
            </td>
            <td><?= number_format((float)($s['execution_time'] ?? 0), 3) ?></td>
             <td><?= htmlspecialchars(toDisplayTime($s['executed_at'] ?? '')) ?></td>
            <td style="display:flex; gap:8px;">
                <a href="?page=submission-detail&id=<?= $s['id'] ?>" class="btn btn-small">Просмотр</a>
                <?php if (!empty($s['contest_id']) && $s['contest_active']): ?>
                    <a href="?page=task&id=<?= $s['task_id'] ?>&contest=<?= $s['contest_id'] ?>" class="btn btn-small">Решать снова</a>
                <?php else: ?>
                    <a href="?page=task&id=<?= $s['task_id'] ?>" class="btn btn-small">Решать снова</a>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php if (empty($submissions)): ?>
    <p style="color:var(--text-muted); text-align:center; padding:20px;">Нет решений. <a href="?page=tasks">Перейти к задачам</a></p>
<?php endif; ?>

<?php
$content = ob_get_clean();
require BASE_PATH . '/templates/layout.php';