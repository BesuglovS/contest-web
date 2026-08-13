<?php
/**
 * AuthClient — единый клиент единой системы авторизации (auth-web).
 *
 * Единственный источник реализации SSO для всех PHP-проектов экосистемы
 * Nayanova Academy (contest-web, python-web, ai-web, j-web и т.д.).
 * Каноническая копия: shared/php/auth-client/AuthClient.php
 *
 * Проверяет авторизацию по общей куке auth_session на .nayanovaacademy.ru
 * через auth.nayanovaacademy.ru/api/*. Результат кэшируется в PHP-сессии.
 */

if (!function_exists('nayanova_auth_url')) {
    function nayanova_auth_url(): string
    {
        if (defined('AUTH_URL')) {
            return AUTH_URL;
        }
        return 'https://auth.nayanovaacademy.ru';
    }
}

if (!class_exists('AuthClient')) {
    class AuthClient
    {
        private static int $cacheTtl = 300; // 5 минут кэш в сессии

        /**
         * Проверить авторизацию через auth-web API.
         * @return array{id:int,login:string,display_name:string,is_admin:bool}|null
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

            $user = $response['user'] ?? null;
            if (!is_array($user)) {
                self::clearCache();
                return null;
            }

            self::setCachedUser($user);
            return $user;
        }

        /** Проверка авторизации как boolean */
        public static function isLoggedIn(): bool
        {
            return self::check() !== null;
        }

        /** Проверка прав администратора */
        public static function isAdmin(): bool
        {
            $user = self::check();
            return $user !== null && !empty($user['is_admin']);
        }

        /** id пользователя из auth-web (глобальный) или null */
        public static function getUserId(): ?int
        {
            $user = self::check();
            return isset($user['id']) ? (int)$user['id'] : null;
        }

        /** Имя пользователя для отображения или null */
        public static function getUserName(): ?string
        {
            $user = self::check();
            return isset($user['display_name']) ? (string)$user['display_name'] : null;
        }

        /**
         * Получить список всех пользователей из auth-web (без паролей).
         * Кэшируется в сессии на cacheTtl.
         */
        public static function getUsers(bool $force = false): ?array
        {
            $cached = self::getCached('users');
            if ($cached !== null && !$force) {
                return $cached;
            }

            $response = self::apiGet('/api/public_users.php');
            if ($response === null || !isset($response['users'])) {
                return null;
            }

            self::setCached('users', $response['users']);
            return $response['users'];
        }

        /**
         * Получить список всех групп (классов) из auth-web.
         * Кэшируется в сессии на cacheTtl.
         */
        public static function getGroups(bool $force = false): ?array
        {
            $cached = self::getCached('groups');
            if ($cached !== null && !$force) {
                return $cached;
            }

            $response = self::apiGet('/api/groups.php');
            if ($response === null || !isset($response['groups'])) {
                return null;
            }

            self::setCached('groups', $response['groups']);
            return $response['groups'];
        }

        /**
         * Получить принадлежность пользователей к группам из auth-web.
         * Возвращает список пар ['user_id' => N, 'group_id' => N].
         * Кэшируется в сессии на cacheTtl.
         */
        public static function getMemberships(bool $force = false): ?array
        {
            $cached = self::getCached('memberships');
            if ($cached !== null && !$force) {
                return $cached;
            }

            $response = self::apiGet('/api/user_groups.php');
            if ($response === null || !isset($response['memberships'])) {
                return null;
            }

            self::setCached('memberships', $response['memberships']);
            return $response['memberships'];
        }

        /**
         * Получить URL для входа с редиректом обратно
         */
        public static function getLoginUrl(string $returnUrl): string
        {
            return nayanova_auth_url() . '/index.php?page=login&redirect=' . urlencode($returnUrl);
        }

        /**
         * Получить URL для выхода
         */
        public static function getLogoutUrl(string $returnUrl): string
        {
            return nayanova_auth_url() . '/api/logout.php?redirect=' . urlencode($returnUrl);
        }

        /** Очистить кэш сессии */
        public static function clearCache(): void
        {
            foreach (['user', 'user_at', 'users', 'users_at', 'groups', 'groups_at', 'memberships', 'memberships_at', 'cookie_hash'] as $key) {
                unset($_SESSION['nayanova_auth_' . $key]);
            }
        }

        private static function apiGet(string $path): ?array
        {
            $url = nayanova_auth_url() . $path;

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
            if (empty($_SESSION['nayanova_auth_user'])) {
                return null;
            }
            $cachedAt = $_SESSION['nayanova_auth_user_at'] ?? 0;
            if (time() - $cachedAt > self::$cacheTtl) {
                return null;
            }
            if (($_SESSION['nayanova_auth_cookie_hash'] ?? '') !== self::getCookieHash()) {
                return null;
            }
            return $_SESSION['nayanova_auth_user'];
        }

        private static function setCachedUser(array $user): void
        {
            $_SESSION['nayanova_auth_user'] = $user;
            $_SESSION['nayanova_auth_user_at'] = time();
            $_SESSION['nayanova_auth_cookie_hash'] = self::getCookieHash();
        }

        private static function getCached(string $kind): ?array
        {
            if (empty($_SESSION['nayanova_auth_' . $kind])) {
                return null;
            }
            $cachedAt = $_SESSION['nayanova_auth_' . $kind . '_at'] ?? 0;
            if (time() - $cachedAt > self::$cacheTtl) {
                return null;
            }
            return $_SESSION['nayanova_auth_' . $kind];
        }

        private static function setCached(string $kind, array $value): void
        {
            $_SESSION['nayanova_auth_' . $kind] = $value;
            $_SESSION['nayanova_auth_' . $kind . '_at'] = time();
        }

        private static function getCookieHash(): string
        {
            return hash('sha256', $_COOKIE['auth_session'] ?? '');
        }
    }
}
