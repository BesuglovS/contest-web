# Архитектура проекта contest-web

## Обзор

Веб-платформа для проведения контестов по программированию на Python. Клиент-серверное приложение с PHP-бэкендом, SQLite-базой данных и песочницей для выполнения пользовательского кода.

## Структура проекта

```
contest-web/
├── config.php                 # Конфигурация (БД, лимиты, CSRF, часовой пояс)
├── index.php                  # Фронт-контроллер (точка входа)
│
├── includes/                  # Ядро приложения
│   ├── AuthClient.php         # Клиент SSO auth-web (каноническая копия)
│   ├── Auth.php               # Аутентификация и роли поверх AuthClient
│   ├── Autoloader.php         # Автозагрузка (includes/ и includes/DTO/)
│   ├── Database.php           # SQLite-синглтон, схема, миграции
│   ├── Router.php             # Маршрутизация (query-string)
│   ├── Sandbox.php            # Изолированный запуск Python-кода
│   ├── TestingEngine.php      # Движок тестирования (линт + тесты)
│   ├── labels.php             # Канонические статусы и метки
│   └── DTO/TestResult.php     # DTO результата одного теста
│
├── admin/                     # Панель администратора (9 файлов)
│   ├── index.php              # Дашборд
│   ├── tasks.php              # Управление задачами
│   ├── task_groups.php        # Группировка задач
│   ├── contests.php           # Управление контестами
│   ├── contest_results.php    # Результаты контестов
│   ├── submissions.php        # Все решения (+ retest)
│   ├── submission_detail.php  # Детали решения
│   ├── import_tasks.php       # Импорт задач из JSON
│   └── import_format.php      # Формат импорта
│
├── api/                       # JSON API
│   ├── submit.php             # Приём кода, запуск тестов
│   ├── status.php             # Статус проверки (для интеграций)
│   └── contest_progress.php   # Прогресс по контесту (копия из auth-web)
│
├── user/                      # Пользовательская часть (8 файлов)
│   ├── index.php              # Личный кабинет
│   ├── tasks.php              # Список задач
│   ├── task.php               # Просмотр задачи
│   ├── contests.php           # Список контестов
│   ├── contest.php            # Просмотр контеста
│   ├── submissions.php        # История решений
│   ├── submission_detail.php  # Детали решения
│   └── leaderboard.php        # Таблица лидеров
│
├── templates/                 # Шаблоны
│   ├── layout.php             # Основной макет
│   └── admin_nav.php          # Навигация админки
│
├── assets/
│   ├── css/style.css          # Стили
│   ├── js/main.js             # Основной JS
│   └── js/editor.js           # Редактор кода
│
├── data/
│   └── contest.db             # SQLite БД (создаётся автоматически)
│
├── sandbox/                   # Временные файлы (auto)
└── docs/
    └── import_format.md       # Спецификация формата импорта
```

## Архитектура приложения

### Поток запроса

```
HTTP-запрос
    │
    ▼
index.php (фронт-контроллер)
    │
    ├── Router.php ─── Определяет страницу и действие
    │       │
    │       ├── ?page=login        → редирект на auth-web (вход)
    │       ├── ?page=logout       → редирект на auth-web (выход)
    │       ├── ?page=admin-*      → admin/*.php
    │       ├── ?page=api          → api/*.php (?endpoint=submit|status|contest_progress)
    │       └── иначе              → user/*.php
    │
    ├── Auth.php ─── Проверка авторизации и роли
    │
    └── Database.php ─── Подключение к SQLite
```

### Ключевые компоненты

#### Database.php — Синглтон для работы с SQLite

- Автоматическое создание БД и таблиц при первом запуске
- Автоматические миграции (добавление недостающих колонок/индексов; версия схемы — `Database::SCHEMA_VERSION`)
- 12 таблиц: `tasks`, `task_groups`, `task_to_groups` (+`sort_order`), `tests`, `contests`,
  `contest_tasks`, `contest_task_groups`, `contest_access`, `submissions`,
  `submission_test_results`, `settings`, `rate_limits`.
  Таблиц пользователей/групп (`users`, `groups`, `user_groups`) нет и быть не должно:
  источник истины — auth-web, миграция `migrateLegacyClassTables()` дропает легаси-таблицы
