FROM php:8.2-fpm-alpine

# Install Git
RUN apk update && apk upgrade && \
	apk add git

RUN docker-php-ext-install pdo pdo_mysql mysqli ftp

#Install INTL Extension
RUN apk add icu-dev
RUN docker-php-ext-configure intl && docker-php-ext-install intl

#Install GD Extension
RUN apk add --no-cache freetype-dev libpng-dev libjpeg-turbo-dev
RUN docker-php-ext-configure gd --with-freetype=/usr/include/ --with-jpeg=/usr/include/
RUN docker-php-ext-install -j$(nproc) gd

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

# Add custom PHP configuration
COPY .docker/php/php.ini /usr/local/etc/php/conf.d/

WORKDIR /srv/
