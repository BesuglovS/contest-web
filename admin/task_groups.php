<?php
$pageTitle = 'Группы задач';
$db = Database::getInstance();
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $name = trim($_POST['name']);
        if ($name) {
            $db->prepare("INSERT INTO task_groups (name, description) VALUES (?, ?)")->execute([$name, $_POST['description'] ?? '']);
            $message = 'Группа задач создана';
        }
    }
    if ($action === 'update') {
        $db->prepare("UPDATE task_groups SET name=?, description=? WHERE id=?")->execute([trim($_POST['name']), $_POST['description'] ?? '', (int)$_POST['id']]);
        $message = 'Группа обновлена';
    }
    if ($action === 'delete') {
        $db->prepare("DELETE FROM task_groups WHERE id=?")->execute([(int)$_POST['id']]);
        $message = 'Группа удалена';
    }
    if ($action === 'add_task') {
        try {
            $db->prepare("INSERT OR IGNORE INTO task_to_groups (task_id, task_group_id) VALUES (?, ?)")->execute([(int)$_POST['task_id'], (int)$_POST['group_id']]);
            $message = 'Задача добавлена в группу';
        } catch (PDOException $e) {}
    }
    if ($action === 'remove_task') {
        $db->prepare("DELETE FROM task_to_groups WHERE task_id=? AND task_group_id=?")->execute([(int)$_POST['task_id'], (int)$_POST['group_id']]);
        $message = 'Задача убрана из группы';
    }
}

$groups = $db->query("SELECT * FROM task_groups ORDER BY name")->fetchAll();
$allTasks = $db->query("SELECT id, title FROM tasks ORDER BY title")->fetchAll();

$editGroup = null;
$groupTasks = [];
if (isset($_GET['edit'])) {
    $gid = (int)$_GET['edit'];
    $editGroup = $db->prepare("SELECT * FROM task_groups WHERE id=?")->execute([$gid]) ? $db->prepare("SELECT * FROM task_groups WHERE id=?")->fetch() : null;
    $stmt = $db->prepare("SELECT * FROM task_groups WHERE id=?");
    $stmt->execute([$gid]);
    $editGroup = $stmt->fetch();
$stmt = $db->prepare("SELECT t.* FROM tasks t INNER JOIN task_to_groups ttg ON t.id=ttg.task_id WHERE ttg.task_group_id=? ORDER BY t.id");
    $stmt->execute([$gid]);
    $groupTasks = $stmt->fetchAll() ?: [];
}

ob_start();
?>

<h1>Группы задач</h1>

<?php $activePage = 'task_groups'; require BASE_PATH . '/templates/admin_nav.php'; ?>

<?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>

<div style="display: grid; grid-template-columns: 1fr 400px; gap: 24px;">
    <div>
        <h3>Список групп задач</h3>
        <table>
            <tr><th>ID</th><th>Название</th><th>Описание</th><th></th></tr>
            <?php foreach ($groups as $g): ?>
            <tr>
                <td><?= $g['id'] ?></td>
                <td><?= htmlspecialchars($g['name']) ?></td>
                <td><?= htmlspecialchars($g['description']) ?></td>
                <td>
                    <a href="?page=admin-task-groups&edit=<?= $g['id'] ?>" class="btn btn-sm">Ред.</a>
                    <form method="POST" style="display:inline" onsubmit="return confirm('Удалить?')">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= $g['id'] ?>">
                        <button class="btn btn-sm btn-danger">Удалить</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <div>
        <div class="card">
            <h3><?= $editGroup ? 'Редактировать' : 'Создать' ?> группу задач</h3>
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="<?= $editGroup ? 'update' : 'create' ?>">
                <?php if ($editGroup): ?><input type="hidden" name="id" value="<?= $editGroup['id'] ?>"><?php endif; ?>
                <div class="form-group">
                    <label>Название</label>
                    <input type="text" name="name" required value="<?= htmlspecialchars($editGroup['name'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Описание</label>
                    <input type="text" name="description" value="<?= htmlspecialchars($editGroup['description'] ?? '') ?>">
                </div>
                <button type="submit" class="btn btn-primary"><?= $editGroup ? 'Сохранить' : 'Создать' ?></button>
                <?php if ($editGroup): ?><a href="?page=admin-task-groups" class="btn">Отмена</a><?php endif; ?>
            </form>
        </div>
        <?php if ($editGroup): ?>
        <div class="card mt-20">
            <h3>Задачи в группе «<?= htmlspecialchars($editGroup['name']) ?>»</h3>
            <?php if ($groupTasks): ?>
                <table>
                    <?php foreach ($groupTasks as $gt): ?>
                    <tr>
                        <td><?= htmlspecialchars($gt['title']) ?></td>
                        <td>
                            <form method="POST">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="remove_task">
                                <input type="hidden" name="group_id" value="<?= $editGroup['id'] ?>">
                                <input type="hidden" name="task_id" value="<?= $gt['id'] ?>">
                                <button class="btn btn-sm btn-danger">Убрать</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            <?php else: ?>
                <p style="color: var(--text-muted);">Нет задач</p>
            <?php endif; ?>
            <h4 class="mt-20">Добавить задачу</h4>
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="add_task">
                <input type="hidden" name="group_id" value="<?= $editGroup['id'] ?>">
                <select name="task_id">
                    <?php foreach ($allTasks as $t):
                        $inGroup = array_filter($groupTasks, fn($gt) => $gt['id'] == $t['id']);
                        if (empty($inGroup)):
                    ?>
                        <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['title']) ?></option>
                    <?php endif; endforeach; ?>
                </select>
                <button type="submit" class="btn btn-primary btn-sm">Добавить</button>
            </form>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php
$content = ob_get_clean();
require BASE_PATH . '/templates/layout.php';