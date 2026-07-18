<?php
$pageTitle = 'Решения пользователей';
$db = Database::getInstance();
$message = '';
$error = '';

require_once BASE_PATH . '/includes/labels.php';

// Обработка POST-действий (удаление)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete_submission') {
        $id = (int)$_POST['id'];
        $db->prepare("DELETE FROM submissions WHERE id=?")->execute([$id]);
        $message = 'Решение #' . $id . ' удалено';
    }

    if ($action === 'retest_submission') {
        $id = (int)$_POST['id'];

        // Загружаем посылку
        $stmt = $db->prepare("SELECT * FROM submissions WHERE id = ?");
        $stmt->execute([$id]);
        $submission = $stmt->fetch();

        if ($submission) {
            $code = $submission['code'];
            $taskId = $submission['task_id'];

            // Загружаем задачу
            $stmt = $db->prepare("SELECT * FROM tasks WHERE id = ?");
            $stmt->execute([$taskId]);
            $task = $stmt->fetch();

            if ($task) {
                // Загружаем тесты задачи
                $stmt = $db->prepare("SELECT * FROM tests WHERE task_id = ? ORDER BY test_number");
                $stmt->execute([$taskId]);
                $tests = $stmt->fetchAll();

                if (!empty($tests)) {
                    // Удаляем старые результаты тестов
                    $db->prepare("DELETE FROM submission_test_results WHERE submission_id = ?")->execute([$id]);

                    require_once BASE_PATH . '/includes/TestingEngine.php';

                    // Запускаем тестирование
                    $testingResult = TestingEngine::runTests($code, $taskId, $db);

                    if (isset($testingResult['error'])) {
                        $error = $testingResult['error'];
                    } elseif ($testingResult['lint_errors']) {
                        // Ошибки линтинга
                        $db->prepare("UPDATE submissions SET status = 'lint_error', lint_errors = ?, execution_time = 0 WHERE id = ?")->execute([$testingResult['lint_errors_json'], $id]);
                        $message = 'Решение #' . $id . ' перетестировано — ошибка оформления';
                    } else {
                        // Очищаем ошибки линтинга
                        $db->prepare("UPDATE submissions SET lint_errors = NULL WHERE id = ?")->execute([$id]);

                        $overallStatus = $testingResult['overall_status'];
                        $totalTime = $testingResult['total_time'];

                        // Сохраняем результат каждого теста
                        foreach ($testingResult['test_results'] as $tr) {
                            $stmt = $db->prepare("INSERT INTO submission_test_results (submission_id, test_number, status, execution_time, memory_used, output) VALUES (?, ?, ?, ?, ?, ?)");
                            $stmt->execute([
                                $id,
                                (int)$tr['test_number'],
                                $tr['status'],
                                round((float)($tr['time'] ?? 0), 3),
                                (int)($tr['memory'] ?? 0),
                                $tr['output'] ?? ''
                            ]);
                        }

                        // Обновляем статус и время посылки
                        $db->prepare("UPDATE submissions SET status = ?, execution_time = ? WHERE id = ?")->execute([$overallStatus, round($totalTime, 3), $id]);
                        $message = 'Решение #' . $id . ' перетестировано — ' . ($statusLabels[$overallStatus] ?? $overallStatus);
                    }
                } else {
                    $error = 'У задачи нет тестов';
                }
            } else {
                $error = 'Задача не найдена';
            }
        } else {
            $error = 'Решение не найдено';
        }
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

$filterParams = [];
if ($filterTask !== '') $filterParams['task_id'] = (int)$filterTask;
if ($filterUser !== '') $filterParams['user_id'] = (int)$filterUser;
if ($filterStatus !== '') $filterParams['status'] = $filterStatus;
if ($filterContest !== '') $filterParams['contest_id'] = (int)$filterContest;

$where = [];
$params = [];
if ($filterTask) { $where[] = "s.task_id = ?"; $params[] = (int)$filterTask; }
if ($filterUser) { $where[] = "s.user_id = ?"; $params[] = (int)$filterUser; }
if ($filterStatus) { $where[] = "s.status = ?"; $params[] = $filterStatus; }
if ($filterContest) { $where[] = "s.contest_id = ?"; $params[] = (int)$filterContest; }

// Пагинация
$perPage = 20;
$pageNum = max(1, (int)($_GET['page_num'] ?? 1));
$offset = ($pageNum - 1) * $perPage;

// Подсчёт общего количества
$countSql = "SELECT COUNT(*) FROM submissions s
        INNER JOIN users u ON s.user_id = u.id
        INNER JOIN tasks t ON s.task_id = t.id";
if ($where) {
    $countSql .= " WHERE " . implode(" AND ", $where);
}
$stmt = $db->prepare($countSql);
$stmt->execute($params);
$totalCount = (int)$stmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalCount / $perPage));

