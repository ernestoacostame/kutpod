<?php
// ============================================================================
// KutPod · Opciones del Plugin Apple Podcasts Reporter
// ============================================================================

declare(strict_types=1);

if (!defined('KUTPOD_VERSION')) {
    exit;
}

require_once __DIR__ . '/apple_reporter.php';

kp_apple_reporter_init_db();

$podcasts = kp_q("SELECT id, title, slug, cover FROM podcasts WHERE hidden = 0 ORDER BY title ASC") ?: [];

$msg = '';
$error = '';
$syncResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action_type'] ?? 'save_settings';

    if ($action === 'save_settings') {
        kp_update_setting('apple_reporter_access_token', trim($_POST['apple_reporter_access_token'] ?? ''));
        kp_update_setting('apple_reporter_user_id', trim($_POST['apple_reporter_user_id'] ?? ''));
        kp_update_setting('apple_reporter_vendor_id', trim($_POST['apple_reporter_vendor_id'] ?? ''));
        kp_update_setting('apple_reporter_auto_sync', isset($_POST['apple_reporter_auto_sync']) ? '1' : '0');

        $msg = 'Configuración de Apple Podcasts Reporter guardada correctamente.';
    } elseif ($action === 'test_connection') {
        $testRes = kp_apple_reporter_test_connection($err);
        if ($err) {
            $error = 'Fallo en la prueba de conexión: ' . htmlspecialchars($err);
        } else {
            $msg = '✅ ' . htmlspecialchars($testRes['message'] ?? 'Conexión verificada con Apple.');
        }
    } elseif ($action === 'sync_now') {
        $podId  = (int)($_POST['sync_podcast_id'] ?? 0);
        $vendor = trim($_POST['sync_vendor_id'] ?? kp_setting('apple_reporter_vendor_id', ''));
        $date   = trim($_POST['sync_date'] ?? date('Ymd', strtotime('-2 days')));

        if ($vendor === '') {
            $error = 'Debes configurar tu Vendor ID (ej. 89123456) antes de sincronizar.';
        } elseif ($podId <= 0) {
            $error = 'Selecciona un podcast para vincular los episodios.';
        } else {
            $cleanDate = preg_replace('/[^0-9]/', '', $date);
            $tsv = kp_apple_reporter_fetch_report($vendor, 'Podcast', 'apEpisodeListening', 'Daily', $cleanDate, $err);
            if ($err) {
                $error = 'Apple Reporter respondió: ' . htmlspecialchars($err);
            } elseif ($tsv) {
                $ingest = kp_apple_reporter_ingest_content($podId, $tsv, $cleanDate);
                $msg = "✅ Reporte de Apple descargado con éxito. Se vincularon {$ingest['matched']} episodios ({$ingest['total']} registros en el reporte).";
                $syncResult = $ingest;
            } else {
                $error = 'No se recibieron datos para la fecha seleccionada (' . htmlspecialchars($cleanDate) . ').';
            }
        }
    }
}

$token    = kp_setting('apple_reporter_access_token', '');
$userId   = kp_setting('apple_reporter_user_id', '');
$vendorId = kp_setting('apple_reporter_vendor_id', '');
$autoSync = kp_setting('apple_reporter_auto_sync', '0') === '1';

