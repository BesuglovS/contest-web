# ==========================================
# 1. Редирект HTTP → HTTPS
# ==========================================
server {
    listen 80;
    server_name contest.nayanovaacademy.ru;
    
    # Перенаправляем все запросы на HTTPS
    return 301 https://$host$request_uri;
}

# ==========================================
# 2. Основной HTTPS-сервер
# ==========================================
server {
    listen 443 ssl http2;
    server_name contest.nayanovaacademy.ru;

    # --- SSL-сертификаты (ваши пути) ---
    ssl_certificate     /etc/ssl/certs/nayanovaacademy.ru/cert.pem;
    ssl_certificate_key /etc/ssl/private/nayanovaacademy.ru/key.pem;

    # --- Настройки безопасности SSL ---
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384;
    ssl_prefer_server_ciphers off;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 1d;

    # --- Security-заголовки ---
    # ВАЖНО: add_header НЕ наследуется локациями, в которых есть свой add_header,
    # поэтому заголовки дублируются в статик-локациях ниже.
    add_header Strict-Transport-Security "max-age=63072000; includeSubDomains; preload" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    # --- Основные параметры сайта ---
    root /var/www/contest.nayanovaacademy.ru/public;
    index index.php index.html index.htm;

    # Логирование
    access_log /var/log/nginx/contest.nayanovaacademy.ru.access.log;
    error_log  /var/log/nginx/contest.nayanovaacademy.ru.error.log;

    # 1. Блокировка скрытых файлов
    location ~ /\. {
        deny all;
        access_log off;
        log_not_found off;
    }

    # 2. Блокировка служебных путей (БД, песочница, конфиг, исходники задач,
    #    серверные классы и шаблоны).
    # ВАЖНО: должно быть ВЫШЕ location ~ \.php$, т.к. regex-локации проверяются
    # по порядку — иначе /config.php исполняется через FastCGI.
    # /tasks содержит скрытые тесты задач (tasks/*.json) и не должен отдаваться.
    location ~ ^/(data|sandbox|config\.php|tasks|includes|templates)(/|$) {
        deny all;
        return 403;
    }

    # 3. Обработка PHP-файлов через FastCGI
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # 4. Кэширование статики
    # SW bez content-hash - ne keshirovat, inache brauzer ne uvidit obnovlenie sw.js
    location = /assets/js/tracking-client.js {
        add_header Strict-Transport-Security "max-age=63072000; includeSubDomains; preload" always;
        add_header X-Content-Type-Options "nosniff" always;
        add_header Referrer-Policy "strict-origin-when-cross-origin" always;
        add_header Cache-Control "no-cache, must-revalidate";
    }

    location = /sw.js {
        add_header Strict-Transport-Security "max-age=63072000; includeSubDomains; preload" always;
        add_header X-Content-Type-Options "nosniff" always;
        add_header Referrer-Policy "strict-origin-when-cross-origin" always;
        add_header Cache-Control "no-cache, must-revalidate";
    }

    location ~* \.(css|js|png|jpg|jpeg|gif|ico|svg|woff|woff2)$ {
        expires 30d;
        add_header Strict-Transport-Security "max-age=63072000; includeSubDomains; preload" always;
        add_header X-Content-Type-Options "nosniff" always;
        add_header Referrer-Policy "strict-origin-when-cross-origin" always;
        add_header Cache-Control "public, immutable";
        access_log off;
    }

    # 5. Основная маршрутизация — все запросы на index.php (роутинг через ?page=).
    # Префиксная локация объявлена последней: она применяется только если не
    # сработала ни одна regex-локация выше.
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
}
