# PS4 Manager Tools AJ 4.5.10 para Windows

Esta carpeta incluye el servidor PHP portable y la aplicación local. No instales PHP ni añadas rutas al PATH.

## Inicio

1. Extrae la carpeta completa del ZIP.
2. Ejecuta `Iniciar-PS4-Manager-Tool.bat`.
3. Espera a que el navegador abra `http://127.0.0.1:8080/` y deja abierta la ventana del servidor.
4. Cierra la ventana del servidor para detener la aplicación.

El puerto 8080 escucha en la red local para que la PS4 pueda solicitar archivos RPI. Si Windows muestra el aviso del Firewall, permite el acceso solo en redes privadas. El iniciador no cambia las reglas del Firewall.

## Conectar la PS4

- Conecta la PS4 y el PC a la misma red local.
- Activa **Enable FTP Server** en GoldHEN. El puerto habitual es `2121`; la IP y el puerto también se pueden indicar manualmente.
- Para enviar payloads, activa **Enable BinLoader Server** y usa únicamente un payload compatible con el firmware.
- Para RPI, abre Package Installer en primer plano.

## Datos y actualización

La carpeta `user/` contiene datos locales del usuario. Consérvala al actualizar. Cierra el servidor antes de extraer una nueva versión sobre la carpeta existente. El paquete incluye PHP 8.5.11 x86; si falta el runtime de Visual C++, Windows indicará el motivo al iniciar. El componente oficial es Microsoft Visual C++ Redistributable 2015–2022 x86: https://learn.microsoft.com/en-us/cpp/windows/latest-supported-vc-redist

La aplicación trabaja en la red local. No publiques el servidor en Internet ni reenvíes el puerto 8080 al exterior.

## Advertencia

El software se proporciona “tal cual”, sin garantías. Cambiar `app.db`, plugins, firmware, payloads o borrar archivos puede causar fallos o pérdida de datos. Verifica el firmware, conserva respaldos y úsalo bajo tu responsabilidad.
