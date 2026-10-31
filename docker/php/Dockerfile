FROM php:8.5-fpm

# poppler-utils (pdftotext) and LibreOffice read the text out of uploaded PDF, Word and Pages files.
RUN apt-get update && apt-get install -y \
    git unzip libzip-dev libicu-dev \
    poppler-utils libreoffice-writer-nogui \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install pdo_mysql zip intl bcmath

COPY uploads.ini /usr/local/etc/php/conf.d/uploads.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
