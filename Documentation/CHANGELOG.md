# Changelog

Todos los cambios notables en este proyecto serán documentados de forma incremental en este archivo.

El formato se basa en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/) y este proyecto se adhiere a [Semantic Versioning](https://semver.org/lang/es/).

## [Sin publicar] - 2026-10-19

### Corregido
- **Error fatal en `cli/op3-worker.php`:** Se corrigió el fallo de tipos (`TypeError: Unsupported operand types`) en el acumulador del worker que hacía fallar el cron de OP3 e impedía procesar y generar las alertas.
- **Formato original de alertas de OP3:** Se restableció la estructura original de las alertas (`{N} nuevas descargas registradas ({D} duplicados filtrados). Detalles: ...`), incorporando el detalle del episodio descargado (`Detalles: {N} de '{Episodio}' en '{Podcast}'`).
- **Eliminación de enlace intrusivo:** Se retiró el enlace forzado a analíticas en las notificaciones de descargas para mantener las tarjetas de alertas limpias tal como estaban diseñadas.
