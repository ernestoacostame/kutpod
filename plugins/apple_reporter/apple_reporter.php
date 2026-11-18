<?php
// ============================================================================
// KutPod · Plugin Apple Podcasts Reporter (Cliente PHP Nativo)
// ============================================================================
// Implementa el protocolo HTTP de Apple Reporter (reportingitc-reporter.apple.com)
// en PHP puro sin dependencias de Java para automatizar la descarga de reportes
// de escucha de episodios (apEpisodeListening), suscriptores y rendimiento.
// ============================================================================

declare(strict_types=1);

if (!defined('KUTPOD_VERSION')) {
    exit;
}

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/op3-tracker.php';
require_once __DIR__ . '/../../includes/op3-importer.php';

const KP_APPLE_REPORTER_ENDPOINT = 'https://reportingitc-reporter.apple.com/reportservice/sales/v1';

/**
 * Inicializa la tabla de auditoría y registros de sincronización de Apple Reporter.
 */
function kp_apple_reporter_init_db(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $pdo = kp_db();
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS apple_reporter_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            podcast_id INTEGER REFERENCES podcasts(id) ON DELETE CASCADE,
            report_date TEXT NOT NULL,
            report_type TEXT NOT NULL,
            matched_episodes INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT 'success',
            message TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        );
        CREATE INDEX IF NOT EXISTS idx_apple_rep_date ON apple_reporter_logs(report_date);
    ");
}

/**
 * Realiza una petición HTTPS autenticada al servicio Reporter de Apple.
 */
