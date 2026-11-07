# Image Docker untuk VPS/uji lokal (PHP 8.4 + FrankenPHP). Isinya sama dengan Dockerfile.vercel.
FROM dunglas/frankenphp:1-php8.4-alpine AS base
WORKDIR /app
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && install-php-extensions curl mongodb
ENV PORT=80

FROM base AS dependencies
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN apk add --no-cache unzip
COPY composer.json ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader

FROM base AS runtime
COPY --from=dependencies /app/vendor ./vendor
COPY Caddyfile /etc/caddy/Caddyfile
COPY app ./app
COPY config ./config
# Document root: aset statis (css/js/gambar) + front controller PHP.
COPY public ./public
COPY server/index.php ./public/index.php
# Halaman utama dibaca oleh PHP dari root proyek (di luar document root).
COPY index.html ./index.html
COPY api.php health.php ./
RUN mkdir -p /app/data && chown -R www-data:www-data /app/data
EXPOSE 80
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile", "--adapter", "caddyfile"]
