<?php
require_once __DIR__ . '/../includes/helpers.php';
$podcasts = kp_podcasts();
?>
<section class="pub-section"><div class="pub-container">
  <h1 class="pub-h1" style="margin-bottom:12px">Catálogo</h1>
  <p style="color:var(--pub-muted);font-size:15px;margin-bottom:40px;max-width:620px"><?= count($podcasts) ?> shows, una voz.</p>
  <div class="pub-shows-grid">
    <?php foreach ($podcasts as $p): ?>
      <a class="pub-show-card" href="/@<?= e($p['id']) ?>" style="text-decoration:none;color:inherit">
        <?php if ($p['cover']): ?>
          <img class="pub-show-cover" style="width:100%;aspect-ratio:1;border-radius:14px;object-fit:cover" src="<?= e($p['cover']) ?>" alt="">
        <?php else: ?>
          <div class="pub-show-cover" style="background:linear-gradient(135deg,<?= e($p['color']) ?>,<?= e($p['color']) ?>88)">
            <span style="color:#fff;font-weight:800;font-size:38px;letter-spacing:-0.04em"><?= e($p['initial']) ?></span>
          </div>
        <?php endif; ?>
        <div style="margin-top:14px">
          <h3 style="font-size:16px;font-weight:600;letter-spacing:-0.01em">
            <?= e($p['title']) ?>
            <?php if (($p['parental'] ?? 'clean') === 'explicit'): ?>
              <span style="display:inline-block;background:var(--border);color:var(--text-2);border-radius:4px;font-size:10px;padding:2px 4px;margin-left:4px;vertical-align:middle;line-height:1;font-weight:800">E</span>
            <?php endif; ?>
            <?php if ((int)kp_get_podcast_meta($p, 'premium_enabled', 0)): ?>
              <span style="display:inline-block;background:#10b981;color:#fff;border-radius:4px;font-size:10px;padding:2px 3px;margin-left:4px;vertical-align:middle;line-height:1;font-weight:800">$</span>
            <?php endif; ?>
          </h3>
          <div style="font-size:12.5px;color:var(--pub-muted);margin-top:4px"><?= (int)$p['episodes'] ?> episodios · <?= e($p['cat']) ?></div>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
</div></section>
