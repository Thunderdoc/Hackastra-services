# Hackstra — production image (Laravel 8 on PHP 8.4, Apache)
# Used by Railway (and any Docker host). See docs/DEPLOYMENT.md.
# PHP 8.4 is required: composer.lock resolves symfony/css-selector v8.1.6,
# which needs PHP >= 8.4.1. On PHP 8.2 the composer install succeeds only
# with platform checks ignored, and Laravel's platform_check then fails
# at boot (build log 2026-10-21). Do not downgrade this image.
FROM php:8.4-apache

# System packages + PHP extensions the app and its packages need
# (audit §3.1: pdo_mysql, mbstring, fileinfo, tokenizer, xml, ctype, curl, zip, gd)
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libpng-dev libzip-dev libxml2-dev libonig-dev \
    && docker-php-ext-install pdo_mysql mbstring zip xml gd bcmath exif pcntl fileinfo \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Production PHP settings: hide deprecation notices from pages (legacy
# libraries on PHP 8.4 print them into responses otherwise).
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Composer (official binary)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_MEMORY_LIMIT=-1

WORKDIR /var/www/html

# Install PHP dependencies first (better layer caching)
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader --no-scripts \
    || composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader --no-scripts --ignore-platform-reqs

# Application code
COPY . .

RUN composer dump-autoload --optimize \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Apache: serve Laravel's public/ folder, allow rewrites, respect $PORT (Railway)
RUN (a2dismod mpm_event mpm_worker || true) \
    && a2enmod mpm_prefork rewrite \
    && sed -i 's|/var/www/html|/var/www/html/public|g' /etc/apache2/sites-available/000-default.conf \
    && sed -i 's|/var/www/|/var/www/html/public|g' /etc/apache2/apache2.conf \
    && sed -i 's|Listen 80|Listen ${PORT}|' /etc/apache2/ports.conf \
    && sed -i 's|<VirtualHost \*:80>|<VirtualHost *:${PORT}>|' /etc/apache2/sites-available/000-default.conf

# Laravel needs .htaccess rewrites inside public/: Debian's default is
# AllowOverride None, which makes every route except / an Apache 404.
RUN printf '<Directory /var/www/html/public>\n    AllowOverride All\n    Require all granted\n</Directory>\nServerName localhost\n' > /etc/apache2/conf-available/laravel.conf \
    && a2enconf laravel
ENV PORT=8080
EXPOSE 8080

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]
