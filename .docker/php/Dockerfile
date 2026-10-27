FROM php:8.1-cli

ARG UID=1000
ARG GID=1000

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libzip-dev libonig-dev libsqlite3-dev \
    && docker-php-ext-install pdo_mysql pdo_sqlite mbstring zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY .docker/php/php.ini /usr/local/etc/php/conf.d/zz-comment-engine.ini

WORKDIR /app

RUN groupadd --gid "${GID}" developer \
    && useradd --uid "${UID}" --gid developer --create-home developer \
    && git config --system --add safe.directory /app

USER developer

CMD ["tail", "-f", "/dev/null"]
