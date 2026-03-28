FROM php:8.2-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libzip-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_mysql zip gd \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY docker/php/php.ini /usr/local/etc/php/conf.d/local-dev.ini
COPY docker/php/start.sh /usr/local/bin/start-local-server
RUN sed -i 's/\r$//' /usr/local/bin/start-local-server \
    && chmod +x /usr/local/bin/start-local-server

EXPOSE 80

CMD ["start-local-server"]
