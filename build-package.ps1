# DeltaPOS Edge Box - Package Builder Script
# This script creates a deployment-ready package for distribution
# Usage: .\build-package.ps1 -Version "1.0.0"

param(
    [Parameter(Mandatory=$true)]
    [string]$Version,

    [string]$OutputPath = "D:\VietVang\Project\DeltaPOS\packages"
)

$ErrorActionPreference = "Stop"
$SourcePath = "D:\VietVang\Project\DeltaPOS\delta-pos-edge-box"
$PackageName = "DeltaPOS-EdgeBox-v$Version"
$PackageDir = Join-Path $OutputPath $PackageName

Write-Host "=========================================" -ForegroundColor Cyan
Write-Host "  DeltaPOS Edge Box Package Builder" -ForegroundColor Cyan
Write-Host "=========================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "Version: $Version" -ForegroundColor Yellow
Write-Host "Source: $SourcePath" -ForegroundColor Yellow
Write-Host "Output: $PackageDir.zip" -ForegroundColor Yellow
Write-Host ""

# Step 1: Create output directory
Write-Host "[1/8] Creating output directory..." -ForegroundColor Yellow
if (Test-Path $OutputPath) {
    Remove-Item $OutputPath -Recurse -Force
}
New-Item -ItemType Directory -Path $OutputPath -Force | Out-Null
New-Item -ItemType Directory -Path $PackageDir -Force | Out-Null

# Step 2: Copy source files (exclude unnecessary files)
Write-Host "[2/8] Copying source files..." -ForegroundColor Yellow
$ExcludeItems = @(
    '.git',
    'node_modules',
    'storage/logs/*.log',
    'storage/framework/cache/*',
    'storage/framework/sessions/*',
    'storage/framework/views/*',
    '*.zip',
    '.env'  # Don't include actual .env, only .env.example
)

Get-ChildItem -Path $SourcePath -Recurse | Where-Object {
    $exclude = $false
    foreach ($item in $ExcludeItems) {
        if ($_.FullName -like "*\$item") {
            $exclude = $true
            break
        }
    }
    -not $exclude
} | ForEach-Object {
    $targetPath = $_.FullName.Replace($SourcePath, $PackageDir)
    if ($_.PSIsContainer) {
        if (-not (Test-Path $targetPath)) {
            New-Item -ItemType Directory -Path $targetPath -Force | Out-Null
        }
    } else {
        $targetDir = Split-Path $targetPath -Parent
        if (-not (Test-Path $targetDir)) {
            New-Item -ItemType Directory -Path $targetDir -Force | Out-Null
        }
        Copy-Item $_.FullName -Destination $targetPath -Force
    }
}

# Step 3: Create clean .env file from template
Write-Host "[3/8] Creating .env configuration template..." -ForegroundColor Yellow
Copy-Item "$SourcePath\.env.example" "$PackageDir\.env" -Force

# Step 4: Download dependencies info
Write-Host "[4/8] Generating dependency list..." -ForegroundColor Yellow
Set-Location $PackageDir
composer show --all > DEPENDENCIES.txt 2>&1 | Out-Null

# Step 5: Create installation instructions
Write-Host "[5/8] Creating installation guide..." -ForegroundColor Yellow
$InstallGuide = @"
# DeltaPOS Edge Box Installation Guide v$Version

## Quick Install (Recommended)

1. Extract this folder to C:\DeltaPOS-EdgeBox
2. Right-click install.ps1 → "Run with PowerShell"
3. Follow the on-screen prompts
4. Open browser: http://localhost:8000

## Manual Install

See QUICK_START.md for detailed instructions.

## Requirements

- Windows 10/11 Pro (64-bit)
- 8GB RAM minimum
- 128GB SSD storage
- Administrator access

## Support

Email: support@deltapos.com
Phone: [Your support number]

## Version Information

Version: $Version
Build Date: $(Get-Date -Format "yyyy-MM-dd HH:mm:ss")
"@

$InstallGuide | Out-File "$PackageDir\INSTALL.txt" -Encoding UTF8

# Step 6: Create version file
Write-Host "[6/8] Creating version metadata..." -ForegroundColor Yellow
$VersionInfo = @{
    Version = $Version
    BuildDate = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    MinWindowsVersion = "10.0"
    MinRAM = "8GB"
    MinStorage = "128GB"
    PHPVersion = "8.1+"
    LaravelVersion = "10.x"
} | ConvertTo-Json -Depth 10

$VersionInfo | Out-File "$PackageDir\version.json" -Encoding UTF8

# Step 7: Calculate package size
Write-Host "[7/8] Calculating package size..." -ForegroundColor Yellow
$PackageSize = (Get-ChildItem $PackageDir -Recurse | Measure-Object -Property Length -Sum).Sum
$PackageSizeMB = [math]::Round($PackageSize / 1MB, 2)
Write-Host "  Package size: $PackageSizeMB MB" -ForegroundColor Green

# Step 8: Create ZIP archive
Write-Host "[8/8] Creating ZIP archive..." -ForegroundColor Yellow
$ZipPath = "$OutputPath\$PackageName.zip"

if (Test-Path $ZipPath) {
    Remove-Item $ZipPath -Force -ErrorAction SilentlyContinue
    Start-Sleep -Seconds 2
}

# Retry logic for file locks
$maxRetries = 3
$retryCount = 0
$success = $false

while (-not $success -and $retryCount -lt $maxRetries) {
    try {
        Compress-Archive -Path "$PackageDir\*" -DestinationPath $ZipPath -CompressionLevel Optimal -ErrorAction Stop
        $success = $true
    } catch {
        $retryCount++
        Write-Host "  Retry $retryCount/$maxRetries..." -ForegroundColor Yellow
        Start-Sleep -Seconds 3

        if ($retryCount -eq $maxRetries) {
            Write-Host "  Failed to create ZIP after $maxRetries attempts" -ForegroundColor Red
            Write-Host "  Error: $_" -ForegroundColor Red
            exit 1
        }
    }
}

$ZipSize = (Get-Item $ZipPath).Length
$ZipSizeMB = [math]::Round($ZipSize / 1MB, 2)

Write-Host ""
Write-Host "=========================================" -ForegroundColor Green
Write-Host "  Package Created Successfully!" -ForegroundColor Green
Write-Host "=========================================" -ForegroundColor Green
Write-Host ""
Write-Host "Package: $ZipPath" -ForegroundColor Cyan
Write-Host "Size: $ZipSizeMB MB" -ForegroundColor Cyan
Write-Host "Version: $Version" -ForegroundColor Cyan
Write-Host ""
Write-Host "Distribution checklist:" -ForegroundColor Yellow
Write-Host "  ✓ Upload to your distribution server" -ForegroundColor White
Write-Host "  ✓ Update download link in documentation" -ForegroundColor White
Write-Host "  ✓ Notify store owners of new version" -ForegroundColor White
Write-Host "  ✓ Test installation on clean Windows PC" -ForegroundColor White
Write-Host ""
