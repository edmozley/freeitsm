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

# Create directories for uploads, attachments, and encryption keys
RUN mkdir -p /var/www/html/tickets/attachments \
    /var/www/html/change-management/attachments \
    /var/www/encryption_keys \
    && chown -R www-data:www-data /var/www/html /var/www/encryption_keys \
    && chmod -R 755 /var/www/html \
    && chmod 700 /var/www/encryption_keys

# Copy entrypoint script (auto-generates encryption key on first boot)
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80

ENTRYPOINT ["entrypoint.sh"]
