<?php
/**
 * Контест — точка входа
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/Auth.php';
require_once __DIR__ . '/includes/Router.php';

// Инициализация базы данных при первом запуске
Database::initialize();

// Запуск роутера
$router = new Router();
$router->dispatch();