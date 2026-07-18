<?php
/**
 * Контест — точка входа
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/Autoloader.php';

// Регистрируем автозагрузчик
Autoloader::register();

// Инициализация базы данных при первом запуске
Database::initialize();

// Запуск роутера
$router = new Router();
$router->dispatch();