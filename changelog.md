1. Mapa Interactivo de Descargas por País
- Choropleth de intensidad en tonos azules: Los países con mayor número de descargas se colorean progresivamente con un tono azul más oscuro y profundo (desde azul cielo claro para bajas descargas hasta azul marino oscuro para el pico de audiencia).
- Efecto de resalte al pasar el cursor: Al posicionar el cursor sobre cualquier país, este se eleva en la jerarquía SVG al primer plano (bring-to-front), mostrando un borde nítido, sombra proyectada e incremento de brillo.
- Tooltip flotante enriquecido:
    - Bandera emoji del país correspondiente.
    - Nombre del país en español con fallback nativo (Intl.DisplayNames).
    - Total de descargas formateadas con resalte azul cian (X descargas).
    - Porcentaje que representa sobre el total mundial (Y% del total mundial).
- Controles de navegación: Controles de Zoom (+ / −), botón de Restablecer (↺) y soporte para paneo por arrastre (drag-to-pan) y rueda del ratón.
- Leyenda explicativa: Barra con gradiente en tonos azules ("0" a "Más descargas (tono más oscuro)") y contador de países con audiencia activa.

2. Agrupación de Estadísticas en Pestañas

Se implementó un sistema de navegación por pestañas con persistencia en URL (?tab=downloads|location|tech) y soporte en los filtros de fecha (7d, 30d, etc.):

Descargas:
- Gráfico interactivo diario (Descargas por día).
- Distribución "Por podcast" ordenado por el show más activo primero (más descargas en el período) y paginado a Top 5 para mantener equilibrio de altura con el gráfico diario.
- Top 5 Episodios globales (con portada, show, temporada/episodio y métricas).
- Líder de cada podcast ordenado por descargas y paginado a Top 10.

Localización:
- Mapa interactivo mundial de descargas en azul.
- Badge indicador del total de países distintos con descargas activas (con tooltip descriptivo).
- Ranking de Países con banderas, nombres en español y barras (Top 10 paginado).
- Top U.S. Metros (códigos DMA de Nielsen traducidos a nombres de ciudad, Top 10 paginado).
- Top Latinoamérica (Top 10 paginado).
- Top Europa (Top 10 paginado).
- Top Asia (Top 10 paginado).

Tecnología:
- Apps · Podcasting 2.0 (User-Agents compatibles con Podcast Index, Top 10 paginado).
- Top Navegadores (Top 10 paginado).
- Top Dispositivos (Top 10 paginado).

3. Barras de Estadísticas con Tooltip Interactivo al Pasar el Cursor
- Se unificaron todas las barras estadísticas mediante un componente reutilizable (stat-bar-item) y un motor de tooltip flotante universal (kp-universal-tooltip).
- Al posar el cursor sobre cualquier barra de show, país, metro, región, app, navegador o dispositivo:
    - Aparece un tooltip flotante indicando la etiqueta, cantidad exacta de descargas y su porcentaje.
    - La barra se ilumina con efecto de brillo (glow) y transición suave.
    - En el gráfico de Descargas por día, cada barra individual muestra la fecha, descargas registradas y el porcentaje respecto al día pico.

4. Paginación y Vista Top 5 en Tarjetas
- Se redujeron a un Top 5 inicial todas las tarjetas estadísticas ("Por podcast", "Líder de cada podcast", "Países", "Top U.S. Metros", "Top Latinoamérica", "Top Europa", "Top Asia", "Apps · Podcasting 2.0", "Top Navegadores", "Top Dispositivos") y se aplicó alineación superior (`align-items: start`) en el grid para garantizar una simetría vertical perfecta y eliminar huecos o espacios vacíos.
- Cada tarjeta incluye un pie interactivo con:
    - Indicador de rango: "Mostrando 1–5 de X".
    - Botón de navegación entre páginas (‹ / ›).
    - Botón "Ver todos (X)" para desplegar la lista completa instantáneamente y alternar a "Ver top 5".

