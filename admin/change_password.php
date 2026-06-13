<?php
$pageTitle = 'Смена пароля';
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($newPassword !== $confirmPassword) {
        $error = 'Новый пароль и подтверждение не совпадают';
    } else {
        $result = Auth::changePassword($currentPassword, $newPassword);
        if ($result['success']) {
            $success = 'Пароль успешно изменён';
        } else {
            $error = $result['error'];
        }
    }
}

ob_start();
?>

<h1>Смена пароля</h1>

<div class="admin-nav">
    <a href="<?= BASE_URL ?>/index.php?page=admin">Дашборд</a>
    <a href="<?= BASE_URL ?>/index.php?page=admin-users">Пользователи</a>
    <a href="<?= BASE_URL ?>/index.php?page=admin-groups">Группы</a>
    <a href="<?= BASE_URL ?>/index.php?page=admin-tasks">Задачи</a>
    <a href="<?= BASE_URL ?>/index.php?page=admin-task-groups">Группы задач</a>
    <a href="<?= BASE_URL ?>/index.php?page=admin-contests">Контесты</a>
    <a href="<?= BASE_URL ?>/index.php?page=admin-submissions">Решения</a>
    <a href="<?= BASE_URL ?>/index.php?page=admin-import-tasks">Импорт задач</a>
</div>

<div class="card" style="max-width: 500px;">
    <h2>Изменить пароль</h2>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <form method="POST" action="">
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
            <a href="<?= BASE_URL ?>/index.php?page=admin" class="btn">Отмена</a>
        </div>
    </form>
</div>

<?php
$content = ob_get_clean();
require BASE_PATH . '/templates/layout.php';