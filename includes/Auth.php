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
        return $user['id'] ?? null;
    }

    public static function getUserName(): ?string
    {
        $user = AuthClient::check();
        return $user['display_name'] ?? null;
    }

    public static function login(string $login, string $password): array
    {
        $response = self::apiPost('/api/login.php', [
            'login' => $login,
            'password' => $password,
        ]);

        if ($response === null) {
            return ['success' => false, 'error' => 'Сервис авторизации недоступен'];
        }

        if (empty($response['success'])) {
            return ['success' => false, 'error' => $response['error'] ?? 'Ошибка входа'];
        }

        AuthClient::clearCache();
        return ['success' => true];
    }

    public static function logout(): void
    {
        AuthClient::clearCache();
        session_destroy();
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
        $db = Database::getInstance();
        return $db->query("SELECT id, login, display_name, is_admin, created_at FROM users ORDER BY login")->fetchAll();
    }

    public static function getUserById(int $id): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT id, login, display_name, is_admin, created_at FROM users WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function getUserByLogin(string $login): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT id, login, display_name, is_admin, created_at FROM users WHERE login = ?");
        $stmt->execute([$login]);
        return $stmt->fetch() ?: null;
    }

    public static function deleteUser(int $id): bool
    {
        if ($id == 1) {
            return false;
        }
        $db = Database::getInstance();
        $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    private static function apiPost(string $path, array $data): ?array
    {
        $url = 'https://auth.nayanovaacademy.ru' . $path;
        $json = json_encode($data);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_COOKIEFILE => '',
            CURLOPT_COOKIEJAR => '',
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        if ($response === false) return null;
        $decoded = json_decode($response, true);
        return is_array($decoded) ? $decoded : null;
    }
}
