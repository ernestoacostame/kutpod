<?php
// ============================================================================
// KutPod · Análisis de Rendimiento Comparativo de Episodios (Cohortes / Ciclo de Vida)
// ============================================================================
// Alinea los episodios en "Día 0" (fecha de publicación) para comparar curvas de
// despegue, velocidad de consumo y retención relativa respecto a la mediana del podcast.
// ============================================================================

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/op3-tracker.php';

/**
 * Paleta de colores distintivos y accesibles para las curvas de rendimiento.
 */
function kp_op3_perf_colors(): array {
    return [
        '#ec4899', // Rosa / Fucsia
        '#06b6d4', // Cyan
        '#10b981', // Verde esmeralda
        '#f97316', // Naranja
        '#8b5cf6', // Violeta
        '#eab308', // Amarillo
        '#3b82f6', // Azul
        '#ef4444', // Rojo
        '#14b8a6', // Teal
        '#f43f5e', // Rosa fuerte
        '#a855f7', // Púrpura
        '#84cc16', // Lima
        '#6366f1', // Índigo
        '#fb923c', // Naranja claro
        '#2dd4bf', // Turquesa
        '#facc15', // Dorado
        '#38bdf8', // Azul cielo
        '#fb7185', // Salmón
        '#4ade80', // Verde brillante
        '#c084fc'  // Lavanda
    ];
}

/**
 * Calcula la mediana de un arreglo numérico.
 */
function kp_calc_median(array $values): float {
    $count = count($values);
    if ($count === 0) return 0.0;
    sort($values, SORT_NUMERIC);
    $mid = (int)floor($count / 2);
    if ($count % 2 === 1) {
        return (float)$values[$mid];
    }
    return ((float)$values[$mid - 1] + (float)$values[$mid]) / 2.0;
}

/**
 * Calcula la curva acumulada de descargas/oyentes de un episodio desde el día 0 hasta $maxDays tras lanzamiento.
 */
