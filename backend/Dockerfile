FROM php:8.4-cli-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends libcurl4-openssl-dev libsqlite3-dev libpq-dev libonig-dev openssl unzip \
    && docker-php-ext-install pdo_mysql pdo_pgsql pdo_sqlite pcntl posix mbstring curl \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader
COPY . .
RUN composer dump-autoload --no-dev --optimize \
    && mkdir -p runtime/logs installation keys \
    && chown -R www-data:www-data runtime installation \
    && chmod 700 installation
USER www-data
EXPOSE 8787 8788 8790
STOPSIGNAL SIGTERM
CMD ["php", "start.php", "start"]
