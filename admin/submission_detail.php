<?php
$pageTitle = 'Просмотр решения';
$db = Database::getInstance();

$submissionId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$submissionId) {
    header('Location: ' . BASE_URL . '/index.php?page=admin-submissions');
    exit;
}

// Получаем данные посылки
$stmt = $db->prepare("
    SELECT s.*, u.login, u.display_name, t.title as task_title, c.title as contest_title
    FROM submissions s
    INNER JOIN users u ON s.user_id = u.id
    INNER JOIN tasks t ON s.task_id = t.id
    LEFT JOIN contests c ON s.contest_id = c.id
    WHERE s.id = ?
");
$stmt->execute([$submissionId]);
$submission = $stmt->fetch();

if (!$submission) {
    header('Location: ' . BASE_URL . '/index.php?page=admin-submissions');
    exit;
}

// Получаем результаты тестов
$stmt = $db->prepare("SELECT * FROM submission_test_results WHERE submission_id = ? ORDER BY test_number");
$stmt->execute([$submissionId]);
$testResults = $stmt->fetchAll() ?: [];

// Получаем тесты задачи (чтобы знать public/private и ожидаемый вывод)
$stmt = $db->prepare("SELECT * FROM tests WHERE task_id = ? ORDER BY test_number");
$stmt->execute([$submission['task_id']]);
$taskTests = $stmt->fetchAll() ?: [];

// Индексируем тесты задачи по test_number
$taskTestsByNumber = [];
foreach ($taskTests as $tt) {
    $taskTestsByNumber[$tt['test_number']] = $tt;
}

// Предыдущее и следующее решение этой задачи этим учеником
$prevSubmission = $db->prepare("SELECT id FROM submissions WHERE user_id = ? AND task_id = ? AND id < ? ORDER BY id DESC LIMIT 1");
$prevSubmission->execute([$submission['user_id'], $submission['task_id'], $submissionId]);
$prevSubmission = $prevSubmission->fetch();

$nextSubmission = $db->prepare("SELECT id FROM submissions WHERE user_id = ? AND task_id = ? AND id > ? ORDER BY id ASC LIMIT 1");
$nextSubmission->execute([$submission['user_id'], $submission['task_id'], $submissionId]);
$nextSubmission = $nextSubmission->fetch();

require_once BASE_PATH . '/includes/labels.php';

$resultStatusLabels = [
    'accepted' => 'Пройден',
    'wrong_answer' => 'Неверный ответ',
    'runtime_error' => 'Ошибка выполнения',
    'time_limit' => 'Превышен лимит времени',
    'memory_limit' => 'Превышен лимит памяти',
    'pending' => 'Ожидает',
];

ob_start();
?>

<h1>Просмотр решения #<?= $submission['id'] ?></h1>

<?php $activePage = 'submissions'; require BASE_PATH . '/templates/admin_nav.php'; ?>

<div class="card">
    <h2>Информация о решении</h2>
    <table>
        <tbody>
            <tr>
                <th style="width:200px;">ID</th>
                <td><?= $submission['id'] ?></td>
            </tr>
            <tr>
                <th>Пользователь</th>
                <td>
                    <?= htmlspecialchars($submission['display_name']) ?> (<?= htmlspecialchars($submission['login']) ?>)
                    <a href="?page=admin-submissions&user_id=<?= $submission['user_id'] ?>" title="Все решения этого пользователя" style="text-decoration:none; margin-left:6px;">🔍</a>
                </td>
            </tr>
            <tr>
                <th>Задача</th>
                <td>
                    <?= htmlspecialchars($submission['task_title']) ?> (ID: <?= $submission['task_id'] ?>)
                    <a href="?page=admin-submissions&task_id=<?= $submission['task_id'] ?>" title="Все решения этой задачи" style="text-decoration:none; margin-left:6px;">🔍</a>
                </td>
            </tr>
            <tr>
                <th>Контест</th>
                <td>
                    <?= $submission['contest_title'] ? htmlspecialchars($submission['contest_title']) : '<em>Не указан</em>' ?>
                    <?php if ($submission['contest_id']): ?>
                    <a href="?page=admin-submissions&contest_id=<?= $submission['contest_id'] ?>" title="Все решения этого контеста" style="text-decoration:none; margin-left:6px;">🔍</a>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th>Статус</th>
                <td>
                    <span class="submission-status status-<?= $submission['status'] ?>">
                        <?= $statusLabels[$submission['status']] ?? $submission['status'] ?>
                    </span>
                </td>
            </tr>
            <tr>
                <th>Время выполнения</th>
                <td><?= number_format((float)($submission['execution_time'] ?? 0), 3) ?> сек</td>
            </tr>
            <tr>
                <th>Дата отправки</th>
                <td><?= htmlspecialchars(toDisplayTime($submission['executed_at'] ?? '')) ?></td>
            </tr>
        </tbody>
    </table>
</div>

<?php if ($prevSubmission || $nextSubmission): ?>
<div style="display:flex; justify-content:space-between; align-items:center; margin: 12px 0;">
    <?php if ($prevSubmission): ?>
        <a href="?page=admin-submission-detail&id=<?= $prevSubmission['id'] ?>" class="btn btn-small">&larr; Предыдущее решение</a>
    <?php else: ?>
        <span></span>
    <?php endif; ?>
    <?php if ($nextSubmission): ?>
        <a href="?page=admin-submission-detail&id=<?= $nextSubmission['id'] ?>" class="btn btn-small">Следующее решение &rarr;</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
    <h2>Отправленный код</h2>
    <pre class="code-block"><code><?= htmlspecialchars($submission['code']) ?></code></pre>
</div>

<?php
$lintErrors = [];
if (!empty($submission['lint_errors'])) {
    $decoded = json_decode($submission['lint_errors'], true);
    $lintErrors = is_array($decoded) ? $decoded : [['line' => 0, 'column' => 0, 'code' => '', 'message' => $submission['lint_errors']]];
}
?>
<?php if (!empty($lintErrors)): ?>
<div class="card">
    <h2>Ошибки линтинга (PEP8)</h2>
    <table>
        <thead>
            <tr>
                <th>Строка</th>
                <th>Столбец</th>
                <th>Код</th>
                <th>Описание</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($lintErrors as $err): ?>
            <tr>
                <td><?= (int)($err['line'] ?? 0) ?></td>
                <td><?= (int)($err['column'] ?? 0) ?></td>
                <td><code><?= htmlspecialchars($err['code'] ?? '') ?></code></td>
                <td><?= htmlspecialchars($err['message'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<div class="card">
    <h2>Результаты тестов (<?= count($testResults) ?>)</h2>

    <?php if (empty($testResults)): ?>
        <?php if ($submission['status'] === 'lint_error'): ?>
            <p style="color:var(--text-muted);">Тесты не выполнялись из-за ошибок на этапе проверки оформления кода (PEP8).</p>
        <?php else: ?>
            <p style="color:var(--text-muted);">Результаты тестов отсутствуют. Возможно, решение ещё не проверено.</p>
        <?php endif; ?>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Статус</th>
                    <th>Время (сек)</th>
                    <th>Память (байт)</th>
                    <th>Входные данные</th>
                    <th>Вывод</th>
                    <th>Ожидаемый вывод</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($testResults as $tr): ?>
                <?php
                    $taskTest = $taskTestsByNumber[$tr['test_number']] ?? null;
                    $isPublic = $taskTest ? (bool)$taskTest['is_public'] : true;
                ?>
                <tr>
                    <td><?= $tr['test_number'] ?></td>
                    <td>
                        <span class="submission-status status-<?= $tr['status'] ?>">
                            <?= $resultStatusLabels[$tr['status']] ?? $tr['status'] ?>
                        </span>
                        <?php if (!$isPublic): ?>
                            <span style="font-size:0.8em; color:var(--text-muted);">(скрытый)</span>
                        <?php endif; ?>
                    </td>
                    <td><?= number_format((float)($tr['execution_time'] ?? 0), 3) ?></td>
                    <td><?= number_format((int)($tr['memory_used'] ?? 0)) ?></td>
                    <td style="max-width:300px;">
                        <?php if ($taskTest): ?>
                            <pre class="test-output"><code><?= htmlspecialchars(mb_substr($taskTest['input'] ?? '', 0, 500)) ?></code></pre>
                            <?php if (mb_strlen($taskTest['input'] ?? '') > 500): ?>
                                <span style="color:var(--text-muted); font-size:0.85em;">... (показано 500 из <?= mb_strlen($taskTest['input']) ?> символов)</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <em style="color:var(--text-muted);">Нет данных</em>
                        <?php endif; ?>
                    </td>
                    <td style="max-width:300px;">
                        <pre class="test-output"><code><?= htmlspecialchars(mb_substr($tr['output'] ?? '', 0, 500)) ?></code></pre>
                        <?php if (mb_strlen($tr['output'] ?? '') > 500): ?>
                            <span style="color:var(--text-muted); font-size:0.85em;">... (показано 500 из <?= mb_strlen($tr['output']) ?> символов)</span>
                        <?php endif; ?>
                    </td>
                    <td style="max-width:300px;">
                        <?php if ($taskTest): ?>
                            <pre class="test-output"><code><?= htmlspecialchars(mb_substr($taskTest['expected_output'] ?? '', 0, 500)) ?></code></pre>
                            <?php if (mb_strlen($taskTest['expected_output'] ?? '') > 500): ?>
                                <span style="color:var(--text-muted); font-size:0.85em;">... (показано 500 из <?= mb_strlen($taskTest['expected_output']) ?> символов)</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <em style="color:var(--text-muted);">Нет данных</em>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<style>
.code-block {
    background: #1e1e1e;
    color: #d4d4d4;
    padding: 16px;
    border-radius: 6px;
    overflow-x: auto;
    font-size: 13px;
    line-height: 1.5;
    max-height: 600px;
    overflow-y: auto;
}
.code-block code {
    font-family: 'Consolas', 'Courier New', monospace;
    white-space: pre;
}
.test-output {
    background: #f5f5f5;
    color: #000;
    padding: 8px;
    border-radius: 4px;
    font-size: 12px;
    max-height: 150px;
    overflow-y: auto;
    margin: 0;
}
.test-output code {
    font-family: 'Consolas', 'Courier New', monospace;
    white-space: pre-wrap;
    word-break: break-all;
}
</style>

<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/editor.css">
<script src="<?= BASE_URL ?>/assets/js/editor.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var codeBlock = document.querySelector('.code-block code');
    if (codeBlock && typeof SyntaxHighlight !== 'undefined') {
        codeBlock.innerHTML = SyntaxHighlight.highlight(codeBlock.textContent);
    }
});
</script>

<?php
$content = ob_get_clean();
require BASE_PATH . '/templates/layout.php';