$logs = kp_q("
    SELECT l.*, p.title as podcast_title 
    FROM apple_reporter_logs l 
    LEFT JOIN podcasts p ON p.id = l.podcast_id 
    ORDER BY l.id DESC LIMIT 10
") ?: [];
?>

<div class="card card-lg" style="margin-bottom:24px">
  <div class="row" style="justify-content:space-between;align-items:center;margin-bottom:14px">
    <div>
      <h2 style="margin:0;font-size:18px;display:flex;align-items:center;gap:8px">
        <span></span> Apple Podcasts Reporter (Cliente Nativo)
      </h2>
      <p class="muted" style="margin:4px 0 0;font-size:12.5px">
        Descarga de forma automatizada y desatendida los reportes oficiales de <code>apEpisodeListening</code> desde Apple Podcasts Connect.
      </p>
    </div>
    <span class="tag" style="background:rgba(255,255,255,0.08);color:var(--text-1)">v1.0.0</span>
  </div>

  <?php if ($msg): ?>
    <div style="background:rgba(34,197,94,0.15);color:#22c55e;padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:13px">
      <?= e($msg) ?>
    </div>
  <?php endif; ?>

  <?php if ($error): ?>
    <div style="background:rgba(239,68,68,0.15);color:#ef4444;padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:13px">
      <?= e($error) ?>
    </div>
  <?php endif; ?>

  <form method="POST" style="margin-top:16px">
    <input type="hidden" name="action_type" value="save_settings">
    <?= kp_csrf_field() ?>

    <div class="stats-two-col" style="gap:20px">
      <div class="stats-col">
        <div class="field" style="margin-bottom:14px">
          <label class="label" style="font-size:12px;font-weight:600">Access Token de Apple</label>
          <input class="input" type="password" name="apple_reporter_access_token" value="<?= e($token) ?>" placeholder="Generado desde Podcasts Connect..." required style="width:100%;font-family:monospace">
          <div style="font-size:11.5px;color:var(--text-3);margin-top:4px">
            Puedes generar tu token de acceso en Apple Podcasts Connect o mediante la línea de comandos de Apple.
          </div>
        </div>

        <div class="field" style="margin-bottom:14px">
          <label class="label" style="font-size:12px;font-weight:600">Vendor ID (Número de cuenta)</label>
          <input class="input" type="text" name="apple_reporter_vendor_id" value="<?= e($vendorId) ?>" placeholder="ej. 89123456" style="width:100%">
          <div style="font-size:11.5px;color:var(--text-3);margin-top:4px">
            Tu código de proveedor numérico visible en Apple Podcasts Connect (sección Información de la cuenta).
          </div>
        </div>
      </div>

      <div class="stats-col">
        <div class="field" style="margin-bottom:14px">
          <label class="label" style="font-size:12px;font-weight:600">User ID / Apple ID (Opcional)</label>
          <input class="input" type="text" name="apple_reporter_user_id" value="<?= e($userId) ?>" placeholder="usuario@apple.com" style="width:100%">
        </div>

        <div class="field" style="margin-bottom:14px">
          <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;margin-top:24px">
            <input type="checkbox" name="apple_reporter_auto_sync" value="1" <?= $autoSync ? 'checked' : '' ?>>
            <span>Sincronizar automáticamente reportes diarios de escucha en segundo plano</span>
          </label>
        </div>
      </div>
    </div>

    <div class="row" style="gap:10px;margin-top:16px;border-top:1px solid var(--border);padding-top:16px">
      <button type="submit" class="btn btn-primary"><?= icon('check', 13) ?> Guardar Configuración</button>
      <button type="submit" name="action_type" value="test_connection" class="btn"><?= icon('refreshCw', 13) ?> Probar Conexión con Apple</button>
    </div>
  </form>
</div>

<!-- Tarjeta de Sincronización Manual Inmediata -->
<div class="card card-lg" style="margin-bottom:24px">
  <div class="card-title" style="margin-bottom:10px;font-size:15px">
    <?= icon('download', 16) ?> Descargar y Sincronizar Reporte Específico
  </div>
  <p class="muted" style="font-size:12.5px;margin:0 0 16px 0">
    Apple genera reportes diarios de escucha con 24-48 horas de retraso. Puedes seleccionar una fecha pasada y descargar directamente el reporte hacia KutPod.
  </p>

  <form method="POST" class="row" style="gap:12px;align-items:flex-end;flex-wrap:wrap">
    <input type="hidden" name="action_type" value="sync_now">
    <?= kp_csrf_field() ?>

    <div style="flex:1;min-width:200px">
      <label class="label" style="font-size:11.5px;font-weight:600;margin-bottom:4px">Podcast</label>
      <select class="select" name="sync_podcast_id" required style="width:100%">
        <?php foreach ($podcasts as $p): ?>
          <option value="<?= (int)$p['id'] ?>"><?= e($p['title']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div style="width:160px">
      <label class="label" style="font-size:11.5px;font-weight:600;margin-bottom:4px">Fecha del reporte</label>
      <input class="input" type="date" name="sync_date" value="<?= date('Y-m-d', strtotime('-2 days')) ?>" style="width:100%">
    </div>

    <input type="hidden" name="sync_vendor_id" value="<?= e($vendorId) ?>">

    <div>
      <button type="submit" class="btn btn-primary" style="display:inline-flex;align-items:center;gap:6px">
        <?= icon('download', 13) ?> Descargar de Apple
      </button>
    </div>
  </form>
</div>

<!-- Historial de sincronizaciones -->
<div class="card card-lg">
  <div class="card-title" style="margin-bottom:14px;font-size:15px">
    <?= icon('clock', 16) ?> Historial de Sincronizaciones con Apple Reporter
  </div>

  <?php if (empty($logs)): ?>
    <div style="text-align:center;padding:24px;color:var(--text-3);font-size:12.5px">
      No hay sincronizaciones registradas todavía.
    </div>
  <?php else: ?>
    <div style="overflow-x:auto">
      <table style="width:100%;border-collapse:collapse;font-size:12.5px">
        <thead>
          <tr style="border-bottom:1px solid var(--border);color:var(--text-3);text-align:left;font-size:11px;text-transform:uppercase">
            <th style="padding:8px">Fecha</th>
            <th style="padding:8px">Podcast</th>
            <th style="padding:8px">Tipo de Reporte</th>
            <th style="padding:8px;text-align:right">Episodios</th>
            <th style="padding:8px">Mensaje</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($logs as $l): ?>
            <tr style="border-bottom:1px solid var(--border)">
              <td class="tabular" style="padding:8px"><?= e($l['report_date']) ?></td>
              <td style="padding:8px"><?= e($l['podcast_title'] ?? '—') ?></td>
              <td style="padding:8px"><code><?= e($l['report_type']) ?></code></td>
              <td class="tabular" style="padding:8px;text-align:right"><?= (int)$l['matched_episodes'] ?></td>
              <td style="padding:8px;color:var(--text-2)"><?= e($l['message']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
