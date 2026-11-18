<?php
// ============================================================================
// KutPod · API endpoint para exportar estadísticas a CSV
// ============================================================================
// Proporciona informe detallado para patrocinadores:
// - Resumen consolidado del podcast en general (duración media, tiempo promedio de
//   reproducción, % de consumo/retención y horas totales escuchadas).
// - Desglose ordenado de cada episodio en particular con sus métricas específicas.
// ============================================================================

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/op3-tracker.php';
require_once __DIR__ . '/../../includes/op3-stats.php';
require_once __DIR__ . '/../../includes/helpers.php';

// 1. Autenticación (Bearer token o sesión activa de usuario)
$user = null;
$auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if ($auth_header || (function_exists('getallheaders') && array_key_exists('authorization', array_change_key_case(getallheaders(), CASE_LOWER)))) {
    $user = api_require_user();
} else {
    $user = kp_current_user();
}

if (!$user) {
    api_error(401, 'unauthorized', 'Usuario no autenticado');
}

// 2. Rango de tiempo y días
$range = $_GET['range'] ?? '30d';
$valid_ranges = [
    '7d'  => 7,
    '30d' => 30,
    'mtd' => max(1, (int)date('j')),
    'all' => 3650
];
$days = $valid_ranges[$range] ?? 30;

$range_labels = [
    '7d'  => 'Últimos 7 días',
    '30d' => 'Últimos 30 días',
    'mtd' => 'Este mes (' . date('F Y') . ')',
    'all' => 'Histórico total'
];
$period_label = $range_labels[$range] ?? ($days . ' días');

// 3. Filtro opcional por podcast
$podParam = $_GET['podcast'] ?? $_GET['podcast_id'] ?? $_GET['show'] ?? null;
$podWhere = "WHERE p.hidden = 0";
$podArgs  = [];
if ($podParam) {
    if (is_numeric($podParam)) {
        $podWhere .= " AND p.id = ?";
        $podArgs[] = (int)$podParam;
    } else {
        $podWhere .= " AND LOWER(p.slug) = LOWER(?)";
        $podArgs[] = (string)$podParam;
    }
}

// Asegurar esquema si es necesario
kp_op3_ensure_schema();

try {
    $podcasts = kp_q("SELECT p.id, p.title, p.slug, p.type FROM podcasts p $podWhere ORDER BY p.title ASC", $podArgs);
} catch (Throwable $e) {
    api_error(500, 'database_error', 'Error al consultar podcasts: ' . $e->getMessage());
}

$filename = 'stats-report-' . ($podParam ? preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$podParam) . '-' : '') . $range . '.csv';

// 4. Salida del archivo CSV
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM para compatibilidad total con Excel / UTF-8

// Encabezados de columnas
fputcsv($out, [
    'Tipo de Registro',
    'Podcast',
    'Episodio',
    'Temporada / Episodio',
    'Fecha / Período',
    'Descargas',
    'Oyentes Únicos',
    'Descargas de Bots',
    'Duración',
    'Tiempo Medio de Reproducción',
    '% Consumo Promedio',
    'Tiempo Total Escuchado'
]);

// 5. Generar filas por cada podcast y sus episodios
foreach ($podcasts as $p) {
    $podId = (int)$p['id'];
    $podTitle = $p['title'];

    // Estadísticas agregadas del podcast
    $podStats = kp_op3_podcast_playback_stats($podId, $days);

    // Fila 1: Resumen General del Podcast
    fputcsv($out, [
        'RESUMEN PODCAST',
        $podTitle,
        'Todos los episodios (Resumen General)',
        '—',
        $period_label,
        $podStats['downloads'],
        $podStats['unique_listeners'],
        $podStats['bot_downloads'],
        $podStats['avg_duration_fmt'],
        $podStats['avg_playback_fmt'],
        $podStats['avg_completion_pct'] . '%',
        $podStats['total_time_fmt']
    ]);

    // Episodios particulares de este podcast
    $episodes = kp_q("
        SELECT id, title, slug, season, number, duration_secs,
               COALESCE(published_at, publish_at, created_at) AS date_iso,
               status
        FROM episodes
        WHERE podcast_id = ? AND hidden = 0
        ORDER BY COALESCE(published_at, publish_at, created_at) DESC
    ", [$podId]);

    foreach ($episodes as $ep) {
        $epId = (int)$ep['id'];
        $epStats = kp_op3_episode_playback_stats($epId, $days);

        $epSeason = (int)($ep['season'] ?? 1);
        $epNumber = (int)($ep['number'] ?? 0);
        $epNumStr = '';
        if ($epSeason > 0 && $epNumber > 0) {
            $epNumStr = sprintf('T%02d · E%02d', $epSeason, $epNumber);
        } elseif ($epNumber > 0) {
            $epNumStr = sprintf('E%02d', $epNumber);
        } else {
            $epNumStr = '—';
        }

        $epDateStr = !empty($ep['date_iso']) ? substr($ep['date_iso'], 0, 10) : '—';

        fputcsv($out, [
            'Episodio',
            $podTitle,
            $ep['title'],
            $epNumStr,
            $epDateStr,
            $epStats['downloads'],
            $epStats['unique_listeners'],
            $epStats['bot_downloads'],
            $epStats['duration_fmt'],
            $epStats['avg_playback_fmt'],
            $epStats['completion_pct'] . '%',
            $epStats['total_time_fmt']
        ]);
    }

    // Fila vacía entre podcasts para mayor legibilidad
    fputcsv($out, []);
}

fclose($out);
exit;
