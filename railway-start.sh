#!/bin/bash
set -e

echo "Starting Pterodactyl Panel on Railway..."

if [ ! -f .env ]; then
    echo "Creating .env file..."
    cp .env.example .env
    
    if [ -z "$APP_KEY" ]; then
        echo "Generating APP_KEY..."
        php artisan key:generate --force
    fi
fi

mkdir -p storage/logs storage/framework/sessions storage/framework/views storage/framework/cache bootstrap/cache
chmod -R 755 storage bootstrap/cache

echo "Waiting for database connection..."
php artisan migrate --force --seed

echo "Clearing caches..."
php artisan config:clear
php artisan cache:clear
php artisan view:clear

echo "Optimizing application..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "Setting up nginx..."
cat > /etc/nginx/nginx.conf << 'EOF'
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
            fastcgi_pass unix:/tmp/php-fpm.sock;
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

[www]
user = nobody
group = nobody
listen = /tmp/php-fpm.sock
listen.owner = nobody
listen.group = nobody
listen.mode = 0660
pm = dynamic
pm.max_children = 50
pm.start_servers = 5
pm.min_spare_servers = 5
pm.max_spare_servers = 35
pm.max_requests = 500
clear_env = no
catch_workers_output = yes
EOF

echo "Starting PHP-FPM..."
php-fpm -F -y /tmp/php-fpm.conf &

echo "Starting nginx..."
nginx -c /etc/nginx/nginx.conf -g 'daemon off;'
