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
│   ├── Database.php           # SQLite-синглтон, схема, миграции
│   ├── Auth.php               # Аутентификация, сессии, роли
│   ├── Router.php             # Маршрутизация (query-string)
│   ├── Sandbox.php            # Изолированный запуск Python-кода
│   ├── TestingEngine.php      # Движок тестирования (линт + тесты)
│   └── labels.php             # Тексты интерфейса
│
├── admin/                     # Панель администратора (12 файлов)
│   ├── users.php              # Управление пользователями
│   ├── groups.php             # Управление группами
│   ├── tasks.php              # Управление задачами
│   ├── task_groups.php        # Группировка задач
│   ├── contests.php           # Управление контестами
│   ├── contest_results.php    # Результаты контестов
│   ├── submissions.php        # Все решения
│   ├── submission_detail.php  # Детали решения
│   ├── import_tasks.php       # Импорт задач из JSON
│   ├── import_format.php      # Формат импорта
│   ├── generate_tasks.php     # Генератор тестов
│   └── change_password.php    # Смена пароля
│
├── api/                       # JSON API
│   ├── submit.php             # Приём кода, запуск тестов
│   └── status.php             # Статус проверки
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
│   ├── login.php              # Форма входа
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
    │       ├── ?page=login        → templates/login.php
    │       ├── ?page=admin-*      → admin/*.php
    │       ├── ?page=user-*       → user/*.php
    │       └── ?page=api          → api/*.php
    │
    ├── Auth.php ─── Проверка авторизации и роли
    │
    └── Database.php ─── Подключение к SQLite
```

### Ключевые компоненты

#### Database.php — Синглтон для работы с SQLite

- Автоматическое создание БД и таблиц при первом запуске
- Автоматические миграции (добавление недостающих колонок/индексов)
- 12 таблиц: `users`, `groups`, `user_groups`, `tasks`, `task_groups`, `task_to_groups`, `tests`, `contests`, `contest_tasks`, `contest_task_groups`, `contest_access`, `submissions`, `submission_test_results`, `settings`
- Подготовленные выражения PDO для защиты от SQL-инъекций

#### Auth.php — Аутентификация и авторизация

- Сессии с флагами `httponly`, `use_only_cookies`
- Регенерация сессии при входе
- Роли: `admin`, `user`
- CSRF-токены для форм
- Хэширование паролей через `password_hash()` (bcrypt)

#### Router.php — Маршрутизация

Query-string маршрутизация на основе `$_GET['page']`:

```php
match($page) {
    'login'         => 'templates/login.php',
    'admin-users'   => 'admin/users.php',
    'task'          => 'user/task.php',
    'api-submit'    => 'api/submit.php',
    // ...
}
```

#### Sandbox.php — Песочница для Python-кода

1. Генерация уникального wrapper-скрипта Python
2. Запуск через `proc_open` с ограничениями:
   - Время: `DEFAULT_TIME_LIMIT` (2.0 сек)
   - Память: `DEFAULT_MEMORY_LIMIT` (128 МБ)
3. Парсинг выходных данных (stdout, stderr, exit code)
4. Очистка traceback для скрытия путей к файлам
5. PEP8-линтинг через pycodestyle

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
        (accepted/wrong_answer/runtime_error/time_limit/lint_error)
```

### Схема БД

```
users ──────────── user_groups ──────────── groups
   │                    │
   │                    │
   └── submissions ─────┘
         │
         └── submission_test_results
         
tasks ────────── task_to_groups ────── task_groups
   │
   └── tests
   
contests ────── contest_tasks ────── tasks
   │
   ├── contest_task_groups ── task_groups
   │
   └── contest_access ── users/groups
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
