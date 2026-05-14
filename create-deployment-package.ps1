# DeltaPOS Edge Box - Deployment Package Creator
# This script creates a complete deployment package ready for distribution

param(
    [string]$Version = "1.0.0",
    [string]$OutputPath = "d:\VietVang\Project\DeltaPOS\deployments"
)

$ErrorActionPreference = "Stop"
$EdgeBoxPath = "d:\VietVang\Project\DeltaPOS\delta-pos-edge-box"
$PackageName = "DeltaPOS-EdgeBox-v$Version"
$PackagePath = "$OutputPath\$PackageName"

Write-Host "=========================================" -ForegroundColor Cyan
Write-Host "  DeltaPOS Edge Box - Package Creator" -ForegroundColor Cyan
Write-Host "=========================================" -ForegroundColor Cyan
Write-Host ""

# Create output directory
if (-not (Test-Path $OutputPath)) {
    New-Item -ItemType Directory -Path $OutputPath | Out-Null
}

if (Test-Path $PackagePath) {
    Write-Host "Removing old package..." -ForegroundColor Yellow
    Remove-Item -Recurse -Force $PackagePath
}

New-Item -ItemType Directory -Path $PackagePath | Out-Null
Write-Host "✓ Created package directory: $PackagePath" -ForegroundColor Green

# Step 1: Copy application files
Write-Host ""
Write-Host "Step 1/7: Copying application files..." -ForegroundColor Yellow

$appFiles = @(
    "app",
    "bootstrap",
    "config",
    "database",
    "public",
    "resources",
    "routes",
    "storage",
    "tests",
    "artisan",
    "composer.json",
    "composer.lock",
    ".env.example"
)

foreach ($file in $appFiles) {
    $source = Join-Path $EdgeBoxPath $file
    $dest = Join-Path $PackagePath $file

    if (Test-Path $source) {
        Copy-Item -Path $source -Destination $dest -Recurse -Force
        Write-Host "  ✓ Copied: $file" -ForegroundColor Gray
    }
}

# Step 2: Copy installation scripts
Write-Host ""
Write-Host "Step 2/7: Copying installation scripts..." -ForegroundColor Yellow

$scripts = @(
    "install.ps1",
    "setup-services.ps1",
    "manage-services.ps1"
)

foreach ($script in $scripts) {
    $source = Join-Path $EdgeBoxPath $script
    $dest = Join-Path $PackagePath $script

    if (Test-Path $source) {
        Copy-Item -Path $source -Destination $dest -Force
        Write-Host "  ✓ Copied: $script" -ForegroundColor Gray
    }
}

# Step 3: Copy documentation
Write-Host ""
Write-Host "Step 3/7: Copying documentation..." -ForegroundColor Yellow

$docs = @(
    "README.md",
    "QUICK_START_GUIDE.md",
    "WINDOWS_SERVICES_GUIDE.md",
    "OFFLINE_INDICATORS_DOCUMENTATION.md",
    "PDF_GENERATION_DOCUMENTATION.md",
    "PRINT_WORKER_AND_INTEGRATION.md",
    "BACKUP_API_AND_CONNECTION_RESOLVER.md",
    "PRINTER_API_DOCUMENTATION.md",
    "DEMO_DATA_SUMMARY.md"
)

foreach ($doc in $docs) {
    $source = Join-Path $EdgeBoxPath $doc
    $dest = Join-Path $PackagePath $doc

    if (Test-Path $source) {
        Copy-Item -Path $source -Destination $dest -Force
        Write-Host "  ✓ Copied: $doc" -ForegroundColor Gray
    }
}

# Step 4: Create .env configuration template
Write-Host ""
Write-Host "Step 4/7: Creating .env configuration template..." -ForegroundColor Yellow

$envContent = @"
# DeltaPOS Edge Box Configuration
# Copy this file to .env and update values

APP_NAME=DeltaPOS
APP_ENV=production
APP_KEY=base64:CHANGE_THIS_KEY
APP_DEBUG=false
APP_URL=http://localhost:8000

