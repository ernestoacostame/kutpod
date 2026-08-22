<?php
/**
 * php/api/handlers/auth_login.php
 *
 * POST /api/auth/login
 * Body JSON: { "user": "<usuario o email>", "password": "<plain>" }
 *
 * Respuesta:
 *   200 → { token, user: { id, user, email, name, ... } }
 *   401 → credenciales inválidas
 */

declare(strict_types=1);
require_once __DIR__ . '/../../includes/helpers.php';

$body = api_read_json_body();
$user = trim((string)($body['user'] ?? ''));
$pass = (string)($body['password'] ?? '');

if ($user === '' || $pass === '') {
    api_error(400, 'bad_request', 'Faltan user o password');
}

// Rate limiting: máximo 5 intentos por IP en 15 minutos
require_once __DIR__ . '/../../includes/auth.php';
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (kp_login_rate_limited($ip)) {
    api_error(429, 'too_many_requests', 'Demasiados intentos de acceso. Intenta de nuevo en 15 minutos.');
}

$pdo = kp_db();
$st  = $pdo->prepare("SELECT * FROM users WHERE email = :u OR name = :u LIMIT 1");
$st->execute([':u' => $user]);
$row = $st->fetch();

$is_valid = false;
$used_token = false;
if ($row) {
    if (!empty($row['password_hash']) && password_verify($pass, $row['password_hash'])) {
        $is_valid = true;
    } else {
        // Verificar si la contraseña ingresada es un token de API persistente
        $hash = hash('sha256', $pass);
        $st_tok = $pdo->prepare("SELECT id FROM api_tokens WHERE user_id = ? AND token_hash = ? LIMIT 1");
        $st_tok->execute([$row['id'], $hash]);
        $tok = $st_tok->fetch();
        if ($tok) {
            $is_valid = true;
            $used_token = true;
            $pdo->prepare("UPDATE api_tokens SET last_used_at = ? WHERE id = ?")->execute([time(), $tok['id']]);
        }
    }
}

if (!$is_valid) {
    kp_login_record_failure($ip);
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    kp_alert('security', 'Login API fallido', "Intento de acceso vía API con usuario '$user' desde $ip.", '', ['actor_name' => $user]);
    api_error(401, 'invalid_credentials', 'Usuario o contraseña incorrectos');
}

// Rehash si el algoritmo ha cambiado (solo si se usó la contraseña maestra)
if (!$used_token && password_needs_rehash($row['password_hash'], PASSWORD_BCRYPT)) {
    $up = $pdo->prepare("UPDATE users SET password_hash = :h WHERE id = :id");
    $up->execute([':h' => password_hash($pass, PASSWORD_BCRYPT), ':id' => $row['id']]);
}

$token     = api_random_token(32);
$now       = time();
$expiresAt = $now + 60 * 60 * 24 * 30;   // 30 días

$ins = $pdo->prepare("
    INSERT INTO editor_api_tokens (token, user_id, client, created_at, last_used, expires_at)
    VALUES (:tok, :uid, :cli, :ts, :ts, :exp)
");
$ins->execute([
    ':tok' => $token,
    ':uid' => $row['id'],
    ':cli' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'), 0, 120),
    ':ts'  => $now,
    ':exp' => $expiresAt,
]);

unset($row['password_hash']);
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$client = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'), 0, 120);
kp_alert('security', 'Login API exitoso', "{$row['name']} se conectó vía API ($client) desde $ip.", '', ['actor_name' => $row['name'] ?? $user]);
api_json([
    'token'      => $token,
    'expires_at' => $expiresAt,
    'user'       => array_merge($row, ['user' => $user]),
]);
