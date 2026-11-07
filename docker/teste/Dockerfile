# PHP da versão exigida pelo projeto (^8.5), só com o que os testes usam.
FROM php:8.5-cli-bookworm

COPY --from=ghcr.io/mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

RUN install-php-extensions mysqli pdo_mysql intl gd zip bcmath
