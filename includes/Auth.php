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

    /**
     * Список всех групп (классов) из auth-web.
     */
    public static function getAllGroups(): array
    {
        $groups = AuthClient::getGroups();
        return is_array($groups) ? $groups : [];
    }

    /**
     * Принадлежность пользователей к группам из auth-web.
     * Возвращает список пар ['user_id' => N, 'group_id' => N].
     */
    public static function getAllMemberships(): array
    {
        $memberships = AuthClient::getMemberships();
        return is_array($memberships) ? $memberships : [];
    }

    /**
     * ID групп (классов), в которых состоит пользователь.
     */
    public static function getUserGroupIds(int $userId): array
    {
        $ids = [];
        foreach (self::getAllMemberships() as $m) {
            if ((int) $m['user_id'] === (int) $userId) {
                $ids[] = (int) $m['group_id'];
            }
        }
        sort($ids);
        return array_values(array_unique($ids));
    }

    /**
     * ID пользователей, состоящих хотя бы в одной из указанных групп.
     */
    public static function getGroupUsersByGroupIds(array $groupIds): array
    {
        if (!$groupIds) {
            return [];
        }
        $map = array_fill_keys(array_map('intval', $groupIds), true);
        $userIds = [];
        foreach (self::getAllMemberships() as $m) {
            if (isset($map[(int) $m['group_id']])) {
                $userIds[] = (int) $m['user_id'];
            }
        }
        return array_values(array_unique($userIds));
    }

    /**
     * SQL-плейсхолдеры для списка ID групп (например "?,?").
     * Если списка нет — "0", чтобы условие "IN (0)" не совпадало ни с чем.
     */
    public static function groupPlaceholders(array $groupIds): string
    {
        return $groupIds ? implode(',', array_fill(0, count($groupIds), '?')) : '0';
    }
}
