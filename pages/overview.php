<?php
// ============================================================================
// pages/overview.php — dashboard general · datos reales · empty states
// ============================================================================
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../version.php';

$podcasts = kp_podcasts();
$episodes = kp_episodes(true);

$tot      = (int)array_sum(array_column($podcasts, 'downloads'));
$tot7     = (int)array_sum(array_column($podcasts, 'downloads7'));
$totalEps = (int)array_sum(array_column($podcasts, 'episodes'));

$drafts = 0;
try { $drafts = (int)(kp_one("SELECT count(*) AS c FROM episodes WHERE status = 'draft'")['c'] ?? 0); } catch (Throwable $e) {}

// Subs reales (ActivityPub + email premium) — 0 mientras no haya tabla poblada.
$subs = 0;
try { $subs = (int)(kp_one("SELECT count(*) AS c FROM ap_followers")['c'] ?? 0); } catch (Throwable $e) {}

// Top show por descargas últimos 7d (puede no existir si BD vacía).
$topShow = null;
if ($podcasts) {
  $ranked = $podcasts;
  usort($ranked, function($a, $b) {
      $d7 = ($b['downloads7'] ?? 0) <=> ($a['downloads7'] ?? 0);
      if ($d7 !== 0) return $d7;
      $dt = ($b['downloads'] ?? 0) <=> ($a['downloads'] ?? 0);
      if ($dt !== 0) return $dt;
      return ($b['episodes'] ?? 0) <=> ($a['episodes'] ?? 0);
  });
  $topShow = $ranked[0];
}

$has_podcasts = !empty($podcasts);
$has_episodes = !empty($episodes);

// Ritmo de publicación · medido sobre los últimos 7 episodios reales
$rhythm_days = null;
if (count($episodes) >= 2) {
  $ts = [];
  foreach (array_slice($episodes, 0, 7) as $e) {
    $t = strtotime($e['date_iso'] ?? '');
    if ($t) $ts[] = $t;
  }
  if (count($ts) >= 2) {
    $deltas = [];
    for ($i = 1; $i < count($ts); $i++) $deltas[] = ($ts[$i-1] - $ts[$i]) / 86400;
    $rhythm_days = array_sum($deltas) / count($deltas);
  }
}
$meta = 7;

// Comprobar si hay actualización disponible (datos cacheados, sin consultar GitHub)
$updateAvailable = null;
try {
  $cached = kp_setting('update_cache', '');
  if ($cached) {
    $updateData = json_decode($cached, true);
    // Solo mostrar si hay update Y la versión nueva es mayor que la instalada
    if ($updateData && !empty($updateData['available']) && !empty($updateData['new_version'])
        && version_compare($updateData['new_version'], KUTPOD_VERSION, '>')) {
      $updateAvailable = $updateData;
    }
  }
} catch (Throwable $e) {}
?>

<?php if ($updateAvailable): ?>
<div style="background:linear-gradient(135deg, var(--accent), color-mix(in srgb, var(--accent), #000 25%));border-radius:14px;padding:16px 24px;margin-bottom:20px;display:flex;align-items:center;justify-content:space-between;gap:16px">
  <div style="display:flex;align-items:center;gap:14px;color:#fff;min-width:0">
    <?= icon('refreshCw',18) ?>
    <div>
      <div style="font-size:14px;font-weight:600">Nueva versión disponible: v<?= e($updateAvailable['new_version'] ?? '') ?></div>
      <div style="font-size:12px;opacity:0.8;margin-top:2px">Estás en v<?= KUTPOD_VERSION ?> · <?= !empty($updateAvailable['published_at']) ? 'publicada ' . kp_relative_time($updateAvailable['published_at']) : '' ?></div>
    </div>
  </div>
  <a class="btn" href="/admin/update" style="background:rgba(255,255,255,0.2);color:#fff;border:1px solid rgba(255,255,255,0.3);white-space:nowrap;backdrop-filter:blur(4px)"><?= icon('download',13) ?> Ver actualización</a>
</div>
<?php endif; ?>

