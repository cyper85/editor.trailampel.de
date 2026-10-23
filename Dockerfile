# syntax=docker/dockerfile:1.4

FROM php:apache

LABEL org.opencontainers.image.authors="webmaster@stadtplan-ilmenau.de"

COPY lib.php /var/www/
COPY create.php /var/www/
COPY src/editor.php /var/www/html/