5. Optimización de Espacios y Layout Multicolumna Responsivo
- Se reestructuraron las tarjetas de las pestañas **Tecnología** y **Localización** en columnas flexibles (`stats-two-col` y `stats-col`) para que las tarjetas se adapten compactamente a su contenido eliminando espacios vacíos (gaps).
- En la pestaña **Tecnología**, la tarjeta **Top Dispositivos** se ubicó directamente debajo de **Top Navegadores** en la columna derecha, logrando una simetría vertical perfecta con la tarjeta **Apps · Podcasting 2.0** en la columna izquierda.
- En la pestaña **Localización**, las tarjetas de países y regiones se agruparon en dos columnas equilibradas evitando filas intermedias o tarjetas aisladas.
- El diseño responde dinámicamente a la resolución de pantalla: en resoluciones de escritorio mantiene 2 columnas compactas con altura auto-ajustable, y en pantallas de 920px o inferiores pasa limpiamente a 1 columna para aprovechar todo el ancho disponible.

6. Reorganización de la Pantalla Overview / Resume y Nuevo Gráfico Donut
- **Gráfico principal a todo el ancho**: La tarjeta de **Descargas por show** ahora abarca el 100% del ancho del grid para ofrecer una visualización amplia y detallada de la línea temporal de descargas.
- **Distribución en 3 columnas en escritorio (`overview-three-col`) con altura 100% nivelada**: Justo debajo del gráfico grande, las tarjetas se organizan en 3 columnas paralelas con alineación estirada (`align-items: stretch`) eliminando cualquier hueco o desnivel inferior:
    - **Columna 1**: **Tus podcasts** con columna ampliada (`1.35fr`), paginador ajustado a **Top 4 por página** (con navegación `‹ 1/X ›` y alternador "Ver todos / Ver top 4"), contenedor flexible y pie de paginación anclado al fondo.
    - **Columna 2**: **Distribución por show (Gráfico Donut)** en columna compacta (`1fr`), configurado para mostrar los **4 primeros shows** más escuchados más **"Otros shows"**, con leyenda anclada al fondo y gráfico centrado verticalmente.
    - **Columna 3**: Tarjetas apiladas de **Ritmo de publicación** y **Top show · 7d** en columna compacta (`1fr`), ambas con distribución vertical proporcional para concluir exactamente en la misma línea base horizontal que las dos columnas anteriores.
    - **Desacoplamiento inteligente en expansión**: Al pulsar "Ver todos" en Tus podcasts, se conmuta dinámicamente el grid a alineación superior para desplegar toda la colección de podcasts sin estirar innecesariamente las columnas laterales.
- **Puntos interactivos dinámicos en el gráfico de líneas**: Se sustituyeron los tooltips nativos del navegador por tooltips flotantes dinámicos (`data-tooltip-html`) con animación de escala, brillo y sombra del color del show, indicando instantáneamente el podcast, fecha y descargas sin demoras.
- **Gráfico Donut de distribución de escuchas por podcast**:
    - Calcula la cuota de descargas de cada show en el período activo (7d, 30d, 12 meses).
    - Anillo SVG estilizado con el total de descargas en el centro.
    - Rebanadas interactivas con expansión y resplandor al pasar el cursor.
    - Leyenda interactiva con porcentajes y descargas, sincronizada bidireccionalmente con las rebanadas del gráfico.

7. Mejoras en Respaldos, Actualizaciones y Federación ActivityPub
- **Ajuste y mayor tamaño de botones de acción en Respaldos**:
    - Se amplió la columna de acciones en la tabla del historial de respaldos de 50px a 125px para evitar desbordamientos.
    - Se incrementó el padding lateral de la tabla a 32px y se alinearon los botones al extremo derecho, separándolos cómodamente del borde de la tarjeta.
    - Se agrandaron los botones de acción (Descargar, Restaurar y Eliminar) a 32×32px con iconos ampliados a 15–16px para facilitar su interacción táctil y con el cursor.
