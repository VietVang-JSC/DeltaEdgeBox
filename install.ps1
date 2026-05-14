# DeltaPOS Edge Box Installation Script for Windows
# Usage: .\install.ps1 -StoreId "STORE_001" -ApiKey "your_api_key" -CloudUrl "https://api.deltapos.cloud"

param(
    [Parameter(Mandatory=$true)]
    [string]$StoreId,

    [Parameter(Mandatory=$true)]
    [string]$ApiKey,

    [string]$CloudUrl = "https://api.deltapos.cloud",

    [string]$InstallPath = "C:\DeltaPOS-EdgeBox"
)

$ErrorActionPreference = "Stop"

Write-Host "=========================================" -ForegroundColor Cyan
Write-Host "  DeltaPOS Edge Box Installer (Windows)" -ForegroundColor Cyan
Write-Host "=========================================" -ForegroundColor Cyan
Write-Host ""

# Step 1: Check Administrator privileges
Write-Host "[1/8] Checking administrator privileges..." -ForegroundColor Yellow
if (-NOT ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole] "Administrator")) {
    Write-Host "Error: Please run as Administrator" -ForegroundColor Red
    exit 1
}
Write-Host "✓ Running as Administrator" -ForegroundColor Green

# Step 2: Check system requirements
Write-Host "[2/8] Checking system requirements..." -ForegroundColor Yellow
$osVersion = [System.Environment]::OSVersion.Version
if ($osVersion.Major -lt 10) {
    Write-Host "Error: Windows 10 or higher required" -ForegroundColor Red
    exit 1
}

$ramGB = (Get-CimInstance Win32_ComputerSystem).TotalPhysicalMemory / 1GB
if ($ramGB -lt 8) {
    Write-Host "Warning: Less than 8GB RAM detected. Performance may be affected." -ForegroundColor Yellow
}
Write-Host "✓ OS: Windows $($osVersion.Major).$($osVersion.Minor)" -ForegroundColor Green
Write-Host "✓ RAM: $([math]::Round($ramGB, 1)) GB" -ForegroundColor Green

# Step 3: Create installation directory
Write-Host "[3/8] Creating installation directory..." -ForegroundColor Yellow
if (Test-Path $InstallPath) {
    Write-Host "Directory already exists. Using existing installation." -ForegroundColor Yellow
} else {
    New-Item -ItemType Directory -Path $InstallPath -Force | Out-Null
    Write-Host "✓ Created: $InstallPath" -ForegroundColor Green
}
Set-Location $InstallPath

# Step 4: Download and extract dependencies
Write-Host "[4/8] Installing dependencies..." -ForegroundColor Yellow

# Check if PHP is installed
$phpExists = Get-Command php -ErrorAction SilentlyContinue
if (-not $phpExists) {
    Write-Host "  Downloading PHP 8.2..."
    Invoke-WebRequest -Uri "https://windows.php.net/downloads/releases/php-8.2.15-Win32-vs16-x64.zip" -OutFile "php.zip"
    Expand-Archive -Path "php.zip" -DestinationPath "$InstallPath\php" -Force
    Remove-Item "php.zip"

    # Add PHP to PATH
    $env:Path = "$InstallPath\php;" + $env:Path
    [Environment]::SetEnvironmentVariable("Path", $env:Path, [EnvironmentVariableTarget]::Machine)
    Write-Host "  ✓ PHP installed" -ForegroundColor Green
} else {
    Write-Host "  ✓ PHP already installed" -ForegroundColor Green
}

# Check if Nginx is installed
if (-not (Test-Path "$InstallPath\nginx\nginx.exe")) {
    Write-Host "  Downloading Nginx..."
    Invoke-WebRequest -Uri "https://nginx.org/download/nginx-1.24.0.zip" -OutFile "nginx.zip"
    Expand-Archive -Path "nginx.zip" -DestinationPath "$InstallPath" -Force
    Rename-Item -Path "$InstallPath\nginx-1.24.0" -NewName "nginx" -Force
    Remove-Item "nginx.zip"
    Write-Host "  ✓ Nginx installed" -ForegroundColor Green
} else {
    Write-Host "  ✓ Nginx already installed" -ForegroundColor Green
}

# Install NSSM (Non-Sucking Service Manager)
if (-not (Test-Path "$InstallPath\nssm\nssm.exe")) {
    Write-Host "  Downloading NSSM..."
    Invoke-WebRequest -Uri "https://nssm.cc/release/nssm-2.24.zip" -OutFile "nssm.zip"
    Expand-Archive -Path "nssm.zip" -DestinationPath "$InstallPath\nssm-temp" -Force
    Move-Item -Path "$InstallPath\nssm-temp\win64\nssm.exe" -Destination "$InstallPath\nssm" -Force
    Remove-Item "nssm-temp" -Recurse -Force
    Remove-Item "nssm.zip"
    Write-Host "  ✓ NSSM installed" -ForegroundColor Green
} else {
    Write-Host "  ✓ NSSM already installed" -ForegroundColor Green
}

# Step 5: Clone/Download application
Write-Host "[5/8] Downloading application..." -ForegroundColor Yellow
$appPath = "$InstallPath\app"
if (-not (Test-Path $appPath)) {
    # Option 1: Git clone (if git is available)
    $gitExists = Get-Command git -ErrorAction SilentlyContinue
    if ($gitExists) {
        git clone "https://gitlab.vietvang.net/cross-platform/delta-pos-edge-box.git" app
    } else {
        # Option 2: Download ZIP
        Write-Host "  Note: Git not found. Please manually copy application files to: $appPath" -ForegroundColor Yellow
        New-Item -ItemType Directory -Path $appPath -Force | Out-Null
    }
    Write-Host "  ✓ Application downloaded" -ForegroundColor Green
} else {
    Write-Host "  ✓ Application already exists" -ForegroundColor Green
}

