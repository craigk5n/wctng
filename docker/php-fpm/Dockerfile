FROM php:8.3-fpm-alpine

# Install system dependencies
RUN apk add --no-cache \
    bash \
    icu-dev \
    libzip-dev \
    oniguruma-dev \
    mysql-client \
    make \
    openssl

# Install PHP extensions
RUN docker-php-ext-install \
    intl \
    pdo \
    pdo_mysql \
    zip \
    mbstring \
    opcache

# LDAP is optional at runtime — every call site guards on
# function_exists('ldap_connect') — but static analysis needs the extension
# present to see LDAP_OPT_* and friends. CI's PHP ships it, so without it here
# Psalm produces a different result locally than in CI (UndefinedConstant plus
# the MixedArgument it cascades into), which desynchronises the baseline.
# Separate layer so it does not invalidate the intl/pdo build above.
RUN apk add --no-cache openldap-dev \
    && docker-php-ext-install ldap

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# PHP configuration for development
RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

# Custom PHP-FPM config
COPY php-fpm.conf /usr/local/etc/php-fpm.d/zz-custom.conf

WORKDIR /var/www/html/webcalendar-api

# Run as www-data
USER www-data

EXPOSE 9000

CMD ["php-fpm"]
