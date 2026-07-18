<?php

/**
 * Простой файловый кэш с TTL.
 * Использует оптимистичное кэширование для частых запросов.
 */
class Cache
{
    private const TTL = 300; // 5 минут
    private static ?string $cacheDir = null;

    /**
     * Возвращает путь к директории кэша.
     */
    private static function getCacheDir(): string
    {
        if (self::$cacheDir === null) {
            self::$cacheDir = BASE_PATH . '/data/.cache';
            if (!is_dir(self::$cacheDir)) {
                mkdir(self::$cacheDir, 0755, true);
            }
        }
        return self::$cacheDir;
    }

    /**
     * Возвращает закэшированное значение или генерирует новое.
     *
     * @param string $key Ключ кэша
     * @param callable $generator Функция-генератор значения
     * @param int|null $ttl Время жизни в секундах (null = значение по умолчанию)
     * @return string Закэшированное или сгенерированное значение
     */
    public static function get(string $key, callable $generator, ?int $ttl = null): string
    {
        $ttl = $ttl ?? self::TTL;
        $cacheFile = self::getCacheDir() . '/' . md5($key) . '.cache';

        if (file_exists($cacheFile)) {
            $age = time() - filemtime($cacheFile);
            if ($age < $ttl) {
                return file_get_contents($cacheFile);
            }
        }

        $result = $generator();

        if (!is_dir(self::getCacheDir())) {
            mkdir(self::getCacheDir(), 0755, true);
        }

        file_put_contents($cacheFile, $result, LOCK_EX);

        return $result;
    }

    /**
     * Инвалидирует (удаляет) кэш по ключу.
     */
    public static function invalidate(string $key): void
    {
        $cacheFile = self::getCacheDir() . '/' . md5($key) . '.cache';
        if (file_exists($cacheFile)) {
            unlink($cacheFile);
        }
    }

    /**
     * Очищает весь кэш.
     */
    public static function clear(): void
    {
        $dir = self::getCacheDir();
        $files = glob($dir . '/*.cache');
        if ($files) {
            foreach ($files as $file) {
                unlink($file);
            }
        }
    }
}