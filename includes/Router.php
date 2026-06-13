<?php
/**
 * Простой роутер для обработки запросов
 */
class Router
{
    private string $page;
    private string $action;

    public function __construct()
    {
        $this->page = $_GET['page'] ?? 'home';
        $this->action = $_GET['action'] ?? 'list';
    }

    public function dispatch(): void
    {
        // Публичные страницы (без авторизации)
        if ($this->page === 'login') {
            $this->renderLoginPage();
            return;
        }

        if ($this->page === 'logout') {
            Auth::logout();
            header('Location: ' . BASE_URL . '/index.php?page=login');
            exit;
        }

        // Требуем авторизацию для всех остальных страниц
        Auth::requireLogin();

        // Роутинг административных страниц
        if (str_starts_with($this->page, 'admin')) {
            Auth::requireAdmin();
            $this->dispatchAdmin();
            return;
        }

        // API эндпоинты
        if ($this->page === 'api') {
            $this->dispatchApi();
            return;
        }

        // Пользовательские страницы
        $this->dispatchUser();
    }

    private function dispatchAdmin(): void
    {
        match ($this->page) {
            'admin' => require BASE_PATH . '/admin/index.php',
            'admin-users' => require BASE_PATH . '/admin/users.php',
            'admin-groups' => require BASE_PATH . '/admin/groups.php',
            'admin-tasks' => require BASE_PATH . '/admin/tasks.php',
            'admin-task-groups' => require BASE_PATH . '/admin/task_groups.php',
            'admin-contests' => require BASE_PATH . '/admin/contests.php',
            'admin-contest-results' => require BASE_PATH . '/admin/contest_results.php',
            'admin-submissions' => require BASE_PATH . '/admin/submissions.php',
            'admin-submission-detail' => require BASE_PATH . '/admin/submission_detail.php',
            'admin-generate-tasks' => require BASE_PATH . '/admin/generate_tasks.php',
            'admin-import-tasks' => require BASE_PATH . '/admin/import_tasks.php',
            'admin-import-format' => require BASE_PATH . '/admin/import_format.php',
            default => $this->render404(),
        };
    }

    private function dispatchUser(): void
    {
        match ($this->page) {
            'home' => require BASE_PATH . '/user/index.php',
            'tasks' => require BASE_PATH . '/user/tasks.php',
            'task' => require BASE_PATH . '/user/task.php',
            'contests' => require BASE_PATH . '/user/contests.php',
            'contest' => require BASE_PATH . '/user/contest.php',
            'submissions' => require BASE_PATH . '/user/submissions.php',
            'submission-detail' => require BASE_PATH . '/user/submission_detail.php',
            default => $this->render404(),
        };
    }

    private function dispatchApi(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $endpoint = $_GET['endpoint'] ?? '';

        if ($endpoint === 'submit') {
            require BASE_PATH . '/api/submit.php';
        } elseif ($endpoint === 'status') {
            require BASE_PATH . '/api/status.php';
        } else {
            echo json_encode(['error' => 'Unknown endpoint']);
        }
        exit;
    }

    private function renderLoginPage(): void
    {
        $error = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $login = trim($_POST['login'] ?? '');
            $password = $_POST['password'] ?? '';
            $result = Auth::login($login, $password);
            if ($result['success']) {
                $redirect = $_SESSION['redirect_after_login'] ?? (BASE_URL . '/index.php');
                unset($_SESSION['redirect_after_login']);
                header('Location: ' . $redirect);
                exit;
            }
            $error = $result['error'];
        }
        $pageTitle = 'Вход';
        ob_start();
        require BASE_PATH . '/templates/login.php';
        $content = ob_get_clean();
        require BASE_PATH . '/templates/layout.php';
    }

    private function render404(): void
    {
        http_response_code(404);
        echo '<!DOCTYPE html><html><head><title>404 - Контест</title></head><body><h1>Страница не найдена</h1><p><a href="' . BASE_URL . '/index.php">На главную</a></p></body></html>';
    }
}