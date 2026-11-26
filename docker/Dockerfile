FROM php:8.4-apache@sha256:95c1a1dfaaffef4bcfee563838c50620457a3c00196c4bf5ddb66934d707fea2

# Same UID/GID as the host user so the mounted storage stays writable from the host.
ARG APP_UID=1000
ARG APP_GID=1000
ARG IMAGE_VERSION=0.1.0-beta.2
ARG IMAGE_REVISION=unknown

LABEL org.opencontainers.image.title="42bash Invoice Bridge" \
      org.opencontainers.image.description="Self-hosted bridge from Invoice Ninja to French e-invoicing, starting with SuperPDP." \
      org.opencontainers.image.source="https://github.com/42bash/invoice-bridge" \
      org.opencontainers.image.url="https://www.42bash.com" \
      org.opencontainers.image.licenses="Apache-2.0" \
      org.opencontainers.image.vendor="42bash" \
      org.opencontainers.image.version="${IMAGE_VERSION}" \
      org.opencontainers.image.revision="${IMAGE_REVISION}"
RUN usermod -u ${APP_UID} www-data && groupmod -g ${APP_GID} www-data

# CVE-2026-124851. The pinned php:8.4-apache digest still ships libzstd1
# 1.5.7+dfsg-1. Debian security currently publishes 1.5.7+dfsg-1+deb13u1.
# The package name is not pinned to that revision: the next image build
# takes the security candidate, including a later fix. The image scan still
# fails when a high or critical vulnerability has a published fix.
RUN apt-get update && apt-get install -y --no-install-recommends \
        libicu-dev libzip-dev libsqlite3-dev sqlite3 unzip curl \
        libzstd1 \
    && docker-php-ext-install -j"$(nproc)" intl zip bcmath pdo_sqlite opcache \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2@sha256:0049ce90b432795bf413a738b073963ada1a689e1a8708104684baafe94586ec /usr/bin/composer /usr/bin/composer
COPY docker/vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-einvoicing.ini
RUN a2enmod rewrite headers

WORKDIR /var/www/html

COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --no-progress

COPY --chown=www-data:www-data . .
RUN composer dump-autoload --optimize --no-dev \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs storage/app \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/einvoicing-entrypoint
RUN chmod +x /usr/local/bin/einvoicing-entrypoint

HEALTHCHECK --interval=60s --timeout=10s --start-period=30s --retries=3 \
    CMD curl -fsS http://127.0.0.1/up || exit 1

EXPOSE 80
ENTRYPOINT ["einvoicing-entrypoint"]
CMD ["apache2-foreground"]
