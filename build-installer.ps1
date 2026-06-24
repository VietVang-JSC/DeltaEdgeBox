# DeltaPOS Edge Box - Build Installer Script
# Usage: .\build-installer.ps1 -AppVersion "1.0.0"
# Requires:
#   - Inno Setup 6+ (https://jrsoftware.org/isdl.php)
#   - PHP 8.3 portable ZIP downloaded manually or auto-downloaded
#   - NSSM 2.24 (https://nssm.cc/download)

param(
    [Parameter(Mandatory = $false)]
    [string]$AppVersion = "1.0.0",

    [Parameter(Mandatory = $false)]
    [string]$PhpVersion = "8.3.17",

    [Parameter(Mandatory = $false)]
    [string]$PhpArch = "x64",

    [Parameter(Mandatory = $false)]
    [string]$InnoSetupPath = "C:\Program Files (x86)\Inno Setup 6\ISCC.exe",

    [Parameter(Mandatory = $false)]
    [switch]$SkipPhpDownload = $false
)

$ErrorActionPreference = "Stop"
$RootDir = Split-Path $PSScriptRoot -Parent
$BuildDir = "$RootDir\build"
$ScriptsDir = "$RootDir\scripts"
$OutputDir = "$RootDir\dist"

Write-Host "=========================================" -ForegroundColor Cyan
Write-Host "  DeltaPOS Edge Box Installer Builder" -ForegroundColor Cyan
Write-Host "=========================================" -ForegroundColor Cyan
Write-Host ""

# Step 1: Clean build directory
Write-Host "[1/7] Preparing build directory..." -ForegroundColor Yellow
if (Test-Path $BuildDir) {
    Remove-Item $BuildDir -Recurse -Force -ErrorAction SilentlyContinue
    Start-Sleep -Seconds 1
}
New-Item $BuildDir -ItemType Directory -Force | Out-Null
New-Item "$BuildDir\app" -ItemType Directory -Force | Out-Null
New-Item "$BuildDir\php" -ItemType Directory -Force | Out-Null
New-Item "$BuildDir\nssm" -ItemType Directory -Force | Out-Null
Write-Host "  ✓ Build directory ready: $BuildDir" -ForegroundColor Green

# Step 2: Copy application files
Write-Host "[2/7] Copying application files..." -ForegroundColor Yellow
$ExcludeList = @(
    '.git', '.gitignore', '.gitattributes',
    'node_modules', 'tests', 'docs',
    'build', '*.log', '*.zip',
    '.env', '.env.example',
    'storage/logs/*', 'storage/framework/cache/*',
    'storage/framework/sessions/*', 'storage/framework/views/*',
    'vendor/bin', 'vendor/phpunit',
    'installer.iss', 'build-installer.ps1',
    'build-package.ps1', 'create-deployment-package.ps1',
    'install.ps1', 'setup-services.ps1', 'manage-services.ps1',
    'setup.bat', 'install.bat',
    'deploy.sh'
)

Get-ChildItem "$RootDir" -Exclude ($ExcludeList + 'scripts') -Depth 0 -Directory | ForEach-Object {
    $target = "$BuildDir\app\$($_.Name)"
    Copy-Item $_.FullName $target -Recurse -Force -ErrorAction SilentlyContinue
    Write-Host "  ✓ Copied: $($_.Name)" -ForegroundColor Gray
}

# Copy scripts separately (not inside app/)
Copy-Item "$RootDir\scripts" "$BuildDir\scripts" -Recurse -Force
Write-Host "  ✓ Copied: scripts" -ForegroundColor Gray

# Copy root files
Get-ChildItem "$RootDir" -File | Where-Object { $_.Extension -in '.php', '.json', '.md' } | ForEach-Object {
    Copy-Item $_.FullName "$BuildDir\app" -Force -ErrorAction SilentlyContinue
}
Write-Host "  ✓ Application files copied" -ForegroundColor Green

