<?php
// ============================================================================
// KutPod · Importador de Estadísticas Externas (Apple Podcasts & Spotify)
// ============================================================================
// Procesa archivos CSV exportados desde Apple Podcasts Connect y Spotify for Creators,
// vincula métricas de retención (% consumo, tiempo promedio, oyentes) con episodios locales
// y consolida la analítica para patrocinadores e informes ejecutivos.
// ============================================================================

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/op3-tracker.php';

/**
 * Normaliza un título para comparaciones insensibles a mayúsculas, acentos y signos de puntuación.
 */
function kp_op3_normalize_title(string $str): string {
  $str = mb_strtolower($str, 'UTF-8');
  // Reemplazar acentos comunes
  $str = str_replace(
    ['á','é','í','ó','ú','ü','ñ','à','è','ì','ò','ù'],
    ['a','e','i','o','u','u','n','a','e','i','o','u'],
    $str
  );
  // Reemplazar caracteres no alfanuméricos por espacios
  $str = (string)preg_replace('/[^\p{L}\p{N}]+/u', ' ', $str);
  return trim((string)preg_replace('/\s+/', ' ', $str));
}

/**
 * Parsea un valor de tiempo en formato 'HH:MM:SS', 'MM:SS' o segundos enteros a segundos (int).
 */
function kp_op3_parse_time_to_secs($val): int {
  if ($val === null || $val === '' || $val === '—' || $val === '-') return 0;
  $val = trim((string)$val);
  if (is_numeric($val)) return max(0, (int)$val);

  // Formato HH:MM:SS o MM:SS
  if (strpos($val, ':') !== false) {
    $parts = explode(':', $val);
    if (count($parts) === 2) {
      return (max(0, (int)$parts[0]) * 60) + max(0, (int)$parts[1]);
    }
    if (count($parts) === 3) {
      return (max(0, (int)$parts[0]) * 3600) + (max(0, (int)$parts[1]) * 60) + max(0, (int)$parts[2]);
    }
  }

  // Formato tipo "15m 30s", "1h 10m"
  $secs = 0;
  if (preg_match('/(\d+)\s*h/i', $val, $m)) $secs += (int)$m[1] * 3600;
  if (preg_match('/(\d+)\s*m/i', $val, $m)) $secs += (int)$m[1] * 60;
  if (preg_match('/(\d+)\s*s/i', $val, $m)) $secs += (int)$m[1];
  if ($secs > 0) return $secs;

  return 0;
}

/**
 * Parsea un porcentaje a flotante (ej. '82%', '0.82', '82,5%' -> 82.5).
 */
function kp_op3_parse_pct_val($val): float {
  if ($val === null || $val === '' || $val === '—' || $val === '-') return 0.0;
  $val = trim((string)$val);
  $val = str_replace('%', '', $val);
  $val = str_replace(',', '.', $val);
  if (!is_numeric($val)) return 0.0;
  $num = (float)$val;
  // Si está en escala 0.0 a 1.0 (ej. 0.825)
  if ($num > 0.0001 && $num <= 1.0) {
    $num = $num * 100.0;
  }
  return round(max(0.0, min(100.0, $num)), 1);
}

/**
 * Parsea un entero limpiando comas, puntos y espacios.
 */
function kp_op3_parse_int_val($val): int {
  if ($val === null || $val === '') return 0;
  $val = trim((string)$val);
  // Eliminar separadores de miles
  $clean = preg_replace('/[^0-9]/', '', $val);
  return (int)$clean;
}

/**
 * Detecta la plataforma (apple, spotify, other) a partir de los encabezados del CSV.
 */
function kp_op3_detect_platform_from_headers(array $headers): string {
  $joined = strtolower(implode(' ', $headers));
  if (
    strpos($joined, 'apple') !== false ||
    strpos($joined, 'engaged listeners') !== false ||
    strpos($joined, 'total time listened') !== false ||
    strpos($joined, 'average consumption') !== false ||
    strpos($joined, 'item guid') !== false ||
    strpos($joined, 'episode guid') !== false
  ) {
    return 'apple';
  }
  if (
    strpos($joined, 'spotify') !== false ||
    strpos($joined, 'starts') !== false ||
    strpos($joined, 'streams') !== false ||
    strpos($joined, 'median completion') !== false ||
    strpos($joined, 'completion rate') !== false ||
    strpos($joined, 'spotify uri') !== false ||
    strpos($joined, 'episode uri') !== false
  ) {
    return 'spotify';
  }
  return 'other';
}

