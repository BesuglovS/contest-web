<?php
$pageTitle = 'Администрирование';
$db = Database::getInstance();

$stats = $db->query("
    SELECT
        (SELECT COUNT(*) FROM users) as users,
        (SELECT COUNT(*) FROM groups) as groups,
        (SELECT COUNT(*) FROM tasks) as tasks,
        (SELECT COUNT(*) FROM task_groups) as task_groups,
        (SELECT COUNT(*) FROM contests) as contests,
        (SELECT COUNT(*) FROM submissions) as submissions
")->fetch();

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

<?php $activePage = 'dashboard'; require BASE_PATH . '/templates/admin_nav.php'; ?>

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
        <?= csrfField() ?>
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
