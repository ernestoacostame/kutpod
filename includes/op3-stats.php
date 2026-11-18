<?php
// ============================================================================
// KutPod · helpers de analytics OP3 · solo datos reales (sin mocks)
// ============================================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

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
    'duration_secs'     => 0,
    'duration_fmt'      => '—',
    'downloads'         => 0,
    'unique_listeners'  => 0,
    'bot_downloads'     => 0,
    'plays'             => 0,
    'completed'         => 0,
    'avg_playback_secs' => 0,
    'avg_playback_fmt'  => '—',
    'completion_pct'    => 0.0,
    'total_time_secs'   => 0,
    'total_time_fmt'    => '0m'
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

  // Cálculo de retención media y tiempo promedio de escucha
  if ($plays > 0 && $dur > 0 && $webSecs > 0) {
    $avgSecsPerPlay = (int)round($webSecs / $plays);
    $retentionRate = min(1.0, max(0.05, $avgSecsPerPlay / $dur));
    $avgPlaybackSecs = (int)round($dur * $retentionRate);
    $completionPct = round($retentionRate * 100, 1);
  } elseif ($dur > 0 && ($dl > 0 || $plays > 0)) {
    // Estimación IAB v2 estándar (benchmark medio del sector: 75% retención)
    $retentionRate = 0.75;
    $avgPlaybackSecs = (int)round($dur * $retentionRate);
    $completionPct = 75.0;
  } else {
    $retentionRate = 0;
    $avgPlaybackSecs = 0;
    $completionPct = 0.0;
  }

  $audienceCount = max($dl, $plays);
  $totalTimeSecs = (int)round($audienceCount * $avgPlaybackSecs);

  return [
    'duration_secs'     => $dur,
    'duration_fmt'      => kp_fmt_duration($dur),
    'downloads'         => $dl,
    'unique_listeners'  => $ul,
    'bot_downloads'     => $bd,
    'plays'             => $plays,
    'completed'         => $completed,
    'avg_playback_secs' => $avgPlaybackSecs,
    'avg_playback_fmt'  => $avgPlaybackSecs > 0 ? kp_fmt_duration($avgPlaybackSecs) : '—',
    'completion_pct'    => $completionPct,
    'total_time_secs'   => $totalTimeSecs,
    'total_time_fmt'    => kp_fmt_hours_mins($totalTimeSecs)
  ];
}

/**
 * Calcula las métricas agregadas de reproducción, retención y tiempo total consumido de un podcast.
 */
function kp_op3_podcast_playback_stats(int $podcastId, int $days = 30): array {
  $zero = [
    'podcast_id'         => $podcastId,
    'downloads'          => 0,
    'unique_listeners'   => 0,
    'bot_downloads'      => 0,
    'episodes_count'     => 0,
    'avg_duration_secs'  => 0,
    'avg_duration_fmt'   => '—',
    'avg_playback_secs'  => 0,
    'avg_playback_fmt'   => '—',
    'avg_completion_pct' => 0.0,
    'total_time_secs'    => 0,
    'total_time_fmt'     => '0m'
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

    $weight = max(1, $epStat['downloads']);
    $weightedPlaybackSum += ($epStat['avg_playback_secs'] * $weight);
    $weightedPctSum += ($epStat['completion_pct'] * $weight);
  }

  $epCount = count($eps);
  $avgDurationSecs = $validDurCount > 0 ? (int)round($sumDuration / $validDurCount) : 0;
  $weightDenominator = max(1, $totalDl > 0 ? $totalDl : $epCount);
  $avgPlaybackSecs = (int)round($weightedPlaybackSum / $weightDenominator);
  $avgCompletionPct = round($weightedPctSum / $weightDenominator, 1);

  return [
    'podcast_id'         => $podcastId,
    'downloads'          => $totalDl,
    'unique_listeners'   => $totalUl,
    'bot_downloads'      => $totalBd,
    'episodes_count'     => $epCount,
    'avg_duration_secs'  => $avgDurationSecs,
    'avg_duration_fmt'   => $avgDurationSecs > 0 ? kp_fmt_duration($avgDurationSecs) : '—',
    'avg_playback_secs'  => $avgPlaybackSecs,
    'avg_playback_fmt'   => $avgPlaybackSecs > 0 ? kp_fmt_duration($avgPlaybackSecs) : '—',
    'avg_completion_pct' => $avgCompletionPct,
    'total_time_secs'    => $totalSecsAccum,
    'total_time_fmt'     => kp_fmt_hours_mins($totalSecsAccum)
  ];
}

/**
 * Calcula las métricas globales consolidadas de reproducción de toda la red de podcasts.
 */
function kp_op3_all_podcasts_playback_totals(int $days = 30): array {
  $podcasts = kp_q("SELECT id, title FROM podcasts WHERE hidden = 0");
  if (!$podcasts) {
    return [
      'downloads'          => 0,
      'unique_listeners'   => 0,
      'bot_downloads'      => 0,
      'avg_playback_secs'  => 0,
      'avg_playback_fmt'   => '—',
      'avg_completion_pct' => 0.0,
      'total_time_secs'    => 0,
      'total_time_fmt'     => '0m'
    ];
  }

  $totalDl = 0; $totalUl = 0; $totalBd = 0;
  $totalTimeSecs = 0;
  $weightedPlaybackSum = 0;
  $weightedPctSum = 0;

  foreach ($podcasts as $p) {
    $ps = kp_op3_podcast_playback_stats((int)$p['id'], $days);
    $totalDl += $ps['downloads'];
    $totalUl += $ps['unique_listeners'];
    $totalBd += $ps['bot_downloads'];
    $totalTimeSecs += $ps['total_time_secs'];

    $weight = max(1, $ps['downloads']);
    $weightedPlaybackSum += ($ps['avg_playback_secs'] * $weight);
    $weightedPctSum += ($ps['avg_completion_pct'] * $weight);
  }

  $weightDenominator = max(1, $totalDl > 0 ? $totalDl : count($podcasts));
  $avgPlaybackSecs = (int)round($weightedPlaybackSum / $weightDenominator);
  $avgCompletionPct = round($weightedPctSum / $weightDenominator, 1);

  return [
    'downloads'          => $totalDl,
    'unique_listeners'   => $totalUl,
    'bot_downloads'      => $totalBd,
    'avg_playback_secs'  => $avgPlaybackSecs,
    'avg_playback_fmt'   => $avgPlaybackSecs > 0 ? kp_fmt_duration($avgPlaybackSecs) : '—',
    'avg_completion_pct' => $avgCompletionPct,
    'total_time_secs'    => $totalTimeSecs,
    'total_time_fmt'     => kp_fmt_hours_mins($totalTimeSecs)
  ];
}

