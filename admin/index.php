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

<?php
$content = ob_get_clean();
require BASE_PATH . '/templates/layout.php';