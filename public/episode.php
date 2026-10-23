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
<?php
$ep_cover = $ep['cover'] ?: ($p['cover'] ?? '');

// Load chapters if available
$chapters = [];
if (!empty($ep['chapters_url']) && file_exists(__DIR__ . '/..' . $ep['chapters_url'])) {
    $chapsData = json_decode(file_get_contents(__DIR__ . '/..' . $ep['chapters_url']), true);
    if (!empty($chapsData['chapters'])) {
        $chapters = $chapsData['chapters'];
    }
}
$fixed_notes = trim($p['fixed_notes'] ?? '');
?>
<section class="pub-section"><div class="pub-container pub-ep-view-container">
  <div class="pub-ep-detail-grid">
    <!-- Fila 1 - Izquierda: Cover con Reflejo -->
    <div class="pub-ep-grid-hero-cover">
      <div class="pub-ep-cover-reflection-wrap">
        <?php if ($ep_cover): ?>
          <img src="<?= e($ep_cover) ?>" alt="<?= e($ep['title']) ?>" class="pub-ep-cover-main">
          <div class="pub-ep-cover-reflection" aria-hidden="true">
            <img src="<?= e($ep_cover) ?>" alt="" class="pub-ep-cover-reflected-img">
            <div class="pub-ep-cover-reflection-fade"></div>
          </div>
        <?php else: ?>
          <div class="pub-ep-cover-main pub-ep-cover-placeholder" style="background:linear-gradient(135deg,<?= e($p['color']) ?>,<?= e($p['color']) ?>88);">
            <span style="color:rgba(255,255,255,0.35);font-weight:800;font-size:60px;letter-spacing:-0.04em"><?= e($p['initial']) ?></span>
          </div>
          <div class="pub-ep-cover-reflection" aria-hidden="true">
            <div class="pub-ep-cover-reflected-img pub-ep-cover-placeholder" style="background:linear-gradient(135deg,<?= e($p['color']) ?>,<?= e($p['color']) ?>88);">
              <span style="color:rgba(255,255,255,0.35);font-weight:800;font-size:60px;letter-spacing:-0.04em"><?= e($p['initial']) ?></span>
            </div>
            <div class="pub-ep-cover-reflection-fade"></div>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Fila 1 - Derecha: Título, Player y Compartir -->
    <div class="pub-ep-grid-hero-info">
      <a href="/@<?= e($p['id']) ?>" class="pub-ep-detail-show-tag" style="color:<?= e($p['color']) ?>">
        <?= e($p['title']) ?>
      </a>

      <h1 class="pub-ep-detail-title">
        <?= e($ep['title']) ?>
        <?php if (($p['parental'] ?? 'clean') === 'explicit'): ?>
          <span class="pub-badge-e" title="Contenido explícito" style="vertical-align:middle;margin-left:8px">E</span>
        <?php endif; ?>
        <?php if ((int)kp_get_podcast_meta($p, 'premium_enabled', 0) || (int)kp_get_episode_meta($ep, 'premium_enabled', 0)): ?>
          <span class="pub-badge-prem" title="Contenido premium" style="vertical-align:middle;margin-left:4px">$</span>
        <?php endif; ?>
      </h1>

      <div class="pub-ep-detail-control-bar">
        <div class="pub-ep-detail-play-row">
          <?php
          ob_start();
          ?>
          <button class="pub-ep-play-pill" style="background:<?= e($p['color']) ?>;color:#fff" aria-label="Reproducir <?= e($ep['title']) ?>"
                  data-kp-play data-src="/r/<?= urlencode($p['id']) ?>/<?= urlencode($ep['id']) ?>/audio.mp3"
                  data-title="<?= e($ep['title']) ?>" data-podcast="<?= e($p['title']) ?>"
                  data-color="<?= e($p['color']) ?>" data-initial="<?= e($p['initial']) ?>"
                  data-cover="<?= e($ep['cover'] ?: ($p['cover'] ?? '')) ?>">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><polygon points="6 4 20 12 6 20"></polygon></svg>
          </button>
          <?php
          $play_control = ob_get_clean();
          echo kp_apply_filters('public_episode_play_control', $play_control, $ep, $p);
          ?>
          
          <div class="pub-ep-detail-meta">
            <?php
            $epMetaParts = [];
            if (!empty($ep['duration'])) {
              $epMetaParts[] = e($ep['duration']);
            }
            if (!empty($ep['n']) && (int)$ep['n'] > 0) {
              $epMetaParts[] = 'Episodio ' . (int)$ep['n'];
            }
            if (!empty($ep['s']) && (int)$ep['s'] > 0) {
              $epMetaParts[] = 'Temporada ' . (int)$ep['s'];
            }
            if (!empty($ep['date_iso'])) {
              $epMetaParts[] = '<span data-kp-date="' . e($ep['date_iso']) . '">Publicado ' . e(kp_relative_time($ep['date_iso'])) . '</span>';
            }
            echo implode(' · ', $epMetaParts);
            ?>
          </div>
        </div>

        <button type="button" class="pub-ep-share-btn" onclick="kpShareEpisode(this)" data-title="<?= e($ep['title']) ?>">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"></path><polyline points="16 6 12 2 8 6"></polyline><line x1="12" y1="2" x2="12" y2="15"></line></svg>
          <span class="pub-ep-share-label">Compartir</span>
        </button>
      </div>

      <?php if (!empty($ep['transcript_url'])): ?>
        <div style="margin-top:20px;display:flex;align-items:center;gap:12px;padding:10px 14px;background:var(--pub-surface);border:1px solid var(--pub-border);border-radius:10px;width:fit-content">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--pub-muted)" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
          <div style="font-size:13px">
            <span style="font-weight:600;color:var(--pub-text)">Transcripción:</span>
            <a href="<?= e($ep['transcript_url']) ?>" target="_blank" style="color:<?= e($p['color']) ?>;text-decoration:none;margin-left:4px" onmouseover="this.style.textDecoration='underline'" onmouseout="this.style.textDecoration='none'">Ver o descargar</a>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <!-- Fila 2 - Izquierda: Capítulos del Episodio (Alineado con Descripción) -->
    <?php if (!empty($chapters)): ?>
      <div class="pub-ep-grid-chapters">
        <h3 class="pub-ep-subheading">Capítulos del episodio</h3>
        <div class="pub-ep-chapters-clean-list">
          <?php foreach ($chapters as $ch): 
              $sec = (int)($ch['startTime'] ?? 0);
              $fmt = sprintf('%d:%02d', floor($sec / 60), $sec % 60);
              $ch_img = !empty($ch['img']) ? $ch['img'] : ($p['cover'] ?? '');
          ?>
            <button class="pub-ep-chapter-row" data-time="<?= $sec ?>"
                    data-src="/r/<?= urlencode($p['id']) ?>/<?= urlencode($ep['id']) ?>/audio.mp3"
                    data-title="<?= e($ep['title']) ?>" data-podcast="<?= e($p['title']) ?>"
                    data-color="<?= e($p['color']) ?>" data-initial="<?= e($p['initial']) ?>"
                    data-cover="<?= e($ep['cover'] ?: ($p['cover'] ?? '')) ?>"
                    aria-label="Saltar al minuto <?= $fmt ?>">
              <span class="pub-ep-chapter-time"><?= $fmt ?></span>
              <span class="pub-ep-chapter-title"><?= e($ch['title']) ?></span>
              <?php if (!empty($ch_img)): ?>
                <img class="pub-ep-chapter-thumb" src="<?= e($ch_img) ?>" alt="">
              <?php endif; ?>
            </button>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <!-- Fila 2 - Derecha: Descripción y Notas del Podcast -->
    <div class="pub-ep-grid-body" style="<?= empty($chapters) ? 'grid-column: 2;' : '' ?>">
      <!-- Sección Descripción -->
      <div class="pub-ep-detail-desc-block">
        <h3 class="pub-ep-subheading">Descripción</h3>
        <div class="pub-prose pub-ep-clean-prose">
          <?php
            $md = $ep['notes_md'] ?? '';
            if (!$md) {
              echo '<p>Sin notas del episodio.</p>';
            } else {
              echo kp_md_to_html($md);
            }
          ?>
        </div>
      </div>

      <!-- Sección Notas del Podcast -->
      <?php if ($fixed_notes): ?>
      <div class="pub-ep-detail-notes-block">
        <h3 class="pub-ep-subheading">Notas del podcast</h3>
        <div class="pub-prose pub-ep-clean-prose" style="--podcast-color: <?= e($p['color']) ?>;">
          <?= kp_md_to_html($fixed_notes) ?>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <!-- Fila 3 - Ancho Completo: Participantes -->
    <?php if (!empty($ep['persons_json'])): 
      $persons = json_decode($ep['persons_json'], true);
      if (is_array($persons) && count($persons) > 0):
    ?>
    <div class="pub-ep-grid-persons">
      <h3 class="pub-ep-subheading">En este episodio participó:</h3>
      <div class="pub-ep-persons-grid">
        <?php foreach ($persons as $person): ?>
          <div class="pub-ep-person-card">
            <div class="pub-ep-person-avatar">
              <?php if (!empty($person['avatar'])): ?>
                <img src="<?= e($person['avatar']) ?>" alt="<?= e($person['name']) ?>">
              <?php else: ?>
                <span><?= e(mb_substr($person['name'], 0, 1)) ?></span>
              <?php endif; ?>
            </div>
            <div class="pub-ep-person-info">
              <div class="pub-ep-person-head">
                <span class="pub-ep-person-name">
                  <?php if (!empty($person['url'])): ?>
                    <a href="<?= e($person['url']) ?>" target="_blank"><?= e($person['name']) ?></a>
                  <?php else: ?>
                    <?= e($person['name']) ?>
                  <?php endif; ?>
                </span>
                <span class="pub-ep-person-role"><?= e($person['role']) ?></span>
              </div>
              <?php if (!empty($person['bio'])): ?>
                <p class="pub-ep-person-bio"><?= e($person['bio']) ?></p>
              <?php endif; ?>
              <?php if (!empty($person['url'])): ?>
                <a href="<?= e($person['url']) ?>" target="_blank" class="pub-ep-person-link" style="color:<?= e($p['color']) ?>">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>
                  Visitar enlace
                </a>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; endif; ?>
  </div>
</div></section>

<script>
function kpShareEpisode(btn) {
  const url = window.location.href;
  const title = btn.getAttribute('data-title') || document.title;
  const label = btn.querySelector('.pub-ep-share-label');
  if (navigator.share) {
    navigator.share({ title: title, url: url }).catch(() => {});
  } else if (navigator.clipboard) {
    navigator.clipboard.writeText(url).then(() => {
      if (label) {
        const orig = label.textContent;
        label.textContent = '¡Enlace copiado!';
        setTimeout(() => { label.textContent = orig; }, 2000);
      }
    }).catch(() => {});
  }
}
</script>
