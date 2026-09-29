FROM php:8.3-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends unzip git libzip-dev \
 && docker-php-ext-install pdo pdo_mysql zip \
 && rm -rf /var/lib/apt/lists/*
RUN a2enmod rewrite headers

WORKDIR /var/www/html

RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

COPY start.sh /start.sh
RUN chmod +x /start.sh

ENV COMPOSER_ALLOW_SUPERUSER=1
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY ./foodbyk/composer.json ./foodbyk/composer.lock ./foodbyk/
RUN composer install --working-dir=/var/www/html/foodbyk --no-dev --no-interaction --prefer-dist --optimize-autoloader


# Core directory transfers
COPY ./foodbyk/ ./foodbyk/
COPY ./public/ ./public/

RUN sed -i 's|DocumentRoot /var/www/html|DocumentRoot /var/www/html/public|g' \
    /etc/apache2/sites-available/000-default.conf

RUN printf '<Directory /var/www/html/public>\n\tAllowOverride All\n\tRequire all granted\n</Directory>\n' \
    >> /etc/apache2/apache2.conf

RUN mkdir -p /var/www/html/public/uploads/products \
    && chown -R www-data:www-data /var/www/html/public/uploads \
    && chmod -R 775 /var/www/html/public/uploads \
    && chown www-data:www-data /var/www/html/public \
    && chmod 775 /var/www/html/public

EXPOSE 80

ENTRYPOINT ["/start.sh"]
