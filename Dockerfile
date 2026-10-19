# JamboApi CMS — Dockerfile production
# Build: docker build -t jamboapi .
# Run:   docker run -p 8080:80 -e DATABASE_URL=... jamboapi

# ── Étape 1 : Compilation des assets frontend (Node.js) ──
FROM node:20-alpine AS frontend-builder

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY webpack.config.js tsconfig.json postcss.config.cjs* ./
COPY assets ./assets

RUN npm run build

# ── Étape 2 : Image runtime PHP-FPM & Nginx ──
FROM php:8.4-fpm-alpine AS final

RUN apk add --no-cache \
    icu-dev libpng-dev libjpeg-turbo-dev freetype-dev \
    nginx supervisor \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
    pdo_mysql intl gd opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php.ini /usr/local/etc/php/conf.d/jamboapi.ini
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

WORKDIR /app

# Copier le code source de l'application
COPY . .

# Copier les assets compilés depuis l'étape frontend
COPY --from=frontend-builder /app/public/build ./public/build

# Installer les dépendances PHP sans scripts (pour éviter warmup sans DB)
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts

# Configuration des permissions pour cache, logs et uploads
RUN mkdir -p var/cache var/log public/uploads/media/cache \
    && chown -R www-data:www-data var public/uploads

# Warmup du cache Symfony avec un environnement de build isolé
RUN APP_ENV=prod APP_DEBUG=0 DATABASE_URL="sqlite:///:memory:" php bin/console cache:warmup --env=prod

EXPOSE 80
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
