FROM php:8.2-apache

RUN apt-get update \
  && apt-get install -y --no-install-recommends \
    ca-certificates \
    default-mysql-client \
    libcurl4-openssl-dev \
    libonig-dev \
    libzip-dev \
    unzip \
    zip \
  && docker-php-ext-install curl mbstring mysqli pdo_mysql zip \
  && a2enmod headers rewrite \
  && rm -rf /var/lib/apt/lists/*

COPY docker/apache-reportes.conf /etc/apache2/conf-available/reportes.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/reportes.ini

RUN a2enconf reportes

WORKDIR /var/www/html

COPY . /var/www/html

RUN chown -R www-data:www-data /var/www/html
