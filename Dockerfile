# 1. Base PHP image
FROM php:8.2-fpm

# 2. Set working directory
WORKDIR /var/www/crm

# 3. Install system packages and PHP extensions
RUN apt-get update && apt-get install -y \
    git curl zip unzip libzip-dev libpq-dev libonig-dev libxml2-dev \
    libpng-dev libjpeg-dev libfreetype6-dev \
    libmcrypt-dev libicu-dev libxslt-dev \
    nodejs npm gnupg \
    && docker-php-ext-install pdo pdo_mysql zip intl xml gd mbstring xsl

# 4. Optional: Install Node.js 18 LTS (more stable than default Debian)
RUN curl -fsSL https://deb.nodesource.com/setup_18.x | bash - \
    && apt-get install -y nodejs

# 5. Install Composer
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

# 6. Copy project files
COPY . .

# 7. Install PHP dependencies
RUN composer install --no-interaction --prefer-dist --optimize-autoloader

# 8. Install and build frontend
RUN npm install && npm run build

# 9. Set permissions
RUN chown -R www-data:www-data /var/www/crm \
    && chmod -R 775 /var/www/crm/storage /var/www/crm/bootstrap/cache

# 10. Expose PHP-FPM port
EXPOSE 9000

# 11. Start PHP-FPM
CMD ["php-fpm"]
