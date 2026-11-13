# Payloads universales y PS4 Temperature

Fecha de revisión: 2026-11-13

## Conclusión

El Manager puede enumerar y enviar payloads existentes mediante BinLoader, pero eso no convierte un payload en compatible con todos los firmwares. No se generó ni se envió un payload universal para ventilador o actualizaciones: los dos PKG entregados no incluyen una fuente verificable de esas rutinas y no prueban compatibilidad hasta 14.00.

Un payload de ventilador o de bloqueo de actualizaciones que parchea memoria del kernel depende de código, estructuras, offsets, versión de GoldHEN y, a veces, modelo/revisión de hardware. Para admitir un firmware nuevo se necesita una implementación fuente o una compilación cuya matriz de compatibilidad sea explícita y pruebas en una consola de ese firmware. Cambiar únicamente el nombre de un archivo o ampliar una lista de selección no añade compatibilidad.

## PKG inspeccionados

| Archivo | SHA-256 | Evidencia recuperada |
| --- | --- | --- |
| `PS4_LAPY20006_v1.06.pkg` | `4F59530085BF931EC7387891C0D60DAEC4A7DE2E25BBE0C45A0431C9E8D9DE17` | SFO: `PS4 Temperature`, Title ID `LAPY20006`, versión 1.06, SDK `04508000`. |
| `PS4_LAPY20006_v1.07.pkg` | `246B57EA44B68BDF48318A2DA425B0888361E84884D99E21038F7FB42E4FA166` | SFO: `PS4 Temperature`, Title ID `LAPY20006`, versión 1.07, SDK `04508000`. |

En ambas extracciones, PkgTool informó que los campos **Content Digest** y **Major Param Digest** no coinciden. Por tanto, estos archivos no se consideran autenticados ni se recomienda instalarlos en la consola a modo de prueba.

El `eboot.bin` extraído de las dos versiones tiene el mismo SHA-256 (`5090E7D0FD53C9707977C0BD19ADD6F5A30475BB23B3022EC6566A92B939AB7A`). Esto permite afirmar que el ejecutable es idéntico entre esas dos muestras; no permite inferir su lista de firmwares admitidos. La etiqueta de SDK tampoco constituye una matriz de compatibilidad.

## Firmware 14.00 y alcance de GoldHEN

El changelog de la beta GoldHEN v2.4b18.13 declara soporte de 14.00. Ese soporte de GoldHEN no certifica automáticamente payloads de terceros. El repositorio de ejemplo de fan control `grimstatic/payload-fan-control-ps4` declara pruebas en una PS4 Pro CUH-7106B con firmware 12.52 y una sola consola de prueba. Esa evidencia no respalda 14.00 ni todos los modelos.

Fuentes primarias consultadas:

- [GoldHEN releases](https://github.com/GoldHEN/GoldHEN/releases)
- [Changelog v2.4b18.13](https://github.com/EEJTek/GoldHEN_v2.4b18.12/blob/main/CHANGELOG.md)
- [Fan Control payload y su alcance de prueba](https://github.com/grimstatic/payload-fan-control-ps4)

## Integración del Manager

- Conservar los payloads existentes agrupados por firmware y mantener el bloqueo de selección cuando el firmware exacto no esté confirmado.
- No instalar un PRX silenciosamente ni editar `plugins.ini` al abrir la app. Subir un archivo por FTP no lo carga en un proceso de juego ni lo convierte en autoarranque; eso requiere un mecanismo de carga de GoldHEN y compatibilidad explícita.
- Un PRX en la consola tampoco acelera por sí mismo el FTP del teléfono/PC: el cliente y sus límites de transferencia se ejecutan en el dispositivo controlador. Solo una medición comparativa podría justificar un servicio residente adicional.
- Un módulo futuro de lanzamiento de juegos necesitaría una interfaz documentada y comprobada de GoldHEN/PS4. La lectura de `app.db` o la edición de metadatos XMB no equivale a iniciar un proceso de juego.

## Qué haría falta para ampliar soporte con seguridad

1. Fuente del payload o documentación del autor que identifique offsets, dependencias y firmwares admitidos.
2. Binarios originales con hashes y procedencia comprobables.
3. Prueba en hardware de cada firmware/modelo objetivo, midiendo estado del BinLoader antes y después y validando recuperación.
4. Para payloads de actualización, una prueba separada que confirme el archivo y el estado exacto de las claves/parches modificados.

Hasta disponer de esas pruebas, 14.00 no se debe añadir a los selectores de payloads de ventilador o de actualizaciones por inferencia.
