# syntax=docker/dockerfile:1.4

FROM php:apache

LABEL org.opencontainers.image.authors="webmaster@stadtplan-ilmenau.de"

COPY checkout.sh /var/www/
COPY commit.sh /var/www/
COPY create.php /var/www/
COPY src/editor.php /var/www/html/

RUN mkdir /var/www/.ssh && \
    chown www-data /var/www/.ssh && \
    mkdir /var/www/.git && \
    chown www-data /var/www/.git && \
    mkdir /var/www/tmp && \
    chown www-data /var/www/tmp && \
    apt-get update && \
    apt-get install --yes git