- **Gestión y borrado de actualizaciones descargadas**:
    - Se incorporó la opción de **Borrar actualizaciones descargadas** en la página de Actualizaciones (`/admin/update`) y en el encabezado del historial.
    - Nueva tarjeta informativa de almacenamiento que contabiliza y muestra en tiempo real el espacio ocupado por paquetes descargados (`.zip`), carpetas temporales y copias de seguridad de versiones anteriores (`rollback-*.tar.gz`).
    - Opción para eliminar paquetes `.zip` descargados y archivos temporales, con casilla opcional para purgar también las copias de seguridad de rollbacks antiguos, liberando espacio en disco de forma segura.
    - Nuevas acciones en API `clean_downloads` y `storage_info` con retroalimentación inmediata del total de archivos eliminados y megabytes liberados.
- **Corrección en la sección de Últimos episodios federados**:
    - Se subsanó un error en la consulta SQL donde se intentaba leer una columna inexistente (`p.initial`), lo cual provocaba que la sección mostrase "No hay episodios federados" de manera errónea.
    - Se actualizó la consulta para extraer episodios publicados de podcasts federados (`p.federate = 1 AND e.status = 'published'`), generando la inicial de forma dinámica (`SUBSTR(p.title, 1, 1)`).
    - Se optimizó la renderización de las filas con carátula por defecto/fallback, cálculo de temporada (`S`) y número (`E`), fecha relativa formateada y nombres con elipsis para evitar desbordamientos.

8. Optimización Masiva de Rendimiento y Motor de Base de Datos
- **Configuración de alto rendimiento en SQLite (PRAGMAs)**:
    - Se configuraron los PRAGMAs `synchronous = NORMAL`, `cache_size = -32000` (32MB de caché en RAM para mantener índices activos en caliente), `temp_store = MEMORY`, `mmap_size = 268435456` (256MB de memory-mapped I/O) y `busy_timeout = 5000` ms, reduciendo drásticamente la latencia en lecturas y escrituras simultáneas en modo WAL.
- **Auto-migración de esquema v6 con índices compuestos**:
    - Se crearon índices optimizados automáticos para `op3_stats_daily(episode_id, downloads)`, `op3_stats_daily(podcast_id, date)`, `op3_geo_daily(episode_id, date)`, `op3_apps_daily(episode_id, date)`, `episodes(status, published_at DESC)`, `episodes(slug)` y `alerts(read_at)`.
    - La consulta de episodios con cálculo de descargas sobre miles de registros pasó de más de 1.000 ms a menos de 3 ms (más de 300x de aceleración).
- **Memoización y optimización de subconsultas en `data.php`**:
    - Se implementó memoización a nivel de petición en `kp_podcasts()` para evitar consultas redundantes dentro del mismo ciclo de vida HTTP.
    - Se eliminaron los JOINs innecesarios en las subconsultas de métricas agregando directamente por `s.podcast_id = p.id`.
    - Se agregó soporte para filtrado en SQL por podcast (`podcastSlug`) y límite (`limit`) en `kp_episodes()`, evitando que `kp_episodes_of()` y la vista general carguen la totalidad de episodios de la base de datos en memoria PHP.
- **Batching de consultas analíticas en `overview.php`**:
    - Se sustituyó el bucle de consultas por show en el gráfico cronológico por una única consulta agrupada (`kp_op3_all_podcasts_series`).
    - Se agrupó la obtención del episodio más escuchado por cada show en una sola consulta mediante funciones de ventana SQLite (`ROW_NUMBER() OVER PARTITION`), eliminando por completo el problema de consultas N+1.
- **Aceleración del Tracker de Redirección OP3**:
    - Se optimizó `kp_op3_ensure_schema()` con verificación estática y chequeo de existencia, evitando la ejecución reiterada de 11 sentencias DDL en cada descarga o reproducción de audio.
- **Recolección de basura mejorada en `cleanup.php`**:
    - Se añadió la purga programada de hits procesados en `op3_hits` con más de 90 días de antigüedad (`processed = 1 AND ts < datetime('now', '-90 days')`), reduciendo decenas de megabytes de espacio innecesario en disco tras la ejecución de VACUUM.

