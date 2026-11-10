<#
Creates a feature update for an existing bet1x PHP deployment. No environment files or test data.
Run from any working directory: powershell -File php-backend/tools/package-originals.ps1
#>
$ErrorActionPreference = 'Stop'
$projectRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$archivePath = Join-Path $projectRoot 'originals-update.zip'
$gameKeys = @('dice','coinflip','wheel','plinko','keno','roulette','baccarat','dragontiger','andarbahar','slots','blackjack','matka')
$files = @(
    'assets/js/lobby-covers.js','assets/css/style.css',
    '5c0dc4b9b16ab70957dd.html','assets/js/superadmin-accounts.js','assets/js/ui-common.js',
    'auth.html','profile.html','subadmin.html',
    'php-backend/routes/account_management.php','php-backend/routes/auth.php','php-backend/routes/gamesync.php',
    'php-backend/routes/legacy.php','php-backend/routes/otp.php','php-backend/routes/password_reset.php','php-backend/routes/phone_reset.php',
    'php-backend/config.php','php-backend/lib/otp.php','php-backend/lib/sms.php','php-backend/lib/mailer.php',
    'index.html','admin.html','ORIGINALS-LICENSE.md',
    'assets/css/originals.css','assets/js/originals.js','assets/js/admin-originals.js','assets/js/game-shell.js',
    'assets/css/dragontiger.css','assets/js/dragontiger.js',
    'php-backend/lib/originals_shared.php','php-backend/lib/cron.php',
    'php-backend/index.php','php-backend/lib/originals.php','php-backend/lib/riskcontrols.php','php-backend/lib/helpers.php',
    'php-backend/routes/originals.php','php-backend/routes/admin.php','php-backend/routes/profile.php','php-backend/routes/settings.php',
    'php-backend/sql/migration-019-originals-postgres.sql','php-backend/sql/schema-mysql.sql',
    'astronaut.html','aviator.html','boundarybaazi.html','cashier.html','chickenroad.html','cricket.html',
    'mining.html','teenpatti.html','towerrush.html','win.html','win1.html','win2.html','win3.html','youreleven.html'
)
foreach ($key in $gameKeys) {
    $files += "$key.html"
    $files += "assets/game_$key.webp"
    $files += "php-backend/games/originals/$key.php"
}
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
$stream = [IO.File]::Open($archivePath, [IO.FileMode]::Create)
$zip = [IO.Compression.ZipArchive]::new($stream, [IO.Compression.ZipArchiveMode]::Create)
try {
    foreach ($relative in $files) {
        $absolute = [IO.Path]::GetFullPath((Join-Path $projectRoot $relative))
        if (-not $absolute.StartsWith($projectRoot + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)) { throw 'Archive source is outside the project.' }
        [IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, $absolute, $relative, [IO.Compression.CompressionLevel]::Optimal) | Out-Null
    }
} finally { $zip.Dispose(); $stream.Dispose() }
Write-Output "Packaged $($files.Count) files into originals-update.zip."
