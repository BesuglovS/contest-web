<?php
/**
 * Единый шаблон навигации админки.
 * Переменные:
 *   $activePage  — ключ активной страницы (например 'dashboard', 'users', 'tasks' и т.д.)
 */
$base = defined('BASE_URL') ? BASE_URL : '';
$navItems = [
    'dashboard'      => ['label' => 'Дашборд',        'href' => "$base/index.php?page=admin"],
    'users'          => ['label' => 'Пользователи',    'href' => "$base/index.php?page=admin-users"],
    'groups'         => ['label' => 'Группы',          'href' => "$base/index.php?page=admin-groups"],
    'tasks'          => ['label' => 'Задачи',          'href' => "$base/index.php?page=admin-tasks"],
    'task_groups'    => ['label' => 'Группы задач',    'href' => "$base/index.php?page=admin-task-groups"],
    'contests'       => ['label' => 'Контесты',        'href' => "$base/index.php?page=admin-contests"],
    'submissions'    => ['label' => 'Решения',         'href' => "$base/index.php?page=admin-submissions"],
    'import_tasks'   => ['label' => 'Импорт задач',    'href' => "$base/index.php?page=admin-import-tasks"],
];

?>
<div class="admin-nav">
    <?php foreach ($navItems as $key => $item): ?>
        <a href="<?= $item['href'] ?>"<?= ($activePage ?? '') === $key ? ' class="active"' : '' ?>><?= htmlspecialchars($item['label']) ?></a>
    <?php endforeach; ?>
</div>