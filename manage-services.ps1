# DeltaPOS Edge Box - Service Management Script
# Manage all Edge Box Windows services (start, stop, restart, status)

param(
    [switch]$StartAll = $false,
    [switch]$StopAll = $false,
    [switch]$RestartAll = $false,
    [switch]$Status = $false,
    [string]$ServiceName = ""
)

$ErrorActionPreference = "Continue"

$services = @(
    "DeltaPOS-Sync-Worker",
    "DeltaPOS-Print-Worker",
    "DeltaPOS-Heartbeat",
    "DeltaPOS-Backup",
    "DeltaPOS-Cleanup"
)

function Show-Menu {
    Write-Host ""
    Write-Host "=========================================" -ForegroundColor Cyan
    Write-Host "  DeltaPOS Edge Box Service Manager" -ForegroundColor Cyan
    Write-Host "=========================================" -ForegroundColor Cyan
    Write-Host ""
    Write-Host "Available Commands:" -ForegroundColor Yellow
    Write-Host "  .\manage-services.ps1 -StartAll      Start all services" -ForegroundColor White
    Write-Host "  .\manage-services.ps1 -StopAll       Stop all services" -ForegroundColor White
    Write-Host "  .\manage-services.ps1 -RestartAll    Restart all services" -ForegroundColor White
    Write-Host "  .\manage-services.ps1 -Status        Show service status" -ForegroundColor White
    Write-Host "  .\manage-services.ps1                Interactive menu" -ForegroundColor White
    Write-Host ""
}

function Start-AllServices {
    Write-Host "Starting all Edge Box services..." -ForegroundColor Yellow
    Write-Host ""

    foreach ($service in $services) {
        $svc = Get-Service -Name $service -ErrorAction SilentlyContinue
        if ($svc) {
            if ($svc.Status -eq 'Running') {
                Write-Host "  ✓ $service is already running" -ForegroundColor Green
            } else {
                Write-Host "  Starting $service..." -ForegroundColor Gray
                Start-Service -Name $service
                Write-Host "  ✓ $service started" -ForegroundColor Green
            }
        } else {
            Write-Host "  ⚠ $service not found" -ForegroundColor Yellow
        }
    }

    Write-Host ""
    Write-Host "All services started!" -ForegroundColor Green
}

function Stop-AllServices {
    Write-Host "Stopping all Edge Box services..." -ForegroundColor Yellow
    Write-Host ""

    foreach ($service in $services) {
        $svc = Get-Service -Name $service -ErrorAction SilentlyContinue
        if ($svc) {
            if ($svc.Status -eq 'Stopped') {
                Write-Host "  ✓ $service is already stopped" -ForegroundColor Green
            } else {
                Write-Host "  Stopping $service..." -ForegroundColor Gray
                Stop-Service -Name $service -Force
                Write-Host "  ✓ $service stopped" -ForegroundColor Green
            }
        } else {
            Write-Host "  ⚠ $service not found" -ForegroundColor Yellow
        }
    }

    Write-Host ""
    Write-Host "All services stopped!" -ForegroundColor Green
}

function Restart-AllServices {
    Write-Host "Restarting all Edge Box services..." -ForegroundColor Yellow
    Write-Host ""

    Stop-AllServices
    Write-Host ""
    Start-Sleep -Seconds 3
    Start-AllServices
}

function Show-Status {
    Write-Host "Edge Box Services Status:" -ForegroundColor Yellow
    Write-Host ""

    $foundAny = $false
    foreach ($service in $services) {
        $svc = Get-Service -Name $service -ErrorAction SilentlyContinue
        if ($svc) {
            $foundAny = $true
            $statusColor = if ($svc.Status -eq 'Running') { 'Green' } else { 'Red' }
            Write-Host "  $($svc.DisplayName):" -ForegroundColor White -NoNewline
            Write-Host " $($svc.Status)" -ForegroundColor $statusColor
            Write-Host "    Start Type: $($svc.StartType)" -ForegroundColor Gray
            Write-Host ""
        }
    }

    if (-not $foundAny) {
        Write-Host "  No Edge Box services found." -ForegroundColor Yellow
        Write-Host "  Run setup-services.ps1 to install services." -ForegroundColor Gray
    }
}

function InteractiveMenu {
    while ($true) {
        Show-Status

        Write-Host "Choose an action:" -ForegroundColor Cyan
        Write-Host "  [1] Start All Services" -ForegroundColor White
        Write-Host "  [2] Stop All Services" -ForegroundColor White
        Write-Host "  [3] Restart All Services" -ForegroundColor White
        Write-Host "  [4] Refresh Status" -ForegroundColor White
        Write-Host "  [Q] Quit" -ForegroundColor White
        Write-Host ""

        $choice = Read-Host "Enter choice (1-4 or Q)"

        switch ($choice.ToUpper()) {
            '1' { Start-AllServices; Write-Host ""; Pause }
            '2' { Stop-AllServices; Write-Host ""; Pause }
            '3' { Restart-AllServices; Write-Host ""; Pause }
            '4' { Clear-Host }
            'Q' { break }
            default { Write-Host "Invalid choice. Try again." -ForegroundColor Red }
        }
    }
}

# Main logic
if ($StartAll) {
    Start-AllServices
} elseif ($StopAll) {
    Stop-AllServices
} elseif ($RestartAll) {
    Restart-AllServices
} elseif ($Status) {
    Show-Status
} elseif ($ServiceName) {
    # Manage specific service
    $svc = Get-Service -Name $ServiceName -ErrorAction SilentlyContinue
    if ($svc) {
        Write-Host "Service: $($svc.DisplayName)" -ForegroundColor Cyan
        Write-Host "Status: $($svc.Status)" -ForegroundColor Yellow
        Write-Host "Start Type: $($svc.StartType)" -ForegroundColor Yellow
    } else {
        Write-Host "Service not found: $ServiceName" -ForegroundColor Red
    }
} else {
    # Interactive mode
    InteractiveMenu
}
