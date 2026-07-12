<?php
$pageTitle = 'Управление группами';
$db = Database::getInstance();
$message = '';
$error = '';

// Обработка действий
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = trim($_POST['name']);
        $description = trim($_POST['description'] ?? '');
        if ($name) {
            try {
                $stmt = $db->prepare("INSERT INTO groups (name, description) VALUES (?, ?)");
                $stmt->execute([$name, $description]);
                $message = 'Группа создана';
            } catch (PDOException $e) {
                $error = 'Группа с таким именем уже существует';
            }
        } else {
            $error = 'Введите название группы';
        }
    }

    if ($action === 'update') {
        $id = (int) $_POST['id'];
        $name = trim($_POST['name']);
        $description = trim($_POST['description'] ?? '');
        try {
            $stmt = $db->prepare("UPDATE groups SET name = ?, description = ? WHERE id = ?");
            $stmt->execute([$name, $description, $id]);
            $message = 'Группа обновлена';
        } catch (PDOException $e) {
            $error = 'Группа с таким именем уже существует';
        }
    }

    if ($action === 'delete') {
        $id = (int) $_POST['id'];
        $stmt = $db->prepare("DELETE FROM groups WHERE id = ?");
        $stmt->execute([$id]);
        $message = 'Группа удалена';
    }

    // Управление пользователями в группе
    if ($action === 'add_user') {
        $groupId = (int) $_POST['group_id'];
        $userId = (int) $_POST['user_id'];
        try {
            $stmt = $db->prepare("INSERT OR IGNORE INTO user_groups (user_id, group_id) VALUES (?, ?)");
            $stmt->execute([$userId, $groupId]);
            $message = 'Пользователь добавлен в группу';
        } catch (PDOException $e) {
            $error = 'Ошибка добавления';
        }
    }

    if ($action === 'remove_user') {
        $groupId = (int) $_POST['group_id'];
        $userId = (int) $_POST['user_id'];
        $stmt = $db->prepare("DELETE FROM user_groups WHERE user_id = ? AND group_id = ?");
        $stmt->execute([$userId, $groupId]);
        $message = 'Пользователь удалён из группы';
    }

    if ($action === 'bulk_add_users') {
        $groupId = (int) $_POST['group_id'];
        $rawText = trim($_POST['bulk_logins'] ?? '');
        $bulkGroupResults = ['success' => [], 'failed' => []];

        if ($rawText !== '') {
            $lines = preg_split('/\r\n|\r|\n/', $rawText);
            foreach ($lines as $lineNum => $line) {
                $login = trim($line);
                if ($login === '') {
                    continue;
                }

                $stmt = $db->prepare("SELECT id, login, display_name FROM users WHERE login = ?");
                $stmt->execute([$login]);
                $user = $stmt->fetch();

                if (!$user) {
                    $bulkGroupResults['failed'][] = "Строка " . ($lineNum + 1) . ": пользователь '" . htmlspecialchars($login) . "' не найден";
                    continue;
                }

                try {
                    $stmt2 = $db->prepare("INSERT OR IGNORE INTO user_groups (user_id, group_id) VALUES (?, ?)");
                    $stmt2->execute([$user['id'], $groupId]);
                    $bulkGroupResults['success'][] = $user['display_name'] . ' (' . $user['login'] . ')';
                } catch (PDOException $e) {
                    $bulkGroupResults['failed'][] = "Строка " . ($lineNum + 1) . " (" . htmlspecialchars($login) . "): ошибка добавления";
                }
            }
        }

        if (count($bulkGroupResults['success']) > 0) {
            $message = 'Добавлено в группу пользователей: ' . count($bulkGroupResults['success']);
        }
        if (count($bulkGroupResults['failed']) > 0) {
            $bulkGroupError = implode('<br>', $bulkGroupResults['failed']);
        }
    }
}

$groups = $db->query("SELECT g.*, (SELECT COUNT(*) FROM user_groups ug WHERE ug.group_id = g.id) AS user_count FROM groups g ORDER BY g.name")->fetchAll();
$allUsers = Auth::getAllUsers();

$editGroup = null;
$groupUsers = [];
if (isset($_GET['edit'])) {
    $groupId = (int) $_GET['edit'];
    $stmt = $db->prepare("SELECT * FROM groups WHERE id = ?");
    $stmt->execute([$groupId]);
    $editGroup = $stmt->fetch();

    $stmt = $db->prepare("SELECT u.* FROM users u JOIN user_groups ug ON u.id = ug.user_id WHERE ug.group_id = ? ORDER BY u.login");
    $stmt->execute([$groupId]);
    $groupUsers = $stmt->fetchAll() ?: [];
}

