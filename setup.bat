@echo off
chcp 65001 >nul
title DeltaPOS Edge Box Installer
echo =====================================
echo   DeltaPOS Edge Box Installer
echo =====================================
echo.

:: Kiểm tra Administrator
net session >nul 2>&1
if %errorLevel% neq 0 (
    echo [!] Vui long chay voi quyen Administrator
    pause
    exit /b 1
)

set "ROOT=%~dp0"

:: Cấu hình .env nếu chưa có
if not exist "%ROOT%.env" (
    echo [1/6] Cau hinh .env...
    copy "%ROOT%.env.example" "%ROOT%.env" >nul
    echo OK
    echo.
    echo [+] Mo file .env bang notepad, sua cac thong tin:
    echo     STORE_ID=...     ID cua store
    echo     CLOUD_API_URL=...  VD: https://api-pos-test.vietvang.net
    echo     API_KEY=...        Key tu cloud
    echo.
    notepad "%ROOT%.env"
    echo.
    pause
)

:: Cài PHP + Composer
echo [2/6] Cai dat dependencies...
cd /d "%ROOT%"
php artisan key:generate --force 2>nul
composer install --no-dev --quiet 2>nul
echo OK

:: Database
echo [3/6] Tao database...
del /q database\database.sqlite 2>nul
php artisan migrate --force --quiet
echo OK

:: Sync master data tu cloud
echo [4/6] Dong bo du lieu...
php artisan edge:sync-master --quiet
echo OK

:: Tao services tu dong
echo [5/6] Cai dat auto-start...

set "PS_FILE=%ROOT%run-edgebox.ps1"
echo $loop = $true > "%PS_FILE%"
echo while (^$loop^) { >> "%PS_FILE%"
echo     try { >> "%PS_FILE%"
echo         php artisan serve --host=0.0.0.0 --port=8000 --quiet 2>&1 ^| Out-Null >> "%PS_FILE%"
echo     } catch { >> "%PS_FILE%"
echo         Start-Sleep -Seconds 3 >> "%PS_FILE%"
echo     } >> "%PS_FILE%"
echo } >> "%PS_FILE%"

set "PS_SYNC=%ROOT%run-sync.ps1"
echo $loop = $true > "%PS_SYNC%"
echo while (^$loop^) { >> "%PS_SYNC%"
echo     try { >> "%PS_SYNC%"
echo         php artisan sync:worker --daemon --quiet 2^>^&1 ^| Out-Null >> "%PS_SYNC%"
echo     } catch { >> "%PS_SYNC%"
echo         Start-Sleep -Seconds 3 >> "%PS_SYNC%"
echo     } >> "%PS_SYNC%"
echo } >> "%PS_SYNC%"

:: Tao batch file de start ca 2
echo @echo off > "%ROOT%start.bat"
echo cd /d "%ROOT%" >> "%ROOT%start.bat"
echo start "EdgeBox-Server" /MIN php artisan serve --host=0.0.0.0 --port=8000 >> "%ROOT%start.bat"
echo start "EdgeBox-Sync" /MIN php artisan sync:worker --daemon >> "%ROOT%start.bat"
echo exit >> "%ROOT%start.bat"

:: Them vao Startup
copy "%ROOT%start.bat" "%APPDATA%\Microsoft\Windows\Start Menu\Programs\Startup\" >nul
echo OK

:: Khoi dong
echo [6/6] Khoi dong Edge Box...
start /MIN php artisan serve --host=0.0.0.0 --port=8000
start /MIN php artisan sync:worker --daemon

timeout /t 3 >nul
curl -s http://localhost:8000/api/health >nul 2>&1
if %errorLevel% equ 0 (
    echo.
    echo =====================================
    echo   CAI DAT THANH CONG!
    echo =====================================
    echo.
    echo  Edge Box URL: http://192.168.1.%COMPUTERNAME%
    echo  Tiep theo: Cai dat cloud admin:
    echo    - deployment_mode = offline-first
    echo    - edge_routing_active = true
    echo    - edge_box_url = http://192.168.1.XXX:8000
    echo.
    echo  Edge Box tu dong chay khi khoi dong may
    echo.
) else (
    echo [!] Loi: Edge Box khong khoi dong duoc
    echo    Kiem tra log: storage\logs\laravel.log
)

pause
