<?php
$pageTitle = 'Управление контестами';
$db = Database::getInstance();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $title = trim($_POST['title']);
        $description = $_POST['description'] ?? '';
        $startTime = $_POST['start_time'] ? toUtcTime($_POST['start_time']) : utcNow();
        $endTime = $_POST['end_time'] ? toUtcTime($_POST['end_time']) : null;

        if ($title) {
            $db->prepare("INSERT INTO contests (title, description, start_time, end_time) VALUES (?, ?, ?, ?)")
               ->execute([$title, $description, $startTime, $endTime]);
            $contestId = $db->lastInsertId();

            // Добавляем задачи с учётом порядка
            $sortOrder = 0;
            $addedTaskIds = []; // чтобы не дублировать задачи из групп
            if (!empty($_POST['task_ids'])) {
                foreach ($_POST['task_ids'] as $taskId) {
                    if ($taskId === '') continue;
                    $taskId = (int)$taskId;
                    if (isset($addedTaskIds[$taskId])) continue;
                    $sortOrder++;
                    $addedTaskIds[$taskId] = true;
                    $db->prepare("INSERT OR IGNORE INTO contest_tasks (contest_id, task_id, sort_order) VALUES (?, ?, ?)")
                       ->execute([$contestId, $taskId, $sortOrder]);
                }
            }
            if (!empty($_POST['task_group_ids'])) {
                foreach ($_POST['task_group_ids'] as $tgId) {
                    $stmt = $db->prepare("SELECT task_id FROM task_to_groups WHERE task_group_id=?");
                    $stmt->execute([(int)$tgId]);
                    $tasks = $stmt->fetchAll() ?: [];
                    foreach ($tasks as $t) {
                        if (isset($addedTaskIds[$t['task_id']])) continue;
                        $sortOrder++;
                        $addedTaskIds[$t['task_id']] = true;
                        $db->prepare("INSERT OR IGNORE INTO contest_tasks (contest_id, task_id, sort_order) VALUES (?, ?, ?)")
                           ->execute([$contestId, $t['task_id'], $sortOrder]);
                    }
                }
            }

            // Сохраняем группы задач
            if (!empty($_POST['task_group_ids'])) {
                foreach ($_POST['task_group_ids'] as $tgId) {
                    $db->prepare("INSERT OR IGNORE INTO contest_task_groups (contest_id, task_group_id) VALUES (?, ?)")->execute([$contestId, (int)$tgId]);
                }
            }

            // Доступ
            if (!empty($_POST['group_ids'])) {
                foreach ($_POST['group_ids'] as $gid) {
                    $db->prepare("INSERT OR IGNORE INTO contest_access (contest_id, group_id) VALUES (?, ?)")->execute([$contestId, (int)$gid]);
                }
            }
            if (!empty($_POST['user_ids'])) {
                foreach ($_POST['user_ids'] as $uid) {
                    $db->prepare("INSERT OR IGNORE INTO contest_access (contest_id, user_id) VALUES (?, ?)")->execute([$contestId, (int)$uid]);
                }
            }

            $message = 'Контест создан';
        }
    }

    if ($action === 'update') {
        $id = (int)$_POST['id'];
        $title = trim($_POST['title']);
        $description = $_POST['description'] ?? '';
        $startTime = $_POST['start_time'] ? toUtcTime($_POST['start_time']) : utcNow();
        $endTime = $_POST['end_time'] ? toUtcTime($_POST['end_time']) : null;

        $db->prepare("UPDATE contests SET title=?, description=?, start_time=?, end_time=? WHERE id=?")
           ->execute([$title, $description, $startTime, $endTime, $id]);

        // Перестраиваем задачи, группы задач и доступ
        $db->prepare("DELETE FROM contest_tasks WHERE contest_id=?")->execute([$id]);
        $db->prepare("DELETE FROM contest_task_groups WHERE contest_id=?")->execute([$id]);
        $db->prepare("DELETE FROM contest_access WHERE contest_id=?")->execute([$id]);

        $sortOrder = 0;
        $addedTaskIds = []; // чтобы не дублировать задачи из групп
        if (!empty($_POST['task_ids'])) {
            foreach ($_POST['task_ids'] as $taskId) {
                if ($taskId === '') continue;
                $taskId = (int)$taskId;
                if (isset($addedTaskIds[$taskId])) continue;
                $sortOrder++;
                $addedTaskIds[$taskId] = true;
                $db->prepare("INSERT OR IGNORE INTO contest_tasks (contest_id, task_id, sort_order) VALUES (?, ?, ?)")->execute([$id, $taskId, $sortOrder]);
            }
        }
        if (!empty($_POST['task_group_ids'])) {
            foreach ($_POST['task_group_ids'] as $tgId) {
                $stmt = $db->prepare("SELECT task_id FROM task_to_groups WHERE task_group_id=?");
                $stmt->execute([(int)$tgId]);
                foreach ($stmt->fetchAll() ?: [] as $t) {
                    if (isset($addedTaskIds[$t['task_id']])) continue;
                    $sortOrder++;
                    $addedTaskIds[$t['task_id']] = true;
                    $db->prepare("INSERT OR IGNORE INTO contest_tasks (contest_id, task_id, sort_order) VALUES (?, ?, ?)")->execute([$id, $t['task_id'], $sortOrder]);
                }
            }
        }
        if (!empty($_POST['task_group_ids'])) {
            foreach ($_POST['task_group_ids'] as $tgId) {
                $db->prepare("INSERT OR IGNORE INTO contest_task_groups (contest_id, task_group_id) VALUES (?, ?)")->execute([$id, (int)$tgId]);
            }
        }
        if (!empty($_POST['group_ids'])) {
            foreach ($_POST['group_ids'] as $gid) {
                $db->prepare("INSERT OR IGNORE INTO contest_access (contest_id, group_id) VALUES (?, ?)")->execute([$id, (int)$gid]);
            }
        }
        if (!empty($_POST['user_ids'])) {
            foreach ($_POST['user_ids'] as $uid) {
                $db->prepare("INSERT OR IGNORE INTO contest_access (contest_id, user_id) VALUES (?, ?)")->execute([$id, (int)$uid]);
            }
        }

        $message = 'Контест обновлён';
    }

    if ($action === 'delete') {
        $db->prepare("DELETE FROM contests WHERE id=?")->execute([(int)$_POST['id']]);
        $message = 'Контест удалён';
    }
}

