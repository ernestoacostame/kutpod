<?php
require_once __DIR__ . '/../includes/helpers.php';
$p = kp_find_podcast($_GET['id'] ?? '') ?? kp_podcasts()[0];
$eps = kp_episodes_of($p['id']);

$title = $p['title'];
$og_title = $p['title'];
$og_description = mb_strimwidth(trim(strip_tags($p['description'] ?? '')), 0, 200, '...');
$og_image = $p['cover'] ?? '';
$og_type = 'website';

// JSON-LD: PodcastSeries para Google Podcasts / buscadores
$domain_seo = kp_handle_domain();
$protocol_seo = kp_get_protocol();
$og_jsonld = [
    '@context' => 'https://schema.org',
    '@type' => 'PodcastSeries',
    'name' => $p['title'],
    'description' => trim(strip_tags($p['description'] ?? '')),
    'url' => "{$protocol_seo}://{$domain_seo}/@" . rawurlencode($p['id']),
    'author' => ['@type' => 'Person', 'name' => $p['author'] ?? ''],
    'webFeed' => "{$protocol_seo}://{$domain_seo}/feed/" . rawurlencode($p['id']) . ".xml",
];
if (!empty($p['cover'])) {
    $og_jsonld['image'] = "{$protocol_seo}://{$domain_seo}" . $p['cover'];
}

// Anclar trailers al inicio
usort($eps, function($a, $b) {
  $aIsTrailer = ($a['type'] ?? '') === 'trailer';
  $bIsTrailer = ($b['type'] ?? '') === 'trailer';
  if ($aIsTrailer && !$bIsTrailer) return -1;
  if (!$aIsTrailer && $bIsTrailer) return 1;
  return 0;
});

$seasons = array_unique(array_map(fn($e) => (int)($e['s'] ?? 1), $eps));
rsort($seasons);

$filterSeason = isset($_GET['s']) && $_GET['s'] !== '' ? (int)$_GET['s'] : null;
if ($filterSeason !== null) {
  $eps = array_values(array_filter($eps, fn($e) => (int)($e['s'] ?? 1) === $filterSeason));
}

$pageEp = max(1, (int)($_GET['p'] ?? 1));
$perPage = 10;
$totalEpsCount = count($eps);
$totalPages = max(1, ceil($totalEpsCount / $perPage));
$epsPage = array_slice($eps, ($pageEp - 1) * $perPage, $perPage);

require_once __DIR__ . '/../includes/platforms.php';
$broadcast_links = !empty($p['broadcast_links']) ? json_decode($p['broadcast_links'], true) : [];
$visible_platforms = array_filter($broadcast_links, fn($c) => !empty($c['visible']) && !empty($c['link']));

function get_public_platform_svg($type, $slug) {
    $path = __DIR__ . '/../public/assets/platforms/' . $type . '/' . $slug . '.svg';
    if (!file_exists($path)) $path = __DIR__ . '/../public/assets/platforms/' . $type . '/default.svg';
    if (file_exists($path)) return file_get_contents($path);
    return icon('link', 24);
}

