<?php
// ============================================================================
// pages/alerts.php — Notificaciones del sistema y del fediverso
// ============================================================================
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';


// Procesar acción de marcar todas como leídas
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'mark_all_read') {
  try {
    kp_q("UPDATE alerts SET read_at = datetime('now') WHERE read_at IS NULL");
  } catch (Throwable $e) {}
  header('Location: /admin/alerts');
  exit;
}

// Lee de tabla `alerts` si existe; si no, empty state.
$alerts = [];
$totalAlerts = 0;
$totalPages = 1;
$page = 1;
$perPage = 50;

try {
  $totalAlerts = (int)(kp_one("SELECT count(*) c FROM alerts")['c'] ?? 0);
  $totalPages = max(1, ceil($totalAlerts / $perPage));
  $page = max(1, min($totalPages, (int)($_GET['p'] ?? 1)));
  $offset = ($page - 1) * $perPage;

  $alerts = kp_q("SELECT id, kind, title, body, link, actor_url, action_type, actor_avatar, actor_name, created_at, read_at
                  FROM alerts ORDER BY created_at DESC LIMIT ? OFFSET ?", [$perPage, $offset]) ?: [];
} catch (Throwable $e) {}

$unread = 0;
try {
  $unread = (int)(kp_one("SELECT count(*) c FROM alerts WHERE read_at IS NULL")['c'] ?? 0);
} catch (Throwable $e) {
  foreach ($alerts as $a) if (empty($a['read_at'])) $unread++;
}

// Agrupa por tipo para los filtros (y para las estadísticas globales)
$by_kind = [];
try {
  $kind_counts = kp_q("SELECT kind, count(*) c FROM alerts GROUP BY kind") ?: [];
  foreach ($kind_counts as $kc) {
    $by_kind[$kc['kind'] ?? 'system'] = (int)$kc['c'];
  }
} catch (Throwable $e) {
  foreach ($alerts as $a) {
    $k = $a['kind'] ?? 'system';
    $by_kind[$k] = ($by_kind[$k] ?? 0) + 1;
  }
}

$kind_meta = [
  'fediverse' => ['Fediverso',  '#6366f1', 'globe'],
  'import'    => ['Importación','#10b981', 'rss'],
  'op3'       => ['Analytics',  '#f59e0b', 'chart'],
  'security'  => ['Seguridad',  '#ef4444', 'shield'],
  'system'    => ['Sistema',    '#6b7280', 'settings'],
];
?>
<div class="page-head">
  <div>
    <h1 class="page-title">Alertas</h1>
    <p class="page-sub">
      <?= $unread ? "<strong>$unread</strong> sin leer · " : '' ?>Notificaciones de federación, importaciones, métricas y seguridad
    </p>
  </div>
  <div class="row" style="gap:8px">
    <?php if ($unread): ?>
      <form method="POST" style="display:inline">
        <?= kp_csrf_field() ?>
        <input type="hidden" name="_action" value="mark_all_read"/>
        <button class="btn" type="submit"><?= icon('check',13) ?> Marcar todas leídas</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php if (!$alerts): ?>
  <div class="card card-lg" style="text-align:center;padding:64px 24px">
    <div style="font-size:48px;margin-bottom:12px;opacity:.4"><?= icon('bell',48) ?></div>
    <h3 style="margin:0 0 8px">Sin alertas</h3>
    <p class="muted" style="margin:0">Aquí aparecerán nuevos seguidores del fediverso, importaciones completadas, picos de descargas, problemas de federación y avisos del sistema.</p>
  </div>
<?php else: ?>

  <style>
    .alert-stats { grid-template-columns: repeat(5, 1fr); gap: 12px; margin-bottom: 24px; }
    @media (max-width: 920px) {
      .alert-stats { grid-template-columns: repeat(2, 1fr); }
    }
    @media (max-width: 600px) {
      .alert-stats { grid-template-columns: 1fr; }
    }
  </style>
  <!-- Stats por tipo -->
  <div class="stat-grid alert-stats">
    <?php foreach ($kind_meta as $k => [$label, $color, $ic]):
      $count = $by_kind[$k] ?? 0; ?>
      <div class="stat" style="padding: 12px 14px;">
        <div class="row" style="gap:8px;align-items:center;margin-bottom:6px">
          <span style="width:8px;height:8px;border-radius:50%;background:<?= $color ?>"></span>
          <span class="muted" style="font-size:12px;text-transform:uppercase;letter-spacing:.04em"><?= e($label) ?></span>
        </div>
        <div style="font-size:28px;font-weight:600"><?= $count ?></div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- Lista -->
  <div class="card card-lg">
    <div class="row" style="justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:12px">
      <h3 style="margin:0">Recientes</h3>
      <div class="row" style="gap:6px;flex-wrap:wrap">
        <button class="chip active" type="button" data-filter="all">Todas</button>
        <?php foreach ($kind_meta as $k => [$label]):
          if (!isset($by_kind[$k])) continue; ?>
          <button class="chip" type="button" data-filter="<?= e($k) ?>"><?= e($label) ?></button>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="alert-list" style="display:flex;flex-direction:column;gap:8px">
      <?php foreach ($alerts as $a):
        $k = $a['kind'] ?? 'system';
        [$label, $color, $ic] = $kind_meta[$k] ?? $kind_meta['system'];
        $unread_cls = empty($a['read_at']) ? ' unread' : '';
      ?>
        <div class="alert-row<?= $unread_cls ?>" data-kind="<?= e($k) ?>"
             style="display:flex;gap:12px;padding:14px;border-radius:10px;background:var(--bg-2);border:1px solid var(--border)<?= empty($a['read_at']) ? ';border-left:3px solid '.$color : '' ?>">
          
          <?php if (!empty($a['actor_avatar'])): ?>
            <div style="width:36px;height:36px;border-radius:50%;background:var(--bg-3) url('<?= e($a['actor_avatar']) ?>') center/cover;flex-shrink:0;"></div>
          <?php else: ?>
            <div style="width:36px;height:36px;border-radius:8px;background:<?= $color ?>22;color:<?= $color ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0">
              <?= icon($ic, 16) ?>
            </div>
          <?php endif; ?>

          <div style="flex:1;min-width:0">
            <div class="row" style="justify-content:space-between;gap:8px;margin-bottom:2px">
              <strong style="font-size:14px">
                <?php if (!empty($a['action_type'])): ?>
                  <?php
                    $at = $a['action_type'];
                    $atIcon = ['Follow'=>'👤','Like'=>'💖','Announce'=>'🔁','Reply'=>'💬'][$at] ?? '⚡';
                  ?>
                  <span style="opacity:0.8;font-weight:normal;margin-right:4px"><?= $atIcon ?></span>
                <?php endif; ?>
                <?= e($a['title']) ?>
              </strong>
              <span class="muted" style="font-size:12px;white-space:nowrap"><?= e(kp_relative_time($a['created_at'])) ?></span>
            </div>
            
            <?php if (!empty($a['actor_name'])): ?>
              <div style="font-size:13px;font-weight:500;margin-bottom:2px">
                <?= e($a['actor_name']) ?> <span class="muted" style="font-weight:normal;font-size:12px;opacity:0.7">&lt;<?= e($a['actor_url']) ?>&gt;</span>
              </div>
            <?php endif; ?>

            <?php if (!empty($a['body'])): ?>
              <div class="muted" style="font-size:13px"><?= e($a['body']) ?></div>
            <?php endif; ?>
            <?php if (!empty($a['link']) && $a['link'] !== '/admin/alerts'):
              $linkLabel = 'Ver detalle →';
              $lnk = $a['link'];
              if (str_contains($lnk, 'new-episode'))       $linkLabel = 'Ver episodio →';
              elseif (str_contains($lnk, 'tab=analytics'))  $linkLabel = 'Ver estadísticas →';
              elseif (str_contains($lnk, '/admin/podcast/'))$linkLabel = 'Ver podcast →';
              elseif (str_contains($lnk, '/admin/import'))  $linkLabel = 'Ver importación →';
              elseif (str_contains($lnk, '/admin/analytics'))$linkLabel = 'Ver estadísticas →';
              elseif (str_contains($lnk, 'flarum') || str_contains($lnk, '/d/')) $linkLabel = 'Ver en foro →';
              elseif (str_starts_with(strtolower($lnk), 'http'))        $linkLabel = 'Ver perfil →';
            ?>
              <a href="<?= e($a['link']) ?>" style="font-size:12px;color:<?= $color ?>;margin-top:4px;display:inline-block"><?= $linkLabel ?></a>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    
    <?php if ($totalPages > 1): ?>
      <div class="row" style="justify-content:space-between;margin-top:24px;padding-top:16px;border-top:1px solid var(--border);align-items:center">
        <div>
          <?php if ($page > 1): ?>
            <a href="?p=<?= $page - 1 ?>" class="btn" style="font-size:13px">« Anterior</a>
          <?php else: ?>
            <button class="btn" disabled style="opacity:0.5;cursor:not-allowed;font-size:13px">« Anterior</button>
          <?php endif; ?>
        </div>
        <div class="muted" style="font-size:13.5px">
          Página <?= $page ?> de <?= $totalPages ?> (Total: <?= $totalAlerts ?>)
        </div>
        <div>
          <?php if ($page < $totalPages): ?>
            <a href="?p=<?= $page + 1 ?>" class="btn" style="font-size:13px">Siguiente »</a>
          <?php else: ?>
            <button class="btn" disabled style="opacity:0.5;cursor:not-allowed;font-size:13px">Siguiente »</button>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <script>
    document.querySelectorAll('.chip[data-filter]').forEach(btn => {
      btn.addEventListener('click', () => {
        document.querySelectorAll('.chip[data-filter]').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        const f = btn.dataset.filter;
        document.querySelectorAll('.alert-row').forEach(r => {
          r.style.display = (f === 'all' || r.dataset.kind === f) ? '' : 'none';
        });
      });
    });
  </script>
<?php endif; ?>
