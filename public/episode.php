<?php
require_once __DIR__ . '/../includes/helpers.php';
$ep = kp_find_episode($_GET['ep'] ?? $_GET['id'] ?? '', $_GET['show'] ?? '') ?? kp_episodes()[0];
$p = kp_podcast_or_placeholder($ep['podcast'] ?? '');

$title = $ep['title'] . ' - ' . $p['title'];
$og_title = $ep['title'];

// Get description: strip HTML and markdown formatting to get clean plain text
$og_desc_raw = $ep['notes_md'] ?? '';
$og_desc_raw = preg_replace('/\[(.+?)\]\((.+?)\)/', '$1', $og_desc_raw); // Links
$og_desc_raw = preg_replace('/\*\*(.+?)\*\*/', '$1', $og_desc_raw); // Bold
$og_desc_raw = preg_replace('/\*(.+?)\*/', '$1', $og_desc_raw); // Italic
$og_desc_raw = preg_replace('/`([^`]+)`/', '$1', $og_desc_raw); // Code
$og_desc_raw = preg_replace('/^#+\s+(.+)$/m', '$1', $og_desc_raw); // Headers
$og_desc_raw = preg_replace('/^>\s+(.+)$/m', '$1', $og_desc_raw); // Blockquotes
$og_desc_raw = preg_replace('/^- (.+)$/m', '$1', $og_desc_raw); // Lists
$og_desc_raw = strip_tags($og_desc_raw);
$og_description = mb_strimwidth(trim($og_desc_raw), 0, 200, '...');

$og_image = $ep['cover'] ?: ($p['cover'] ?? '');
$og_type = 'music.song';

