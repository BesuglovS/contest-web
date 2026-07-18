# Анализ проекта contest-web — возможности для улучшения

Дата анализа: 18 июля 2026  
Дата верификации: 18 июля 2026

## 1. Архитектура и структура проекта

### ✅ Сильные стороны
- Чёткое разделение на слои: `admin/`, `user/`, `api/`, `includes/`, `templates/`
- Использование паттерна Connection Registry (Singleton для PDO) в `Database`
- Централизованная конфигурация через `config.php`
- CSRF-защита в формах

### ⚠️ Зоны для улучшения

#### 1.1. Паттерн Connection Registry (Singleton для PDO)
```php
class Database {
    private static ?PDO $instance = null;   // ← не Database, а PDO
    // ...
    public static function getInstance(): PDO { ... }
}
```
**Пояснение:** Класс `Database` — это не Singleton самого себя, а держатель единственного PDO-подключения (Connection Registry). Для SQLite с WAL-режимом это оправдано: повторное использование одного подключения даёт лучшую конкурентность, чем создание новых. Индексы также создаются автоматически в `migrateSchema()`.

**Проблема:** Статический вызов `Database::getInstance()` затрудняет unit-тестирование (нельзя подменить подключение).

**Решение (опционально):** Внедрение зависимостей (DI) через конструктор там, где нужна тестируемость. Для продакшена текущий подход приемлем.

#### 1.2. Маппинг результатов в `TestingEngine.php`
```php
$testResult = [
    'test_number' => (int)$test['test_number'],
    'is_public' => (bool)$test['is_public'],
    'status' => '',
    'input' => $test['input'],
    'expected' => $test['expected_output'],
    'output' => $runResult['output'] ?? '',
    'error' => $cleanTraceback($runResult['error'] ?? ''),
    // ...
];
```

**Пояснение:** Поля `input` и `expected` необходимы: они показываются администратору/автору задачи для отладки скрытых тестов. Это не лишние данные, а функциональное требование.

**Проблема:** Результат в виде ассоциативного массива не типизирован — IDE не подсказывает поля, легко ошибиться в ключах.

**Решение (опционально):** Выделить DTO-класс с сохранением всех полей:

```php
readonly class TestResult {
    public function __construct(
        public int $number,
        public bool $isPublic,
        public string $status,
        public string $output,
        public string $error,
        public float $time,
        public int $memory,
        public string $input,
        public string $expected,
    ) {}
}
```

#### 1.3. Rate Limiting
```php
// Было (JSON-файлы):
$rateLimitDir = BASE_PATH . '/data/.ratelimit';
$rateLimitFile = $rateLimitDir . '/submit_' . $userId . '.json';
```

**Статус:** ✅ **ИСПРАВЛЕНО.** Rate limiting перенесён в БД. Таблица `rate_limits` создаётся в `Database::initialize()` (не в `migrateSchema()` — уточнено при верификации). В `submit.php` используется UPSERT с фильтрацией timestamps за последние 60 секунд.

**Текущая реализация:**
```php
// Таблица:
CREATE TABLE IF NOT EXISTS rate_limits (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL UNIQUE,
    timestamps TEXT NOT NULL DEFAULT '[]',
    updated_at DATETIME NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

// В submit.php — UPSERT:
INSERT INTO rate_limits (user_id, timestamps, updated_at) VALUES (?, ?, datetime('now'))
  ON CONFLICT(user_id) DO UPDATE SET timestamps = excluded.timestamps, updated_at = datetime('now')
```

#### 1.4. Санитизация и фильтрация
```php
function sanitizeInput(string $input): string {
    return htmlspecialchars(trim($input), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function filterString(string $input): string {
    $cleaned = preg_replace('/[\x{2000}-\x{206F}\x{3000}]/u', '', $input);
    return sanitizeInput($cleaned);
}
```

**Статус:** ✅ **ЧАСТИЧНО ИСПРАВЛЕНО.** В `config.php` добавлена функция `sanitizeString()`:
```php
function sanitizeString(?string $value): string {
    if ($value === null) return '';
    $value = str_replace("\0", '', $value);          // удаление NULL-байтов
    if (!mb_check_encoding($value, 'UTF-8')) {
        $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    }
    return $value;
}
```

**Оставшаяся задача:** Применить `sanitizeString()` ко всем входящим данным в формах и API. `filterString()` с `preg_replace` всё ещё используется — можно заменить на `mb_convert_encoding` там, где нужна фильтрация спецсимволов.

