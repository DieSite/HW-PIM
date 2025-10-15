FROM webkul/unopim:1.0.1

RUN docker-php-ext-install exif

ENTRYPOINT ["/var/www/html/dockerfiles/q-entrypoint.sh"]
