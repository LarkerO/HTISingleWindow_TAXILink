FROM php:8.4-cli-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends libcurl4-openssl-dev libsqlite3-dev libonig-dev openssl unzip \
    && docker-php-ext-install pdo_mysql pdo_sqlite pcntl mbstring curl \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader
COPY . .
RUN composer dump-autoload --no-dev --optimize && mkdir -p runtime/logs
CMD ["php", "start.php", "start"]
