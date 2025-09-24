FROM php:8.3-cli

ARG USER_ID=1000
ARG GROUP_ID=1000

RUN apt-get update \
     && apt-get install -y --no-install-recommends \
         git zip unzip libssl-dev libzip-dev \
     && docker-php-ext-install pcntl sockets \
     && pecl install xdebug \
     && docker-php-ext-enable xdebug \
     && rm -rf /var/lib/apt/lists/*

# Disable xdebug by default (enable via XDEBUG_MODE env when needed)
RUN echo "xdebug.mode=off" > /usr/local/etc/php/conf.d/docker-xdebug.ini \
 && echo "memory_limit=512M" > /usr/local/etc/php/conf.d/memory-limit.ini

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Create non-root user (optional)
RUN groupadd -g ${GROUP_ID} appgroup \
    && useradd -m -u ${USER_ID} -g appgroup appuser || true

USER appuser

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    PATH="/app/vendor/bin:$PATH"

CMD ["bash"]
