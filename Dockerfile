FROM php:8.3-apache

RUN apt-get update \
  && apt-get install -y --no-install-recommends \
    ca-certificates \
    default-mysql-client \
    libcurl4-openssl-dev \
    libonig-dev \
    libzip-dev \
    unixodbc-dev \
    gnupg2 \
    curl \
    unzip \
    zip \
  && docker-php-ext-install curl mbstring mysqli pdo_mysql zip \
  && a2enmod headers rewrite

# Repositorio Microsoft
RUN curl -fsSL https://packages.microsoft.com/keys/microsoft.asc \
    | gpg --dearmor -o /usr/share/keyrings/microsoft-prod.gpg \
  && echo "deb [arch=amd64 signed-by=/usr/share/keyrings/microsoft-prod.gpg] https://packages.microsoft.com/debian/12/prod bookworm main" \
    > /etc/apt/sources.list.d/microsoft-prod.list

# Driver ODBC para SQL Server
RUN apt-get update \
  && ACCEPT_EULA=Y apt-get install -y msodbcsql18

# Extensiones PHP para SQL Server
RUN pecl install sqlsrv pdo_sqlsrv \
  && docker-php-ext-enable sqlsrv pdo_sqlsrv

# Limpieza
RUN apt-get clean \
  && rm -rf /var/lib/apt/lists/*

COPY docker/apache-reportes.conf /etc/apache2/conf-available/reportes.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/reportes.ini

RUN a2enconf reportes

WORKDIR /var/www/html

COPY . /var/www/html

RUN chown -R www-data:www-data /var/www/html