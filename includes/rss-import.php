<?php
// ============================================================================
// KutPod · motor de importación RSS robusto
// ============================================================================
// Diseño:
//   - Streaming con XMLReader (no carga el feed entero en memoria)
//   - Idempotente: upsert por <guid>, conserva GUIDs originales
//   - Resumable: cada job persiste su cursor; reanuda donde quedó si falla
//   - Estado en BD (import_jobs + import_job_events) para que la UI haga poll
//   - Descargas con cURL stream-to-disk, reintentos exponenciales, validación de tamaño
//   - Soporte completo de namespaces: itunes, content, podcast 2.0, atom

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

const KP_IMPORT_UA = 'KutPod-Importer/1.0 (+https://www.tupodcast.com)';
const KP_IMPORT_TIMEOUT = 30;
const KP_IMPORT_RETRIES = 5;
const KP_IMPORT_BACKOFF = [1, 2, 5, 10, 30]; // segundos

function kp_import_ensure_schema(): void {
  $pdo = kp_db();
  $pdo->exec("CREATE TABLE IF NOT EXISTS import_jobs (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    feed_url     TEXT NOT NULL,
    target_id    INTEGER REFERENCES podcasts(id) ON DELETE SET NULL,
    options_json TEXT NOT NULL DEFAULT '{}',
    status       TEXT NOT NULL DEFAULT 'queued' CHECK(status IN ('queued','running','paused','done','failed')),
    total        INTEGER NOT NULL DEFAULT 0,
    done         INTEGER NOT NULL DEFAULT 0,
    failed       INTEGER NOT NULL DEFAULT 0,
    bytes        INTEGER NOT NULL DEFAULT 0,
    cursor       TEXT,
    last_error   TEXT,
    started_at   TEXT,
    finished_at  TEXT,
    created_at   TEXT NOT NULL DEFAULT (datetime('now'))
  )");
  $pdo->exec("CREATE TABLE IF NOT EXISTS import_job_events (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    job_id    INTEGER NOT NULL REFERENCES import_jobs(id) ON DELETE CASCADE,
    level     TEXT NOT NULL DEFAULT 'info',
    message   TEXT NOT NULL,
    at        TEXT NOT NULL DEFAULT (datetime('now'))
  )");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_iev_job ON import_job_events(job_id, id DESC)");
}

function kp_import_log(int $jobId, string $msg, string $level = 'info'): void {
  kp_db()->prepare("INSERT INTO import_job_events (job_id,level,message) VALUES (?,?,?)")
        ->execute([$jobId, $level, $msg]);
}

function kp_import_create_job(string $feedUrl, ?int $targetId, array $options): int {
  kp_import_ensure_schema();
  $defaults = [
    'preserve_guid'    => true,
    'preserve_dates'   => true,
    'download_audio'   => true,
    'download_images'  => true,
    'podcast_2_0'      => true,
    'resume_on_fail'   => true,
    'auto_backup'      => true,
    'concurrency'      => 4,
  ];
  $opts = array_merge($defaults, $options);
  $id = kp_exec("INSERT INTO import_jobs (feed_url,target_id,options_json,status)
                 VALUES (?,?,?,'queued')",
                [$feedUrl, $targetId, json_encode($opts, JSON_UNESCAPED_UNICODE)]);
  kp_import_log($id, "Job creado · feed = $feedUrl");
  return $id;
}

