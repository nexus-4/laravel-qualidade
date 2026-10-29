ARG PHP_VERSION=8.4
FROM php:${PHP_VERSION}-cli

COPY --from=mlocati/php-extension-installer:2.7.31 /usr/bin/install-php-extensions /usr/local/bin/

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions pdo_mysql pdo_pgsql gd zip exif intl bcmath pcntl sockets redis pcov @composer \
    && git config --system --add safe.directory '*'

COPY php/qualidade.ini /usr/local/etc/php/conf.d/zz-qualidade.ini

RUN mkdir -p /tools/rector /tools/infection \
    && composer --working-dir=/tools/rector require --no-interaction --no-progress \
        rector/rector:2.8.1 driftingly/rector-laravel:2.7.3 phpstan/phpstan:2.3.3 \
    && echo '{}' > /tools/infection/composer.json \
    && composer --working-dir=/tools/infection config --no-plugins allow-plugins.infection/extension-installer true \
    && composer --working-dir=/tools/infection require --no-interaction --no-progress infection/infection:0.29.14 \
    && chmod -R a+rX /tools

ENV PATH="/tools/rector/vendor/bin:/tools/infection/vendor/bin:${PATH}"

WORKDIR /app
