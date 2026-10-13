FROM php:8.1-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev libjpeg62-turbo-dev libfreetype6-dev libicu-dev libzip-dev \
        libxml2-dev unzip git curl ca-certificates locales \
    && echo "en_AU.UTF-8 UTF-8" >> /etc/locale.gen && locale-gen \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" mysqli gd intl zip soap exif opcache \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY php.ini /usr/local/etc/php/conf.d/moodle.ini

# Moodle 4.5 (branch MOODLE_405_STABLE, build semanal mais recente — mesma linha 4.5.x+ da FAI)
ARG MOODLE_TGZ=https://download.moodle.org/download.php/direct/stable405/moodle-latest-405.tgz
RUN rm -rf /var/www/html \
    && curl -fsSL "$MOODLE_TGZ" -o /tmp/moodle.tgz \
    && tar -xzf /tmp/moodle.tgz -C /var/www \
    && mv /var/www/moodle /var/www/html \
    && rm /tmp/moodle.tgz

# Dependências do composer do Moodle, inclusive as de desenvolvimento (PHPUnit; ele exige o locale en_AU acima)
RUN cd /var/www/html && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --prefer-dist --no-progress \
    && composer clear-cache \
    && mkdir -p /var/www/.composer && chown www-data:www-data /var/www/.composer  # o init do PHPUnit roda o composer como www-data

# User key authentication (auth_userkey 2022081902, usado pelo SSO da spec 006). O commit 56867f7 é byte a byte
# o zip oficial do Moodle Plugins directory (o mesmo da produção da FAI; SHA-256 7b53c60b…2820d).
# WITH_AUTH_USERKEY=0 gera um Moodle sem ele, para testar a instalação pelo painel.
ARG WITH_AUTH_USERKEY=1
ARG AUTH_USERKEY_COMMIT=56867f70e6fc1c7b9c3822fac87f3df8d5bb49fe
RUN if [ "$WITH_AUTH_USERKEY" = 1 ]; then \
        curl -fsSL "https://github.com/catalyst/moodle-auth_userkey/archive/$AUTH_USERKEY_COMMIT.tar.gz" -o /tmp/userkey.tgz \
        && mkdir -p /var/www/html/auth/userkey \
        && tar -xzf /tmp/userkey.tgz -C /var/www/html/auth/userkey --strip-components=1 \
        && rm /tmp/userkey.tgz \
        && grep -q 2022081902 /var/www/html/auth/userkey/version.php; \
    fi \
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