/**
 * Lee un archivo CSV resolviendo codificaciones UTF-16LE / UTF-16BE / UTF-8 con BOM y delimitadores comunes.
 */
function kp_op3_read_csv_rows(string $filePath): array {
  if (!file_exists($filePath) || !is_readable($filePath)) {
    throw new InvalidArgumentException('El archivo no existe o no se puede leer: ' . $filePath);
  }

  $raw = file_get_contents($filePath);
  if ($raw === false || $raw === '') {
    return [];
  }

  // Manejo de codificación UTF-16LE / UTF-16BE (frecuente en exportaciones de Apple en macOS/Excel)
  if (str_starts_with($raw, "\xFF\xFE")) {
    $raw = mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16LE');
  } elseif (str_starts_with($raw, "\xFE\xFF")) {
    $raw = mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16BE');
  } elseif (str_starts_with($raw, "\xEF\xBB\xBF")) {
    $raw = substr($raw, 3);
  } else {
    // Si contiene muchos bytes nulos es probablemente UTF-16LE sin BOM
    $nullCount = substr_count(substr($raw, 0, 512), "\x00");
    if ($nullCount > 20) {
      $raw = mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE');
    }
  }

  // Normalizar saltos de línea
  $raw = str_replace(["\r\n", "\r"], "\n", $raw);
  $lines = explode("\n", $raw);
  if (empty($lines)) return [];

  // Detectar delimitador de la primera línea con contenido
  $firstLine = '';
  foreach ($lines as $l) {
    if (trim($l) !== '') {
      $firstLine = $l;
      break;
    }
  }
  if ($firstLine === '') return [];

  $commaCount = substr_count($firstLine, ',');
  $semiCount  = substr_count($firstLine, ';');
  $tabCount   = substr_count($firstLine, "\t");

  $delimiter = ',';
  if ($semiCount > $commaCount && $semiCount > $tabCount) {
    $delimiter = ';';
  } elseif ($tabCount > $commaCount && $tabCount > $semiCount) {
    $delimiter = "\t";
  }

  $stream = fopen('php://temp', 'r+');
  fwrite($stream, $raw);
  rewind($stream);

  $rows = [];
  while (($data = fgetcsv($stream, 0, $delimiter)) !== false) {
    // Si es una fila vacía de un solo elemento vacío, omitir
    if (count($data) === 1 && trim((string)$data[0]) === '') continue;
    $rows[] = $data;
  }
  fclose($stream);

  return $rows;
}

/**
 * Importa y parsea un CSV de Apple Podcasts o Spotify para un podcast dado.
 *
 * @param int $podcastId ID del podcast en KutPod
 * @param string $platformOverride 'auto', 'apple', 'spotify', 'other'
 * @param string $filePath Ruta al archivo temporal del CSV
 * @param string $originalFilename Nombre original del archivo subido
 * @return array Resumen de la importación
 */
