FROM php:8.3-apache

# System deps + PHP extensions (zip is required for Composer dist downloads)
RUN apt-get update \
 && apt-get install -y --no-install-recommends unzip git libzip-dev \
 && docker-php-ext-install pdo pdo_mysql zip \
 && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite headers

WORKDIR /var/www/html

# Silences the 'Could not reliably determine the server's fully qualified domain name' warning
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Cache-friendly script installation
COPY start.sh /start.sh
RUN chmod +x /start.sh

# Install the S3-compatible client used to create temporary private-image URLs.
ENV COMPOSER_ALLOW_SUPERUSER=1
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
# composer.loc[k] makes the lock file optional (matches zero or one file)
COPY ./foodbyk/composer.json ./foodbyk/composer.loc[k] ./foodbyk/
RUN composer install --working-dir=/var/www/html/foodbyk --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts

# Core directory transfers
COPY ./foodbyk/ ./foodbyk/
COPY ./public/ ./public/

# Rebuild optimized autoloader now that app source is present
RUN composer dump-autoload --optimize --no-dev --working-dir=/var/www/html/foodbyk

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