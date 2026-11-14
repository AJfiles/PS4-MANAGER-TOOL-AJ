# Plugin auxiliar de PS4 Manager Tools

## Resumen de viabilidad

Un plugin residente puede añadir funciones que corran dentro de procesos de PS4, pero no es un acelerador general del FTP ni una forma genérica de ejecutar aplicaciones. El Manager es una aplicación cliente: lee/escribe archivos por FTP y envía payloads por BinLoader. Un PRX dentro de la consola no aumenta el ancho de banda Wi-Fi/Ethernet del teléfono o PC ni corrige el rendimiento del servidor FTP de GoldHEN.

La opción viable es un complemento explícito y opcional para tareas que necesiten código ejecutándose en la PS4. Debe instalarse una vez mediante una ruta de carga compatible, validarse por hash/versión y añadirse a `plugins.ini` solo tras consentimiento. En conexiones siguientes se compara archivo, hash y línea de configuración y se omite cualquier escritura si ya están correctos. Las actualizaciones deben respaldar el INI, hacer escritura atómica/validada cuando el FTP lo permita y restaurar el original si la verificación falla.

## Qué podría aportar

- Un pequeño endpoint local para exponer estado que GoldHEN no entregue por FTP, si el plugin dispone de una API documentada y segura.
- Señales de proceso/juego activo para adaptar la interfaz del Manager.
- Lanzamiento de una aplicación únicamente si se implementa una llamada de sistema adecuada a la versión y se conoce un método fiable para obtener los permisos necesarios.

Cada punto requiere código específico de PS4, ABI y firmware. Un PRX no puede convertir automáticamente la app web en una interfaz privilegiada; tampoco debe instalarse en silencio al conectar.

## Lanzar aplicaciones

La interfaz puede preparar una acción por CUSA y pedir a un componente privilegiado que la ejecute, pero el Manager web por sí solo no tiene una API universal de GoldHEN para abrir cualquier app. Antes de implementar un lanzador hace falta una fuente técnica verificable para la llamada (o payload) por firmware, reglas de permisos y pruebas en una PS4 de prueba. La pantalla debe distinguir “orden aceptada” de “aplicación confirmada en ejecución”. No se debe afirmar éxito por el mero envío del socket.

## Límites y riesgos

- Instalar PRX/payload requiere que BinLoader o un mecanismo alterno esté funcionando; un plugin no puede reparar por sí mismo el canal que debe cargarlo inicialmente.
- Un plugin incompatible puede causar cierres o impedir que arranquen juegos; necesita lista de firmware/modelo probado, interruptor de desactivación y recuperación por FTP.
- La edición de `plugins.ini` debe usar parser, respaldo, deduplicación y lectura de retorno. El proyecto ya tiene diagnóstico y reparación verificada de formato; no añadiría una línea de plugin a cada conexión.
- Para mejorar FTP, la prioridad debe ser medir transferencia y reducir trabajo del cliente. El rendimiento depende del enlace de red, almacenamiento de origen/destino, firmware y servidor FTP; un PRX solo ayudaría si sustituye el servidor o implementa otro protocolo probado.

## Decisión para el proyecto

No existe base suficiente para distribuir un “plugin universal de optimización” ni autoinstalarlo. La arquitectura recomendada es mantener las operaciones actuales sin dependencia de PRX y tratar cualquier futuro plugin como un módulo opcional, firmware-específico, con instalación manual confirmada, hashes, respaldo, botón de retirar y procedimiento de recuperación. El lanzador de apps debe investigarse por separado y comenzar con soporte experimental explícito, no presentarse como universal.

## Estado de investigación

Esta nota es análisis de viabilidad; no contiene un PRX ni payload y no se ha probado código nuevo dentro de la consola. La consola disponible se usó para lecturas FTP y pruebas de red previamente documentadas, nunca para instalar un plugin auxiliar.

## Verificacion con documentacion actual de GoldHEN (2026-11-14)

El repositorio oficial GoldHEN_Plugins_Repository describe PRX cargados antes de iniciar juegos. Su guia requiere GoldHEN 2.3 o superior, activar la carga de plugins en el menu Plugins, guardar los PRX en /data/GoldHEN/plugins/ y referenciarlos desde /data/GoldHEN/plugins.ini; recomienda secciones por CUSA frente a [default]. Por ello un PRX cargado asi puede extender o instrumentar juegos, pero no se convierte por ese acto en un daemon disponible al abrir el Manager en el menu XMB ni en un acelerador del servidor FTP.

La guia de compilacion enumera LLVM 10+, OpenOrbis SDK y GoldHEN Plugin SDK. La carga exige habilitar plugins en la consola. El Manager podria automatizar la copia y la edicion idempotente del INI despues de una seleccion explicita, con hash/version, respaldo, lectura de retorno y opcion de retirar; la instalacion automatica al conectar seguiria siendo enga�osa porque escribir el archivo no confirma que GoldHEN ya lo cargara. Primero debe existir un PRX concreto, firmado/construible, con objetivo y firmware definidos.

La documentacion de releases actual consultada enumera soporte oficial hasta 11.00. No se usara esta investigacion para atribuir compatibilidad a 14.00 ni para habilitar instalacion remota sin una build probada.

Fuentes primarias: https://github.com/GoldHEN/GoldHEN_Plugins_Repository/blob/main/.github/README.md y https://github.com/GoldHEN/GoldHEN/releases.