9. Corrección en Fechas y Sincronización de Episodios Federados (ActivityPub)
- **Sincronización y orden de últimos episodios federados**:
    - Se corrigió la consulta de "Últimos episodios federados" en `/admin/fediverse` para ordenar mediante `COALESCE(e.published_at, e.publish_at, e.created_at) DESC`, solucionando el problema donde los episodios recién publicados no aparecían o se mostraban con fechas antiguas de meses atrás debido a que `published_at` se encontraba en `NULL`.
    - La etiqueta de tiempo relativo ("hace X min / h / d") ahora utiliza la fecha resuelta con fallback asegurando que el tiempo transcurrido sea exacto.
- **Persistencia consistente de `published_at`**:
    - Al crear o editar episodios tanto desde la interfaz web (`pages/new-episode.php`) como desde la API de Kut Editor (`api/handlers/episode_create.php` y `api/handlers/episode_update.php`), la columna `published_at` se rellena y persiste formalmente cada vez que el estado del episodio es o pasa a `published`.
    - En el verificador de episodios programados (`data.php`), al cambiar el estado de `scheduled` a `published`, se actualiza de inmediato el campo `published_at`.
- **Auto-migración de esquema v7**:
    - Se integró la migración automática de esquema v7 en `includes/db.php` para actualizar retroactivamente en la base de datos de producción todos los episodios ya publicados que tenían `published_at` en `NULL`, asignándoles `publish_at` o `created_at`.
- **Robustez en ActivityPub y RSS**:
    - Se incorporaron fallbacks consistentes a `publish_at` y `created_at` tanto al generar la fecha del objeto Note de ActivityPub en `activitypub.php` como en la etiqueta `<pubDate>` de `feed.php`.

10. Mejoras en Frontend, Reproducción de Capítulos y Gestor de Actualizaciones
- **Gestión fluida de actualizaciones consecutivas en el Panel de Administración**:
    - Se solucionó un problema en `/admin/update` donde, tras aplicarse una actualización con éxito, el botón "Actualizar ahora" (`#btn-apply`) permanecía deshabilitado y el mensaje de recarga bloqueaba la interfaz si el usuario buscaba otra versión disponible sin antes recargar la página.
    - La búsqueda de actualizaciones (`kpUpdateCheck`) ahora restablece limpiamente la barra de progreso, oculta el mensaje de éxito previo y reactiva el botón de actualización para permitir actualizaciones continuas inmediatas.
- **Conmutación y reproducción precisa de capítulos entre episodios (Frontend)**:
    - Se corrigió el comportamiento del reproductor persistente con navegación PJAX: al reproducir un episodio y navegar a otro episodio que contenga capítulos, al pulsar en cualquiera de sus capítulos ahora se cambia de pista automáticamente al nuevo episodio, se actualizan la carátula y los metadatos en el reproductor y, tras cargar la pista, salta al minuto exacto del capítulo.
    - Si se pulsa un capítulo del mismo episodio que ya está en reproducción, salta inmediatamente a la posición indicada sin recargas innecesarias.
    - Se integraron los atributos `data-src`, `data-title`, `data-podcast`, `data-color` y `data-cover` directamente en los botones de capítulo (`.pub-ep-chapter-row`).
- **Reflejos en portadas del Podcast y Detalle del Episodio**:
    - Implementación de un reflejo realista mediante inversión vertical (`transform: scaleY(-1)`) y gradientes de máscara alfa (`mask-image` y `-webkit-mask-image`), tanto en la carátula del hero en la página del podcast (`public/show.php`) como en el episodio individual (`public/episode.php`).
    - El desvanecimiento es transparente y se fusiona naturalmente con los fondos de color, gradientes temáticos del show y el modo oscuro.
- **Rediseño del Frontend (Show y Episodios)**:
    - Nueva disposición en CSS Grid de 2 filas en la vista de episodio para garantizar que la sección de capítulos y la columna de descripción comiencen exactamente en la misma línea horizontal.
    - Rediseño de la lista de episodios con tipografía más legible y moderna (títulos a 22px en color `#504e5d` y encabezados a 28px), botón de play pill con el color de acento del podcast, espaciado vertical armónico y botón de compartir con icono integrado.