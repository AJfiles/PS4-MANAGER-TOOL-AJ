# Análisis de PS4 Temperature 1.06 y 1.07

Fecha: 2026-11-14  
Alcance: inspección estática de los dos PKG que proporcionó AJ. No se instaló ni ejecutó la aplicación en la PS4.

## Archivos examinados

| Versión | PKG | SHA-256 | `universal.prx` extraído | SHA-256 del PRX |
|---|---|---|---|---|
| 1.06 | `PS4_LAPY20006_v1.06.pkg` | `4f59530085bf931ec7387891c0d60daec4a7de2e25bbe0c45a0431c9e8d9de17` | 163,527 bytes | `c899d294f5ac9ffe1cf8371d82fc97d3924b85bdf4637b2534fe1b28cd7a119a` |
| 1.07 | `PS4_LAPY20006_v1.07.pkg` | `246b57ea44b68bdf48318a2da425b0888361e84884d99e21038f7fb42e4fa166` | 53,092 bytes | `3d4bb827a3c57b56e505a3f42844ac2d0795bcb36714cbc7fee21165ce2cd1cc` |

La comparación de scripts administrados se encuentra en `temporal carpeta/ps4-temperature-analysis/v1.06_decompiled/Assembly-CSharp.decompiled.cs` y `.../v1.07_decompiled/Assembly-CSharp.decompiled.cs`. Los dos PRX nativos son distintos en tamaño y hash; no son intercambiables por equivalencia demostrada.

La validación previa de PkgTool registró **Content Digest** y **Major Param Digest** no válidos para ambos PKG. Los `eboot.bin` extraídos sí son idénticos (SHA-256 `5090e7d0fd53c9707977c0bd19add6f5a30475bb23b3022ec6566a92b939ab7a`), pero esto no valida la autenticidad del contenedor ni extiende compatibilidad. No se recomienda instalar estas copias de muestra.

## Evidencia de comportamiento

En ambas versiones el código C# importa tres funciones del módulo nativo `universal`: `FreeUnjail(int)`, `Temperature()` y `SetTemperature(int)`. El programa consulta temperatura cada dos segundos, conserva máximos/mínimos en preferencias locales y programa `SetTemperature` después de dos segundos cuando el slider queda quieto. El valor inicial del umbral es 79 °C. La llamada a `SetTemperature` es una acción de control real, no solo una pantalla de lectura.

La versión 1.06 presenta un selector de firmware: guarda el número elegido como `FW900`, asigna ese valor a `Temperatura.FW` y activa el componente. `Temperatura.Start()` llama a `FreeUnjail(FW)` solo cuando el selector entrega un valor mayor que cero.

La versión 1.07 elimina ese selector. Su `Start()` llama `FreeUnjail(0)` sin una selección de firmware y después consulta/ajusta la temperatura. Esto demuestra un cambio en el flujo administrado, pero **no** demuestra que la rutina nativa resuelva todas las diferencias de kernel, hardware o firmware. El código de `FreeUnjail` reside en `universal.prx`; su nombre no basta para deducir su implementación ni compatibilidad.

## Qué puede y qué no puede reutilizar el Manager

- El Manager puede seguir enviando únicamente los binarios de ventilador que ya están organizados por firmware y declarados en su catálogo. Debe mantener la advertencia, selección explícita de versión y confirmación antes de cualquier envío.
- Los PKG estudiados contienen una aplicación Unity y un PRX nativo que se carga dentro de ella. No son payloads `.bin` listos para BinLoader y no se deben renombrar, extraer ni enviar como si fueran payloads de control del ventilador.
- No se reutilizan `universal.prx` ni el `eboot.bin` del PKG: no hay prueba de autorización/licencia para redistribuirlos, y el módulo ejecuta funciones nativas con impacto en el control térmico.
- Una rutina universal para todas las versiones de PS4 no se puede deducir de estos dos clientes. Harían falta código nativo reconstruible, especificación de ABI/firmware, validación por modelo y firmware, protecciones térmicas independientes y pruebas en una consola de laboratorio. Que el cliente 1.07 pase cero a `FreeUnjail` no satisface esos requisitos.

## Compatibilidad de firmware

La página del autor de PS4 Temperature lista v1.07 como compatible con firmware 5.05–11.00. No lista 12.xx–14.00. La página oficial de releases de GoldHEN consultada enumera soporte hasta 11.00; tampoco enumera 12.xx–14.00. Un enlace directo al tag oficial `v2.4b18.14` de GoldHEN devuelve 404. Por tanto, el Manager no debe prometer que estos payloads ni PS4 Temperature v1.07 funcionen en 14.00. La compatibilidad debe provenir de releases oficiales y ensayos documentados, no de inferirla por número de versión o anuncios de terceros.

El SDK abierto de payloads puede enumerar offsets aportados para versiones posteriores, pero los offsets marcados como no probados no demuestran soporte de jailbreak/GoldHEN. Un changelog comunitario tampoco equivale a una release oficial. La evidencia y el alcance por fuente se mantienen en `docs/PAYLOADS_UNIVERSALES_Y_PS4_TEMPERATURE.md`.

GoldHEN advierte que BinLoader es experimental y que payloads defectuosos pueden dañar el funcionamiento o colgar la consola. La función de ventilador permanece limitada a las builds por firmware ya presentes en `payloads fw/5.05/Fan` y `payloads fw/9.00/Fan`; esta inspección no añade firmware ni cambia binarios.

## Dictamen

Los PKG evidencian que el ajuste de temperatura depende de una biblioteca nativa distinta entre las versiones 1.06 y 1.07. **No existe base suficiente para crear a partir de ellos un payload universal ni para declarar compatibilidad en cualquier firmware.** La opción segura y útil para el Manager es conservar la matriz explícita de payloads verificados, impedir selecciones ambiguas y mostrar con claridad los firmware no disponibles. Cualquier ampliación requiere fuente nativa legalmente redistribuible y pruebas separadas en un equipo de laboratorio compatible.

## Fuentes técnicas

- [GoldHEN Releases y firmware soportados](https://github.com/GoldHEN/GoldHEN/releases)
- [LapyGames, página del autor y rango declarado de PS4 Temperature v1.07](https://lapygames.wordpress.com/apps/)
- [GoldHEN Plugins SDK](https://github.com/GoldHEN/GoldHEN_Plugins_SDK)
- [Guía oficial de GoldHEN Plugins](https://github.com/GoldHEN/GoldHEN_Plugins_Repository/blob/main/.github/README.md)
