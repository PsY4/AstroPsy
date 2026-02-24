#!/bin/bash
set -e

echo "Starting ASCOM Alpaca server ..."
gunicorn alpaca_server:app \
    --bind 0.0.0.0:11111 \
    --workers 1 \
    --threads 8 \
    --timeout 120 \
    --keep-alive 30 \
    --graceful-timeout 10 \
    --worker-class gthread \
    --log-level info &
ALPACA_PID=$!

echo "ASCOM Alpaca server started on port 11111"
wait -n
exit $?