$sql = "SELECT s.*, u.login, u.display_name, t.title as task_title
        FROM submissions s
        INNER JOIN users u ON s.user_id = u.id
        INNER JOIN tasks t ON s.task_id = t.id";
if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
}
$sql .= " ORDER BY s.id DESC LIMIT " . $perPage . " OFFSET " . $offset;

$stmt = $db->prepare($sql);
$stmt->execute($params);
$submissions = $stmt->fetchAll() ?: [];

$tasks = $db->query("SELECT id, title FROM tasks ORDER BY title")->fetchAll();
$users = Auth::getAllUsers();
$contests = $db->query("SELECT id, title FROM contests ORDER BY title")->fetchAll();

ob_start();
?>

<h1>Решения пользователей</h1>

<?php $activePage = 'submissions'; require BASE_PATH . '/templates/admin_nav.php'; ?>

<?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<!-- Блок массового удаления -->
<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px;">
    <div class="card">
        <h3 style="margin-top: 0;">Удалить все решения участника</h3>
        <form method="POST" onsubmit="return confirm('Удалить ВСЕ решения этого пользователя? Действие необратимо.')">
            <?= csrfField() ?>
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
            <?= csrfField() ?>
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

<?php
// Active filters bar
$activeFilters = [];
if ($filterUser !== '') {
    $userName = 'Пользователь #' . $filterUser;
    foreach ($users as $u) {
        if ((string)$u['id'] === $filterUser) { $userName = htmlspecialchars($u['display_name']); break; }
    }
    $activeFilters[] = ['label' => $userName, 'params' => array_diff_key($filterParams, ['user_id' => 1])];
}
if ($filterTask !== '') {
    $taskName = 'Задача #' . $filterTask;
    foreach ($tasks as $t) {
        if ((string)$t['id'] === $filterTask) { $taskName = htmlspecialchars($t['title']); break; }
    }
    $activeFilters[] = ['label' => $taskName, 'params' => array_diff_key($filterParams, ['task_id' => 1])];
}
if ($filterStatus !== '') {
    $statusName = $statusLabels[$filterStatus] ?? $filterStatus;
    $activeFilters[] = ['label' => $statusName, 'params' => array_diff_key($filterParams, ['status' => 1])];
}
if ($filterContest !== '') {
    $contestName = 'Контест #' . $filterContest;
    foreach ($contests as $c) {
        if ((string)$c['id'] === $filterContest) { $contestName = htmlspecialchars($c['title']); break; }
    }
    $activeFilters[] = ['label' => $contestName, 'params' => array_diff_key($filterParams, ['contest_id' => 1])];
}
?>
<?php if (!empty($activeFilters)): ?>
<div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-bottom:12px;">
    <span style="font-size:13px; color:#666;">Активные фильтры:</span>
    <?php foreach ($activeFilters as $af): ?>
    <span style="display:inline-flex; align-items:center; gap:4px; background:#e8f0fe; border:1px solid #c4d8f0; border-radius:4px; padding:3px 10px; font-size:13px;">
        <?= $af['label'] ?>
        <a href="?page=admin-submissions<?php foreach ($af['params'] as $k => $v) echo '&' . $k . '=' . urlencode($v); ?>" style="text-decoration:none; color:#c0392b; font-weight:bold; font-size:14px; line-height:1;" title="Снять фильтр">✖</a>
    </span>
    <?php endforeach; ?>
    <a href="?page=admin-submissions" style="font-size:12px; color:#888; text-decoration:none; border:1px dashed #ccc; border-radius:4px; padding:3px 10px;">Сбросить все</a>
