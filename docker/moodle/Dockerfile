FROM php:8.1-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev libjpeg62-turbo-dev libfreetype6-dev libicu-dev libzip-dev \
        libxml2-dev unzip curl ca-certificates \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" mysqli gd intl zip soap exif opcache \
    && rm -rf /var/lib/apt/lists/*

COPY php.ini /usr/local/etc/php/conf.d/moodle.ini

# Moodle 4.5 (branch MOODLE_405_STABLE, build semanal mais recente — mesma linha 4.5.x+ da FAI)
ARG MOODLE_TGZ=https://download.moodle.org/download.php/direct/stable405/moodle-latest-405.tgz
RUN rm -rf /var/www/html \
    && curl -fsSL "$MOODLE_TGZ" -o /tmp/moodle.tgz \
    && tar -xzf /tmp/moodle.tgz -C /var/www \
    && mv /var/www/moodle /var/www/html \
    && rm /tmp/moodle.tgz \
    && chown -R www-data:www-data /var/www/html

# Pacote de idioma pt_br (copiado para o moodledata na 1ª subida)
RUN mkdir -p /opt/langpacks \
    && curl -fsSL https://download.moodle.org/download.php/direct/langpack/4.5/pt_br.zip -o /opt/langpacks/pt_br.zip

# Scripts da integração CRM (setup idempotente e simulação de prova)
COPY setup.php simular_prova.php /opt/fai/
RUN sed -i 's/\r$//' /opt/fai/setup.php /opt/fai/simular_prova.php \
    && mkdir -p /opt/fai/output && chown www-data:www-data /opt/fai/output

COPY entrypoint.sh /usr/local/bin/moodle-entrypoint
RUN sed -i 's/\r$//' /usr/local/bin/moodle-entrypoint && chmod +x /usr/local/bin/moodle-entrypoint

WORKDIR /var/www/html
ENTRYPOINT ["moodle-entrypoint"]
CMD ["web"]
