<?php
/**
 * Класс для аутентификации — интеграция с auth-web
 */
class Auth
{
    public static function isLoggedIn(): bool
    {
        $user = AuthClient::check();
        return $user !== null;
    }

    public static function isAdmin(): bool
    {
        $user = AuthClient::check();
        return $user !== null && !empty($user['is_admin']);
    }

    public static function getUserId(): ?int
    {
        $user = AuthClient::check();
        if (!$user || empty($user['id'])) return null;
        return (int) $user['id'];
    }

    public static function getUserName(): ?string
    {
        $user = AuthClient::check();
        return $user['display_name'] ?? null;
    }

    public static function requireLogin(): void
    {
        if (!self::isLoggedIn()) {
            $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
            header('Location: ' . AuthClient::getLoginUrl(BASE_URL . '/index.php'));
            exit;
        }
    }

    public static function requireAdmin(): void
    {
        self::requireLogin();
        if (!self::isAdmin()) {
            header('Location: ' . BASE_URL . '/index.php');
            exit;
        }
    }

    public static function getAllUsers(): array
    {
        $users = AuthClient::getUsers();
        return is_array($users) ? $users : [];
    }

    public static function getUserById(int $id): ?array
    {
        foreach (self::getAllUsers() as $user) {
            if ((int) $user['id'] === $id) {
                return $user;
            }
        }
        return null;
    }

    public static function getUserByLogin(string $login): ?array
    {
        foreach (self::getAllUsers() as $user) {
            if ($user['login'] === $login) {
                return $user;
            }
        }
        return null;
    }
}