// ============================================================================
// Análisis previo (sondeo): cuenta items y extrae metadatos del channel
// sin descargar nada. Usado por el botón "Analizar feed" del panel.
// ============================================================================
function kp_import_analyze(string $url): array {
  $tmp = tempnam(sys_get_temp_dir(), 'kp_feed_');
  kp_http_download($url, $tmp); // descarga completa: solo para análisis
  $meta = ['url'=>$url, 'items'=>0, 'namespaces'=>[]];

  $r = new XMLReader();
  if (!$r->open($tmp)) { @unlink($tmp); throw new RuntimeException('No se pudo leer el feed'); }
  while ($r->read()) {
    if ($r->nodeType === XMLReader::ELEMENT) {
      // Detectar namespaces declarados en <rss>
      if ($r->name === 'rss' && $r->hasAttributes) {
        while ($r->moveToNextAttribute()) {
          if (strpos($r->name, 'xmlns:') === 0) $meta['namespaces'][] = substr($r->name, 6);
        }
        $r->moveToElement();
      }
      if ($r->name === 'channel') {
        $node = new SimpleXMLElement($r->readOuterXml());
        $node->registerXPathNamespace('itunes', 'http://www.itunes.com/dtds/podcast-1.0.dtd');
        $meta['title']       = (string)$node->title;
        $meta['description'] = kp_html_to_markdown((string)$node->description);
        $meta['language']    = (string)$node->language;
        $meta['link']        = (string)$node->link;
        $img = $node->xpath('itunes:image/@href');
        $meta['image']       = $img ? (string)$img[0] : (string)($node->image->url ?? '');
        $auth = $node->xpath('itunes:author');
        $meta['author']      = $auth ? (string)$auth[0] : '';
      }
      if ($r->name === 'item') $meta['items']++;
    }
  }
  $r->close();
  @unlink($tmp);
  return $meta;
}

