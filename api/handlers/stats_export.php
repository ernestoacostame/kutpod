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

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../data.php';
require_once __DIR__ . '/../../includes/op3-tracker.php';
require_once __DIR__ . '/../../includes/op3-stats.php';

// 1. Autenticación: primero comprobar la sesión web activa por cookie (navegador)
$user = kp_current_user();
if (!$user) {
    // Si no hay cookie de sesión web, verificar cabecera Authorization (Bearer token)
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

try {
    // Asegurar esquema si es necesario
    kp_op3_ensure_schema();

    $podcasts = kp_q("SELECT p.id, p.title, p.slug, p.type FROM podcasts p $podWhere ORDER BY p.title ASC", $podArgs);
    if (!$podcasts) {
        $podcasts = [];
    }

    $safeSlug = $podParam ? preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$podParam) : '';
    $filename = 'stats-report-' . ($safeSlug ? $safeSlug . '-' : '') . $range . '.csv';

    // Limpiar cualquier salida previa en el buffer
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    // 4. Salida del archivo CSV
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    if (!$out) {
        throw new RuntimeException('No se pudo abrir el stream de salida php://output');
    }

    fwrite($out, "\xEF\xBB\xBF"); // BOM para compatibilidad total con Excel / UTF-8

    // Encabezados de columnas
    fputcsv($out, [
        'Tipo de Registro',
        'Podcast',
        'Episodio',
        'Temporada / Episodio',
        'Fecha / Período',
        'Descargas (OP3)',
        'Oyentes Únicos (OP3)',
        'Descargas de Bots',
        'Duración',
        'Tiempo Medio de Reproducción',
        '% Consumo Consolidado',
        '% Retención Apple',
        '% Retención Spotify',
        'Tiempo Total Escuchado'
    ]);

    // 5. Generar filas por cada podcast y sus episodios
    foreach ($podcasts as $p) {
        $podId = (int)$p['id'];
        $podTitle = (string)$p['title'];

        // Estadísticas agregadas del podcast
        $podStats = kp_op3_podcast_playback_stats($podId, $days);

        // Fila 1: Resumen General del Podcast
        fputcsv($out, [
            'RESUMEN PODCAST',
            $podTitle,
            'Todos los episodios (Resumen General)',
            '—',
            $period_label,
            $podStats['downloads'] ?? 0,
            $podStats['unique_listeners'] ?? 0,
            $podStats['bot_downloads'] ?? 0,
            $podStats['avg_duration_fmt'] ?? '—',
            $podStats['avg_playback_fmt'] ?? '—',
            ($podStats['avg_completion_pct'] ?? 0) > 0 ? ($podStats['avg_completion_pct'] . '%') : '—',
            ($podStats['apple_avg_completion_pct'] ?? 0) > 0 ? ($podStats['apple_avg_completion_pct'] . '%') : '—',
            ($podStats['spotify_avg_completion_pct'] ?? 0) > 0 ? ($podStats['spotify_avg_completion_pct'] . '%') : '—',
            $podStats['total_time_fmt'] ?? '—'
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

        if (is_array($episodes)) {
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

                $epDateStr = !empty($ep['date_iso']) ? substr((string)$ep['date_iso'], 0, 10) : '—';

                fputcsv($out, [
                    'Episodio',
                    $podTitle,
                    (string)$ep['title'],
                    $epNumStr,
                    $epDateStr,
                    $epStats['downloads'] ?? 0,
                    $epStats['unique_listeners'] ?? 0,
                    $epStats['bot_downloads'] ?? 0,
                    $epStats['duration_fmt'] ?? '—',
                    $epStats['avg_playback_fmt'] ?? '—',
                    ($epStats['completion_pct'] ?? 0) > 0 ? ($epStats['completion_pct'] . '%') : '—',
                    isset($epStats['apple_completion_pct']) && $epStats['apple_completion_pct'] > 0 ? ($epStats['apple_completion_pct'] . '%') : '—',
                    isset($epStats['spotify_completion_pct']) && $epStats['spotify_completion_pct'] > 0 ? ($epStats['spotify_completion_pct'] . '%') : '—',
                    $epStats['total_time_fmt'] ?? '—'
                ]);
            }
        }

        // Fila vacía entre podcasts para mayor legibilidad
        fputcsv($out, []);
    }

    fclose($out);
    exit;

} catch (Throwable $e) {
    error_log('[stats_export error] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    api_error(500, 'export_error', 'Error generando el archivo CSV: ' . $e->getMessage());
}
