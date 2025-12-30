# PHP application stage
FROM marcelorodrigo/freshguard

# Copy application code
COPY . /var/www/html

# Create Laravel required directories
RUN mkdir -p storage/framework/cache/data \
    && mkdir -p storage/framework/sessions \
    && mkdir -p storage/framework/testing \
    && mkdir -p storage/framework/views \
    && mkdir -p storage/logs \
    && mkdir -p bootstrap/cache

# Install PHP dependencies (production only)
RUN composer install --optimize-autoloader --no-dev

RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html/storage \
    && chmod -R 755 /var/www/html/bootstrap/cache

# Install and build frontend assets
RUN npm ci && npm run build

# Expose port
EXPOSE 80