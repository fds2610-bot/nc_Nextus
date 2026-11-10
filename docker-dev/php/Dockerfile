# Nextcloud-DEV-Image mit älterer PHP-Version (Kompatibilitätstest, z. B. PHP 8.3).
# Die offiziellen nextcloud-Images gibt es nur mit der jeweils neuesten PHP-Version
# (34/35: PHP 8.5). Dieses Image übernimmt Nextcloud-Quellen, Entrypoint und
# Konfiguration aus dem offiziellen Image und setzt sie auf php:<PHP>-apache.
#
# Bau über ../../dev.sh --php 8.3 (oder von Hand):
#   docker build --build-arg NC=35 --build-arg PHP=8.3 -t nc-nexus/nextcloud:35-php8.3 .
ARG NC=35
ARG PHP=8.3

FROM nextcloud:${NC}-apache AS nc

FROM php:${PHP}-apache

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/

RUN set -eux; \
	apt-get update; \
	apt-get install -y --no-install-recommends rsync bzip2 busybox-static; \
	rm -rf /var/lib/apt/lists/*; \
	install-php-extensions apcu bcmath exif gd gmp intl opcache pcntl pdo_mysql pdo_pgsql redis sysvsem zip

COPY --from=nc /usr/src/nextcloud /usr/src/nextcloud
COPY --from=nc /entrypoint.sh /cron.sh /upgrade.exclude /
COPY --from=nc /usr/local/etc/php/conf.d/nextcloud.ini /usr/local/etc/php/conf.d/opcache-recommended.ini /usr/local/etc/php/conf.d/
COPY --from=nc /etc/apache2/conf-available/apache-limits.conf /etc/apache2/conf-available/remoteip.conf /etc/apache2/conf-available/
COPY --from=nc /var/spool/cron/crontabs/www-data /var/spool/cron/crontabs/www-data

RUN set -eux; \
	echo 'apc.enable_cli=1' >> /usr/local/etc/php/conf.d/docker-php-ext-apcu.ini; \
	a2enmod headers rewrite remoteip env dir mime; \
	a2enconf remoteip apache-limits; \
	mkdir -p /var/www/data; \
	chown -R www-data:root /var/www; \
	chmod -R g=u /var/www

ENV PHP_MEMORY_LIMIT=512M \
	PHP_UPLOAD_LIMIT=512M \
	PHP_OPCACHE_MEMORY_CONSUMPTION=128 \
	APACHE_BODY_LIMIT=1073741824

VOLUME /var/www/html
ENTRYPOINT ["/entrypoint.sh"]
CMD ["apache2-foreground"]
