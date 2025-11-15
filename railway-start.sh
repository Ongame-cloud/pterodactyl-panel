#!/bin/sh

echo "Starting Pterodactyl Panel on Railway..."

PORT=${PORT:-8080}
echo "Using port: $PORT"

if [ ! -f .env ]; then
    echo "Creating .env file..."
    cp .env.example .env
    
    if [ -z "$APP_KEY" ]; then
        echo "Generating APP_KEY..."
        php artisan key:generate --force || true
    fi
fi

mkdir -p storage/logs storage/framework/sessions storage/framework/views storage/framework/cache bootstrap/cache
chmod -R 777 storage bootstrap/cache

echo "Running migrations..."
php artisan migrate --force || echo "Migration skipped"

echo "Clearing caches..."
php artisan config:clear 2>/dev/null || true
php artisan cache:clear 2>/dev/null || true
php artisan view:clear 2>/dev/null || true

echo "Setting up nginx..."
cat > /etc/nginx/nginx.conf << EOF
user nobody;
worker_processes auto;
pid /tmp/nginx.pid;

events {
    worker_connections 1024;
}

http {
    include /etc/nginx/mime.types;
    default_type application/octet-stream;
    
    access_log /dev/stdout;
    error_log /dev/stderr;
    
    sendfile on;
    tcp_nopush on;
    tcp_nodelay on;
    keepalive_timeout 65;
    types_hash_max_size 2048;
    client_max_body_size 100M;
    
    gzip on;
    gzip_vary on;
    gzip_proxied any;
    gzip_comp_level 6;
    gzip_types text/plain text/css text/xml text/javascript application/json application/javascript application/xml+rss application/rss+xml font/truetype font/opentype application/vnd.ms-fontobject image/svg+xml;
    
    server {
        listen $PORT;
        server_name _;
        root /app/public;
        index index.php;
        
        location / {
            try_files \$uri \$uri/ /index.php?\$query_string;
        }
        
        location ~ \.php$ {
            fastcgi_split_path_info ^(.+\.php)(/.+)$;
            fastcgi_pass 127.0.0.1:9000;
            fastcgi_index index.php;
            include fastcgi_params;
            fastcgi_param PHP_VALUE "upload_max_filesize = 100M \n post_max_size=100M";
            fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
            fastcgi_param HTTP_PROXY "";
            fastcgi_intercept_errors off;
            fastcgi_buffer_size 16k;
            fastcgi_buffers 4 16k;
            fastcgi_connect_timeout 300;
            fastcgi_send_timeout 300;
            fastcgi_read_timeout 300;
        }
        
        location ~ /\.ht {
            deny all;
        }
    }
}
EOF

echo "Setting up PHP-FPM..."
cat > /tmp/php-fpm.conf << 'EOF'
[global]
pid = /tmp/php-fpm.pid
error_log = /dev/stderr
daemonize = no

[www]
user = nobody
group = nobody
listen = 127.0.0.1:9000
pm = dynamic
pm.max_children = 50
pm.start_servers = 5
pm.min_spare_servers = 5
pm.max_spare_servers = 35
pm.max_requests = 500
clear_env = no
catch_workers_output = yes
php_admin_value[error_log] = /dev/stderr
php_admin_flag[log_errors] = on
EOF

echo "Starting PHP-FPM..."
php-fpm -F -y /tmp/php-fpm.conf &
PHP_FPM_PID=$!

echo "Waiting for PHP-FPM to start..."
sleep 3

echo "Testing PHP-FPM connection..."
for i in 1 2 3 4 5; do
    if nc -z 127.0.0.1 9000 2>/dev/null; then
        echo "PHP-FPM is running on 127.0.0.1:9000"
        break
    fi
    echo "Waiting for PHP-FPM... ($i/5)"
    sleep 1
done

echo "Creating health check..."
cat > /app/public/health.php << 'HEALTHEOF'
<?php
echo "OK - PHP-FPM is working\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n";
phpinfo(INFO_GENERAL);
HEALTHEOF

echo "Starting nginx on port $PORT..."
nginx -c /etc/nginx/nginx.conf -g 'daemon off;' &
NGINX_PID=$!

echo "Services started. PHP-FPM PID: $PHP_FPM_PID, Nginx PID: $NGINX_PID"
echo "Health check available at: /health.php"
echo "Logs will appear below..."

wait $NGINX_PID
