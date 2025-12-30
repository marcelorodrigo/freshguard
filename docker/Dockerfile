# PHP application stage
FROM php:8.5.1-fpm-alpine3.23 AS base

# Install system dependencies and PHP extensions
RUN apk add --no-cache \
    freetype-dev \
    gifsicle \
    git \
    icu-dev \
    jpegoptim \
    libjpeg-turbo-dev \
    libpng-dev \
    libzip-dev \
    mysql-client \
    nginx \
    nodejs \
    npm \
    oniguruma-dev \
    optipng \
    pngquant \
    supervisor \
    unzip \
    zip \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install exif gd intl pdo_mysql zip

# Set working directory
WORKDIR /var/www/html

# Configure Nginx
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/default.conf /etc/nginx/http.d/default.conf

# Configure Supervisor
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Install Composer
COPY --from=composer/composer:latest /usr/bin/composer /usr/bin/composer

# Expose port
EXPOSE 80

# Start Supervisor
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf", "-n"]