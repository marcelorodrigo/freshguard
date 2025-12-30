# PHP application stage
FROM marcelorodrigo/freshguard:latest

# Install PHP dependencies (production only)
RUN composer install --optimize-autoloader --no-dev

RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html/storage \
    && chmod -R 755 /var/www/html/bootstrap/cache

# Install and build frontend assets
RUN npm ci && npm run build

# Expose port
EXPOSE 80