// Validar acceso premium y token para mostrar/ocultar el botón RSS
$is_premium_show = (int)kp_get_podcast_meta($p, 'premium_enabled', 0);
$has_valid_token = false;
$tokenStr = trim($_GET['token'] ?? '');
if ($is_premium_show) {
    $podcast_id = $p['db_id'] ?? 0;
    if ($podcast_id === 0 && isset($p['id'])) {
        $found = kp_one("SELECT id FROM podcasts WHERE LOWER(slug) = LOWER(?)", [$p['id']]);
        $podcast_id = $found ? (int)$found['id'] : 0;
    }
    if ($podcast_id > 0 && !empty($tokenStr)) {
        $token = kp_one("SELECT id FROM premium_tokens WHERE token = ? AND podcast_id = ? AND status = 'active'", [$tokenStr, $podcast_id]);
        if ($token) {
            $has_valid_token = true;
        }
    }
    if (!function_exists('kp_current_user')) {
        require_once __DIR__ . '/../includes/auth.php';
    }
    $u = kp_current_user();
    $is_owner_admin = ($u && in_array($u['role'], ['owner', 'admin'], true));
} else {
    $is_owner_admin = false;
}
?>
<section class="pub-show-hero" style="background:linear-gradient(180deg,<?= e($p['color']) ?>1a 0%,transparent 100%)">
  <div class="pub-container">
    <a class="pub-link" href="/shows" style="margin-bottom:24px;display:inline-block">← Catálogo</a>
    <div style="display:grid;grid-template-columns:320px 1fr;gap:40px;align-items:start">
      <?php if ($p['cover']): ?>
        <img class="pub-show-cover" style="width:100%;aspect-ratio:1;border-radius:16px;object-fit:cover" src="<?= e($p['cover']) ?>" alt="">
      <?php else: ?>
        <div class="pub-show-cover" style="background:linear-gradient(135deg,<?= e($p['color']) ?>,<?= e($p['color']) ?>88);aspect-ratio:1;border-radius:16px">
          <span style="color:#fff;font-weight:800;font-size:72px;letter-spacing:-0.04em"><?= e($p['initial']) ?></span>
        </div>
      <?php endif; ?>
      <div>
        <div class="pub-eyebrow"><?= e($p['cat']) ?></div>
        <h1 class="pub-h1" style="margin-bottom:12px">
          <?= e($p['title']) ?>
          <?php if (($p['parental'] ?? 'clean') === 'explicit'): ?>
            <span style="display:inline-block;background:var(--border);color:var(--text-2);border-radius:4px;font-size:12px;padding:2px 6px;margin-left:6px;vertical-align:middle;line-height:1;font-weight:800">E</span>
          <?php endif; ?>
          <?php if ((int)kp_get_podcast_meta($p, 'premium_enabled', 0)): ?>
            <span style="display:inline-block;background:#10b981;color:#fff;border-radius:4px;font-size:12px;padding:2px 6px;margin-left:6px;vertical-align:middle;line-height:1;font-weight:800">$</span>
          <?php endif; ?>
        </h1>
        <?php kp_do_action('public_show_hero_meta', $p); ?>
        <p class="pub-show-desc" style="font-size:15.5px;color:var(--pub-text-2);line-height:1.65;max-width:620px;margin-bottom:20px"><?= nl2br($p['description'] ?: 'Sin descripción.') ?></p>
        <div class="row" style="gap:24px;font-size:13.5px;color:var(--pub-muted);margin-bottom:24px">
          <span><?= (int)$p['episodes'] ?> episodios</span><span>·</span><span><?= fmt_num($p['downloads']) ?> reproducciones</span>
        </div>
        
        <div class="row" style="gap:16px;flex-wrap:wrap;align-items:center">
          <?php if (!$is_premium_show || $has_valid_token || $is_owner_admin): ?>
            <?php 
              $feed_url = '/@' . e($p['id']) . '/feed.xml';
              if ($has_valid_token && !empty($tokenStr)) {
                  $feed_url .= '?token=' . urlencode($tokenStr);
              }
            ?>
            <a class="pub-btn pub-btn-primary" href="<?= e($feed_url) ?>"><?= icon('rss',13) ?> RSS</a>
          <?php endif; ?>
          
          <div class="row" style="gap:12px;align-items:center">
            <?php 
              $apple = $broadcast_links['apple'] ?? null;
              if ($apple && !empty($apple['link'])): 
            ?>
              <a href="<?= e($apple['link']) ?>" target="_blank" rel="noopener" title="Apple Podcasts" style="color:var(--pub-text);opacity:0.7;transition:all 0.2s;display:grid;place-items:center;width:24px;height:24px" onmouseover="this.style.opacity=1;this.style.color='<?= e($p['color']) ?>'" onmouseout="this.style.opacity=0.7;this.style.color='var(--pub-text)'">
                <div style="width:24px;height:24px;font-size:24px;display:grid;place-items:center"><?= get_public_platform_svg('podcasting', 'apple') ?></div>
              </a>
            <?php endif; ?>

            <?php 
              $spotify = $broadcast_links['spotify'] ?? null;
              if ($spotify && !empty($spotify['link'])): 
            ?>
              <a href="<?= e($spotify['link']) ?>" target="_blank" rel="noopener" title="Spotify" style="color:var(--pub-text);opacity:0.7;transition:all 0.2s;display:grid;place-items:center;width:24px;height:24px" onmouseover="this.style.opacity=1;this.style.color='<?= e($p['color']) ?>'" onmouseout="this.style.opacity=0.7;this.style.color='var(--pub-text)'">
                <div style="width:24px;height:24px;font-size:24px;display:grid;place-items:center"><?= get_public_platform_svg('podcasting', 'spotify') ?></div>
              </a>
            <?php endif; ?>

            <?php 
              $funding_platforms = array_filter($visible_platforms, fn($plat) => $plat['type'] === 'funding');
              if (!empty($funding_platforms)): 
            ?>
              <span style="color:var(--pub-muted);opacity:0.4;margin:0 4px">|</span>
              <?php foreach ($funding_platforms as $slug => $plat): 
                  $platData = \KutPod\KpPlatforms::DATA['funding'][$slug] ?? null;
              ?>
                <a href="<?= e($plat['link']) ?>" target="_blank" rel="noopener" title="<?= e($platData['label'] ?? $slug) ?>" style="color:var(--pub-text);opacity:0.7;transition:all 0.2s;display:grid;place-items:center;width:24px;height:24px" onmouseover="this.style.opacity=1;this.style.color='<?= e($p['color']) ?>'" onmouseout="this.style.opacity=0.7;this.style.color='var(--pub-text)'">
                  <div style="width:24px;height:24px;font-size:24px;display:grid;place-items:center"><?= get_public_platform_svg('funding', $slug) ?></div>
                </a>
              <?php endforeach; ?>
            <?php endif; ?>

            <?php 
              $social_platforms = array_filter($visible_platforms, fn($plat) => $plat['type'] === 'social');
              if (!empty($social_platforms)): 
            ?>
              <span style="color:var(--pub-muted);opacity:0.4;margin:0 4px">|</span>
              <?php foreach ($social_platforms as $slug => $plat): 
                  $platData = \KutPod\KpPlatforms::DATA['social'][$slug] ?? null;
                  $label = !empty($plat['account_id']) ? $plat['account_id'] : ($platData ? $platData['label'] : $slug);
              ?>
                <a href="<?= e($plat['link']) ?>" target="_blank" rel="noopener" title="<?= e($label) ?>" style="color:var(--pub-text);opacity:0.7;transition:all 0.2s;display:grid;place-items:center;width:24px;height:24px" onmouseover="this.style.opacity=1;this.style.color='<?= e($p['color']) ?>'" onmouseout="this.style.opacity=0.7;this.style.color='var(--pub-text)'">
                  <div style="width:24px;height:24px;font-size:24px;display:grid;place-items:center"><?= get_public_platform_svg('social', $slug) ?></div>
                </a>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>

        <?php 
        // Solo mostramos el resto de directorios de podcast abajo (Amazon, iVoox, etc.)
        $pod_directories = array_filter($visible_platforms, function($plat, $slug) {
            return $plat['type'] === 'podcasting' && !in_array($slug, ['apple', 'spotify']);
        }, ARRAY_FILTER_USE_BOTH);

        if (!empty($pod_directories)): 
        ?>
        <div class="row" style="gap:14px;flex-wrap:wrap;margin-top:24px">
            <?php foreach ($pod_directories as $slug => $plat): 
                $platData = \KutPod\KpPlatforms::DATA[$plat['type']][$slug] ?? null;
            ?>
            <a href="<?= e($plat['link']) ?>" target="_blank" rel="noopener" title="<?= e($platData['label'] ?? $slug) ?>" style="color:var(--pub-text);opacity:0.7;transition:opacity 0.2s" onmouseover="this.style.opacity=1;this.style.color='<?= e($p['color']) ?>'" onmouseout="this.style.opacity=0.7;this.style.color='var(--pub-text)'">
                <div style="width:28px;height:28px;font-size:28px;display:grid;place-items:center">
                    <?= get_public_platform_svg($plat['type'], $slug) ?>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>

