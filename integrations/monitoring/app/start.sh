#!/usr/bin/env bash
# Biwork Monitoring — kompyuterda ishga tushirish (PHP 8.1+: pdo_sqlite, mbstring, sodium)
cd "$(dirname "$0")"
PORT="${PORT:-8090}"
echo "Biwork Monitoring: http://localhost:$PORT"
exec php -S "0.0.0.0:$PORT" -t public public/router.php
