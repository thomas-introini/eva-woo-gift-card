# File: Dockerfile
FROM wordpress:php8.1-apache

RUN apt-get update && apt-get install -y \
    less \
    default-mysql-client \
    git \
    unzip \
    sudo \
  && rm -rf /var/lib/apt/lists/*

RUN curl -o /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
  && chmod +x /usr/local/bin/wp

RUN docker-php-ext-install mysqli && docker-php-ext-enable mysqli


