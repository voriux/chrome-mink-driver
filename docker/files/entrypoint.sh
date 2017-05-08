#!/usr/bin/env bash

umask 000

/usr/bin/supervisord -n -c /etc/supervisord.conf > /dev/null 2>&1 &

if [[ $# -eq 1 && $1 == "bash" ]]; then
    $@
else
    exec "$@"
fi