// JSON-LD: PodcastEpisode para buscadores
$domain_seo = kp_handle_domain();
$protocol_seo = kp_get_protocol();
$dur_secs = (int)($ep['duration_secs'] ?? 0);
$dur_iso = 'PT' . ($dur_secs > 0 ? intdiv($dur_secs, 3600) . 'H' . intdiv($dur_secs % 3600, 60) . 'M' . ($dur_secs % 60) . 'S' : '0S');
$og_jsonld = [
    '@context' => 'https://schema.org',
    '@type' => 'PodcastEpisode',
    'name' => $ep['title'],
    'description' => $og_description,
    'url' => "{$protocol_seo}://{$domain_seo}/@" . rawurlencode($ep['podcast']) . '/' . rawurlencode($ep['id']),
    'datePublished' => $ep['date_iso'] ?? '',
    'timeRequired' => $dur_iso,
    'partOfSeries' => [
        '@type' => 'PodcastSeries',
        'name' => $p['title'],
        'url' => "{$protocol_seo}://{$domain_seo}/@" . rawurlencode($p['id']),
    ],
];
if (!empty($ep['audio_url'])) {
    $og_jsonld['associatedMedia'] = [
        '@type' => 'MediaObject',
        'contentUrl' => "{$protocol_seo}://{$domain_seo}/r/" . rawurlencode($p['id']) . '/' . rawurlencode($ep['id']) . '/audio.mp3',
    ];
}
if (!empty($og_image)) {
    $img_url = $og_image;
    if (!preg_match('#^https?://#i', $img_url)) {
        $img_url = "{$protocol_seo}://{$domain_seo}" . $img_url;
    }
    $og_jsonld['image'] = $img_url;
}
?>
<section class="pub-section"><div class="pub-container" style="max-width:820px">
  <a class="pub-link" href="/@<?= e($p['id']) ?>" style="margin-bottom:24px;display:inline-block">← <?= e($p['title']) ?></a>
  <?php
    $ep_cover = $ep['cover'] ?: ($p['cover'] ?? '');
  ?>
  <div class="pub-ep-layout">
    <?php if ($ep_cover): ?>
    <img src="<?= e($ep_cover) ?>" alt="<?= e($ep['title']) ?>" class="pub-ep-layout-cover">
    <?php else: ?>
    <div class="pub-ep-layout-cover pub-ep-layout-cover-placeholder" style="background:linear-gradient(135deg,<?= e($p['color']) ?>,<?= e($p['color']) ?>88);">
      <span style="color:rgba(255,255,255,0.35);font-weight:800;font-size:60px;letter-spacing:-0.04em"><?= e($p['initial']) ?></span>
    </div>
    <?php endif; ?>
    <div class="pub-ep-layout-info">
      <div class="pub-eyebrow" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">
        <span><?= e($p['title']) ?></span>
        <span style="opacity:0.5">·</span>
        <?php if (($ep['type'] ?? '') === 'trailer'): ?>
          <span class="badge-promo">Trailer</span>
        <?php elseif (($ep['type'] ?? '') === 'bonus'): ?>
          <span class="badge-extra">Bonus</span>
        <?php else: ?>
          <?php if ((int)($ep['s'] ?? 1) === 0): ?>
            <span class="badge-number">Episodio <?= (int)$ep['n'] ?></span>
          <?php else: ?>
            <span class="badge-number">S<?= (int)$ep['s'] ?> · E<?= (int)$ep['n'] ?></span>
          <?php endif; ?>
        <?php endif; ?>
      </div>
      <h1 class="pub-h1" style="margin-bottom:14px;font-size:1.6em;display:inline-flex;align-items:center;flex-wrap:wrap;gap:6px">
        <?= e($ep['title']) ?>
        <?php if (($p['parental'] ?? 'clean') === 'explicit'): ?>
          <span style="display:inline-block;background:var(--border);color:var(--text-2);border-radius:4px;font-size:12px;padding:2px 6px;line-height:1;font-weight:800">E</span>
        <?php endif; ?>
        <?php if ((int)kp_get_podcast_meta($p, 'premium_enabled', 0) || (int)kp_get_episode_meta($ep, 'premium_enabled', 0)): ?>
          <span style="display:inline-block;background:#10b981;color:#fff;border-radius:4px;font-size:12px;padding:2px 6px;line-height:1;font-weight:800">$</span>
        <?php endif; ?>
      </h1>
      <div class="row" style="gap:18px;font-size:13.5px;color:var(--pub-muted);margin-bottom:20px">
        <span><?= e($ep['duration']) ?></span><span>·</span><span title="" data-kp-date="<?= e($ep['date_iso']) ?>">Publicado hace: <?= kp_relative_time($ep['date_iso']) ?></span>
      </div>
      <?php
      ob_start();
      ?>
      <div class="pub-ep-layout-play">
        <button class="pub-ep-play-circle" style="background:<?= e($p['color']) ?>;width:48px;height:48px;border-radius:50%;color:white;display:grid;place-items:center;cursor:pointer;border:none;box-shadow:0 4px 12px <?= e($p['color']) ?>66;transition:transform 0.2s"
                data-kp-play data-src="/r/<?= urlencode($p['id']) ?>/<?= urlencode($ep['id']) ?>/audio.mp3"
                data-title="<?= e($ep['title']) ?>" data-podcast="<?= e($p['title']) ?>"
                data-color="<?= e($p['color']) ?>" data-initial="<?= e($p['initial']) ?>"
                data-cover="<?= e($ep['cover'] ?: ($p['cover'] ?? '')) ?>"
                onmouseover="this.style.transform='scale(1.05)'" onmouseout="this.style.transform='scale(1)'">
          <?= icon('play',20) ?>
        </button>
        <div>
          <div style="font-size:14px;font-weight:600;color:var(--pub-text)">
            <?= (($ep['type'] ?? '') === 'trailer') ? 'Previsualización' : ((($ep['type'] ?? '') === 'bonus') ? 'Escuchar bonus' : 'Escuchar episodio') ?>
          </div>
          <div style="font-size:12.5px;color:var(--pub-muted);margin-top:2px"><?= e($ep['duration']) ?></div>
        </div>
      </div>
      <?php
      $play_control = ob_get_clean();
      echo kp_apply_filters('public_episode_play_control', $play_control, $ep, $p);
      ?>
    </div>
  </div>
  <div style="border-top:1px solid var(--pub-border);margin-bottom:32px;opacity:0.5"></div>
  
  <?php if (!empty($ep['transcript_url'])): ?>
    <div style="margin-bottom:36px;display:flex;align-items:center;gap:12px;padding:12px 16px;background:var(--pub-surface);border:1px solid var(--pub-border);border-radius:12px;width:fit-content">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--pub-muted)" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
      <div>
        <div style="font-size:14px;font-weight:600;color:var(--pub-text)">Transcripción</div>
        <a href="<?= e($ep['transcript_url']) ?>" target="_blank" style="font-size:13px;color:<?= e($p['color']) ?>;text-decoration:none" onmouseover="this.style.textDecoration='underline'" onmouseout="this.style.textDecoration='none'">Descargar o visualizar</a>
      </div>
    </div>
  <?php endif; ?>

  <?php
  // Load chapters if available
  $chapters = [];
  if (!empty($ep['chapters_url']) && file_exists(__DIR__ . '/..' . $ep['chapters_url'])) {
      $chapsData = json_decode(file_get_contents(__DIR__ . '/..' . $ep['chapters_url']), true);
      if (!empty($chapsData['chapters'])) {
          $chapters = $chapsData['chapters'];
      }
  }
  ?>

  <?php if (!empty($chapters)): ?>
  <div class="pub-chapters">
    <h3 class="pub-chapters-title">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 12h16M4 18h7"/></svg>
      Capítulos del Episodio
    </h3>
    <div class="pub-chapters-list">
      <?php foreach ($chapters as $ch): 
          $sec = (int)($ch['startTime'] ?? 0);
          $fmt = sprintf('%d:%02d', floor($sec / 60), $sec % 60);
      ?>
        <button class="pub-chapter-btn" data-time="<?= $sec ?>" style="--podcast-color: <?= e($p['color']) ?>;">
          <span class="pub-chapter-time"><?= $fmt ?></span>
          <span class="pub-chapter-text"><?= e($ch['title']) ?></span>
          <?php if (!empty($ch['img'])): ?>
            <img class="pub-chapter-img" src="<?= e($ch['img']) ?>" alt="">
          <?php endif; ?>
        </button>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
  <div class="pub-prose">
    <?php
      $md = $ep['notes_md'] ?? '';
      if (!$md) {
        echo '<p>Sin notas del episodio.</p>';
      } else {
        echo kp_md_to_html($md);
      }
    ?>
  </div>

  <?php 
    $fixed_notes = trim($p['fixed_notes'] ?? '');
    if ($fixed_notes): 
  ?>
  <div style="margin-top:20px">
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="<?= e($p['color']) ?>" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
      <span style="font-size:13px;font-weight:600;text-transform:uppercase;letter-spacing:0.06em;color:<?= e($p['color']) ?>">Notas del podcast</span>
    </div>
    <div class="pub-prose" style="opacity:0.85">
      <?= kp_md_to_html($fixed_notes) ?>
    </div>
  </div>
  <?php endif; ?>
  
  <?php if (!empty($ep['persons_json'])): 
    $persons = json_decode($ep['persons_json'], true);
    if (is_array($persons) && count($persons) > 0):
  ?>
  <div style="margin-top:48px;padding-top:32px;border-top:1px solid var(--pub-border)">
    <h3 style="font-size:16px;font-weight:600;margin-bottom:24px;color:var(--pub-text)">En este episodio</h3>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:20px">
      <?php foreach ($persons as $person): ?>
        <div style="display:flex;align-items:flex-start;gap:16px;background:var(--pub-surface);padding:20px;border-radius:16px;border:1px solid var(--pub-border);box-shadow:0 2px 8px rgba(0,0,0,0.02)">
          <div style="width:64px;height:64px;border-radius:50%;overflow:hidden;background:var(--pub-border);display:flex;align-items:center;justify-content:center;color:var(--pub-muted);font-weight:bold;font-size:24px;flex-shrink:0">
            <?php if (!empty($person['avatar'])): ?>
              <img src="<?= e($person['avatar']) ?>" style="width:100%;height:100%;object-fit:cover" alt="">
            <?php else: ?>
              <?= e(mb_substr($person['name'], 0, 1)) ?>
            <?php endif; ?>
          </div>
          <div style="flex:1">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
              <div style="font-size:16px;font-weight:600;color:var(--pub-text)">
                <?php if (!empty($person['url'])): ?>
                  <a href="<?= e($person['url']) ?>" target="_blank" style="color:inherit;text-decoration:none" onmouseover="this.style.textDecoration='underline'" onmouseout="this.style.textDecoration='none'"><?= e($person['name']) ?></a>
                <?php else: ?>
                  <?= e($person['name']) ?>
                <?php endif; ?>
              </div>
              <span style="font-size:11px;padding:2px 8px;border-radius:12px;background:var(--pub-border);color:var(--pub-muted);text-transform:capitalize;font-weight:500"><?= e($person['role']) ?></span>
            </div>
            
            <?php if (!empty($person['bio'])): ?>
              <div style="font-size:13.5px;color:var(--pub-muted);line-height:1.5;margin-bottom:8px"><?= e($person['bio']) ?></div>
            <?php endif; ?>
            
            <?php if (!empty($person['url'])): ?>
              <a href="<?= e($person['url']) ?>" target="_blank" style="font-size:13px;color:<?= e($p['color']) ?>;text-decoration:none;display:inline-flex;align-items:center;gap:4px" onmouseover="this.style.textDecoration='underline'" onmouseout="this.style.textDecoration='none'">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>
                Visitar enlace
              </a>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; endif; ?>
</div></section>
