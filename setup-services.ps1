# DeltaPOS Edge Box - Windows Services Setup Script
# This script registers all Edge Box background services using NSSM

param(
    [string]$InstallPath = "C:\DeltaPOS-EdgeBox",
    [switch]$Uninstall = $false
)

$ErrorActionPreference = "Stop"
$appPath = "$InstallPath\app"
$nssmPath = "$InstallPath\nssm\nssm.exe"

Write-Host "=========================================" -ForegroundColor Cyan
Write-Host "  DeltaPOS Edge Box Services Manager" -ForegroundColor Cyan
Write-Host "=========================================" -ForegroundColor Cyan
Write-Host ""

# Check if running as Administrator
if (-NOT ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole] "Administrator")) {
    Write-Host "Error: Please run as Administrator" -ForegroundColor Red
    exit 1
}

# Check NSSM exists
if (-not (Test-Path $nssmPath)) {
    Write-Host "Error: NSSM not found at $nssmPath" -ForegroundColor Red
    Write-Host "Please run install.ps1 first to install NSSM." -ForegroundColor Yellow
    exit 1
}

# Check app path exists
if (-not (Test-Path $appPath)) {
    Write-Host "Error: Application not found at $appPath" -ForegroundColor Red
    exit 1
}

function Install-Service {
    param(
        [string]$ServiceName,
        [string]$DisplayName,
        [string]$Description,
        [string]$Command,
        [string]$AppDirectory = $appPath,
        [int]$StartDelay = 0
    )

    Write-Host "Installing service: $DisplayName..." -ForegroundColor Yellow

    # Remove existing service if exists
    $existing = Get-Service -Name $ServiceName -ErrorAction SilentlyContinue
    if ($existing) {
        Write-Host "  Service already exists. Removing..." -ForegroundColor Gray
        & $nssmPath remove $ServiceName confirm
        Start-Sleep -Seconds 2
    }

    # Install service
    & $nssmPath install $ServiceName "php" "$Command"
    & $nssmPath set $ServiceName AppDirectory $AppDirectory
    & $nssmPath set $ServiceName DisplayName $DisplayName
    & $nssmPath set $ServiceName Description $Description

    # Configure restart on failure
    & $nssmPath set $ServiceName AppRestartDelay $StartDelay
    & $nssmPath set $ServiceName AppExit Default Restart
    & $nssmPath set $ServiceName AppStdout "$appPath\storage\logs\$ServiceName-stdout.log"
    & $nssmPath set $ServiceName AppStderr "$appPath\storage\logs\$ServiceName-stderr.log"

    # Set startup type to automatic
    & $nssmPath set $ServiceName Start SERVICE_AUTO_START

    Write-Host "  ✓ Service installed: $DisplayName" -ForegroundColor Green
}

function Uninstall-Service {
    param(
        [string]$ServiceName,
        [string]$DisplayName
    )

    Write-Host "Removing service: $DisplayName..." -ForegroundColor Yellow

    $existing = Get-Service -Name $ServiceName -ErrorAction SilentlyContinue
    if ($existing) {
        # Stop service first
        if ($existing.Status -eq 'Running') {
            Write-Host "  Stopping service..." -ForegroundColor Gray
            & $nssmPath stop $ServiceName
            Start-Sleep -Seconds 3
        }

        # Remove service
        & $nssmPath remove $ServiceName confirm
        Write-Host "  ✓ Service removed: $DisplayName" -ForegroundColor Green
    } else {
        Write-Host "  Service not found: $DisplayName" -ForegroundColor Gray
    }
}

