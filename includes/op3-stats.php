<?php
// ============================================================================
// KutPod · helpers de analytics OP3 · solo datos reales (sin mocks)
// ============================================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/op3-importer.php';
require_once __DIR__ . '/op3-cohorts.php';

function kp_op3_tables_exist(): bool {
  static $exists = null;
  if ($exists !== null) return $exists;
  try { kp_one("SELECT 1 FROM op3_stats_daily LIMIT 1"); return $exists = true; }
  catch (Throwable $e) { return $exists = false; }
}

function kp_op3_podcast_totals(int $podcastId, int $days = 7): array {
  $zero = ['downloads'=>0,'listeners'=>0,'bots'=>0];
  if (!kp_op3_tables_exist()) return $zero;
  $r = kp_one(
    "SELECT COALESCE(sum(downloads),0) downloads,
            COALESCE(sum(unique_listeners),0) listeners,
            COALESCE(sum(bot_downloads),0) bots
     FROM op3_stats_daily WHERE podcast_id = ? AND date >= date('now', ?)",
    [$podcastId, '-' . $days . ' day']
  );
  return $r ?: $zero;
}

function kp_op3_podcast_series(int $podcastId, int $days = 30): array {
  if (!kp_op3_tables_exist()) return [];
  return kp_q(
    "SELECT date, sum(downloads) downloads FROM op3_stats_daily
     WHERE podcast_id = ? AND date >= date('now', ?) GROUP BY date ORDER BY date",
    [$podcastId, '-' . $days . ' day']
  );
}

function kp_op3_all_podcasts_series(int $days = 30): array {
  if (!kp_op3_tables_exist()) return [];
  return kp_q(
    "SELECT podcast_id, date, sum(downloads) downloads FROM op3_stats_daily
     WHERE date >= date('now', ?) GROUP BY podcast_id, date ORDER BY date",
    ['-' . $days . ' day']
  );
}

function kp_op3_top_apps(int $podcastId, int $days = 30, int $limit = 8): array {
  if (!kp_op3_tables_exist()) return [];
  $rows = kp_q(
    "SELECT a.app_name, sum(a.downloads) as downloads FROM op3_apps_daily a
     JOIN episodes e ON e.id = a.episode_id
     WHERE e.podcast_id = ? AND a.date >= date('now', ?)
     GROUP BY a.app_name ORDER BY downloads DESC LIMIT ?",
    [$podcastId, '-' . $days . ' day', $limit]
  );
  $total = array_sum(array_column($rows, 'downloads'));
  foreach ($rows as &$r) $r['pct'] = $total ? round(100 * $r['downloads'] / $total, 1) : 0;
  return $rows;
}

function kp_op3_top_countries(int $podcastId, int $days = 30, int $limit = 10): array {
  if (!kp_op3_tables_exist()) return [];
  return kp_q(
    "SELECT g.country, sum(g.downloads) as downloads FROM op3_geo_daily g
     JOIN episodes e ON e.id = g.episode_id
     WHERE e.podcast_id = ? AND g.date >= date('now', ?) AND g.country != ''
     GROUP BY g.country ORDER BY downloads DESC LIMIT ?",
    [$podcastId, '-' . $days . ' day', $limit]
  );
}

function kp_op3_top_metros(int $podcastId, int $days = 30, int $limit = 10): array {
  if (!kp_op3_tables_exist()) return [];
  $rows = kp_q(
    "SELECT metro_code, count(*) as downloads FROM op3_downloads
     WHERE podcast_id = ? AND date >= date('now', ?) AND country = 'US' AND metro_code != '' AND bot_type IS NULL
     GROUP BY metro_code ORDER BY downloads DESC LIMIT ?",
    [$podcastId, '-' . $days . ' day', $limit]
  );
  $total = array_sum(array_column($rows, 'downloads'));
  foreach ($rows as &$r) $r['pct'] = $total ? round(100 * $r['downloads'] / $total, 1) : 0;
  return $rows;
}

