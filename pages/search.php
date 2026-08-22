<?php
// pages/search.php — Resultados de búsqueda
require_once __DIR__ . '/../includes/helpers.php';

$q = trim($_GET['q'] ?? '');
$qLower = kp_unaccent(mb_strtolower($q, 'UTF-8'));

$podcasts = [];
$episodes = [];

if ($q !== '') {
    // Filtrar podcasts
    foreach (kp_podcasts() as $p) {
        if (str_contains(kp_unaccent(mb_strtolower($p['title'], 'UTF-8')), $qLower) || 
            str_contains(kp_unaccent(mb_strtolower($p['author'], 'UTF-8')), $qLower)) {
            $podcasts[] = $p;
        }
    }

    // Filtrar episodios
    foreach (kp_episodes() as $ep) {
        if (str_contains(kp_unaccent(mb_strtolower($ep['title'], 'UTF-8')), $qLower) || 
            str_contains(kp_unaccent(mb_strtolower($ep['guest'], 'UTF-8')), $qLower)) {
            $episodes[] = $ep;
        }
    }
}
?>
<div class="page-head">
  <div>
    <h1 class="page-title">Resultados de búsqueda</h1>
    <p class="page-sub">
      <?php if ($q === ''): ?>
        Escribe un término en la barra superior.
      <?php else: ?>
        Mostrando resultados para "<?= e($q) ?>"
      <?php endif; ?>
    </p>
  </div>
</div>

<?php if ($q !== ''): ?>
  <?php if (empty($podcasts) && empty($episodes)): ?>
    <div class="card" style="padding:60px 40px;text-align:center;margin-top:var(--gap)">
      <div style="color:var(--text-3);margin-bottom:12px"><?= icon('search',32) ?></div>
      <div style="font-size:16px;font-weight:500">No hay coincidencias</div>
      <div class="page-sub" style="margin-top:6px">No se encontraron podcasts ni episodios para "<?= e($q) ?>".</div>
    </div>
  <?php else: ?>
    
    <?php if (!empty($podcasts)): ?>
      <div class="card" style="margin-top:var(--gap)">
        <div class="card-head"><div class="card-title">Podcasts encontrados</div></div>
        <div class="list">
          <?php foreach ($podcasts as $p): ?>
            <a class="row" style="padding:12px 8px;border-top:1px solid var(--border);text-decoration:none;color:var(--text)" href="/admin/podcast?id=<?= e($p['id']) ?>">
              <?php if ($p['cover']): ?>
                <img class="cover" src="<?= e($p['cover']) ?>" style="width:42px;height:42px;border-radius:10px;object-fit:cover" alt="">
              <?php else: ?>
                <div class="cover" style="width:42px;height:42px;border-radius:10px;background:linear-gradient(135deg,<?= e($p['color']) ?>,<?= e($p['color']) ?>99);color:white;font-weight:700"><?= e($p['initial']) ?></div>
              <?php endif; ?>
              <div style="flex:1;min-width:0">
                <div style="font-size:14px;font-weight:500"><?= e($p['title']) ?></div>
                <div style="font-size:12px;color:var(--text-3)"><?= e($p['author']) ?></div>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php if (!empty($episodes)): ?>
      <div class="card" style="margin-top:var(--gap)">
        <div class="card-head"><div class="card-title">Episodios encontrados</div></div>
        <div class="list">
          <div style="overflow-x:auto;">
            <div style="min-width: 700px;">
              <?= ep_header() ?>
              <?php foreach ($episodes as $ep) echo ep_row($ep); ?>
            </div>
          </div>
        </div>
      </div>
    <?php endif; ?>

  <?php endif; ?>
<?php endif; ?>
