<?php
$pageTitle = 'Управление пользователями';
$db = Database::getInstance();
$message = '';
$error = '';

// Обработка действий
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $login = trim($_POST['login']);
        $displayName = trim($_POST['display_name']);
        $password = $_POST['password'];
        $isAdmin = isset($_POST['is_admin']) ? 1 : 0;

        if ($login && $displayName && $password) {
            $result = Auth::createUser($login, $displayName, $password, (bool) $isAdmin);
            if ($result['success']) {
                $message = 'Пользователь создан';
            } else {
                $error = $result['error'];
            }
        } else {
            $error = 'Заполните все поля';
        }
    }

    if ($action === 'bulk_import') {
        $bulkResults = ['success' => [], 'failed' => []];
        $rawText = '';

        // Источник данных: файл или текстовое поле
        if (!empty($_FILES['bulk_file']['tmp_name'])) {
            $rawText = file_get_contents($_FILES['bulk_file']['tmp_name']);
        } elseif (!empty($_POST['bulk_text'])) {
            $rawText = trim($_POST['bulk_text']);
        }

        if ($rawText !== '') {
            $lines = preg_split('/\r\n|\r|\n/', $rawText);
            foreach ($lines as $lineNum => $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                // Поддержка разделителей: табуляция, запятая, пробелы
                $parts = preg_split('/\t+/', $line);
                if (count($parts) < 3) {
                    $parts = preg_split('/\s*,\s*/', $line);
                }
                if (count($parts) < 3) {
                    // Пробелы: первые два слова могут содержать пробелы в имени,
                    // поэтому просто бьём по 2+ пробелам
                    $parts = preg_split('/\s{2,}/', $line);
                }
                if (count($parts) < 3) {
                    // Если всё ещё меньше 3 — бьём по последним двум пробелам
                    $parts = preg_split('/\s+/', $line);
                    if (count($parts) >= 3) {
                        // Объединяем всё кроме последних двух в display_name
                        $displayName = implode(' ', array_slice($parts, 0, -2));
                        $login = $parts[count($parts) - 2];
                        $password = $parts[count($parts) - 1];
                    } else {
                        $bulkResults['failed'][] = "Строка " . ($lineNum + 1) . ": недостаточно полей (нужно: имя, логин, пароль)";
                        continue;
                    }
                } else {
                    $displayName = trim($parts[0]);
                    $login = trim($parts[1]);
                    $password = trim($parts[2]);
                }

                if ($displayName === '' || $login === '' || $password === '') {
                    $bulkResults['failed'][] = "Строка " . ($lineNum + 1) . ": пустое поле";
                    continue;
                }

                $result = Auth::createUser($login, $displayName, $password, false);
                if ($result['success']) {
                    $bulkResults['success'][] = $displayName . ' (' . $login . ')';
                } else {
                    $bulkResults['failed'][] = "Строка " . ($lineNum + 1) . " (" . htmlspecialchars($login) . "): " . htmlspecialchars($result['error']);
                }
            }
        }

        if (count($bulkResults['success']) > 0) {
            $message = 'Добавлено пользователей: ' . count($bulkResults['success']);
        }
        if (count($bulkResults['failed']) > 0) {
            $bulkError = implode('<br>', $bulkResults['failed']);
        }
    }

    if ($action === 'update') {
        $id = (int) $_POST['id'];
        $login = trim($_POST['login']);
        $displayName = trim($_POST['display_name']);
        $isAdmin = isset($_POST['is_admin']) ? 1 : 0;
        $password = $_POST['password'] ?: null;

        $result = Auth::updateUser($id, $login, $displayName, (bool) $isAdmin, $password);
        if ($result['success']) {
            $message = 'Пользователь обновлён';
        } else {
            $error = $result['error'];
        }
    }

    if ($action === 'delete') {
        $id = (int) $_POST['id'];
        if (Auth::deleteUser($id)) {
            $message = 'Пользователь удалён';
        } else {
            $error = 'Не удалось удалить пользователя';
        }
    }
}

$users = Auth::getAllUsers();
$editUser = null;
if (isset($_GET['edit'])) {
    $editUser = Auth::getUserById((int) $_GET['edit']);
}

ob_start();
?>

<h1>Управление пользователями</h1>

