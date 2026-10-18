# Changelog

Todos los cambios notables en este proyecto serán documentados de forma incremental en este archivo.

El formato se basa en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/) y este proyecto se adhiere a [Semantic Versioning](https://semver.org/lang/es/).

## [Sin publicar] - 2026-10-18

### Añadido
- **Detalle de episodios en alertas de OP3:** Se agregó el desglose y conteo de descargas por episodio (`by_episode` y `by_podcast_episode`) en `kp_op3_process_pending()`.
- **Enlace directo a analíticas:** Se incorporó el enlace a estadísticas (`/admin/podcast/{slug}?tab=analytics`) en la generación de alertas de OP3, habilitando el botón interactivo *Ver estadísticas →* en el centro de alertas.

### Modificado
- **Jerarquía y formato de alerta OP3:** Se reestructuró la notificación para situar como protagonista el título del episodio, el podcast y el total de descargas registradas (ej. `'Episodio' ('Podcast'): 3 descargas registradas.`).
- **Posición de duplicados filtrados:** La métrica de duplicados deduplicados por IAB v2 se trasladó al final del mensaje: `(X duplicados filtrados)`.
- **Pluralización y gramática:** Corrección gramatical dinámica en singular/plural para descargas (`1 descarga registrada` vs `N descargas registradas`) y duplicados (`1 duplicado filtrado` vs `N duplicados filtrados`).
