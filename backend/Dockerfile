FROM php:8.3-apache

RUN docker-php-ext-install pdo pdo_mysql

RUN a2enmod rewrite headers

RUN php -v && php -m | grep -i pdo

WORKDIR /var/www/html

# Move these lines up here to break the Docker build cache
COPY start.sh /start.sh
RUN chmod +x /start.sh

COPY foodbyk/ ./foodbyk/
COPY public/ ./public/

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
