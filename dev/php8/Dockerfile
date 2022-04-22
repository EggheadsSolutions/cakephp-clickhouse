FROM php:8.0-fpm

RUN apt-get update && apt-get install -y \
        curl \
        wget \
        mc \
        git \
        libonig-dev \
        libzip-dev \
        libpng-dev \
        libcurl4-gnutls-dev \
        libicu-dev \
        libxml2-dev \
        libsqlite3-dev \
        locales \
        locales-all \
        zip \
    && locale-gen ru_RU.UTF-8 \
    && docker-php-ext-install -j$(nproc) iconv mbstring mysqli pdo_mysql zip \
    && docker-php-ext-configure gd \
    && docker-php-ext-install -j$(nproc) gd  \
    && docker-php-ext-install -j$(nproc) intl soap xml opcache bcmath pdo_sqlite pcntl \
    && docker-php-ext-configure pcntl --enable-pcntl \
    && docker-php-ext-install -j$(nproc) pcntl \
    && pecl install xdebug && docker-php-ext-enable xdebug \
    && pecl install runkit7-alpha && docker-php-ext-enable runkit7 \
    && pecl install redis && docker-php-ext-enable redis \
    && rm -rf /tmp/pear \
    && rm -frv /var/cache/apk/*

# Куда же без composer'а.
RUN  curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Для тестирования sass
RUN curl -fsSL https://deb.nodesource.com/setup_current.x | bash - \
    && apt-get install -y nodejs \
    && npm install -g sass \
    npm install uglify-es -g

# Настройка PHP
RUN echo "post_max_size = 200M" > /usr/local/etc/php/conf.d/40-custom.ini \
    && echo "upload_max_filesize = 200M" >> /usr/local/etc/php/conf.d/40-custom.ini \
    && echo "date.timezone = Europe/Moscow" >> /usr/local/etc/php/conf.d/40-custom.ini \
    && echo "xdebug.mode = debug" >> /usr/local/etc/php/conf.d/40-custom.ini \
    && echo "xdebug.log = \"/var/www/dev/php/xdebug.log\"" >> /usr/local/etc/php/conf.d/40-custom.ini \
    && echo "memory_limit = -1" >> /usr/local/etc/php/conf.d/40-custom.ini

ENV LANG ru_RU.UTF-8
ENV LANGUAGE ru_RU:ru
ENV LC_ALL ru_RU.UTF-8

# Указываем рабочую директорию для PHP
WORKDIR /var/www
RUN usermod -u 1000 www-data && groupmod -g 1000 www-data
# Запускаем контейнер
# Из документации: The main purpose of a CMD is to provide defaults for an executing container. These defaults can include an executable,
# or they can omit the executable, in which case you must specify an ENTRYPOINT instruction as well.
CMD ["php-fpm"]
# Пересборка контейнера
# docker-compose build php