<div class="page-head">
  <div>
    <h1 class="page-title">Overview</h1>
    <p class="page-sub">
      <?= $has_podcasts ? count($podcasts) . ' show' . (count($podcasts)>1?'s':'') . ' activos' : 'Sin podcasts todavía' ?>
      · <?= $totalEps ?> episodio<?= $totalEps===1?'':'s' ?>
      · <?= $drafts ?> borrador<?= $drafts===1?'':'es' ?>
    </p>
  </div>
</div>

<div class="stat-grid">
  <?= stat_card('Descargas · 7d', fmt_num($tot7), 'download', ['sub'=>$tot7 ? 'OP3 nativo' : 'Aún sin tráfico']) ?>
  <?= stat_card('Descargas totales', fmt_num($tot), 'chart', ['sub'=>$tot ? 'desde el primer ep' : '—']) ?>
  <?= stat_card('Episodios publicados', (string)$totalEps, 'headphones', ['sub'=>$drafts ? "$drafts borrador" . ($drafts>1?'es':'') . ' en cola' : 'Sin borradores']) ?>
  <?= stat_card('Seguidores Fediverso', fmt_num($subs), 'rss', ['sub'=>'ActivityPub']) ?>
</div>

<?php if (!$has_podcasts): ?>
  <div class="card card-lg" style="padding:60px 40px;margin-top:24px;text-align:center">
    <div style="display:inline-grid;place-items:center;width:64px;height:64px;border-radius:16px;background:var(--accent-soft);color:var(--accent);margin-bottom:18px"><?= icon('mic',28) ?></div>
    <h2 style="font-size:22px;letter-spacing:-0.01em;margin-bottom:8px">Tu instancia está limpia.</h2>
    <p class="page-sub" style="max-width:480px;margin:0 auto 24px">Crea tu primer podcast o importa un feed RSS existente. Cuando publiques episodios verás las estadísticas reales aquí.</p>
    <div class="row" style="justify-content:center;gap:10px">
      <a class="btn btn-primary" href="/admin/new-podcast"><?= icon('plus',13) ?> Crear primer podcast</a>
      <a class="btn" href="/admin/import"><?= icon('rss',13) ?> Importar RSS</a>
    </div>
  </div>

<?php else: ?>

