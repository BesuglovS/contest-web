<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Платформа для проведения соревнований и олимпиад по программированию на Python">
    <meta name="csrf-token" content="<?= csrfToken() ?>">
    <meta name="theme-color" content="#1a73e8">
    <title><?= htmlspecialchars($pageTitle ?? 'Контест') ?> — <?= SITE_NAME ?></title>
    <link rel="canonical" href="<?= BASE_URL . ($canonicalPath ?? '/index.php') ?>">
    <link rel="icon" type="image/png" sizes="16x16" href="/assets/favicon-16x16.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="48x48" href="/assets/favicon-48x48.png">
    <link rel="icon" type="image/png" sizes="256x256" href="/assets/favicon-256x256.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/assets/favicon-180x180.png">
    <link rel="manifest" href="/manifest.json">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=5">
    <!-- KaTeX for LaTeX rendering -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.css">
    <script src="https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/contrib/auto-render.min.js"></script>
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "WebSite",
        "name": "Контест",
        "url": "https://contest.nayanovaacademy.ru",
        "description": "Платформа для проведения соревнований и олимпиад по программированию на Python",
        "potentialAction": {
            "@type": "SearchAction",
            "target": "https://contest.nayanovaacademy.ru/index.php?page=tasks&search={search_term_string}",
            "query-input": "required name=search_term_string"
        }
    }
    </script>
</head>
<body>
    <a href="#main-content" class="skip-link">Перейти к содержимому</a>
    <header class="site-header">
        <div class="container">
            <a href="<?= BASE_URL ?>/index.php" class="logo" aria-label="На главную"><?= SITE_NAME ?></a>
            <nav class="main-nav" aria-label="Основная навигация">
                <?php if (Auth::isLoggedIn()): ?>
                    <?php if (Auth::isAdmin()): ?>
                        <a href="<?= BASE_URL ?>/index.php?page=admin">Админка</a>
                    <?php endif; ?>
                    <a href="<?= BASE_URL ?>/index.php?page=contests">Контесты</a>
                    <a href="<?= BASE_URL ?>/index.php?page=leaderboard">Лидеры</a>
                    <a href="<?= BASE_URL ?>/index.php?page=submissions">Мои решения</a>
                    <span class="user-info" aria-label="Текущий пользователь"><?= htmlspecialchars(Auth::getUserName()) ?></span>
                    <a href="<?= BASE_URL ?>/index.php?page=logout" class="btn-logout">Выйти</a>
                <?php endif; ?>
            </nav>
        </div>
    </header>

    <main class="container" id="main-content" role="main">
        <?= $content ?? '' ?>
    </main>

    <footer class="site-footer" role="contentinfo">
        <div class="container">
            <p>&copy; <?= date('Y') ?> <?= SITE_NAME ?> — система обучения программированию на Python</p>
        </div>
    </footer>

    <script src="<?= BASE_URL ?>/assets/js/main.js"></script>
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function() {
                navigator.serviceWorker.register('/sw.js').then(function(registration) {
                    console.log('SW registered: ', registration.scope);
                }).catch(function(err) {
                    console.log('SW registration failed: ', err);
                });
            });
        }
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
