# PHP application stage
FROM php:8.5.1-fpm-alpine3.23

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
COPY nginx.conf /etc/nginx/nginx.conf
COPY default.conf /etc/nginx/http.d/default.conf

# Configure Supervisor
COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Metadata labels
LABEL maintainer="Marcelo Rodrigo <mrodrigow@gmail.com>" \
      org.opencontainers.image.source="https://github.com/marcelorodrigo/freshguard" \
      org.opencontainers.image.title="FreshGuard" \
      org.opencontainers.image.description="Home inventory management system built using Laravel, Filament and Tailwind"

# Expose HTTP and PHP-FPM ports
EXPOSE 80 9000

# Default command: supervisord manages PHP-FPM and Nginx
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
