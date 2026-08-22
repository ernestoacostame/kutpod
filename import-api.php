<?php
// ============================================================================
// Endpoint AJAX para crear / monitorear jobs de importación
// ============================================================================
// Llamado desde pages/import.php via fetch().

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rss-import.php';

$u = kp_require_role('owner','admin','editor');
header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
  switch ($action) {

    case 'analyze':
      echo json_encode(kp_import_analyze($_POST['url'] ?? ''));
      break;

    case 'create':
      $id = kp_import_create_job(
        $_POST['url'] ?? '',
        !empty($_POST['target_id']) ? (int)$_POST['target_id'] : null,
        json_decode($_POST['options'] ?? '{}', true) ?: []
      );
      // Dispara el worker en background (no bloquea la respuesta HTTP)
      if (function_exists('fastcgi_finish_request')) {
        echo json_encode(['job_id' => $id]);
        session_write_close();
        fastcgi_finish_request();
        try {
          kp_import_run($id);
        } catch (Throwable $e) {
          kp_db()->prepare("UPDATE import_jobs SET status='failed', last_error = ?, finished_at=datetime('now') WHERE id = ?")
                ->execute([$e->getMessage(), $id]);
          kp_import_log($id, "Error fatal: " . $e->getMessage(), 'error');
        }
      } else {
        // Fallback: usar trigger_async para no bloquear
        echo json_encode(['job_id' => $id, 'needs_trigger' => true]);
        exit;
      }
      break;

    case 'status':
      $job = kp_one("SELECT * FROM import_jobs WHERE id = ?", [(int)$_GET['job']]);
      $events = kp_q("SELECT level,message,at FROM import_job_events
                      WHERE job_id = ? ORDER BY id DESC LIMIT 30", [(int)$_GET['job']]);
      echo json_encode(['job' => $job, 'events' => array_reverse($events)]);
      break;

    case 'pause':
      kp_db()->prepare("UPDATE import_jobs SET status='paused' WHERE id = ?")
            ->execute([(int)$_POST['job']]);
      echo json_encode(['ok' => true]);
      break;

    case 'resume':
      $id = (int)$_POST['job'];
      kp_db()->prepare("UPDATE import_jobs SET status='queued' WHERE id = ? AND status='paused'")
            ->execute([$id]);
      if (function_exists('fastcgi_finish_request')) {
        echo json_encode(['ok' => true]);
        session_write_close();
        fastcgi_finish_request();
        try {
          kp_import_run($id);
        } catch (Throwable $e) {
          kp_db()->prepare("UPDATE import_jobs SET status='failed', last_error = ?, finished_at=datetime('now') WHERE id = ?")
                ->execute([$e->getMessage(), $id]);
          kp_import_log($id, "Error fatal: " . $e->getMessage(), 'error');
        }
      } else {
        echo json_encode(['ok' => true, 'needs_trigger' => true]);
        exit;
      }
      break;

    case 'cancel':
      kp_db()->prepare("UPDATE import_jobs SET status='failed', last_error='cancelado', finished_at=datetime('now') WHERE id = ?")
            ->execute([(int)$_POST['job']]);
      echo json_encode(['ok' => true]);
      break;

    case 'run_worker':
      // Fallback para entornos compartidos sin FPM o exec()
      set_time_limit(0);
      ignore_user_abort(true);
      
      // Intentar cerrar la conexión para no bloquear al cliente
      @ob_end_clean();
      header("Connection: close");
      ob_start();
      echo json_encode(['ok' => true]);
      $size = ob_get_length();
      header("Content-Length: $size");
      ob_end_flush();
      @flush();
      if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
      
      $id = (int)($_GET['job'] ?? 0);
      if ($id > 0) {
        try {
          kp_import_run($id);
        } catch (Throwable $e) {
          kp_db()->prepare("UPDATE import_jobs SET status='failed', last_error = ?, finished_at=datetime('now') WHERE id = ?")
                ->execute([$e->getMessage(), $id]);
          kp_import_log($id, "Error fatal: " . $e->getMessage(), 'error');
        }
      }
      exit;

    default:
      http_response_code(400);
      echo json_encode(['error' => 'acción desconocida']);
  }
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error' => $e->getMessage()]);
}
