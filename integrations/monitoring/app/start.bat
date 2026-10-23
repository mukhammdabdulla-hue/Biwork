@echo off
rem Biwork Monitoring: Windows'da ishga tushirish (PHP 8.1+ kerak: pdo_sqlite, mbstring, sodium)
cd /d "%~dp0"
if "%PORT%"=="" set PORT=8090
echo Biwork Monitoring: http://localhost:%PORT%
php -S 0.0.0.0:%PORT% -t public public/router.php
