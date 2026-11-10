# PS4 Manager Tools AJ 4.5.2

PS4 Manager Tools AJ es una aplicación local para administrar una PS4 con GoldHEN desde Android o Windows. Se conecta por FTP a la consola y reúne biblioteca, partidas, capturas, parches, plugins, payloads, ventilador, transferencias RPI y herramientas de archivos.

## Descargas

Elige una de las tres opciones publicadas en [Releases](https://github.com/AJfiles/PS4-MANAGER-TOOL-AJ/releases/latest):

1. **Termux**: instala y ejecuta el gestor en Android. En Termux, pega:

   ```bash
   curl -sL https://raw.githubusercontent.com/AJfiles/PS4-MANAGER-TOOL-AJ/main/goldhen.sh | bash
   ```

2. **APK para Android**: descarga `PS4-Manager-Tools-AJ-v4.5.2.apk` e instálalo. No requiere instalar Termux. Android solicita confirmación del sistema al instalar una actualización descargada desde Ajustes.

3. **Windows**: descarga el ZIP `PS4-Manager-Tools-AJ-PC-v4.5.2.zip`, extráelo y ejecuta `Iniciar-PS4-Manager-Tool.bat`. Mantén abierta la ventana del servidor mientras uses la aplicación.

## Requisitos de conexión

- PS4 y dispositivo conectados a la misma red local.
- GoldHEN con el servidor FTP habilitado; normalmente usa el puerto `2121`.
- Para enviar payloads, habilita también BinLoader en GoldHEN. El envío no confirma que el payload se haya ejecutado correctamente.
- Para instalar PKG por RPI, abre Package Installer en primer plano y habilita el puerto RPI que ofrezca la consola.
- En Termux, acepta el permiso de almacenamiento para buscar PKG y payloads.

La primera conexión puede configurarse manualmente o con Radar. Si el escaneo de la red está limitado, introduce la IP de la PS4 y los puertos manualmente.

## Módulos

- **Biblioteca** lista juegos y aplicaciones que se pueden leer por FTP, con carátulas y detalles disponibles.
- **Partidas** muestra perfiles, IDs, tamaños y partidas; permite crear ZIP de respaldo, descargar, restaurar y eliminar con controles de confirmación.
- **Limpieza** identifica partidas cuyo título no aparece en las ubicaciones de juegos instalados que el FTP pudo leer. Revisa perfil, tamaño y respaldo antes de eliminar.
- **Capturas** muestra la galería FTP, permite descargar y eliminar imágenes.
- **Almacenamiento** estima tamaños de juegos, actualizaciones, DLC, partidas y capturas mediante un recorrido FTP de solo lectura. La PS4 no publica capacidad libre por este servicio.
- **Parches de Juego** lee XML y estados de GoldHEN. La compatibilidad se determina comparando versión del juego y del parche; una incompatibilidad requiere confirmación.
- **Organizar Apps** permite cambiar visibilidad, carpetas y ajustes XMB usando `app.db`, con respaldo local y verificación.
- **Plugins** consulta los archivos `.prx` y `plugins.ini`; crea respaldo antes de cambios.
- **Payloads** envía archivos locales o de la consola por BinLoader y organiza los payloads del proyecto.
- **Ventilador**, **Actualizaciones FW**, **Transferencias**, **Explorador FTP**, **Modding** y **Respaldo** proporcionan controles adicionales.

Algunas rutas de GoldHEN pueden variar por firmware, versión, almacenamiento externo o estado del servidor. La aplicación informa los datos que pudo leer; si una ruta FTP falla, no interpreta la lista como completa.

**Límite del APK Android 4.5.2:** el runtime PHP incluido atiende las páginas en `127.0.0.1`, por lo que la PS4 no puede conectarse al servidor de archivos del teléfono para la descarga RPI. Usa Termux o la versión de PC para RPI; FTP manual y lectura de módulos funcionan desde el APK.

## Actualizaciones

En **Ajustes → Actualizaciones → Verificar** se consulta la última publicación del repositorio. La opción de actualizar Android solo se habilita si Releases ofrece un APK más reciente con su SHA-256. Al descargarlo, abre la notificación de Android y confirma la instalación. También puedes instalar manualmente el APK de la sección Releases.

## Datos y privacidad

La aplicación opera en tu red local. No expongas el servidor PHP de Termux o Windows a Internet ni abras puertos hacia redes públicas. Cachés, archivos locales y respaldos se guardan en el dispositivo que ejecuta la aplicación; no se cargan automáticamente al repositorio.

## Advertencia legal y de seguridad

Este software se proporciona “tal cual”, sin garantías y es de código abierto. El mantenedor “AJ” no se responsabiliza por daños a la PS4, pérdida de datos o violación de términos de servicio.

Los payloads, las modificaciones de `app.db`, los cambios de plugins y las acciones de borrado pueden causar fallos o pérdida de datos si se usan incorrectamente. Úsalo bajo tu propio riesgo. Se recomienda conservar respaldos antes de modificar la consola.
