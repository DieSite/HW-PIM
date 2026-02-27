FROM webkul/unopim:1.0.1

RUN apt-get update; \
    apt-get install -y libmagickwand-dev; \
    pecl install imagick; \
    docker-php-ext-enable imagick;


RUN docker-php-ext-configure pcntl --enable-pcntl \
  && docker-php-ext-install pcntl \
  && docker-php-ext-install exif;

ENTRYPOINT ["/var/www/html/dockerfiles/q-entrypoint.sh"]
