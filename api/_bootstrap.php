<?php
/**
 * php/api/_bootstrap.php
 *
 * Núcleo común para todos los endpoints /api/* que consume KutEditor.
 *
 *  - Conexión SQLite singleton ($pdo)
 *  - Helpers de respuesta JSON (api_json, api_error)
 *  - Validación de token Bearer y bootstrap del usuario actual
 *  - Helpers de slug, paginación, multipart
 *  - Crea la tabla `api_tokens` si no existe (idempotente)
 *
 * Filosofía: cero dependencias, cero composer. PHP puro + PDO.
 */

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

// ── CORS ────────────────────────────────────────────────────────────
// KutEditor (Qt) no necesita CORS pero un cliente web sí.
// Restringir al dominio de la instancia cuando esté configurado.
$_cors_origin = '*';
try {
    $dbPath = __DIR__ . '/../storage/kutpod.db';
    if (file_exists($dbPath)) {
        $_pdo_cors = new PDO('sqlite:' . $dbPath);
        $_row_cors = $_pdo_cors->query("SELECT v FROM settings WHERE k='instance_domain' LIMIT 1")->fetch();
        if ($_row_cors && !empty($_row_cors['v'])) {
            $domain = $_row_cors['v'];
            if (!str_starts_with($domain, 'http')) $domain = 'https://' . $domain;
            $_cors_origin = rtrim($domain, '/');
        }
        unset($_pdo_cors, $_row_cors);
    }
} catch (Throwable $e) {}
header('Access-Control-Allow-Origin: ' . $_cors_origin);
header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Conexión a SQLite ───────────────────────────────────────────────
if (!function_exists('kp_db')) {
function kp_db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $dbPath = __DIR__ . '/../storage/kutpod.db';
    if (!file_exists($dbPath)) {
        api_error(500, 'database_missing',
                  'No se encuentra storage/kutpod.db; ejecuta cli/install.php');
    }
    $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');

    // Tabla de tokens API (idempotente)
    $pdo->exec("CREATE TABLE IF NOT EXISTS editor_api_tokens (
        token       TEXT PRIMARY KEY,
        user_id     INTEGER NOT NULL,
        client      TEXT,
        created_at  INTEGER NOT NULL,
        last_used   INTEGER,
        expires_at  INTEGER,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_editor_api_tokens_user
                ON editor_api_tokens(user_id)");

    return $pdo;
}
}

