<?php
// ============================================================================
// KutPod · generador de feed RSS 2.0 · podcasting 2.0
// ============================================================================
// URL pública: /@{slug}/feed.xml  (rewrite a feed.php?slug=...)
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/cache.php';
require_once __DIR__ . '/data.php';

kp_check_scheduled();

$slug = strtolower(preg_replace('/[^a-z0-9\-]/i', '', $_GET['slug'] ?? ''));

// 1. Buscar por slug actual
$p = kp_one("SELECT * FROM podcasts WHERE LOWER(slug) = ? AND hidden = 0", [$slug]);
if ($p) {
    // Si vinieron por la ruta vieja /feed/slug.xml, redireccionar con 301 a /@slug/feed.xml
    if (preg_match('#^/feed/#i', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) {
        $query = $_SERVER['QUERY_STRING'] ?? '';
        header('Location: /@' . $p['slug'] . '/feed.xml' . ($query !== '' ? '?' . $query : ''), true, 301);
        exit;
    }
} else {
    // 2. Buscar por slug de redirección
    $p = kp_one("SELECT * FROM podcasts WHERE feed_redirect_slug IS NOT NULL AND feed_redirect_slug != '' AND LOWER(feed_redirect_slug) = ? AND hidden = 0", [$slug]);
    if ($p) {
        $query = $_SERVER['QUERY_STRING'] ?? '';
        header('Location: /@' . $p['slug'] . '/feed.xml' . ($query !== '' ? '?' . $query : ''), true, 301);
        exit;
    }
}

// 3. Si no existe, redirigir o mostrar 404
if (!$p) {
    if (preg_match('#^/feed/#i', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) {
        header('Location: /feed/' . rawurlencode($slug), true, 302);
        exit;
    }
    http_response_code(404);
    exit('Feed no encontrado');
}

// Gancho para interceptar la petición de feed RSS (ej: verificar tokens)
kp_do_action('feed_rss_init', $p);

$tokenStr = trim($_GET['token'] ?? '');
$cache_key = 'rss_feed_' . $p['id'] . '_' . md5($tokenStr);
$cached_xml = kp_cache_get($cache_key);
if ($cached_xml) {
    header('Content-Type: application/rss+xml; charset=utf-8');
    echo $cached_xml;
    exit;
}

function xml_sanitize(?string $str): string {
    if ($str === null) return '';
    // Remove invalid XML control characters
    return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $str);
}

$base = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'www.tupodcast.com');
// Tracker nativo · cada enclosure pasa por /r/{show}/{ep}/audio.ext
// Si op3 está OFF en el podcast, el tracker hace 302 directo sin loggear.
$tracker = $base . '/r/' . rawurlencode($p['slug']) . '/';
$guid = !empty($p['guid']) ? $p['guid'] : kp_deterministic_podcast_guid(kp_canonical_feed_url($p['slug']));
if (!empty($p['op3'])) {
    $tracker = 'https://op3.dev/e,pg=' . $guid . '/' . preg_replace('#^https?://#', '', $tracker);
}

