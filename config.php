<?php
/**
 * Основная конфигурация сайта
 */
define('SITE_NAME', 'Контест');
define('BASE_URL', 'https://contest.nayanovaacademy.ru');
define('BASE_PATH', __DIR__);

// Путь к базе данных SQLite
define('DB_PATH', BASE_PATH . '/data/contest.db');

// Директория для временных файлов песочницы
define('SANDBOX_DIR', BASE_PATH . '/sandbox');

// Лимиты по умолчанию
define('DEFAULT_TIME_LIMIT', 2.0);    // секунд
define('DEFAULT_MEMORY_LIMIT', 128);   // МБ

// Максимальный размер вывода теста (байт)
define('MAX_OUTPUT_SIZE', 65536);

// Запрещённые модули Python
define('FORBIDDEN_MODULES', ['os', 'subprocess', 'sys', 'shutil', 'ctypes', 'signal', 'multiprocessing', 'threading', 'socket']);

// Отключаем вывод ошибок в браузер (на проде — логировать в файл)
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

// Часовой пояс: всё храним в UTC, отображаем в UTC+4 (Europe/Samara)
date_default_timezone_set('UTC');
define('DISPLAY_TIMEZONE', '+04:00');
define('DISPLAY_TIMEZONE_NAME', 'Europe/Samara');

/**
 * Конвертирует время из UTC в часовой пояс для отображения (UTC+4)
 * Принимает строку в формате 'Y-m-d H:i:s' или null, возвращает строку в русском формате: "22 июня 2024, 15:30:15"
 */
function toDisplayTime(?string $utcTime): ?string {
    if ($utcTime === null || $utcTime === '') return null;
    $dt = new DateTime($utcTime, new DateTimeZone('UTC'));
    $dt->setTimezone(new DateTimeZone(DISPLAY_TIMEZONE_NAME));
    $months = [
        1 => 'января', 2 => 'февраля', 3 => 'марта', 4 => 'апреля',
        5 => 'мая', 6 => 'июня', 7 => 'июля', 8 => 'августа',
        9 => 'сентября', 10 => 'октября', 11 => 'ноября', 12 => 'декабря'
    ];
    $day = (int)$dt->format('j');
    $month = $months[(int)$dt->format('n')];
    $year = $dt->format('Y');
    $time = $dt->format('H:i:s');
    return $day . ' ' . $month . ' ' . $year . ', ' . $time;
}

/**
 * Конвертирует время из UTC в машинный формат для полей ввода datetime-local
 * Принимает строку в формате 'Y-m-d H:i:s' или null, возвращает 'Y-m-d H:i:s'
 */
function toDisplayTimeInput(?string $utcTime): ?string {
    if ($utcTime === null || $utcTime === '') return null;
    $dt = new DateTime($utcTime, new DateTimeZone('UTC'));
    $dt->setTimezone(new DateTimeZone(DISPLAY_TIMEZONE_NAME));
    return $dt->format('Y-m-d H:i:s');
}

/**
 * Конвертирует время из часового пояса отображения (UTC+4) в UTC для хранения
 * Принимает строку в формате 'Y-m-d H:i:s' или null, возвращает строку в том же формате
 */
function toUtcTime(?string $displayTime): ?string {
    if ($displayTime === null || $displayTime === '') return null;
    $dt = new DateTime($displayTime, new DateTimeZone(DISPLAY_TIMEZONE_NAME));
    $dt->setTimezone(new DateTimeZone('UTC'));
    return $dt->format('Y-m-d H:i:s');
}

/**
 * Возвращает текущее время в UTC в формате 'Y-m-d H:i:s'
 */
function utcNow(): string {
    return gmdate('Y-m-d H:i:s');
}

// Стартуем сессию
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    session_start();
}

/**
 * Генерирует или возвращает CSRF-токен для текущей сессии
 */
function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Генерирует HTML-поле с CSRF-токеном для форм
 */
function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken()) . '">';
}

/**
 * Проверяет CSRF-токен из POST-запроса
 */
function validateCsrf(): bool {
    $token = $_POST['csrf_token'] ?? '';
    if (empty($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}