<section class="pub-section"><div class="pub-container">
  <div class="row" style="justify-content:space-between;align-items:center;margin-bottom:24px;flex-wrap:wrap;gap:16px">
    <h2 class="pub-h2" style="margin:0">Episodios</h2>
    <?php if (count($seasons) > 1): ?>
    <select style="padding:6px 12px;border-radius:8px;border:1px solid var(--pub-border);background:var(--pub-surface);color:var(--pub-text);font-family:inherit;font-size:14px;cursor:pointer" onchange="window.location.href='/@<?= e($p['id']) ?>?p=1'+(this.value?'&s='+this.value:'')">
      <option value="">Todas las temporadas</option>
      <?php foreach ($seasons as $seasonNum): ?>
        <option value="<?= $seasonNum ?>" <?= $filterSeason === $seasonNum ? 'selected' : '' ?>>Temporada <?= $seasonNum ?></option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>
  </div>
  <div class="pub-ep-list">
    <?php foreach ($epsPage as $ep): ?>
      <article class="pub-ep-row">
        <?php 
        $play_btn = '<button class="pub-ep-play-circle" style="background:'.e($p['color']).'"
                data-kp-play data-src="/r/'.urlencode($p['id']).'/'.urlencode($ep['id']).'/audio.mp3"
                data-title="'.e($ep['title']).'" data-podcast="'.e($p['title']).'"
                data-color="'.e($p['color']).'" data-initial="'.e($p['initial']).'"
                data-cover="'.e($ep['cover'] ?: ($p['cover'] ?? '')).'">
          ' . icon('play',14) . '
        </button>';
        echo kp_apply_filters('public_show_episode_play_button', $play_btn, $ep, $p);
        ?>
        <a style="flex:1;min-width:0;text-decoration:none;color:inherit" href="/@<?= e($p['id']) ?>/<?= e($ep['id']) ?>">
          <div style="margin-bottom:6px;display:flex;gap:8px;align-items:center">
            <?php if (($ep['type'] ?? '') === 'trailer'): ?>
              <span class="badge-promo">Trailer</span>
            <?php elseif (($ep['type'] ?? '') === 'bonus'): ?>
              <span class="badge-extra">Bonus</span>
            <?php else: ?>
              <?php if ((int)($ep['s'] ?? 1) === 0): ?>
                <span class="badge-number">Episodio <?= (int)$ep['n'] ?></span>
              <?php else: ?>
                <span class="badge-number" style="background:none;border-color:transparent;color:var(--pub-muted);padding:0">S<?= (int)$ep['s'] ?> · E<?= (int)$ep['n'] ?></span>
              <?php endif; ?>
            <?php endif; ?>
          </div>
          <h3 class="pub-ep-title" style="display:inline-flex;align-items:center;flex-wrap:wrap;gap:4px">
            <?= e($ep['title']) ?>
            <?php if (($p['parental'] ?? 'clean') === 'explicit'): ?>
              <span style="display:inline-block;background:var(--border);color:var(--text-2);border-radius:4px;font-size:9px;padding:1px 3px;line-height:1;font-weight:800">E</span>
            <?php endif; ?>
            <?php if ((int)kp_get_podcast_meta($p, 'premium_enabled', 0) || (int)kp_get_episode_meta($ep, 'premium_enabled', 0)): ?>
              <span style="display:inline-block;background:#10b981;color:#fff;border-radius:4px;font-size:9px;padding:1px 3px;line-height:1;font-weight:800">$</span>
            <?php endif; ?>
          </h3>
          <?php if (!empty($ep['notes_md'])): ?>
            <p style="font-size:13.5px;color:var(--pub-text-2);margin:4px 0 0;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;line-height:1.4;opacity:0.8"><?= e(mb_strimwidth(strip_tags($ep['notes_md']), 0, 200, '...')) ?></p>
          <?php endif; ?>
          <div style="font-size:13px;color:var(--pub-muted);margin-top:6px"><?= e($ep['duration']) ?> · <span title="" data-kp-date="<?= e($ep['date_iso']) ?>">Publicado hace: <?= kp_relative_time($ep['date_iso']) ?></span></div>
        </a>
        <div class="tabular" style="font-size:13px;color:var(--pub-muted)"><?= e($ep['duration']) ?></div>
      </article>
    <?php endforeach; ?>
  </div>
  <?php if ($totalPages > 1): ?>
    <div class="row" style="padding-top:32px;justify-content:center;gap:6px">
      <?php 
      $sParam = $filterSeason ? '&s=' . $filterSeason : '';
      $window = 2;
      for ($i = 1; $i <= $totalPages; $i++): 
        if ($i == 1 || $i == $totalPages || ($i >= $pageEp - $window && $i <= $pageEp + $window)):
      ?>
        <a href="/@<?= e($p['id']) ?>?p=<?= $i ?><?= $sParam ?>" class="pub-btn <?= $i === $pageEp ? 'pub-btn-primary' : 'pub-btn-ghost' ?>" style="padding:6px 12px;font-weight:600"><?= $i ?></a>
      <?php elseif ($i == $pageEp - $window - 1 || $i == $pageEp + $window + 1): ?>
        <span style="padding:6px 4px;color:var(--pub-muted)">...</span>
      <?php endif; ?>
      <?php endfor; ?>
    </div>
  <?php endif; ?>
</div></section>