#### 1.5. Очистка Traceback
```php
$cleanTraceback = function (string $error): string {
    $lines = explode("\n", $error);
    $filtered = [];
    foreach ($lines as $line) {
        if (strpos($line, 'Traceback (most recent call last)') !== false) {
            continue;
        }
        $line = preg_replace('/^\s*File\s+"[^"]*",\s*/', '', $line);
        $filtered[] = $line;
    }
    return trim(implode("\n", $filtered));
};
```

**Проблема:** Регулярное выражение в цикле — медленно.

**Решение:** Оптимизируй, используя `str_contains` и строковые операции.

```php
$cleanTraceback = function (string $error): string {
    $lines = explode("\n", $error);
    return array_map(function ($line) {
        if (!str_contains($line, 'Traceback (most recent call last)')) {
            return preg_replace('/^\s*File\s+"[^"]*",\s*/', '', $line);
        }
        return '';
    }, $lines);
};
```

#### 1.6. Обработка ошибок в `submit.php`
```php
catch (Throwable $e) {
    // Очищаем буфер от возможного мусора
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    ob_start();
    // ...
}
```

**Проблема:** Обработка ошибок в catch блоке — это сложная логика.

**Решение:** Используй try-catch на уровне каждого действия.

```php
try {
    $db = Database::getInstance();
    // ...
} catch (PDOException $e) {
    handleDatabaseError($e);
}
```

---

## 2. Безопасность

### ✅ Сильные стороны
- CSRF-защита через `csrfToken()`
- Проверка авторизации
- Rate limiting

### ⚠️ Зоны для улучшения

#### 2.1. CSRF-токены
```php
function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCsrf(): bool {
    $token = $_POST['csrf_token'] ?? '';
    if (empty($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }
    $valid = hash_equals($_SESSION['csrf_token'], $token);
    if ($valid) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $valid;
}
```

**Статус:** ✅ **ИСПРАВЛЕНО.** Токен обновляется в `validateCsrf()` после каждой успешной проверки — защита от повторной отправки формы работает. В генераторе `csrfToken()` токен не обновляется, что корректно для multi-tab.

#### 2.2. Проверка доступа к задаче
```php
$stmt = $db->prepare("SELECT 1 FROM tasks t
    JOIN contest_tasks ct ON t.id = ct.task_id
    JOIN contest_access ca ON ct.contest_id = ca.contest_id
    LEFT JOIN user_groups ug ON ug.user_id = ? AND ca.group_id = ug.group_id
    WHERE t.id = ? AND ct.contest_id = ? AND (ca.user_id = ? OR ug.group_id IS NOT NULL)
    LIMIT 1");
```

**Пояснение:** Необходимые индексы уже созданы в `Database::migrateSchema()` (строки 215–225: `idx_contest_tasks_contest_id`, `idx_contest_access_user`, `idx_contest_access_group`, `idx_user_groups_user`, `idx_submissions_*`). Для SQLite на типовых объёмах данных JOIN-запросы не являются узким местом.

**Мелкое улучшение:** Заменить `JOIN` → `INNER JOIN` для ясности (уже сделано в рекомендации ниже). Можно также добавить составной индекс `contest_tasks(task_id, contest_id)`, но текущих индексов достаточно.

#### 2.3. Авторизация
```php
function isLoggedIn(): bool {
    return isset($_SESSION['user_id']);
}

function getUserId(): ?int {
    return $_SESSION['user_id'] ?? null;
}
```

**Пояснение:** `isset()` возвращает `false` для `null`-значений, поэтому дополнительная проверка `!== null` избыточна. Текущий код корректен.

**Рекомендация:** Добавить функцию `isAdmin()` — при верификации подтверждено, что она **отсутствует** в `Auth.php`:

```php
function isAdmin(): bool {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
}
```

---

## 3. Производительность

### ✅ Сильные стороны
- Кэширование контента через HTTP-заголовки
- Минификация CSS/JS

### ⚠️ Зоны для улучшения

#### 3.1. Загрузка контента
```php
// В index.php
require_once BASE_PATH . '/includes/Database.php';
require_once BASE_PATH . '/includes/Auth.php';
// ...
```

**Проблема:** Каждый раз требуются файлы, что замедляет загрузку.

**Решение:** Используй автозагрузчик и кэширование.

```php
// Composer autoloader:
require_once __DIR__ . '/../vendor/autoload.php';

use App\Database;
use App\Auth;

$db = Database::getInstance();
```

