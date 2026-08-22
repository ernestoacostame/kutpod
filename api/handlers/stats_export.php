<?php
// ============================================================================
// KutPod · API endpoint para exportar estadísticas globales a CSV
// ============================================================================

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/op3-stats.php';
require_once __DIR__ . '/../../includes/helpers.php';

// 1. Authenticate user (either by Bearer token or session)
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

// 2. Resolve range and query days
$range = $_GET['range'] ?? '30d';
$valid_ranges = ['7d' => 7, '30d' => 30, 'mtd' => (int)date('j'), 'all' => 3650];
$days = $valid_ranges[$range] ?? 30;

// 3. Check if OP3 tables exist
if (!kp_op3_tables_exist()) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="stats-export-' . $range . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM for Excel UTF-8 support
    fputcsv($out, ['Fecha', 'Podcast', 'Descargas', 'Oyentes Únicos', 'Descargas de Bots']);
    fclose($out);
    exit;
}

// 4. Query global statistics per podcast per day in range
$sql = "
  SELECT s.date, p.title AS podcast_title, s.downloads, s.unique_listeners, s.bot_downloads
  FROM op3_stats_daily s
  JOIN podcasts p ON p.id = s.podcast_id
  WHERE s.date >= date('now', ?)
  ORDER BY s.date DESC, p.title ASC
";

try {
    $rows = kp_q($sql, ['-' . $days . ' day']);
} catch (Throwable $e) {
    api_error(500, 'database_error', 'Error al consultar las estadísticas en la base de datos.');
}

// 5. Output CSV file download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="stats-export-' . $range . '.csv"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM for Excel UTF-8 support

// Header row
fputcsv($out, ['Fecha', 'Podcast', 'Descargas', 'Oyentes Únicos', 'Descargas de Bots']);

// Data rows
foreach ($rows as $row) {
    fputcsv($out, [
        $row['date'],
        $row['podcast_title'],
        $row['downloads'],
        $row['unique_listeners'],
        $row['bot_downloads']
    ]);
}

fclose($out);
exit;
