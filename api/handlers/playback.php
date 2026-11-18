<?php
// ============================================================================
// KutPod · API endpoint para registrar telemetría de reproducción (web / embed)
// ============================================================================

declare(strict_types=1);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/op3-tracker.php';

// Leer datos enviados como JSON o form POST
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = $_POST;
}

$epParam     = $data['episode_id'] ?? $data['episode'] ?? $data['ep'] ?? null;
$podParam    = $data['podcast_id'] ?? $data['podcast'] ?? $data['show'] ?? null;
$secs        = (int)($data['listened_secs'] ?? $data['secs'] ?? 0);
$isStart     = !empty($data['is_start']);
$isCompleted = !empty($data['is_completed']);

if (!$epParam) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Missing episode identifier']);
    exit;
}

$epId = 0;
$podId = 0;

if (is_numeric($epParam)) {
    $epId = (int)$epParam;
    if (is_numeric($podParam)) {
        $podId = (int)$podParam;
    } else {
        $ep = kp_one("SELECT id, podcast_id FROM episodes WHERE id = ?", [$epId]);
        if ($ep) $podId = (int)$ep['podcast_id'];
    }
} else {
    // Buscar por slug
    if ($podParam) {
        $ep = kp_one("
            SELECT e.id, e.podcast_id 
            FROM episodes e 
            JOIN podcasts p ON p.id = e.podcast_id 
            WHERE (LOWER(p.slug) = LOWER(?) OR p.id = ?) AND LOWER(e.slug) = LOWER(?)
        ", [(string)$podParam, is_numeric($podParam) ? (int)$podParam : 0, (string)$epParam]);
    } else {
        $ep = kp_one("SELECT id, podcast_id FROM episodes WHERE LOWER(slug) = LOWER(?)", [(string)$epParam]);
    }
    if ($ep) {
        $epId = (int)$ep['id'];
        $podId = (int)$ep['podcast_id'];
    }
}

if ($epId > 0 && $podId > 0) {
    kp_op3_record_playback($epId, $podId, $secs, $isStart, $isCompleted);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'ok', 'episode_id' => $epId]);
} else {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Episode not found']);
}
exit;
