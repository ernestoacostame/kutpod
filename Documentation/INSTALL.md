# Guía de Instalación de KutPod

Bienvenido a **KutPod**, tu plataforma de podcasting descentralizada, independiente y compatible con Podcasting 2.0. A continuación encontrarás las instrucciones detalladas para instalar y configurar KutPod en tu propio servidor.

---

## 1. Requisitos del Servidor

- **Servidor web:** Apache (con `mod_rewrite`) o Nginx.
- **PHP:** Versión 8.1 o superior.
- **Extensiones PHP requeridas:** `sqlite3`, `curl`, `mbstring`, `json`, `gd`, `zip` (o `imagick` para procesar imágenes).
- **Base de datos:** SQLite3 (KutPod utiliza una base de datos local en archivos, no requieres MySQL/MariaDB).

---

## 2. Preparar los Archivos y Permisos

1. Sube todo el contenido de este paquete a tu servidor (por ejemplo, `/var/www/kutpod`).
2. Es **crítico** que el usuario de tu servidor web (por ejemplo, `www-data` en Ubuntu/Debian) tenga permisos de escritura sobre las siguientes carpetas para que KutPod pueda guardar las configuraciones, bases de datos y archivos multimedia:

```bash
cd /var/www/kutpod

# Crear directorios en caso de que falte alguno
mkdir -p storage media/audio media/covers media/avatars media/transcripts media/chapters

# Asignar propietario (cambia www-data si usas otro usuario)
sudo chown -R www-data:www-data storage media

# Permisos de escritura
sudo chmod -R 775 storage media
```

---

## 3. Configuración del Servidor Web

KutPod utiliza múltiples "Front Controllers" (enrutadores) para separar el panel de administración, el sitio público, la API y el ActivityPub.

### Opción A: Apache (Recomendado / Más fácil)
KutPod incluye un archivo `.htaccess` listo para usar en el directorio raíz.
Solo necesitas asegurarte de que tu VirtualHost de Apache tenga `AllowOverride All` activado para ese directorio.

```apache
<VirtualHost *:80>
    ServerName tudominio.com
    DocumentRoot /var/www/kutpod

    <Directory /var/www/kutpod>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```
Habilita el módulo de reescritura si no lo has hecho: `sudo a2enmod rewrite` y reinicia Apache.

### Opción B: Nginx
Nginx no lee archivos `.htaccess`, por lo que debes replicar las reglas en tu bloque de servidor (Server Block). Aquí tienes la configuración completa:

```nginx
server {
    listen 80;
    server_name tudominio.com;
    root /var/www/kutpod;
    index site.php index.php;

    # 1. Instalador
    location = /install {
        try_files $uri /cli/install.php?$query_string;
    }

    # 2. Tracker OP3 nativo
    rewrite ^/r/([^/]+)/([^/]+)(?:/.*)?$ /r.php?show=$1&ep=$2 last;

    # 3. Feeds RSS
    rewrite ^/feed/([a-z0-9\-]+)\.xml$ /feed.php?slug=$1 last;
    rewrite ^/feed\.xml$ /feed.php?slug=all last;

    # 4. API REST
    rewrite ^/api/(.*)$ /api/index.php?route=$1 last;

    # 5. ActivityPub (Fediverso)
    rewrite ^/\.well-known/webfinger$ /activitypub.php?action=webfinger last;
    rewrite ^/ap/([a-z0-9\-]+)(/.*)?$ /activitypub.php?actor=$1 last;

    # 6. Panel de Administración
    rewrite ^/admin/?$ /index.php last;
    rewrite ^/admin/([a-z0-9\-]+)/?$ /index.php?page=$1 last;
    rewrite ^/admin/podcast/([a-z0-9\-]+)/?$ /index.php?page=podcast&id=$1 last;
    rewrite ^/admin/podcast/([a-z0-9\-]+)/edit/?$ /index.php?page=edit-podcast&id=$1 last;

    # 7. Sitio Público Frontend
    rewrite ^/@([a-z0-9\-]+)/?$ /site.php?r=show&id=$1 last;
    rewrite ^/@([a-z0-9\-]+)/([a-z0-9\-]+)/?$ /site.php?r=episode&show=$1&ep=$2 last;
    rewrite ^/shows/?$ /site.php?r=shows last;
    rewrite ^/episodes/?$ /site.php?r=episodes last;
    rewrite ^/p/([a-z0-9\-]+)/?$ /site.php?r=page&id=$1 last;

    # 8. Seguridad: Bloquear acceso directo a archivos internos
    location ~ ^/(pages|includes|api/handlers|public)/ { deny all; }
    location ~ ^/cli/(?!install\.php) { deny all; }
    location ~ ^/storage/(?!media/) { deny all; }

    # 9. Home y Fallbacks
    location / {
        try_files $uri $uri/ /site.php?r=home;
    }

    # Procesamiento PHP
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock; # Ajusta tu versión de PHP
    }
}
```

---

## 4. Añadir tareas al cron KutPod

```bash
# Procesa las estadísticas IAB v2 de OP3 (1 vez por minuto)
* * * * * php /tu/ruta/kutpod/cli/op3-worker.php >> /dev/null 2>&1

# Procesa las importaciones de RSS largas en segundo plano (1 vez por minuto)
* * * * * php /tu/ruta/kutpod/cli/import-worker.php >> /dev/null 2>&1

# Envía los mensajes/episodios al Fediverso (1 vez por minuto)
* * * * * php /tu/ruta/kutpod/cli/ap-deliver.php >> /dev/null 2>&1

# Ejecuta limpieza de la base de datos
00 09 * * 1 php /tu/ruta/kutpod/cli/cleanup.php >> /dev/null 2>&1
```