#### 3.2. База данных
```php
$stats = [
    'users' => $db->query("SELECT COUNT(*) FROM users")->fetchColumn(),
    'groups' => $db->query("SELECT COUNT(*) FROM groups")->fetchColumn(),
    // ...
];
```

**Статус:** ✅ **ИСПРАВЛЕНО.** Статистика в `admin/index.php` уже собирается одним запросом с подзапросами:
```php
$stmt = $db->query("
    SELECT 
        (SELECT COUNT(*) FROM users) as users,
        (SELECT COUNT(*) FROM groups) as groups,
        (SELECT COUNT(*) FROM tasks) as tasks,
        (SELECT COUNT(*) FROM task_groups) as task_groups,
        (SELECT COUNT(*) FROM contests) as contests,
        (SELECT COUNT(*) FROM submissions) as submissions
");
$stats = $stmt->fetch();
```

#### 3.3. Кэширование
```php
// Нет кэширования
```

**Проблема:** Нет кэширования для частых запросов.

**Решение:** Используй оптимистичное кэширование.

```php
class Cache {
    private const TTL = 300; // 5 минут
    
    public static function get(string $key, callable $generator): string {
        $key = 'cache:' . $key;
        $cached = cacheGet($key);
        if ($cached) {
            return $cached;
        }
        $result = $generator();
        cacheSet($key, $result, self::TTL);
        return $result;
    }
}
```

---

## 4. Код и стили

### ✅ Сильные стороны
- Чистый код с комментариями
- Использование PDO
- Централизованная конфигурация

### ⚠️ Зоны для улучшения

#### 4.1. Стилизация
```css
/* В style.css */
.site-header {
    padding: 1rem;
    background: var(--primary);
}
```

**Проблема:** CSS-стили могут быть избыточными.

**Решение:** Используй CSS-переменные и модульные стили.

```css
:root {
    --header-height: 60px;
    --primary-color: #2c5aa0;
}

.site-header {
    padding: calc(var(--header-height) / 2) 1rem;
    background: var(--primary-color);
}
```

#### 4.2. HTML-семантика
```html
<main>
    <h1>...</h1>
    <p>...</p>
</main>
```

**Проблема:** Нет семантических тегов.

**Решение:** Используй семантические теги.

```html
<main>
    <article>
        <header>
            <h1>...</h1>
        </header>
        <section>
            <h2>...</h2>
            <p>...</p>
        </section>
        <footer>
            <p>...</p>
        </footer>
    </article>
</main>
```

#### 4.3. Адаптивный дизайн
```css
@media (max-width: 768px) {
    .container {
        padding: 0.5rem;
    }
}
```

**Проблема:** Не все медиа-запросы оптимизированы.

**Решение:** Используй контейнеры и flexbox.

```css
.container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 1rem;
}

@media (max-width: 768px) {
    .container {
        padding: 0.5rem;
    }
    
    .grid {
        grid-template-columns: 1fr;
    }
}
```

---

## 5. Доступность (a11y)

### ✅ Сильные стороны
- Skip link для навигации
- aria-label для кнопок
- Контрастные цвета

### ⚠️ Зоны для улучшения

#### 5.1. Альтернативный текст
```html
<img src="logo.png" alt="Логотип">
```

**Проблема:** Изображения без alt-атрибута.

**Решение:**
```html
<img src="logo.png" alt="Логотип НА">
<img src="avatar.jpg" alt="<?= htmlspecialchars(Auth::getUserName()) ?>">
```

#### 5.2. Фокус-стили
```css
:focus-visible {
    outline: 3px solid var(--accent);
}
```

**Проблема:** Фокус-стили не везде определены.

**Решение:** Добавь фокус-стили ко всем интерактивным элементам.

#### 5.3. Семантическая структура
```html
<nav>
    <ul>...</ul>
</nav>
```

**Проблема:** Навигация без семантической структуры.

**Решение:**
```html
<nav aria-label="Основная навигация">
    <ul role="list">
        <li><a href="/">Главная</a></li>
        <li><a href="/tasks">Задачи</a></li>
        <li><a href="/contests">Контесты</a></li>
    </ul>
</nav>
```

---

## 6. SEO

### ✅ Сильные стороны
- Meta-теги
- Open Graph
- Sitemap

### ⚠️ Зоны для улучшения

#### 6.1. Микроразметка
```html
<!-- Нет микроразметки -->
```

