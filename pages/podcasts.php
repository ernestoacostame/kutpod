<?php
// pages/podcasts.php — grid de shows
require_once __DIR__ . '/../includes/helpers.php';
$podcasts = kp_podcasts();

$pageShow = max(1, (int)($_GET['p'] ?? 1));
$perPage = 12;
$totalShowsCount = count($podcasts);
$totalPages = max(1, ceil($totalShowsCount / $perPage));
$podcastsPage = array_slice($podcasts, ($pageShow - 1) * $perPage, $perPage);
$totalEps = (int)array_sum(array_column($podcasts, 'episodes'));
?>
<div class="page-head">
  <div><h1 class="page-title">Podcasts</h1><p class="page-sub"><?= $totalShowsCount ?> show<?= $totalShowsCount === 1 ? '' : 's' ?> · <?= $totalEps ?> episodios totales</p></div>
  <div class="row">
    <a class="btn" href="/admin/import"><?= icon('rss',13) ?> Importar RSS</a>
    <a class="btn btn-primary" href="/admin/new-podcast"><?= icon('plus',13) ?> Nuevo podcast</a>
  </div>
</div>
<div class="grid-12">
  <?php foreach ($podcastsPage as $p): ?>
    <a class="card span-2" style="text-decoration:none;color:inherit;display:block;padding:16px" href="/admin/podcast?id=<?= e($p['id']) ?>">
      <?php if ($p['cover']): ?>
        <img style="width:100%;aspect-ratio:1;border-radius:12px;object-fit:cover;margin-bottom:12px" src="<?= e($p['cover']) ?>" alt="">
      <?php else: ?>
        <div style="aspect-ratio:1;border-radius:12px;background:linear-gradient(135deg,<?= e($p['color']) ?>,<?= e($p['color']) ?>88);display:grid;place-items:center;color:white;font-size:32px;font-weight:800;letter-spacing:-0.04em;margin-bottom:12px"><?= e($p['initial']) ?></div>
      <?php endif; ?>
      <div style="font-size:15px;font-weight:600;letter-spacing:-0.01em;line-height:1.3">
        <?= e($p['title']) ?>
        <?php if (($p['parental'] ?? 'clean') === 'explicit'): ?>
          <span style="display:inline-block;background:var(--border);color:var(--text-2);border-radius:4px;font-size:10px;padding:2px 4px;margin-left:4px;vertical-align:middle;line-height:1;font-weight:800">E</span>
        <?php endif; ?>
      </div>
      <div style="font-size:11.5px;color:var(--text-3);margin-top:4px"><?= (int)$p['episodes'] ?> eps · <?= e($p['cat']) ?></div>
      <div class="row" style="justify-content:space-between;margin-top:14px;padding-top:14px;border-top:1px solid var(--border);font-size:12.5px">
        <span style="color:var(--text-3)">7d</span>
        <span class="tabular" style="font-weight:600"><?= fmt_num($p['downloads7']) ?></span>
      </div>
    </a>
  <?php endforeach; ?>
</div>

<?php if ($totalPages > 1): ?>
<div class="row" style="padding:24px 0;justify-content:center;gap:4px">
  <?php for ($i = 1; $i <= $totalPages; $i++): ?>
    <a href="?page=podcasts&p=<?= $i ?>" class="btn <?= $i === $pageShow ? 'btn-primary' : '' ?>" style="padding:4px 10px"><?= $i ?></a>
  <?php endfor; ?>
</div>
<?php endif; ?>
