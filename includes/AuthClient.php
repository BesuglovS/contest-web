<?php
/**
 * Клиент для auth-web API
 * Проверяет авторизацию через централизованную систему auth.nayanovaacademy.ru
 */
class AuthClient
{
    private static string $authUrl = 'https://auth.nayanovaacademy.ru';
    private static int $cacheTtl = 300; // 5 минут кэш в сессии

    /**
     * Проверить авторизацию через auth-web API
     */
    public static function check(): ?array
    {
        $cached = self::getCachedUser();
        if ($cached !== null) {
            return $cached;
        }

        $response = self::apiGet('/api/check.php');
        if ($response === null || empty($response['authenticated'])) {
            self::clearCache();
            return null;
        }

        self::setCachedUser($response['user']);
        return $response['user'];
    }

    /**
     * Получить URL для входа с редиректом обратно
     */
    public static function getLoginUrl(string $returnUrl): string
    {
        return self::$authUrl . '/index.php?page=login&redirect=' . urlencode($returnUrl);
    }

    /**
     * Получить URL для выхода
     */
    public static function getLogoutUrl(string $returnUrl): string
    {
        return self::$authUrl . '/api/logout.php?redirect=' . urlencode($returnUrl);
    }

    private static function apiGet(string $path): ?array
    {
        $url = self::$authUrl . $path;

        $cookieHeader = '';
        if (!empty($_COOKIE['auth_session'])) {
            $cookieHeader = 'auth_session=' . $_COOKIE['auth_session'];
        }

        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => false,
        ];
        if ($cookieHeader !== '') {
            $opts[CURLOPT_COOKIE] = $cookieHeader;
        }
        curl_setopt_array($ch, $opts);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            return null;
        }

        $data = json_decode($response, true);
        return is_array($data) ? $data : null;
    }

    private static function getCachedUser(): ?array
    {
        if (empty($_SESSION['auth_user'])) {
            return null;
        }
        $cachedAt = $_SESSION['auth_user_cached_at'] ?? 0;
        if (time() - $cachedAt > self::$cacheTtl) {
            return null;
        }
        return $_SESSION['auth_user'];
    }

    private static function setCachedUser(array $user): void
    {
        $_SESSION['auth_user'] = $user;
        $_SESSION['auth_user_cached_at'] = time();
    }

    public static function clearCache(): void
    {
        unset($_SESSION['auth_user'], $_SESSION['auth_user_cached_at']);
    }
}
