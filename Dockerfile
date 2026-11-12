FROM php:8.3-cli-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip libpq-dev libonig-dev \
    && docker-php-ext-install pdo_pgsql mbstring bcmath pcntl \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .

RUN composer install --no-dev --no-interaction \
    --prefer-dist --optimize-autoloader \
    && mkdir -p storage/framework/cache \
       storage/framework/sessions \
       storage/framework/views \
       storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && echo "expose_php = Off" > /usr/local/etc/php/conf.d/security.ini

EXPOSE 10000

# `php artisan serve` handles one request at a time by default, so a slow AI
# call blocked every other visitor. Run several PHP workers in parallel.
ENV PHP_CLI_SERVER_WORKERS=4

# Apply pending database migrations on every deploy before serving.
CMD ["sh", "-c", "php artisan migrate --force && php artisan serve --host=0.0.0.0 --port=${PORT:-10000}"]
