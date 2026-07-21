<?php
$pageTitle = 'Администрирование';
$db = Database::getInstance();

$syncMessage = '';
$syncError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'sync_users') {
        if (!validateCsrf()) {
            $syncError = 'Недействительный CSRF-токен';
        } else {
            $result = Database::syncUsers();
            if ($result['success']) {
                $syncMessage = 'Синхронизировано: ' . $result['synced'] . ', удалено: ' . $result['deleted'];
            } else {
                $syncError = $result['error'];
            }
        }
    }
}

$userCount = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
if ($userCount == 0) {
    $result = Database::syncUsers();
    if ($result['success']) {
        $syncMessage = 'Автосинхронизация: загружено ' . $result['synced'] . ' пользователей';
        $userCount = $result['synced'];
    }
}

$stats = $db->query("
    SELECT
        (SELECT COUNT(*) FROM users) as users,
        (SELECT COUNT(*) FROM groups) as groups,
        (SELECT COUNT(*) FROM tasks) as tasks,
        (SELECT COUNT(*) FROM task_groups) as task_groups,
        (SELECT COUNT(*) FROM contests) as contests,
        (SELECT COUNT(*) FROM submissions) as submissions
")->fetch();

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
    <h2>Пользователи</h2>
    <p style="color: var(--text-muted); margin-bottom: 16px;">
        Управление пользователями осуществляется через
        <a href="https://auth.nayanovaacademy.ru/index.php?page=admin-users" target="_blank">панель авторизации</a>.
    </p>

    <?php if ($syncMessage): ?>
        <div class="alert alert-success"><?= htmlspecialchars($syncMessage) ?></div>
    <?php endif; ?>

    <?php if ($syncError): ?>
        <div class="alert alert-error"><?= htmlspecialchars($syncError) ?></div>
    <?php endif; ?>

    <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="sync_users">
        <button type="submit" class="btn btn-primary">Синхронизировать пользователей</button>
    </form>
</div>

<?php
$content = ob_get_clean();
require BASE_PATH . '/templates/layout.php';