<div class="grid-12" style="margin-top:var(--gap)">
  <div class="card span-8">
    <div class="card-head">
      <?php 
        $period = (int)($_GET['period'] ?? 30);
        if (!in_array($period, [7, 30, 365])) $period = 30;
      ?>
      <div class="card-title">Descargas por show — últimos <?= $period==365 ? '12 meses' : $period.' días' ?></div>
      <div class="seg">
        <button class="<?= $period==7?'active':'' ?>" onclick="location.href='?period=7'">7d</button>
        <button class="<?= $period==30?'active':'' ?>" onclick="location.href='?period=30'">30d</button>
        <button class="<?= $period==365?'active':'' ?>" onclick="location.href='?period=365'">12m</button>
      </div>
    </div>
    <?php
      require_once __DIR__ . '/../includes/op3-stats.php';
      $W = 800; $H = 220;
      $all_zero = !$tot7;
      echo '<svg viewBox="0 0 '.$W.' '.$H.'" preserveAspectRatio="none" style="width:100%;height:220px;overflow:visible">';
      foreach ([0.25, 0.5, 0.75] as $g) echo '<line x1="0" x2="'.$W.'" y1="'.($H*$g).'" y2="'.($H*$g).'" stroke="var(--border)" stroke-dasharray="2 4"/>';
      if ($all_zero) {
        echo '<text x="'.($W/2).'" y="'.($H/2).'" text-anchor="middle" fill="var(--text-3)" font-size="13" font-family="Inter,system-ui">Esperando datos de OP3…</text>';
      } else {
        $max_val = 1;
        $all_series = [];
        $days = [];
        for ($i=$period-1; $i>=0; $i--) $days[] = date('Y-m-d', strtotime("-$i days"));
        
        foreach ($podcasts as $p) {
          $s_data = kp_op3_podcast_series((int)$p['db_id'], $period);
          $dict = [];
          foreach ($s_data as $row) $dict[$row['date']] = (int)$row['downloads'];
          
          $series_pts = [];
          foreach ($days as $d) {
            $val = $dict[$d] ?? 0;
            if ($val > $max_val) $max_val = $val;
            $series_pts[] = ['d'=>$d, 'v'=>$val];
          }
          $all_series[] = ['p' => $p, 'pts' => $series_pts];
        }



        foreach ($all_series as $s) {
          $pts = [];
          $circles = [];
          foreach ($s['pts'] as $k => $pt) {
            $x = ($k / max(1, $period - 1)) * $W;
            $y = $H - (($pt['v'] / $max_val) * $H * 0.9);
            $pts[] = $x . ',' . $y;
            if ($pt['v'] > 0) {
                $circles[] = '<circle cx="'.$x.'" cy="'.$y.'" r="4" fill="'.e($s['p']['color']).'" stroke="var(--bg-1)" stroke-width="2" style="cursor:crosshair"><title>'.e($s['p']['title']).' - '.e($pt['d']).': '.number_format($pt['v']).' descargas</title></circle>';
            }
          }
          echo '<polyline points="'.implode(' ', $pts).'" fill="none" stroke="'.e($s['p']['color']).'" stroke-width="2" opacity="0.85"/>';
          echo implode('', $circles);
        }
        file_put_contents('/tmp/debug_svg.txt', "period=$period max_val=$max_val\n" . print_r($all_series, true));
      }
      echo '</svg>';
    ?>
    <div class="row" style="gap:18px;flex-wrap:wrap;margin-top:14px">
      <?php foreach ($podcasts as $p): ?>
        <div class="row" style="gap:6px;font-size:12px"><span style="width:10px;height:10px;border-radius:3px;background:<?= e($p['color']) ?>"></span><?= e($p['title']) ?></div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="card span-4">
    <div class="card-head"><div class="card-title">Ritmo de publicación</div></div>
    <?php if ($rhythm_days === null): ?>
      <div class="page-sub" style="padding:30px 0;text-align:center">Necesitas al menos 2 episodios publicados para medir el ritmo.</div>
    <?php else: ?>
      <div class="tabular" style="font-size:32px;font-weight:600;letter-spacing:-0.02em">
        <?= number_format($rhythm_days, 1) ?><span style="font-size:14px;color:var(--text-3);font-weight:400;margin-left:6px">días entre eps</span>
      </div>
      <div class="delta <?= $rhythm_days <= $meta ? 'up' : 'down' ?>" style="margin-top:6px">
        <?= icon($rhythm_days <= $meta ? 'arrowDown' : 'arrowUp',12) ?>
        <?= number_format(abs($meta - $rhythm_days), 1) ?>d vs meta de <?= $meta ?>d
      </div>
      <div class="row" style="justify-content:space-between;margin-top:14px;font-size:11px;color:var(--text-3)"><span>últimos episodios</span><span><?= e($episodes[0]['date'] ?? '') ?></span></div>
    <?php endif; ?>
  </div>
</div>

