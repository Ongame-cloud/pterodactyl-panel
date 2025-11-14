web: php-fpm -F & nginx -g 'daemon off;'
worker: php artisan queue:work --sleep=3 --tries=3
scheduler: while true; do php artisan schedule:run; sleep 60; done