// ── Respuesta JSON ──────────────────────────────────────────────────
function api_json($data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function api_error(int $status, string $code, string $message, array $extra = []): void {
    api_json(array_merge([
        'error'   => $code,
        'message' => $message,
    ], $extra), $status);
}

// ── Centralized Error and Exception Handling ─────────────────────────
set_exception_handler(function (Throwable $exception) {
    $logFile = __DIR__ . '/../storage/api_error.log';
    $timestamp = date('[Y-m-d H:i:s]');
    $message = sprintf(
        "%s Uncaught Exception: %s in %s on line %d\nStack trace:\n%s\n\n",
        $timestamp,
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine(),
        $exception->getTraceAsString()
    );
    @file_put_contents($logFile, $message, FILE_APPEND);
    // SEC-04: No exponer detalles internos al cliente
    api_error(500, 'internal_server_error', 'Error interno del servidor. Consulta los logs para más detalles.');
});

set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline) {
    if (!(error_reporting() & $errno)) {
        return false;
    }
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

// ── Auth: Bearer token ──────────────────────────────────────────────
/**
 * Lee el header Authorization: Bearer <token>, valida contra api_tokens
 * y devuelve la fila del usuario. Aborta con 401 si falta o no es válido.
 */
function api_require_user(): array {
    $hdr = '';
    foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $k) {
        if (!empty($_SERVER[$k])) { $hdr = $_SERVER[$k]; break; }
    }
    if ($hdr === '' && function_exists('getallheaders')) {
        $all = array_change_key_case(getallheaders(), CASE_LOWER);
        $hdr = $all['authorization'] ?? '';
    }
    if (!preg_match('~^Bearer\s+([A-Za-z0-9._-]+)$~i', $hdr, $m)) {
        api_error(401, 'unauthorized', 'Falta o no es válido el header Authorization');
    }
    $token = $m[1];
    $pdo   = kp_db();

    // 1. Primero intentar con tokens de sesión (editor_api_tokens - texto plano)
    $st = $pdo->prepare("
        SELECT u.*, 'editor' as token_type FROM editor_api_tokens t
        JOIN users u ON u.id = t.user_id
        WHERE t.token = :tok
          AND (t.expires_at IS NULL OR t.expires_at > :now)
    ");
    $st->execute([':tok' => $token, ':now' => time()]);
    $user = $st->fetch();

    // 2. Si no, intentar con tokens persistentes (api_tokens - hash sha256)
    if (!$user) {
        $hash = hash('sha256', $token);
        $st = $pdo->prepare("
            SELECT u.*, 'persistent' as token_type FROM api_tokens t
            JOIN users u ON u.id = t.user_id
            WHERE t.token_hash = :hash
        ");
        $st->execute([':hash' => $hash]);
        $user = $st->fetch();
    }

    if (!$user) {
        try {
            require_once __DIR__ . '/../includes/helpers.php';
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            kp_alert('security', 'Token API inválido', "Se intentó acceder a la API con un token inválido o expirado desde $ip.", '');
        } catch (Throwable $e) {}
        api_error(401, 'unauthorized', 'Token inválido o expirado');
    }

    // Actualizar last_used (no crítico)
    try {
        $now = time();
        if (($user['token_type'] ?? '') === 'editor') {
            $pdo->prepare("UPDATE editor_api_tokens SET last_used=:now WHERE token=:tok")
                ->execute([':now' => $now, ':tok' => $token]);
        } else {
            $pdo->prepare("UPDATE api_tokens SET last_used_at=:now WHERE token_hash=:hash")
                ->execute([':now' => $now, ':hash' => hash('sha256', $token)]);
        }
    } catch (Throwable $e) { /* ignore */ }

    return $user;
}

// ── Body parsing ────────────────────────────────────────────────────
function api_read_json_body(): array {
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

// ── Utils ───────────────────────────────────────────────────────────
function api_slugify(string $s): string {
    $s = trim($s);
    $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
    $s = preg_replace('~[^A-Za-z0-9]+~', '-', $s);
    $s = trim($s, '-');
    $s = strtolower($s);
    return $s === '' ? 'sin-titulo' : $s;
}

function api_random_token(int $bytes = 32): string {
    return bin2hex(random_bytes($bytes));
}

/** Pagina con LIMIT/OFFSET; devuelve [page, perPage, offset]. */
function api_paging(int $defaultPerPage = 10, int $maxPerPage = 100): array {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per  = (int)($_GET['per_page'] ?? $defaultPerPage);
    if ($per <= 0) $per = $defaultPerPage;
    if ($per > $maxPerPage) $per = $maxPerPage;
    return [$page, $per, ($page - 1) * $per];
}

/**
 * Comprueba que $userId es owner del podcast $podcastId.
 * Lanza 403 si no. Devuelve la fila del podcast.
 */
function api_require_podcast_owner(int $userId, $podcastId): array {
    $pdo = kp_db();
    // Aceptar tanto numérico como slug
    if (is_numeric($podcastId)) {
        $sql = "SELECT * FROM podcasts WHERE id = :pid";
    } else {
        $sql = "SELECT * FROM podcasts WHERE slug = :pid";
    }
    $st = $pdo->prepare($sql);
    $st->execute([':pid' => $podcastId]);
    $p = $st->fetch();
    if (!$p) api_error(404, 'not_found', 'Podcast no encontrado');
    
    // Check if user is owner/admin
    $stRole = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
    $stRole->execute([$userId]);
    $u = $stRole->fetch();
    $is_admin = $u && in_array($u['role'], ['owner', 'admin'], true);
    
    if (!$is_admin) {
        $pu = $pdo->prepare("SELECT 1 FROM podcast_users WHERE podcast_id = ? AND user_id = ?");
        $pu->execute([$p['id'], $userId]);
        if (!$pu->fetch()) {
            api_error(403, 'forbidden', 'No tienes permisos sobre este podcast');
        }
    }
    return $p;
}
