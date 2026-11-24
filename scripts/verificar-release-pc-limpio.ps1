# Verifica el paquete PC versionado antes de comprimirlo para GitHub Releases.
param([string]$ReleasePath)

$ErrorActionPreference = 'Stop'
$repoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$projectVersion = Get-Content -LiteralPath (Join-Path $repoRoot 'project-version.json') -Raw | ConvertFrom-Json
if ([string]::IsNullOrWhiteSpace($ReleasePath)) {
    $ReleasePath = Join-Path $repoRoot ("PS4-Manager-Tools-AJ-PC-v" + $projectVersion.version)
}
$wrapper = (Resolve-Path -LiteralPath $ReleasePath).Path
$expectedWrapperName = "PS4-Manager-Tools-AJ-PC-v$($projectVersion.version)"
if ((Split-Path -Leaf $wrapper) -ne $expectedWrapperName) {
    throw "La carpeta contenedora debe llamarse $expectedWrapperName."
}
$appDir = Join-Path $wrapper 'PS4-Manager-Tools-AJ-PC'
$launcherPath = Join-Path $wrapper 'Iniciar-PS4-Manager-Tool.bat'
if (-not (Test-Path -LiteralPath $appDir -PathType Container) -or -not (Test-Path -LiteralPath $launcherPath -PathType Leaf)) {
    throw 'El ZIP debe mostrar la carpeta de la app y un único BAT en la carpeta contenedora.'
}
$topEntries = @(Get-ChildItem -LiteralPath $wrapper -Force)
if ($topEntries.Count -ne 2 -or @($topEntries | Where-Object { $_.Name -notin @('PS4-Manager-Tools-AJ-PC', 'Iniciar-PS4-Manager-Tool.bat') }).Count) {
    throw 'La carpeta contenedora solo puede incluir la carpeta PS4-Manager-Tools-AJ-PC y su BAT.'
}
$release = $appDir
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
$releaseVersion = Get-Content -LiteralPath (Join-Path $release 'project-version.json') -Raw | ConvertFrom-Json
if ($releaseVersion.version -ne $projectVersion.version) {
    throw "El paquete PC es v$($releaseVersion.version), pero el proyecto es v$($projectVersion.version)."
}
$launchers = @(Get-ChildItem -LiteralPath $wrapper -Filter '*.bat' -File -Recurse)
if ($launchers.Count -ne 1 -or $launchers[0].FullName -ne $launcherPath) {
    throw 'El paquete PC debe contener un solo lanzador: Iniciar-PS4-Manager-Tool.bat.'
}
$launcherSource = Get-Content -LiteralPath $launcherPath -Raw
if ($launcherSource -notmatch 'set "APP_DIR=%~dp0PS4-Manager-Tools-AJ-PC"' -or $launcherSource -notmatch ('v' + [regex]::Escape($projectVersion.version))) {
    throw 'El BAT debe iniciar la carpeta interna y mostrar la versión del proyecto.'
}
foreach ($iconPath in @('js\icon-192.png', 'js\icon-512.png')) {
    if (-not (Test-Path -LiteralPath (Join-Path $release $iconPath) -PathType Leaf)) { throw "Falta el icono $iconPath" }
}

Write-Host "PC_RELEASE_READY_TO_ZIP: $wrapper" -ForegroundColor Green