# Store Configuration
STORE_ID=STORE_001
STORE_NAME=DeltaPOS Coffee Shop
STORE_ADDRESS=123 Nguyen Hue, District 1, HCMC
STORE_PHONE=0123-456-789

# Database (SQLite for offline-first)
DB_CONNECTION=sqlite
DB_DATABASE=/path/to/database/database.sqlite

# Cloud API Configuration
CLOUD_API_URL=https://api.deltapos.cloud
API_KEY=your_api_key_here
API_SECRET=your_api_secret_here

# Backblaze B2 Backup Configuration
B2_KEY_ID=your_b2_key_id
B2_APPLICATION_KEY=your_b2_application_key
B2_BUCKET=your_bucket_name
B2_ENDPOINT=https://s3.us-west-000.backblazeb2.com

# Printer Service
PRINTER_SERVICE_URL=http://localhost:3001

# Sync Configuration
SYNC_INTERVAL=10
SYNC_BATCH_SIZE=50
"@

$envContent | Out-File -FilePath (Join-Path $PackagePath ".env.example") -Encoding UTF8
Write-Host "  ✓ Created .env.example" -ForegroundColor Green

# Step 5: Create database directory with placeholder
Write-Host ""
Write-Host "Step 5/7: Setting up database directory..." -ForegroundColor Yellow

$dbDir = Join-Path $PackagePath "database"
if (-not (Test-Path $dbDir)) {
    New-Item -ItemType Directory -Path $dbDir | Out-Null
}

# Create .gitignore for database folder
".gitignore`n*.sqlite`n*.sqlite-journal`n*.sqlite-wal" | Out-File -FilePath (Join-Path $dbDir ".gitignore") -Encoding UTF8
Write-Host "  ✓ Created database directory" -ForegroundColor Green

# Step 6: Create storage directories
Write-Host ""
Write-Host "Step 6/7: Setting up storage directories..." -ForegroundColor Yellow

$storageDirs = @(
    "storage/app",
    "storage/framework/cache",
    "storage/framework/sessions",
    "storage/framework/views",
    "storage/logs"
)

foreach ($dir in $storageDirs) {
    $fullPath = Join-Path $PackagePath $dir
    if (-not (Test-Path $fullPath)) {
        New-Item -ItemType Directory -Path $fullPath -Force | Out-Null
    }
}

Write-Host "  ✓ Created storage directories" -ForegroundColor Green

# Step 7: Create deployment manifest
Write-Host ""
Write-Host "Step 7/7: Creating deployment manifest..." -ForegroundColor Yellow

$manifest = @{
    name = "DeltaPOS Edge Box"
    version = $Version
    build_date = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    requirements = @{
        os = "Windows 10/11 (64-bit)"
        ram = "4GB minimum, 8GB recommended"
        storage = "20GB free space (SSD recommended)"
        php = "8.1 or higher"
        composer = "2.x"
    }
    components = @{
        database = "SQLite with WAL mode"
        web_server = "Laravel built-in server / Nginx"
        services = "5 Windows services via NSSM"
        sync = "Automatic cloud synchronization"
        backup = "Backblaze B2 integration"
        printing = "Thermal printer support (80mm/58mm)"
        offline = "Full offline-first capability"
    }
    endpoints = @{
        health = "/api/health"
        sync_status = "/api/sync/status"
        printers = "/api/printers"
        backup = "/api/backup"
    }
    default_credentials = @{
        admin_email = "admin@deltapos.local"
        admin_password = "Change this password immediately!"
    }
}

$manifestJson = $manifest | ConvertTo-Json -Depth 10
$manifestJson | Out-File -FilePath (Join-Path $PackagePath "MANIFEST.json") -Encoding UTF8
Write-Host "  ✓ Created MANIFEST.json" -ForegroundColor Green

# Create README for package
$readmeContent = @"
# DeltaPOS Edge Box v$Version

## 🚀 Quick Installation

### Prerequisites
- Windows 10/11 (64-bit)
- 4GB RAM minimum (8GB recommended)
- 20GB free disk space (SSD recommended)

### Installation Steps

1. **Extract the package** to desired location (e.g., C:\DeltaPOS-EdgeBox)

