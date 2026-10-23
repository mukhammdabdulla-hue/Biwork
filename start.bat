@echo off
rem Biwork portalini Windows'da mahalliy ishga tushirish.
rem   start.bat               -> http://localhost:8090
rem   set PORT=9000 ^& start.bat -> boshqa port
chcp 65001 >nul
setlocal
cd /d "%~dp0"
if not defined PORT set "PORT=8090"

where php >nul 2>nul
if errorlevel 1 (
  echo XATO: php topilmadi. PHP 8.1+ o'rnating va PATH ga qo'shing.
  pause
  exit /b 1
)
php -m | findstr /i /r /c:"^sodium$" >nul || (echo XATO: PHP 'sodium' kengaytmasi kerak ^(php.ini: extension=sodium^). & pause & exit /b 1)
php -m | findstr /i /r /c:"^pdo_sqlite$" >nul || (echo XATO: PHP 'pdo_sqlite' kengaytmasi kerak. & pause & exit /b 1)

if not exist portal\data mkdir portal\data
if "%1"=="--demo" php tools\cli.php seed:demo

echo.
echo Birinchi sozlash: brauzerda http://localhost:%PORT%/setup ni oching.
echo O'rnatish kaliti: portal\data\setup_key.txt ^(sahifa birinchi ochilganda yaratiladi^).
echo.
echo Biwork portali: http://localhost:%PORT%   ^(To'xtatish: Ctrl+C^)
echo.
cd portal
php -d upload_max_filesize=64M -d post_max_size=64M -S 127.0.0.1:%PORT% -t public public\router.php
