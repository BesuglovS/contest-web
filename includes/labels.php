<?php
/**
 * Единые метки статусов решений и результатов тестов.
 * Подключать через: require_once BASE_PATH . '/includes/labels.php';
 */

$statusLabels = [
    'pending'       => 'Ожидает',
    'lint_error'    => 'Ошибка оформления',
    'accepted'      => 'Принято',
    'wrong_answer'  => 'Неверный ответ',
    'runtime_error' => 'Ошибка выполнения',
    'time_limit'    => 'Превышен лимит времени',
    'memory_limit'  => 'Превышен лимит памяти',
];

$resultStatusLabels = [
    'accepted'      => 'Пройден',
    'wrong_answer'  => 'Неверный ответ',
    'runtime_error' => 'Ошибка выполнения',
    'time_limit'    => 'Превышен лимит времени',
    'memory_limit'  => 'Превышен лимит памяти',
    'pending'       => 'Ожидает',
];