2. **Open PowerShell as Administrator** and navigate to the folder:
   ```powershell
   cd C:\DeltaPOS-EdgeBox
   ```

3. **Run the installer**:
   ```powershell
   .\install.ps1
   ```

4. **Configure your store** by editing `.env`:
   ```powershell
   notepad .env
   ```

   Update these values:
   - STORE_ID (your unique store identifier)
   - STORE_NAME (your store name)
   - API_KEY and API_SECRET (from cloud dashboard)
   - B2 credentials (for backups)

5. **Install Windows Services**:
   ```powershell
   .\setup-services.ps1
   ```

6. **Start using Edge Box**:
   - Web UI: http://localhost:8000
   - Default login: admin@deltapos.local / password (change immediately!)

## 📚 Documentation

- **Quick Start Guide**: QUICK_START_GUIDE.md
- **Windows Services**: WINDOWS_SERVICES_GUIDE.md
- **Offline Indicators**: OFFLINE_INDICATORS_DOCUMENTATION.md
- **PDF Generation**: PDF_GENERATION_DOCUMENTATION.md
- **Printer Integration**: PRINT_WORKER_AND_INTEGRATION.md

## 🔧 Management Commands

```powershell
# Manage services
.\manage-services.ps1 -Status
.\manage-services.ps1 -StartAll
.\manage-services.ps1 -StopAll

# Check application status
php artisan route:list
php artisan migrate:status
php artisan schedule:list

# View logs
Get-Content storage\logs\laravel.log -Tail 50
```

## 🆘 Support

For issues or questions:
- Check logs: storage/logs/laravel.log
- Review documentation in this package
- Contact: support@deltapos.com

---

**Build Date**: $(Get-Date -Format "yyyy-MM-dd HH:mm:ss")
**Version**: $Version
"@

$readmeContent | Out-File -FilePath (Join-Path $PackagePath "INSTALLATION.md") -Encoding UTF8
Write-Host "  ✓ Created INSTALLATION.md" -ForegroundColor Green

# Calculate package size
Write-Host ""
Write-Host "Calculating package size..." -ForegroundColor Yellow
$packageSize = (Get-ChildItem $PackagePath -Recurse | Measure-Object -Property Length -Sum).Sum
$sizeMB = [math]::Round($packageSize / 1MB, 2)

Write-Host ""
Write-Host "=========================================" -ForegroundColor Cyan
Write-Host "  Package Created Successfully! ✅" -ForegroundColor Green
Write-Host "=========================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "Package Details:" -ForegroundColor White
Write-Host "  Name: $PackageName" -ForegroundColor Gray
Write-Host "  Location: $PackagePath" -ForegroundColor Gray
Write-Host "  Size: $sizeMB MB" -ForegroundColor Gray
Write-Host ""
Write-Host "Next Steps:" -ForegroundColor Yellow
Write-Host "  1. Test installation on clean Windows machine" -ForegroundColor Gray
Write-Host "  2. Compress to ZIP for distribution" -ForegroundColor Gray
Write-Host "  3. Distribute to stores" -ForegroundColor Gray
Write-Host ""

# Offer to create ZIP
$createZip = Read-Host "Create ZIP archive? (y/n)"
if ($createZip -eq 'y' -or $createZip -eq 'Y') {
    $zipPath = "$OutputPath\$PackageName.zip"

    if (Test-Path $zipPath) {
        Remove-Item $zipPath -Force
    }

    Write-Host ""
    Write-Host "Creating ZIP archive..." -ForegroundColor Yellow

    Compress-Archive -Path "$PackagePath\*" -DestinationPath $zipPath -CompressionLevel Optimal

    $zipSize = (Get-Item $zipPath).Length
    $zipSizeMB = [math]::Round($zipSize / 1MB, 2)

    Write-Host "✓ ZIP created: $zipPath" -ForegroundColor Green
    Write-Host "  Compressed size: $zipSizeMB MB" -ForegroundColor Gray
}

Write-Host ""
Write-Host "Done! 🎉" -ForegroundColor Green