# Step 3: Optimize vendor
Write-Host "[3/7] Optimizing vendor..." -ForegroundColor Yellow
$VendorDir = "$BuildDir\app\vendor"
if (Test-Path $VendorDir) {
    # Remove dev files from vendor
    Get-ChildItem "$VendorDir" -Recurse -Directory -Filter "tests" -ErrorAction SilentlyContinue | Remove-Item -Recurse -Force -ErrorAction SilentlyContinue
    Get-ChildItem "$VendorDir" -Recurse -Directory -Filter "test" -ErrorAction SilentlyContinue | Remove-Item -Recurse -Force -ErrorAction SilentlyContinue
    Get-ChildItem "$VendorDir" -Recurse -Directory -Filter "docs" -ErrorAction SilentlyContinue | Remove-Item -Recurse -Force -ErrorAction SilentlyContinue
    # Remove .git from vendor packages
    Get-ChildItem "$VendorDir" -Recurse -Directory -Filter ".git" -ErrorAction SilentlyContinue | Remove-Item -Recurse -Force -ErrorAction SilentlyContinue
    Write-Host "  ✓ Vendor optimized (removed tests, docs, .git)" -ForegroundColor Green
} else {
    Write-Host "  ⚠ Vendor directory not found. Run 'composer install --no-dev' first." -ForegroundColor Yellow
}

# Step 4: Build PHAR archive
Write-Host "[4/7] Building PHAR archive..." -ForegroundColor Yellow
Set-Location "$BuildDir\app"
if (Test-Path "build-phar.php") {
    # Need PHP to build PHAR — use system PHP or download first
    $phpExe = Get-Command "php.exe" -ErrorAction SilentlyContinue
    if ($phpExe) {
        # Update index.php for PHAR (already compatible)
        $pharResult = php build-phar.php 2>&1
        Write-Host "  $pharResult" -ForegroundColor Gray
        if (Test-Path "build\edge-box.phar") {
            Copy-Item "build\edge-box.phar" "$BuildDir\edge-box.phar" -Force
            Remove-Item "build\edge-box.phar" -Force
            Write-Host "  ✓ PHAR built: edge-box.phar" -ForegroundColor Green
        }
    } else {
        Write-Host "  ⚠ PHP not found, skipping PHAR build (will build later)" -ForegroundColor Yellow
    }
} else {
    Write-Host "  ⚠ build-phar.php not found, skipping PHAR build" -ForegroundColor Yellow
}
Set-Location $RootDir

# Step 5: Download or copy PHP portable
Write-Host "[5/7] Setting up PHP $PhpVersion ($PhpArch)..." -ForegroundColor Yellow

if (-not $SkipPhpDownload) {
    $PhpZipUrl = "https://windows.php.net/downloads/releases/php-$PhpVersion-nts-Win32-vs16-$PhpArch.zip"
    $PhpZipPath = "$env:TEMP\php-$PhpVersion.zip"

    try {
        Write-Host "  Downloading PHP from $PhpZipUrl ..." -ForegroundColor Gray
        Invoke-WebRequest -Uri $PhpZipUrl -OutFile $PhpZipPath -UseBasicParsing -TimeoutSec 120
        Expand-Archive -Path $PhpZipPath -DestinationPath "$BuildDir\php" -Force
        Remove-Item $PhpZipPath -Force
        Write-Host "  ✓ PHP $PhpVersion downloaded and extracted" -ForegroundColor Green
    } catch {
        Write-Host "  ⚠ Download failed: $_" -ForegroundColor Red
        Write-Host "  Please download PHP $PhpVersion manually from:" -ForegroundColor Yellow
        Write-Host "  $PhpZipUrl" -ForegroundColor Yellow
        Write-Host "  Extract to: $BuildDir\php" -ForegroundColor Yellow
        exit 1
    }
} else {
    Write-Host "  ⚠ Skipping PHP download (use --SkipPhpDownload)" -ForegroundColor Yellow
}

# Verify PHP
if (Test-Path "$BuildDir\php\php.exe") {
    $PhpVer = & "$BuildDir\php\php.exe" -v 2>&1 | Select-Object -First 1
    Write-Host "  ✓ PHP version: $PhpVer" -ForegroundColor Green
} else {
    Write-Host "  ✗ php.exe not found at $BuildDir\php" -ForegroundColor Red
    exit 1
}

# Step 5: Copy NSSM
Write-Host "[6/7] Setting up NSSM..." -ForegroundColor Yellow
$NssmDlPath = "$env:TEMP\nssm-2.24.zip"