function kp_op3_import_external_csv(int $podcastId, string $platformOverride, string $filePath, string $originalFilename = ''): array {
  kp_op3_ensure_schema();

  $rows = kp_op3_read_csv_rows($filePath);
  if (empty($rows)) {
    throw new RuntimeException('El archivo CSV está vacío o no tiene un formato válido.');
  }

  // Fila de encabezados
  $rawHeaders = array_shift($rows);
  if (empty($rawHeaders)) {
    throw new RuntimeException('No se encontraron encabezados en el archivo CSV.');
  }

  $headers = [];
  $showNameIdx = null;
  foreach ($rawHeaders as $idx => $h) {
    $clean = mb_strtolower(trim((string)$h), 'UTF-8');
    $clean = str_replace(['"', "'", "\xEF\xBB\xBF"], '', $clean);
    $headers[$idx] = $clean;
    if ($showNameIdx === null && ($clean === 'show name' || $clean === 'podcast' || $clean === 'podcast name' || $clean === 'show')) {
      $showNameIdx = $idx;
    }
  }

  // Auto-detección de podcast si no fue especificado
  if ($podcastId <= 0) {
    if ($showNameIdx !== null && isset($rows[0][$showNameIdx])) {
      $sName = trim($rows[0][$showNameIdx]);
      $found = kp_one("SELECT id, title, slug FROM podcasts WHERE LOWER(TRIM(title)) = LOWER(?) LIMIT 1", [$sName]);
      if ($found) {
        $podcastId = (int)$found['id'];
        $podcast = $found;
      }
    }
    if ($podcastId <= 0 && $originalFilename !== '') {
      $allPods = kp_q("SELECT id, title, slug FROM podcasts") ?: [];
      foreach ($allPods as $p) {
        if (!empty($p['slug']) && str_contains(strtolower($originalFilename), strtolower($p['slug']))) {
          $podcastId = (int)$p['id'];
          $podcast = $p;
          break;
        }
      }
    }
  }

  if ($podcastId <= 0) {
    throw new InvalidArgumentException('Debes seleccionar un podcast válido para este reporte.');
  }

  if (!isset($podcast) || !$podcast) {
    $podcast = kp_one("SELECT id, title, slug FROM podcasts WHERE id = ?", [$podcastId]);
    if (!$podcast) {
      throw new InvalidArgumentException('El podcast seleccionado no existe.');
    }
  }

  // Determinar plataforma
  $detected = kp_op3_detect_platform_from_headers($headers);
  $platform = ($platformOverride && $platformOverride !== 'auto') ? $platformOverride : $detected;
  if ($platform === 'other' && $detected !== 'other') {
    $platform = $detected;
  }
  if (!in_array($platform, ['apple', 'spotify', 'other'], true)) {
    $platform = 'apple';
  }

  // Identificar índices de columnas
  $titleIdx = null;
  $guidIdx = null;
  $playsIdx = null;
  $listenersIdx = null;
  $avgPlaybackIdx = null;
  $completionIdx = null;
  $totalTimeIdx = null;
  $durationIdx = null;

  foreach ($headers as $idx => $h) {
    // Título
    if ($titleIdx === null && (
      $h === 'episode title' || $h === 'episode name' || $h === 'title' || $h === 'name' ||
      $h === 'episodio' || $h === 'título' || $h === 'titulo' || str_contains($h, 'episode title')
    )) {
      $titleIdx = $idx;
      continue;
    }
    // GUID
    if ($guidIdx === null && (
      $h === 'episode guid' || $h === 'item guid' || $h === 'guid' || $h === 'episode id' ||
      $h === 'episode uri' || $h === 'spotify uri' || $h === 'uri' || $h === 'id'
    )) {
      $guidIdx = $idx;
      continue;
    }
    // Plays / Streams / Starts
    if ($playsIdx === null && (
      $h === 'plays' || $h === 'starts' || $h === 'streams' || $h === 'reproducciones' ||
      $h === 'inicios' || $h === 'total plays' || str_contains($h, 'plays') || str_contains($h, 'starts')
    )) {
      $playsIdx = $idx;
      continue;
    }
    // Listeners / Engaged Listeners
    if ($listenersIdx === null && (
      $h === 'engaged listeners' || $h === 'listeners' || $h === 'unique listeners' ||
      $h === 'oyentes' || $h === 'oyentes únicos' || $h === 'oyentes interactivos' || str_contains($h, 'listeners')
    )) {
      $listenersIdx = $idx;
      continue;
    }
    // Tiempo medio de escucha
    if ($avgPlaybackIdx === null && (
      $h === 'average listen time' || $h === 'average playback time' || $h === 'avg listen time' ||
      $h === 'tiempo medio de escucha' || $h === 'tiempo promedio' || str_contains($h, 'average listen') ||
      str_contains($h, 'avg listen')
    )) {
      $avgPlaybackIdx = $idx;
      continue;
    }
    // % Consumo / Retención
    if ($completionIdx === null && (
      $h === 'average consumption' || $h === 'median completion percentage' ||
      $h === 'completion percentage' || $h === 'completion rate' || $h === 'consumption' ||
      $h === '% consumption' || $h === 'consumo promedio' || $h === '% retención' ||
      $h === 'retención' || str_contains($h, 'consumption') || str_contains($h, 'completion')
    )) {
      $completionIdx = $idx;
      continue;
    }
    // Tiempo total escuchado
    if ($totalTimeIdx === null && (
      $h === 'total time listened' || $h === 'time listened' || $h === 'total time' ||
      $h === 'tiempo total escuchado' || $h === 'tiempo total' || str_contains($h, 'total time') ||
      str_contains($h, 'consumption time')
    )) {
      $totalTimeIdx = $idx;
      $isTotalHours = str_contains($h, '(hours)');
      continue;
    }
    // Duración
    if ($durationIdx === null && (
      $h === 'duration' || $h === 'duración' || $h === 'duracion' || $h === 'length' ||
      $h === 'episode duration'
    )) {
      $durationIdx = $idx;
      continue;
    }
  }

  // Cargar episodios locales del podcast para mapeo eficiente
  $episodes = kp_q("
    SELECT id, guid, slug, title, duration_secs, audio_url
    FROM episodes
    WHERE podcast_id = ?
  ", [$podcastId]);

  $byGuid = [];
  $byAudio = [];
  $byTitleNorm = [];
  $bySlug = [];

  foreach ($episodes as $ep) {
    if (!empty($ep['guid'])) {
      $byGuid[strtolower(trim($ep['guid']))] = $ep;
    }
    if (!empty($ep['audio_url'])) {
      $byAudio[strtolower(trim($ep['audio_url']))] = $ep;
    }
    if (!empty($ep['slug'])) {
      $bySlug[strtolower(trim($ep['slug']))] = $ep;
    }
    $norm = kp_op3_normalize_title($ep['title']);
    if ($norm !== '') {
      $byTitleNorm[$norm] = $ep;
    }
  }

  $matchedCount = 0;
  $unmatchedCount = 0;
  $updatedEpisodes = [];
  $unmatchedList = [];

  $pdo = kp_db();
  $stmtUpsert = $pdo->prepare("
    INSERT INTO external_platform_stats (
      podcast_id, episode_id, platform, source_file,
      plays, listeners, avg_playback_secs, completion_pct,
      total_time_secs, raw_data, imported_at
    ) VALUES (
      :podcast_id, :episode_id, :platform, :source_file,
      :plays, :listeners, :avg_playback_secs, :completion_pct,
      :total_time_secs, :raw_data, datetime('now')
    )
    ON CONFLICT(episode_id, platform) DO UPDATE SET
      source_file       = excluded.source_file,
      plays             = CASE WHEN excluded.plays > 0 THEN excluded.plays ELSE external_platform_stats.plays END,
      listeners         = CASE WHEN excluded.listeners > 0 THEN excluded.listeners ELSE external_platform_stats.listeners END,
      avg_playback_secs = CASE WHEN excluded.avg_playback_secs > 0 THEN excluded.avg_playback_secs ELSE external_platform_stats.avg_playback_secs END,
      completion_pct    = CASE WHEN excluded.completion_pct > 0.0 THEN excluded.completion_pct ELSE external_platform_stats.completion_pct END,
      total_time_secs   = CASE WHEN excluded.total_time_secs > 0 THEN excluded.total_time_secs ELSE external_platform_stats.total_time_secs END,
      raw_data          = excluded.raw_data,
      imported_at       = datetime('now')
  ");

  foreach ($rows as $row) {
    $rowTitle = ($titleIdx !== null && isset($row[$titleIdx])) ? trim((string)$row[$titleIdx]) : '';
    $rowGuid  = ($guidIdx !== null && isset($row[$guidIdx]))   ? trim((string)$row[$guidIdx])  : '';

    // Si la fila no tiene título ni guid, ignorar
    if ($rowTitle === '' && $rowGuid === '') {
      continue;
    }

    // Intentar emparejar
    $matchedEp = null;

    // 1. Por GUID exacto
    if ($rowGuid !== '') {
      $gLower = strtolower($rowGuid);
      if (isset($byGuid[$gLower])) {
        $matchedEp = $byGuid[$gLower];
      } elseif (isset($bySlug[$gLower])) {
        $matchedEp = $bySlug[$gLower];
      } else {
        // En Spotify a veces el GUID es 'spotify:episode:xxxx'
        foreach ($byGuid as $bgKey => $ep) {
          if (str_contains($gLower, $bgKey) || str_contains($bgKey, $gLower)) {
            $matchedEp = $ep;
            break;
          }
        }
      }
    }

    // 2. Por título normalizado exacto
    if (!$matchedEp && $rowTitle !== '') {
      $tNorm = kp_op3_normalize_title($rowTitle);
      if (isset($byTitleNorm[$tNorm])) {
        $matchedEp = $byTitleNorm[$tNorm];
      } else {
        // 3. Coincidencia difusa: verificar si un título contiene al otro
        foreach ($byTitleNorm as $dbNorm => $ep) {
          if ($dbNorm === '' || $tNorm === '') continue;
          if (str_contains($tNorm, $dbNorm) || str_contains($dbNorm, $tNorm)) {
            $matchedEp = $ep;
            break;
          }
        }
      }
    }

    if (!$matchedEp) {
      $unmatchedCount++;
      $unmatchedList[] = [
        'title' => $rowTitle ?: '—',
        'guid'  => $rowGuid ?: '—'
      ];
      continue;
    }

    // Extraer métricas de la fila
    $epId = (int)$matchedEp['id'];
    $durSecs = (int)($matchedEp['duration_secs'] ?? 0);

    // Si el CSV trae duración y el episodio no la tenía, actualizarla
    if ($durSecs <= 0 && $durationIdx !== null && isset($row[$durationIdx])) {
      $parsedDur = kp_op3_parse_time_to_secs($row[$durationIdx]);
      if ($parsedDur > 0) {
        $durSecs = $parsedDur;
        try {
          $pdo->prepare("UPDATE episodes SET duration_secs = ? WHERE id = ?")->execute([$durSecs, $epId]);
        } catch (Throwable $e) {}
      }
    }

    $plays = ($playsIdx !== null && isset($row[$playsIdx])) ? kp_op3_parse_int_val($row[$playsIdx]) : 0;
    $listeners = ($listenersIdx !== null && isset($row[$listenersIdx])) ? kp_op3_parse_int_val($row[$listenersIdx]) : 0;
    $avgPlaybackSecs = ($avgPlaybackIdx !== null && isset($row[$avgPlaybackIdx])) ? kp_op3_parse_time_to_secs($row[$avgPlaybackIdx]) : 0;
    $completionPct = ($completionIdx !== null && isset($row[$completionIdx])) ? kp_op3_parse_pct_val($row[$completionIdx]) : 0.0;
    
    $totalTimeSecs = 0;
    if ($totalTimeIdx !== null && isset($row[$totalTimeIdx])) {
      $rawVal = trim((string)$row[$totalTimeIdx]);
      if (!empty($isTotalHours) && is_numeric(str_replace(',', '.', $rawVal))) {
        $totalTimeSecs = (int)round((float)str_replace(',', '.', $rawVal) * 3600);
      } else {
        $totalTimeSecs = kp_op3_parse_time_to_secs($rawVal);
      }
    }

    // Relaciones derivadas:
    // Si falta avgPlaybackSecs pero tenemos completionPct y duración:
    if ($avgPlaybackSecs <= 0 && $completionPct > 0.0 && $durSecs > 0) {
      $avgPlaybackSecs = (int)round(($completionPct / 100.0) * $durSecs);
    }
    // Si falta completionPct pero tenemos avgPlaybackSecs y duración:
    if ($completionPct <= 0.0 && $avgPlaybackSecs > 0 && $durSecs > 0) {
      $completionPct = round(($avgPlaybackSecs / $durSecs) * 100.0, 1);
    }
    // Si falta totalTimeSecs pero tenemos avgPlaybackSecs y plays:
    if ($totalTimeSecs <= 0 && $avgPlaybackSecs > 0 && $plays > 0) {
      $totalTimeSecs = (int)($avgPlaybackSecs * $plays);
    }

    // Ejecutar upsert
    $stmtUpsert->execute([
      ':podcast_id'        => $podcastId,
      ':episode_id'        => $epId,
      ':platform'          => $platform,
      ':source_file'       => $originalFilename ?: basename($filePath),
      ':plays'             => $plays,
      ':listeners'         => $listeners,
      ':avg_playback_secs' => $avgPlaybackSecs,
      ':completion_pct'    => $completionPct,
      ':total_time_secs'   => $totalTimeSecs,
      ':raw_data'          => json_encode($row, JSON_UNESCAPED_UNICODE)
    ]);

    $matchedCount++;
    $updatedEpisodes[] = [
      'id'                => $epId,
      'title'             => $matchedEp['title'],
      'platform'          => $platform,
      'plays'             => $plays,
      'listeners'         => $listeners,
      'avg_playback_secs' => $avgPlaybackSecs,
      'avg_playback_fmt'  => $avgPlaybackSecs > 0 ? kp_fmt_duration($avgPlaybackSecs) : '—',
      'completion_pct'    => $completionPct,
      'total_time_fmt'    => $totalTimeSecs > 0 ? kp_fmt_hours_mins($totalTimeSecs) : '—'
    ];
  }

  return [
    'success'           => true,
    'platform'          => $platform,
    'podcast_id'        => $podcastId,
    'podcast_title'     => $podcast['title'],
    'total_rows'        => count($rows),
    'matched'           => $matchedCount,
    'unmatched'         => $unmatchedCount,
    'updated_episodes'  => $updatedEpisodes,
    'unmatched_list'    => array_slice($unmatchedList, 0, 10)
  ];
}