// ============================================================================
// Ejecutor del job. Diseñado para ser invocado:
//   - Por CLI: `php cli/import-worker.php <job_id>`
//   - Por HTTP en background (ignore_user_abort + fastcgi_finish_request)
// Es idempotente: si lo matas a la mitad, vuelves a llamar y reanuda.
// ============================================================================
function kp_import_run(int $jobId, ?callable $tick = null): void {
  kp_import_ensure_schema();
  $job = kp_one("SELECT * FROM import_jobs WHERE id = ?", [$jobId]);
  if (!$job) throw new RuntimeException("Job $jobId no existe");
  if (in_array($job['status'], ['done','failed'], true)) return;

  set_time_limit(0);
  ignore_user_abort(true);

  $opts = json_decode($job['options_json'], true) ?: [];
  $pdo = kp_db();

  // Marcar running + timestamp de arranque
  $pdo->prepare("UPDATE import_jobs SET status='running', started_at=COALESCE(started_at,datetime('now')) WHERE id = ?")
      ->execute([$jobId]);
  kp_import_log($jobId, $job['cursor'] ? "Reanudando desde GUID ${job['cursor']}" : "Iniciando importación");

  // 1) Descargar feed a temp (resume con If-Range no aplica a la mayoría de feeds)
  $feedHash = md5($job['feed_url']);
  $feedFile = sys_get_temp_dir() . "/kp_feed_{$jobId}_{$feedHash}.xml";
  
  // Solo reusamos el archivo si el job está reanudando (tiene cursor) y el archivo existe
  if (!$job['cursor'] || !file_exists($feedFile) || filesize($feedFile) < 1024) {
    kp_import_log($jobId, "Descargando feed XML…");
    kp_http_download($job['feed_url'], $feedFile);
  }

  // 2) Primera pasada: extraer metadatos del channel y crear/actualizar podcast
  $podcastId = (int)$job['target_id'];
  $channel = kp_import_read_channel($feedFile);

  if (!$podcastId) {
    $title = $channel['title'] ?? 'Imported Podcast';
    
    // Intentar extraer el slug original de la URL del feed o del <link> (ej. /@sinpropaganda)
    $slug = '';
    if (preg_match('#/@([a-z0-9\-]+)#i', $job['feed_url'], $m) || preg_match('#/@([a-z0-9\-]+)#i', $channel['link'] ?? '', $m)) {
      $slug = strtolower($m[1]);
    }
    if (!$slug) {
      $slug = kp_slugify($title);
    }
    
    // Garantizar que el slug es único
    $baseSlug = $slug;
    $i = 1;
    while (kp_one("SELECT id FROM podcasts WHERE slug = ?", [$slug])) {
      $slug = $baseSlug . '-' . $i++;
    }
    
    // Download podcast cover
    $coverRel = null;
    if (!empty($channel['image'])) {
      try {
        [$coverRel,] = kp_download_with_retry($channel['image'], __DIR__ . "/../media/covers", 'podcast_' . $slug);
      } catch (Throwable $e) {}
    }

    $podcastId = kp_exec(
      "INSERT INTO podcasts (slug,title,description,language,category,author,cover,color)
       VALUES (?,?,?,?,?,?,?,?)",
      [$slug, $title, $channel['description'] ?? '',
       $channel['language'] ?? 'es', $channel['category'] ?? '',
       $channel['author'] ?? '', $coverRel ?: ($channel['image'] ?? null),
       kp_random_color()]
    );
    $pdo->prepare("UPDATE import_jobs SET target_id = ? WHERE id = ?")->execute([$podcastId, $jobId]);
    kp_import_log($jobId, "Podcast creado: $slug (id=$podcastId)");
  } else {
    kp_import_log($jobId, "Mergeando en podcast id=$podcastId");
  }

  // 3) Backup pre-importación
  if ($opts['auto_backup']) {
    $bk = __DIR__ . "/../storage/backups/pre-import-$jobId.sql";
    @mkdir(dirname($bk), 0755, true);
    file_put_contents($bk, kp_dump_podcast($podcastId));
    kp_exec("INSERT INTO backups (podcast_id,kind,path,bytes) VALUES (?,?,?,?)",
      [$podcastId, 'pre-import', $bk, filesize($bk)]);
    kp_import_log($jobId, "Backup pre-importación guardado");
  }

  // 4) Contar total una vez (si no se contó antes)
  if (!$job['total']) {
    $total = kp_import_count_items($feedFile);
    $pdo->prepare("UPDATE import_jobs SET total = ? WHERE id = ?")->execute([$total, $jobId]);
    $job['total'] = $total;
    kp_import_log($jobId, "Total de items: $total");
  }

  // 5) Stream de items con XMLReader (no carga el feed entero)
  $reader = new XMLReader();
  $reader->open($feedFile);
  $cursorReached = empty($job['cursor']); // si hay cursor, saltar hasta encontrarlo
  $insertEp = $pdo->prepare(
    "INSERT INTO episodes
      (podcast_id,guid,slug,title,season,number,ep_type,parental,notes_md,
       audio_url,audio_bytes,audio_mime,duration_secs,cover,transcript_url,chapters_url,
       status,published_at,created_at)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'published',?,datetime('now'))
     ON CONFLICT(guid) DO UPDATE SET
       title=excluded.title,
       notes_md=excluded.notes_md,
       audio_bytes=excluded.audio_bytes,
       duration_secs=excluded.duration_secs,
       updated_at=datetime('now')"
  );

  $startTime = microtime(true);
  while ($reader->read()) {
    if ($reader->nodeType !== XMLReader::ELEMENT || $reader->name !== 'item') continue;
    $xml = $reader->readOuterXml();
    if (!$xml) continue;

    try {
      $item = kp_import_parse_item($xml);
    } catch (Throwable $e) {
      kp_import_log($jobId, "Item ilegible: " . $e->getMessage(), 'warn');
      $pdo->prepare("UPDATE import_jobs SET failed = failed + 1 WHERE id = ?")->execute([$jobId]);
      continue;
    }

    // Cursor: saltar hasta volver al punto donde se cortó la corrida anterior
    if (!$cursorReached) {
      if ($item['guid'] === $job['cursor']) $cursorReached = true;
      continue;
    }

    // Idempotencia: si existe ya este GUID en este podcast, saltamos descarga de audio si está
    $exists = kp_one("SELECT id, audio_url FROM episodes WHERE podcast_id = ? AND guid = ?",
      [$podcastId, $item['guid']]);

    // Descargar audio
    $audioRel = $exists['audio_url'] ?? null;
    $audioBytes = 0;
    if ($opts['download_audio'] && $item['enclosure_url'] && !$audioRel) {
      try {
        [$audioRel, $audioBytes] = kp_download_with_retry(
          $item['enclosure_url'],
          __DIR__ . "/../media/audio/$podcastId",
          $item['guid']
        );
        $pdo->prepare("UPDATE import_jobs SET bytes = bytes + ? WHERE id = ?")->execute([$audioBytes, $jobId]);
      } catch (Throwable $e) {
        kp_import_log($jobId, "Audio falló para ${item['title']}: " . $e->getMessage(), 'warn');
        if (!$opts['resume_on_fail']) throw $e;
      }
    }

    $chaptersUrl = $item['chapters_url'];
    if (empty($chaptersUrl) && !empty($audioRel)) {
      $audioAbsPath = __DIR__ . '/..' . $audioRel;
      $chaptersUrl = kp_extract_chapters_to_json($audioAbsPath) ?: '';
    }

    // Descargar portada del episodio
    $coverRel = null;
    if ($opts['download_images'] && $item['image']) {
      try {
        [$coverRel,] = kp_download_with_retry($item['image'], __DIR__ . "/../media/covers/$podcastId", $item['guid']);
      } catch (Throwable $e) { /* portada opcional */ }
    }

    $slug = kp_slugify($item['title']);
    $insertEp->execute([
      $podcastId,
      $opts['preserve_guid'] ? $item['guid'] : bin2hex(random_bytes(8)),
      $slug,
      $item['title'],
      $item['season'],
      $item['number'],
      $item['ep_type'] ?: 'full',
      $item['explicit'] ? 'explicit' : 'clean',
      $item['notes'],
      $audioRel,
      $audioBytes ?: ($item['enclosure_length'] ?: 0),
      $item['enclosure_type'] ?: 'audio/mpeg',
      $item['duration_secs'],
      $coverRel,
      $item['transcript_url'],
      $chaptersUrl,
      $opts['preserve_dates'] ? $item['pub_date'] : gmdate('Y-m-d H:i:s'),
    ]);

    if (!empty($item['fixed_notes'])) {
      $pdo->prepare("UPDATE podcasts SET fixed_notes = ? WHERE id = ? AND (fixed_notes IS NULL OR fixed_notes = '')")
          ->execute([$item['fixed_notes'], $podcastId]);
    }

    // Avanzar contadores y cursor
    $pdo->prepare("UPDATE import_jobs SET done = done + 1, cursor = ? WHERE id = ?")
        ->execute([$item['guid'], $jobId]);

    if ($tick) {
      $tick([
        'done' => $job['done'] + 1,
        'total' => $job['total'],
        'title' => $item['title'],
        'rate' => round(($job['done'] + 1) / max(1, microtime(true) - $startTime), 2),
      ]);
    }
  }
  $reader->close();
  @unlink($feedFile);

  $pdo->prepare("UPDATE import_jobs SET status='done', finished_at=datetime('now') WHERE id = ?")
      ->execute([$jobId]);
  kp_import_log($jobId, "Importación completada");

  // Alerta de importación completada
  $finalJob = kp_one("SELECT done, failed, target_id FROM import_jobs WHERE id = ?", [$jobId]);
  $podName = '';
  if ($finalJob && $finalJob['target_id']) {
    $pod = kp_one("SELECT title FROM podcasts WHERE id = ?", [$finalJob['target_id']]);
    $podName = $pod['title'] ?? '';
  }
  $doneCount = $finalJob['done'] ?? 0;
  $failCount = $finalJob['failed'] ?? 0;
  kp_alert('import', 'Importación completada', "Se importaron $doneCount episodios" . ($failCount ? " ($failCount fallidos)" : '') . ($podName ? " en '$podName'" : '') . ".", '/admin/import');
}

