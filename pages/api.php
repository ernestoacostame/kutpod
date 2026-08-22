<?php
// ============================================================================
// pages/api.php — Tokens API + endpoints + webhooks (Kut Editor)
// ============================================================================
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';

$user = $current_user ?? kp_current_user();
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['_action'] ?? '';
  if ($action === 'create_token') {
    $label = trim($_POST['label'] ?? '');
    if ($label !== '') {
      $raw = 'kpt_live_' . bin2hex(random_bytes(20));
      $hash = hash('sha256', $raw);
      kp_exec("INSERT INTO api_tokens(user_id, label, token_hash, prefix, created_at)
               VALUES(?, ?, ?, ?, strftime('%s','now'))",
              [(int)$user['id'], $label, $hash, substr($raw, 0, 14)]);
      $flash = ['kind'=>'token','token'=>$raw,'label'=>$label];
    }
  } elseif ($action === 'revoke') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) kp_exec("DELETE FROM api_tokens WHERE id = ? AND user_id = ?", [$id, (int)$user['id']]);
    header('Location: /admin/api'); exit;
  }
}

$tokens = [];
try {
  $tokens = kp_q("SELECT id, label, prefix, last_used_at, created_at FROM api_tokens
                  WHERE user_id = ? ORDER BY created_at DESC", [(int)$user['id']]) ?: [];
} catch (Throwable $e) {}

$endpoints = [
  ['GET',    '/api/v1/podcasts',                        'Lista podcasts del usuario autenticado',  '200 · array'],
  ['GET',    '/api/v1/podcasts/{id}',                   'Detalle completo con feed URL',           '200 · object'],
  ['GET',    '/api/v1/podcasts/{id}/seasons',           'Temporadas con conteo de episodios',      '200 · array'],
  ['GET',    '/api/v1/podcasts/{id}/episodes',          'Lista episodios · paginación',            '200 · array'],
  ['POST',   '/api/v1/podcasts/{id}/episodes',          'Crear episodio (audio + metadatos)',      '201 · object'],
  ['PUT',    '/api/v1/episodes/{id}',                   'Actualizar episodio completo',            '200 · object'],
  ['POST',   '/api/v1/episodes/{id}/transcript',        'Subir .srt/.vtt',                         '201'],
  ['POST',   '/api/v1/episodes/{id}/chapters',          'Subir chapters.json',                     '201'],
  ['POST',   '/api/v1/episodes/{id}/cover',             'Subir portada personalizada',             '201'],
  ['DELETE', '/api/v1/episodes/{id}',                   'Eliminar episodio',                       '204'],
  ['GET',    '/api/v1/stats/podcasts/{id}',             'Métricas OP3 por podcast',                '200 · object'],
  ['GET',    '/api/v1/stats/export.csv',                'Exportar estadísticas globales en CSV',   '200 · text/csv'],
];
$method_color = ['GET'=>'#10b981','POST'=>'#0a84ff','PUT'=>'#f59e0b','DELETE'=>'#ef4444'];

$webhooks = [];
try { $webhooks = kp_q("SELECT id, event, url, last_status, last_attempt_at FROM webhooks ORDER BY id") ?: []; }
catch (Throwable $e) {}
?>
<div class="page-head">
  <div>
    <h1 class="page-title">API · Kut Editor</h1>
    <p class="page-sub">REST API para publicar desde Kut Editor (Hindenburg Pro) y herramientas externas · auth Bearer</p>
  </div>
  <div class="row" style="gap:8px">
    <a class="btn" href="/api/v1/docs" target="_blank"><?= icon('file',13) ?> Docs OpenAPI</a>
    <button class="btn btn-primary" type="button" onclick="document.getElementById('newTokenDialog').showModal()">
      <?= icon('plus',13) ?> Nuevo token
    </button>
  </div>
</div>

<?php if ($flash && $flash['kind'] === 'token'): ?>
  <div class="card card-lg" style="padding:20px;margin-bottom:24px;border:1px solid #10b98155;background:#10b98111">
    <div class="row" style="gap:12px;align-items:flex-start">
      <div style="color:#10b981;flex-shrink:0"><?= icon('shield',20) ?></div>
      <div style="flex:1;min-width:0">
        <div style="font-weight:600;margin-bottom:6px">Token «<?= e($flash['label']) ?>» creado</div>
        <div class="muted" style="font-size:12.5px;margin-bottom:10px">Cópialo ahora — no podrás verlo de nuevo. KutPod solo almacena el hash.</div>
        <div style="display:flex;gap:8px;align-items:center">
          <code style="flex:1;background:var(--surface-2);padding:10px 12px;border-radius:8px;font-size:12.5px;overflow:auto;white-space:nowrap"><?= e($flash['token']) ?></code>
          <button class="btn" type="button" onclick="navigator.clipboard.writeText('<?= e($flash['token']) ?>');this.textContent='Copiado'">
            <?= icon('copy',13) ?> Copiar
          </button>
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>

<div class="grid-12" style="gap:24px">
  <!-- Endpoints -->
  <div class="card card-lg span-8" style="padding:28px">
    <div class="card-title" style="margin-bottom:18px">Endpoints</div>
    <div class="list">
      <div style="display:grid;grid-template-columns:80px 1fr 1fr 130px;gap:14px;padding:10px 4px;font-size:11px;color:var(--text-3);text-transform:uppercase;letter-spacing:.06em;font-weight:600;border-bottom:1px solid var(--border)">
        <div>Método</div><div>Ruta</div><div>Descripción</div><div>Respuesta</div>
      </div>
      <?php foreach ($endpoints as [$m, $p, $d, $r]): $c = $method_color[$m] ?? 'var(--text-3)'; ?>
        <div style="display:grid;grid-template-columns:80px 1fr 1fr 130px;gap:14px;padding:14px 4px;align-items:center;border-bottom:1px solid var(--border);font-size:13px">
          <div><span class="tag" style="background:<?= $c ?>22;color:<?= $c ?>;font-family:ui-monospace,monospace;font-size:11px;font-weight:700"><?= $m ?></span></div>
          <div style="font-family:ui-monospace,monospace;font-size:12.5px"><?= e($p) ?></div>
          <div style="color:var(--text-2);font-size:12.5px"><?= e($d) ?></div>
          <div style="font-family:ui-monospace,monospace;font-size:11.5px;color:var(--text-3)"><?= e($r) ?></div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="card-title" style="margin:28px 0 14px;font-size:14px">Ejemplo · publicar episodio</div>
    <pre style="background:#0d0f12;color:#9ca3af;border-radius:10px;padding:18px;font-family:ui-monospace,monospace;font-size:12px;line-height:1.6;overflow:auto;margin:0">
<span style="color:#10b981">POST</span> /api/v1/podcasts/como-pienso-digo/episodes
<span style="color:#6b7280">Authorization:</span> Bearer kpt_live_a8f2...
<span style="color:#6b7280">Content-Type:</span> multipart/form-data

audio=<span style="color:#f59e0b">@episodio-48.mp3</span>
cover=<span style="color:#f59e0b">@cover.jpg</span>
title=<span style="color:#10b981">"Charla con Eric Miles"</span>
season=<span style="color:#f59e0b">4</span>
episode=<span style="color:#f59e0b">48</span>
type=<span style="color:#10b981">"full"</span>
publish_at=<span style="color:#10b981">"2026-05-12T10:00:00Z"</span>
transcript=<span style="color:#f59e0b">@transcript.srt</span>
chapters=<span style="color:#f59e0b">@chapters.json</span>
notes_markdown=<span style="color:#10b981">"## Resumen..."</span></pre>
  </div>

  <!-- Tokens activos -->
  <div class="card card-lg span-4" style="padding:28px">
    <div class="card-head"><div class="card-title">Tus tokens</div></div>
    <?php if (!$tokens): ?>
      <div class="muted" style="padding:24px 0;text-align:center;font-size:13px">
        Aún no tienes tokens. Crea uno para conectar Kut Editor.
      </div>
    <?php else: ?>
      <div class="col" style="gap:10px;margin-top:14px">
        <?php foreach ($tokens as $t):
          $last = $t['last_used_at'] ? kp_relative_time((int)$t['last_used_at']) : 'nunca usado';
          $color = $t['last_used_at'] ? '#10b981' : 'var(--text-3)';
        ?>
          <div style="padding:14px;border:1px solid var(--border);border-radius:11px">
            <div class="row" style="justify-content:space-between;align-items:flex-start">
              <div style="font-weight:500;font-size:13.5px"><?= e($t['label']) ?></div>
              <span class="tag" style="background:<?= $color ?>22;color:<?= $color ?>;font-size:11px">activo</span>
            </div>
            <div style="font-family:ui-monospace,monospace;font-size:11.5px;color:var(--text-3);margin-top:6px"><?= e($t['prefix']) ?>…</div>
            <div style="font-size:11.5px;color:var(--text-3);margin-top:6px">Último uso: <?= e($last) ?></div>
            <div class="row" style="gap:6px;margin-top:10px">
              <form method="POST" style="display:inline" onsubmit="return confirm('¿Revocar este token?')">
                <?= kp_csrf_field() ?>
                <input type="hidden" name="_action" value="revoke"/>
                <input type="hidden" name="id" value="<?= (int)$t['id'] ?>"/>
                <button class="btn" type="submit" style="font-size:11.5px;padding:5px 9px;color:#ef4444">Revocar</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <div style="margin-top:18px;padding:14px;background:var(--surface-2);border-radius:10px;font-size:12px;color:var(--text-3);line-height:1.5">
      <?= icon('eye',13) ?> Los tokens se muestran <b>una sola vez</b>. KutPod guarda solo el hash SHA-256.
    </div>
  </div>
</div>

<!-- Webhooks -->
<div class="card card-lg" style="padding:28px;margin-top:24px">
  <div class="card-head">
    <div class="card-title">Webhooks salientes</div>
    <a class="btn" href="/admin/api/webhooks/new"><?= icon('plus',13) ?> Añadir webhook</a>
  </div>
  <div class="help" style="margin:8px 0 14px">KutPod notifica a estas URLs cuando ocurren eventos en tus podcasts.</div>
  <?php if (!$webhooks): ?>
    <div class="muted" style="padding:32px 0;text-align:center;font-size:13px">
      Sin webhooks configurados. Útil para sincronizar con servicios externos en tiempo real.
    </div>
  <?php else: ?>
    <div class="list">
      <?php foreach ($webhooks as $w):
        $st = $w['last_status'] ?? null;
        $col = ($st && $st >= 200 && $st < 300) ? '#10b981' : ($st ? '#ef4444' : 'var(--text-3)');
      ?>
        <div style="display:grid;grid-template-columns:200px 1fr 130px 40px;gap:14px;padding:14px 4px;align-items:center;border-bottom:1px solid var(--border);font-size:13px">
          <div style="font-family:ui-monospace,monospace;font-size:12.5px;color:var(--accent)"><?= e($w['event']) ?></div>
          <div style="font-family:ui-monospace,monospace;font-size:12px;color:var(--text-2);overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($w['url']) ?></div>
          <div><span class="tag" style="background:<?= $col ?>22;color:<?= $col ?>;font-size:11px"><?= e($st ?? 'pendiente') ?></span></div>
          <a class="kebab" href="/admin/api/webhooks/<?= (int)$w['id'] ?>"><?= icon('more',14) ?></a>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<!-- Dialog: nuevo token -->
<dialog id="newTokenDialog" style="border:none;border-radius:14px;background:var(--surface);color:var(--text-1);padding:0;max-width:480px;width:90vw;box-shadow:0 24px 60px #0008">
  <form method="POST" style="padding:28px">
    <?= kp_csrf_field() ?>
    <input type="hidden" name="_action" value="create_token"/>
    <div class="card-title" style="margin-bottom:14px">Nuevo token API</div>
    <div class="help" style="margin-bottom:18px">Dale un nombre descriptivo para identificarlo (ej. «Kut Editor · Mac», «CI GitHub Actions»).</div>
    <label class="label">Nombre</label>
    <input class="input" type="text" name="label" placeholder="Kut Editor · Mac" required autofocus/>
    <div class="row" style="justify-content:flex-end;gap:8px;margin-top:22px">
      <button class="btn" type="button" onclick="this.closest('dialog').close()">Cancelar</button>
      <button class="btn btn-primary" type="submit"><?= icon('plus',13) ?> Generar token</button>
    </div>
  </form>
</dialog>
