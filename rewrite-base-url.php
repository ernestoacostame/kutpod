<?php
// Endpoint para reescribir la URL base en toda la BD
// POST: from=https://old.example.com to=https://new.example.com [dry=1]
// Devuelve JSON con resumen de filas modificadas por tabla.
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
kp_require_role('owner');
header('Content-Type: application/json; charset=utf-8');

$from = rtrim(trim($_POST['from'] ?? ''), '/');
$to   = rtrim(trim($_POST['to']   ?? ''), '/');
$dry  = !empty($_POST['dry']);

if (!preg_match('#^https?://[a-z0-9.\-]+#i', $from) || !preg_match('#^https?://[a-z0-9.\-]+#i', $to)) {
  http_response_code(400);
  exit(json_encode(['error' => 'URLs inválidas. Deben empezar por http:// o https://']));
}
if ($from === $to) exit(json_encode(['ok' => true, 'noop' => true, 'changes' => []]));

$pdo = kp_db();

// Mapa: tabla → columnas a reescribir (todas las que pueden contener URLs absolutas)
$targets = [
  'podcasts' => ['cover','banner','custom_tags'],
  'episodes' => ['audio_url','cover','transcript_url','chapters_url','custom_tags','notes_md'],
  'pages'    => ['blocks_json'],
];
// + storage/pages/*.json (file-based pages)

$report = [];
if (!$dry) $pdo->beginTransaction();

try {
  foreach ($targets as $table => $cols) {
    // Algunas tablas pueden no existir (p.ej. pages aún no migrada)
    try { $pdo->query("SELECT 1 FROM $table LIMIT 1"); }
    catch (Throwable $e) { continue; }

    $rows = kp_q("SELECT id, " . implode(',', $cols) . " FROM $table");
    $changed = 0;
    foreach ($rows as $r) {
      $diff = false;
      $upd = [];
      foreach ($cols as $c) {
        $old = $r[$c] ?? '';
        $new = $old !== '' ? str_replace($from, $to, $old) : '';
        if ($new !== $old) { $diff = true; $upd[$c] = $new; }
      }
      if ($diff) {
        $changed++;
        if (!$dry) {
          $set = implode(',', array_map(fn($k) => "$k = ?", array_keys($upd)));
          $pdo->prepare("UPDATE $table SET $set WHERE id = ?")
              ->execute([...array_values($upd), $r['id']]);
        }
      }
    }
    if ($changed) $report[$table] = $changed;
  }

  // Páginas estáticas en archivos JSON
  $pageDir = __DIR__ . '/storage/pages';
  $pageChanges = 0;
  foreach (glob($pageDir . '/*.json') ?: [] as $f) {
    $raw = file_get_contents($f);
    $new = str_replace($from, $to, $raw);
    if ($new !== $raw) {
      $pageChanges++;
      if (!$dry) file_put_contents($f, $new);
    }
  }
  if ($pageChanges) $report['pages.json'] = $pageChanges;

  // settings · dominio
  try {
    $row = kp_one("SELECT v FROM settings WHERE k = 'instance_domain'");
    if ($row) {
      $oldDomain = $row['v'];
      $newDomain = parse_url($to, PHP_URL_HOST) ?: $to; // Fallback to raw string if parsing fails
      if ($oldDomain !== $newDomain) {
        if (!$dry) {
          $pdo->prepare("INSERT INTO settings (k,v) VALUES ('instance_domain',?)
                         ON CONFLICT(k) DO UPDATE SET v=excluded.v, updated_at=datetime('now')")
              ->execute([$newDomain]);
        }
        $report['settings.instance_domain'] = "$oldDomain → $newDomain";
      }
    }
  } catch (Throwable $e) {}

  // Log de la operación
  if (!$dry) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (k TEXT PRIMARY KEY, v TEXT, updated_at TEXT DEFAULT (datetime('now')))");
    $pdo->prepare("INSERT INTO settings (k,v) VALUES ('last_url_rewrite', ?)
                   ON CONFLICT(k) DO UPDATE SET v=excluded.v, updated_at=datetime('now')")
        ->execute([json_encode(['from'=>$from,'to'=>$to,'at'=>date('c'),'changes'=>$report])]);
    $pdo->commit();
  }

  echo json_encode(['ok' => true, 'dry' => $dry, 'from' => $from, 'to' => $to,
                    'changes' => $report, 'total' => array_sum(array_filter($report, 'is_int'))]);
} catch (Throwable $e) {
  if (!$dry && $pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo json_encode(['error' => $e->getMessage()]);
}
