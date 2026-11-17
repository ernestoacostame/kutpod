<?php
// ============================================================================
// pages/queue.php — Cola de procesos en segundo plano
// ============================================================================
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['_action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    
    if ($id && $action === 'delete') {
        kp_db()->prepare("DELETE FROM ap_delivery WHERE id = ?")->execute([$id]);
    } elseif ($id && $action === 'retry') {
        kp_db()->prepare("UPDATE ap_delivery SET status='pending', attempts=0, next_try=datetime('now') WHERE id = ?")->execute([$id]);
        require_once __DIR__ . '/../activitypub.php';
        kp_ap_trigger_delivery();
    } elseif ($id && $action === 'stop') {
        kp_db()->prepare("UPDATE ap_delivery SET status='failed' WHERE id = ?")->execute([$id]);
    } elseif ($id && $action === 'remove_follower') {
        require_once __DIR__ . '/../activitypub.php';
        $row = kp_one("SELECT inbox FROM ap_delivery WHERE id = ?", [$id]);
        if ($row && !empty($row['inbox'])) {
            kp_ap_remove_follower_by_inbox($row['inbox'], "Eliminado manualmente desde la cola");
        }
        kp_db()->prepare("DELETE FROM ap_delivery WHERE id = ?")->execute([$id]);
        header('Location: /admin/queue?deleted_follower=1');
        exit;
    } elseif ($action === 'purge_failed') {
        require_once __DIR__ . '/../activitypub.php';
        $failed = kp_q("SELECT DISTINCT inbox FROM ap_delivery WHERE status = 'failed' OR attempts >= 3");
        $purgedCount = 0;
        foreach ($failed as $f) {
            if (!empty($f['inbox'])) {
                kp_ap_remove_follower_by_inbox($f['inbox'], "Purga masiva de seguidores con errores");
                $purgedCount++;
            }
        }
        header('Location: /admin/queue?purged=' . $purgedCount);
        exit;
    } elseif ($action === 'process_queue') {
        require_once __DIR__ . '/../activitypub.php';
        $sent = kp_ap_deliver_pending(100);
        header('Location: /admin/queue?sent=' . $sent);
        exit;
    }
    
    header('Location: /admin/queue');
    exit;
}

