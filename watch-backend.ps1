param (
    [string]$Source = "C:\Users\LENOVO\Desktop\Wormhole BWA59na",
    [string]$Dest = "E:\Aadi"
)

if (-not (Test-Path $Dest)) {
    Write-Error "Destination folder '$Dest' does not exist!"
    exit 1
}

Write-Host "==========================================================" -ForegroundColor Cyan
Write-Host " Real-time Backend Watcher Started" -ForegroundColor Cyan
Write-Host " Watching: backend\, php-backend\, bot_core_locked\" -ForegroundColor Green
Write-Host " Target:   $Dest" -ForegroundColor Green
Write-Host " Protected: HTML files (*.html) and assets/ are ignored" -ForegroundColor Yellow
Write-Host " Press Ctrl+C to stop watching at any time." -ForegroundColor DarkGray
Write-Host "==========================================================" -ForegroundColor Cyan

$foldersToWatch = @("backend", "php-backend", "bot_core_locked")
$watchers = @()

$syncFile = {
    param($fullPath)
    if (-not $fullPath) { return }
    # Ignore node_modules, .git, temp files
    if ($fullPath -match "node_modules|\.git|___jb_tmp___|~|\.tmp") { return }

    # Calculate relative path
    $rel = $fullPath.Substring($Source.Length).TrimStart("\/")
    $targetFile = Join-Path $Dest $rel
    $targetDir = Split-Path $targetFile -Parent

    try {
        if (Test-Path $fullPath -PathType Container) {
            if (-not (Test-Path $targetFile)) {
                New-Item -ItemType Directory -Path $targetFile -Force | Out-Null
            }
        } else {
            if (-not (Test-Path $targetDir)) {
                New-Item -ItemType Directory -Path $targetDir -Force | Out-Null
            }
            # Give short pause in case file is currently being saved by editor
            Start-Sleep -Milliseconds 150
            if (Test-Path $fullPath -PathType Leaf) {
                Copy-Item -Path $fullPath -Destination $targetFile -Force
                $timestamp = Get-Date -Format "HH:mm:ss"
                Write-Host "[$timestamp] Synced: $rel -> E:\Aadi\$rel" -ForegroundColor Green
            }
        }
    } catch {
        # Ignore temporary lock errors
    }
}

foreach ($folder in $foldersToWatch) {
    $dirPath = Join-Path $Source $folder
    if (Test-Path $dirPath) {
        $fsw = New-Object System.IO.FileSystemWatcher
        $fsw.Path = $dirPath
        $fsw.IncludeSubdirectories = $true
        $fsw.NotifyFilter = [System.IO.NotifyFilters]'FileName, LastWrite, Size, DirectoryName'
        $fsw.EnableRaisingEvents = $true

        $action = {
            param($sourceWatcher, $eventArgs)
            & $syncFile $eventArgs.FullPath
        }

        Register-ObjectEvent $fsw "Changed" -Action $action | Out-Null
        Register-ObjectEvent $fsw "Created" -Action $action | Out-Null
        $watchers += $fsw
    }
}

# Also watch root server files
$fswRoot = New-Object System.IO.FileSystemWatcher
$fswRoot.Path = $Source
$fswRoot.IncludeSubdirectories = $false
$fswRoot.NotifyFilter = [System.IO.NotifyFilters]'FileName, LastWrite, Size'
$fswRoot.EnableRaisingEvents = $true
$rootAction = {
    param($sourceWatcher, $eventArgs)
    $name = Split-Path $eventArgs.FullPath -Leaf
    if ($name -in @("router.php", "docker-compose.yml", "Dockerfile", "package.json", "package-lock.json")) {
        & $syncFile $eventArgs.FullPath
    }
}
Register-ObjectEvent $fswRoot "Changed" -Action $rootAction | Out-Null
$watchers += $fswRoot

Write-Host "Watcher is running and listening for file saves...`n" -ForegroundColor DarkCyan

try {
    while ($true) {
        Start-Sleep -Seconds 1
    }
} finally {
    foreach ($w in $watchers) {
        $w.EnableRaisingEvents = $false
        $w.Dispose()
    }
    Get-EventSubscriber | Unregister-Event
    Write-Host "`nBackend watcher stopped." -ForegroundColor Yellow
}
