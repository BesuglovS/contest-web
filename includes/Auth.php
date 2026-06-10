<?php
/**
 * Класс для аутентификации и управления сессиями
 */
class Auth
{
    /**
     * Проверка, авторизован ли пользователь
     */
    public static function isLoggedIn(): bool
    {
        return isset($_SESSION['user_id']);
    }

    /**
     * Проверка, администратор ли текущий пользователь
     */
    public static function isAdmin(): bool
    {
        return isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1;
    }

    /**
     * Получить ID текущего пользователя
     */
    public static function getUserId(): ?int
    {
        return $_SESSION['user_id'] ?? null;
    }

    /**
     * Получить имя текущего пользователя
     */
    public static function getUserName(): ?string
    {
        return $_SESSION['display_name'] ?? null;
    }

    /**
     * Попытка входа по логину и паролю
     */
    public static function login(string $login, string $password): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM users WHERE login = ?");
        $stmt->execute([$login]);
        $user = $stmt->fetch();

        if (!$user) {
            return ['success' => false, 'error' => 'Пользователь не найден'];
        }

        if (!password_verify($password, $user['password_hash'])) {
            return ['success' => false, 'error' => 'Неверный пароль'];
        }

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['login'] = $user['login'];
        $_SESSION['display_name'] = $user['display_name'];
        $_SESSION['is_admin'] = (int) $user['is_admin'];

        return ['success' => true];
    }

    /**
     * Выход из системы
     */
    public static function logout(): void
    {
        session_destroy();
    }

    /**
     * Требовать авторизацию, иначе редирект на страницу входа
     */
    public static function requireLogin(): void
    {
        if (!self::isLoggedIn()) {
            header('Location: ' . BASE_URL . '/index.php?page=login');
            exit;
        }
    }

    /**
     * Требовать права администратора
     */
    public static function requireAdmin(): void
    {
        self::requireLogin();
        if (!self::isAdmin()) {
            header('Location: ' . BASE_URL . '/index.php');
            exit;
        }
    }

    /**
     * Получить всех пользователей
     */
    public static function getAllUsers(): array
    {
        $db = Database::getInstance();
        return $db->query("SELECT id, login, display_name, is_admin, created_at FROM users ORDER BY login")->fetchAll();
    }

    /**
     * Получить пользователя по ID
     */
    public static function getUserById(int $id): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT id, login, display_name, is_admin, created_at FROM users WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Создать пользователя
     */
    public static function createUser(string $login, string $displayName, string $password, bool $isAdmin = false): array
    {
        $db = Database::getInstance();
        try {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $db->prepare("INSERT INTO users (login, display_name, password_hash, is_admin) VALUES (?, ?, ?, ?)");
            $stmt->execute([$login, $displayName, $hash, (int) $isAdmin]);
            return ['success' => true, 'id' => $db->lastInsertId()];
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'UNIQUE')) {
                return ['success' => false, 'error' => 'Пользователь с таким логином уже существует'];
            }
            return ['success' => false, 'error' => 'Ошибка базы данных'];
        }
    }

    /**
     * Обновить пользователя
     */
    public static function updateUser(int $id, string $login, string $displayName, bool $isAdmin, ?string $password = null): array
    {
        $db = Database::getInstance();
        try {
            if ($password !== null && $password !== '') {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $db->prepare("UPDATE users SET login = ?, display_name = ?, is_admin = ?, password_hash = ? WHERE id = ?");
                $stmt->execute([$login, $displayName, (int) $isAdmin, $hash, $id]);
            } else {
                $stmt = $db->prepare("UPDATE users SET login = ?, display_name = ?, is_admin = ? WHERE id = ?");
                $stmt->execute([$login, $displayName, (int) $isAdmin, $id]);
            }
            return ['success' => true];
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'UNIQUE')) {
                return ['success' => false, 'error' => 'Пользователь с таким логином уже существует'];
            }
            return ['success' => false, 'error' => 'Ошибка базы данных'];
        }
    }

    /**
     * Удалить пользователя
     */
    public static function deleteUser(int $id): bool
    {
        if ($id == 1) {
            return false; // Нельзя удалить первого администратора
        }
        $db = Database::getInstance();
        $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }
}