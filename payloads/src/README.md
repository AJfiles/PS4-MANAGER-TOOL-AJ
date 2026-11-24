# Control de Apps: payload y daemon

`app_control_daemon.c` es el código fuente que produce `../app_control.elf`. El helper atiende consultas manuales por TCP 9091. El servidor BinLoader de GoldHEN (9090) solo se usa para enviar el ELF; el cliente no hace una conexión vacía de prueba. Tras el único envío, la web espera una respuesta de salud por 9091 durante un plazo limitado y no reintenta automáticamente.

## Compilación reproducible en Windows

La compilación necesita el PS4 Payload SDK v0.9 y un NDK Android que incluya Clang/LLD. Son dependencias de desarrollo; no hacen falta a los usuarios de la aplicación. No se distribuyen claves privadas ni se incorpora el SDK al proyecto.

```powershell
.\scripts\build-app-control-payload.ps1 `
  -SdkRoot 'C:\ruta\al\ps4-payload-sdk' `
  -NdkRoot 'C:\ruta\al\android-sdk\ndk\28.2.13676358'
```

El script compila con `-Wall -Wextra -Werror`, enlaza con las bibliotecas del SDK y comprueba la cabecera ELF. El nombre/versión del firmware PS4 debe validarse en consola; que el ELF enlace no demuestra compatibilidad de ejecución.

## Límites operativos y seguridad

- Estado: informa el ID de app mediante `sceSystemServiceGetAppIdOfBigApp` y `sceLncUtilGetAppTitleId`. La aplicación web puede usar FTP como respaldo para obtener el montaje, nombre e icono.
- Apertura: solicita el lanzamiento y solo informa confirmado si una lectura posterior ve el mismo Title ID en primer plano. Una aceptación sin confirmación se comunica como pendiente.
- Intervalo de seguridad: el daemon 1.2.1 bloquea nuevas aperturas o cierres durante 40 segundos después de una acción aceptada; guarda el instante localmente para que reiniciar el servicio no elimine el intervalo.
- Cierre: no se encontró una interfaz verificada de cierre ordenado. La ruta forzada exige confirmación, vuelve a comprobar la identidad activa en el daemon y protege los Title ID `NPXS`.
- 9091 escucha en todas las interfaces LAN y no incorpora autenticación. Úsese únicamente en una red privada de confianza; no se debe exponer a Internet ni reenviar el puerto desde el router.
- No habilitar AutoRun durante pruebas sin autorización separada. La opción web realiza copia de respaldo y lectura de verificación de `payloads.ini`; el uso real sigue siendo persistente entre reinicios.
- Un ELF compilado y una respuesta de envío no prueban que el firmware acepte o ejecute el payload. Solo una respuesta de salud real en 9091 confirma que el daemon arrancó.
