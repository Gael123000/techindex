#!/bin/sh
# Recharge Nginx toutes les 6 heures pour prendre en compte les certificats
# renouvelés par le service certbot (sans interruption de service).
if [ "${TLS_MODE:-off}" != "off" ]; then
    nohup sh -c 'while :; do sleep 21600; nginx -s reload; done' >/dev/null 2>&1 &
fi
