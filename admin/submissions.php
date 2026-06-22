<?php
$pageTitle = 'Решения пользователей';
$db = Database::getInstance();
$message = '';
$error = '';

// Обработка POST-действий (удаление)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete_submission') {
        $id = (int)$_POST['id'];
        $db->prepare("DELETE FROM submissions WHERE id=?")->execute([$id]);
        $message = 'Решение #' . $id . ' удалено';
    }

    if ($action === 'delete_user_submissions') {
        $userId = (int)$_POST['user_id'];
        $stmt = $db->prepare("SELECT COUNT(*) FROM submissions WHERE user_id=?");
        $stmt->execute([$userId]);
        $count = $stmt->fetchColumn();
        $db->prepare("DELETE FROM submissions WHERE user_id=?")->execute([$userId]);
        $message = 'Удалено решений: ' . $count . ' (пользователь #' . $userId . ')';
    }

    if ($action === 'delete_contest_submissions') {
        $contestId = (int)$_POST['contest_id'];
        $stmt = $db->prepare("SELECT COUNT(*) FROM submissions WHERE contest_id=?");
        $stmt->execute([$contestId]);
        $count = $stmt->fetchColumn();
        $db->prepare("DELETE FROM submissions WHERE contest_id=?")->execute([$contestId]);
        $message = 'Удалено решений: ' . $count . ' (контест #' . $contestId . ')';
    }
}

$filterTask = $_GET['task_id'] ?? '';
$filterUser = $_GET['user_id'] ?? '';
$filterStatus = $_GET['status'] ?? '';
$filterContest = $_GET['contest_id'] ?? '';

$where = [];
$params = [];
if ($filterTask) { $where[] = "s.task_id = ?"; $params[] = (int)$filterTask; }
if ($filterUser) { $where[] = "s.user_id = ?"; $params[] = (int)$filterUser; }
if ($filterStatus) { $where[] = "s.status = ?"; $params[] = $filterStatus; }
if ($filterContest) { $where[] = "s.contest_id = ?"; $params[] = (int)$filterContest; }

$sql = "SELECT s.*, u.login, u.display_name, t.title as task_title
        FROM submissions s
        JOIN users u ON s.user_id = u.id
        JOIN tasks t ON s.task_id = t.id";
if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
}
$sql .= " ORDER BY s.id DESC LIMIT 200";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$submissions = $stmt->fetchAll() ?: [];

$tasks = $db->query("SELECT id, title FROM tasks ORDER BY title")->fetchAll();
$users = Auth::getAllUsers();
$contests = $db->query("SELECT id, title FROM contests ORDER BY title")->fetchAll();

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

<h1>Решения пользователей</h1>

<div class="admin-nav">
    <a href="?page=admin">Дашборд</a>
    <a href="?page=admin-users">Пользователи</a>
    <a href="?page=admin-groups">Группы</a>
    <a href="?page=admin-tasks">Задачи</a>
    <a href="?page=admin-task-groups">Группы задач</a>
    <a href="?page=admin-contests">Контесты</a>
    <a href="?page=admin-submissions" class="active">Решения</a>
    <a href="?page=admin-import-tasks">Импорт задач</a>
</div>

<?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<!-- Блок массового удаления -->
<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px;">
    <div class="card">
        <h3 style="margin-top: 0;">Удалить все решения участника</h3>
        <form method="POST" onsubmit="return confirm('Удалить ВСЕ решения этого пользователя? Действие необратимо.')">
            <input type="hidden" name="action" value="delete_user_submissions">
            <div style="display: flex; gap: 8px; align-items: end;">
                <div class="form-group" style="margin-bottom: 0; flex: 1;">
                    <label>Пользователь</label>
                    <select name="user_id" required>
                        <option value="">Выберите пользователя...</option>
                        <?php foreach ($users as $u): ?>
                        <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['login']) ?> (<?= htmlspecialchars($u['display_name']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-danger">Удалить все решения</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3 style="margin-top: 0;">Удалить все решения в контесте</h3>
        <form method="POST" onsubmit="return confirm('Удалить ВСЕ решения в этом контесте? Действие необратимо.')">
            <input type="hidden" name="action" value="delete_contest_submissions">
            <div style="display: flex; gap: 8px; align-items: end;">
                <div class="form-group" style="margin-bottom: 0; flex: 1;">
                    <label>Контест</label>
                    <select name="contest_id" required>
                        <option value="">Выберите контест...</option>
                        <?php foreach ($contests as $c): ?>
                        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-danger">Удалить все решения</button>
            </div>
        </form>
    </div>
</div>

<div class="card mb-20">
    <form method="GET" style="display:flex; gap:12px; align-items:end; flex-wrap:wrap;">
        <input type="hidden" name="page" value="admin-submissions">
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
            <label>Пользователь</label>
            <select name="user_id">
                <option value="">Все</option>
                <?php foreach ($users as $u): ?>
                <option value="<?= $u['id'] ?>" <?= $filterUser == $u['id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['display_name']) ?> (<?= htmlspecialchars($u['login']) ?>)</option>
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
        <div class="form-group" style="margin-bottom:0;">
            <label>Контест</label>
            <select name="contest_id">
                <option value="">Все</option>
                <?php foreach ($contests as $c): ?>
                <option value="<?= $c['id'] ?>" <?= $filterContest == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['title']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-primary">Фильтр</button>
    </form>
</div>

<table>
    <thead>
        <tr>
            <th>ID</th>
            <th>Пользователь</th>
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
            <td><?= htmlspecialchars($s['display_name']) ?></td>
            <td><?= htmlspecialchars($s['task_title']) ?></td>
            <td>
                <span class="submission-status status-<?= $s['status'] ?>">
                    <?= $statusLabels[$s['status']] ?? $s['status'] ?>
                </span>
            </td>
            <td><?= number_format((float)($s['execution_time'] ?? 0), 3) ?></td>
             <td><?= htmlspecialchars(toDisplayTime($s['executed_at'] ?? '')) ?></td>
            <td>
                <a href="?page=admin-submission-detail&id=<?= $s['id'] ?>" class="btn btn-sm btn-primary">Просмотр</a>
                <form method="POST" style="display:inline" onsubmit="return confirm('Удалить решение #<?= $s['id'] ?>?')">
                    <input type="hidden" name="action" value="delete_submission">
                    <input type="hidden" name="id" value="<?= $s['id'] ?>">
                    <button class="btn btn-sm btn-danger">Удалить</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php
$content = ob_get_clean();
require BASE_PATH . '/templates/layout.php';