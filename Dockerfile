FROM php:8.3-apache

RUN apt-get update && apt-get install -y \
    git unzip cron libzip-dev \
    && docker-php-ext-install pdo pdo_mysql zip \
    && a2enmod rewrite


COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .

RUN composer install --no-dev --optimize-autoloader

COPY docker/crontab /etc/cron.d/max-bot-cron
RUN chmod 0644 /etc/cron.d/max-bot-cron

RUN mkdir -p /var/www/html/logs && chown -R www-data:www-data /var/www/html/logs

CMD service cron start && apache2-foreground
