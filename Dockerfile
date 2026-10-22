# HAXiam production-style image (proof of concept).
#
# Ubuntu 24.04 + Apache (mpm_event) + PHP 8.3-fpm. The HAXcms core and the
# composer vendor/ tree are baked in at BUILD time so first boot does not need
# GitHub or packagist. Everything instance-specific (config, salt, credentials,
# users and their sites) lives in volumes so the image can be replaced to
# upgrade without losing data.
#
# Build:   docker build -t haxiam:poc .
# Run:     docker run -p 8080:80 -e HAXIAM_DOMAIN=example.localhost:8080 \
#            -e HAXIAM_PROTOCOL=http haxiam:poc
# See docker/entrypoint.sh for the supported environment variables.

FROM ubuntu:24.04

# Branch/tag of haxcms-php to bake in as cores/HAXcms-1.x.x.
ARG HAXCMS_REF=master

ENV DEBIAN_FRONTEND=noninteractive \
    TZ=Etc/UTC \
    LANG=C.UTF-8 \
    LC_ALL=C.UTF-8

# Includes everything haxiam-install.sh phase 2 checks for (including certbot)
# so that phase is a no-op at runtime and needs no network.
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
        apache2 \
        apache2-utils \
        brotli \
        ca-certificates \
        certbot \
        curl \
        git \
        php8.3-cli \
        php8.3-common \
        php8.3-curl \
        php8.3-fpm \
        php8.3-gd \
        php8.3-mbstring \
        php8.3-xml \
        php8.3-yaml \
        php8.3-zip \
        python3 \
        python3-certbot-apache \
        tzdata \
        unzip \
        uuid-runtime \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Apache + php-fpm wiring (same modules the installer enables). The vhost
# itself is written by the installer on start.
RUN set -eux; \
    a2dismod mpm_prefork || true; \
    a2enmod mpm_event proxy_fcgi http2 ssl rewrite headers brotli; \
    a2enconf php8.3-fpm || true; \
    mkdir -p /run/php; \
    sed -i 's|^listen = .*|listen = /run/php/php8.3-fpm.sock|' /etc/php/8.3/fpm/pool.d/www.conf

WORKDIR /var/www/iam
COPY . /var/www/iam

# Bake the HAXcms core and composer deps. The installer skips the clone when
# the core directory already exists and skips composer when vendor/ is newer
# than composer.json.
RUN git clone --depth 1 --branch "${HAXCMS_REF}" \
        https://github.com/haxtheweb/haxcms-php.git cores/HAXcms-1.x.x \
 && composer install --no-dev --no-interaction --no-progress \
 && mkdir -p _iamConfig cores/HAXcms-1.x.x/_config \
 && chown www-data:www-data users users_sites cores/HAXcms-1.x.x/_config \
 && chmod 0755 docker/entrypoint.sh

# Persistent state. Named volumes are seeded from these paths on first use.
#  _iamConfig                    instance config (config.cfg, azure.json, salt, htpasswd)
#  cores/HAXcms-1.x.x/_config    per-instance core config (credentials, JWT material)
#  users, users_sites            user spaces and the sites inside them
VOLUME ["/var/www/iam/_iamConfig", "/var/www/iam/cores/HAXcms-1.x.x/_config", "/var/www/iam/users", "/var/www/iam/users_sites"]

EXPOSE 80

# Any HTTP response (including 3xx/401) means Apache + php-fpm are up.
HEALTHCHECK --interval=15s --timeout=5s --retries=6 --start-period=60s \
  CMD curl -s -o /dev/null --max-time 3 http://127.0.0.1/login.php || exit 1

ENTRYPOINT ["/var/www/iam/docker/entrypoint.sh"]
CMD ["serve"]