function kp_apple_reporter_request(string $queryInput, ?string &$error = null): ?string {
    $token  = trim(kp_setting('apple_reporter_access_token', ''));
    $userId = trim(kp_setting('apple_reporter_user_id', ''));

    if ($token === '') {
        $error = 'Access Token de Apple Podcasts no configurado.';
        return null;
    }

    $payload = [
        'userid'      => $userId ?: 'User',
        'accesstoken' => $token,
        'version'     => '1.0',
        'mode'        => 'Normal',
        'queryInput'  => $queryInput
    ];

    $postData = http_build_query([
        'jsonRequest' => json_encode($payload, JSON_UNESCAPED_SLASHES)
    ]);

    $ch = curl_init(KP_APPLE_REPORTER_ENDPOINT);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postData,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: */*',
            'User-Agent: KutPod-AppleReporter/1.0'
        ]
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curlErr) {
        $error = 'Error de conexión con Apple Reporter: ' . ($curlErr ?: 'Respuesta vacía');
        return null;
    }

    // Verificar si la respuesta viene comprimida con gzip (magic bytes 0x1F 0x8B)
    if (strlen($response) >= 2 && substr($response, 0, 2) === "\x1F\x8B") {
        $uncompressed = @gzdecode($response);
        if ($uncompressed !== false) {
            $response = $uncompressed;
        }
    }

    if ($httpCode !== 200) {
        $error = 'Error ' . $httpCode . ' de Apple Reporter: ' . trim(strip_tags((string)$response));
        return null;
    }

    // Comprobar si Apple respondió con un mensaje de error en texto plano
    if (str_starts_with(trim($response), 'ERROR:') || str_contains($response, '<Error>')) {
        $error = trim((string)$response);
        return null;
    }

    return (string)$response;
}

/**
 * Prueba la conectividad y las credenciales con Apple Reporter.
 */
function kp_apple_reporter_test_connection(?string &$error = null): array {
    $token = trim(kp_setting('apple_reporter_access_token', ''));
    if ($token === '') {
        $error = 'Debes introducir un Access Token.';
        return ['ok' => false, 'error' => $error];
    }

    // 1. Obtener vendors asociados
    $resVendors = kp_apple_reporter_request('[p=Reporter.properties, Sales.getVendors]', $errVendors);
    if ($errVendors) {
        // Intentar con getStatus
        $resStatus = kp_apple_reporter_request('[p=Reporter.properties, Sales.getStatus]', $errStatus);
        if ($errStatus) {
            $error = $errVendors;
            return ['ok' => false, 'error' => $error];
        }
        return [
            'ok'      => true,
            'message' => 'Conexión con Apple establecida exitosamente (Estado: ' . trim($resStatus) . ').'
        ];
    }

    $vendors = array_filter(array_map('trim', explode("\n", $resVendors)));
    return [
        'ok'      => true,
        'vendors' => array_values($vendors),
        'message' => 'Conexión exitosa. Vendors disponibles: ' . implode(', ', $vendors)
    ];
}

/**
 * Descarga y parsea un reporte específico desde Apple Podcasts Connect.
 */
function kp_apple_reporter_fetch_report(
    string $vendorNumber,
    string $reportType = 'Podcast',
    string $reportSubtype = 'apEpisodeListening',
    string $dateType = 'Daily',
    string $date = '',
    ?string &$error = null
): ?string {
    if ($date === '') {
        // Por defecto, anteayer (Apple procesa los reportes diarios con 24-48h de desfase)
        $date = date('Ymd', strtotime('-2 days'));
    }

    $query = sprintf(
        '[p=Reporter.properties, Sales.getReport, %s, %s, %s, %s, %s]',
        $vendorNumber,
        $reportType,
        $reportSubtype,
        $dateType,
        $date
    );

    return kp_apple_reporter_request($query, $error);
}

/**
 * Procesa el contenido TSV/CSV devuelto por Apple e ingesta las métricas en KutPod.
 */
function kp_apple_reporter_ingest_content(int $podcastId, string $tsvContent, string $date = ''): array {
    kp_apple_reporter_init_db();
    kp_op3_ensure_schema();

    $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", trim($tsvContent)));
    if (empty($lines)) {
        return ['matched' => 0, 'total' => 0];
    }

    $headerLine = array_shift($lines);
    $headers = array_map(function($h) {
        return mb_strtolower(trim($h), 'UTF-8');
    }, explode("\t", $headerLine));

    // Si viene con comas en lugar de tabuladores
    if (count($headers) <= 1 && str_contains($headerLine, ',')) {
        $headers = array_map(function($h) {
            return mb_strtolower(trim(str_replace('"', '', $h)), 'UTF-8');
        }, str_getcsv($headerLine));
    }

    $episodes = kp_q("SELECT id, guid, slug, title, duration_secs FROM episodes WHERE podcast_id = ?", [$podcastId]);
    $byGuid = [];
    $byTitle = [];
    foreach ($episodes as $ep) {
        if (!empty($ep['guid'])) $byGuid[strtolower(trim($ep['guid']))] = $ep;
        $norm = kp_op3_normalize_title($ep['title']);
        if ($norm !== '') $byTitle[$norm] = $ep;
    }

    $pdo = kp_db();
    $stmtUpsert = $pdo->prepare("
        INSERT INTO external_platform_stats (
            podcast_id, episode_id, platform, source_file,
            plays, listeners, avg_playback_secs, completion_pct,
            total_time_secs, raw_data, imported_at
        ) VALUES (
            :podcast_id, :episode_id, 'apple', :source_file,
            :plays, :listeners, :avg_playback_secs, :completion_pct,
            :total_time_secs, :raw_data, datetime('now')
        )
        ON CONFLICT(episode_id, platform) DO UPDATE SET
            source_file       = excluded.source_file,
            plays             = excluded.plays,
            listeners         = excluded.listeners,
            avg_playback_secs = excluded.avg_playback_secs,
            completion_pct    = excluded.completion_pct,
            total_time_secs   = excluded.total_time_secs,
            raw_data          = excluded.raw_data,
            imported_at       = datetime('now')
    ");

    $matched = 0;
    $total = 0;

    foreach ($lines as $line) {
        if (trim($line) === '') continue;
        $cols = str_contains($line, "\t") ? explode("\t", $line) : str_getcsv($line);
        $total++;

        $row = [];
        foreach ($headers as $idx => $name) {
            $row[$name] = isset($cols[$idx]) ? trim($cols[$idx]) : '';
        }

        $epTitle = $row['episode title'] ?? $row['episode name'] ?? $row['title'] ?? '';
        $epGuid  = $row['episode guid'] ?? $row['item guid'] ?? $row['guid'] ?? '';
        $matchedEp = null;

        if ($epGuid !== '' && isset($byGuid[strtolower($epGuid)])) {
            $matchedEp = $byGuid[strtolower($epGuid)];
        } elseif ($epTitle !== '') {
            $tNorm = kp_op3_normalize_title($epTitle);
            if (isset($byTitle[$tNorm])) {
                $matchedEp = $byTitle[$tNorm];
            } else {
                foreach ($byTitle as $dbNorm => $ep) {
                    if (str_contains($tNorm, $dbNorm) || str_contains($dbNorm, $tNorm)) {
                        $matchedEp = $ep;
                        break;
                    }
                }
            }
        }

        if (!$matchedEp) continue;

        $epId = (int)$matchedEp['id'];
        $durSecs = (int)($matchedEp['duration_secs'] ?? 0);

        $plays = kp_op3_parse_int_val($row['plays'] ?? $row['totallisteners'] ?? 0);
        $listeners = kp_op3_parse_int_val($row['unique listeners'] ?? $row['listeners'] ?? $row['uniquelisteners'] ?? 0);
        $completionPct = kp_op3_parse_pct_val($row['average consumption'] ?? $row['consumption'] ?? 0);
        $avgPlaybackSecs = kp_op3_parse_time_to_secs($row['average listen time'] ?? $row['avg listen time'] ?? 0);

        if ($avgPlaybackSecs <= 0 && $completionPct > 0.0 && $durSecs > 0) {
            $avgPlaybackSecs = (int)round(($completionPct / 100.0) * $durSecs);
        }
        if ($completionPct <= 0.0 && $avgPlaybackSecs > 0 && $durSecs > 0) {
            $completionPct = round(($avgPlaybackSecs / $durSecs) * 100.0, 1);
        }
        $totalTimeSecs = ($avgPlaybackSecs > 0 && $plays > 0) ? (int)($avgPlaybackSecs * $plays) : 0;

        $stmtUpsert->execute([
            ':podcast_id'        => $podcastId,
            ':episode_id'        => $epId,
            ':source_file'       => 'apple_reporter_' . ($date ?: date('Ymd')),
            ':plays'             => $plays,
            ':listeners'         => $listeners,
            ':avg_playback_secs' => $avgPlaybackSecs,
            ':completion_pct'    => $completionPct,
            ':total_time_secs'   => $totalTimeSecs,
            ':raw_data'          => json_encode($row, JSON_UNESCAPED_UNICODE)
        ]);

        $matched++;
    }

    try {
        $pdo->prepare("
            INSERT INTO apple_reporter_logs (podcast_id, report_date, report_type, matched_episodes, status, message)
            VALUES (?, ?, 'apEpisodeListening', ?, 'success', ?)
        ")->execute([$podcastId, $date ?: date('Y-m-d'), $matched, "Sincronizados $matched de $total registros desde Apple Reporter"]);
    } catch (Throwable $e) {}

    return ['matched' => $matched, 'total' => $total];
}
