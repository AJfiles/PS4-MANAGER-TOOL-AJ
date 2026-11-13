@echo off
setlocal EnableExtensions
chcp 65001 >nul
title PS4 Manager Tools AJ v4.5.11 - PC
color 0B
for %%I in ("%~dp0.") do set "ROOT=%%~fI"
set "PHP=%ROOT%\runtime\php.exe"
set "URL=http://127.0.0.1:8080/"
if not exist "%PHP%" (
  echo No se encontro runtime\php.exe. Extrae de nuevo el ZIP completo.
  pause
  exit /b 1
)
powershell.exe -NoProfile -Command "$c=New-Object Net.Sockets.TcpClient; try {$c.Connect('127.0.0.1',8080); exit 0} catch {exit 1} finally {$c.Dispose()}" >nul 2>&1
if not errorlevel 1 (
  echo El puerto 8080 ya esta ocupado. Cierra el otro servidor PS4 Manager Tools AJ e intenta de nuevo.
  pause
  exit /b 2
)
echo ================================================
echo       PS4 Manager Tools AJ v4.5.11 - PC
echo ================================================
echo Iniciando PHP. Se abrira una segunda ventana del servidor.
echo Si PHP muestra un error, revisa alli el mensaje y runtime Visual C++ x86.
start "" /D "%ROOT%" "%PHP%" -c "%ROOT%\runtime\php.ini" -S 0.0.0.0:8080 -t "%ROOT%" "%ROOT%\server-router.php"
for /L %%I in (1,1,30) do (
  powershell.exe -NoProfile -Command "try {$r=Invoke-WebRequest -UseBasicParsing -TimeoutSec 2 'http://127.0.0.1:8080/'; if ($r.StatusCode -eq 200) {exit 0} else {exit 1}} catch {exit 1}" >nul 2>&1
  if not errorlevel 1 goto ready
  timeout /t 1 /nobreak >nul
)
echo PHP no respondio en el puerto 8080. Revisa la ventana del servidor y el runtime Visual C++ x86.
pause
exit /b 3
:ready
echo Servidor listo: %URL%
start "" "%URL%"
echo Deja abierta la ventana del servidor PHP. Cierrala para detener la aplicacion.
exit /b 0
endlocal
