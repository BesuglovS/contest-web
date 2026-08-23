<?php
// Защита от прямого доступа к файлу — только через фронт-контроллер (index.php)
if (!defined('BASE_PATH')) {
    http_response_code(403);
    exit('Forbidden');
}

// Решать задачи можно только в рамках контеста
header('Location: ?page=contests');
exit;
