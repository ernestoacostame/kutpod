<?php
// CLI worker · drena op3_hits aplicando IAB v2 + dedupe + bot detection
// Cron recomendado: cada 15 minutos.
//   */15 * * * * cd /var/www/kutpod && php php/cli/op3-worker.php >> /var/log/kutpod-op3.log 2>&1
require_once __DIR__ . '/../includes/op3-tracker.php';
require_once __DIR__ . '/../includes/helpers.php';
if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }
$total = ['processed' => 0, 'inserted' => 0, 'duplicates' => 0];
$by_podcast = [];
$by_podcast_episode = [];
do {
  $r = kp_op3_process_pending(5000);
  foreach ($r as $k => $v) {
    if ($k === 'by_podcast') {
      foreach ($v as $pid => $cnt) {
        $by_podcast[$pid] = ($by_podcast[$pid] ?? 0) + $cnt;
      }
    } elseif ($k === 'by_podcast_episode') {
      foreach ($v as $pid => $eps) {
        foreach ($eps as $eid => $cnt) {
          $by_podcast_episode[$pid][$eid] = ($by_podcast_episode[$pid][$eid] ?? 0) + $cnt;
        }
      }
    } else {
      $total[$k] += $v;
    }
  }
} while ($r['processed'] >= 5000);
printf("[%s] OP3 worker · hits:%d downloads:%d dups:%d\n",
  date('c'), $total['processed'], $total['inserted'], $total['duplicates']);

// Alerta de analytics solo si hubo descargas procesadas
if ($total['inserted'] > 0) {
  $dlCount = (int)$total['inserted'];
  $dlStr = $dlCount === 1 ? '1 descarga registrada' : "{$dlCount} descargas registradas";
  $dupCount = (int)$total['duplicates'];
  $dupStr = $dupCount === 1 ? '1 duplicado filtrado' : "{$dupCount} duplicados filtrados";

  $link = '';
  $podCount = count($by_podcast_episode);

  if ($podCount === 1) {
    $pid = array_key_first($by_podcast_episode);
    $eps = $by_podcast_episode[$pid];
    $p = kp_one("SELECT title, slug FROM podcasts WHERE id = ?", [$pid]);
    $pname = $p['title'] ?? 'Podcast Desconocido';
    if (!empty($p['slug'])) {
      $link = '/admin/podcast/' . urlencode($p['slug']) . '?tab=analytics';
    }

    if (count($eps) === 1) {
      $eid = array_key_first($eps);
      $e = kp_one("SELECT title FROM episodes WHERE id = ?", [$eid]);
      $ename = $e['title'] ?? 'Episodio';
      $body = "'{$ename}' ('{$pname}'): {$dlStr}. ({$dupStr}).";
    } else {
      $epParts = [];
      $shown = 0;
      foreach ($eps as $eid => $cnt) {
        $shown++;
        if ($shown <= 3) {
          $e = kp_one("SELECT title FROM episodes WHERE id = ?", [$eid]);
          $ename = $e['title'] ?? 'Episodio';
          $epParts[] = "'{$ename}' ({$cnt})";
        }
      }
      if (count($eps) > 3) {
        $epParts[] = 'y ' . (count($eps) - 3) . ' más';
      }
      $body = implode(', ', $epParts) . " ('{$pname}'): {$dlStr}. ({$dupStr}).";
    }
  } elseif ($podCount > 1) {
    $podParts = [];
    foreach ($by_podcast_episode as $pid => $eps) {
      $p = kp_one("SELECT title, slug FROM podcasts WHERE id = ?", [$pid]);
      $pname = $p['title'] ?? 'Podcast';
      $epParts = [];
      $shown = 0;
      foreach ($eps as $eid => $cnt) {
        $shown++;
        if ($shown <= 2) {
          $e = kp_one("SELECT title FROM episodes WHERE id = ?", [$eid]);
          $ename = $e['title'] ?? 'Episodio';
          $epParts[] = "'{$ename}' ({$cnt})";
        }
      }
      if (count($eps) > 2) {
        $epParts[] = 'y ' . (count($eps) - 2) . ' más';
      }
      $podParts[] = implode(', ', $epParts) . " ('{$pname}')";
    }
    $body = implode('; ', $podParts) . ": {$dlStr}. ({$dupStr}).";
    $link = '/admin/analytics';
  } else {
    // Fallback si por alguna razón no vinieron episode_id
    $detail = [];
    foreach ($by_podcast as $pid => $cnt) {
      if ($cnt > 0) {
        $p = kp_one("SELECT title, slug FROM podcasts WHERE id = ?", [$pid]);
        $pname = $p['title'] ?? 'Podcast Desconocido';
        $detail[] = "'{$pname}' ({$cnt})";
        if (empty($link) && !empty($p['slug'])) {
          $link = '/admin/podcast/' . urlencode($p['slug']) . '?tab=analytics';
        }
      }
    }
    $body = implode(', ', $detail) . ": {$dlStr}. ({$dupStr}).";
    if (count($by_podcast) > 1) {
      $link = '/admin/analytics';
    }
  }

  kp_alert('op3', 'Descargas procesadas', $body, $link);
}

// Detección de milestones de descargas por episodio
try {
  $milestones = [100, 500, 1000, 5000, 10000, 50000, 100000];
  $episodes = kp_q("SELECT e.id, e.title, e.slug, e.podcast_id, p.title as podcast_title, p.slug as podcast_slug,
                     COALESCE(SUM(d.downloads), 0) as total_downloads
                     FROM episodes e
                     JOIN podcasts p ON p.id = e.podcast_id
                     LEFT JOIN op3_stats_daily d ON d.episode_id = e.id
                     GROUP BY e.id
                     HAVING total_downloads > 0");
   
  foreach ($episodes as $ep) {
    $dl = (int)$ep['total_downloads'];
    foreach ($milestones as $m) {
      if ($dl >= $m && $dl < $m + ($total['inserted'] ?: 1)) {
        // Verificar que no hayamos generado este milestone antes
        $already = kp_one("SELECT 1 FROM alerts WHERE kind='op3' AND title LIKE ? AND body LIKE ? LIMIT 1",
          ["%$m descargas%", "%{$ep['title']}%"]);
        if (!$already) {
          $emoji = $m >= 10000 ? '🔥' : ($m >= 1000 ? '🎉' : '📈');
          $epLink = '/admin/podcast/' . urlencode($ep['podcast_slug']) . '?tab=analytics';
          kp_alert('op3', "$emoji $m descargas alcanzadas",
            "El episodio '{$ep['title']}' de {$ep['podcast_title']} ha superado las " . number_format($m) . " descargas.",
            $epLink);
        }
      }
    }
  }
} catch (Throwable $e) {
  // Milestones son opcionales, no fallar el worker
}
