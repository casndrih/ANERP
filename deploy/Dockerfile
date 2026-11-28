FROM composer:2 AS composer-bin
FROM php:8.4-fpm-bookworm AS app

RUN apt-get update && apt-get install -y --no-install-recommends git unzip libpq-dev libzip-dev libicu-dev libonig-dev \
 && docker-php-ext-install pdo_pgsql pgsql intl mbstring bcmath opcache zip \
 && pecl install redis && docker-php-ext-enable redis \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer-bin /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN composer create-project laravel/laravel:^12.0 . --no-interaction --prefer-dist --no-progress
COPY routes/ routes/
COPY database/ database/
COPY resources/ resources/
COPY deploy/php/zz-anerp.ini /usr/local/etc/php/conf.d/zz-anerp.ini
RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache
EXPOSE 9000
CMD ["php-fpm"]

FROM nginx:1.27-alpine AS web
COPY --from=app /var/www/html/public /var/www/html/public
COPY deploy/nginx/default.conf /etc/nginx/conf.d/default.conf
EXPOSE 80
