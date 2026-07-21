<?php
/**
 * Класс для работы с SQLite
 */
class Database
{
    private static ?PDO $instance = null;

    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            // Ensure the data directory exists and is writable
            $dbDir = dirname(DB_PATH);
            if (!is_dir($dbDir)) {
                if (!mkdir($dbDir, 0755, true)) {
                    throw new RuntimeException(
                        "Не удалось создать директорию для базы данных: {$dbDir}. " .
                        "Проверьте права доступа."
                    );
                }
            }

            if (!is_writable($dbDir)) {
                throw new RuntimeException(
                    "Директория базы данных недоступна для записи: {$dbDir}. " .
                    "Установите права на запись для веб-сервера (например: chmod 775 {$dbDir} " .
                    "или chown -R www-data:www-data {$dbDir})."
                );
            }

            self::$instance = new PDO('sqlite:' . DB_PATH, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            self::$instance->exec('PRAGMA journal_mode=WAL');
            self::$instance->exec('PRAGMA foreign_keys=ON');
        }
        return self::$instance;
    }

    /**
     * Инициализация схемы БД — создаёт таблицы, если их нет
     */
    public static function initialize(): void
    {
        $db = self::getInstance();

        $db->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                login TEXT UNIQUE NOT NULL,
                display_name TEXT NOT NULL,
                password_hash TEXT NOT NULL,
                is_admin INTEGER NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT (datetime('now'))
            );

            CREATE TABLE IF NOT EXISTS groups (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT UNIQUE NOT NULL,
                description TEXT DEFAULT ''
            );

            CREATE TABLE IF NOT EXISTS user_groups (
                user_id INTEGER NOT NULL,
                group_id INTEGER NOT NULL,
                PRIMARY KEY (user_id, group_id),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE
            );

            CREATE TABLE IF NOT EXISTS task_groups (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                description TEXT DEFAULT ''
            );

            CREATE TABLE IF NOT EXISTS tasks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL,
                given TEXT NOT NULL DEFAULT '',
                input_format TEXT NOT NULL DEFAULT '',
                output_format TEXT NOT NULL DEFAULT '',
                time_limit REAL NOT NULL DEFAULT 2.0,
                memory_limit INTEGER NOT NULL DEFAULT 128,
                created_at DATETIME NOT NULL DEFAULT (datetime('now'))
            );

            CREATE TABLE IF NOT EXISTS task_to_groups (
                task_id INTEGER NOT NULL,
                task_group_id INTEGER NOT NULL,
                PRIMARY KEY (task_id, task_group_id),
                FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
                FOREIGN KEY (task_group_id) REFERENCES task_groups(id) ON DELETE CASCADE
            );

            CREATE TABLE IF NOT EXISTS tests (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                task_id INTEGER NOT NULL,
                test_number INTEGER NOT NULL,
                input TEXT NOT NULL DEFAULT '',
                expected_output TEXT NOT NULL DEFAULT '',
                is_public INTEGER NOT NULL DEFAULT 0,
                FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
            );

            CREATE TABLE IF NOT EXISTS contests (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL,
                description TEXT NOT NULL DEFAULT '',
                start_time DATETIME,
                end_time DATETIME,
                created_at DATETIME NOT NULL DEFAULT (datetime('now'))
            );

            CREATE TABLE IF NOT EXISTS contest_tasks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                contest_id INTEGER NOT NULL,
                task_id INTEGER NOT NULL,
                sort_order INTEGER NOT NULL DEFAULT 0,
                FOREIGN KEY (contest_id) REFERENCES contests(id) ON DELETE CASCADE,
                FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
            );

            CREATE TABLE IF NOT EXISTS contest_task_groups (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                contest_id INTEGER NOT NULL,
                task_group_id INTEGER NOT NULL,
                FOREIGN KEY (contest_id) REFERENCES contests(id) ON DELETE CASCADE,
                FOREIGN KEY (task_group_id) REFERENCES task_groups(id) ON DELETE CASCADE
            );

            CREATE TABLE IF NOT EXISTS contest_access (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                contest_id INTEGER NOT NULL,
                user_id INTEGER,
                group_id INTEGER,
                FOREIGN KEY (contest_id) REFERENCES contests(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE
            );

            CREATE TABLE IF NOT EXISTS submissions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                task_id INTEGER NOT NULL,
                contest_id INTEGER,
                code TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'pending',
                execution_time REAL DEFAULT 0,
                lint_errors TEXT DEFAULT NULL,
                executed_at DATETIME NOT NULL DEFAULT (datetime('now')),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
                FOREIGN KEY (contest_id) REFERENCES contests(id) ON DELETE SET NULL
            );

            CREATE TABLE IF NOT EXISTS submission_test_results (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                submission_id INTEGER NOT NULL,
                test_number INTEGER NOT NULL,
                status TEXT NOT NULL,
                execution_time REAL DEFAULT 0,
                memory_used INTEGER DEFAULT 0,
                output TEXT DEFAULT '',
                FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE
            );

            CREATE TABLE IF NOT EXISTS settings (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL
            );

            CREATE TABLE IF NOT EXISTS rate_limits (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL UNIQUE,
                timestamps TEXT NOT NULL DEFAULT '[]',
                updated_at DATETIME NOT NULL DEFAULT (datetime('now')),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            );
        ");

        // Создаём администратора по умолчанию, если пользователей нет
        $count = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
        if ($count == 0) {
            $hash = password_hash('admin', PASSWORD_BCRYPT);
            $stmt = $db->prepare("INSERT INTO users (login, display_name, password_hash, is_admin) VALUES (?, ?, ?, 1)");
            $stmt->execute(['admin', 'Администратор', $hash]);
        }

        // Автоматическое добавление недостающих колонок и индексов
        self::migrateSchema($db);
    }

    /**
     * Автоматическая миграция схемы: добавляет недостающие колонки и индексы
     */
    private static function migrateSchema(PDO $db): void
    {
        // Проверяем и добавляем недостающие колонки в submissions
        $columns = [];
        foreach ($db->query("PRAGMA table_info(submissions)")->fetchAll() as $col) {
            $columns[$col['name']] = true;
        }

        $migrations = [
            'execution_time' => 'ALTER TABLE submissions ADD COLUMN execution_time REAL DEFAULT 0',
            'lint_errors'    => 'ALTER TABLE submissions ADD COLUMN lint_errors TEXT DEFAULT NULL',
        ];

        foreach ($migrations as $col => $sql) {
            if (!isset($columns[$col])) {
                try {
                    $db->exec($sql);
                } catch (PDOException $e) {
                    // Колонка уже существует или другая ошибка — продолжаем
                }
            }
        }

        // Индексы для производительности
        $indexes = [
            'idx_submissions_user_id'      => 'CREATE INDEX IF NOT EXISTS idx_submissions_user_id ON submissions(user_id)',
            'idx_submissions_contest_id'   => 'CREATE INDEX IF NOT EXISTS idx_submissions_contest_id ON submissions(contest_id)',
            'idx_submissions_status'       => 'CREATE INDEX IF NOT EXISTS idx_submissions_status ON submissions(status)',
            'idx_contest_tasks_contest_id' => 'CREATE INDEX IF NOT EXISTS idx_contest_tasks_contest_id ON contest_tasks(contest_id)',
            'idx_tests_task_id'            => 'CREATE INDEX IF NOT EXISTS idx_tests_task_id ON tests(task_id)',
            'idx_contest_access_user'      => 'CREATE INDEX IF NOT EXISTS idx_contest_access_user ON contest_access(user_id)',
            'idx_contest_access_group'     => 'CREATE INDEX IF NOT EXISTS idx_contest_access_group ON contest_access(group_id)',
            'idx_user_groups_user'         => 'CREATE INDEX IF NOT EXISTS idx_user_groups_user ON user_groups(user_id)',
            'idx_task_to_groups_group'     => 'CREATE INDEX IF NOT EXISTS idx_task_to_groups_group ON task_to_groups(task_group_id)',
        ];

        foreach ($indexes as $sql) {
            try {
                $db->exec($sql);
            } catch (PDOException $e) {
                // Индекс уже существует — продолжаем
            }
        }
    }

    /**
     * Синхронизировать пользователей из auth-web
     */
    public static function syncUsers(): array
    {
        $db = self::getInstance();
        $authUrl = 'https://auth.nayanovaacademy.ru/api/admin_users.php';

        $cookieHeader = '';
        if (!empty($_COOKIE['auth_session'])) {
            $cookieHeader = 'auth_session=' . $_COOKIE['auth_session'];
        }

        $ch = curl_init($authUrl);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
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
            return ['success' => false, 'error' => 'Не удалось получить данные из auth-web (HTTP ' . $httpCode . ')'];
        }

        $data = json_decode($response, true);
        if (!is_array($data) || empty($data['users'])) {
            return ['success' => false, 'error' => 'Неверный ответ от auth-web'];
        }

        $remoteIds = [];
        $synced = 0;

        $stmtInsert = $db->prepare(
            "INSERT INTO users (id, login, display_name, is_admin, created_at)
             VALUES (?, ?, ?, ?, ?)
             ON CONFLICT(id) DO UPDATE SET
               login = excluded.login,
               display_name = excluded.display_name,
               is_admin = excluded.is_admin,
               created_at = excluded.created_at"
        );

        foreach ($data['users'] as $user) {
            $id = (int) $user['id'];
            $remoteIds[] = $id;
            $stmtInsert->execute([
                $id,
                $user['login'],
                $user['display_name'],
                (int) ($user['is_admin'] ?? 0),
                $user['created_at'] ?? gmdate('Y-m-d H:i:s'),
            ]);
            $synced++;
        }

        $deleted = 0;
        if (!empty($remoteIds)) {
            $placeholders = implode(',', array_fill(0, count($remoteIds), '?'));
            $stmtDelete = $db->prepare("DELETE FROM users WHERE id NOT IN ($placeholders)");
            $stmtDelete->execute($remoteIds);
            $deleted = $stmtDelete->rowCount();
        }

        return ['success' => true, 'synced' => $synced, 'deleted' => $deleted];
    }
}