// ============================================================================
// Parsers
// ============================================================================
function kp_import_read_channel(string $file): array {
  // Leverage the same logic as kp_import_analyze to safely extract channel meta
  $meta = [];
  $r = new XMLReader();
  if (!$r->open($file)) return [];
  while ($r->read()) {
    if ($r->nodeType === XMLReader::ELEMENT && $r->name === 'channel') {
      $xml = '';
      $moved = $r->read();
      while ($moved) {
        if ($r->nodeType === XMLReader::ELEMENT && $r->name === 'item') break;
        if ($r->nodeType === XMLReader::END_ELEMENT && $r->name === 'channel') break;
        
        if ($r->nodeType === XMLReader::ELEMENT) {
          $xml .= $r->readOuterXml();
          $moved = $r->next();
        } else {
          $moved = $r->read();
        }
      }
      $rss = @simplexml_load_string('<rss xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:podcast="https://podcastindex.org/namespace/1.0" xmlns:atom="http://www.w3.org/2005/Atom"><channel>' . $xml . '</channel></rss>');
      $node = $rss ? $rss->channel : null;
      if ($node) {
        $node->registerXPathNamespace('itunes', 'http://www.itunes.com/dtds/podcast-1.0.dtd');
        $img = $node->xpath('itunes:image/@href');
        $auth = $node->xpath('itunes:author');
        $cat = $node->xpath('itunes:category/@text');
        $itunesSummary = $node->xpath('itunes:summary');
        $meta = [
          'title' => (string)$node->title,
          'description' => kp_html_to_markdown(trim((string)$node->description) ?: ($itunesSummary ? trim((string)$itunesSummary[0]) : '')),
          'language' => (string)$node->language,
          'image' => $img ? (string)$img[0] : (string)($node->image->url ?? ''),
          'author' => $auth ? (string)$auth[0] : '',
          'category' => $cat ? (string)$cat[0] : '',
        ];
      }
      break;
    }
  }
  $r->close();
  return $meta;
}

