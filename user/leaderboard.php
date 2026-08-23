<?php
// Защита от прямого доступа к файлу — только через фронт-контроллер (index.php)
if (!defined('BASE_PATH')) {
    http_response_code(403);
    exit('Forbidden');
}

$pageTitle = 'Таблица лидеров';
$db = Database::getInstance();
$userId = Auth::getUserId();
require_once BASE_PATH . '/includes/labels.php';

// Определяем активный контест: из GET-параметра или берём ближайший активный
$contestId = isset($_GET['contest_id']) ? (int)$_GET['contest_id'] : null;

$userGroupIds = Auth::getUserGroupIds($userId);
$groupPlaceholders = Auth::groupPlaceholders($userGroupIds);

// Список контестов, к которым у пользователя есть доступ
$stmt = $db->prepare("SELECT c.* FROM contests c
    WHERE EXISTS (
        SELECT 1 FROM contest_access ca
        WHERE ca.contest_id = c.id AND (ca.user_id = ? OR ca.group_id IN ($groupPlaceholders))
    )
    ORDER BY c.start_time DESC");
$stmt->execute(array_merge([$userId], $userGroupIds));
$availableContests = $stmt->fetchAll() ?: [];

// Если contest_id не передан, берём первый доступный
if (!$contestId && !empty($availableContests)) {
    $contestId = (int)$availableContests[0]['id'];
}

// Данные лидерборда для выбранного контеста
$leaderboard = [];
if ($contestId) {
    // Проверяем доступ к контесту
    $stmt = $db->prepare("SELECT 1 FROM contest_access ca
        WHERE ca.contest_id = ? AND (ca.user_id = ? OR ca.group_id IN ($groupPlaceholders))
        LIMIT 1");
    $stmt->execute(array_merge([$contestId, $userId], $userGroupIds));
    $hasAccess = (bool)$stmt->fetch();

    if ($hasAccess) {
        // Подсчитываем для каждого пользователя количество уникальных решённых задач в контесте.
        // Сортировка как в ICPC-правиле: больше решённых → выше; при равенстве выше тот,
        // кто решил раньше (время ПЕРВОГО принятого решения по каждой задаче не трекаем,
        // поэтому используем MIN(executed_at) — время первого AC-запроса участника).
        $stmt = $db->prepare("
            SELECT
                s.user_id,
                COUNT(DISTINCT s.task_id) AS solved_count,
                MIN(s.executed_at) AS first_solved_at
            FROM submissions s
            INNER JOIN contest_tasks ct ON ct.task_id = s.task_id AND ct.contest_id = s.contest_id
            WHERE s.status = 'accepted' AND s.contest_id = ?
            GROUP BY s.user_id
            ORDER BY solved_count DESC, first_solved_at ASC
        ");
        $stmt->execute([$contestId]);
        $leaderboard = $stmt->fetchAll() ?: [];

        // Добавляем display_name из auth-web
        if ($leaderboard) {
            $usersById = [];
            foreach (Auth::getAllUsers() as $u) {
                $usersById[(int)$u['id']] = $u;
            }
            foreach ($leaderboard as &$entry) {
                $u = $usersById[(int)$entry['user_id']] ?? null;
                $entry['display_name'] = $u ? ($u['display_name'] ?: $u['login']) : ('Пользователь #' . $entry['user_id']);
            }
            unset($entry);
        }

        // Получаем общее количество задач в контесте
        $stmt = $db->prepare("SELECT COUNT(*) FROM contest_tasks WHERE contest_id = ?");
        $stmt->execute([$contestId]);
        $totalTasksInContest = (int)$stmt->fetchColumn();
    }
}

ob_start();
?>

<h1>Таблица лидеров</h1>

<?php if (empty($availableContests)): ?>
    <div class="card" style="text-align: center; padding: 40px;">
        <p>У вас пока нет доступа к контестам.</p>
    </div>
<?php else: ?>
    <?php if (count($availableContests) > 1): ?>
    <form method="get" action="<?= BASE_URL ?>/index.php" style="margin-bottom: 20px;">
        <input type="hidden" name="page" value="leaderboard">
        <label for="contest-select" style="margin-right: 8px;">Контест:</label>
        <select id="contest-select" name="contest_id" onchange="this.form.submit()" class="form-select" style="max-width: 400px;">
            <?php foreach ($availableContests as $c): ?>
                <option value="<?= $c['id'] ?>" <?= ($c['id'] == $contestId) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($c['title']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>
    <?php endif; ?>

    <?php if (empty($leaderboard)): ?>
        <div class="card" style="text-align: center; padding: 40px;">
            <p>Пока никто не решил ни одной задачи в этом контесте.</p>
        </div>
    <?php else: ?>
        <div class="card" style="overflow-x: auto;">
            <table class="table">
                <thead>
                    <tr>
                        <th style="width: 60px;">Место</th>
                        <th>Участник</th>
                        <th style="width: 100px;">Решено</th>
                        <th style="width: 120px;">Всего задач</th>
                        <th style="width: 160px;">Первое решение</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $rank = 0; $prevCount = -1; ?>
                    <?php foreach ($leaderboard as $index => $entry): ?>
                        <?php
                        if ($entry['solved_count'] !== $prevCount) {
                            $rank = $index + 1;
                        }
                        $prevCount = $entry['solved_count'];
                        ?>
                        <tr <?= ($entry['user_id'] == $userId) ? 'style="background: var(--highlight-bg, #e8f5e9);"' : '' ?>>
                            <td style="text-align: center; font-weight: bold;"><?= $rank ?></td>
                            <td><?= htmlspecialchars($entry['display_name']) ?></td>
                            <td style="text-align: center;"><?= $entry['solved_count'] ?></td>
                            <td style="text-align: center;"><?= $totalTasksInContest ?? '-' ?></td>
                            <td style="font-size: 0.9em; color: var(--text-muted);">
                                <?= $entry['first_solved_at'] ? htmlspecialchars(toDisplayTime($entry['first_solved_at']) ?? '-') : '-' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php
$content = ob_get_clean();
require BASE_PATH . '/templates/layout.php';