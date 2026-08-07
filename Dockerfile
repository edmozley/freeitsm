FROM php:8.4-apache

# Enable required PHP extensions
RUN docker-php-ext-install pdo pdo_mysql

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Set the document root
ENV APACHE_DOCUMENT_ROOT=/var/www/html
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Allow .htaccess overrides
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Copy application files
COPY . /var/www/html/

# Copy Docker-specific config files into place
COPY docker/config.php /var/www/html/config.php
COPY docker/db_config.php /var/www/html/db_config.php

# Session cookie hardening. The image previously shipped no php.ini at all, so PHP's
# compiled defaults applied — cookie_httponly=0, cookie_samesite="" and, worst of the
# three, use_strict_mode=0, which makes PHP adopt an attacker-supplied session ID.
# This base image is mod_php, which does NOT read .user.ini, so the file has to be
# dropped into conf.d rather than relying on the copy in the app root.
COPY docker/php.ini /usr/local/etc/php/conf.d/freeitsm.ini

# Create directories for uploads, attachments, and encryption keys
RUN mkdir -p /var/www/html/tickets/attachments \
    /var/www/html/change-management/attachments \
    /var/www/encryption_keys \
    && chown -R www-data:www-data /var/www/html /var/www/encryption_keys \
    && chmod -R 755 /var/www/html \
    && chmod 700 /var/www/encryption_keys

# Copy entrypoint script (auto-generates encryption key on first boot)
# sed strips Windows CRLF line endings that break bash in Linux
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint.sh && chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80

ENTRYPOINT ["entrypoint.sh"]