function kp_op3_top_regions(int $podcastId, string $continent, int $days = 30, int $limit = 10): array {
  if (!kp_op3_tables_exist()) return [];
  if ($continent === 'SA') {
    $rows = kp_q(
      "SELECT region_name, count(*) as downloads FROM op3_downloads
       WHERE podcast_id = ? AND date >= date('now', ?) AND (continent = 'SA' OR country IN ('MX','GT','BZ','SV','HN','NI','CR','PA','CU','DO','PR')) AND region_name != '' AND bot_type IS NULL
       GROUP BY region_name ORDER BY downloads DESC LIMIT ?",
      [$podcastId, '-' . $days . ' day', $limit]
    );
  } else {
    $rows = kp_q(
      "SELECT region_name, count(*) as downloads FROM op3_downloads
       WHERE podcast_id = ? AND date >= date('now', ?) AND continent = ? AND region_name != '' AND bot_type IS NULL
       GROUP BY region_name ORDER BY downloads DESC LIMIT ?",
      [$podcastId, '-' . $days . ' day', $continent, $limit]
    );
  }
  $total = array_sum(array_column($rows, 'downloads'));
  foreach ($rows as &$r) $r['pct'] = $total ? round(100 * $r['downloads'] / $total, 1) : 0;
  return $rows;
}

function kp_op3_top_browsers(int $podcastId, int $days = 30, int $limit = 10): array {
  if (!kp_op3_tables_exist()) return [];
  $rows = kp_q(
    "SELECT agent_name as name, count(*) as downloads FROM op3_downloads
     WHERE podcast_id = ? AND date >= date('now', ?) AND agent_type = 'browser' AND bot_type IS NULL
     GROUP BY name ORDER BY downloads DESC LIMIT ?",
    [$podcastId, '-' . $days . ' day', $limit]
  );
  $total = array_sum(array_column($rows, 'downloads'));
  foreach ($rows as &$r) $r['pct'] = $total ? round(100 * $r['downloads'] / $total, 1) : 0;
  return $rows;
}

function kp_op3_top_devices(int $podcastId, int $days = 30, int $limit = 10): array {
  if (!kp_op3_tables_exist()) return [];
  $rows = kp_q(
    "SELECT device_name as name, count(*) as downloads FROM op3_downloads
     WHERE podcast_id = ? AND date >= date('now', ?) AND device_name != '' AND bot_type IS NULL
     GROUP BY name ORDER BY downloads DESC LIMIT ?",
    [$podcastId, '-' . $days . ' day', $limit]
  );
  $total = array_sum(array_column($rows, 'downloads'));
  foreach ($rows as &$r) $r['pct'] = $total ? round(100 * $r['downloads'] / $total, 1) : 0;
  return $rows;
}

function kp_op3_last_sync(): ?array {
  try {
    $r = kp_one("SELECT v, updated_at FROM settings WHERE k = 'op3_last_sync'");
    return $r ? array_merge(json_decode($r['v'], true) ?: [], ['updated_at' => $r['updated_at']]) : null;
  } catch (Throwable $e) { return null; }
}

function kp_op3_rules_count(): int {
  try { return (int) kp_one("SELECT count(*) c FROM op3_botip_rules")['c']; }
  catch (Throwable $e) { return 0; }
}

function kp_op3_most_downloaded_episode(int $podcastId): ?array {
  if (!kp_op3_tables_exist()) return null;
  $row = kp_one(
    "SELECT e.id AS db_id, e.slug AS id, e.title AS title, p.slug AS podcast,
            COALESCE(SUM(s.downloads), 0) AS downloads,
            COALESCE(e.season, 1) AS s, COALESCE(e.number, 0) AS n, e.status AS status,
            COALESCE(e.published_at, e.publish_at, e.created_at) AS date_iso,
            e.cover AS cover, e.duration_secs AS duration_secs, e.guest AS guest
     FROM op3_stats_daily s
     JOIN episodes e ON e.id = s.episode_id
     JOIN podcasts p ON p.id = e.podcast_id
     WHERE s.podcast_id = ? AND e.hidden = 0
     GROUP BY s.episode_id
     ORDER BY downloads DESC
     LIMIT 1",
    [$podcastId]
  );
  if ($row) {
    $row['duration'] = kp_fmt_duration((int)($row['duration_secs'] ?? 0));
    $row['date']     = kp_fmt_relative_date($row['date_iso'] ?? '');
    $pb = kp_op3_episode_playback_stats((int)$row['db_id'], 30);
    $row['avg_playback_fmt'] = $pb['avg_playback_fmt'];
    $row['completion_pct']   = $pb['completion_pct'];
    $row['total_time_fmt']   = $pb['total_time_fmt'];
  }
  return $row ?: null;
}

