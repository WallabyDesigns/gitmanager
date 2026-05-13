# syntax=docker/dockerfile:1.7

FROM composer:2 AS vendor
WORKDIR /app
USER root
ENV COMPOSER_ALLOW_SUPERUSER=1
COPY . .
RUN mkdir -p bootstrap/cache \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    && chmod -R 775 bootstrap/cache storage
RUN composer install --no-dev --no-interaction --prefer-dist --no-progress --optimize-autoloader

FROM php:8.2-fpm-bookworm AS app
WORKDIR /var/www/html

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        curl \
        ca-certificates \
        libzip-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libonig-dev \
        libxml2-dev \
        sqlite3 \
        libsqlite3-dev \
        openssh-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        pdo_sqlite \
        mbstring \
        zip \
        exif \
        pcntl \
        bcmath \
        gd \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=vendor /app /var/www/html

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh \
    && mkdir -p storage bootstrap/cache database \
    && chown -R www-data:www-data storage bootstrap/cache database \
    && chmod -R 775 storage bootstrap/cache \
    && (ln -s /var/www/html/storage/app/public /var/www/html/public/storage 2>/dev/null || true)

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm"]

FROM nginx:1.27-alpine AS nginx
WORKDIR /var/www/html
COPY --from=app /var/www/html /var/www/html
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
RUN mkdir -p /var/www/html/storage/app \
    && (ln -s /var/www/html/storage/app/public /var/www/html/public/storage 2>/dev/null || true)