- Подготовленные выражения PDO для защиты от SQL-инъекций

#### Auth.php — Аутентификация и авторизация

- Единый источник данных о пользователях — сервис `auth.nayanovaacademy.ru` (auth-web)
- Проверка сессии через auth-web API (`AuthClient::check()`), кэширование в PHP-сессии
- Роли: `admin`, `user` (флаг `is_admin` приходит из auth-web)
- CSRF-токены для форм
- Список пользователей для админки и лидерборда запрашивается из `auth-web/api/public_users.php`

#### Router.php — Маршрутизация

Query-string маршрутизация на основе `$_GET['page']`; для API — `$_GET['endpoint']`:

```php
// dispatchAdmin(): match($page)
match($page) {
    'admin'                => 'admin/index.php',
    'admin-tasks'          => 'admin/tasks.php',
    'admin-contests'       => 'admin/contests.php',
    'admin-submissions'    => 'admin/submissions.php',
    // ...
}

// dispatchUser(): match($page)
'home', 'tasks', 'task', 'contests', 'contest', 'submissions',
'submission-detail', 'leaderboard'

// dispatchApi(): $endpoint = 'submit' | 'status' | 'contest_progress'
```

#### Sandbox.php — Песочница для Python-кода

1. Генерация уникального wrapper-скрипта Python
2. Запуск через `proc_open` с ограничениями:
   - Время: лимит задачи (`tasks.time_limit`, дефолт 2.0 сек)
   - Память: лимит задачи (`tasks.memory_limit`, дефолт 128 МБ; на Linux — RLIMIT_AS с запасом + вердикт по пику RSS, на Windows мягкий режим)
3. Парсинг выходных данных (stdout, stderr, exit code)
4. Очистка traceback для скрытия путей к файлам
5. PEP8-линтинг через pycodestyle (fail-closed: без линтера все посылки = `lint_error`)

#### TestingEngine.php — Движок тестирования

```
Задача + Код участника
    │
    ├── Загрузка тестов из БД
    ├── Линтинг PEP8
    ├── Для каждого теста:
    │   ├── Запуск кода с входными данными
    │   ├── Сравнение вывода с эталоном
    │   └── Запись результата
    └── Возврат итогового статуса
        (accepted/wrong_answer/runtime_error/time_limit/memory_limit/no_function/lint_error;
         вердикт определяется ПЕРВЫМ непройденным тестом, последующие его не перебивают)
```

### Схема БД

Таблицы пользователей в contest-web нет. Везде, где нужен `user_id`, хранится глобальный id пользователя из auth-web. Имена пользователей подтягиваются из auth-web на лету.

```
contest_access (user_id из auth-web / group_id из auth-web)
    │
    └── submissions (user_id из auth-web)
          │
          └── submission_test_results
          
tasks ────────── task_to_groups ────── task_groups
   │                (+ sort_order)
   └── tests
   
contests ────── contest_tasks ────── tasks
   │
   ├── contest_task_groups ── task_groups
   │
   └── contest_access (user_id/group_id)
```

### Безопасность

| Угроза | Защита |
|--------|--------|
| SQL-инъекции | Подготовленные выражения PDO |
| XSS | `htmlspecialchars()` для вывода |
| CSRF | Токены в формах |
| Песочница | `proc_open` + ограничения времени/памяти |
| Сессии | `httponly` + `use_only_cookies` + регенерация |

### API

#### POST api/submit.php

```json
// Запрос
{
    "task_id": 1,
    "code": "print(int(input()) * 2)",
    "contest_id": 1
}

// Ответ
{
    "submission_id": 1,
    "status": "accepted",
    "all_passed": true,
    "passed": 5,
    "total": 5,
    "total_time": 0.023,
    "public_results": [...]
}
```

Ограничения: CSRF-проверка, лимит 10 запросов/минуту, проверка доступа к контесту, проверка временного окна контеста.
