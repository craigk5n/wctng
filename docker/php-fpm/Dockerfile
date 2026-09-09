FROM php:8.3-fpm-alpine

# Install system dependencies
RUN apk add --no-cache \
    bash \
    icu-dev \
    libzip-dev \
    oniguruma-dev \
    mysql-client \
    make \
    openssl \
    # git: Infection's --git-diff-lines shells out to it, and Composer needs it
    # to resolve the webcalendar-core VCS repository without the GitHub API.
    git

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

# pcov rather than xdebug: the only thing needed here is line coverage, for
# `make coverage` and for Infection to know which tests touch which lines.
# pcov is a fraction of xdebug's overhead and, unlike xdebug, costs nothing
# when disabled. Separate layer so it does not invalidate the layers above.
RUN apk add --no-cache --virtual .pcov-build $PHPIZE_DEPS \
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && apk del .pcov-build

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
