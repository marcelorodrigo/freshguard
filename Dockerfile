FROM node:24-alpine AS assets
WORKDIR /data
COPY package.json package-lock.json vite.config.js ./
RUN npm ci --ignore-scripts --omit=dev
COPY resources ./resources
RUN npm run build

# PHP application stage
FROM php:8.5-fpm-alpine AS base

# Install system dependencies and PHP extensions
RUN apk add --no-cache \
    curl \
    freetype-dev \
    gifsicle \
    git \
    jpegoptim \
    libjpeg-turbo-dev \
    libpng-dev \
    nginx \
    optipng \
    pngquant \
    supervisor \
    sqlite \
    unzip \
    vim \
    zip \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install pdo pdo_sqlite mbstring exif pcntl bcmath gd

# Install Composer
COPY --from=composer/composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy composer files
COPY composer.json composer.lock ./

# Install PHP dependencies (production only)
RUN composer install --no-dev --optimize-autoloader --no-scripts

# Copy application code
COPY . .

# Copy built assets from Node.js stage
COPY --from=assets /data/public/build ./public/build

RUN composer deploy \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html/storage \
    && chmod -R 755 /var/www/html/bootstrap/cache

# Configure Nginx
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/default.conf /etc/nginx/http.d/default.conf

# Configure Supervisor
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Expose port
EXPOSE 80

# Start Supervisor
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]