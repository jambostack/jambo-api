# JamboApi CMS — Dockerfile production
# Build: docker build -t jamboapi .
# Run:   docker run -p 8080:80 -e DATABASE_URL=... jamboapi

FROM php:8.4-fpm-alpine AS php-base

RUN apk add --no-cache \
    icu-dev libpng-dev libjpeg-turbo-dev freetype-dev \
    nginx supervisor nodejs npm mysql-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
    pdo_mysql intl gd opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php.ini /usr/local/etc/php/conf.d/jamboapi.ini
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

WORKDIR /app

# Copier tout le code source d'abord (nécessaire pour le build assets)
COPY . .

# Composer (vendor/ exclu via .dockerignore)
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# NPM build (node_modules/ exclu via .dockerignore)
RUN npm ci && npm run build

# Warmup Symfony
RUN php bin/console cache:warmup --env=prod

EXPOSE 80
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
