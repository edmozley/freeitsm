FROM php:8.4-apache

# PHP extensions beyond the ones PHP builds in - the list, and why each one, is
# docker/php-extensions.txt (GH #207: until 4.0.0 this installed only pdo_mysql,
# so LDAP sign-in, SCORM upload and .docx reading could not work in the image).
#
# The -dev packages are only needed to compile. The apt-mark dance is the one
# the official PHP images use: after the build, keep exactly the runtime
# libraries the new extensions link against, and remove everything else.
# ⚠️ The awk, not `cut -d:`: on Debian trixie dpkg-query --search also prints
# "diversion by X from: /path" lines, and cut turned those into package names
# apt-get could not find. awk keeps only the "package:" lines.
RUN set -eux; \
    savedAptMark="$(apt-mark showmanual)"; \
    apt-get update; \
    apt-get install -y --no-install-recommends libldap-dev libzip-dev libicu-dev; \
    docker-php-ext-configure ldap --with-libdir="lib/$(dpkg-architecture --query DEB_HOST_MULTIARCH)"; \
    docker-php-ext-install -j"$(nproc)" pdo_mysql ldap zip intl; \
    apt-mark auto '.*' > /dev/null; \
    [ -z "$savedAptMark" ] || apt-mark manual $savedAptMark; \
    find /usr/local -type f -executable -exec ldd '{}' ';' \
        | awk '/=>/ { so = $(NF-1); if (index(so, "/usr/local/") == 1) { next }; gsub("^/(usr/)?", "", so); printf "*%s\n", so }' \
        | sort -u | xargs -r dpkg-query --search | awk 'sub(":$", "", $1) { print $1 }' | sort -u | xargs -r apt-mark manual; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false; \
    rm -rf /var/lib/apt/lists/*

# 🔴 The smoke check (GH #207). Every extension in the list must load, or the
# build stops here - for both architectures the release workflow builds - and
# nothing is published.
COPY docker/php-extensions.txt /usr/local/etc/freeitsm-php-extensions.txt
RUN php -r '$need = []; foreach (file("/usr/local/etc/freeitsm-php-extensions.txt") as $l) { $l = trim(preg_replace("/#.*/", "", $l)); if ($l !== "") $need[] = $l; } $missing = array_values(array_filter($need, function ($e) { return !extension_loaded($e); })); if ($missing) { fwrite(STDERR, "Missing PHP extensions: " . implode(", ", $missing) . "\n"); exit(1); } if (!function_exists("ldap_start_tls")) { fwrite(STDERR, "ldap loaded without StartTLS\n"); exit(1); } echo "PHP extensions OK: " . implode(", ", $need) . "\n";'

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Set the document root
ENV APACHE_DOCUMENT_ROOT=/var/www/html

# 🔴 How the application knows it is in a container, without reading the disk.
#
# includes/storage_persistence.php raises the issue #109 warning — the one that
# tells an operator their uploads are about to be destroyed by the next
# `docker compose up -d --build`. It decided this by looking for /.dockerenv at
# the root of the filesystem, which a host with `open_basedir` set will refuse
# (GH #127). A refused read must never be mistaken for "not a container", or the
# warning disappears for the very people who need it.
#
# ⚠️ DO NOT REMOVE. Without it, container detection depends entirely on being
# allowed to read the root of the filesystem.
ENV FREEITSM_CONTAINER=1
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

# HTTPS site for the certificate made on System -> Docker. Installed but NOT
# enabled: entrypoint.sh turns it (and mod_ssl) on at start only when a valid
# certificate is present in /var/www/tls.
COPY docker/apache-ssl.conf /etc/apache2/sites-available/freeitsm-ssl.conf

# Behind a reverse proxy doing HTTPS: make Apache's own redirects keep https.
# The site includes the file only if it exists, and entrypoint.sh puts it there
# only when TRUST_PROXY_HTTPS is set - see the file (GH #152).
COPY docker/apache-proxy.conf /etc/apache2/freeitsm-proxy.conf.available
RUN sed -i 's#^\(\s*\)DocumentRoot .*#&\n\1IncludeOptional /etc/apache2/freeitsm-proxy.conf#' /etc/apache2/sites-available/000-default.conf \
    && grep -q freeitsm-proxy.conf /etc/apache2/sites-available/000-default.conf

# Create directories for uploads, attachments, encryption keys and certificates.
# Every folder docker-compose.yml puts a volume on is made here, so it exists and
# is www-data's in the image - a new volume copies both, and one on a folder the
# image lacks would be root's, which PHP cannot write to.
RUN mkdir -p /var/www/html/tickets/attachments \
    /var/www/html/change-management/attachments \
    /var/www/html/uploads/files \
    /var/www/html/recordings \
    /var/www/html/lms/content \
    /var/www/html/contracts/rfp-builder/uploads \
    /var/www/html/system/uploads/branding \
    /var/www/html/system/uploads/label-fonts \
    /var/www/html/war-room/attachments \
    /var/www/html/forms/images \
    /var/www/encryption_keys \
    /var/www/tls \
    && chown -R www-data:www-data /var/www/html /var/www/encryption_keys /var/www/tls \
    && chmod -R 755 /var/www/html \
    && chmod 700 /var/www/encryption_keys /var/www/tls

# Copy entrypoint script (auto-generates encryption key on first boot)
# sed strips Windows CRLF line endings that break bash in Linux
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint.sh && chmod +x /usr/local/bin/entrypoint.sh

# tini as process 1 (#233). The built-in scheduler starts each background job as
# its own detached process, and a detached process whose parent has gone is handed
# to process 1 to collect when it ends. Apache is not written to be process 1, so
# without tini every finished job could linger as a zombie. tini also passes
# Docker's stop signal on to Apache.
RUN apt-get update && apt-get install -y --no-install-recommends tini && rm -rf /var/lib/apt/lists/*

EXPOSE 80 443

ENTRYPOINT ["tini", "--", "entrypoint.sh"]