function kp_op3_most_downloaded_episodes_by_podcast(): array {
  if (!kp_op3_tables_exist()) return [];
  $rows = kp_q(
    "WITH ranked AS (
      SELECT s.podcast_id, e.id AS db_id, e.slug AS id, e.title AS title, p.slug AS podcast,
             COALESCE(SUM(s.downloads), 0) AS downloads,
             COALESCE(e.season, 1) AS s, COALESCE(e.number, 0) AS n, e.status AS status,
             COALESCE(e.published_at, e.publish_at, e.created_at) AS date_iso,
             e.cover AS cover, e.duration_secs AS duration_secs, e.guest AS guest,
             ROW_NUMBER() OVER (PARTITION BY s.podcast_id ORDER BY SUM(s.downloads) DESC) as rn
      FROM op3_stats_daily s
      JOIN episodes e ON e.id = s.episode_id
      JOIN podcasts p ON p.id = e.podcast_id
      WHERE e.hidden = 0
      GROUP BY s.podcast_id, s.episode_id
    )
    SELECT * FROM ranked WHERE rn = 1"
  );
  $by_podcast = [];
  foreach ($rows as $row) {
    $row['duration'] = kp_fmt_duration((int)($row['duration_secs'] ?? 0));
    $row['date']     = kp_fmt_relative_date($row['date_iso'] ?? '');
    $pb = kp_op3_episode_playback_stats((int)$row['db_id'], 30);
    $row['avg_playback_fmt'] = $pb['avg_playback_fmt'];
    $row['completion_pct']   = $pb['completion_pct'];
    $row['total_time_fmt']   = $pb['total_time_fmt'];
    $by_podcast[$row['podcast_id']] = $row;
  }
  return $by_podcast;
}

function kp_op3_top_episodes(int $limit = 5): array {
  if (!kp_op3_tables_exist()) return [];
  $rows = kp_q(
    "SELECT e.id AS db_id, e.slug AS id, e.title AS title, p.slug AS podcast, p.title AS podcast_title,
            COALESCE(SUM(s.downloads), 0) AS downloads,
            COALESCE(e.season, 1) AS s, COALESCE(e.number, 0) AS n, e.status AS status,
            COALESCE(e.published_at, e.publish_at, e.created_at) AS date_iso,
            e.cover AS cover, e.duration_secs AS duration_secs, e.guest AS guest
     FROM op3_stats_daily s
     JOIN episodes e ON e.id = s.episode_id
     JOIN podcasts p ON p.id = e.podcast_id
     WHERE e.hidden = 0
     GROUP BY s.episode_id
     ORDER BY downloads DESC
     LIMIT ?",
    [$limit]
  );
  foreach ($rows as &$row) {
    $row['duration'] = kp_fmt_duration((int)($row['duration_secs'] ?? 0));
    $row['date']     = kp_fmt_relative_date($row['date_iso'] ?? '');
    $pb = kp_op3_episode_playback_stats((int)$row['db_id'], 30);
    $row['avg_playback_fmt'] = $pb['avg_playback_fmt'];
    $row['completion_pct']   = $pb['completion_pct'];
    $row['total_time_fmt']   = $pb['total_time_fmt'];
  }
  return $rows ?: [];
}

/**
 * Formatea segundos en un formato amigable de horas y minutos para patrocinadores (ej. "142h 30m").
 */
function kp_fmt_hours_mins(int $secs): string {
  if ($secs <= 0) return '0m';
  if ($secs < 3600) {
    return max(1, (int)round($secs / 60)) . 'm';
  }
  $h = floor($secs / 3600);
  $m = (int)round(($secs % 3600) / 60);
  if ($m === 0) return "{$h}h";
  return "{$h}h {$m}m";
}

/**
 * Calcula las métricas de reproducción, retención y tiempo total consumido de un episodio.
 */
