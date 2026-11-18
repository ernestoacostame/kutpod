<?php
// ============================================================================
// KutPod · API endpoint para importar estadísticas externas (Apple & Spotify)
// ============================================================================

declare(strict_types=1);

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/op3-tracker.php';
require_once __DIR__ . '/../../includes/op3-importer.php';

// 1. Autenticación: verificar sesión por cookie web o Bearer token
$user = kp_current_user();
if (!$user) {
    $auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (!$auth_header && function_exists('getallheaders')) {
        $headers = array_change_key_case(getallheaders(), CASE_LOWER);
        $auth_header = $headers['authorization'] ?? '';
    }
    if ($auth_header && preg_match('~^Bearer\s+~i', $auth_header)) {
        try {
            $user = api_require_user();
        } catch (Throwable $e) {}
    }
}

if (!$user) {
    api_error(401, 'unauthorized', 'Usuario no autenticado');
}

$role = $user['role'] ?? 'author';
if (!in_array($role, ['owner', 'admin', 'editor'], true)) {
    api_error(403, 'forbidden', 'No tienes permisos para importar métricas');
}

// 2. Parámetros
$podcastId = (int)($_POST['podcast_id'] ?? 0);
$platform  = trim((string)($_POST['platform'] ?? 'auto'));
$redirect  = trim((string)($_POST['redirect'] ?? ''));

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))
    || !empty($_POST['ajax']);

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $errCode = $_FILES['file']['error'] ?? -1;
    $msg = 'Error al subir el archivo CSV (Código: ' . $errCode . ')';
    if ($isAjax) {
        api_error(400, 'upload_failed', $msg);
    } else {
        setcookie('kp_flash', 'Error: ' . $msg, time() + 30, '/');
        header('Location: ' . ($redirect ?: '/admin/analytics'));
        exit;
    }
}

$tmpPath = (string)$_FILES['file']['tmp_name'];
$origName = (string)$_FILES['file']['name'];

try {
    $result = kp_op3_import_external_csv($podcastId, $platform, $tmpPath, $origName);

    if ($isAjax) {
        api_json($result);
    } else {
        $pName = ($result['platform'] === 'apple') ? 'Apple Podcasts' : (($result['platform'] === 'spotify') ? 'Spotify' : 'CSV');
        $flash = "Se importaron las métricas de {$result['matched']} episodios ({$pName}) correctamente.";
        if ($result['unmatched'] > 0) {
            $flash .= " ({$result['unmatched']} filas del CSV no coincidieron con episodios de este podcast)";
        }
        setcookie('kp_flash', $flash, time() + 30, '/');
        $target = $redirect ?: ('/admin/podcast?id=' . $podcastId);
        header('Location: ' . $target);
        exit;
    }
} catch (Throwable $e) {
    if ($isAjax) {
        api_error(500, 'import_failed', $e->getMessage());
    } else {
        setcookie('kp_flash', 'Error al importar CSV: ' . $e->getMessage(), time() + 30, '/');
        header('Location: ' . ($redirect ?: '/admin/analytics'));
        exit;
    }
}
