<?php
$pageTitle = 'Администрирование';
$db = Database::getInstance();

$stats = $db->query("
    SELECT
        (SELECT COUNT(*) FROM groups) as groups,
        (SELECT COUNT(*) FROM tasks) as tasks,
        (SELECT COUNT(*) FROM task_groups) as task_groups,
        (SELECT COUNT(*) FROM contests) as contests,
        (SELECT COUNT(*) FROM submissions) as submissions
")->fetch();
$stats['users'] = count(Auth::getAllUsers());

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
        Единый источник данных о пользователях — сервис auth.nayanovaacademy.ru.
    </p>
</div>

<?php
$content = ob_get_clean();
require BASE_PATH . '/templates/layout.php';
