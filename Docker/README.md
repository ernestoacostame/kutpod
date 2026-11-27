# KutPod en Docker

## Inicio rápido

Desde la carpeta `Docker/`:

```bash
docker compose up -d --build
```

Abre en tu navegador: `http://localhost:8080/install` (o `http://localhost:8080` tras instalar).

## Puerto personalizado

```bash
KUTPOD_PORT=8083 docker compose up -d --build
```

## Características incluidas

- PHP 8.3 FPM + Nginx con soporte de `Range` para streaming de audio/vídeo y cabeceras de embed.
- `ffmpeg` preinstalado para procesamiento de audio y portadas.
- Drivers **SQLite, PostgreSQL y MySQL/MariaDB** habilitados (`pdo_sqlite`, `pdo_pgsql`, `pdo_mysql`).
- Persistencia completa en el volumen `kutpod_storage` montado en `/var/www/html/storage`.
