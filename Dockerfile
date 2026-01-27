# syntax=docker/dockerfile:1
############################################
# Stage 1: Build frontend assets
############################################
FROM node:24-alpine AS assets
WORKDIR /app

# Copy package files for better layer caching
COPY package*.json ./

# Install dependencies
RUN --mount=type=cache,id=npm-cache,target=/root/.npm \
  npm ci --ignore-scripts --cache /root/.npm --prefer-offline --no-audit --progress=false

# Copy source files needed for Vite/Tailwind build
COPY resources/ resources/
COPY vite.config.js ./

# Build production assets
RUN npm run build

############################################
# Stage 2: PHP base with extensions
############################################
FROM serversideup/php:8.5-fpm-nginx-alpine AS base

# Switch to root to install extensions
USER root

# Install required PHP extensions for Laravel + Filament
# - intl: Internationalization (Laravel/Filament)
# - gd: Image processing (Filament file uploads)
# - exif: Image metadata (Filament)
# - zip: ZIP archives (Filament, installed by default)
# - pdo_mysql: MariaDB/MySQL driver (installed by default)
RUN install-php-extensions intl gd exif

# Switch back to unprivileged user
USER www-data

############################################
# Stage 3: Composer Install
############################################
FROM base AS composer
WORKDIR /var/www/html
ENV COMPOSER_CACHE_DIR=/var/www/.composer/cache
# Copy composer files for better layer caching
COPY --chown=www-data:www-data composer.json composer.lock ./
# Install Composer dependencies
RUN --mount=type=cache,id=composer-cache,target=/var/www/.composer/cache \
  composer install --optimize-autoloader --no-dev --no-interaction --no-progress --no-scripts

############################################
# Stage 4: Production image
############################################
FROM base AS production

# Copy application code with correct ownership
COPY --chown=www-data:www-data . /var/www/html

# Copy built assets from Node stage (overwrites source public/build)
COPY --from=assets --chown=www-data:www-data /app/public/build /var/www/html/public/build

# Copy vendor from composer layer for dependency cache
COPY --from=composer /var/www/html/vendor /var/www/html/vendor

# Ensure storage and cache directories exist and are writable
USER root
RUN mkdir -p /var/www/html/storage/logs \
             /var/www/html/storage/framework/cache/data \
             /var/www/html/storage/framework/sessions \
             /var/www/html/storage/framework/views \
             /var/www/html/bootstrap/cache \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Install Composer dependencies (production only)
USER www-data
RUN composer install --optimize-autoloader --no-dev --no-interaction --no-progress

# fpm-nginx listens on port 8080 (HTTP) and 8443 (HTTPS) by default
EXPOSE 8080 8443