if (-not (Test-Path "$BuildDir\nssm\nssm.exe")) {
    try {
        if (-not (Test-Path $NssmDlPath)) {
            Write-Host "  Downloading NSSM 2.24..." -ForegroundColor Gray
            Invoke-WebRequest -Uri "https://nssm.cc/release/nssm-2.24.zip" -OutFile $NssmDlPath -UseBasicParsing -TimeoutSec 30
        }
        $NssmExtractDir = "$env:TEMP\nssm-2.24"
        if (Test-Path $NssmExtractDir) { Remove-Item $NssmExtractDir -Recurse -Force }
        Expand-Archive -Path $NssmDlPath -DestinationPath $NssmExtractDir -Force
        Copy-Item "$NssmExtractDir\win64\nssm.exe" "$BuildDir\nssm\nssm.exe" -Force
        Remove-Item $NssmExtractDir -Recurse -Force
        Write-Host "  ✓ NSSM 2.24 downloaded" -ForegroundColor Green
    } catch {
        Write-Host "  ⚠ NSSM download failed: $_" -ForegroundColor Red
        Write-Host "  Download manually from: https://nssm.cc/release/nssm-2.24.zip" -ForegroundColor Yellow
        Write-Host "  Extract win64/nssm.exe to: $BuildDir\nssm\nssm.exe" -ForegroundColor Yellow
        exit 1
    }
} else {
    Write-Host "  ✓ NSSM already present" -ForegroundColor Green
}

# Create cacert.pem for SSL
Write-Host "  Downloading SSL certificates..." -ForegroundColor Gray
try {
    Invoke-WebRequest -Uri "https://curl.se/ca/cacert.pem" -OutFile "$ScriptsDir\cacert.pem" -UseBasicParsing -TimeoutSec 30 -ErrorAction SilentlyContinue
    Write-Host "  ✓ SSL certificates downloaded" -ForegroundColor Green
} catch {
    Write-Host "  ⚠ SSL cert download failed. Create $ScriptsDir\cacert.pem manually." -ForegroundColor Yellow
}

# Create edge-manager.url shortcut file
$UrlContent = "[InternetShortcut]`nURL=http://localhost:8000/edge-manager`n"
$UrlContent | Out-File -FilePath "$ScriptsDir\edge-manager.url" -Encoding ASCII

# Step 6: Run Inno Setup compiler
Write-Host "[7/7] Compiling installer..." -ForegroundColor Yellow

if (-not (Test-Path $InnoSetupPath)) {
    Write-Host "  ✗ Inno Setup not found at: $InnoSetupPath" -ForegroundColor Red
    Write-Host "  Download from: https://jrsoftware.org/isdl.php" -ForegroundColor Yellow
    Write-Host "  Then re-run this script." -ForegroundColor Yellow
    exit 1
}

if (-not (Test-Path $OutputDir)) {
    New-Item $OutputDir -ItemType Directory -Force | Out-Null
}

# Set env var for version
$env:APP_VERSION = $AppVersion

# Run ISCC
Set-Location $RootDir
Write-Host "  Running Inno Setup Compiler..." -ForegroundColor Gray
& $InnoSetupPath "installer.iss" /Q

if ($LASTEXITCODE -eq 0) {
    $InstallerPath = "$OutputDir\DeltaPOS-EdgeBox-Setup-v$AppVersion.exe"
    if (Test-Path $InstallerPath) {
        $Size = (Get-Item $InstallerPath).Length
        $SizeMB = [math]::Round($Size / 1MB, 2)
        Write-Host ""
        Write-Host "=========================================" -ForegroundColor Green
        Write-Host "  ✅ Build Successful!" -ForegroundColor Green
        Write-Host "=========================================" -ForegroundColor Green
        Write-Host ""
        Write-Host "  Installer: $InstallerPath" -ForegroundColor Cyan
        Write-Host "  Size: $SizeMB MB" -ForegroundColor Cyan
        Write-Host "  Version: $AppVersion" -ForegroundColor Cyan
        Write-Host ""
        Write-Host "  Next steps:" -ForegroundColor Yellow
        Write-Host "  1. Copy installer to a clean Windows 10/11 VM" -ForegroundColor Gray
        Write-Host "  2. Run as Administrator" -ForegroundColor Gray
        Write-Host "  3. Test full installation flow" -ForegroundColor Gray
        Write-Host "  4. Test Edge Manager at http://localhost:8000/edge-manager" -ForegroundColor Gray
    }
} else {
    Write-Host "  ✗ Inno Setup compilation failed (exit code: $LASTEXITCODE)" -ForegroundColor Red
    exit 1
}

Write-Host ""
Write-Host "Done!" -ForegroundColor Green
