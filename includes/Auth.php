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
        $status = AuthClient::checkStatus();

        if ($status['status'] === 'unavailable') {
            // auth-web недоступен — это НЕ «не авторизован»: не выкидываем на логин,
            // а честно показываем 503 (кэш сессии сглаживает краткие сбои).
            http_response_code(503);
            header('Content-Type: text/html; charset=utf-8');
            header('Retry-After: 60');
            echo '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><title>Сервис временно недоступен</title></head>'
                . '<body style="font-family:sans-serif;max-width:32rem;margin:4rem auto;text-align:center">'
                . '<h1>503 — Сервис авторизации временно недоступен</h1>'
                . '<p>Попробуйте обновить страницу через минуту.</p>'
                . '<p><a href="' . htmlspecialchars(BASE_URL . '/index.php') . '">Обновить</a></p>'
                . '</body></html>';
            exit;
        }

        if ($status['user'] === null) {
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
