@echo off
chcp 65001 >nul
title DeltaPOS Edge Box - Cai Dat
mode con cols=70 lines=30
echo ============================================================
echo        DELTAPOS EDGE BOX - CAI DAT TU DONG
echo ============================================================
echo.
echo  File nay se cai dat Edge Box hoan chinh.
echo  Co the dung offline neu co san file package.zip
echo  hoac can internet de tai ve.
echo.
echo  Nhan Ctrl+C de huy bat ky luc nao.
echo.

:: Kiem tra Administrator
net session >nul 2>&1
if %errorLevel% neq 0 (
    echo [!] Vui long chay voi quyen Administrator
    echo     Chuot phai file nay -^> Run as administrator
    pause
    exit /b 1
)

:: Nhap thong tin
echo.
set /p STORE_ID="Nhap STORE ID (VD: 84): "
set /p LOCAL_IP="Nhap IP may nay (VD: 192.168.1.100): "
if "%LOCAL_IP%"=="" set "LOCAL_IP=localhost"

:: Kiem tra package co san khong
set "PKG_SOURCE=%~dp0"
if exist "%PKG_SOURCE%package.zip" (
    echo  Tim thay package.zip trong thu muc hien tai.
    set "USE_LOCAL=1"
) else (
    echo  Khong co package.zip, se tai tu cloud...
    set "USE_LOCAL=0"
)

set "TEMP_DIR=%TEMP%\DeltaPOS-EdgeBox"

if "%USE_LOCAL%"=="1" (
    :: Giai nen tu local
    echo  Giai nen package...
    rmdir /s /q "%TEMP_DIR%" 2>nul
    mkdir "%TEMP_DIR%" 2>nul
    powershell -Command "Expand-Archive -Path '%PKG_SOURCE%package.zip' -DestinationPath 'C:\DeltaPOS-EdgeBox' -Force" 2>nul
) else (
    :: Tai tu cloud
    rmdir /s /q "%TEMP_DIR%" 2>nul
    mkdir "%TEMP_DIR%" 2>nul
    set "PKG_URL=https://github.com/deltapos/edge-box/releases/latest/download/package.zip"
    echo  Dang tai... (co the mat vai phut)
    powershell -Command "try { Invoke-WebRequest -Uri '%PKG_URL%' -OutFile '%TEMP_DIR%\package.zip' -UseBasicParsing -TimeoutSec 120 } catch { exit 1 }" 2>nul
    if %errorLevel% neq 0 (
        echo [!] Khong the tai package. Kiem tra internet.
        pause
        exit /b 1
    )
    echo  Giai nen...
    powershell -Command "Expand-Archive -Path '%TEMP_DIR%\package.zip' -DestinationPath 'C:\DeltaPOS-EdgeBox' -Force" 2>nul
)

if not exist "C:\DeltaPOS-EdgeBox" (
    echo [!] Giai nen that bai.
    pause
    exit /b 1
)

:: Cau hinh .env
echo  Cau hinh...
cd /d "C:\DeltaPOS-EdgeBox"
if exist .env del .env
copy .env.example .env >nul
powershell -Command "(Get-Content .env) -replace 'STORE_ID=.*', 'STORE_ID=%STORE_ID%' | Set-Content .env"
powershell -Command "(Get-Content .env) -replace 'CLOUD_API_URL=.*', 'CLOUD_API_URL=https://api-pos-test.vietvang.net' | Set-Content .env"
powershell -Command "(Get-Content .env) -replace 'API_KEY=.*', 'API_KEY=f3c87a03634f6f6d7df6fdf1675327d6' | Set-Content .env"
powershell -Command "(Get-Content .env) -replace 'EDGE_BOX_API_KEY=.*', 'EDGE_BOX_API_KEY=f3c87a03634f6f6d7df6fdf1675327d6' | Set-Content .env"

:: Chay setup
echo.
echo ============================================================
echo  Dang cai dat... (co the mat vai phut)
echo ============================================================
cd /d "C:\DeltaPOS-EdgeBox"
call setup.bat

if %errorLevel% equ 0 (
    echo.
    echo ============================================================
    echo        CAI DAT HOAN TAT!
    echo ============================================================
    echo.
    echo  Edge Box URL: http://%LOCAL_IP%:8000
    echo.
    echo  Cau hinh tren Cloud Admin:
    echo    - deployment_mode = offline-first
    echo    - edge_routing_active = true
    echo    - edge_box_url = http://%LOCAL_IP%:8000
    echo.
    echo  Edge Box tu dong chay khi khoi dong may.
    echo.
) else (
    echo [!] Cai dat that bai. Xem log: C:\DeltaPOS-EdgeBox\storage\logs\laravel.log
)

pause
