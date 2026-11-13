<?php
// ============================================================================
// KutPod · ActivityPub (Webfinger + Actor + Outbox + Inbox + HTTP Signatures)
// ============================================================================
// Rewrites recomendados (Apache/Nginx → todos a este archivo):
//   /.well-known/webfinger       → activitypub.php?ap=webfinger
//   /.well-known/host-meta       → activitypub.php?ap=host-meta
//   /users/{slug}                → activitypub.php?ap=actor&slug={slug}
//   /users/{slug}/outbox         → activitypub.php?ap=outbox&slug={slug}
//   /users/{slug}/inbox          → activitypub.php?ap=inbox&slug={slug}
//   /users/{slug}/followers      → activitypub.php?ap=followers&slug={slug}
//   /users/{slug}/notes/{ep}     → activitypub.php?ap=note&slug={slug}&ep={ep}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';

if (!kp_plugin_is_active('fediverse') && ((isset($_SERVER['SCRIPT_FILENAME']) && realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'])) || isset($_GET['ap']))) {
    http_response_code(404);
    exit('El Fediverso está desactivado.');
}

// ---------------------------------------------------------------------------
// Esquema · claves RSA + cola de entrega + actividades salientes
// ---------------------------------------------------------------------------
function kp_ap_ensure_schema(): void {
  $pdo = kp_db();
  $pdo->exec("CREATE TABLE IF NOT EXISTS ap_keys (
    podcast_id  INTEGER PRIMARY KEY REFERENCES podcasts(id) ON DELETE CASCADE,
    public_key  TEXT NOT NULL,
    private_key TEXT NOT NULL,
    created_at  TEXT NOT NULL DEFAULT (datetime('now'))
  )");
  $pdo->exec("CREATE TABLE IF NOT EXISTS ap_outbox (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    podcast_id  INTEGER NOT NULL REFERENCES podcasts(id) ON DELETE CASCADE,
    activity_id TEXT NOT NULL UNIQUE,
    type        TEXT NOT NULL,
    object_json TEXT NOT NULL,
    published   TEXT NOT NULL DEFAULT (datetime('now'))
  )");
  $pdo->exec("CREATE TABLE IF NOT EXISTS ap_delivery (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    outbox_id   INTEGER NOT NULL REFERENCES ap_outbox(id) ON DELETE CASCADE,
    inbox       TEXT NOT NULL,
    status      TEXT NOT NULL DEFAULT 'pending',
    attempts    INTEGER NOT NULL DEFAULT 0,
    last_error  TEXT,
    next_try    TEXT NOT NULL DEFAULT (datetime('now')),
    delivered_at TEXT
  )");
  $pdo->exec("CREATE TABLE IF NOT EXISTS ap_likes (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    podcast_id  INTEGER NOT NULL REFERENCES podcasts(id) ON DELETE CASCADE,
    episode_id  INTEGER NOT NULL REFERENCES episodes(id) ON DELETE CASCADE,
    actor_url   TEXT NOT NULL,
    actor_name  TEXT,
    actor_handle TEXT,
    actor_avatar TEXT,
    created_at  TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(episode_id, actor_url)
  )");
}
kp_ap_ensure_schema();

function kp_ap_base(): string {
  $domain = kp_setting('instance_domain', $_SERVER['HTTP_HOST'] ?? 'www.tupodcast.com');
  $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'https'; // Forzamos https en producción
  return "$proto://$domain";
}

function kp_ap_keypair(int $podcastId): array {
  $row = kp_one("SELECT * FROM ap_keys WHERE podcast_id = ?", [$podcastId]);
  if ($row) return $row;
  $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
  openssl_pkey_export($res, $priv);
  $pub = openssl_pkey_get_details($res)['key'];
  kp_exec("INSERT INTO ap_keys (podcast_id,public_key,private_key) VALUES (?,?,?)",
    [$podcastId, $pub, $priv]);
  return ['public_key' => $pub, 'private_key' => $priv];
}

function kp_ap_json(array $payload, string $type = 'application/activity+json'): void {
  header("Content-Type: $type; charset=utf-8");
  echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

// ---------------------------------------------------------------------------
// Webfinger · resuelve acct:slug@host → actor URL
// ---------------------------------------------------------------------------
function kp_ap_webfinger(): void {
  $r = $_GET['resource'] ?? '';
  if (!preg_match('/^acct:([^@]+)@(.+)$/', $r, $m)) { http_response_code(400); exit; }
  $slug = strtolower($m[1]);
  $p = kp_one("SELECT * FROM podcasts WHERE slug = ? OR fediverse_handle = ?", [$slug, $slug]);
  if (!$p || !$p['federate']) { http_response_code(404); exit; }
  $actor = kp_ap_base() . "/users/" . $p['slug'];
  kp_ap_json([
    'subject' => "acct:$slug@" . kp_handle_domain(),
    'aliases' => [$actor],
    'links' => [
      ['rel' => 'self', 'type' => 'application/activity+json', 'href' => $actor],
      ['rel' => 'http://webfinger.net/rel/profile-page', 'type' => 'text/html', 'href' => kp_ap_base() . "/@$slug"],
    ],
  ], 'application/jrd+json');
}

// ---------------------------------------------------------------------------
// Actor · perfil del podcast como Service ActivityPub
// ---------------------------------------------------------------------------
function kp_ap_make_actor_array(array $p): array {
  $slug = $p['slug'];
  $kp = kp_ap_keypair($p['id']);
  $url = kp_ap_base() . "/users/$slug";
  $base = kp_ap_base();
  return [
    '@context' => ['https://www.w3.org/ns/activitystreams', 'https://w3id.org/security/v1'],
    'id' => $url,
    'type' => 'Service',
    'preferredUsername' => $slug,
    'name' => $p['title'],
    'summary' => $p['description'] ?? '',
    'manuallyApprovesFollowers' => false,
    'url' => "$base/@$slug",
    'inbox' => "$url/inbox",
    'outbox' => "$url/outbox",
    'followers' => "$url/followers",
    'icon' => $p['cover'] ? ['type' => 'Image', 'url' => $base . $p['cover']] : null,
    'image' => $p['banner'] ? ['type' => 'Image', 'url' => $base . $p['banner']] : null,
    'attachment' => [
      ['type' => 'PropertyValue', 'name' => 'RSS', 'value' => kp_canonical_feed_url($slug)],
    ],
    'publicKey' => [
      'id' => "$url#main-key",
      'owner' => $url,
      'publicKeyPem' => $kp['public_key'],
    ],
  ];
}

function kp_ap_actor(string $slug): void {
  $p = kp_one("SELECT * FROM podcasts WHERE slug = ? AND federate = 1", [$slug]);
  if (!$p) { http_response_code(404); exit; }
  kp_ap_json(kp_ap_make_actor_array($p));
}

function kp_ap_update_actor(int $podcastId): void {
  $p = kp_one("SELECT * FROM podcasts WHERE id = ?", [$podcastId]);
  if (!$p || !$p['federate']) return;

  $base = kp_ap_base();
  $actorUrl = "$base/users/{$p['slug']}";
  $actId = "$actorUrl/activities/update-" . bin2hex(random_bytes(8));

  $actorObj = kp_ap_make_actor_array($p);

  $activity = [
    '@context' => [
      'https://www.w3.org/ns/activitystreams',
      'https://w3id.org/security/v1'
    ],
    'id' => $actId,
    'type' => 'Update',
    'actor' => $actorUrl,
    'to' => ['https://www.w3.org/ns/activitystreams#Public'],
    'object' => $actorObj,
  ];

  $oid = kp_exec("INSERT INTO ap_outbox (podcast_id,activity_id,type,object_json) VALUES (?,?,?,?)",
    [$p['id'], $actId, 'Update', json_encode($activity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);

  kp_alert('fediverse', 'Perfil actualizado', "El perfil del podcast en el Fediverso se ha encolado para actualización.", "");

  // Encolar entrega a todos los seguidores
  $followers = kp_q("SELECT DISTINCT inbox FROM ap_followers WHERE podcast_id = ? AND inbox != ''", [$p['id']]);
  $ins = kp_db()->prepare("INSERT INTO ap_delivery (outbox_id, inbox) VALUES (?, ?)");
  foreach ($followers as $f) {
      $ins->execute([$oid, $f['inbox']]);
  }
  kp_ap_trigger_delivery();
}

// ---------------------------------------------------------------------------
// Outbox · OrderedCollection con la última publicación por episodio
// ---------------------------------------------------------------------------
function kp_ap_outbox(string $slug): void {
  $p = kp_one("SELECT * FROM podcasts WHERE slug = ?", [$slug]);
  if (!$p) { http_response_code(404); exit; }
  $items = kp_q("SELECT * FROM ap_outbox WHERE podcast_id = ? ORDER BY id DESC LIMIT 40", [$p['id']]);
  $total = (int)(kp_one("SELECT count(*) c FROM ap_outbox WHERE podcast_id = ?", [$p['id']])['c'] ?? 0);
  $url = kp_ap_base() . "/users/$slug/outbox";
  kp_ap_json([
    '@context' => 'https://www.w3.org/ns/activitystreams',
    'id' => $url, 'type' => 'OrderedCollection',
    'totalItems' => $total,
    'orderedItems' => array_map(fn($i) => json_decode($i['object_json'], true), $items),
  ]);
}

function kp_ap_followers(string $slug): void {
  $p = kp_one("SELECT * FROM podcasts WHERE slug = ?", [$slug]);
  if (!$p) { http_response_code(404); exit; }
  $rows = kp_q("SELECT actor_url FROM ap_followers WHERE podcast_id = ?", [$p['id']]);
  kp_ap_json([
    '@context' => 'https://www.w3.org/ns/activitystreams',
    'id' => kp_ap_base() . "/users/$slug/followers",
    'type' => 'OrderedCollection',
    'totalItems' => count($rows),
    'orderedItems' => array_column($rows, 'actor_url'),
  ]);
}

// ---------------------------------------------------------------------------
// Resolver episodio a partir de un targetLink (URL de nota, actividad, permalink, outbox, etc.)
// ---------------------------------------------------------------------------
function kp_ap_resolve_episode($podcast, ?string $targetLink): ?array {
  if (empty($targetLink)) return null;

  $podcastId = is_array($podcast) ? (int)($podcast['id'] ?? 0) : (is_numeric($podcast) ? (int)$podcast : 0);
  $podcastSlug = is_array($podcast) ? strtolower(trim($podcast['slug'] ?? '')) : (is_string($podcast) && !is_numeric($podcast) ? strtolower(trim($podcast)) : '');

  if (!$podcastId && $podcastSlug) {
    $pRow = kp_one("SELECT id, slug FROM podcasts WHERE LOWER(slug) = LOWER(?)", [$podcastSlug]);
    if ($pRow) {
      $podcastId = (int)$pRow['id'];
      $podcastSlug = strtolower(trim($pRow['slug']));
    }
  } elseif ($podcastId && !$podcastSlug) {
    $pRow = kp_one("SELECT id, slug FROM podcasts WHERE id = ?", [$podcastId]);
    if ($pRow) {
      $podcastSlug = strtolower(trim($pRow['slug'] ?? ''));
    }
  }

  if (!$podcastId) return null;

  $targetLink = trim($targetLink);
  $origTargetLink = $targetLink;

  // 1. Extraer posibles candidatos de slug desde la URL
  $candidateSlugs = [];

  // Patrón 1: /users/{pod}/notes/{slug} o /users/{pod}/activities/{slug}
  if (preg_match('#/users/[^/]+/(?:notes|activities)/([^/?#]+)#i', $targetLink, $m)) {
    $candidateSlugs[] = $m[1];
  }
  // Patrón 2: /notes/{slug} o /activities/{slug} (sin /users/)
  if (preg_match('#/(?:notes|activities)/([^/?#]+)#i', $targetLink, $m)) {
    $candidateSlugs[] = $m[1];
  }
  // Patrón 3: /@{pod}/{slug} o /embed/@{pod}/{slug}
  if (preg_match('#/(?:embed/)?@[^/]+/([^/?#]+)#i', $targetLink, $m)) {
    $candidateSlugs[] = $m[1];
  }
  // Patrón 4: Parámetros de consulta ?slug={slug} o ?ep={slug} o ?id={id}
  $parsedUrl = parse_url($targetLink);
  if (!empty($parsedUrl['query'])) {
    parse_str($parsedUrl['query'], $qp);
    if (!empty($qp['slug'])) $candidateSlugs[] = $qp['slug'];
    if (!empty($qp['ep'])) $candidateSlugs[] = $qp['ep'];
    if (!empty($qp['id'])) $candidateSlugs[] = $qp['id'];
  }
  // Patrón 5: Último segmento de ruta si la URL apunta a un path relativo o absoluto
  if (!empty($parsedUrl['path'])) {
    $pathSegments = array_values(array_filter(explode('/', trim($parsedUrl['path'], '/'))));
    if (!empty($pathSegments)) {
      $lastSeg = end($pathSegments);
      if ($lastSeg !== $podcastSlug && $lastSeg !== 'notes' && $lastSeg !== 'activities') {
        $candidateSlugs[] = $lastSeg;
      }
    }
  }

  // 2. Probar candidatos contra la tabla episodes
  foreach ($candidateSlugs as $raw) {
    if (!$raw) continue;
    $variants = [
      $raw,
      urldecode($raw),
      preg_replace('/-\d{9,11}$/', '', $raw),
      preg_replace('/-\d+$/', '', $raw),
    ];
    $variants = array_unique(array_filter($variants));
    foreach ($variants as $cand) {
      if ($cand === $podcastSlug) continue; // Ignorar perfil del podcast
      $ep = kp_one("SELECT * FROM episodes WHERE podcast_id = ? AND (LOWER(slug) = LOWER(?) OR id = ? OR guid = ?) LIMIT 1",
        [$podcastId, $cand, is_numeric($cand) ? (int)$cand : -1, $cand]);
      if ($ep) return $ep;
    }
  }

  // 3. Si no se resolvió por URL directa, buscar en ap_outbox si coincide con alguna actividad o nota publicada
  $outboxRows = kp_q("
    SELECT object_json FROM ap_outbox 
    WHERE podcast_id = ? AND (activity_id = ? OR activity_id = ? OR object_json LIKE ? OR object_json LIKE ?) 
    ORDER BY id DESC LIMIT 5
  ", [$podcastId, $origTargetLink, urldecode($origTargetLink), '%' . $origTargetLink . '%', '%' . urldecode($origTargetLink) . '%']) ?: [];

  foreach ($outboxRows as $outboxRow) {
    if (empty($outboxRow['object_json'])) continue;
    $outJson = json_decode($outboxRow['object_json'], true);
    if (!is_array($outJson)) continue;

    $candidatesFromOutbox = [];
    if (!empty($outJson['object']['url'])) $candidatesFromOutbox[] = $outJson['object']['url'];
    if (!empty($outJson['url'])) $candidatesFromOutbox[] = $outJson['url'];
    if (!empty($outJson['object']['id'])) $candidatesFromOutbox[] = $outJson['object']['id'];
    if (!empty($outJson['id'])) $candidatesFromOutbox[] = $outJson['id'];

    foreach ($candidatesFromOutbox as $outUrl) {
      if (preg_match('#/(?:embed/)?@[^/]+/([^/?#]+)#i', $outUrl, $nm) ||
          preg_match('#/(?:notes|activities)/([^/?#]+)#i', $outUrl, $nm)) {
        $cand = $nm[1];
        $candVariants = [
          $cand,
          urldecode($cand),
          preg_replace('/-\d{9,11}$/', '', $cand),
          preg_replace('/-\d+$/', '', $cand),
        ];
        $candVariants = array_unique(array_filter($candVariants));
        foreach ($candVariants as $cv) {
          if ($cv === $podcastSlug) continue;
          $ep = kp_one("SELECT * FROM episodes WHERE podcast_id = ? AND (LOWER(slug) = LOWER(?) OR id = ? OR guid = ?) LIMIT 1",
            [$podcastId, $cv, is_numeric($cv) ? (int)$cv : -1, $cv]);
          if ($ep) return $ep;
        }
      }
    }
  }

  return null;
}

// ---------------------------------------------------------------------------
// Inbox · recibe Follow/Undo/Like/Announce de otras instancias
// ---------------------------------------------------------------------------
function kp_ap_inbox(string $slug): void {
  $p = kp_one("SELECT * FROM podcasts WHERE slug = ?", [$slug]);
  if (!$p) { http_response_code(404); exit; }
  $raw = file_get_contents('php://input');
  $a = json_decode($raw, true);
  if (!$a || empty($a['type'])) { http_response_code(400); exit; }

  kp_ap_log("Inbox: Recibido POST para '$slug'. Tipo: " . $a['type'] . " | Actor: " . ($a['actor'] ?? 'desconocido'));

  // Verificar firma HTTP del emisor
  $sigVerified = kp_ap_verify_signature($raw);
  if (!$sigVerified) {
    $actorUrl = is_array($a['actor'] ?? null) ? ($a['actor']['id'] ?? '') : ($a['actor'] ?? '');
    kp_ap_log("Aviso: Firma HTTP no verificada para '$slug'. Tipo: " . ($a['type'] ?? '') . " | Actor: " . $actorUrl);
    
    // Si la firma falla, validar que el actor sea una URL válida
    if (empty($actorUrl) || !filter_var($actorUrl, FILTER_VALIDATE_URL)) {
      if ($a['type'] === 'Delete') {
        $obj = is_array($a['object'] ?? null) ? ($a['object']['id'] ?? '') : ($a['object'] ?? '');
        if ($actorUrl && $obj === $actorUrl) {
          $actorObj = kp_ap_fetch_actor($actorUrl);
          if ($actorObj === null || empty($actorObj['publicKey']) || ($actorObj['type'] ?? '') === 'Tombstone') {
            kp_db()->prepare("DELETE FROM ap_followers WHERE actor_url = ?")->execute([$actorUrl]);
            http_response_code(202);
            exit;
          }
        }
      }
      http_response_code(401);
      exit('signature invalid');
    }
  }

  switch ($a['type']) {
    case 'Follow':
      $actor = is_array($a['actor'] ?? null) ? ($a['actor']['id'] ?? '') : ($a['actor'] ?? '');
      $info = kp_ap_fetch_actor($actor);
      $handle = $info['preferredUsername'] ?? '?';
      $name = $info['name'] ?? $handle;
      $avatar = $info['icon']['url'] ?? '';
      kp_exec("INSERT OR IGNORE INTO ap_followers (podcast_id,actor_url,handle,inbox) VALUES (?,?,?,?)",
        [$p['id'], $actor, $handle, ($info['inbox'] ?? '')]);
      kp_ap_send_accept($p, $a);
      require_once __DIR__ . '/includes/helpers.php';
      kp_alert('fediverse', 'Nuevo seguidor', "@{$handle} ha comenzado a seguir a tu podcast '{$p['title']}'.", $actor, [
        'actor_url' => $actor, 'action_type' => 'Follow', 'actor_avatar' => $avatar, 'actor_name' => $name
      ]);
      break;
    case 'Undo':
      $undoObj = $a['object'] ?? [];
      $undoType = is_array($undoObj) ? ($undoObj['type'] ?? '') : '';
      $actor = is_array($a['actor'] ?? null) ? ($a['actor']['id'] ?? '') : ($a['actor'] ?? '');
      if ($undoType === 'Follow' || (is_string($undoObj) && str_contains($undoObj, 'followers'))) {
        kp_db()->prepare("DELETE FROM ap_followers WHERE podcast_id = ? AND actor_url = ?")
              ->execute([$p['id'], $actor]);
      } elseif ($undoType === 'Like') {
        $likedObj = is_array($undoObj) ? ($undoObj['object'] ?? '') : '';
        $targetLink = is_string($likedObj) ? $likedObj : ($likedObj['id'] ?? $likedObj['url'] ?? '');
        $ep = kp_ap_resolve_episode($p, $targetLink);
        if ($ep) {
          kp_db()->prepare("DELETE FROM ap_likes WHERE episode_id = ? AND actor_url = ?")->execute([$ep['id'], $actor]);
        }
        if (file_exists(__DIR__ . '/includes/cache.php')) {
          require_once __DIR__ . '/includes/cache.php';
          kp_cache_clear();
        }
      }
      break;
    case 'Like':
    case 'Announce':
      $actor = is_array($a['actor'] ?? null) ? ($a['actor']['id'] ?? '') : ($a['actor'] ?? '');
      $info = kp_ap_fetch_actor($actor);
      $handle = $info['preferredUsername'] ?? (preg_match('#@([^@/]+)$#', $actor, $hm) ? $hm[1] : (parse_url($actor, PHP_URL_PATH) ? basename(parse_url($actor, PHP_URL_PATH)) : 'user'));
      $name = $info['name'] ?? $handle;
      $avatar = $info['icon']['url'] ?? '';
      require_once __DIR__ . '/includes/helpers.php';
      
      $actionName = $a['type'] === 'Like' ? 'Me Gusta' : 'Boost';
      $bodyMsg = $a['type'] === 'Like' ? "Alguien le ha dado like a una publicación de" : "Alguien ha compartido una publicación de";
      
      $rawObj = $a['object'] ?? '';
      $targetLink = is_string($rawObj) ? $rawObj : ($rawObj['id'] ?? $rawObj['url'] ?? '');
      $ep = kp_ap_resolve_episode($p, $targetLink);
      $episodeId = $ep ? (int)$ep['id'] : null;

      if ($ep) {
        $targetLink = kp_ap_base() . "/@" . $p['slug'] . "/" . $ep['slug'];
      } else {
        // Si no pertenece a ningún episodio en particular (interacción con el perfil del podcast):
        // NO asociar a un episodio arbitrariamente. Mantener episodeId como null.
        $targetLink = $targetLink ?: (kp_ap_base() . "/@" . $p['slug']);
      }

      if ($a['type'] === 'Like' && $episodeId) {
        try {
          kp_exec("INSERT OR REPLACE INTO ap_likes (podcast_id, episode_id, actor_url, actor_name, actor_handle, actor_avatar, created_at) VALUES (?, ?, ?, ?, ?, ?, datetime('now'))",
            [$p['id'], $episodeId, $actor, $name, $handle, $avatar]);
          
          if (file_exists(__DIR__ . '/includes/cache.php')) {
            require_once __DIR__ . '/includes/cache.php';
            kp_cache_clear();
          }
        } catch (Throwable $e) {
          kp_ap_log("Error guardando Like en ap_likes: " . $e->getMessage());
        }
      }

      $alertBody = $episodeId 
        ? "$bodyMsg '{$p['title']}'." 
        : ($a['type'] === 'Like' ? "A alguien le gusta el podcast '{$p['title']}'." : "Alguien ha compartido el perfil del podcast '{$p['title']}'.");

      kp_alert('fediverse', "Nuevo $actionName", $alertBody, $targetLink, [
        'actor_url' => $actor, 'action_type' => $a['type'], 'actor_avatar' => $avatar, 'actor_name' => $name
      ]);
      break;
    case 'Create':
      if (($a['object']['type'] ?? '') === 'Note') {
        require_once __DIR__ . '/includes/helpers.php';
        $actor = $a['actor'];
        $info = kp_ap_fetch_actor($actor);
        $handle = $info['preferredUsername'] ?? '?';
        $name = $info['name'] ?? $handle;
        $avatar = $info['icon']['url'] ?? '';
        $objUrl = $a['object']['id'] ?? $a['object']['url'] ?? '';
        kp_alert('fediverse', 'Nuevo Comentario', "@{$handle} ha comentado en tu podcast.", $objUrl, [
          'actor_url' => $actor, 'action_type' => 'Reply', 'actor_avatar' => $avatar, 'actor_name' => $name
        ]);
        kp_ap_dm_admin($p, $objUrl, $handle);
      }
      break;
    case 'Delete':
      // Borrado remoto del actor: limpiar followers (solo si el objeto borrado es el actor)
      $obj = is_array($a['object'] ?? null) ? ($a['object']['id'] ?? '') : ($a['object'] ?? '');
      if ($obj === $a['actor']) {
        kp_db()->prepare("DELETE FROM ap_followers WHERE actor_url = ?")->execute([$a['actor']]);
      }
      break;
  }
  http_response_code(202);
}

// ---------------------------------------------------------------------------
// Publicar una nota nueva cuando un episodio se publica
// (llamar desde new-episode.php tras INSERT con status='published')
// ---------------------------------------------------------------------------
function kp_ap_publish_episode(int $episodeId, bool $resend = false): void {
  $e = kp_one("SELECT * FROM episodes WHERE id = ?", [$episodeId]);
  if (!$e) return;
  $p = kp_one("SELECT * FROM podcasts WHERE id = ?", [$e['podcast_id']]);
  if (!$p || !$p['federate']) return;

  $base = kp_ap_base();
  $actor = "$base/users/{$p['slug']}";
  $suffix = $resend ? '-' . time() : '';
  $noteId = "$actor/notes/{$e['slug']}$suffix";
  $actId = "$actor/activities/{$e['slug']}$suffix";

  // Evitar duplicados en el outbox si ya fue publicado/federado anteriormente
  $existing = kp_one("SELECT id FROM ap_outbox WHERE activity_id = ? LIMIT 1", [$actId]);
  if ($existing) return;

  $notes_md = $e['notes_md'] ?? '';
  $truncated_md = mb_substr($notes_md, 0, 400);
  if (mb_strlen($notes_md) > 400) {
      $truncated_md .= '...';
  }
  $notes_html = kp_md_to_html($truncated_md);

  $note = [
    'id' => $noteId, 'type' => 'Note',
    'attributedTo' => $actor,
    'to' => ['https://www.w3.org/ns/activitystreams#Public'],
    'cc' => ["$actor/followers"],
    'published' => gmdate('Y-m-d\TH:i:s\Z', strtotime($e['published_at'] ?: ($e['publish_at'] ?: ($e['created_at'] ?? 'now')))),
    'url' => "$base/@{$p['slug']}/{$e['slug']}",
    'content' => "<p><strong>" . htmlspecialchars($e['title']) . "</strong></p>"
                 . $notes_html
                 . "<p><a href=\"$base/@{$p['slug']}/{$e['slug']}\">Escuchar episodio</a></p>",
    'attachment' => $e['audio_url'] ? [[
      'type' => 'Audio',
      'mediaType' => $e['audio_mime'] ?: 'audio/mpeg',
      'url' => $base . $e['audio_url'],
      'name' => $e['title'],
    ]] : [],
  ];
  $activity = [
    '@context' => 'https://www.w3.org/ns/activitystreams',
    'id' => $actId, 'type' => 'Create',
    'actor' => $actor,
    'published' => $note['published'],
    'to' => $note['to'], 'cc' => $note['cc'],
    'object' => $note,
  ];

  try {
    $oid = kp_exec("INSERT INTO ap_outbox (podcast_id,activity_id,type,object_json) VALUES (?,?,?,?)",
      [$p['id'], $actId, 'Create', json_encode($activity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
  } catch (Throwable $e) {
    if (strpos($e->getMessage(), 'UNIQUE constraint failed') !== false) {
      return;
    }
    throw $e;
  }
  
  if ($resend) {
    kp_alert('fediverse', 'Episodio re-enviado al Fediverso', "El episodio '{$e['title']}' se ha vuelto a encolar para entrega.", "");
  } else {
    kp_alert('fediverse', 'Episodio publicado en Fediverso', "El episodio '{$e['title']}' se ha encolado para entrega.", "");
  }

  // Encolar entrega a cada follower
  $followers = kp_q("SELECT DISTINCT inbox FROM ap_followers WHERE podcast_id = ? AND inbox != ''", [$p['id']]);
  
  $ins = kp_db()->prepare("INSERT INTO ap_delivery (outbox_id, inbox) VALUES (?, ?)");
  foreach ($followers as $f) {
      $ins->execute([$oid, $f['inbox']]);
  }

  kp_ap_trigger_delivery();
}

// ---------------------------------------------------------------------------
// Eliminar un episodio publicado del Fediverso
// ---------------------------------------------------------------------------
function kp_ap_delete_episode($episodeOrId): void {
  if (is_array($episodeOrId)) {
    $e = $episodeOrId;
  } else {
    $e = kp_one("SELECT * FROM episodes WHERE id = ?", [(int)$episodeOrId]);
  }
  if (!$e) return;
  $p = kp_one("SELECT * FROM podcasts WHERE id = ?", [$e['podcast_id']]);
  if (!$p || empty($p['federate'])) return;

  $base = kp_ap_base();
  $actor = "$base/users/{$p['slug']}";
  $searchUrl = "$base/@{$p['slug']}/{$e['slug']}";
  $cleanTitle = trim(preg_replace('/\s*\([^)]*\)\s*$/', '', $e['title'] ?? ''));

  $outboxItems = kp_q("SELECT * FROM ap_outbox WHERE podcast_id = ? AND type = 'Create' ORDER BY id DESC", [$p['id']]);

  $notesToDelete = [];
  foreach ($outboxItems as $item) {
    $raw = $item['object_json'];
    $obj = json_decode($raw, true);
    if (!$obj || empty($obj['object'])) continue;

    $noteObj = $obj['object'];
    $noteId = is_string($noteObj) ? $noteObj : ($noteObj['id'] ?? null);
    if (!$noteId) continue;

    $matched = false;
    // 1. Coincidencia por URL canónica de episodio
    if (strpos($raw, $searchUrl) !== false) {
      $matched = true;
    }
    // 2. Coincidencia por slug de episodio en el identificador de la nota
    if (!empty($e['slug']) && strpos($noteId, "/notes/{$e['slug']}") !== false) {
      $matched = true;
    }
    // 3. Coincidencia por archivo de audio
    if (!empty($e['audio_url']) && strpos($raw, $e['audio_url']) !== false) {
      $matched = true;
    }
    // 4. Coincidencia por título exacto
    if (!empty($e['title']) && (strpos($raw, htmlspecialchars($e['title'])) !== false || strpos($raw, $e['title']) !== false)) {
      $matched = true;
    }
    // 5. Coincidencia por título base (sin sufijos tipo "(actualizado)")
    if (!empty($cleanTitle) && mb_strlen($cleanTitle) > 4 && (strpos($raw, htmlspecialchars($cleanTitle)) !== false || strpos($raw, $cleanTitle) !== false)) {
      $matched = true;
    }

    if ($matched) {
      $notesToDelete[$noteId] = true;
    }
  }

  // Fallback si no se encontró en ap_outbox
  if (empty($notesToDelete) && !empty($e['slug'])) {
    $notesToDelete["$actor/notes/{$e['slug']}"] = true;
  }

  if (empty($notesToDelete)) return;

  $followers = kp_q("SELECT DISTINCT inbox FROM ap_followers WHERE podcast_id = ? AND inbox != ''", [$p['id']]);
  $ins = kp_db()->prepare("INSERT INTO ap_delivery (outbox_id, inbox) VALUES (?, ?)");

  foreach (array_keys($notesToDelete) as $noteId) {
    $actId = "$actor/activities/" . bin2hex(random_bytes(8));
    $activity = [
      '@context' => 'https://www.w3.org/ns/activitystreams',
      'id' => $actId,
      'type' => 'Delete',
      'actor' => $actor,
      'to' => ['https://www.w3.org/ns/activitystreams#Public'],
      'object' => [
        'id' => $noteId,
        'type' => 'Tombstone'
      ]
    ];

    $oid = kp_exec("INSERT INTO ap_outbox (podcast_id,activity_id,type,object_json) VALUES (?,?,?,?)",
      [$p['id'], $actId, 'Delete', json_encode($activity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);

    foreach ($followers as $f) {
      $ins->execute([$oid, $f['inbox']]);
    }
  }

  kp_ap_trigger_delivery();
}

// ---------------------------------------------------------------------------
// Disparador de entrega en segundo plano / no bloqueante
// ---------------------------------------------------------------------------
function kp_ap_trigger_delivery(): void {
  $script = __DIR__ . '/cli/ap-deliver.php';
  if (file_exists($script) && function_exists('exec')) {
    @exec("php " . escapeshellarg($script) . " > /dev/null 2>&1 &");
    return;
  }
  if (function_exists('fastcgi_finish_request')) {
    @fastcgi_finish_request();
    @kp_ap_deliver_pending(50);
    return;
  }
  @kp_ap_deliver_pending(20);
}

// ---------------------------------------------------------------------------
// Worker de entrega · drena ap_delivery con backoff exponencial
// Invocar desde cron: php cli/ap-deliver.php
// ---------------------------------------------------------------------------
function kp_ap_deliver_pending(int $batch = 50): int {
  $rows = kp_q("SELECT * FROM ap_delivery 
                WHERE status = 'pending' AND next_try <= datetime('now', '+1 minute')
                ORDER BY id LIMIT ?", [$batch]);
  $sent = 0;
  foreach ($rows as $d) {
    try {
      $outbox = kp_one("SELECT podcast_id, object_json FROM ap_outbox WHERE id = ?", [$d['outbox_id']]);
      if (!$outbox) {
          kp_db()->prepare("UPDATE ap_delivery SET status='failed', last_error='Outbox item missing' WHERE id = ?")->execute([$d['id']]);
          continue;
      }

      kp_ap_post_signed($d['inbox'], $outbox['object_json'], (int)$outbox['podcast_id']);
      kp_db()->prepare("UPDATE ap_delivery SET status='delivered', delivered_at=datetime('now') WHERE id = ?")
            ->execute([$d['id']]);
      $sent++;
    } catch (Throwable $e) {
      $attempts = $d['attempts'] + 1;
      $backoff = min(86400, pow(2, $attempts) * 60); // hasta 1 día
      $next = gmdate('Y-m-d H:i:s', time() + $backoff);
      $status = $attempts >= 8 ? 'failed' : 'pending';
      kp_db()->prepare("UPDATE ap_delivery SET attempts=?, last_error=?, next_try=?, status=? WHERE id = ?")
            ->execute([$attempts, $e->getMessage(), $next, $status, $d['id']]);
    }
  }
  return $sent;
}

// ---------------------------------------------------------------------------
// HTTP Signatures (draft-cavage-12) · entregar firmado
// ---------------------------------------------------------------------------
function kp_ap_post_signed(string $url, string $body, int $podcastId): void {
  kp_ap_log("Entrega: Intentando enviar POST firmado a $url");
  $p = kp_one("SELECT slug FROM podcasts WHERE id = ?", [$podcastId]);
  $kp = kp_ap_keypair($podcastId);
  $u = parse_url($url);
  $date = gmdate('D, d M Y H:i:s') . ' GMT';
  $digest = 'SHA-256=' . base64_encode(hash('sha256', $body, true));
  $path = empty($u['path']) ? '/' : $u['path'];
  if (!empty($u['query'])) $path .= '?' . $u['query'];
  $signingString = "(request-target): post {$path}\nhost: {$u['host']}\ndate: $date\ndigest: $digest";
  openssl_sign($signingString, $sig, $kp['private_key'], OPENSSL_ALGO_SHA256);
  $keyId = kp_ap_base() . "/users/{$p['slug']}#main-key";
  $sigHeader = sprintf(
    'keyId="%s",algorithm="rsa-sha256",headers="(request-target) host date digest",signature="%s"',
    $keyId, base64_encode($sig)
  );

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST => 'POST',
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_HTTPHEADER => [
      "Host: {$u['host']}",
      "Date: $date",
      "Digest: $digest",
      "Signature: $sigHeader",
      "Content-Type: application/activity+json",
      "Accept: application/activity+json",
    ],
    CURLOPT_USERAGENT => 'KutPod/1.0 (+' . kp_ap_base() . ')',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_FAILONERROR => true,
  ]);
  $resp = curl_exec($ch);
  $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);
  if ($http >= 400 || $resp === false) {
    kp_ap_log("Entrega fallida a $url: HTTP $http $err. Respuesta: " . substr((string)$resp, 0, 300));
    throw new RuntimeException("HTTP $http $err");
  }
  kp_ap_log("Entrega exitosa a $url: HTTP $http");
}

function kp_ap_log(string $msg): void {
  $file = __DIR__ . '/storage/activitypub.log';
  $date = date('Y-m-d H:i:s');
  @file_put_contents($file, "[$date] $msg\n", FILE_APPEND);
}

function kp_ap_verify_signature(string $body): bool {
  $sig = $_SERVER['HTTP_SIGNATURE'] ?? '';
  if (!$sig && function_exists('getallheaders')) {
    $headers = getallheaders();
    foreach ($headers as $k => $v) {
      if (strtolower($k) === 'signature') {
        $sig = $v;
        break;
      }
    }
  }
  if (!$sig) {
    kp_ap_log("Firma fallida: Cabecera 'Signature' no encontrada. User-Agent: " . ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    return false;
  }
  $parts = [];
  foreach (explode(',', $sig) as $kv) {
    if (preg_match('/(\w+)="([^"]*)"/', trim($kv), $m)) $parts[$m[1]] = $m[2];
  }
  if (empty($parts['keyId']) || empty($parts['signature']) || empty($parts['headers'])) {
    kp_ap_log("Firma fallida: Componentes incompletos en cabecera: " . $sig);
    return false;
  }
  
  $actorUrl = preg_replace('/#.*/', '', $parts['keyId']);
  $actor = kp_ap_fetch_actor($actorUrl);
  if (empty($actor)) {
    kp_ap_log("Firma fallida: No se pudo obtener el actor desde $actorUrl");
    return false;
  }
  
  $pubKeyPem = $actor['publicKey']['publicKeyPem'] ?? $actor['publicKeyPem'] ?? '';
  if (empty($pubKeyPem) && !empty($actor['owner'])) {
    $ownerActor = kp_ap_fetch_actor($actor['owner']);
    $pubKeyPem = $ownerActor['publicKey']['publicKeyPem'] ?? $ownerActor['publicKeyPem'] ?? '';
  }
  if (empty($pubKeyPem)) {
    kp_ap_log("Firma fallida: El actor no expone una publicKeyPem en su perfil. Actor URL: $actorUrl");
    return false;
  }
  
  $signingString = [];
  foreach (explode(' ', $parts['headers']) as $h) {
    $h = strtolower(trim($h));
    if ($h === '(request-target)') {
      $method = strtolower($_SERVER['REQUEST_METHOD']);
      $uri = $_SERVER['REQUEST_URI'] ?? '/';
      $signingString[] = "(request-target): $method " . (parse_url($uri, PHP_URL_PATH) ?: '/');
    } elseif ($h === 'content-type') {
      $val = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
      $signingString[] = "$h: $val";
    } elseif ($h === 'content-length') {
      $val = $_SERVER['CONTENT_LENGTH'] ?? $_SERVER['HTTP_CONTENT_LENGTH'] ?? '';
      $signingString[] = "$h: $val";
    } else {
      $headerKey = 'HTTP_' . strtoupper(str_replace('-', '_', $h));
      $val = $_SERVER[$headerKey] ?? '';
      if (!$val && function_exists('getallheaders')) {
        $headers = getallheaders();
        foreach ($headers as $k => $v) {
          if (strtolower($k) === $h) {
            $val = $v;
            break;
          }
        }
      }
      $signingString[] = "$h: $val";
    }
  }
  
  $ok = openssl_verify(implode("\n", $signingString), base64_decode($parts['signature']),
    $pubKeyPem, OPENSSL_ALGO_SHA256);
  if ($ok !== 1) {
    kp_ap_log("Firma fallida: openssl_verify devolvió $ok. String firmado:\n" . implode("\n", $signingString) . "\nClave ID: " . $parts['keyId']);
    return false;
  }
  
  kp_ap_log("Firma válida para keyId: " . $parts['keyId']);
  return true;
}

function kp_ap_fetch_actor(string $url): ?array {
  if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) return null;
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 5,
    CURLOPT_HTTPHEADER => [
      'Accept: application/activity+json, application/ld+json; profile="https://www.w3.org/ns/activitystreams"'
    ],
    CURLOPT_USERAGENT => 'KutPod/1.0 (+' . kp_ap_base() . ')',
    CURLOPT_TIMEOUT => 10,
  ]);
  $r = curl_exec($ch); curl_close($ch);
  return $r ? json_decode($r, true) : null;
}

function kp_ap_resolve_webfinger(string $acct): ?array {
  $acct = ltrim(trim($acct), '@');
  $parts = explode('@', $acct);
  if (count($parts) !== 2) return null;
  $host = $parts[1];
  $url = "https://$host/.well-known/webfinger?resource=acct:$acct";
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Accept: application/jrd+json, application/json'],
    CURLOPT_USERAGENT => 'KutPod/1.0 (+' . kp_ap_base() . ')',
    CURLOPT_TIMEOUT => 10,
  ]);
  $r = curl_exec($ch); curl_close($ch);
  $res = $r ? json_decode($r, true) : null;
  
  if (!$res || empty($res['links'])) return null;
  foreach ($res['links'] as $l) {
    if ($l['rel'] === 'self' && $l['type'] === 'application/activity+json') {
      return kp_ap_fetch_actor($l['href']);
    }
  }
  return null;
}

function kp_ap_send_accept(array $p, array $follow): void {
  $base = kp_ap_base();
  $actor = "$base/users/{$p['slug']}";
  $accept = [
    '@context' => 'https://www.w3.org/ns/activitystreams',
    'id' => "$actor/accepts/" . bin2hex(random_bytes(8)),
    'type' => 'Accept', 'actor' => $actor, 'object' => $follow,
  ];
  $inbox = kp_ap_fetch_actor($follow['actor'])['inbox'] ?? null;
  if ($inbox) {
    try {
      $oid = kp_exec("INSERT INTO ap_outbox (podcast_id,activity_id,type,object_json) VALUES (?,?,?,?)", [$p['id'], $accept['id'], 'Accept', json_encode($accept, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
      kp_db()->prepare("INSERT INTO ap_delivery (outbox_id, inbox) VALUES (?, ?)")->execute([$oid, $inbox]);
    } catch (Throwable $e) {}
  }
}

function kp_ap_dm_admin(array $p, string $commentUrl, string $authorHandle): void {
  require_once __DIR__ . '/includes/helpers.php';
  $adminActor = kp_setting('admin_mastodon_actor');
  $adminInbox = kp_setting('admin_mastodon_inbox');
  if (!$adminActor || !$adminInbox) return;

  $base = kp_ap_base();
  $actor = "$base/users/{$p['slug']}";
  $noteId = "$actor/notes/dm_" . bin2hex(random_bytes(8));
  $actId = "$actor/activities/dm_" . bin2hex(random_bytes(8));

  $note = [
    'id' => $noteId, 'type' => 'Note',
    'attributedTo' => $actor,
    'to' => [$adminActor], // Direct message (Solo al admin)
    'cc' => [],
    'published' => gmdate('c'),
    'content' => "<p><span class=\"h-card\" translate=\"no\"><a href=\"{$adminActor}\" class=\"u-url mention\">@<span>admin</span></a></span> Tienes un nuevo comentario de <strong>@{$authorHandle}</strong> en tu podcast.</p><p><a href=\"{$commentUrl}\">Ver comentario original</a></p>",
    'tag' => [
      [
        'type' => 'Mention',
        'href' => $adminActor,
        'name' => '@admin' // Mastodon looks for this to correctly link the mention
      ]
    ]
  ];

  $activity = [
    '@context' => 'https://www.w3.org/ns/activitystreams',
    'id' => $actId, 'type' => 'Create',
    'actor' => $actor,
    'published' => $note['published'],
    'to' => $note['to'], 'cc' => $note['cc'],
    'object' => $note,
  ];

  $oid = kp_exec("INSERT INTO ap_outbox (podcast_id,activity_id,type,object_json) VALUES (?,?,?,?)",
    [$p['id'], $actId, 'Create', json_encode($activity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
    
  kp_db()->prepare("INSERT INTO ap_delivery (outbox_id, inbox) VALUES (?, ?)")->execute([$oid, $adminInbox]);
}

// ---------------------------------------------------------------------------
// Note · Permite a Mastodon consultar la nota original por ID
// ---------------------------------------------------------------------------
function kp_ap_note(string $slug, string $epSlug): void {
  $p = kp_one("SELECT * FROM podcasts WHERE slug = ?", [$slug]);
  if (!$p) { http_response_code(404); exit; }

  // Probar coincidencia exacta primero, luego quitando sufijo timestamp UNIX si fue re-publicado
  $e = kp_one("SELECT * FROM episodes WHERE podcast_id = ? AND (LOWER(slug) = LOWER(?) OR id = ?)", [$p['id'] ?? 0, $epSlug, is_numeric($epSlug) ? (int)$epSlug : -1]);
  if (!$e) {
    $cleanSlug = preg_replace('/-\d{9,11}$/', '', $epSlug);
    $e = kp_one("SELECT * FROM episodes WHERE podcast_id = ? AND (LOWER(slug) = LOWER(?) OR id = ?)", [$p['id'] ?? 0, $cleanSlug, is_numeric($cleanSlug) ? (int)$cleanSlug : -1]);
  }
  if (!$e) {
    $cleanSlug = preg_replace('/-\d+$/', '', $epSlug);
    $e = kp_one("SELECT * FROM episodes WHERE podcast_id = ? AND (LOWER(slug) = LOWER(?) OR id = ?)", [$p['id'] ?? 0, $cleanSlug, is_numeric($cleanSlug) ? (int)$cleanSlug : -1]);
  }
  if (!$e) { http_response_code(404); exit; }
  
  $base = kp_ap_base();
  $actor = "$base/users/{$p['slug']}";
  $noteId = "$actor/notes/{$epSlug}";
  
  $notes_md = $e['notes_md'] ?? '';
  $truncated_md = mb_substr($notes_md, 0, 400);
  if (mb_strlen($notes_md) > 400) {
      $truncated_md .= '...';
  }
  $notes_html = kp_md_to_html($truncated_md);

  $note = [
    '@context' => 'https://www.w3.org/ns/activitystreams',
    'id' => $noteId, 'type' => 'Note',
    'attributedTo' => $actor,
    'to' => ['https://www.w3.org/ns/activitystreams#Public'],
    'cc' => ["$actor/followers"],
    'published' => gmdate('Y-m-d\TH:i:s\Z', strtotime($e['published_at'] ?: 'now')),
    'url' => "$base/@{$p['slug']}/{$e['slug']}",
    'content' => "<p><strong>" . htmlspecialchars($e['title']) . "</strong></p>"
                 . $notes_html
                 . "<p><a href=\"$base/@{$p['slug']}/{$e['slug']}\">Escuchar episodio</a></p>",
    'attachment' => $e['audio_url'] ? [[
      'type' => 'Audio',
      'mediaType' => $e['audio_mime'] ?: 'audio/mpeg',
      'url' => $base . $e['audio_url'],
      'name' => $e['title'],
    ]] : [],
  ];
  kp_ap_json($note);
}

// ---------------------------------------------------------------------------
// Sincronizar likes históricos o pendientes desde alerts hacia ap_likes
// ---------------------------------------------------------------------------
function kp_ap_sync_likes_from_alerts(?int $podcastId = null, ?int $episodeId = null): int {
  $synced = 0;
  try {
    $alerts = kp_q("
      SELECT * FROM alerts 
      WHERE (action_type = 'Like' OR action_type = 'like' OR title LIKE '%Me Gusta%' OR title LIKE '%like%')
        AND actor_url IS NOT NULL AND actor_url != ''
      ORDER BY id ASC
    ") ?: [];

    if (empty($alerts)) return 0;

    $insLike = kp_db()->prepare("
      INSERT OR IGNORE INTO ap_likes (podcast_id, episode_id, actor_url, actor_name, actor_handle, actor_avatar, created_at)
      VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    $allPodcasts = kp_q("SELECT id, slug, title FROM podcasts") ?: [];
    $podMap = [];
    foreach ($allPodcasts as $pod) {
      $podMap[(int)$pod['id']] = $pod;
    }

    foreach ($alerts as $al) {
      $actorUrl = trim($al['actor_url'] ?? '');
      if (!$actorUrl) continue;
      $lnk = trim($al['link'] ?? '');
      if (!$lnk) continue;

      $targetPodcasts = ($podcastId && isset($podMap[$podcastId])) ? [$podMap[$podcastId]] : $allPodcasts;
      foreach ($targetPodcasts as $pod) {
        $ep = kp_ap_resolve_episode($pod, $lnk);
        if ($ep) {
          $epId = (int)$ep['id'];
          if ($episodeId && $epId !== $episodeId) {
            continue;
          }
          $exists = kp_one("SELECT id FROM ap_likes WHERE episode_id = ? AND actor_url = ?", [$epId, $actorUrl]);
          if (!$exists) {
            $handle = preg_match('#@([^@/]+)$#', $actorUrl, $hm) ? $hm[1] : (parse_url($actorUrl, PHP_URL_PATH) ? basename(parse_url($actorUrl, PHP_URL_PATH)) : 'user');
            $name = !empty($al['actor_name']) ? $al['actor_name'] : $handle;
            $avatar = !empty($al['actor_avatar']) ? $al['actor_avatar'] : '';
            $createdAt = !empty($al['created_at']) ? $al['created_at'] : gmdate('Y-m-d H:i:s');

            $insLike->execute([(int)$pod['id'], $epId, $actorUrl, $name, $handle, $avatar, $createdAt]);
            $synced++;
          }
          break;
        }
      }
    }
  } catch (Throwable $e) {
    kp_ap_log("Error en kp_ap_sync_likes_from_alerts: " . $e->getMessage());
  }

  return $synced;
}

// ---------------------------------------------------------------------------
// Obtener likes del Fediverso para un episodio
// ---------------------------------------------------------------------------
function kp_ap_get_episode_likes($episodeId): array {
  static $cache = [];
  $cacheKey = (string)$episodeId;
  if (isset($cache[$cacheKey])) {
    return $cache[$cacheKey];
  }
  
  $ep = null;
  if (is_numeric($episodeId) && (int)$episodeId > 0) {
    $ep = kp_one("SELECT id, slug, title, podcast_id FROM episodes WHERE id = ?", [(int)$episodeId]);
  }
  if (!$ep && !empty($episodeId)) {
    $ep = kp_one("SELECT id, slug, title, podcast_id FROM episodes WHERE slug = ? LIMIT 1", [(string)$episodeId]);
  }
  if (!$ep) {
    return [];
  }
  $numericEpId = (int)$ep['id'];
  $podcastId = (int)$ep['podcast_id'];

  // Si no hay likes en ap_likes para este episodio, intentar sincronizar de alerts
  $existingCount = (int)(kp_one("SELECT count(*) as c FROM ap_likes WHERE episode_id = ?", [$numericEpId])['c'] ?? 0);
  if ($existingCount === 0) {
    kp_ap_sync_likes_from_alerts($podcastId, $numericEpId);
  }

  $rows = kp_q("SELECT * FROM ap_likes WHERE episode_id = ? ORDER BY id DESC", [$numericEpId]) ?: [];

  // Asegurar que los avatares existentes se resuelvan si estaban vacíos
  foreach ($rows as &$r) {
    if (empty($r['actor_avatar']) && !empty($r['actor_url']) && function_exists('kp_ap_fetch_actor')) {
      try {
        $info = kp_ap_fetch_actor($r['actor_url']);
        if (!empty($info['icon']['url'])) {
          $r['actor_avatar'] = $info['icon']['url'];
          kp_db()->prepare("UPDATE ap_likes SET actor_avatar = ? WHERE id = ?")->execute([$r['actor_avatar'], $r['id']]);
        }
        if (!empty($info['name']) && empty($r['actor_name'])) {
          $r['actor_name'] = $info['name'];
          kp_db()->prepare("UPDATE ap_likes SET actor_name = ? WHERE id = ?")->execute([$r['actor_name'], $r['id']]);
        }
      } catch (Throwable $e) {}
    }
  }
  unset($r);

  $cache[$cacheKey] = $rows;
  return $rows;
}

// ---------------------------------------------------------------------------
// Limpieza de likes huérfanos o asignados erróneamente por fallos de sincronización
// ---------------------------------------------------------------------------
function kp_ap_cleanup_likes(): int {
  $cleaned = 0;
  try {
    $pdo = kp_db();
    // 1. Eliminar likes de episodios que ya no existen
    $cleaned += $pdo->exec("DELETE FROM ap_likes WHERE episode_id NOT IN (SELECT id FROM episodes)") ?: 0;

    // 2. Eliminar likes con podcast_id inconsistente con el del episodio
    $cleaned += $pdo->exec("
      DELETE FROM ap_likes 
      WHERE id IN (
        SELECT l.id 
        FROM ap_likes l 
        JOIN episodes e ON e.id = l.episode_id 
        WHERE l.podcast_id != e.podcast_id
      )
    ") ?: 0;

    // 3. Revisar coherencia de likes con las alertas registradas
    $likes = kp_q("
      SELECT l.id, l.podcast_id, l.episode_id, l.actor_url,
             e.slug AS ep_slug, p.slug AS pod_slug
      FROM ap_likes l
      JOIN episodes e ON e.id = l.episode_id
      JOIN podcasts p ON p.id = l.podcast_id
    ");

    if (!empty($likes)) {
      foreach ($likes as $like) {
        $actorUrl = $like['actor_url'];
        $alerts = kp_q("
          SELECT link 
          FROM alerts 
          WHERE actor_url = ? AND (action_type = 'Like' OR action_type = 'like' OR kind = 'fediverse')
        ", [$actorUrl]);

        if (!empty($alerts)) {
          $hasMatchForThisEp = false;
          $hasMatchForOtherEp = false;
          $isPodcastProfileLike = false;

          foreach ($alerts as $al) {
            $lnk = $al['link'] ?? '';
            if (!$lnk) continue;

            $resolved = kp_ap_resolve_episode((int)$like['podcast_id'], $lnk);
            if ($resolved) {
              if ((int)$resolved['id'] === (int)$like['episode_id']) {
                $hasMatchForThisEp = true;
              } else {
                $hasMatchForOtherEp = true;
              }
            } else {
              $podSlug = strtolower(trim($like['pod_slug']));
              $lnkLow = strtolower(urldecode($lnk));
              if (str_contains($lnkLow, '@' . $podSlug) || str_contains($lnkLow, '/users/' . $podSlug)) {
                $isPodcastProfileLike = true;
              }
            }
          }

          if (!$hasMatchForThisEp && ($hasMatchForOtherEp || $isPodcastProfileLike)) {
            $pdo->prepare("DELETE FROM ap_likes WHERE id = ?")->execute([$like['id']]);
            $cleaned++;
            continue;
          }
        }
      }
    }

    // 4. Sincronizar likes legítimos que existan en alerts
    kp_ap_sync_likes_from_alerts();

    if ($cleaned > 0 && file_exists(__DIR__ . '/includes/cache.php')) {
      require_once __DIR__ . '/includes/cache.php';
      kp_cache_clear();
    }
  } catch (Throwable $e) {
    kp_ap_log("Error en kp_ap_cleanup_likes: " . $e->getMessage());
  }

  return $cleaned;
}

// ---------------------------------------------------------------------------
// Mini-router (solo si se ejecuta directamente)
// ---------------------------------------------------------------------------
if ((isset($_SERVER['SCRIPT_FILENAME']) && realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'])) || isset($_GET['ap'])) {
  $ap = $_GET['ap'] ?? '';
  $slug = strtolower($_GET['slug'] ?? '');
  
  if (strpos($ap, 'notes/') === 0) {
    $_GET['ep'] = substr($ap, 6);
    $ap = 'note';
  }
  
  $is_get = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET';

  // Negociación de contenido: si la petición proviene de un navegador web humano (pide text/html
  // y no especifica ActivityPub JSON), redirigir a la vista web pública en vez de escupir JSON crudo
  $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
  $wants_html = str_contains($accept, 'text/html');
  $wants_ap = str_contains($accept, 'application/activity+json') || str_contains($accept, 'application/ld+json') || str_contains($accept, 'application/jrd+json');
  
  if ($is_get && $wants_html && !$wants_ap) {
    if ($ap === 'actor' || $ap === 'outbox' || $ap === 'followers') {
      header('Location: ' . kp_ap_base() . '/@' . $slug, true, 302);
      exit;
    }
    if ($ap === 'note') {
      $cleanEp = preg_replace('/-\d{9,11}$/', '', $_GET['ep'] ?? '');
      header('Location: ' . kp_ap_base() . '/@' . $slug . '/' . $cleanEp, true, 302);
      exit;
    }
  }

  $cache_key = 'ap_' . $ap . '_' . md5(json_encode($_GET));
  
  if ($is_get && $ap !== 'inbox' && $ap !== '') {
    require_once __DIR__ . '/includes/cache.php';
    $cached = kp_cache_get($cache_key);
    if ($cached) {
      header('Content-Type: ' . ($ap === 'webfinger' ? 'application/jrd+json' : 'application/activity+json') . '; charset=utf-8');
      echo is_array($cached) ? json_encode($cached, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $cached;
      exit;
    }
    ob_start();
  }

  switch ($ap) {
    case 'webfinger': kp_ap_webfinger(); break;
    case 'actor':     kp_ap_actor($slug); break;
    case 'outbox':    kp_ap_outbox($slug); break;
    case 'inbox':     kp_ap_inbox($slug); break;
    case 'followers': kp_ap_followers($slug); break;
    case 'note':      kp_ap_note($slug, $_GET['ep'] ?? ''); break;
    default: http_response_code(404); exit('AP endpoint desconocido');
  }

  if ($is_get && $ap !== 'inbox' && $ap !== '') {
    $out = ob_get_clean();
    kp_cache_set($cache_key, $out, 600); // 10 minutes TTL
    echo $out;
  }
}
