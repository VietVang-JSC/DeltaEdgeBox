@echo off
setlocal enabledelayedexpansion

set APP_DIR=%~1\edge-box
set PHP_DIR=%~1\php
set NSSM_DIR=%~1\nssm
set DB_DIR=%~1\database
set PATH=%PHP_DIR%;%NSSM_DIR%;%PATH%

echo =========================================
echo   DeltaPOS Edge Box Post-Install
echo =========================================
echo.

:: 1. Create database directory
if not exist "%DB_DIR%" mkdir "%DB_DIR%"
echo [1/8] Database directory ready

:: 2. Create storage directories
if not exist "%APP_DIR%\storage\logs" mkdir "%APP_DIR%\storage\logs"
if not exist "%APP_DIR%\storage\app\public\product-images" mkdir "%APP_DIR%\storage\app\public\product-images"
if not exist "%APP_DIR%\storage\framework\cache" mkdir "%APP_DIR%\storage\framework\cache"
if not exist "%APP_DIR%\storage\framework\sessions" mkdir "%APP_DIR%\storage\framework\sessions"
if not exist "%APP_DIR%\storage\framework\views" mkdir "%APP_DIR%\storage\framework\views"
echo [2/8] Storage directories ready

:: 3. Generate app key
cd /d "%APP_DIR%"
php artisan key:generate --force
echo [3/8] App key generated

:: 4. Create storage link
php artisan storage:link --force 2>nul
echo [4/8] Storage link created

:: 5. Run migrations
php artisan migrate --force
echo [5/8] Database migrated

:: 6. Download cacert.pem for SSL
if not exist "%PHP_DIR%\cacert.pem" (
    curl -sL -o "%PHP_DIR%\cacert.pem" https://curl.se/ca/cacert.pem
    echo [6/8] SSL certificates downloaded
) else (
    echo [6/8] SSL certificates found
)

:: 7. Install Windows services with NSSM
echo [7/8] Installing Windows services...

:: EdgeBoxWeb - PHP built-in server
nssm install EdgeBoxWeb "php.exe" "-S 0.0.0.0:8000 -t public"
nssm set EdgeBoxWeb AppDirectory "%APP_DIR%"
nssm set EdgeBoxWeb AppParameters "-S 0.0.0.0:8000 -t public"
nssm set EdgeBoxWeb DisplayName "DeltaPOS Edge Box - Web Server"
nssm set EdgeBoxWeb Description "Serves POS API and management UI"
nssm set EdgeBoxWeb Start SERVICE_AUTO_START
nssm set EdgeBoxWeb AppStdout "%APP_DIR%\storage\logs\web.log"
nssm set EdgeBoxWeb AppStderr "%APP_DIR%\storage\logs\web-error.log"
nssm set EdgeBoxWeb AppEnvironmentExtra "PATH=%PHP_DIR%;%PATH%"
echo   + EdgeBoxWeb installed

:: EdgeBoxPrint - Print worker
nssm install EdgeBoxPrint "php.exe" "artisan print:worker --daemon"
nssm set EdgeBoxPrint AppDirectory "%APP_DIR%"
nssm set EdgeBoxPrint AppParameters "artisan print:worker --daemon"
nssm set EdgeBoxPrint DisplayName "DeltaPOS Edge Box - Print Worker"
nssm set EdgeBoxPrint Description "Processes print queue"
nssm set EdgeBoxPrint Start SERVICE_AUTO_START
nssm set EdgeBoxPrint AppStdout "%APP_DIR%\storage\logs\print.log"
nssm set EdgeBoxPrint AppStderr "%APP_DIR%\storage\logs\print-error.log"
nssm set EdgeBoxPrint AppRotateFiles 1
nssm set EdgeBoxPrint AppRotateOnline 1
nssm set EdgeBoxPrint AppRotateSeconds 86400
nssm set EdgeBoxPrint AppRotateBytes 10485760
echo   + EdgeBoxPrint installed

:: EdgeBoxSchedule - Schedule worker
nssm install EdgeBoxSchedule "php.exe" "artisan schedule:work"
nssm set EdgeBoxSchedule AppDirectory "%APP_DIR%"
nssm set EdgeBoxSchedule AppParameters "artisan schedule:work"
nssm set EdgeBoxSchedule DisplayName "DeltaPOS Edge Box - Scheduler"
nssm set EdgeBoxSchedule Description "Runs scheduled tasks (master sync, backup)"
nssm set EdgeBoxSchedule Start SERVICE_AUTO_START
nssm set EdgeBoxSchedule AppStdout "%APP_DIR%\storage\logs\schedule.log"
nssm set EdgeBoxSchedule AppStderr "%APP_DIR%\storage\logs\schedule-error.log"
nssm set EdgeBoxSchedule AppRotateFiles 1
nssm set EdgeBoxSchedule AppRotateOnline 1
nssm set EdgeBoxSchedule AppRotateSeconds 86400
nssm set EdgeBoxSchedule AppRotateBytes 10485760
echo   + EdgeBoxSchedule installed

:: 8. Configure firewall
echo [8/8] Configuring firewall...
netsh advfirewall firewall add rule name="DeltaPOS Edge Box (HTTP)" dir=in action=allow protocol=TCP localport=8000 >nul 2>nul
echo   + Firewall port 8000 opened

:: Start services
echo.
echo Starting services...
nssm start EdgeBoxWeb >nul 2>nul
nssm start EdgeBoxPrint >nul 2>nul
nssm start EdgeBoxSchedule >nul 2>nul

echo.
echo =========================================
echo   Installation Complete!
echo.
echo   Open: http://localhost:8000/edge-manager
echo =========================================
