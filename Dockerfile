############################################
# Stage 1: Build frontend assets
############################################
FROM node:24-alpine AS assets
WORKDIR /app

# Copy only manifests to cache dependency install
COPY package.json package-lock.json ./

# Use BuildKit cache for npm packages (recommended)
# This command requires BuildKit. It caches npm artifacts under /root/.npm.
RUN --mount=type=cache,target=/root/.npm \
    npm ci --ignore-scripts --no-progress

# Now copy the files required by the build (you noted these are required)
COPY vite.config.js ./
COPY resources/ resources/

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
# Stage 3: Composer
############################################
# composer deps stage — inherits extensions from base
FROM base AS php-deps
WORKDIR /var/www/html
# copy composer manifests only
COPY composer.json composer.lock ./
USER root
RUN --mount=type=cache,target=/root/.composer \
    composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist --no-progress

############################################
# Stage 4: Production image
############################################
FROM base AS production

# Copy application code with correct ownership
COPY --chown=www-data:www-data . /var/www/html

# copy vendor and built assets from builder stages
COPY --from=php-deps --chown=www-data:www-data /var/www/html/vendor /var/www/html/vendor
COPY --from=assets --chown=www-data:www-data /app/public/build /var/www/html/public/build

# Ensure storage and cache directories exist and are writable
USER root
RUN mkdir -p /var/www/html/storage/logs \
             /var/www/html/storage/framework/cache/data \
             /var/www/html/storage/framework/sessions \
             /var/www/html/storage/framework/views \
             /var/www/html/bootstrap/cache \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

USER www-data

# fpm-nginx listens on port 8080 (HTTP) and 8443 (HTTPS) by default
EXPOSE 8080 8443