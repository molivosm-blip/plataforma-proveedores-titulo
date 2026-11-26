# Imagen de la API (Lumen) para ejecución en contenedores.
FROM composer:2 AS dependencias
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --ignore-platform-reqs

FROM php:8.2-apache
RUN apt-get update \
 && apt-get install -y --no-install-recommends libzip-dev libonig-dev unzip \
 && docker-php-ext-install pdo_mysql zip mbstring \
 && a2enmod rewrite headers \
 && sed -ri 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
 && rm -rf /var/lib/apt/lists/*
WORKDIR /var/www/html
COPY --from=dependencias /app/vendor ./vendor
COPY . .
RUN mkdir -p storage/app storage/logs storage/framework/cache storage/framework/views \
 && chown -R www-data:www-data storage
EXPOSE 80
