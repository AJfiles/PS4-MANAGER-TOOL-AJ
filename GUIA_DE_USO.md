# Guía de uso · PS4 Manager Tools 4.5.5

Esta guía complementa el [README principal](README.md), donde están las descargas, el comando Termux, los requisitos, la privacidad y las advertencias de seguridad.

## Primera conexión

1. Activa en GoldHEN **Enable FTP Server** y conecta la PS4 y el teléfono/PC a la misma red.
2. Abre PS4 Manager Tools; usa Radar o escribe la IP y el puerto FTP (habitualmente `2121`).
3. Pulsa **Conectar** y espera a que el indicador confirme la conexión.
4. Si Radar no encuentra la PS4, comprueba que el router no aísle dispositivos y usa la IP manual.

## Lecturas y módulos

- **Biblioteca:** sincroniza para leer títulos y metadatos accesibles por FTP. Si el inventario no parece completo, revisa la conexión y el resultado de la lectura.
- **SaveGames / Limpieza:** inspecciona CUSA, perfil y tamaño antes de respaldar o borrar. Descarga el ZIP verificado y guárdalo fuera del almacenamiento privado de la APK.
- **Almacenamiento / Capturas:** los tamaños son estimaciones FTP. El borrado de capturas depende de los permisos del servidor FTP.
- **Organizar Apps:** crea un respaldo de `app.db` antes de guardar cambios. Recarga perfiles y títulos tras instalar juegos o modificar la estructura XMB.
- **Parches / Plugins:** revisa el diagnóstico y su vista previa antes de reparar o escribir `plugins.ini`. Los parches requieren la configuración de Game Patch en GoldHEN.
- **Explorador FTP:** confirma la ruta y el nombre antes de subir o borrar archivos.
- **Payloads / Ventilador / Actualizaciones FW:** confirma el firmware y lee los avisos. Para payloads activa **Enable BinLoader Server**; la respuesta de envío no confirma por sí sola que el payload se ejecutó.
- **Transferencias RPI:** abre Package Installer en primer plano. RPI está disponible en Termux y PC; el servidor local de la APK no es visible para la PS4.

## Guardar archivos en Android

La APK solicita una ubicación al exportar partidas, respaldos o actualizaciones. En el selector de Android puedes elegir una carpeta existente o crear **PS4 Manager Tools AJ** en el almacenamiento interno. Al terminar una descarga, toca su notificación para abrir el archivo; Android solicitará confirmación para instalar una APK.

En Termux, los datos persistentes del proyecto están en `Almacenamiento interno/PS4 Manager Tools AJ/user/`. En PC, conserva `user/` junto a la aplicación al actualizar.

## Actualizaciones

Abre `Ajustes → Actualizaciones → Verificar`. El botón muestra que está consultando GitHub. En Termux la actualización obtiene el código de la rama principal; en PC descarga el ZIP versionado para extraerlo después de cerrar el servidor; en la APK descarga el instalador firmado y Android requiere confirmar la instalación.

## Verificación local

En Windows, ejecuta `scripts/verificar-proyecto.ps1`. Esa revisión automatiza sintaxis y lógica local; no sustituye pruebas de las acciones de escritura en tu firmware y consola.
