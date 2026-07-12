<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrfToken() ?>">
    <title><?= htmlspecialchars($pageTitle ?? 'Контест') ?> — <?= SITE_NAME ?></title>
    <link rel="icon" type="image/png" sizes="16x16" href="/assets/favicon-16x16.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="48x48" href="/assets/favicon-48x48.png">
    <link rel="icon" type="image/png" sizes="256x256" href="/assets/favicon-256x256.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/assets/favicon-180x180.png">    
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=5">
    <!-- KaTeX for LaTeX rendering -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.css">
    <script src="https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/contrib/auto-render.min.js"></script>
</head>
<body>
    <header class="site-header">
        <div class="container">
            <a href="<?= BASE_URL ?>/index.php" class="logo"><?= SITE_NAME ?></a>
            <nav class="main-nav">
                <?php if (Auth::isLoggedIn()): ?>
                    <?php if (Auth::isAdmin()): ?>
                        <a href="<?= BASE_URL ?>/index.php?page=admin">Админка</a>
                    <?php endif; ?>
                    <a href="<?= BASE_URL ?>/index.php?page=contests">Контесты</a>
                    <a href="<?= BASE_URL ?>/index.php?page=leaderboard">Лидеры</a>
                    <a href="<?= BASE_URL ?>/index.php?page=submissions">Мои решения</a>
                    <span class="user-info"><?= htmlspecialchars(Auth::getUserName()) ?></span>
                    <a href="<?= BASE_URL ?>/index.php?page=logout" class="btn-logout">Выйти</a>
                <?php endif; ?>
            </nav>
        </div>
    </header>

    <main class="container">
        <?= $content ?? '' ?>
    </main>

    <footer class="site-footer">
        <div class="container">
            <p>&copy; <?= date('Y') ?> <?= SITE_NAME ?> — система обучения программированию на Python</p>
        </div>
    </footer>

    <script src="<?= BASE_URL ?>/assets/js/main.js"></script>
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            renderMathInElement(document.body, {
                delimiters: [
                    {left: "\\(", right: "\\)", display: false},
                    {left: "\\[", right: "\\]", display: true}
                ],
                throwOnError: false
            });
        });
    </script>
</body>
</html>