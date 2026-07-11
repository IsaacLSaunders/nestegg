FROM dunglas/frankenphp:1-php8.5

RUN install-php-extensions pdo_pgsql intl zip opcache apcu

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
