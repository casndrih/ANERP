FROM composer:2 AS composer-bin
FROM php:8.4-fpm-bookworm
# Refresh the Debian base packages before installing runtime dependencies so
# security fixes published after the upstream PHP image was built are included.
RUN apt-get update \
 && apt-get upgrade -y \
 && apt-get install -y --no-install-recommends git unzip libpq-dev libzip-dev libicu-dev libonig-dev \
 && docker-php-ext-install pdo_pgsql pgsql intl mbstring bcmath opcache zip \
 && pecl install redis && docker-php-ext-enable redis \
 && rm -rf /var/lib/apt/lists/*
COPY --from=composer-bin /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
RUN composer create-project laravel/laravel:^12.0 . --no-interaction
COPY app/ app/
COPY routes/ routes/
COPY database/ database/
COPY resources/ resources/
COPY deploy/php/zz-anerp.ini /usr/local/etc/php/conf.d/zz-anerp.ini
RUN chown -R www-data:www-data storage bootstrap/cache
EXPOSE 9000
CMD ["php-fpm"]
