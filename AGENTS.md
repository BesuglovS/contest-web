# AGENTS.md — Инструкции для ИИ-ассистентов

Платформа олимпиадных задач (contest platform) Nayanova Academy: PHP 8 + SQLite, без фреймворков.
SSO через `auth.nayanovaacademy.ru` (кука `auth_session`), проверка решений — Python-песочница с
lint-ом `pycodestyle`, API под `api/`, роутинг по query-string через `includes/Router.php`.
Прод: `https://contest.nayanovaacademy.ru`.

## ⚠️ Критические правила

1. **Авторизация пользователей — только через auth-web.** Таблицы `users`, `groups`, `user_groups`
   **не создавать и не добавлять** — `migrateLegacyClassTables()` явно их дропает; источник истины — auth-web.
2. **Скрытые тесты не должны протекать.** `submit.php` возвращает только `public_results`;
   `user/submission_detail.php` маскирует ввод/вывод непубличных тестов («скрыто»). Не ослабляйте.
3. **Доверенный HTML**: поля `given`/`input_format`/`output_format` задач рендерятся сырыми в
   `user/task.php` намеренно (контент от админов). Не «чините» `htmlspecialchars`. Всё остальное пользовательское — экранировать.
4. **Песочница — мягкая изоляция**: лимит времени — в Python-обёртке (`subprocess.communicate(timeout)`);
   память на Linux измеряется (пик RSS) и ограничивается (`RLIMIT_AS` с запасом 64МБ, вердикт по RSS),
   на Windows — мягкий режим без изоляции памяти. Запрещённые модули (`FORBIDDEN_MODULES`) проверяются
   в `TestingEngine::findForbiddenModules()` до запуска (вердикт `lint_error`). Это НЕ полный sandbox.
5. **Fail-closed lint**: если на сервере нет `pycodestyle`, все посылки становятся `lint_error`. Проверяйте `pycodestyle --version` при работе с песочницей.
6. **Новые страницы регистрируются в `includes/Router.php`** (блоки `dispatchAdmin`/`dispatchUser`/`dispatchApi`), иначе 404.
7. **Новые классы — в `includes/` или `includes/DTO/`** (без namespace): кастомный `Autoloader` сканирует только эти две директории.
8. **Канонические статусы** (в `includes/labels.php`): `pending, lint_error, accepted, wrong_answer, runtime_error, time_limit, memory_limit, no_function`. Метки статусов брать ТОЛЬКО оттуда, не копировать массивы локально.
9. **Rate-limit — DB-backed** (таблица `rate_limits`, UPSERT внутри `BEGIN IMMEDIATE`). Легаси `data/.ratelimit` убрано.
10. **Оценивание синхронное** в `submit.php` (N тестов + lint в одном HTTP-запросе). Не переводите в async без переработки `status.php` и фронтенда.
11. **Время — UTC**; учётные часовые пояса соблюдать при выводе (`toDisplayTime()`, не сырые datetime).
12. **Схема БД версионируется**: константа `Database::SCHEMA_VERSION`; при любом изменении схемы — поднять её,
    иначе миграции не применятся. Источник истины — фактические файлы, а не история git и не устаревшие доки.
13. **CSRF обязателен для всех POST** (админка — `validateCsrf()`, API — заголовок `X-CSRF-TOKEN`).

## 🔧 Команды

```bash
php -S 127.0.0.1:8080 -t .   # локальный dev-сервер (PHP 8, SQLite3, Python + pycodestyle)
php sandbox/test_run.php      # ручной smoke-тест песочницы
php sandbox/test_comprehensive.php
php sandbox/test_debug.php
.\deploy.ps1 -DryRun          # сухой прогон
.\deploy.ps1                  # деплой
```

Автотестов/CI/линтера нет. `refactoring.md` — частично устаревший ручной чек-лист.

## 🏗 Структура

```
config.php               # БД, putenv(PYTHON_CMD); сессия (secure-флаг по HTTPS/X-Forwarded-Proto)
index.php                # фронт-контроллер → Router::dispatch()
api/                     # JSON: submit.php, status.php, contest_progress.php и др.
admin/                   # админка: задачи, посылки (retest), импорт JSON, статистика
user/                    # кабинет: task.php, submissions, submission_detail.php, results
includes/                # Router.php, Database.php, AuthClient.php, Sandbox.php, TestingEngine.php, labels.php, DTO/
templates/layout.php     # общий шаблон (ob_start-паттерн)
sandbox/                 # Python-обёртки (генерируются рантайм) + dev-тесты (nginx блокирует /sandbox — не снимать)
data/contest.db          # SQLite (на сервере; сохраняется деплоем)
assets/js/               # editor.js, main.js (обёртка fetch: X-CSRF-TOKEN), tracking-client.js
tasks/                   # исходники задач для импорта (JSON); НЕ деплоится — содержат скрытые тесты
docs/, refactoring.md    # документация (местами устарела — сверяться с кодом)
deploy.ps1               # деплой по SSH (sqlite3-backup БД + атомарный своп каталога)
contest.nayanovaacademy.ru  # nginx-конфиг
```