function kp_op3_episode_playback_stats(int $episodeId, int $days = 30): array {
  $zero = [
    'duration_secs'          => 0,
    'duration_fmt'           => '—',
    'downloads'              => 0,
    'unique_listeners'       => 0,
    'bot_downloads'          => 0,
    'plays'                  => 0,
    'completed'              => 0,
    'avg_playback_secs'      => 0,
    'avg_playback_fmt'       => '—',
    'completion_pct'         => 0.0,
    'total_time_secs'        => 0,
    'total_time_fmt'         => '—',
    'apple_completion_pct'   => null,
    'apple_avg_secs'         => null,
    'apple_plays'            => null,
    'spotify_completion_pct' => null,
    'spotify_avg_secs'       => null,
    'spotify_plays'          => null,
    'has_external_stats'     => false,
    'external_stats'         => []
  ];
  if ($episodeId <= 0) return $zero;

  $ep = kp_one("SELECT duration_secs, title, season, number, published_at, created_at FROM episodes WHERE id = ?", [$episodeId]);
  if (!$ep) return $zero;
  $dur = (int)($ep['duration_secs'] ?? 0);

  $dl = 0; $ul = 0; $bd = 0;
  if (kp_op3_tables_exist()) {
    $stats = kp_one("
      SELECT COALESCE(SUM(downloads), 0) as dl,
             COALESCE(SUM(unique_listeners), 0) as ul,
             COALESCE(SUM(bot_downloads), 0) as bd
      FROM op3_stats_daily
      WHERE episode_id = ? AND date >= date('now', ?)
    ", [$episodeId, '-' . $days . ' day']);
    if ($stats) {
      $dl = (int)$stats['dl'];
      $ul = (int)$stats['ul'];
      $bd = (int)$stats['bd'];
    }
  }

  // Telemetría web medida (sesiones web y embed)
  $plays = 0; $completed = 0; $webSecs = 0;
  try {
    $pb = kp_one("
      SELECT COALESCE(SUM(play_count), 0) as plays,
             COALESCE(SUM(completed_count), 0) as completed,
             COALESCE(SUM(total_secs), 0) as secs
      FROM op3_playback_daily
      WHERE episode_id = ? AND date >= date('now', ?)
    ", [$episodeId, '-' . $days . ' day']);
    if ($pb) {
      $plays = (int)$pb['plays'];
      $completed = (int)$pb['completed'];
      $webSecs = (int)$pb['secs'];
    }
  } catch (Throwable $e) {}

  // Métricas de plataformas externas importadas (solo si el plugin external_metrics está activo)
  $appleStats = null;
  $spotifyStats = null;
  $extRows = [];
  if (function_exists('kp_plugin_is_active') && kp_plugin_is_active('external_metrics')) {
    try {
      $extRows = kp_q("
        SELECT platform, plays, listeners, avg_playback_secs, completion_pct, total_time_secs, imported_at
        FROM external_platform_stats
        WHERE episode_id = ?
      ", [$episodeId]);
      if ($extRows) {
        foreach ($extRows as $er) {
          if ($er['platform'] === 'apple')   $appleStats = $er;
          if ($er['platform'] === 'spotify') $spotifyStats = $er;
        }
      }
    } catch (Throwable $e) {}
  }

  // Fuentes con retención medida
  $sources = [];

  // 1. Telemetría web local
  if ($plays > 0 && $dur > 0 && $webSecs > 0) {
    $webAvg = (int)round($webSecs / $plays);
    $webRate = min(1.0, max(0.05, $webAvg / $dur));
    $sources[] = [
      'weight'     => max(1, $plays),
      'pct'        => round($webRate * 100, 1),
      'secs'       => (int)round($dur * $webRate),
      'total_time' => $webSecs
    ];
  }

  // 2. Apple Podcasts Connect
  if ($appleStats && ($appleStats['completion_pct'] > 0 || $appleStats['avg_playback_secs'] > 0)) {
    $aPct = (float)$appleStats['completion_pct'];
    $aSecs = (int)$appleStats['avg_playback_secs'];
    if ($aSecs <= 0 && $aPct > 0 && $dur > 0) {
      $aSecs = (int)round(($aPct / 100) * $dur);
    } elseif ($aPct <= 0 && $aSecs > 0 && $dur > 0) {
      $aPct = round(($aSecs / $dur) * 100, 1);
    }
    $sources[] = [
      'weight'     => max(1, (int)$appleStats['listeners'] ?: (int)$appleStats['plays']),
      'pct'        => $aPct,
      'secs'       => $aSecs,
      'total_time' => (int)$appleStats['total_time_secs']
    ];
  }

  // 3. Spotify for Creators
  if ($spotifyStats && ($spotifyStats['completion_pct'] > 0 || $spotifyStats['avg_playback_secs'] > 0)) {
    $sPct = (float)$spotifyStats['completion_pct'];
    $sSecs = (int)$spotifyStats['avg_playback_secs'];
    if ($sSecs <= 0 && $sPct > 0 && $dur > 0) {
      $sSecs = (int)round(($sPct / 100) * $dur);
    } elseif ($sPct <= 0 && $sSecs > 0 && $dur > 0) {
      $sPct = round(($sSecs / $dur) * 100, 1);
    }
    $sources[] = [
      'weight'     => max(1, (int)$spotifyStats['listeners'] ?: (int)$spotifyStats['plays']),
      'pct'        => $sPct,
      'secs'       => $sSecs,
      'total_time' => (int)$spotifyStats['total_time_secs']
    ];
  }

  // Cálculo consolidado de retención y tiempo total consumido
  if (!empty($sources)) {
    $sumWeight = 0;
    $weightedPct = 0;
    $weightedSecs = 0;
    $sumTotalTime = 0;
    foreach ($sources as $s) {
      $w = max(1, $s['weight']);
      $sumWeight += $w;
      $weightedPct += ($s['pct'] * $w);
      $weightedSecs += ($s['secs'] * $w);
      $sumTotalTime += $s['total_time'];
    }
    $completionPct = round($weightedPct / max(1, $sumWeight), 1);
    $avgPlaybackSecs = (int)round($weightedSecs / max(1, $sumWeight));
    $audienceCount = max($dl, $sumWeight);
    $totalTimeSecs = $audienceCount > 0 ? (int)round($audienceCount * $avgPlaybackSecs) : $sumTotalTime;
  } else {
    $completionPct = 0.0;
    $avgPlaybackSecs = 0;
    $totalTimeSecs = 0;
  }

  return [
    'duration_secs'          => $dur,
    'duration_fmt'           => kp_fmt_duration($dur),
    'downloads'              => $dl,
    'unique_listeners'       => $ul,
    'bot_downloads'          => $bd,
    'plays'                  => $plays,
    'completed'              => $completed,
    'avg_playback_secs'      => $avgPlaybackSecs,
    'avg_playback_fmt'       => $avgPlaybackSecs > 0 ? kp_fmt_duration($avgPlaybackSecs) : '—',
    'completion_pct'         => $completionPct,
    'total_time_secs'        => $totalTimeSecs,
    'total_time_fmt'         => $totalTimeSecs > 0 ? kp_fmt_hours_mins($totalTimeSecs) : '—',
    'apple_completion_pct'   => $appleStats ? (float)$appleStats['completion_pct'] : null,
    'apple_avg_secs'         => $appleStats ? (int)$appleStats['avg_playback_secs'] : null,
    'apple_plays'            => $appleStats ? (int)$appleStats['plays'] : null,
    'spotify_completion_pct' => $spotifyStats ? (float)$spotifyStats['completion_pct'] : null,
    'spotify_avg_secs'       => $spotifyStats ? (int)$spotifyStats['avg_playback_secs'] : null,
    'spotify_plays'          => $spotifyStats ? (int)$spotifyStats['plays'] : null,
    'has_external_stats'     => (!empty($appleStats) || !empty($spotifyStats)),
    'external_stats'         => $extRows
  ];
}

/**
 * Calcula las métricas agregadas de reproducción, retención y tiempo total consumido de un podcast.
 */
function kp_op3_podcast_playback_stats(int $podcastId, int $days = 30): array {
  $zero = [
    'podcast_id'                 => $podcastId,
    'downloads'                  => 0,
    'unique_listeners'           => 0,
    'bot_downloads'              => 0,
    'episodes_count'             => 0,
    'avg_duration_secs'          => 0,
    'avg_duration_fmt'           => '—',
    'avg_playback_secs'          => 0,
    'avg_playback_fmt'           => '—',
    'avg_completion_pct'         => 0.0,
    'apple_avg_completion_pct'   => 0.0,
    'spotify_avg_completion_pct' => 0.0,
    'total_time_secs'            => 0,
    'total_time_fmt'             => '—'
  ];
  if ($podcastId <= 0) return $zero;

  $eps = kp_q("
    SELECT id, duration_secs 
    FROM episodes 
    WHERE podcast_id = ? AND hidden = 0
    ORDER BY COALESCE(published_at, created_at) DESC
  ", [$podcastId]);
  if (!$eps) return $zero;

  $totalDl = 0; $totalUl = 0; $totalBd = 0;
  $sumDuration = 0; $validDurCount = 0;
  $totalSecsAccum = 0;
  $weightedPlaybackSum = 0;
  $weightedPctSum = 0;
  $appleWeightedPctSum = 0; $appleWeightSum = 0;
  $spotifyWeightedPctSum = 0; $spotifyWeightSum = 0;

  foreach ($eps as $ep) {
    $epStat = kp_op3_episode_playback_stats((int)$ep['id'], $days);
    $totalDl += $epStat['downloads'];
    $totalUl += $epStat['unique_listeners'];
    $totalBd += $epStat['bot_downloads'];
    $totalSecsAccum += $epStat['total_time_secs'];

    if ($epStat['duration_secs'] > 0) {
      $sumDuration += $epStat['duration_secs'];
      $validDurCount++;
    }

    if ($epStat['avg_playback_secs'] > 0) {
      $weight = max(1, $epStat['downloads']);
      $weightedPlaybackSum += ($epStat['avg_playback_secs'] * $weight);
      $weightedPctSum += ($epStat['completion_pct'] * $weight);
    }

    if (isset($epStat['apple_completion_pct']) && $epStat['apple_completion_pct'] > 0) {
      $aWeight = max(1, (int)($epStat['apple_plays'] ?? $epStat['downloads']));
      $appleWeightedPctSum += ($epStat['apple_completion_pct'] * $aWeight);
      $appleWeightSum += $aWeight;
    }

    if (isset($epStat['spotify_completion_pct']) && $epStat['spotify_completion_pct'] > 0) {
      $sWeight = max(1, (int)($epStat['spotify_plays'] ?? $epStat['downloads']));
      $spotifyWeightedPctSum += ($epStat['spotify_completion_pct'] * $sWeight);
      $spotifyWeightSum += $sWeight;
    }
  }

  $epCount = count($eps);
  $avgDurationSecs = $validDurCount > 0 ? (int)round($sumDuration / $validDurCount) : 0;
  $hasMeasuredPlays = ($totalSecsAccum > 0 && $weightedPlaybackSum > 0);
  $avgPlaybackSecs = $hasMeasuredPlays ? (int)round($weightedPlaybackSum / max(1, $totalDl)) : 0;
  $avgCompletionPct = $hasMeasuredPlays ? round($weightedPctSum / max(1, $totalDl), 1) : 0.0;
  $appleAvgCompletionPct = $appleWeightSum > 0 ? round($appleWeightedPctSum / $appleWeightSum, 1) : 0.0;
  $spotifyAvgCompletionPct = $spotifyWeightSum > 0 ? round($spotifyWeightedPctSum / $spotifyWeightSum, 1) : 0.0;

  return [
    'podcast_id'                 => $podcastId,
    'downloads'                  => $totalDl,
    'unique_listeners'           => $totalUl,
    'bot_downloads'              => $totalBd,
    'episodes_count'             => $epCount,
    'avg_duration_secs'          => $avgDurationSecs,
    'avg_duration_fmt'           => $avgDurationSecs > 0 ? kp_fmt_duration($avgDurationSecs) : '—',
    'avg_playback_secs'          => $avgPlaybackSecs,
    'avg_playback_fmt'           => $avgPlaybackSecs > 0 ? kp_fmt_duration($avgPlaybackSecs) : '—',
    'avg_completion_pct'         => $avgCompletionPct,
    'apple_avg_completion_pct'   => $appleAvgCompletionPct,
    'spotify_avg_completion_pct' => $spotifyAvgCompletionPct,
    'total_time_secs'            => $totalSecsAccum,
    'total_time_fmt'             => $totalSecsAccum > 0 ? kp_fmt_hours_mins($totalSecsAccum) : '—'
  ];
}

/**
 * Calcula las métricas globales consolidadas de reproducción de toda la red de podcasts.
 */
function kp_op3_all_podcasts_playback_totals(int $days = 30): array {
  $zero = [
    'downloads'                  => 0,
    'unique_listeners'           => 0,
    'bot_downloads'              => 0,
    'avg_playback_secs'          => 0,
    'avg_playback_fmt'           => '—',
    'avg_completion_pct'         => 0.0,
    'apple_avg_completion_pct'   => 0.0,
    'spotify_avg_completion_pct' => 0.0,
    'total_time_secs'            => 0,
    'total_time_fmt'             => '—'
  ];

  $dateArg = '-' . $days . ' day';
  $hasOp3 = kp_op3_tables_exist();

  $op3Sql = $hasOp3
    ? "LEFT JOIN (
         SELECT episode_id,
                SUM(downloads) AS dl,
                SUM(unique_listeners) AS ul,
                SUM(bot_downloads) AS bd
         FROM op3_stats_daily
         WHERE date >= date('now', ?)
         GROUP BY episode_id
       ) s ON s.episode_id = e.id"
    : "";
  $op3Cols = $hasOp3
    ? "COALESCE(s.dl, 0) AS dl, COALESCE(s.ul, 0) AS ul, COALESCE(s.bd, 0) AS bd"
    : "0 AS dl, 0 AS ul, 0 AS bd";
  $params = $hasOp3 ? [$dateArg, $dateArg] : [$dateArg];

  $episodes = kp_q("
    SELECT e.id AS episode_id,
           e.podcast_id,
           COALESCE(e.duration_secs, 0) AS duration_secs,
           $op3Cols,
           COALESCE(pb.plays, 0) AS plays,
           COALESCE(pb.secs, 0) AS web_secs
    FROM episodes e
    JOIN podcasts p ON p.id = e.podcast_id
    $op3Sql
    LEFT JOIN (
      SELECT episode_id,
             SUM(play_count) AS plays,
             SUM(total_secs) AS secs
      FROM op3_playback_daily
      WHERE date >= date('now', ?)
      GROUP BY episode_id
    ) pb ON pb.episode_id = e.id
    WHERE p.hidden = 0 AND e.hidden = 0
  ", $params);

  if (!$episodes) return $zero;

  $extByEp = [];
  try {
    $extRows = kp_q("
      SELECT eps.episode_id, eps.platform, eps.plays, eps.listeners, eps.avg_playback_secs, eps.completion_pct, eps.total_time_secs
      FROM external_platform_stats eps
      JOIN episodes e ON e.id = eps.episode_id
      JOIN podcasts p ON p.id = e.podcast_id
      WHERE p.hidden = 0 AND e.hidden = 0 AND eps.platform IN ('apple', 'spotify')
    ");
    foreach ($extRows as $er) {
      $extByEp[(int)$er['episode_id']][$er['platform']] = $er;
    }
  } catch (Throwable $e) {}

  $byPodcast = [];
  foreach ($episodes as $ep) {
    $epId = (int)$ep['episode_id'];
    $podId = (int)$ep['podcast_id'];
    $dur = (int)$ep['duration_secs'];
    $dl = (int)$ep['dl'];
    $ul = (int)$ep['ul'];
    $bd = (int)$ep['bd'];
    $plays = (int)$ep['plays'];
    $webSecs = (int)$ep['web_secs'];

    $appleStats = $extByEp[$epId]['apple'] ?? null;
    $spotifyStats = $extByEp[$epId]['spotify'] ?? null;

    $sources = [];
    if ($plays > 0 && $dur > 0 && $webSecs > 0) {
      $webAvg = (int)round($webSecs / $plays);
      $webRate = min(1.0, max(0.05, $webAvg / $dur));
      $sources[] = [
        'weight'     => max(1, $plays),
        'pct'        => round($webRate * 100, 1),
        'secs'       => (int)round($dur * $webRate),
        'total_time' => $webSecs
      ];
    }

    if ($appleStats && ($appleStats['completion_pct'] > 0 || $appleStats['avg_playback_secs'] > 0)) {
      $aPct = (float)$appleStats['completion_pct'];
      $aSecs = (int)$appleStats['avg_playback_secs'];
      if ($aSecs <= 0 && $aPct > 0 && $dur > 0) $aSecs = (int)round(($aPct / 100) * $dur);
      elseif ($aPct <= 0 && $aSecs > 0 && $dur > 0) $aPct = round(($aSecs / $dur) * 100, 1);
      $sources[] = [
        'weight'     => max(1, (int)$appleStats['listeners'] ?: (int)$appleStats['plays']),
        'pct'        => $aPct,
        'secs'       => $aSecs,
        'total_time' => (int)$appleStats['total_time_secs']
      ];
    }

    if ($spotifyStats && ($spotifyStats['completion_pct'] > 0 || $spotifyStats['avg_playback_secs'] > 0)) {
      $sPct = (float)$spotifyStats['completion_pct'];
      $sSecs = (int)$spotifyStats['avg_playback_secs'];
      if ($sSecs <= 0 && $sPct > 0 && $dur > 0) $sSecs = (int)round(($sPct / 100) * $dur);
      elseif ($sPct <= 0 && $sSecs > 0 && $dur > 0) $sPct = round(($sSecs / $dur) * 100, 1);
      $sources[] = [
        'weight'     => max(1, (int)$spotifyStats['listeners'] ?: (int)$spotifyStats['plays']),
        'pct'        => $sPct,
        'secs'       => $sSecs,
        'total_time' => (int)$spotifyStats['total_time_secs']
      ];
    }

    $completionPct = 0.0;
    $avgPlaybackSecs = 0;
    $epTotalTimeSecs = 0;
    if (!empty($sources)) {
      $sumWeight = 0; $weightedPct = 0; $weightedSecs = 0; $sumTotalTime = 0;
      foreach ($sources as $src) {
        $w = max(1, $src['weight']);
        $sumWeight += $w;
        $weightedPct += ($src['pct'] * $w);
        $weightedSecs += ($src['secs'] * $w);
        $sumTotalTime += $src['total_time'];
      }
      $completionPct = round($weightedPct / max(1, $sumWeight), 1);
      $avgPlaybackSecs = (int)round($weightedSecs / max(1, $sumWeight));
      $audienceCount = max($dl, $sumWeight);
      $epTotalTimeSecs = $audienceCount > 0 ? (int)round($audienceCount * $avgPlaybackSecs) : $sumTotalTime;
    }

    if (!isset($byPodcast[$podId])) {
      $byPodcast[$podId] = [
        'dl' => 0, 'ul' => 0, 'bd' => 0, 'total_secs' => 0,
        'w_pb' => 0, 'w_pct' => 0,
        'a_w_pct' => 0, 'a_w' => 0,
        's_w_pct' => 0, 's_w' => 0,
      ];
    }
    $ps = &$byPodcast[$podId];
    $ps['dl'] += $dl;
    $ps['ul'] += $ul;
    $ps['bd'] += $bd;
    $ps['total_secs'] += $epTotalTimeSecs;

    if ($avgPlaybackSecs > 0) {
      $w = max(1, $dl);
      $ps['w_pb'] += ($avgPlaybackSecs * $w);
      $ps['w_pct'] += ($completionPct * $w);
    }
    if ($appleStats && (float)$appleStats['completion_pct'] > 0) {
      $aw = max(1, (int)$appleStats['plays'] ?: $dl);
      $ps['a_w_pct'] += ((float)$appleStats['completion_pct'] * $aw);
      $ps['a_w'] += $aw;
    }
    if ($spotifyStats && (float)$spotifyStats['completion_pct'] > 0) {
      $sw = max(1, (int)$spotifyStats['plays'] ?: $dl);
      $ps['s_w_pct'] += ((float)$spotifyStats['completion_pct'] * $sw);
      $ps['s_w'] += $sw;
    }
    unset($ps);
  }

  $totalDl = 0; $totalUl = 0; $totalBd = 0;
  $totalTimeSecs = 0;
  $weightedPlaybackSum = 0;
  $weightedPctSum = 0;
  $appleWeightedSum = 0; $appleWeightSum = 0;
  $spotifyWeightedSum = 0; $spotifyWeightSum = 0;

  foreach ($byPodcast as $ps) {
    $totalDl += $ps['dl'];
    $totalUl += $ps['ul'];
    $totalBd += $ps['bd'];
    $totalTimeSecs += $ps['total_secs'];

    $hasMeasured = ($ps['total_secs'] > 0 && $ps['w_pb'] > 0);
    $podAvgPb = $hasMeasured ? (int)round($ps['w_pb'] / max(1, $ps['dl'])) : 0;
    $podAvgPct = $hasMeasured ? round($ps['w_pct'] / max(1, $ps['dl']), 1) : 0.0;
    $podApplePct = $ps['a_w'] > 0 ? round($ps['a_w_pct'] / $ps['a_w'], 1) : 0.0;
    $podSpotifyPct = $ps['s_w'] > 0 ? round($ps['s_w_pct'] / $ps['s_w'], 1) : 0.0;

    if ($podAvgPb > 0) {
      $w = max(1, $ps['dl']);
      $weightedPlaybackSum += ($podAvgPb * $w);
      $weightedPctSum += ($podAvgPct * $w);
    }
    if ($podApplePct > 0) {
      $w = max(1, $ps['dl']);
      $appleWeightedSum += ($podApplePct * $w);
      $appleWeightSum += $w;
    }
    if ($podSpotifyPct > 0) {
      $w = max(1, $ps['dl']);
      $spotifyWeightedSum += ($podSpotifyPct * $w);
      $spotifyWeightSum += $w;
    }
  }

  $hasMeasuredPlays = ($totalTimeSecs > 0 && $weightedPlaybackSum > 0);
  $avgPlaybackSecs = $hasMeasuredPlays ? (int)round($weightedPlaybackSum / max(1, $totalDl)) : 0;
  $avgCompletionPct = $hasMeasuredPlays ? round($weightedPctSum / max(1, $totalDl), 1) : 0.0;
  $appleAvgCompletionPct = $appleWeightSum > 0 ? round($appleWeightedSum / $appleWeightSum, 1) : 0.0;
  $spotifyAvgCompletionPct = $spotifyWeightSum > 0 ? round($spotifyWeightedSum / $spotifyWeightSum, 1) : 0.0;

  return [
    'downloads'                  => $totalDl,
    'unique_listeners'           => $totalUl,
    'bot_downloads'              => $totalBd,
    'avg_playback_secs'          => $avgPlaybackSecs,
    'avg_playback_fmt'           => $avgPlaybackSecs > 0 ? kp_fmt_duration($avgPlaybackSecs) : '—',
    'avg_completion_pct'         => $avgCompletionPct,
    'apple_avg_completion_pct'   => $appleAvgCompletionPct,
    'spotify_avg_completion_pct' => $spotifyAvgCompletionPct,
    'total_time_secs'            => $totalTimeSecs,
    'total_time_fmt'             => $totalTimeSecs > 0 ? kp_fmt_hours_mins($totalTimeSecs) : '—'
  ];
}