$contests = $db->query("SELECT * FROM contests ORDER BY id DESC")->fetchAll();
$allTasks = $db->query("SELECT id, title FROM tasks ORDER BY title")->fetchAll();
$allTaskGroups = $db->query("SELECT id, name FROM task_groups ORDER BY name")->fetchAll();
$allGroups = $db->query("SELECT id, name FROM groups ORDER BY name")->fetchAll();
$allUsers = Auth::getAllUsers();

$editContest = null;
if (isset($_GET['edit'])) {
    if ($_GET['edit'] === 'new') {
        $editContest = ['_new' => true];
    } else {
        $cid = (int)$_GET['edit'];
        $stmt = $db->prepare("SELECT * FROM contests WHERE id=?");
        $stmt->execute([$cid]);
        $editContest = $stmt->fetch();

        if ($editContest) {
            $stmt = $db->prepare("SELECT ct.task_id, ct.sort_order, t.title FROM contest_tasks ct JOIN tasks t ON ct.task_id = t.id WHERE ct.contest_id=? ORDER BY ct.sort_order, t.id");
            $stmt->execute([$cid]);
            $editContest['contest_tasks'] = $stmt->fetchAll() ?: [];
            $editContest['task_ids'] = array_column($editContest['contest_tasks'], 'task_id');

            $stmt = $db->prepare("SELECT group_id FROM contest_access WHERE contest_id=? AND group_id IS NOT NULL");
            $stmt->execute([$cid]);
            $editContest['group_ids'] = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

            $stmt = $db->prepare("SELECT user_id FROM contest_access WHERE contest_id=? AND user_id IS NOT NULL");
            $stmt->execute([$cid]);
            $editContest['user_ids'] = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

            $stmt = $db->prepare("SELECT task_group_id FROM contest_task_groups WHERE contest_id=?");
            $stmt->execute([$cid]);
            $editContest['task_group_ids'] = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        }
    }
}

ob_start();
?>

<h1>Управление контестами</h1>

<div class="admin-nav">
    <a href="?page=admin">Дашборд</a>
    <a href="?page=admin-users">Пользователи</a>
    <a href="?page=admin-groups">Группы</a>
    <a href="?page=admin-tasks">Задачи</a>
    <a href="?page=admin-task-groups">Группы задач</a>
    <a href="?page=admin-contests" class="active">Контесты</a>
    <a href="?page=admin-submissions">Решения</a>
    <a href="?page=admin-import-tasks">Импорт задач</a>
</div>

