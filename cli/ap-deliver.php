<?php
// CLI worker · entrega de actividades pendientes a inboxes federados.
// Cron recomendado: cada 1 minuto.
//   * * * * * cd /var/www/kutpod && php php/cli/ap-deliver.php >> /var/log/kutpod-ap.log 2>&1
require_once __DIR__ . '/../activitypub.php';
if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }
$sent = kp_ap_deliver_pending(200);
echo "[" . date('c') . "] entregadas: $sent\n";

$errores = kp_q("SELECT id, inbox, attempts, last_error, next_try FROM ap_delivery WHERE status IN ('pending', 'failed') AND attempts > 0");
if (!empty($errores)) {
    echo "\n=== ENTREGAS PENDIENTES O FALLIDAS ===\n";
    foreach ($errores as $e) {
        echo "- ID {$e['id']} | Inbox: {$e['inbox']} | Intentos: {$e['attempts']} | Error: {$e['last_error']}\n";
    }
}
