# Builds a Windows ZIP whose extracted wrapper shows only the app folder and one BAT.
param(
    [string]$SourceDirectory,
    [string]$OutputZip
)

$ErrorActionPreference = 'Stop'
$repoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$projectVersion = Get-Content -LiteralPath (Join-Path $repoRoot 'project-version.json') -Raw | ConvertFrom-Json
$version = [string]$projectVersion.version
$appName = 'PS4-Manager-Tools-AJ-PC'
$wrapperName = "PS4-Manager-Tools-AJ-PC-v$version"

if ([string]::IsNullOrWhiteSpace($SourceDirectory)) {
    $SourceDirectory = Join-Path $repoRoot $wrapperName
}
$source = (Resolve-Path -LiteralPath $SourceDirectory).Path
if ([string]::IsNullOrWhiteSpace($OutputZip)) {
    $OutputZip = Join-Path $repoRoot "$wrapperName.zip"
}
$zipFull = [System.IO.Path]::GetFullPath($OutputZip)
if (-not $zipFull.StartsWith($repoRoot + [System.IO.Path]::DirectorySeparatorChar, [System.StringComparison]::OrdinalIgnoreCase)) {
    throw "El ZIP de salida debe quedar dentro del proyecto: $zipFull"
}
$stage = Join-Path $repoRoot (".pc-release-stage-" + $version)
$wrapper = Join-Path $stage $wrapperName
$app = Join-Path $wrapper $appName

if (Test-Path -LiteralPath $stage) {
    $resolvedStage = (Resolve-Path -LiteralPath $stage).Path
    if (-not $resolvedStage.StartsWith($repoRoot + [System.IO.Path]::DirectorySeparatorChar, [System.StringComparison]::OrdinalIgnoreCase) -or (Split-Path -Leaf $resolvedStage) -ne ".pc-release-stage-$version") {
        throw "Ruta temporal de empaquetado inesperada: $resolvedStage"
    }
    Remove-Item -LiteralPath $resolvedStage -Recurse -Force
}
New-Item -ItemType Directory -Path $app -Force | Out-Null
Get-ChildItem -LiteralPath $source -Force | ForEach-Object {
    Copy-Item -LiteralPath $_.FullName -Destination $app -Recurse -Force
}

$innerLauncher = Join-Path $app 'Iniciar-PS4-Manager-Tool.bat'
if (-not (Test-Path -LiteralPath $innerLauncher -PathType Leaf)) {
    throw "Falta el BAT fuente: $innerLauncher"
}
Remove-Item -LiteralPath $innerLauncher -Force

$launcher = @"
@echo off
setlocal EnableExtensions
chcp 65001 >nul
set "APP_DIR=%~dp0$appName"
for %%I in ("%APP_DIR%") do set "ROOT=%%~fI"
set "PHP=%ROOT%\runtime\php.exe"
set "INI=%ROOT%\runtime\php.ini"
set "URL=http://127.0.0.1:8080/"
if not defined LOCALAPPDATA (
  echo Windows no definio LOCALAPPDATA.
  pause
  exit /b 4
)
set "PHP_OPCACHE_CACHE_DIR=%LOCALAPPDATA%\PS4 Manager Tools AJ\opcache"
if not exist "%PHP%" (
  echo No se encontro PHP portable. Vuelve a extraer el ZIP completo.
  pause
  exit /b 1
)
if not exist "%INI%" (
  echo No se encontro runtime\php.ini. Vuelve a extraer el ZIP completo.
  pause
  exit /b 1
)
if /i "%~1"=="/server" goto php_server
title PS4 Manager Tools AJ v$version - PC
color 0B
powershell.exe -NoProfile -Command "$c=New-Object Net.Sockets.TcpClient; try {$c.Connect('127.0.0.1',8080); exit 0} catch {exit 1} finally {$c.Dispose()}" >nul 2>&1
if not errorlevel 1 (
  echo El puerto 8080 ya esta ocupado. Cierra el otro servidor PS4 Manager Tools AJ e intenta de nuevo.
  pause
  exit /b 2
)
echo ================================================
echo       PS4 Manager Tools AJ v$version - PC
echo ================================================
echo Iniciando el servidor PHP local.
echo La ventana del servidor permanecera abierta mientras uses la app.
start "PS4 Manager Tools AJ - Servidor PHP" /D "%ROOT%" "%ComSpec%" /k ""%~f0" /server"
for /L %%I in (1,1,30) do (
  powershell.exe -NoProfile -Command "try {$r=Invoke-WebRequest -UseBasicParsing -TimeoutSec 2 'http://127.0.0.1:8080/'; if ($r.StatusCode -eq 200) {exit 0} else {exit 1}} catch {exit 1}" >nul 2>&1
  if not errorlevel 1 goto ready
  timeout /t 1 /nobreak >nul
)
echo PHP no respondio en el puerto 8080. Revisa la ventana del servidor.
pause
exit /b 3
:ready
echo Servidor listo: %URL%
start "" "%URL%"
echo Deja abierta la ventana del servidor. Cierrala para detener la aplicacion.
exit /b 0
:php_server
title PS4 Manager Tools AJ - Servidor local
color 0B
if not exist "%PHP_OPCACHE_CACHE_DIR%" mkdir "%PHP_OPCACHE_CACHE_DIR%" >nul 2>&1
if not exist "%PHP_OPCACHE_CACHE_DIR%" (
  echo No se pudo crear la cache local de PHP.
  pause
  exit /b 4
)
echo PS4 Manager Tools AJ v$version - servidor local
echo Escuchando en http://0.0.0.0:8080/
echo No cierres esta ventana mientras uses la aplicacion.
echo.
"%PHP%" -c "%INI%" -d "opcache.file_cache=%PHP_OPCACHE_CACHE_DIR%" -d opcache.file_cache_fallback=1 -S 0.0.0.0:8080 -t "%ROOT%" "%ROOT%\server-router.php"
echo.
echo PHP se detuvo con codigo %ERRORLEVEL%. Copia cualquier error mostrado arriba.
pause
exit /b 0
endlocal
"@
Set-Content -LiteralPath (Join-Path $wrapper 'Iniciar-PS4-Manager-Tool.bat') -Value $launcher -Encoding Ascii

if (Test-Path -LiteralPath $zipFull -PathType Leaf) { Remove-Item -LiteralPath $zipFull -Force }
Compress-Archive -Path $wrapper -DestinationPath $zipFull -CompressionLevel Optimal
& (Join-Path $PSScriptRoot 'verificar-release-pc-limpio.ps1') -ReleasePath $wrapper
Write-Host "PC_ZIP=$zipFull" -ForegroundColor Green