if ($Uninstall) {
    # Uninstall all services
    Write-Host "Uninstalling all Edge Box services..." -ForegroundColor Yellow
    Write-Host ""

    Uninstall-Service "DeltaPOS-Sync-Worker" "DeltaPOS Sync Worker"
    Uninstall-Service "DeltaPOS-Print-Worker" "DeltaPOS Print Worker"
    Uninstall-Service "DeltaPOS-Heartbeat" "DeltaPOS Heartbeat Monitor"
    Uninstall-Service "DeltaPOS-Backup" "DeltaPOS Backup Scheduler"
    Uninstall-Service "DeltaPOS-Cleanup" "DeltaPOS Cleanup Service"

    Write-Host ""
    Write-Host "All services uninstalled successfully!" -ForegroundColor Green

} else {
    # Install all services
    Write-Host "Installing Edge Box services..." -ForegroundColor Yellow
    Write-Host ""

    # 1. Sync Worker - Processes sync queue every 10 seconds
    Install-Service `
        -ServiceName "DeltaPOS-Sync-Worker" `
        -DisplayName "DeltaPOS Sync Worker" `
        -Description "Synchronizes local data with cloud every 10 seconds" `
        -Command "$appPath\artisan sync:worker --daemon --sleep=10 --batch=50" `
        -StartDelay 5000

    # 2. Print Worker - Processes print queue every 2 seconds
    Install-Service `
        -ServiceName "DeltaPOS-Print-Worker" `
        -DisplayName "DeltaPOS Print Worker" `
        -Description "Processes print queue and sends jobs to thermal printers every 2 seconds" `
        -Command "$appPath\artisan print:worker --daemon --sleep=2 --batch=10" `
        -StartDelay 3000

    # 3. Heartbeat - Sends heartbeat to cloud every 30 seconds
    Install-Service `
        -ServiceName "DeltaPOS-Heartbeat" `
        -DisplayName "DeltaPOS Heartbeat Monitor" `
        -Description "Sends heartbeat to cloud server every 30 seconds for monitoring" `
        -Command "$appPath\artisan schedule:run" `
        -StartDelay 10000

    # 4. Backup Service - Daily database backup at 23:59
    Install-Service `
        -ServiceName "DeltaPOS-Backup" `
        -DisplayName "DeltaPOS Backup Scheduler" `
        -Description "Performs daily database backup to Backblaze B2 at 23:59" `
        -Command "$appPath\artisan backup:database --type=daily --max=7" `
        -StartDelay 0

    # 5. Cleanup Service - Cleanup old sync logs and queues daily at 2:00 AM
    Install-Service `
        -ServiceName "DeltaPOS-Cleanup" `
        -DisplayName "DeltaPOS Cleanup Service" `
        -Description "Cleans up old sync logs and completed jobs daily at 2:00 AM" `
        -Command "$appPath\artisan sync:cleanup --days-queue=7 --days-logs=30" `
        -StartDelay 0

    Write-Host ""
    Write-Host "Starting all services..." -ForegroundColor Yellow
    Start-Sleep -Seconds 2

    # Start all services
    & $nssmPath start "DeltaPOS-Sync-Worker"
    & $nssmPath start "DeltaPOS-Print-Worker"
    & $nssmPath start "DeltaPOS-Heartbeat"
    & $nssmPath start "DeltaPOS-Backup"
    & $nssmPath start "DeltaPOS-Cleanup"

    Write-Host ""
    Write-Host "=========================================" -ForegroundColor Cyan
    Write-Host "  Services Installed Successfully! ✅" -ForegroundColor Green
    Write-Host "=========================================" -ForegroundColor Cyan
    Write-Host ""

    # Show service status
    Write-Host "Service Status:" -ForegroundColor Yellow
    Get-Service | Where-Object { $_.DisplayName -like '*DeltaPOS*' } | Format-Table DisplayName, Status, StartType -AutoSize

    Write-Host ""
    Write-Host "Management Commands:" -ForegroundColor Yellow
    Write-Host "  View all services: Get-Service | Where-Object { `$_.DisplayName -like '*DeltaPOS*' }" -ForegroundColor Gray
    Write-Host "  Stop all: .\manage-services.ps1 -StopAll" -ForegroundColor Gray
    Write-Host "  Start all: .\manage-services.ps1 -StartAll" -ForegroundColor Gray
    Write-Host "  Restart all: .\manage-services.ps1 -RestartAll" -ForegroundColor Gray
    Write-Host "  Uninstall: .\setup-services.ps1 -Uninstall" -ForegroundColor Gray
    Write-Host ""
    Write-Host "View Logs:" -ForegroundColor Yellow
    Write-Host "  Sync Worker: Get-Content $appPath\storage\logs\DeltaPOS-Sync-Worker-stdout.log -Wait" -ForegroundColor Gray
    Write-Host "  Print Worker: Get-Content $appPath\storage\logs\DeltaPOS-Print-Worker-stdout.log -Wait" -ForegroundColor Gray
    Write-Host "  Laravel Log: Get-Content $appPath\storage\logs\laravel.log -Tail 50" -ForegroundColor Gray
    Write-Host ""
}
