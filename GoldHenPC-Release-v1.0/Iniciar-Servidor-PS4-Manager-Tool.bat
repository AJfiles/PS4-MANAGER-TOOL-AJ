@echo off
setlocal EnableExtensions
chcp 65001 >nul
title PS4 Manager Tools AJ - Servidor PHP
for %%I in ("%~dp0.") do set "ROOT=%%~fI"
set "PHP=%ROOT%\runtime\php.exe"
set "INI=%ROOT%\runtime\php.ini"
if not exist "%PHP%" (
  echo No se encontro runtime\php.exe.
  goto stopped
)
if not exist "%INI%" (
  echo No se encontro runtime\php.ini.
  goto stopped
)
echo PS4 Manager Tools AJ 4.5.13 - servidor local
echo Escuchando en http://0.0.0.0:8080/
echo No cierres esta ventana mientras uses la aplicacion.
echo.
"%PHP%" -c "%INI%" -S 0.0.0.0:8080 -t "%ROOT%" "%ROOT%\server-router.php"
echo.
echo PHP se detuvo con codigo %ERRORLEVEL%. Si ves un error arriba, copia ese mensaje para diagnosticarlo.
:stopped
echo Puedes cerrar esta ventana cuando termines.
pause
endlocal