<div class="grid-12" style="margin-top:var(--gap)">
  <div class="card span-8">
    <div class="card-head">
      <div class="card-title">Tus podcasts</div>
      <a class="btn btn-ghost" href="/admin/podcasts">Ver todos →</a>
    </div>
    <div class="list">
      <?php foreach ($podcasts as $p): ?>
        <a class="row" style="padding:12px 8px;border-top:1px solid var(--border);text-decoration:none;color:var(--text)" href="/admin/podcast/<?= e($p['id']) ?>">
          <?php if ($p['cover']): ?>
            <img class="cover" src="<?= e($p['cover']) ?>" style="width:42px;height:42px;border-radius:10px;object-fit:cover" alt="">
          <?php else: ?>
            <div class="cover" style="width:42px;height:42px;border-radius:10px;background:linear-gradient(135deg,<?= e($p['color']) ?>,<?= e($p['color']) ?>99);color:white;font-weight:700"><?= e($p['initial']) ?></div>
          <?php endif; ?>
          <div style="flex:1;min-width:0">
            <div style="font-size:14px;font-weight:500">
              <?= e($p['title']) ?>
              <?php if (($p['parental'] ?? 'clean') === 'explicit'): ?>
                <span style="display:inline-block;background:var(--border);color:var(--text-2);border-radius:4px;font-size:10px;padding:2px 4px;margin-left:4px;vertical-align:middle;line-height:1;font-weight:800">E</span>
              <?php endif; ?>
            </div>
            <div style="font-size:12px;color:var(--text-3)"><?= (int)$p['episodes'] ?> eps · <?= e($p['cat']) ?></div>
            <?php
              $top_ep = kp_op3_most_downloaded_episode((int)$p['db_id']);
              if ($top_ep && $top_ep['downloads'] > 0):
            ?>
              <div style="font-size:11.5px;color:var(--text-3);margin-top:4px;display:flex;align-items:center;gap:4px">
                <span style="color:var(--accent);display:inline-flex"><?= icon('star', 11) ?></span>
                <span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:380px;" title="Top: <?= e($top_ep['title']) ?> (<?= fmt_num((int)$top_ep['downloads']) ?> descargas)">
                  Top: <strong style="color:var(--text-2)"><?= e($top_ep['title']) ?></strong> (<?= fmt_num((int)$top_ep['downloads']) ?> desc.)
                </span>
              </div>
            <?php endif; ?>
          </div>
          <div class="tabular" style="text-align:right">
            <div style="font-weight:600"><?= fmt_num((int)$p['downloads7']) ?></div>
            <div style="font-size:11px;color:var(--text-3);margin-top:2px">desc. 7d</div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if ($topShow): ?>
  <div class="card span-4">
    <div class="card-head"><div class="card-title">Top show · 7d</div></div>
    <div class="row" style="gap:14px;align-items:flex-start">
      <?php if ($topShow['cover']): ?>
        <img class="cover" src="<?= e($topShow['cover']) ?>" style="width:72px;height:72px;border-radius:14px;object-fit:cover;flex-shrink:0" alt="">
      <?php else: ?>
        <div class="cover" style="width:72px;height:72px;border-radius:14px;background:linear-gradient(135deg,<?= e($topShow['color']) ?>,<?= e($topShow['color']) ?>99);color:white;font-weight:700;font-size:20px;flex-shrink:0"><?= e($topShow['initial']) ?></div>
      <?php endif; ?>
      <div style="flex:1;min-width:0">
        <div style="font-size:16px;font-weight:600">
          <?= e($topShow['title']) ?>
          <?php if (($topShow['parental'] ?? 'clean') === 'explicit'): ?>
            <span style="display:inline-block;background:var(--border);color:var(--text-2);border-radius:4px;font-size:10px;padding:2px 4px;margin-left:4px;vertical-align:middle;line-height:1;font-weight:800">E</span>
          <?php endif; ?>
        </div>
        <div style="font-size:12.5px;color:var(--text-3);margin-top:2px"><?= (int)$topShow['episodes'] ?> eps · <?= e($topShow['cat']) ?></div>
        <div class="row" style="gap:18px;margin-top:14px">
          <div><div style="font-size:11px;color:var(--text-3)">7d</div><div class="tabular" style="font-weight:600"><?= fmt_num((int)$topShow['downloads7']) ?></div></div>
          <div><div style="font-size:11px;color:var(--text-3)">Total</div><div class="tabular" style="font-weight:600"><?= fmt_num((int)$topShow['downloads']) ?></div></div>
        </div>
      </div>
    </div>
    <a class="btn" style="margin-top:14px;width:100%;justify-content:center" href="/admin/podcast/<?= e($topShow['id']) ?>">Abrir show →</a>
  </div>
  <?php endif; ?>
</div>

<div class="card" style="margin-top:var(--gap)">
  <div class="card-head">
    <div class="card-title">Episodios recientes</div>
    <a class="btn btn-ghost" href="/admin/podcasts">Ver más →</a>
  </div>
  <?php if (!$has_episodes): ?>
    <div class="page-sub" style="padding:40px;text-align:center">Aún no has publicado episodios.
      <?php if ($has_podcasts): ?>
        <a class="btn" style="margin-top:14px" href="/admin/new-episode?podcast=<?= e($podcasts[0]['id']) ?>">Subir el primero →</a>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="list">
      <div style="overflow-x:auto;">
        <div style="min-width: 700px;">
          <?= ep_header() ?>
          <?php foreach (array_slice($episodes, 0, 8) as $ep) echo ep_row($ep); ?>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php endif; ?>
