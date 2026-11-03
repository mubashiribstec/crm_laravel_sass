# 1. Base PHP image (8.3: PHP 8.2's security support ends on 31 Dec 2026)
FROM php:8.3-fpm-bookworm

# 2. Set working directory
WORKDIR /var/www/crm

# 3. System packages, Node.js 22 LTS (Debian's own nodejs/npm are not installed, so Node is
#    installed once) and the PHP extensions. poppler-utils gives pdftotext, which the opt-in
#    Xplogent CV excerpt uses to read PDF CVs.
RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates curl gnupg git zip unzip \
        libzip-dev libpq-dev libonig-dev libxml2-dev \
        libpng-dev libjpeg-dev libfreetype6-dev libicu-dev libxslt-dev \
        poppler-utils \
    && curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && docker-php-ext-install pdo pdo_mysql zip intl xml gd mbstring xsl \
    && rm -rf /var/lib/apt/lists/*

# 4. Install Composer
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

# 5. Copy project files
COPY . .

# 6. Install PHP dependencies
RUN composer install --no-interaction --prefer-dist --optimize-autoloader

# 7. Install and build frontend
RUN npm install && npm run build

# PHP's default upload limit (2 MB) is below the CV limit the app validates (10 MB).
RUN printf "upload_max_filesize=12M\npost_max_size=16M\n" > /usr/local/etc/php/conf.d/uploads.ini

# 8. Set permissions
RUN chown -R www-data:www-data /var/www/crm \
    && chmod -R 775 /var/www/crm/storage /var/www/crm/bootstrap/cache

# 9. Expose PHP-FPM port
EXPOSE 9000

# 10. Start PHP-FPM
CMD ["php-fpm"]
