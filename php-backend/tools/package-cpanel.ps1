$ErrorActionPreference = 'Stop'
$projectRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
Push-Location $projectRoot
try {
    $files = @(git ls-files) + @('assets/js/superadmin-accounts.js','php-backend/routes/account_management.php')
    $files = $files | Sort-Object -Unique | Where-Object {
        ($_ -match '^[^/]+\.html$' -or $_ -eq '.htaccess' -or $_ -eq 'ORIGINALS-LICENSE.md' -or $_ -match '^(assets/|hen-studio/src/|php-backend/)') -and
        $_ -notmatch '(^|/)(\.env($|\.)|node_modules/|data/|tests/|tools/|test_[^/]*$|DEPLOY\.md$)'
    }
    Add-Type -AssemblyName System.IO.Compression
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $stream = [IO.File]::Open((Join-Path $projectRoot 'cpanel_deploy.zip'), [IO.FileMode]::Create)
    $zip = [IO.Compression.ZipArchive]::new($stream, [IO.Compression.ZipArchiveMode]::Create)
    try {
        foreach ($relative in $files) {
            $absolute = [IO.Path]::GetFullPath((Join-Path $projectRoot $relative))
            if (-not $absolute.StartsWith($projectRoot + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)) { throw 'Archive source is outside workspace.' }
            [IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, $absolute, $relative, [IO.Compression.CompressionLevel]::Optimal) | Out-Null
        }
    } finally { $zip.Dispose(); $stream.Dispose() }
    Write-Output "Packaged $($files.Count) runtime files; private environments excluded."
} finally { Pop-Location }