$order = ($p['type'] ?? 'episodic') === 'serial' ? 'ASC' : 'DESC';
$now_utc = gmdate('Y-m-d H:i:s');
$episodes = kp_q("SELECT * FROM episodes
                  WHERE podcast_id = ? 
                    AND (status = 'published' OR (status = 'scheduled' AND COALESCE(publish_at, created_at) <= ?))
                    AND hidden = 0
                  ORDER BY COALESCE(publish_at, created_at) $order", [$p['id'], $now_utc]);

// Filtrar los episodios antes de renderizarlos (ej: ocultar premium si no tiene acceso)
$episodes = kp_apply_filters('feed_rss_episodes', $episodes, $p);


header('Content-Type: application/rss+xml; charset=utf-8');
ob_start();
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<rss version="2.0"
     xmlns:atom="http://www.w3.org/2005/Atom"
     xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd"
     xmlns:content="http://purl.org/rss/1.0/modules/content/"
     xmlns:podcast="https://podcastindex.org/namespace/1.0">
<channel>
  <title><?= e($p['title']) ?></title>
  <link><?= e($base) ?>/@<?= e($p['slug']) ?></link>
  <?php
    $self_url = kp_canonical_feed_url($p['slug']);
    if (!empty($tokenStr)) {
        $self_url .= (strpos($self_url, '?') !== false ? '&' : '?') . 'token=' . urlencode($tokenStr);
    }
  ?>
  <atom:link href="<?= e($self_url) ?>" rel="self" type="application/rss+xml"/>
  <?php if (!empty($guid)): ?>
  <podcast:guid><?= e($guid) ?></podcast:guid>
  <?php endif; ?>
  <language><?= e($p['language']) ?></language>
  <description><?= e($p['description'] ?? '') ?></description>
  <itunes:summary><?= e($p['description'] ?? '') ?></itunes:summary>
  <itunes:author><?= e($p['author'] ?? '') ?></itunes:author>
  <itunes:type><?= e($p['type']) ?></itunes:type>
  <itunes:explicit><?= $p['parental'] === 'explicit' ? 'true' : 'false' ?></itunes:explicit>
  <itunes:category text="<?= e($p['category'] ?? '') ?>"/>
  <?php if (!$p['remove_email'] && $p['owner_email']): ?>
  <itunes:owner>
    <itunes:name><?= e($p['author'] ?? '') ?></itunes:name>
    <itunes:email><?= e($p['owner_email']) ?></itunes:email>
  </itunes:owner>
  <?php endif; ?>
  <?php if ($p['cover']): ?><itunes:image href="<?= e($base . $p['cover']) ?>"/><?php endif; ?>
  <copyright><?= e($p['copyright'] ?? '') ?></copyright>
  <?php if ($p['fediverse_handle']): ?>
  <podcast:social platform="activitypub" url="<?= e($base) ?>/@<?= e($p['fediverse_handle']) ?>" priority="1"/>
  <?php endif; ?>
  <?php if ($p['lat'] && $p['lon']): ?>
  <podcast:location osmid="<?= e($p['osm_id']) ?>" geo="geo:<?= e($p['lat']) ?>,<?= e($p['lon']) ?>"><?= e($p['location_name']) ?></podcast:location>
  <?php endif; ?>
  <?php if ($p['locked']): ?><podcast:locked>yes</podcast:locked><?php endif; ?>
  <?php if ($p['complete']): ?><itunes:complete>yes</itunes:complete><?php endif; ?>
  <?php 
    require_once __DIR__ . '/includes/platforms.php';
    $broadcast_links = !empty($p['broadcast_links']) ? json_decode($p['broadcast_links'], true) : [];
    foreach ($broadcast_links as $slug => $plat) {
        if (empty($plat['visible']) || empty($plat['link'])) continue;
        if ($plat['type'] === 'funding') {
            $label = \KutPod\KpPlatforms::DATA['funding'][$slug]['label'] ?? $slug;
            echo "  <podcast:funding url=\"" . e($plat['link']) . "\">" . e($label) . "</podcast:funding>\n";
        } else {
            echo "  <podcast:social platform=\"" . e($slug) . "\" url=\"" . e($plat['link']) . "\"/>\n";
        }
    }
  ?>
  <?php kp_do_action('feed_rss_channel', $p); ?>
  <?php
    // SEC-01: Sanitizar custom_tags para prevenir inyección XML
    $channel_custom = $p['custom_tags'] ?? '';
    if ($channel_custom !== '') {
        // Eliminar scripts, event handlers y contenido peligroso
        $channel_custom = preg_replace('/<\s*script[^>]*>.*?<\s*\/\s*script\s*>/is', '', $channel_custom);
        $channel_custom = preg_replace('/\bon\w+\s*=\s*["\'][^"\']*["\']/i', '', $channel_custom);
    }
  ?>
  <?= $channel_custom ?>

  <?php foreach ($episodes as $e): ?>
  <item>
    <title><?= e(xml_sanitize($e['title'])) ?></title>
    <guid isPermaLink="false"><?= e(xml_sanitize($e['guid'])) ?></guid>
    <link><?= e($base) ?>/@<?= e($p['slug']) ?>/<?= e($e['slug']) ?></link>
    <pubDate><?= e(date(DATE_RSS, kp_db_strtotime($e['published_at'] ?? $e['created_at']))) ?></pubDate>
    <description><?= e(xml_sanitize(strip_tags(kp_md_to_html($e['notes_md'] ?? '')))) ?></description>
    <itunes:summary><?= e(xml_sanitize(strip_tags(kp_md_to_html($e['notes_md'] ?? '')))) ?></itunes:summary>
    <?php
      $ep_content = kp_md_to_html($e['notes_md'] ?? '');
      $ep_fixed = trim($p['fixed_notes'] ?? '');
      if ($ep_fixed) {
        $ep_content .= "\n\n<hr>\n\n" . kp_md_to_html($ep_fixed);
      }
      $ep_content = xml_sanitize($ep_content);
    ?>
    <content:encoded><![CDATA[<?= str_replace(']]>', ']]]]><![CDATA[>', $ep_content) ?>]]></content:encoded>
    <?php if ($e['audio_url']): 
      $enc_url = $tracker . rawurlencode($e['slug']) . '/' . basename($e['audio_url']);
      $enc_url = kp_apply_filters('feed_rss_enclosure_url', $enc_url, $e, $p);
    ?>
    <enclosure url="<?= e($enc_url) ?>"
               length="<?= (int)$e['audio_bytes'] ?>"
               type="<?= e($e['audio_mime'] ?? 'audio/mpeg') ?>"/>
    <?php endif; ?>
    <itunes:duration><?= (int)$e['duration_secs'] ?></itunes:duration>
    <itunes:episodeType><?= e($e['ep_type']) ?></itunes:episodeType>
    <?php if ($e['season'] && (int)$e['season'] > 0 && $e['ep_type'] !== 'bonus' && $e['ep_type'] !== 'trailer'): ?><itunes:season><?= (int)$e['season'] ?></itunes:season><?php endif; ?>
    <?php if ($e['number'] && $e['ep_type'] !== 'bonus' && $e['ep_type'] !== 'trailer'): ?><itunes:episode><?= (int)$e['number'] ?></itunes:episode><?php endif; ?>
    <itunes:explicit><?= $e['parental'] === 'explicit' ? 'true' : 'false' ?></itunes:explicit>
    <?php 
      $item_cover = $e['cover'] ?: ($p['cover'] ?? '');
      if ($item_cover): 
    ?>
    <itunes:image href="<?= e($base . $item_cover) ?>"/>
    <?php endif; ?>
    <?php if ($e['transcript_url']): ?>
    <podcast:transcript url="<?= e($base . $e['transcript_url']) ?>" type="application/x-subrip"/>
    <?php endif; ?>
    <?php if ($e['chapters_url']): ?>
    <podcast:chapters url="<?= e($base . $e['chapters_url']) ?>" type="application/json+chapters"/>
    <?php endif; ?>
    <?php if ($e['lat'] && $e['lon']): ?>
    <podcast:location geo="geo:<?= e($e['lat']) ?>,<?= e($e['lon']) ?>"><?= e($e['location_name']) ?></podcast:location>
    <?php endif; ?>
    <?php 
      if (!empty($e['persons_json'])) {
        $persons = json_decode($e['persons_json'], true);
        if (is_array($persons)) {
          foreach ($persons as $p_j) {
            $r = $p_j['role'] ?? 'guest';
            $img = !empty($p_j['avatar']) ? ' img="' . e($base . $p_j['avatar']) . '"' : '';
            $href = !empty($p_j['url']) ? ' href="' . e($p_j['url']) . '"' : '';
            echo "    <podcast:person role=\"" . e($r) . "\"{$img}{$href}>" . e($p_j['name']) . "</podcast:person>\n";
          }
        }
      }
    ?>
    <?php kp_do_action('feed_rss_item', $e, $p); ?>
    <?php
      $item_custom = $e['custom_tags'] ?? '';
      if ($item_custom !== '') {
          $item_custom = preg_replace('/<\s*script[^>]*>.*?<\s*\/\s*script\s*>/is', '', $item_custom);
          $item_custom = preg_replace('/\bon\w+\s*=\s*["\'][^"\']*["\']/i', '', $item_custom);
      }
    ?>
    <?= $item_custom ?>
  </item>
  <?php endforeach; ?>
</channel>
</rss>
<?php
$final_xml = ob_get_clean();
$final_xml = kp_apply_filters('feed_rss_xml', $final_xml, $p);
kp_cache_set($cache_key, $final_xml, 300); // 5 minutes TTL
echo $final_xml;
