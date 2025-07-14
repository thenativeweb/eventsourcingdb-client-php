ARG PHP_VERSION=8.2

FROM php:${PHP_VERSION}-cli

WORKDIR /thenativeweb

RUN apt-get update \
    && apt-get install -y curl docker.io unzip \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

RUN sed -i 's/^# export/export/' /root/.bashrc && \
    sed -i 's/^# eval/eval/' /root/.bashrc && \
    sed -i 's/^# alias l/alias l/' /root/.bashrc
