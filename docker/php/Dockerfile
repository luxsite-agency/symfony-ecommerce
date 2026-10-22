FROM php:8.4-fpm

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libicu-dev libzip-dev libpng-dev libjpeg-dev libwebp-dev libfreetype-dev \
        default-mysql-client \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install -j"$(nproc)" intl pdo_mysql zip opcache gd bcmath \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY dev.ini /usr/local/etc/php/conf.d/zz-dev.ini

# працюємо від uid/gid власника файлів на dev-сервері (alex:devs), щоб не плодити root-файли у спільній теці
ARG UID=501
ARG GID=3000
RUN groupadd -g ${GID} devs && useradd -m -u ${UID} -g ${GID} app \
    && sed -i -e 's/^user = .*/user = app/' -e 's/^group = .*/group = devs/' /usr/local/etc/php-fpm.d/www.conf
USER app

WORKDIR /app
