FROM php:8.3-fpm-alpine AS base

# Install system dependencies
RUN apk add --no-cache \
    bash \
    icu-dev \
    libzip-dev \
    oniguruma-dev

# Install PHP extensions
RUN docker-php-ext-install \
    intl \
    pdo \
    pdo_mysql \
    zip \
    mbstring \
    opcache

# PHP production config
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

WORKDIR /var/www/html/webcalendar-api

# ---

FROM base AS build

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copy composer files first for better layer caching
COPY composer.json composer.lock ./

# Install dependencies (no dev)
RUN composer install --no-dev --no-scripts --no-interaction --optimize-autoloader

# Copy application code
COPY . .

# Run post-install scripts
RUN composer run-script auto-scripts --no-interaction || true

# ---

FROM base AS production

# Copy built application from build stage
COPY --from=build /var/www/html/webcalendar-api /var/www/html/webcalendar-api

# Ensure var directory is writable
RUN chown -R www-data:www-data var/

USER www-data

EXPOSE 9000

CMD ["php-fpm"]