Set-Location $appPath

# Step 6: Configure environment
Write-Host "[6/8] Configuring environment..." -ForegroundColor Yellow
if (Test-Path "config\.env.template") {
    Copy-Item "config\.env.template" -Destination ".env" -Force
} elseif (Test-Path ".env.example") {
    Copy-Item ".env.example" -Destination ".env" -Force
}

# Update .env file
if (Test-Path ".env") {
    $content = Get-Content ".env"
    $content = $content -replace 'STORE_ID=.*', "STORE_ID=$StoreId"
    $content = $content -replace 'API_KEY=.*', "API_KEY=$ApiKey"
    $content = $content -replace 'CLOUD_API_URL=.*', "CLOUD_API_URL=$CloudUrl"
    $content = $content -replace 'DEPLOYMENT_MODE=.*', 'DEPLOYMENT_MODE=offline-first'
    Set-Content ".env" $content
}

# Generate application key
php artisan key:generate --force
Write-Host "  ✓ Environment configured" -ForegroundColor Green

# Step 7: Install Composer dependencies
Write-Host "[7/8] Installing Composer dependencies..." -ForegroundColor Yellow
if (Test-Path "composer.phar") {
    php composer.phar install --no-dev --optimize-autoloader
} else {
    composer install --no-dev --optimize-autoloader
}
Write-Host "  ✓ Dependencies installed" -ForegroundColor Green

# Step 8: Setup database and services
Write-Host "[8/8] Setting up database and services..." -ForegroundColor Yellow

# Initialize database
php artisan migrate --force

# Register Windows services using NSSM
& "$InstallPath\nssm\nssm.exe" install DeltaPosSyncWorker "php" "$appPath\artisan sync:worker --daemon --sleep=10"
& "$InstallPath\nssm\nssm.exe" set DeltaPosSyncWorker AppDirectory "$appPath"
& "$InstallPath\nssm\nssm.exe" set DeltaPosSyncWorker DisplayName "DeltaPOS Sync Worker"
& "$InstallPath\nssm\nssm.exe" set DeltaPosSyncWorker Description "Synchronizes local data with cloud every 10 seconds"
& "$InstallPath\nssm\nssm.exe" start DeltaPosSyncWorker

& "$InstallPath\nssm\nssm.exe" install DeltaPosHeartbeat "php" "$appPath\artisan schedule:run"
& "$InstallPath\nssm\nssm.exe" set DeltaPosHeartbeat AppDirectory "$appPath"
& "$InstallPath\nssm\nssm.exe" set DeltaPosHeartbeat DisplayName "DeltaPOS Heartbeat"
& "$InstallPath\nssm\nssm.exe" set DeltaPosHeartbeat Description "Sends heartbeat to cloud every 30 seconds"
& "$InstallPath\nssm\nssm.exe" start DeltaPosHeartbeat

# Start Nginx
Start-Process -FilePath "$InstallPath\nginx\nginx.exe" -WorkingDirectory "$InstallPath\nginx"

# Configure firewall
try {
    New-NetFirewallRule -DisplayName "DeltaPOS Edge Box" -Direction Inbound -Protocol TCP -LocalPort 8000 -Action Allow -ErrorAction SilentlyContinue
    Write-Host "  ✓ Firewall rule added" -ForegroundColor Green
} catch {
    Write-Host "  ⚠ Could not add firewall rule. Please add manually." -ForegroundColor Yellow
}

Write-Host "  ✓ Services started" -ForegroundColor Green

Write-Host ""
Write-Host "=========================================" -ForegroundColor Cyan
Write-Host "  Installation Complete! ✅" -ForegroundColor Green
Write-Host "=========================================" -ForegroundColor Cyan
Write-Host ""

# Get local IP address
$localIP = (Get-NetIPAddress -AddressFamily IPv4 | Where-Object {$_.InterfaceAlias -notlike "*Loopback*"} | Select-Object -First 1).IPAddress

Write-Host "Edge Box Details:" -ForegroundColor Yellow
Write-Host "  Store ID: $StoreId" -ForegroundColor White
Write-Host "  Local URL: http://$localIP`:8000" -ForegroundColor White
Write-Host "  Status: Running" -ForegroundColor Green
Write-Host ""
Write-Host "Services:" -ForegroundColor Yellow
Write-Host "  - Nginx: Running on port 8000" -ForegroundColor White
Write-Host "  - Sync Worker: Running (NSSM service)" -ForegroundColor White
Write-Host "  - Heartbeat: Running (NSSM service)" -ForegroundColor White
Write-Host ""
Write-Host "Next Steps:" -ForegroundColor Yellow
Write-Host "  1. Configure POS terminals to use: http://$localIP`:8000" -ForegroundColor White
Write-Host "  2. Test connectivity: Invoke-WebRequest http://localhost:8000/api/health" -ForegroundColor White
Write-Host "  3. Check logs: Get-Content $appPath\storage\logs\laravel.log -Tail 50" -ForegroundColor White
Write-Host "  4. View services: Get-Service | Where-Object {`$_.DisplayName -like '*DeltaPOS*'}" -ForegroundColor White
Write-Host ""
Write-Host "Management Commands:" -ForegroundColor Yellow
Write-Host "  Stop services: & '$InstallPath\nssm\nssm.exe' stop DeltaPosSyncWorker" -ForegroundColor Gray
Write-Host "  Start services: & '$InstallPath\nssm\nssm.exe' start DeltaPosSyncWorker" -ForegroundColor Gray
Write-Host "  View logs: Get-Content $appPath\storage\logs\laravel.log -Wait" -ForegroundColor Gray
Write-Host ""