<?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<?php if (!$editContest): ?>
    <a href="?page=admin-contests&edit=new" class="btn btn-primary mb-20">+ Новый контест</a>
    <table>
        <tr><th>ID</th><th>Название</th><th>Начало</th><th>Конец</th><th></th></tr>
        <?php foreach ($contests as $c): ?>
        <tr>
            <td><?= $c['id'] ?></td>
            <td><?= htmlspecialchars($c['title']) ?></td>
             <td><?= htmlspecialchars(toDisplayTime($c['start_time']) ?? '') ?></td>
             <td><?= htmlspecialchars(toDisplayTime($c['end_time']) ?? '—') ?></td>
            <td>
                <a href="?page=admin-contest-results&id=<?= $c['id'] ?>" class="btn btn-sm">Результаты</a>
                <a href="?page=admin-contests&edit=<?= $c['id'] ?>" class="btn btn-sm">Ред.</a>
                <form method="POST" style="display:inline" onsubmit="return confirm('Удалить?')">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= $c['id'] ?>">
                    <button class="btn btn-sm btn-danger">Удалить</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
<?php else: ?>
    <form method="POST">
        <input type="hidden" name="action" value="<?= isset($editContest['id']) ? 'update' : 'create' ?>">
        <?php if (isset($editContest['id'])): ?><input type="hidden" name="id" value="<?= $editContest['id'] ?>"><?php endif; ?>

        <div class="card">
            <div class="form-group">
                <label>Название контеста</label>
                <input type="text" name="title" required value="<?= htmlspecialchars($editContest['title'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Описание</label>
                <textarea name="description" style="min-height:100px;"><?= htmlspecialchars($editContest['description'] ?? '') ?></textarea>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                <div class="form-group">
                    <label>Дата начала</label>
                    <input type="datetime-local" name="start_time" value="<?= isset($editContest['start_time']) ? str_replace(' ', 'T', toDisplayTimeInput($editContest['start_time'])) : toDisplayTimeInput(utcNow()) ?>">
                </div>
                <div class="form-group">
                    <label>Дата окончания</label>
                    <input type="datetime-local" name="end_time" value="<?= isset($editContest['end_time']) && $editContest['end_time'] ? str_replace(' ', 'T', toDisplayTimeInput($editContest['end_time'])) : '' ?>">
                </div>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-top: 20px;">
            <div class="card">
                <h3>Задачи</h3>
                <p style="font-size:0.9em; color:var(--text-muted);">Выберите и упорядочьте задачи (перетаскивайте для изменения порядка):</p>
                <div id="task-sortable" class="sortable-list">
                    <?php if (isset($editContest['contest_tasks']) && !empty($editContest['contest_tasks'])): ?>
                        <?php foreach ($editContest['contest_tasks'] as $ct): ?>
                        <div class="sortable-item" draggable="true" data-task-id="<?= $ct['task_id'] ?>">
                            <span class="sortable-handle">☰</span>
                            <input type="hidden" name="task_ids[]" value="<?= $ct['task_id'] ?>">
                            <span><?= htmlspecialchars($ct['title']) ?></span>
                            <span class="sortable-remove" title="Удалить">&times;</span>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <p style="font-size:0.9em; color:var(--text-muted); margin-top:8px;">
                    Добавить задачу:
                    <select id="task-selector" style="margin-top:4px; width:100%;">
                        <option value="">— выберите задачу —</option>
                        <?php foreach ($allTasks as $t): ?>
                        <option value="<?= $t['id'] ?>">
                            <?= htmlspecialchars($t['title']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="btn btn-sm" id="add-task-btn" style="margin-top:4px;">+ Добавить</button>
                </p>
                <p style="font-size:0.9em; color:var(--text-muted); margin-top:12px;">Или добавьте группы задач:</p>
                <div style="max-height:200px; overflow-y:auto;">
                    <?php foreach ($allTaskGroups as $tg): ?>
                    <label style="display:block; padding:4px 0;">
                        <input type="checkbox" name="task_group_ids[]" value="<?= $tg['id'] ?>"
                            <?= (isset($editContest['task_group_ids']) && in_array($tg['id'], $editContest['task_group_ids'])) ? 'checked' : '' ?>>
                        <?= htmlspecialchars($tg['name']) ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card">
                <h3>Доступ</h3>
                <p style="font-size:0.9em; color:var(--text-muted);">Дать доступ группам:</p>
                <div style="max-height:200px; overflow-y:auto;">
                    <?php foreach ($allGroups as $g): ?>
                    <label style="display:block; padding:4px 0;">
                        <input type="checkbox" name="group_ids[]" value="<?= $g['id'] ?>"
                            <?= (isset($editContest['group_ids']) && in_array($g['id'], $editContest['group_ids'])) ? 'checked' : '' ?>>
                        <?= htmlspecialchars($g['name']) ?>
                    </label>
                    <?php endforeach; ?>
                </div>
                <p style="font-size:0.9em; color:var(--text-muted); margin-top:12px;">Дать доступ отдельным пользователям:</p>
                <div style="max-height:200px; overflow-y:auto;">
                    <?php foreach ($allUsers as $u): ?>
                    <label style="display:block; padding:4px 0;">
                        <input type="checkbox" name="user_ids[]" value="<?= $u['id'] ?>"
                            <?= (isset($editContest['user_ids']) && in_array($u['id'], $editContest['user_ids'])) ? 'checked' : '' ?>>
                        <?= htmlspecialchars($u['login']) ?> (<?= htmlspecialchars($u['display_name']) ?>)
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="form-actions mt-20 mb-20">
            <button type="submit" class="btn btn-primary"><?= isset($editContest['id']) ? 'Сохранить' : 'Создать контест' ?></button>
            <a href="?page=admin-contests" class="btn">Отмена</a>
        </div>
    </form>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var sortable = document.getElementById('task-sortable');
    var selector = document.getElementById('task-selector');
    var addBtn = document.getElementById('add-task-btn');

    if (!sortable) return;

    // Drag and drop
    var dragSrcEl = null;

    sortable.addEventListener('dragstart', function(e) {
        var item = e.target.closest('.sortable-item');
        if (!item) return;
        dragSrcEl = item;
        item.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', '');
    });

    sortable.addEventListener('dragend', function(e) {
        var item = e.target.closest('.sortable-item');
        if (item) item.classList.remove('dragging');
        document.querySelectorAll('.sortable-item.drag-over').forEach(function(el) {
            el.classList.remove('drag-over');
        });
    });

    sortable.addEventListener('dragover', function(e) {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        var target = e.target.closest('.sortable-item');
        if (!target || target === dragSrcEl) return;
        target.classList.add('drag-over');
    });

    sortable.addEventListener('dragleave', function(e) {
        var target = e.target.closest('.sortable-item');
        if (target) target.classList.remove('drag-over');
    });

    sortable.addEventListener('drop', function(e) {
        e.preventDefault();
        var target = e.target.closest('.sortable-item');
        if (!target || target === dragSrcEl) return;
        target.classList.remove('drag-over');
        // Move the item
        var rect = target.getBoundingClientRect();
        var mid = rect.top + rect.height / 2;
        if (e.clientY < mid) {
            sortable.insertBefore(dragSrcEl, target);
        } else {
            sortable.insertBefore(dragSrcEl, target.nextSibling);
        }
        updateOptions();
    });

    // Remove task
    sortable.addEventListener('click', function(e) {
        var removeBtn = e.target.closest('.sortable-remove');
        if (!removeBtn) return;
        var item = removeBtn.closest('.sortable-item');
        if (item) {
            var taskId = item.getAttribute('data-task-id');
            item.remove();
            updateOptions();
        }
    });

    // Add task
    addBtn.addEventListener('click', function() {
        var value = selector.value;
        if (!value) return;
        var text = selector.options[selector.selectedIndex].text;
        // Check if already added
        var existing = sortable.querySelector('.sortable-item[data-task-id="' + value + '"]');
        if (existing) return;

        var div = document.createElement('div');
        div.className = 'sortable-item';
        div.draggable = true;
        div.setAttribute('data-task-id', value);
        div.innerHTML = '<span class="sortable-handle">☰</span>' +
            '<input type="hidden" name="task_ids[]" value="' + value + '">' +
            '<span>' + escapeHtml(text) + '</span>' +
            '<span class="sortable-remove" title="Удалить">&times;</span>';
        sortable.appendChild(div);
        selector.value = '';
        updateOptions();
    });

    function updateOptions() {
        var items = sortable.querySelectorAll('.sortable-item');
        var addedIds = {};
        items.forEach(function(item) {
            addedIds[item.getAttribute('data-task-id')] = true;
        });
        var options = selector.querySelectorAll('option');
        options.forEach(function(opt) {
            if (opt.value === '') return;
            if (addedIds[opt.value]) {
                opt.disabled = true;
            } else {
                opt.disabled = false;
            }
        });
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // Initial update
    updateOptions();
});
</script>
<?php
$content = ob_get_clean();
require BASE_PATH . '/templates/layout.php';