---

## 5. Instalación de KutPod

KutPod creará su base de datos local SQLite de forma automática. Puedes realizar la instalación de dos maneras:

### Vía Web (Asistente Visual)
Simplemente ingresa desde tu navegador a la URL de tu servidor:
`http://tudominio.com/install`

Sigue los pasos en pantalla para crear tu cuenta de administrador, establecer tu contraseña e iniciar sesión.

### Vía Línea de Comandos (CLI)
Si prefieres hacerlo por terminal, puedes instalar ejecutando el instalador con PHP (ideal para automatizaciones o Docker):

```bash
cd /var/www/kutpod
php cli/install.php
```

Se te pedirá por consola que ingreses tu nombre, correo y que definas la contraseña de administrador. 

---

## 6. ¡A Disfrutar!

Una vez instalado:
1. Accede a tu panel de administración en: `http://tudominio.com/admin`
2. Puedes empezar a crear tu primer Podcast o importar uno usando el importador RSS integrado.

¡Bienvenido a KutPod!


## 7. Guía para reiniciar los contadores de descargas en KutPod

KutPod utiliza el sistema OP3 nativo para contar descargas. Estas estadísticas se procesan periódicamente y se guardan localmente en la base de datos SQLite. Debido a que no existe una opción en la interfaz web de administración para borrar estos contadores, el proceso debe realizarse directamente interactuando con las bases de datos SQLite en el servidor.

---

## 📊 Estructura de Datos de Estadísticas

Las descargas y métricas están definidas en el esquema de la base de datos ([storage/schema.sql](file:///storage/schema.sql)) en las siguientes tablas:

*   `op3_hits`: Registro temporal en bruto de todas las peticiones de descarga.
*   `op3_downloads`: Descargas procesadas y deduplicadas siguiendo la especificación IAB v2.
*   `op3_stats_daily`: Totales acumulados diarios por episodio y podcast. **Esta tabla alimenta el dashboard de administración.**
*   `op3_geo_daily`: Desglose por regiones y países.
*   `op3_apps_daily`: Desglose por aplicaciones y dispositivos.

---

## ⚡ Comandos para Reiniciar Contadores

> [!WARNING]
> **Recomendación:** Realiza una copia de seguridad del archivo `storage/kutpod.db` antes de ejecutar cualquiera de estas sentencias.

Abre una terminal en el directorio raíz de KutPod y ejecuta el comando según el periodo que desees vaciar.

> [!TIP]
> Si recibes un error tipo `Error: database is locked`, es porque el servidor web u otro proceso está accediendo a la base de datos en ese instante. Para evitarlo, usamos la opción `-cmd ".timeout 5000"` que le indica a SQLite que espere hasta 5 segundos a que se libere el bloqueo.

### Opción A: Reiniciar TODO el historial (Desde cero)
Borra todas las descargas del sistema permanentemente y libera el espacio en disco:
```bash
sqlite3 -cmd ".timeout 5000" storage/kutpod.db "DELETE FROM op3_hits; DELETE FROM op3_downloads; DELETE FROM op3_stats_daily; DELETE FROM op3_geo_daily; DELETE FROM op3_apps_daily; VACUUM;"
```

### Opción B: Reiniciar esta semana (Últimos 7 días móviles)
Elimina únicamente las descargas y peticiones de los últimos 7 días:
```bash
sqlite3 -cmd ".timeout 5000" storage/kutpod.db "DELETE FROM op3_downloads WHERE date >= date('now', '-7 days'); DELETE FROM op3_hits WHERE ts >= datetime('now', '-7 days'); DELETE FROM op3_stats_daily WHERE date >= date('now', '-7 days'); DELETE FROM op3_geo_daily WHERE date >= date('now', '-7 days'); DELETE FROM op3_apps_daily WHERE date >= date('now', '-7 days'); VACUUM;"
```

### Opción C: Reiniciar este mes (Month-to-Date)
Elimina las descargas registradas desde el primer día del mes actual en curso:
```bash
sqlite3 -cmd ".timeout 5000" storage/kutpod.db "DELETE FROM op3_downloads WHERE date >= date('now', 'start of month'); DELETE FROM op3_hits WHERE ts >= date('now', 'start of month'); DELETE FROM op3_stats_daily WHERE date >= date('now', 'start of month'); DELETE FROM op3_geo_daily WHERE date >= date('now', 'start of month'); DELETE FROM op3_apps_daily WHERE date >= date('now', 'start of month'); VACUUM;"
```

### Opción D: Reiniciar este año (Year-to-Date)
Elimina las descargas registradas desde el 1 de enero del año actual en curso:
```bash
sqlite3 -cmd ".timeout 5000" storage/kutpod.db "DELETE FROM op3_downloads WHERE date >= date('now', 'start of year'); DELETE FROM op3_hits WHERE ts >= date('now', 'start of year'); DELETE FROM op3_stats_daily WHERE date >= date('now', 'start of year'); DELETE FROM op3_geo_daily WHERE date >= date('now', 'start of year'); DELETE FROM op3_apps_daily WHERE date >= date('now', 'start of year'); VACUUM;"
```

---

## 🧹 Limpiar la Caché del Dashboard

KutPod tiene implementado un sistema de caché ([includes/cache.php](file:///includes/cache.php)) que almacena los resultados de las estadísticas durante 30 minutos para optimizar el rendimiento. 

Una vez que hayas vaciado las tablas deseadas, **debes eliminar el archivo de caché** para que el panel de administración muestre los datos limpios de inmediato:

```bash
rm storage/cache.db
```

*(El archivo se volverá a crear automáticamente de manera interna con los nuevos valores la próxima vez que accedas a la sección de Estadísticas)*.

