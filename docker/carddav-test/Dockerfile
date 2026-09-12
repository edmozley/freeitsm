# Baikal, built from the OFFICIAL release zip and pinned to an exact version.
#
# 🔴 WHY WE BUILD THIS OURSELVES INSTEAD OF PULLING A COMMUNITY IMAGE
#
# `ckulka/baikal` is the image everyone points at, and its `latest` / `nginx`
# tags ship **Baikal 0.10.1** — two releases behind. Its newest version tag is
# 0.10.1 as well, so there is no 0.12.x to switch to: the repository has stopped
# following upstream even though the images themselves are still rebuilt weekly
# (the rebuild refreshes the base OS, not Baikal). There is no official
# sabre-io image on Docker Hub or ghcr.
#
# ⚠️ And 0.10.1 is not merely old. **0.12.1 fixes a security vulnerability in
# Baikal** — an authenticated user could take over the admin interface by
# renaming their calendar (reported by Aleksandar Djordjevic). Even for a
# throwaway fixture there is no reason to run the vulnerable one.
#
# So: the official zip, a recorded checksum, and a version we control.
FROM php:8.3-apache

ARG BAIKAL_VERSION=0.12.1
# sha256 of baikal-0.12.1.zip as published on the GitHub release, verified
# before this file was written. A changed checksum means the artefact changed
# under us and the build MUST fail rather than carry on.
ARG BAIKAL_SHA256=0449abb72b151d39d9c08c63cb83a05d9e9adb065b1165ef6786b0b6a13d203c

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends unzip curl ca-certificates; \
    rm -rf /var/lib/apt/lists/*; \
    # Baikal stores everything in SQLite by default. The official PHP images
    # build pdo_sqlite in already, but assert it rather than assume — a missing
    # driver surfaces as an unhelpful failure halfway through the install wizard.
    php -m | grep -qx pdo_sqlite; \
    php -m | grep -qx dom; \
    php -m | grep -qx mbstring

RUN set -eux; \
    curl -fsSL -o /tmp/baikal.zip \
      "https://github.com/sabre-io/Baikal/releases/download/${BAIKAL_VERSION}/baikal-${BAIKAL_VERSION}.zip"; \
    echo "${BAIKAL_SHA256}  /tmp/baikal.zip" | sha256sum -c -; \
    unzip -q /tmp/baikal.zip -d /var/www; \
    rm /tmp/baikal.zip; \
    # The zip extracts to `baikal/`, holding Core, html, config, Specific and a
    # pre-built vendor — so there is no composer step.
    test -f /var/www/baikal/html/dav.php; \
    chown -R www-data:www-data /var/www/baikal/config /var/www/baikal/Specific

# `html/` is the docroot and carries its own .htaccess, so AllowOverride has to
# be on or the DAV routing silently 404s.
RUN set -eux; \
    a2enmod rewrite; \
    printf '%s\n' \
      '<VirtualHost *:80>' \
      '    DocumentRoot /var/www/baikal/html' \
      '    <Directory /var/www/baikal/html>' \
      '        AllowOverride All' \
      '        Require all granted' \
      '    </Directory>' \
      '</VirtualHost>' > /etc/apache2/sites-available/000-default.conf

EXPOSE 80