<div class="admin-nav">
    <a href="?page=admin">Дашборд</a>
    <a href="?page=admin-users" class="active">Пользователи</a>
    <a href="?page=admin-groups">Группы</a>
    <a href="?page=admin-tasks">Задачи</a>
    <a href="?page=admin-task-groups">Группы задач</a>
    <a href="?page=admin-contests">Контесты</a>
    <a href="?page=admin-submissions">Решения</a>
    <a href="?page=admin-import-tasks">Импорт задач</a>
</div>

<?php if ($message): ?>
    <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: 1fr 350px; gap: 24px;">
    <div>
        <h3>Список пользователей</h3>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Логин</th>
                    <th>Имя</th>
                    <th>Роль</th>
                    <th>Дата</th>
                    <th>Действия</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user): ?>
                <tr>
                    <td><?= $user['id'] ?></td>
                    <td><?= htmlspecialchars($user['login']) ?></td>
                    <td><?= htmlspecialchars($user['display_name']) ?></td>
                    <td>
                        <?php if ($user['is_admin']): ?>
                            <span class="badge badge-admin">Админ</span>
                        <?php else: ?>
                            <span class="badge badge-user">Пользователь</span>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars(toDisplayTime($user['created_at'] ?? '')) ?></td>
                    <td>
                        <a href="?page=admin-users&edit=<?= $user['id'] ?>" class="btn btn-sm">Ред.</a>
                        <?php if ($user['id'] != 1): ?>
                            <form method="POST" style="display:inline" onsubmit="return confirm('Удалить пользователя?')">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $user['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger">Удалить</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h3><?= $editUser ? 'Редактировать' : 'Создать' ?> пользователя</h3>
        <form method="POST">
            <input type="hidden" name="action" value="<?= $editUser ? 'update' : 'create' ?>">
            <?php if ($editUser): ?>
                <input type="hidden" name="id" value="<?= $editUser['id'] ?>">
            <?php endif; ?>

            <div class="form-group">
                <label for="login">Логин</label>
                <input type="text" id="login" name="login" required
                       value="<?= htmlspecialchars($editUser['login'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label for="display_name">Отображаемое имя</label>
                <input type="text" id="display_name" name="display_name" required
                       value="<?= htmlspecialchars($editUser['display_name'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label for="password">Пароль <?= $editUser ? '(оставьте пустым чтобы не менять)' : '' ?></label>
                <input type="text" id="password" name="password" <?= $editUser ? '' : 'required' ?>>
            </div>

            <div class="form-group">
                <label>
                    <input type="checkbox" name="is_admin" value="1"
                           <?= ($editUser && $editUser['is_admin']) ? 'checked' : '' ?>>
                    Администратор
                </label>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><?= $editUser ? 'Сохранить' : 'Создать' ?></button>
                <?php if ($editUser): ?>
                    <a href="?page=admin-users" class="btn">Отмена</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<?php if (isset($bulkError)): ?>
    <div class="alert alert-error" style="margin-top:16px;">Ошибки импорта:<br><?= $bulkError ?></div>
<?php endif; ?>

<?php if (isset($bulkResults) && count($bulkResults['success']) > 0): ?>
    <details style="margin-top:16px;">
        <summary>Успешно добавлены (<?= count($bulkResults['success']) ?>):</summary>
        <ul style="margin-top:8px;">
            <?php foreach ($bulkResults['success'] as $s): ?>
                <li><?= htmlspecialchars($s) ?></li>
            <?php endforeach; ?>
        </ul>
    </details>
<?php endif; ?>

<div class="card" style="margin-top:24px;">
    <h3>Импорт пользователей списком</h3>
    <p style="color:#666;font-size:14px;">
        Каждая строка — один пользователь. Формат: <b>Отображаемое имя, Логин, Пароль</b><br>
        Разделители: табуляция, запятая или 2+ пробела. Либо загрузите текстовый файл (.txt, .csv).
    </p>
    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="bulk_import">

        <div class="form-group">
            <label for="bulk_text">Вставьте список пользователей:</label>
            <textarea id="bulk_text" name="bulk_text" rows="10" style="width:100%;font-family:monospace;"
                      placeholder="Иван Иванов, ivanov, pass123
Петр Петров, petrov, pass456
..."></textarea>
        </div>

        <div class="form-group">
            <label for="bulk_file">Или выберите текстовый файл:</label>
            <input type="file" id="bulk_file" name="bulk_file" accept=".txt,.csv">
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Импортировать</button>
        </div>
    </form>
</div>

<?php
$content = ob_get_clean();
require BASE_PATH . '/templates/layout.php';