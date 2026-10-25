FROM php:8.5-cli

# PIE is the replacement for pecl. The :bin image only contains pie.phar.
COPY --from=ghcr.io/php/pie:1.5-bin /pie /usr/local/bin/pie

RUN set -eux; \
    export DEBIAN_FRONTEND=noninteractive; \
    apt-get update; \
    apt-get install -y --no-install-recommends libtool unzip; \
    pie install --no-cache --skip-enable-extension 'xdebug/xdebug:^3.5'; \
    docker-php-ext-enable xdebug; \
    apt-get purge -y --auto-remove libtool unzip; \
    rm -rf /var/lib/apt/lists/* /root/.pie /root/.cache/pie /usr/local/bin/pie

COPY xdebug.ini /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini

RUN XDEBUG_MODE=off php -r 'exit(extension_loaded("xdebug") ? 0 : 1);'

WORKDIR /app
EXPOSE 8000
CMD ["php", "-S", "0.0.0.0:8000", "-t", "www/"]
