<?php
// ============================================================================
// CLI worker · ejecuta un job de importación
// ============================================================================
// Uso:
//   php php/cli/import-worker.php <job_id>
//   php php/cli/import-worker.php --queue   (procesa el siguiente queued)
//   php php/cli/import-worker.php --all     (procesa todos los queued)
//
// Recomendado en producción · cron cada minuto:
//   * * * * * cd /var/www/kutpod && php php/cli/import-worker.php --queue >> /var/log/kutpod-import.log 2>&1

require_once __DIR__ . '/../includes/rss-import.php';

if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

$arg = $argv[1] ?? '';
if (!$arg) { fwrite(STDERR, "Uso: import-worker.php <job_id> | --queue | --all\n"); exit(1); }

function pick_one(): ?int {
  $r = kp_one("SELECT id FROM import_jobs WHERE status IN ('queued','paused','running') ORDER BY id LIMIT 1");
  return $r ? (int)$r['id'] : null;
}

function run_one(int $jobId): void {
  $start = microtime(true);
  echo "[" . date('H:i:s') . "] iniciando job $jobId\n";
  try {
    kp_import_run($jobId, function ($s) use ($jobId) {
      printf("  ↳ %d/%d · %s · %.1f ep/s\n", $s['done'], $s['total'], substr($s['title'], 0, 60), $s['rate']);
    });
    printf("[%s] job %d OK · %.1fs\n", date('H:i:s'), $jobId, microtime(true) - $start);
  } catch (Throwable $e) {
    kp_db()->prepare("UPDATE import_jobs SET status='failed', last_error = ?, finished_at=datetime('now') WHERE id = ?")
          ->execute([$e->getMessage(), $jobId]);
    kp_import_log($jobId, "Error fatal: " . $e->getMessage(), 'error');
    kp_alert('import', 'Importación fallida', "El job #$jobId falló: " . $e->getMessage(), '/admin/import');
    fwrite(STDERR, "[ERR] job $jobId: " . $e->getMessage() . "\n");
    exit(2);
  }
}

if ($arg === '--queue') {
  $id = pick_one();
  if (!$id) { echo "(no hay jobs en cola)\n"; exit(0); }
  run_one($id);
} elseif ($arg === '--all') {
  while ($id = pick_one()) run_one($id);
} else {
  run_one((int)$arg);
}
