FROM webkul/unopim:1.0.1

RUN docker-php-ext-configure pcntl --enable-pcntl \
  && docker-php-ext-install pcntl;

ENTRYPOINT ["/var/www/html/dockerfiles/web-entrypoint.sh"]
