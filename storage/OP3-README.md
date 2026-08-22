# KutPod · OP3 nativo

Tracker de descargas IAB v2 reescrito en PHP/SQLite, basado en el código abierto de
[op3.dev](https://github.com/skymethod/op3). KutPod NO depende de OP3 externamente;
los datos viven en tu servidor.

## Cómo se cuenta una descarga

Una **request** llega al endpoint `/r/{show}/{episode}/{file.mp3}`:
1. **302 inmediato** al audio real. El cliente arranca a descargar sin esperar.
2. En paralelo (post-FastCGI o post-response): se escribe una fila en `op3_hits`.

Cada **15 minutos** el worker `cli/op3-worker.php` procesa los hits aplicando IAB v2:
- Solo `GET` cuenta.
- Si tiene `Range`, debe pedir más de 2 bytes. Excepción: `bytes=0-1` se guarda como
  `first-two` y se **reemplaza** si después llega una request real del mismo oyente.
- `audienceId = SHA-256(hashedIP | userAgent | referer)` — identifica al oyente sin
  conservar la IP. El hash de IP rota cada día.
- `downloadHash = SHA-1(serverUrl | audienceId)` — clave de dedupe.
  Solo cuenta UNA vez por día.
- Detección de bots en 3 capas: tipo de agente, patrón regex en UA, lista curada
  de reglas ASN+región+fecha sincronizada desde OP3.

Las descargas se agregan en tres tablas:
- `op3_stats_daily` — totales por episodio y día (downloads, listeners únicos, bots)
- `op3_geo_daily` — desglose por país/región
- `op3_apps_daily` — desglose por app y device

## Configuración

### 1. Cron
```cron
*/15 * * * * cd /var/www/kutpod && php php/cli/op3-worker.php >> /var/log/kutpod-op3.log 2>&1
```

### 2. Rewrites
**Apache** (`.htaccess`):
```apache
RewriteRule ^r/([^/]+)/([^/]+)(?:/.*)?$ /r.php?show=$1&ep=$2 [QSA,L]
```
**Nginx**:
```nginx
rewrite ^/r/([^/]+)/([^/]+) /r.php?show=$1&ep=$2 last;
```

### 3. Geolocalización · 2 opciones

**Opción A · Cloudflare (recomendado, gratis y exacto).**
Si pones tu dominio detrás de Cloudflare, KutPod lee `CF-IPCountry`, `CF-Region`,
`CF-ASN`, etc. automáticamente. No hay nada que instalar.

**Opción B · MaxMind GeoLite2 local.**
```bash
# Regístrate gratis: https://www.maxmind.com/en/geolite2/signup
# Descarga GeoLite2-City.mmdb y GeoLite2-ASN.mmdb
mkdir -p php/storage/geoip
mv ~/Downloads/GeoLite2-City.mmdb php/storage/geoip/
mv ~/Downloads/GeoLite2-ASN.mmdb  php/storage/geoip/
composer require geoip2/geoip2
```
Cron mensual para mantenerla fresca:
```cron
0 3 1 * * curl -sL "https://download.maxmind.com/app/geoip_download?...&edition_id=GeoLite2-City&suffix=tar.gz" | tar xz --strip 1 -C php/storage/geoip
```

**Opción C · Nada.** Funciona, pero los reportes por país/región saldrán vacíos.

### 4. Reglas anti-bot

Las reglas curadas (~80 patrones ASN+región+fecha) se sincronizan desde el repo
público de OP3. Desde el panel:

**Preferencias → OP3 → Sincronizar reglas anti-bot**

Eso descarga `bots.ts` de GitHub, lo parsea y reemplaza las reglas en BD.
Recomendado correrlo semanalmente vía cron:
```cron
0 4 * * 0 curl -X POST https://kutpod.io/op3-sync.php -H "Cookie: kp_sess=..." > /dev/null
```

O directamente en CLI:
```bash
php -r "require 'php/includes/op3-tracker.php'; print_r(kp_op3_sync_bot_rules_from_github());"
```

## Tablas

| Tabla | Contenido |
|---|---|
| `op3_hits` | Raw, una fila por request. Se purga a 90 días por defecto. |
| `op3_downloads` | Downloads dedupeados IAB v2. Una fila = una descarga real. |
| `op3_stats_daily` | Agregado por episodio/día. **Esto alimenta el dashboard.** |
| `op3_geo_daily` | Desglose geográfico. |
| `op3_apps_daily` | Desglose por app/device. |
| `op3_botip_rules` | Reglas curadas, sincronizables. |

## Privacidad

- IPs nunca se guardan en claro. Se hashean con SHA-1 + sal del día (`hash(ip + date)`),
  por lo que el mismo oyente cambia de hash cada 24h.
- El `audienceId` derivado se vuelve incorrelacionable después de 24h.
- Sin cookies, sin scripts en el cliente, sin terceros.