function kp_import_count_items(string $file): int {
  $n = 0; $r = new XMLReader(); $r->open($file);
  while ($r->read()) {
    if ($r->nodeType === XMLReader::ELEMENT && $r->name === 'item') $n++;
  }
  $r->close();
  return $n;
}

function kp_import_parse_item(string $xml): array {
  $wrapper = '<rss xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:podcast="https://podcastindex.org/namespace/1.0" xmlns:atom="http://www.w3.org/2005/Atom">' . $xml . '</rss>';
  $rss = new SimpleXMLElement($wrapper);
  $node = $rss->item;
  $node->registerXPathNamespace('itunes', 'http://www.itunes.com/dtds/podcast-1.0.dtd');
  $node->registerXPathNamespace('content', 'http://purl.org/rss/1.0/modules/content/');
  $node->registerXPathNamespace('podcast', 'https://podcastindex.org/namespace/1.0');

  $itunes = function(string $tag) use ($node) {
    $r = $node->xpath("itunes:$tag");
    return $r ? (string)$r[0] : '';
  };
  $podcast = function(string $tag, string $attr) use ($node) {
    $r = $node->xpath("podcast:$tag/@$attr");
    return $r ? (string)$r[0] : '';
  };

  $enclosure = $node->enclosure;
  $contentEncoded = $node->xpath('content:encoded');

  $duration = $itunes('duration');
  $secs = 0;
  if (preg_match('/^(\d+):(\d+):(\d+)$/', $duration, $m)) $secs = (int)$m[1]*3600 + (int)$m[2]*60 + (int)$m[3];
  elseif (preg_match('/^(\d+):(\d+)$/', $duration, $m)) $secs = (int)$m[1]*60 + (int)$m[2];
  elseif (ctype_digit($duration)) $secs = (int)$duration;

  $guid = (string)($node->guid ?? '');
  if (!$guid) $guid = sha1((string)$node->title . (string)$node->pubDate);

  $img = $node->xpath('itunes:image/@href');

  $notes = '';
  $customNotesMd = $node->xpath('notes_md') ?: $node->xpath('.//*[local-name()="notes_md"]');
  
  if ($customNotesMd && trim((string)$customNotesMd[0]) !== '') {
    $notes = trim((string)$customNotesMd[0]);
  } elseif ($contentEncoded && trim((string)$contentEncoded[0]) !== '') {
    $notes = trim((string)$contentEncoded[0]);
  } elseif (trim((string)$node->description) !== '') {
    $notes = trim((string)$node->description);
  } else {
    $notes = trim($itunes('summary'));
  }

  $fixed_notes = '';
  if (preg_match('#<footer>(.*?)</footer>#is', $notes, $m)) {
    $fixed_notes = trim($m[1]);
    $notes = trim(preg_replace('#<footer>.*?</footer>#is', '', $notes));
  }

  if (!($customNotesMd && trim((string)$customNotesMd[0]) !== '')) {
    $notes = kp_html_to_markdown($notes);
    if ($fixed_notes !== '') {
      $fixed_notes = kp_html_to_markdown($fixed_notes);
    }
  }

  return [
    'guid' => $guid,
    'title' => trim((string)$node->title),
    'pub_date' => $node->pubDate ? gmdate('Y-m-d H:i:s', strtotime((string)$node->pubDate)) : gmdate('Y-m-d H:i:s'),
    'notes' => $notes,
    'enclosure_url' => $enclosure ? (string)$enclosure['url'] : '',
    'enclosure_length' => $enclosure ? (int)$enclosure['length'] : 0,
    'enclosure_type' => $enclosure ? (string)$enclosure['type'] : '',
    'duration_secs' => $secs,
    'season' => (int)$itunes('season') ?: null,
    'number' => (int)$itunes('episode') ?: null,
    'ep_type' => $itunes('episodeType') ?: 'full',
    'explicit' => in_array(strtolower($itunes('explicit')), ['yes','true','explicit'], true),
    'image' => $img ? (string)$img[0] : '',
    'transcript_url' => $podcast('transcript', 'url'),
    'chapters_url' => $podcast('chapters', 'url'),
    'fixed_notes' => $fixed_notes,
  ];
}

