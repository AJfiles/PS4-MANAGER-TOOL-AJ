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

Consulta actualizada el 2026-11-13: GitHub muestra GoldHEN v2.4b18 como pre-release oficial, cuya lista de compatibilidad llega hasta 11.00; el tag estable más reciente que muestra GitHub es v2.3. Ninguna de esas notas oficiales documenta 14.00. El issue oficial #412 sobre 14.00 está cerrado, pero el cuerpo del issue es una solicitud y el cierre no identifica un binario, matriz de compatibilidad o prueba reproducible; no se toma como confirmación de soporte. Un changelog comunitario que atribuye 14.00 a una beta v2.4b18.13 no es el canal oficial y no basta para recomendarla ni para certificar payloads de terceros.

El repositorio de ejemplo de fan control `grimstatic/payload-fan-control-ps4` declara pruebas en una PS4 Pro CUH-7106B con firmware 12.52 y una sola consola de prueba. Esa evidencia no respalda 14.00 ni todos los modelos. El SDK de payloads enumera offsets de kernel por firmware y advierte que suelen ser específicos por versión. La lista incluye algunas versiones superiores a las publicadas por GoldHEN, pero varios elementos figuran como entradas aportadas y no confirmadas. Tener offsets en un SDK no demuestra que exista soporte de jailbreak/GoldHEN ni valida un payload de ventilador o de actualizaciones. Por ello, un único binario universal no puede asumirse compatible por el mero hecho de que GoldHEN cargue.

Fuentes primarias consultadas:

- [GoldHEN releases oficiales](https://github.com/GoldHEN/GoldHEN/releases)
- [Issue oficial de GoldHEN sobre 14.00](https://github.com/GoldHEN/GoldHEN/issues/412)
- [Fan Control payload y su alcance de prueba](https://github.com/grimstatic/payload-fan-control-ps4)
- [SDK PS4 y offsets por firmware](https://github.com/ps4-payload-dev/sdk#working-firmwares)

Referencia comunitaria no usada como confirmación: [changelog v2.4b18.13 en un fork](https://github.com/EEJTek/GoldHEN_v2.4b18.12/blob/main/CHANGELOG.md).

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
