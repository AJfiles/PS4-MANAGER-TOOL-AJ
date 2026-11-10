# 🎮 PS4 Manager Tools 4.5.4

**Administra tu PS4 con GoldHEN desde Android o Windows.** PS4 Manager Tools reúne en una sola interfaz local la biblioteca, las partidas, las capturas, los parches, los plugins, los payloads, el almacenamiento y las herramientas de red y archivos.

📡 Se conecta directamente a la consola por la red local y FTP. No requiere una cuenta, no envía tus archivos a un servicio en la nube y ofrece tres formas de uso: **Termux**, **APK Android** y **Windows**.

## ⬇️ Descargas

La descarga más reciente y sus notas están en [GitHub Releases](https://github.com/AJfiles/PS4-MANAGER-TOOL-AJ/releases/latest).

### 📱 Termux — Android

Instala Termux desde una fuente vigente, abre la terminal y pega este comando:

```bash
curl -sL https://raw.githubusercontent.com/AJfiles/PS4-MANAGER-TOOL-AJ/main/goldhen.sh | bash
```

El instalador prepara las dependencias, inicia PS4 Manager Tools y abre el panel local. En instalaciones nuevas, crea **Almacenamiento interno/PS4 Manager Tools AJ/user/** para los archivos locales y respaldos. Si ya tenías datos en la carpeta antigua, el instalador conserva y enlaza esa carpeta para no perderlos.

### 📦 APK — Android

Descarga **PS4-Manager-Tools-AJ-v4.5.4.apk** desde Releases e instálalo. La aplicación se identifica en Android como **PS4 Manager Tools AJ** y dentro de la interfaz aparece como **PS4 Manager Tools**. No necesitas instalar Termux.

Los archivos que exportes se guardan en **Descargas** de Android. Los datos de trabajo que mantiene el servidor PHP quedan en el espacio privado y persistente de la aplicación; desinstalarla puede eliminar esos datos, así que descarga los respaldos que quieras conservar.

### 🖥️ PC — Windows

Descarga **PS4-Manager-Tools-AJ-PC-v4.5.4.zip**, descomprímelo y ejecuta `Iniciar-PS4-Manager-Tool.bat`. El paquete incluye PHP portable; no necesitas configurar PHP ni añadirlo al PATH. Mantén abierta la ventana del servidor mientras uses la aplicación.

## ✅ Requisitos y conexión

- Una PS4 con GoldHEN y el servidor FTP habilitado (habitualmente puerto **2121**).
- El teléfono o PC y la consola conectados a la misma red local.
- Para payloads, habilita **Enable BinLoader Server** en los ajustes de servidores de GoldHEN. El envío confirma los bytes transmitidos, no que el payload se haya ejecutado.
- Para instalación RPI, abre **Package Installer** en primer plano. El APK Android no puede exponer su servidor local a la PS4; usa Termux o la versión de PC para RPI.
- Termux necesita sus dependencias y permiso de almacenamiento para localizar archivos del dispositivo.

La conexión se puede configurar con Radar o manualmente mediante IP y puerto. Algunos routers, redes de invitados o reglas de aislamiento impiden el descubrimiento automático; en ese caso, usa la IP de la consola.

## ✨ Qué puedes hacer

| Módulo | Funciones |
| --- | --- |
| **Biblioteca** | Lista juegos y aplicaciones reconocibles por FTP, muestra carátulas y detalles disponibles, permite organizar la vista y acceder a herramientas por título. |
| **SaveGames** | Consulta perfiles y partidas, muestra título/CUSA y tamaño, crea ZIP de respaldo, descarga, restaura o elimina con confirmación. |
| **Limpieza** | Identifica partidas cuyo título no aparece entre los juegos leídos como instalados. Permite revisar tamaño y selección antes de eliminar. |
| **Almacenamiento** | Resume el espacio que se pudo medir para juegos, actualizaciones, DLC, partidas y capturas; permite inspeccionar los elementos que ocupan espacio. Los tamaños son estimaciones FTP y la capacidad libre no está disponible por esta interfaz. |
| **Capturas** | Explora la galería de la consola, descarga imágenes y elimina capturas seleccionadas cuando el FTP permite esa operación. |
| **Parches de Juego** | Consulta archivos XML y estados disponibles de GoldHEN, compara versión del juego con la del parche y solicita confirmación para casos no compatibles o inciertos. |
| **Organizar Apps** | Organiza carpetas, visibilidad y estructura XMB mediante `app.db`, con lectura, respaldos y guardado de cambios. |
| **Plugins** | Lee plugins locales/remotos y `plugins.ini`, permite asignarlos por juego o de forma global, diagnosticar, importar/exportar y respaldar cambios. |
| **Payloads** | Explora payloads locales y de la consola, permite seleccionar un archivo del dispositivo, instalarlo en la PS4 o enviarlo por BinLoader. |
| **Ventilador** | Envía payloads disponibles para los firmwares incluidos. Requiere comprobar firmware y aceptar el aviso de riesgo antes de usarlo. |
| **Actualizaciones FW** | Permite activar o bloquear la actualización de sistema con avisos y confirmación adicional para habilitarla. |
| **Transferencias** | Sube archivos por FTP o instala PKG mediante RPI en los entornos compatibles; muestra estado y registro de la operación. |
| **Explorador FTP** | Navega archivos y carpetas, descarga/sube elementos y ofrece acciones de gestión según las capacidades del FTP. |
| **Modding** | Administra portadas, imágenes y recursos asociados a juegos. |
| **Respaldo** | Crea y restaura copias de datos del proyecto y de las funciones que lo admiten. |
| **Ajustes** | Personaliza idioma, audio, aspecto, animaciones y dispositivo; consulta la versión y busca actualizaciones. |

La lectura depende de las rutas y permisos que exponga cada firmware, versión de GoldHEN y FTP. Si una carpeta no se pudo leer, el resultado puede ser parcial: la interfaz muestra errores o límites detectados en lugar de afirmar que el inventario está completo.

## 🔄 Actualizaciones

- **APK:** `Ajustes → Actualizaciones → Verificar` consulta GitHub Releases. Si hay una versión con APK y SHA-256 válidos, **Actualizar** descarga el archivo a Descargas. Ábrelo y confirma la instalación en Android; el sistema siempre requiere autorización del usuario.
- **Termux:** `Verificar → Actualizar` obtiene los cambios de la rama principal mediante Git y recarga la página al finalizar.
- **PC:** el verificador consulta el ZIP de PC y su SHA-256 en Releases. Descarga el ZIP, cierra el servidor y extrae el contenido sobre la carpeta actual. Conserva `user/`; Windows no puede reemplazar con seguridad todos los archivos mientras el servidor está ejecutándose.

También puedes instalar manualmente la versión deseada desde [Releases](https://github.com/AJfiles/PS4-MANAGER-TOOL-AJ/releases).

## 📁 Dónde quedan tus archivos

- **Termux:** `Almacenamiento interno/PS4 Manager Tools AJ/user/`. En la actualización de una instalación anterior, se conserva la carpeta de datos existente y se mantiene un enlace compatible.
- **APK:** el servidor guarda su estado persistente en el espacio privado de la app; los archivos exportados se guardan en **Descargas**. Android restringe la escritura libre en la raíz compartida del teléfono.
- **PC:** `user/` dentro de la carpeta extraída. Mantén esa carpeta al actualizar el programa.

## 🔒 Datos y privacidad

La conexión PS4 se realiza en la red local. El actualizador consulta información pública de GitHub Releases; no sube partidas, capturas, direcciones IP ni contenido de la consola. Los archivos que eliges descargar o enviar permanecen bajo tu control. No expongas el servidor de Termux o Windows a Internet ni abras sus puertos hacia redes públicas.

## ⚠️ Advertencia legal y de seguridad

Este software se proporciona “tal cual”, sin garantías y es de código abierto. El mantenedor “AJ” no se responsabiliza por daños a la PS4, pérdida de datos o incumplimiento de términos de servicio.

Los payloads, modificaciones de `app.db`, cambios de plugins, ajustes de firmware y acciones de borrado pueden provocar fallos o pérdida de datos si se usan incorrectamente. Úsalo bajo tu propio riesgo y conserva respaldos antes de modificar la consola.

## 🧰 Verificación para colaboradores

La revisión local del proyecto está disponible en `scripts/verificar-proyecto.ps1`. La compatibilidad real de funciones FTP, payloads y RPI requiere probarlas en una PS4; las comprobaciones locales no sustituyen esa validación de hardware.
