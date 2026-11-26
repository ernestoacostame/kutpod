<?php
// ============================================================================
// KutPod · Opciones del plugin Importador de Métricas Externas
// ============================================================================
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/db.php';

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'purge_external_stats') {
    kp_csrf_verify();
    try {
        kp_db()->exec("DELETE FROM external_platform_stats");
        $msg = __('ext_metrics_purged', 'Se eliminaron todas las métricas externas importadas.');
    } catch (Throwable $e) {
        $msg = 'Error: ' . $e->getMessage();
    }
}

$counts = ['apple' => 0, 'spotify' => 0, 'other' => 0];
$totalRows = 0;
try {
    foreach (kp_q("SELECT platform, COUNT(*) c FROM external_platform_stats GROUP BY platform") ?: [] as $r) {
        $p = $r['platform'] ?? 'other';
        $c = (int)($r['c'] ?? 0);
        if (isset($counts[$p])) {
            $counts[$p] += $c;
        } else {
            $counts['other'] += $c;
        }
        $totalRows += $c;
    }
} catch (Throwable $e) {}
?>
<div class="card card-lg" style="padding:24px;max-width:720px">
  <div class="card-title"><?= __('ext_metrics_title', 'Importador de Métricas Externas (Apple / Spotify)') ?></div>
  <p class="page-sub" style="margin-top:6px;font-size:13px">
    <?= __('ext_metrics_desc', 'Cuando este plugin está activo, puedes importar archivos CSV de Apple Podcasts Connect y Spotify for Creators y ver sus métricas consolidadas en Analíticas y Episodios. Si desactivas el plugin, todas las estadísticas externas se ocultan automáticamente.') ?>
  </p>

  <?php if ($msg): ?>
    <div style="margin-top:14px;padding:10px 14px;border-radius:8px;background:#10b98122;color:#10b981;font-size:13px"><?= e($msg) ?></div>
  <?php endif; ?>

  <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-top:18px">
    <div style="padding:14px;border:1px solid var(--border);border-radius:10px">
      <div style="font-size:11px;color:var(--text-3);text-transform:uppercase">Apple Podcasts</div>
      <div class="tabular" style="font-size:20px;font-weight:700;margin-top:4px"><?= number_format($counts['apple']) ?></div>
    </div>
    <div style="padding:14px;border:1px solid var(--border);border-radius:10px">
      <div style="font-size:11px;color:var(--text-3);text-transform:uppercase">Spotify</div>
      <div class="tabular" style="font-size:20px;font-weight:700;margin-top:4px"><?= number_format($counts['spotify']) ?></div>
    </div>
    <div style="padding:14px;border:1px solid var(--border);border-radius:10px">
      <div style="font-size:11px;color:var(--text-3);text-transform:uppercase"><?= __('other', 'Otros') ?></div>
      <div class="tabular" style="font-size:20px;font-weight:700;margin-top:4px"><?= number_format($counts['other']) ?></div>
    </div>
  </div>

  <div class="row" style="gap:10px;margin-top:20px;flex-wrap:wrap">
    <button type="button" class="btn btn-primary" onclick="openImportMetricsModal()"><?= icon('upload', 13) ?> <?= __('analytics_import_csv', 'Importar métricas') ?></button>
    <form method="POST" style="margin:0" onsubmit="return confirm('<?= e(__('ext_metrics_confirm_purge', '¿Eliminar permanentemente todas las métricas externas importadas?')) ?>')">
      <?= kp_csrf_field() ?>
      <input type="hidden" name="action" value="purge_external_stats">
      <button type="submit" class="btn" style="color:#ef4444;border-color:#ef444455" <?= $totalRows === 0 ? 'disabled' : '' ?>>
        <?= icon('trash', 13) ?> <?= __('ext_metrics_purge_btn', 'Eliminar métricas importadas') ?> (<?= number_format($totalRows) ?>)
      </button>
    </form>
  </div>
</div>
<?php require_once __DIR__ . '/../../includes/views/modal-import-metrics.php'; ?>
