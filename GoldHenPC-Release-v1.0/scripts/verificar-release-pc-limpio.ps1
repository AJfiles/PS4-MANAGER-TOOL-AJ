# Ejecutar desde la raíz del proyecto antes de comprimir GoldHenPC-Release-v1.0.
param([string]$ReleasePath = (Join-Path $PSScriptRoot '..\GoldHenPC-Release-v1.0'))

$ErrorActionPreference = 'Stop'
$release = (Resolve-Path -LiteralPath $ReleasePath).Path
$userDir = Join-Path $release 'user'
if (-not (Test-Path -LiteralPath $userDir -PathType Container)) {
    throw "Falta el directorio runtime user: $userDir"
}

$allowedMarkers = @('.gitkeep', '.nomedia', '.last_cleanup')
$unexpected = @(Get-ChildItem -LiteralPath $userDir -Recurse -File -Force | Where-Object { $_.Name -notin $allowedMarkers })
if ($unexpected.Count) {
    Write-Host 'El paquete contiene datos locales que no se deben distribuir:' -ForegroundColor Red
    $unexpected | ForEach-Object { Write-Host " - $($_.FullName) ($($_.Length) bytes)" -ForegroundColor Red }
    exit 1
}

$manifestPath = Join-Path $release 'manifest.json'
$manifest = Get-Content -LiteralPath $manifestPath -Raw | ConvertFrom-Json
if ($manifest.name -ne 'PS4 Manager Tools' -or $manifest.short_name -ne 'PS4 Manager') {
    throw 'El nombre PWA no coincide con PS4 Manager Tools.'
}
foreach ($iconPath in @('js\icon-192.png', 'js\icon-512.png')) {
    if (-not (Test-Path -LiteralPath (Join-Path $release $iconPath) -PathType Leaf)) { throw "Falta el icono $iconPath" }
}

Write-Host "PC_RELEASE_READY_TO_ZIP: $release" -ForegroundColor Green
