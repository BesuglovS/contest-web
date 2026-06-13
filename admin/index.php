<?php
$pageTitle = 'Администрирование';
$db = Database::getInstance();

$stats = [
    'users' => $db->query("SELECT COUNT(*) FROM users")->fetchColumn(),
    'groups' => $db->query("SELECT COUNT(*) FROM groups")->fetchColumn(),
    'tasks' => $db->query("SELECT COUNT(*) FROM tasks")->fetchColumn(),
    'task_groups' => $db->query("SELECT COUNT(*) FROM task_groups")->fetchColumn(),
    'contests' => $db->query("SELECT COUNT(*) FROM contests")->fetchColumn(),
    'submissions' => $db->query("SELECT COUNT(*) FROM submissions")->fetchColumn(),
];

$passwordError = '';
$passwordSuccess = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($newPassword !== $confirmPassword) {
        $passwordError = 'Новый пароль и подтверждение не совпадают';
    } else {
        $result = Auth::changePassword($currentPassword, $newPassword);
        if ($result['success']) {
            $passwordSuccess = 'Пароль успешно изменён';
        } else {
            $passwordError = $result['error'];
        }
    }
}

ob_start();
?>

<h1>Панель администратора</h1>

<div class="admin-nav">
    <a href="<?= BASE_URL ?>/index.php?page=admin" class="active">Дашборд</a>
    <a href="<?= BASE_URL ?>/index.php?page=admin-users">Пользователи</a>
    <a href="<?= BASE_URL ?>/index.php?page=admin-groups">Группы</a>
    <a href="<?= BASE_URL ?>/index.php?page=admin-tasks">Задачи</a>
    <a href="<?= BASE_URL ?>/index.php?page=admin-task-groups">Группы задач</a>
    <a href="<?= BASE_URL ?>/index.php?page=admin-contests">Контесты</a>
    <a href="<?= BASE_URL ?>/index.php?page=admin-submissions">Решения</a>
    <a href="<?= BASE_URL ?>/index.php?page=admin-import-tasks">Импорт задач</a>
    <a href="<?= BASE_URL ?>/index.php?page=admin-change-password">Сменить пароль</a>
</div>

<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 16px;">
    <div class="card" style="text-align: center;">
        <h3 style="font-size: 2em; color: var(--primary);"><?= $stats['users'] ?></h3>
        <p style="color: var(--text-muted);">Пользователей</p>
    </div>
    <div class="card" style="text-align: center;">
        <h3 style="font-size: 2em; color: var(--primary);"><?= $stats['groups'] ?></h3>
        <p style="color: var(--text-muted);">Групп</p>
    </div>
    <div class="card" style="text-align: center;">
        <h3 style="font-size: 2em; color: var(--primary);"><?= $stats['tasks'] ?></h3>
        <p style="color: var(--text-muted);">Задач</p>
    </div>
    <div class="card" style="text-align: center;">
        <h3 style="font-size: 2em; color: var(--primary);"><?= $stats['task_groups'] ?></h3>
        <p style="color: var(--text-muted);">Групп задач</p>
    </div>
    <div class="card" style="text-align: center;">
        <h3 style="font-size: 2em; color: var(--primary);"><?= $stats['contests'] ?></h3>
        <p style="color: var(--text-muted);">Контестов</p>
    </div>
    <div class="card" style="text-align: center;">
        <h3 style="font-size: 2em; color: var(--primary);"><?= $stats['submissions'] ?></h3>
        <p style="color: var(--text-muted);">Решений</p>
    </div>
</div>

<div class="card" style="max-width: 500px; margin-top: 24px;">
    <h2>Сменить пароль</h2>

    <?php if ($passwordError): ?>
        <div class="alert alert-error"><?= htmlspecialchars($passwordError) ?></div>
    <?php endif; ?>

    <?php if ($passwordSuccess): ?>
        <div class="alert alert-success"><?= htmlspecialchars($passwordSuccess) ?></div>
    <?php endif; ?>

    <form method="POST" action="">
        <input type="hidden" name="change_password" value="1">

        <div class="form-group">
            <label for="current_password">Текущий пароль</label>
            <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
        </div>

        <div class="form-group">
            <label for="new_password">Новый пароль</label>
            <input type="password" id="new_password" name="new_password" required minlength="4" autocomplete="new-password">
        </div>

        <div class="form-group">
            <label for="confirm_password">Подтвердите новый пароль</label>
            <input type="password" id="confirm_password" name="confirm_password" required minlength="4" autocomplete="new-password">
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Сменить пароль</button>
        </div>
    </form>
</div>

<?php
$content = ob_get_clean();
require BASE_PATH . '/templates/layout.php';