function kp_op3_episode_cohort_curve(int $episodeId, string $publishedDate, int $maxDays = 60): array {
    $curve = array_fill(0, $maxDays + 1, 0);
    if ($episodeId <= 0 || empty($publishedDate)) {
        return $curve;
    }

    $pubDay = substr($publishedDate, 0, 10);
    $rows = kp_q("
        SELECT date, downloads, unique_listeners
        FROM op3_stats_daily
        WHERE episode_id = ? AND date >= ?
        ORDER BY date ASC
    ", [$episodeId, $pubDay]);

    $dailyDls = [];
    if ($rows) {
        foreach ($rows as $r) {
            $offset = (int)floor((strtotime($r['date']) - strtotime($pubDay)) / 86400);
            if ($offset >= 0 && $offset <= $maxDays) {
                $dailyDls[$offset] = (int)$r['downloads'];
            }
        }
    }

    // Si existen métricas importadas de Apple / Spotify y no hay suficiente OP3 daily,
    // distribuir la curva de manera proporcional para reflejar los oyentes reales
    $ext = kp_one("SELECT plays, listeners FROM external_platform_stats WHERE episode_id = ? ORDER BY plays DESC LIMIT 1", [$episodeId]);
    $extAudience = $ext ? max((int)($ext['listeners'] ?? 0), (int)($ext['plays'] ?? 0)) : 0;

    $running = 0;
    $daysSinceRelease = (int)floor((time() - strtotime($pubDay)) / 86400);

    for ($d = 0; $d <= $maxDays; $d++) {
        if (isset($dailyDls[$d])) {
            $running += $dailyDls[$d];
        }
        // Si hay datos externos y superan lo registrado en OP3 web local
        if ($running < $extAudience && $d <= $daysSinceRelease) {
            // Curva asintótica de crecimiento típica de podcast
            $factor = 1.0 - exp(-0.15 * ($d + 1));
            $est = (int)round($extAudience * $factor);
            if ($est > $running) {
                $running = $est;
            }
        }
        $curve[$d] = $running;
    }

    return $curve;
}

/**
 * Recopila todos los datos de rendimiento comparativo para un podcast (o todos).
 */
function kp_op3_get_performance_data(int $podcastId = 0, int $limit = 50, int $maxDays = 60, string $compareMode = 'median'): array {
    kp_op3_ensure_schema();

    $where = "WHERE e.hidden = 0 AND e.status = 'published' AND e.published_at IS NOT NULL";
    $args = [];
    if ($podcastId > 0) {
        $where .= " AND e.podcast_id = ?";
        $args[] = $podcastId;
    }

    $episodes = kp_q("
        SELECT e.id, e.podcast_id, e.title, e.slug, e.season, e.number, e.duration_secs,
               COALESCE(e.published_at, e.publish_at, e.created_at) AS pub_date,
               p.title AS podcast_title, p.color AS podcast_color
        FROM episodes e
        JOIN podcasts p ON p.id = e.podcast_id
        $where
        ORDER BY COALESCE(e.published_at, e.publish_at, e.created_at) DESC
        LIMIT " . (int)$limit,
        $args
    ) ?: [];

    if (empty($episodes)) {
        return [
            'episodes'     => [],
            'median_curve' => array_fill(0, $maxDays + 1, 0),
            'max_val'      => 10,
            'max_days'     => $maxDays
        ];
    }

    $colors = kp_op3_perf_colors();
    $colorCount = count($colors);

    $curvesPerDay = array_fill(0, $maxDays + 1, []);
    $preparedEps = [];
    $globalMax = 10;

    foreach ($episodes as $idx => $ep) {
        $epId = (int)$ep['id'];
        $pubDate = (string)$ep['pub_date'];
        $curve = kp_op3_episode_cohort_curve($epId, $pubDate, $maxDays);

        $color = $colors[$idx % $colorCount];
        $daysSince = max(0, (int)floor((time() - strtotime(substr($pubDate, 0, 10))) / 86400));
        $cutoffDay = min($maxDays, $daysSince);
        $currVal = $curve[$cutoffDay] ?? 0;

        if ($currVal > $globalMax) {
            $globalMax = $currVal;
        }

        for ($d = 0; $d <= $maxDays; $d++) {
            if ($curve[$d] > $globalMax) {
                $globalMax = $curve[$d];
            }
            if ($d <= $daysSince) {
                $curvesPerDay[$d][] = $curve[$d];
            }
        }

        $epSeason = (int)($ep['season'] ?? 1);
        $epNumber = (int)($ep['number'] ?? 0);
        $epNumStr = '';
        if ($epSeason > 0 && $epNumber > 0) {
            $epNumStr = sprintf('S%d E%02d', $epSeason, $epNumber);
        } elseif ($epNumber > 0) {
            $epNumStr = sprintf('E%02d', $epNumber);
        } else {
            $epNumStr = 'Bonus';
        }

        $formattedDate = date('d M Y', strtotime($pubDate));

        $preparedEps[] = [
            'id'             => $epId,
            'title'          => $ep['title'],
            'num_str'        => $epNumStr,
            'pub_date_fmt'   => $formattedDate,
            'pub_date_raw'   => $pubDate,
            'days_since'     => $daysSince,
            'podcast_id'     => (int)$ep['podcast_id'],
            'podcast_title'  => $ep['podcast_title'],
            'color'          => $color,
            'curve'          => $curve,
            'current_value'  => $currVal,
            'is_checked'     => ($idx < 5), // Los primeros 5 activados por defecto
            'change_pct'     => 0
        ];
    }

    // Calcular la curva de mediana (o promedio) para cada día
    $medianCurve = array_fill(0, $maxDays + 1, 0);
    for ($d = 0; $d <= $maxDays; $d++) {
        $vals = $curvesPerDay[$d] ?? [];
        if (!empty($vals)) {
            if ($compareMode === 'average') {
                $medianCurve[$d] = (int)round(array_sum($vals) / count($vals));
            } else {
                $medianCurve[$d] = (int)round(kp_calc_median($vals));
            }
        } elseif ($d > 0) {
            $medianCurve[$d] = $medianCurve[$d - 1];
        }
    }

    // Calcular el % de cambio respecto a la mediana para cada episodio
    foreach ($preparedEps as &$pe) {
        $cutoffDay = min($maxDays, $pe['days_since']);
        $medAtCutoff = $medianCurve[$cutoffDay] ?? $medianCurve[$maxDays] ?? 1;
        if ($medAtCutoff > 0) {
            $pe['change_pct'] = (int)round((($pe['current_value'] - $medAtCutoff) / $medAtCutoff) * 100);
        } else {
            $pe['change_pct'] = 0;
        }
    }
    unset($pe);

    return [
        'episodes'     => $preparedEps,
        'median_curve' => $medianCurve,
        'max_val'      => max(10, (int)(ceil($globalMax * 1.15))),
        'max_days'     => $maxDays
    ];
}
