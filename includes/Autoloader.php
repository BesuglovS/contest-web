<?php

/**
 * Простой PSR-4-совместимый автозагрузчик.
 * Регистрирует автозагрузку классов из директорий includes/ и includes/DTO/.
 */
class Autoloader
{
    private static array $paths = [];

    /**
     * Регистрирует автозагрузчик.
     */
    public static function register(): void
    {
        self::$paths = [
            __DIR__ . '/',
            __DIR__ . '/DTO/',
        ];

        spl_autoload_register([self::class, 'load']);
    }

    /**
     * Загружает файл класса.
     */
    public static function load(string $class): void
    {
        foreach (self::$paths as $path) {
            $file = $path . $class . '.php';
            if (file_exists($file)) {
                require_once $file;
                return;
            }
        }
    }
}