# PHP-FPM de produção do Map-OS SaaS (PHP 8.5, como pede o projeto).
FROM php:8.5-fpm-bookworm

COPY --from=ghcr.io/mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions mysqli pdo_mysql intl gd zip bcmath opcache

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY php.ini "$PHP_INI_DIR/conf.d/zz-mapos.ini"
COPY fpm.conf /usr/local/etc/php-fpm.d/zz-mapos.conf

COPY entrypoint.sh /usr/local/bin/mapos-entrypoint
COPY cron.sh /usr/local/bin/mapos-cron
RUN chmod 0755 /usr/local/bin/mapos-entrypoint /usr/local/bin/mapos-cron

ENTRYPOINT ["mapos-entrypoint"]
CMD ["php-fpm"]