// ============================================================================
// HTTP · descargas robustas
// ============================================================================
function kp_http_download(string $url, string $dest, int $retries = KP_IMPORT_RETRIES): int {
  for ($i = 0; $i < $retries; $i++) {
    try {
      $fp = fopen($dest, 'wb');
      if (!$fp) throw new RuntimeException("No se pudo abrir destino $dest");
      $ch = curl_init($url);
      curl_setopt_array($ch, [
        CURLOPT_FILE => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_CONNECTTIMEOUT => KP_IMPORT_TIMEOUT,
        CURLOPT_USERAGENT => KP_IMPORT_UA,
        CURLOPT_FAILONERROR => true,
        CURLOPT_SSL_VERIFYPEER => true,
      ]);
      $ok = curl_exec($ch);
      $err = curl_error($ch);
      $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      curl_close($ch); fclose($fp);
      if (!$ok || $http >= 400) throw new RuntimeException("HTTP $http $err");
      return filesize($dest);
    } catch (Throwable $e) {
      @unlink($dest);
      if ($i + 1 >= $retries) throw $e;
      sleep(KP_IMPORT_BACKOFF[$i] ?? 30);
    }
  }
  return 0;
}

function kp_download_with_retry(string $url, string $destDir, string $guid): array {
  if (!is_dir($destDir)) @mkdir($destDir, 0755, true);
  $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
  if (!$ext) $ext = str_contains($destDir, 'covers') ? 'jpg' : 'bin';
  $safe = preg_replace('/[^a-z0-9]+/i', '-', basename((string)parse_url($url, PHP_URL_PATH)));
  $name = substr(sha1($guid), 0, 8) . '-' . substr($safe, 0, 20) . '.' . $ext;
  $abs = "$destDir/$name";
  // Si ya existe (resume de job anterior) y tiene tamaño plausible, reusarlo
  if (file_exists($abs) && filesize($abs) > 1024) {
    return [str_replace(__DIR__ . '/../media', '/media', $abs), filesize($abs)];
  }
  $bytes = kp_http_download($url, $abs);
  $rel = str_replace(__DIR__ . '/../media', '/media', $abs);
  return [$rel, $bytes];
}



function kp_dump_podcast(int $id): string {
  $p = kp_one("SELECT * FROM podcasts WHERE id = ?", [$id]);
  $eps = kp_q("SELECT * FROM episodes WHERE podcast_id = ?", [$id]);
  return json_encode(['podcast'=>$p, 'episodes'=>$eps], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
