# Hackstra — production image (Laravel 8 on PHP 8.2, Apache)
# Used by Railway (and any Docker host). See docs/DEPLOYMENT.md.
FROM php:8.2-apache

# System packages + PHP extensions the app and its packages need
# (audit §3.1: pdo_mysql, mbstring, fileinfo, tokenizer, xml, ctype, curl, zip, gd)
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libpng-dev libzip-dev libxml2-dev libonig-dev \
    && docker-php-ext-install pdo_mysql mbstring zip xml gd bcmath exif pcntl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Composer (official binary)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Install PHP dependencies first (better layer caching)
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader --no-scripts

# Application code
COPY . .

RUN composer dump-autoload --optimize \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Apache: serve Laravel's public/ folder, allow rewrites, respect $PORT (Railway)
RUN a2enmod rewrite \
    && sed -i 's|/var/www/html|/var/www/html/public|g' /etc/apache2/sites-available/000-default.conf \
    && sed -i 's|/var/www/|/var/www/html/public|g' /etc/apache2/apache2.conf \
    && sed -i 's|Listen 80|Listen ${PORT}|' /etc/apache2/ports.conf \
    && sed -i 's|<VirtualHost \*:80>|<VirtualHost *:${PORT}>|' /etc/apache2/sites-available/000-default.conf
ENV PORT=8080
EXPOSE 8080

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]