</div>
<?php endif; ?>

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
            <td class="cell-with-filter">
                <?= htmlspecialchars($s['display_name']) ?>
                <a class="filter-icon" href="?page=admin-submissions<?php
                    $params = array_merge($filterParams, ['user_id' => $s['user_id']]);
                    foreach ($params as $k => $v) echo '&' . $k . '=' . urlencode($v);
                ?>" title="Показать только решения этого пользователя">🔍</a>
            </td>
            <td class="cell-with-filter">
                <?= htmlspecialchars($s['task_title']) ?>
                <a class="filter-icon" href="?page=admin-submissions<?php
                    $params = array_merge($filterParams, ['task_id' => $s['task_id']]);
                    foreach ($params as $k => $v) echo '&' . $k . '=' . urlencode($v);
                ?>" title="Показать только решения этой задачи">🔍</a>
            </td>
            <td>
                <span class="submission-status status-<?= $s['status'] ?>">
                    <?= $statusLabels[$s['status']] ?? $s['status'] ?>
                </span>
                <a class="filter-icon" href="?page=admin-submissions<?php
                    $params = array_merge($filterParams, ['status' => $s['status']]);
                    foreach ($params as $k => $v) echo '&' . $k . '=' . urlencode($v);
                ?>" title="Показать только решения этого статуса">🔍</a>
                <form method="POST" style="display:inline" onsubmit="return confirm('Перетестировать решение #<?= $s['id'] ?>?')">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="retest_submission">
                    <input type="hidden" name="id" value="<?= $s['id'] ?>">
                    <button class="btn-retest" title="Перетестировать">🔄</button>
                </form>
            </td>
            <td><?= number_format((float)($s['execution_time'] ?? 0), 3) ?></td>
             <td><?= htmlspecialchars(toDisplayTime($s['executed_at'] ?? '')) ?></td>
            <td>
                <a href="?page=admin-submission-detail&id=<?= $s['id'] ?>" class="btn btn-sm btn-primary">Просмотр</a>
                <form method="POST" style="display:inline" onsubmit="return confirm('Удалить решение #<?= $s['id'] ?>?')">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="delete_submission">
                    <input type="hidden" name="id" value="<?= $s['id'] ?>">
                    <button class="btn btn-sm btn-danger">Удалить</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php if ($totalPages > 1): ?>
<?php
$queryParams = $_GET;
unset($queryParams['page_num']);
$baseQuery = http_build_query($queryParams);
?>
<nav class="pagination" style="margin-top: 20px; display: flex; justify-content: center; gap: 8px; flex-wrap: wrap;">
    <?php if ($pageNum > 1): ?>
        <a href="?<?= $baseQuery ?>&page_num=<?= $pageNum - 1 ?>" class="btn btn-small">&laquo; Назад</a>
    <?php endif; ?>

    <?php
    $start = max(1, $pageNum - 3);
    $end = min($totalPages, $pageNum + 3);
    if ($start > 1): ?>
        <a href="?<?= $baseQuery ?>&page_num=1" class="btn btn-small">1</a>
        <?php if ($start > 2): ?><span style="padding: 4px;">...</span><?php endif; ?>
    <?php endif; ?>

    <?php for ($i = $start; $i <= $end; $i++): ?>
        <?php if ($i == $pageNum): ?>
            <span class="btn btn-primary btn-small"><?= $i ?></span>
        <?php else: ?>
            <a href="?<?= $baseQuery ?>&page_num=<?= $i ?>" class="btn btn-small"><?= $i ?></a>
        <?php endif; ?>
    <?php endfor; ?>

    <?php if ($end < $totalPages): ?>
        <?php if ($end < $totalPages - 1): ?><span style="padding: 4px;">...</span><?php endif; ?>
        <a href="?<?= $baseQuery ?>&page_num=<?= $totalPages ?>" class="btn btn-small"><?= $totalPages ?></a>
    <?php endif; ?>

    <?php if ($pageNum < $totalPages): ?>
        <a href="?<?= $baseQuery ?>&page_num=<?= $pageNum + 1 ?>" class="btn btn-small">Вперёд &raquo;</a>
    <?php endif; ?>
</nav>
<?php endif; ?>

<?php
$content = ob_get_clean();
require BASE_PATH . '/templates/layout.php';