## 💻 Конвенции кода

- **PHP 8**: 4-space indent, фигурные скобки с новой строки для классов/методов, русские docblock'и,
  классы без namespace, PDO с `ATTR_EMULATE_PREPARES => false`, подготовленные statements везде.
- **JSON API**: `Content-Type: application/json; charset=utf-8`, `JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE`,
  ошибки `{"error": "..."}` + HTTP-коды; пути файлов вычищаются из сообщений (`cleanErrorMessage()`).
- **Ошибки**: try/catch на шаг для `PDOException`/`Throwable`; `submit.php` использует
  `register_shutdown_function` + `set_error_handler` → `ErrorException` → `sendJsonError()` с очисткой буфера.
- **Шаблоны**: `$pageTitle`, `ob_start()`, `$content = ob_get_clean(); require BASE_PATH . '/templates/layout.php';`
- **JS**: IIFE-модули; `window.fetch` обёрнут для инъекции `X-CSRF-TOKEN`; редактор читает
  `window.TASK_ID`/`window.CONTEST_ID`; код в `localStorage['last_code_<taskId>']`.
- **CSS**: CSS-переменные (`--primary`, `--text-muted`), `:focus-visible`, классы `.card/.btn/.alert/.submission-status`.

## 🔍 Семантика проверки (не сломать)

- Проверка по функции (function-check): `input` — Python-literal аргументов вызова, `output` — ожидаемое
  возвращаемое значение (float-сравнение с допуском 1e-6); вердикт `no_function`, если функции нет;
  блок `if __name__ == '__main__'` студента НЕ выполняется.
- `task_to_groups.sort_order` пишется `import_tasks.php`, но отсутствует в базовой схеме — SQL по этой таблице вести толерантно.

## 🚀 Деплой (`deploy.ps1`)

1. `.env` → SSH-переменные (`DEPLOY_SSH_*`, `DEPLOY_WEB_USER`); `icacls` ключа.
2. `tar` репо (без `.git`, БД, `sandbox`-темпов, логов, `.env`, `deploy.ps1`, nginx-конфига,
   `tasks/` — скрытые тесты, node_modules).
3. Удалённо: `sqlite3 .backup` БД в `/tmp/contest-backup` (fallback — копирование файлов) →
   распаковка в `<remote>.new` → **атомарный mv-своп** каталогов → восстановление БД →
   `chown/chmod 775` на `data/` и `sandbox/` → удаление `.old`.
4. Опционально: деплой nginx-конфига + `nginx -t && systemctl reload nginx`.
5. `-DryRun` печатает команды без выполнения.

Требования сервера: nginx + PHP 8.1-FPM, Python + `pycodestyle`, корень `/var/www/contest.nayanovaacademy.ru/public`, nginx блокирует `/data`, `/sandbox`, `/tasks`, `/includes`, `/templates`, `config.php`, dotfiles.

## 🔒 Безопасность (не ломать)

- Все POST — с CSRF-токеном (админские формы — скрытое поле `csrf_token` через `csrfField()` + `validateCsrf()`;
  fetch — заголовок `X-CSRF-TOKEN`, инъекция в `main.js`); межсайтовые вызовы с `credentials: 'include'`.
- Файловые загрузки: только админ-импорт JSON из `tmp_name`, без сохранения.
- `contest_progress.php` и `tracking-client.js` — канонические копии из auth-web; после правки синхронизировать в экосистему.
- Файл `.env` (deploy-переменные: SSH host/port/user/путь к ключу) **намеренно хранится в
  репозитории** — это нормальная практика данного проекта; не удаляйте его из git и не добавляйте
  в `.gitignore`. Приватный SSH-ключ (`ssh-private.key` в `G:\WebSites\na\`) в репозитории
  не хранится и коммитить его нельзя.
- Бэкап/DB на сервере — единственное, что переживает деплой; не оставлять на сервере посторонних файлов.