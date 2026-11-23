param(
    [string]$OutputDirectory = 'C:\Users\Administrator\Desktop\GoldHenManagerAJ-main\temporal carpeta\objetivo-adjunto-2026-11-09\android-source-4.5.17'
)

$ErrorActionPreference = 'Stop'
$repoRoot = Split-Path -Parent $PSScriptRoot
$stage = [System.IO.Path]::GetFullPath($OutputDirectory)
$archive = "$stage.zip"

if (Test-Path -LiteralPath $stage) {
    throw "La carpeta de salida ya existe; usa una ruta vacia: $stage"
}
New-Item -ItemType Directory -Path $stage | Out-Null

$directories = @('api', 'js', 'modulos', 'payloads', 'payloads fw', 'UniversalPayloadsFW_FAN', 'plugins', 'store')
foreach ($name in $directories) {
    $source = Join-Path $repoRoot $name
    if (Test-Path -LiteralPath $source) { Copy-Item -LiteralPath $source -Destination $stage -Recurse -Force }
}

$files = @('index.php', 'manifest.json', 'project-version.json', 'server-router.php', 'store.php', 'sw.js', 'README.md', 'MEJORAS_IMPLEMENTADAS.txt')
foreach ($name in $files) {
    $source = Join-Path $repoRoot $name
    if (Test-Path -LiteralPath $source) { Copy-Item -LiteralPath $source -Destination $stage -Force }
}

if (Test-Path -LiteralPath $archive) { Remove-Item -LiteralPath $archive -Force }
Compress-Archive -Path (Join-Path $stage '*') -DestinationPath $archive -CompressionLevel Optimal
$version = Get-Content -LiteralPath (Join-Path $stage 'project-version.json') -Raw | ConvertFrom-Json
if ($version.version -ne '4.5.17') { throw "Fuente preparada con version inesperada: $($version.version)" }
$sha = [System.Security.Cryptography.SHA256]::Create()
$stream = [System.IO.File]::OpenRead($archive)
try { $hash = ([System.BitConverter]::ToString($sha.ComputeHash($stream))).Replace('-', '').ToLowerInvariant() }
finally { $stream.Dispose(); $sha.Dispose() }
Write-Output "ANDROID_SOURCE_VERSION=$($version.version)"
Write-Output "ANDROID_SOURCE_DIR=$stage"
Write-Output "ANDROID_SOURCE_ZIP=$archive"
Write-Output "ANDROID_SOURCE_SHA256=$hash"