$queue = [];
try {
    $queue = kp_q("
        SELECT d.id, d.inbox, d.status, d.attempts, d.last_error, d.next_try, d.delivered_at,
               o.type as activity_type, p.title as podcast_title
        FROM ap_delivery d
        JOIN ap_outbox o ON d.outbox_id = o.id
        JOIN podcasts p ON o.podcast_id = p.id
        ORDER BY d.id DESC LIMIT 100
    ");
} catch (Throwable $e) {}

$status_colors = [
    'pending' => '#f59e0b',
    'delivered' => '#10b981',
    'failed' => '#ef4444'
];

?>
<div class="page-head">
  <div>
    <h1 class="page-title"><?= __('queue_title', 'Cola de Procesos') ?></h1>
    <p class="page-sub"><?= __('queue_sub', 'Gestión de envíos al Fediverso y otras tareas en segundo plano.') ?></p>
  </div>
  <div class="row" style="gap:8px">
    <form method="POST" style="margin: 0;" onsubmit="return confirm('<?= e(__('queue_confirm_purge_failed', '¿Deseas eliminar permanentemente de los seguidores todas las direcciones con errores o intentos fallidos?')) ?>');">
      <?= kp_csrf_field() ?>
      <button type="submit" name="_action" value="purge_failed" class="btn" style="display: flex; align-items: center; gap: 6px; color: #ef4444;">
        <?= icon('userMinus', 14) ?> <?= __('queue_purge_failed_btn', 'Limpiar seguidores con error') ?>
      </button>
    </form>
    <form method="POST" style="margin: 0;">
      <?= kp_csrf_field() ?>
      <button type="submit" name="_action" value="process_queue" class="btn btn-primary" style="display: flex; align-items: center; gap: 6px;">
        <?= icon('play', 14) ?> <?= __('queue_process_btn', 'Procesar Cola') ?>
      </button>
    </form>
  </div>
</div>

<?php if (isset($_GET['purged'])): ?>
  <div class="card card-lg" style="margin-bottom: 20px; border-left: 4px solid #10b981; padding: 16px;">
    <div style="display: flex; align-items: center; gap: 8px; font-weight: 600; color: #10b981;">
      <?= icon('check', 16) ?>
      <?= __('queue_proc_done', 'Proceso completado') ?>
    </div>
    <p style="margin: 8px 0 0; font-size: 14px; color: var(--text-2);">
      <?= __raw('queue_purged_count', 'Se han eliminado <strong>:count</strong> seguidores inaccesibles o con errores.', ['count' => (int)$_GET['purged']]) ?>
    </p>
  </div>
<?php endif; ?>

<?php if (isset($_GET['sent'])): ?>
  <div class="card card-lg" style="margin-bottom: 20px; border-left: 4px solid var(--primary); padding: 16px;">
    <div style="display: flex; align-items: center; gap: 8px; font-weight: 600; color: var(--primary);">
      <?= icon('check', 16) ?>
      <?= __('queue_proc_done', 'Proceso completado') ?>
    </div>
    <p style="margin: 8px 0 0; font-size: 14px; color: var(--text-2);">
      <?= __raw('queue_proc_done_desc', 'Se han procesado e intentado entregar <strong>:count</strong> actividades a los servidores federados.', ['count' => (int)$_GET['sent']]) ?>
    </p>
  </div>
<?php endif; ?>

<?php if (!$queue): ?>
  <div class="card card-lg" style="text-align:center;padding:64px 24px">
    <div style="font-size:48px;margin-bottom:12px;opacity:.4"><?= icon('layers',48) ?></div>
    <h3 style="margin:0 0 8px"><?= __('queue_empty_title', 'Cola vacía') ?></h3>
    <p class="muted" style="margin:0"><?= __('queue_empty_desc', 'No hay procesos en espera ni historiales recientes de entrega al Fediverso.') ?></p>
  </div>
<?php else: ?>
  <div class="card card-lg" style="padding:0; overflow-x: auto;">
    <table class="table" style="width:100%; text-align:left; border-collapse: collapse;">
      <thead>
        <tr style="border-bottom: 1px solid var(--border);">
          <th style="padding: 12px; font-weight: 500;"><?= __('queue_th_id', 'ID') ?></th>
          <th style="padding: 12px; font-weight: 500;"><?= __('queue_th_podcast', 'Podcast') ?></th>
          <th style="padding: 12px; font-weight: 500;"><?= __('queue_th_target_type', 'Destino / Tipo') ?></th>
          <th style="padding: 12px; font-weight: 500;"><?= __('queue_th_status', 'Estado') ?></th>
          <th style="padding: 12px; font-weight: 500; text-align: center;"><?= __('queue_th_attempts', 'Intentos') ?></th>
          <th style="padding: 12px; font-weight: 500; text-align: right;"><?= __('queue_th_actions', 'Acciones') ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($queue as $q):
            $c = $status_colors[$q['status']] ?? '#6b7280';
        ?>
          <tr style="border-bottom: 1px solid var(--border);">
            <td style="padding: 12px; font-size: 13px;">#<?= $q['id'] ?></td>
            <td style="padding: 12px; font-size: 13px;"><b><?= e($q['podcast_title']) ?></b></td>
            <td style="padding: 12px; font-size: 13px;">
              <div style="max-width: 200px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?= e($q['inbox']) ?>">
                <?= e($q['inbox']) ?>
              </div>
              <span class="muted" style="font-size: 11px;">(<?= e($q['activity_type']) ?>)</span>
            </td>
            <td style="padding: 12px; font-size: 13px;">
              <span style="display:inline-block; padding: 2px 6px; border-radius: 4px; background: <?= $c ?>22; color: <?= $c ?>; font-weight: 600; font-size: 11px; text-transform: uppercase;">
                <?= e($q['status']) ?>
              </span>
              <?php if ($q['status'] === 'failed' && $q['last_error']): ?>
                 <div style="font-size: 11px; color: var(--text-3); margin-top: 4px; max-width: 200px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?= e($q['last_error']) ?>">
                   <?= __('queue_error', 'Error:') ?> <?= e($q['last_error']) ?>
                 </div>
              <?php endif; ?>
              <?php if ($q['status'] === 'pending' && $q['next_try']): ?>
                 <div style="font-size: 11px; color: var(--text-3); margin-top: 4px;" title="<?= __('queue_next_attempt', 'Próximo intento') ?>">
                   <?= __('queue_next', 'Próximo:') ?> <?= e($q['next_try']) ?>
                 </div>
              <?php endif; ?>
            </td>
            <td style="padding: 12px; font-size: 13px; text-align: center;"><?= $q['attempts'] ?></td>
            <td style="padding: 12px; font-size: 13px; text-align: right; white-space: nowrap;">
                <form method="POST" style="display:inline">
                   <?= kp_csrf_field() ?>
                   <input type="hidden" name="id" value="<?= $q['id'] ?>"/>
                   <?php if ($q['status'] === 'failed' || $q['status'] === 'delivered'): ?>
                      <button class="btn btn-ghost" type="submit" name="_action" value="retry" title="<?= __('queue_retry', 'Reintentar') ?>"><?= icon('refreshCw', 14) ?></button>
                   <?php elseif ($q['status'] === 'pending'): ?>
                      <button class="btn btn-ghost" type="submit" name="_action" value="stop" title="<?= __('queue_stop', 'Detener') ?>"><?= icon('x', 14) ?></button>
                   <?php endif; ?>
                   <button class="btn btn-ghost" type="submit" name="_action" value="remove_follower" title="<?= e(__('queue_remove_follower', 'Eliminar seguidor e inbox')) ?>" style="color: #f97316;" onclick="return confirm('<?= e(__('queue_confirm_remove_follower', '¿Deseas eliminar permanentemente a este seguidor de la lista de entrega?')) ?>');"><?= icon('userMinus', 14) ?></button>
                   <button class="btn btn-ghost" type="submit" name="_action" value="delete" title="<?= __('delete', 'Eliminar') ?>" style="color: #ef4444;" onclick="return confirm('<?= e(__('queue_confirm_delete', '¿Seguro que deseas eliminar este proceso?')) ?>');"><?= icon('trash', 14) ?></button>
                </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php
$log_lines = [];
$log_file = __DIR__ . '/../storage/activitypub.log';
if (file_exists($log_file)) {
    $lines = file($log_file);
    if ($lines) {
        $log_lines = array_slice($lines, -35);
        $log_lines = array_reverse($log_lines);
    }
}
?>
<div class="card card-lg" style="margin-top:24px; padding:20px;">
  <h3 style="margin:0 0 12px; display:flex; align-items:center; gap:8px">
    <?= icon('file', 16) ?> <?= __('queue_log_title', 'Registro de Actividad (activitypub.log)') ?>
  </h3>
  <?php if (empty($log_lines)): ?>
    <p class="muted" style="margin:0; font-size:13px;"><?= __('queue_log_empty', 'No hay registros de actividad recientes en el Fediverso.') ?></p>
  <?php else: ?>
    <div style="background:#0f172a; color:#f8fafc; font-family:monospace; font-size:11px; padding:16px; border-radius:6px; max-height:350px; overflow-y:auto; white-space:pre-wrap; line-height:1.5;">
      <?php foreach ($log_lines as $line): ?>
        <?= htmlspecialchars($line) ?>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
