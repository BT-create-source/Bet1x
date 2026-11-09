param (
    [switch]$IncludeNodeModules
)

$source = "C:\Users\LENOVO\Desktop\Wormhole BWA59na"
$dest = "E:\Aadi"

if (-not (Test-Path $dest)) {
    Write-Error "Destination folder '$dest' not found!"
    exit 1
}

Write-Host "==========================================================" -ForegroundColor Cyan
Write-Host " Selective Sync: Backend/Logic -> E:\Aadi" -ForegroundColor Cyan
Write-Host " Protected: HTML files (*.html) and assets/ are NOT touched" -ForegroundColor Yellow
Write-Host "==========================================================" -ForegroundColor Cyan

$xd = @("node_modules", ".cache", ".git")
if ($IncludeNodeModules) {
    $xd = @(".cache", ".git")
}

# 1. Sync backend/
Write-Host "`n[1/3] Syncing backend/..." -ForegroundColor Green
robocopy "$source\backend" "$dest\backend" /E /XD $xd /R:1 /W:1 /NDL /NP /NFL
if ($LASTEXITCODE -ge 8) { Write-Warning "Robocopy encountered issues in backend/ (code $LASTEXITCODE)" }

# 2. Sync php-backend/
Write-Host "[2/3] Syncing php-backend/..." -ForegroundColor Green
robocopy "$source\php-backend" "$dest\php-backend" /E /XD $xd /R:1 /W:1 /NDL /NP /NFL
if ($LASTEXITCODE -ge 8) { Write-Warning "Robocopy encountered issues in php-backend/ (code $LASTEXITCODE)" }

# 3. Sync bot_core_locked/
Write-Host "[3/3] Syncing bot_core_locked/..." -ForegroundColor Green
robocopy "$source\bot_core_locked" "$dest\bot_core_locked" /E /XD $xd /R:1 /W:1 /NDL /NP /NFL
if ($LASTEXITCODE -ge 8) { Write-Warning "Robocopy encountered issues in bot_core_locked/ (code $LASTEXITCODE)" }

# 4. Sync root configuration and server files
$rootFiles = @("router.php", "docker-compose.yml", "Dockerfile", "package.json", "package-lock.json")
foreach ($f in $rootFiles) {
    $srcPath = Join-Path $source $f
    if (Test-Path $srcPath) {
        Copy-Item -Path $srcPath -Destination (Join-Path $dest $f) -Force
        Write-Host "  -> Synced root config: $f" -ForegroundColor DarkGray
    }
}

Write-Host "`n Selective sync completed successfully!" -ForegroundColor Green
Write-Host "Your custom UI files (*.html, assets/) in E:\Aadi remain untouched." -ForegroundColor Cyan
