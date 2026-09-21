#!/bin/sh
set -eu

: "${PORT:=10000}"
envsubst '${PORT}' < /etc/nginx/templates/default.conf.template > /etc/nginx/conf.d/default.conf

php-fpm -F &
exec nginx -g 'daemon off;'