**Проблема:** Нет Schema.org микроразметки.

**Решение:** Добавь Schema.org.

```html
<script type="application/ld+json">
{
    "@context": "https://schema.org",
    "@type": "WebSite",
    "name": "Конкурс по программированию",
    "url": "https://contest.example.com",
    "potentialAction": {
        "@type": "SearchAction",
        "target": "{search_url}?q={search_term_string}",
        "query-input": "required name=search_term_string"
    }
}
</script>
```

#### 6.2. Canonical URL
```html
<link rel="canonical" href="https://contest.example.com/">
```

**Проблема:** Canonical URL не везде определён.

**Решение:** Добавь canonical URL ко всем страницам.

---

## 7. Выводы и приоритеты

### 🔴 Критические (высокий приоритет)
1. ~~Оптимизация rate limiting~~ ✅ **ИСПРАВЛЕНО** — перенесён в БД (SQLite UPSERT)
2. Кэширование контента через оптимистичное кэширование
3. ~~Обновление CSRF-токенов после каждого POST~~ ✅ **ИСПРАВЛЕНО** — токены ротируются в `validateCsrf()`

### 🟠 Важные (средний приоритет)
1. ~~Оптимизация SQL-запросов (статистика)~~ ✅ **ИСПРАВЛЕНО** — stats одним запросом с подзапросами
2. DTO-класс для результатов тестов (вместо ассоциативного массива)
3. Применение sanitizeString() ко всем входящим данным
4. Обновление focus-стилей для a11y

### 🟢 Желательные (низкий приоритет)
1. Автозагрузчик (Composer)
2. Микроразметка Schema.org
3. Улучшение доступности (alt-атрибуты, focus-стили)
4. Модульная структура CSS

---

## 8. План действий

1. **Неделя 1:** Критические улучшения (rate limiting, кэширование, CSRF)
2. **Неделя 2:** Оптимизация базы данных (индексы, запросы)
3. **Неделя 3:** Упрощение кода (маппинг результатов, санитизация)
4. **Неделя 4:** SEO и доступность (микроразметка, alt-атрибуты)
5. **Неделя 5:** Модульная структура (CSS, JS)

---

## 9. Резюме

Проект `contest-web` имеет хорошую архитектурную основу, но требует:
- Оптимизации производительности
- Улучшения безопасности
- Модернизации кода
- Добавления SEO-метаданных
- PWA-файлов (отсутствуют manifest.json, sw.js, robots.txt, sitemap.xml)

Рекомендуется начать с критических улучшений, затем перейти к оптимизации производительности и в конце — к улучшению UX и SEO.

---

## ✅ Результаты верификации (18.07.2026)

| Утверждение | Статус |
|-------------|--------|
| Структура директорий (admin/, user/, api/, includes/, templates/, sandbox/, tasks/, docs/, assets/) | ✅ Подтверждено — все 9 директорий существуют и содержат файлы |
| Database.php: класс `Database`, `$instance`, `getInstance()`, `migrateSchema()` | ✅ Подтверждено — класс и методы на месте |
| config.php: `sanitizeString()` (null-byte, mb_check_encoding) | ✅ Подтверждено |
| config.php: `csrfToken()`, `validateCsrf()` | ✅ Подтверждено |
| admin/index.php: статистика одним SELECT с подзапросами | ✅ Подтверждено — 6 COUNT(*) в одном запросе |
| Rate limiting в БД (UPSERT в submit.php) | ✅ Подтверждено — `INSERT ... ON CONFLICT DO UPDATE` в `rate_limits` |
| `rate_limits` создаётся в `migrateSchema()` | ⚠️ **Частично опровергнуто** — создаётся в `Database::initialize()`, а не в `migrateSchema()`. Не влияет на функциональность. |
| Индексы БД: idx_contest_tasks_contest_id, idx_contest_access_*, idx_user_groups_user, idx_submissions_* | ✅ Подтверждено — все индексы создаются |
| Auth.php: `isLoggedIn()`, `getUserId()` | ✅ Подтверждено |
| Auth.php: `isAdmin()` | ❌ **Опровергнуто** — функция отсутствует |
| TestingEngine.php: поля test_number, is_public, status, input, expected, output, error | ✅ Подтверждено |
| Задачи в tasks/ (01.json–14.json) | ✅ Подтверждено — 14 файлов |
| robots.txt, sitemap.xml, manifest.json, sw.js | ❌ **Опровергнуто** — отсутствуют все 4 файла |
