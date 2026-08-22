<?php
// Endpoint AJAX para sincronizar reglas anti-bot desde el repo de OP3
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/op3-tracker.php';
kp_require_role('owner','admin');
header('Content-Type: application/json; charset=utf-8');
try {
  if (function_exists('fastcgi_finish_request')) {
    // Devuelve OK inmediato, sincroniza en background
    echo json_encode(['ok' => true, 'starting' => true]);
    fastcgi_finish_request();
  }
  $r = kp_op3_sync_bot_rules_from_github();
  if (!function_exists('fastcgi_finish_request')) echo json_encode($r);
  // Guarda última sincronización (tabla settings opcional)
  kp_db()->exec("CREATE TABLE IF NOT EXISTS settings (k TEXT PRIMARY KEY, v TEXT, updated_at TEXT DEFAULT (datetime('now')))");
  kp_db()->prepare("INSERT INTO settings (k,v) VALUES ('op3_last_sync', ?)
                    ON CONFLICT(k) DO UPDATE SET v = excluded.v, updated_at = datetime('now')")
        ->execute([json_encode(['at' => date('c'), 'result' => $r])]);
  
  // Alerta de analytics
  require_once __DIR__ . '/includes/helpers.php';
  $rulesCount = $r['rules'] ?? $r['count'] ?? 0;
  kp_alert('op3', 'Reglas anti-bot sincronizadas', "Se sincronizaron $rulesCount reglas de detección de bots desde OP3.", '');
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error' => $e->getMessage()]);
}