ob_start();
?>

<h1>Управление группами</h1>

<?php $activePage = 'groups'; require BASE_PATH . '/templates/admin_nav.php'; ?>

<?php if ($message): ?>
    <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: 1fr 400px; gap: 24px;">
    <div>
        <h3>Список групп</h3>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Название</th>
                    <th>Учеников</th>
                    <th>Действия</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($groups as $group): ?>
                <tr>
                    <td><?= $group['id'] ?></td>
                    <td><?= htmlspecialchars($group['name']) ?></td>
                    <td><?= (int) $group['user_count'] ?></td>
                    <td>
                        <a href="?page=admin-groups&edit=<?= $group['id'] ?>" class="btn btn-sm">Ред.</a>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Удалить группу?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $group['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger">Удалить</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div>
        <div class="card">
            <h3><?= $editGroup ? 'Редактировать' : 'Создать' ?> группу</h3>
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="<?= $editGroup ? 'update' : 'create' ?>">
                <?php if ($editGroup): ?>
                    <input type="hidden" name="id" value="<?= $editGroup['id'] ?>">
                <?php endif; ?>

                <div class="form-group">
                    <label for="name">Название</label>
                    <input type="text" id="name" name="name" required
                           value="<?= htmlspecialchars($editGroup['name'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="description">Описание</label>
                    <input type="text" id="description" name="description"
                           value="<?= htmlspecialchars($editGroup['description'] ?? '') ?>">
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary"><?= $editGroup ? 'Сохранить' : 'Создать' ?></button>
                    <?php if ($editGroup): ?>
                        <a href="?page=admin-groups" class="btn">Отмена</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <?php if ($editGroup): ?>
        <div class="card mt-20">
            <h3>Пользователи в группе «<?= htmlspecialchars($editGroup['name']) ?>»</h3>

            <?php if ($groupUsers): ?>
                <table>
                    <thead>
                        <tr><th>Логин</th><th>Имя</th><th></th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($groupUsers as $gu): ?>
                        <tr>
                            <td><?= htmlspecialchars($gu['login']) ?></td>
                            <td><?= htmlspecialchars($gu['display_name']) ?></td>
                            <td>
                                <form method="POST" style="display:inline">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="remove_user">
                                    <input type="hidden" name="group_id" value="<?= $editGroup['id'] ?>">
                                    <input type="hidden" name="user_id" value="<?= $gu['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">Убрать</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p style="color: var(--text-muted);">В группе нет пользователей</p>
            <?php endif; ?>

            <h4 class="mt-20">Добавить пользователя</h4>
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="add_user">
                <input type="hidden" name="group_id" value="<?= $editGroup['id'] ?>">
                <div class="form-group">
                    <select name="user_id">
                        <?php foreach ($allUsers as $u): ?>
                            <?php
                            $alreadyIn = array_filter($groupUsers, fn($gu) => $gu['id'] == $u['id']);
                            if (empty($alreadyIn)):
                            ?>
                                <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['login']) ?> (<?= htmlspecialchars($u['display_name']) ?>)</option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary btn-sm">Добавить</button>
            </form>

            <h4 class="mt-20">Добавить пользователей списком</h4>
            <p style="color:#666;font-size:13px;">По одному логину на строку</p>

            <?php if (isset($bulkGroupError)): ?>
                <div class="alert alert-error" style="margin-top:8px;">Ошибки:<br><?= $bulkGroupError ?></div>
            <?php endif; ?>
            <?php if (isset($bulkGroupResults) && count($bulkGroupResults['success']) > 0): ?>
                <details style="margin-top:8px;">
                    <summary>Добавлены (<?= count($bulkGroupResults['success']) ?>):</summary>
                    <ul style="margin-top:4px;font-size:13px;">
                        <?php foreach ($bulkGroupResults['success'] as $s): ?>
                            <li><?= htmlspecialchars($s) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            <?php endif; ?>

            <form method="POST" style="margin-top:8px;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="bulk_add_users">
                <input type="hidden" name="group_id" value="<?= $editGroup['id'] ?>">
                <div class="form-group">
                    <textarea name="bulk_logins" rows="6" style="width:100%;font-family:monospace;"
                              placeholder="ivanov
petrov
sidorov"></textarea>
                </div>
                <button type="submit" class="btn btn-primary btn-sm">Добавить списком</button>
            </form>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php
$content = ob_get_clean();
require BASE_PATH . '/templates/layout.php';