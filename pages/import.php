<?php
// pages/import.php — UI conectada al motor real (import-api.php)
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';

$targets = kp_q("SELECT id, title FROM podcasts ORDER BY title");
?>
<div class="page-head">
  <div>
    <h1 class="page-title">Importar RSS</h1>
    <p class="page-sub">Streaming · idempotente · resumible. Probado con feeds de 1000+ episodios.</p>
  </div>
</div>

<div class="grid-12" style="gap:24px">
  <div class="card card-lg span-8">
    <div class="card-title" style="margin-bottom:14px">Origen del feed</div>
    <div class="field"><label class="label">URL del feed RSS</label>
      <input class="input" id="imp_url" type="url" placeholder="https://anchor.fm/s/.../podcast/rss" style="font-family:ui-monospace,monospace">
    </div>
    <div class="field" style="margin-top:14px"><label class="label">Destino</label>
      <select class="select" id="imp_target">
        <option value="">— Crear un podcast nuevo —</option>
        <?php foreach ($targets as $t): ?>
          <option value="<?= (int)$t['id'] ?>">Mergear en: <?= e($t['title']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col" style="gap:8px;margin-top:18px">
      <label class="toggle-row"><span><strong>Preservar GUIDs originales</strong></span><label class="toggle"><input type="checkbox" data-opt="preserve_guid" checked><span class="toggle-track"></span></label></label>
      <label class="toggle-row"><span><strong>Preservar fechas de publicación</strong></span><label class="toggle"><input type="checkbox" data-opt="preserve_dates" checked><span class="toggle-track"></span></label></label>
      <label class="toggle-row"><span><strong>Descargar archivos de audio a tu servidor</strong></span><label class="toggle"><input type="checkbox" data-opt="download_audio" checked><span class="toggle-track"></span></label></label>
      <label class="toggle-row"><span><strong>Descargar portadas</strong></span><label class="toggle"><input type="checkbox" data-opt="download_images" checked><span class="toggle-track"></span></label></label>
      <label class="toggle-row"><span><strong>Importar namespaces Podcasting 2.0</strong></span><label class="toggle"><input type="checkbox" data-opt="podcast_2_0" checked><span class="toggle-track"></span></label></label>
      <label class="toggle-row"><span><strong>Continuar si un episodio falla</strong><br><span class="help">Recomendado para feeds grandes</span></span><label class="toggle"><input type="checkbox" data-opt="resume_on_fail" checked><span class="toggle-track"></span></label></label>
      <label class="toggle-row"><span><strong>Crear respaldo automático antes de importar</strong></span><label class="toggle"><input type="checkbox" data-opt="auto_backup" checked><span class="toggle-track"></span></label></label>
    </div>
    <div class="row" style="gap:10px;margin-top:18px">
      <button class="btn" type="button" onclick="KP_IMP.analyze()"><?= icon('search',13) ?> Analizar feed</button>
      <button class="btn btn-primary" type="button" onclick="KP_IMP.start()"><?= icon('download',13) ?> Iniciar importación</button>
    </div>
  </div>

  <div class="card card-lg span-4">
    <div class="card-title" style="margin-bottom:14px">Vista previa</div>
    <div id="imp_preview" class="help" style="min-height:200px">Analiza un feed para ver título, episodios y namespaces detectados.</div>
  </div>

  <div class="card card-lg span-12" id="imp_progress" hidden>
    <div class="card-head"><div class="card-title">Progreso</div>
      <div class="row" style="gap:8px">
        <button class="btn btn-sm" type="button" onclick="KP_IMP.pause()">Pausar</button>
        <button class="btn btn-sm" type="button" onclick="KP_IMP.resume()">Reanudar</button>
        <button class="btn btn-sm" type="button" onclick="KP_IMP.cancel()">Cancelar</button>
      </div>
    </div>
    <div class="progress-bar" style="margin-top:14px"><div class="progress-fill" id="imp_fill"></div></div>
    <div class="row tabular" style="gap:24px;margin-top:10px;font-size:13px;color:var(--text-2)">
      <span><strong id="imp_done">0</strong> / <span id="imp_total">0</span> episodios</span>
      <span><strong id="imp_rate">0</strong> ep/s</span>
      <span><strong id="imp_mb">0</strong> MB descargados</span>
      <span>ETA: <strong id="imp_eta">—</strong></span>
      <span>Errores: <strong id="imp_failed">0</strong></span>
    </div>
    <div class="terminal" id="imp_log" style="margin-top:14px;max-height:280px;overflow:auto;font-family:ui-monospace,monospace;font-size:12px;background:var(--surface-2);padding:14px;border-radius:8px"></div>
  </div>
</div>

<style>
.progress-bar { height: 8px; background: var(--surface-2); border-radius: 999px; overflow: hidden; }
.progress-fill { height: 100%; background: var(--accent); width: 0%; transition: width 300ms; }
.terminal .lvl-info { color: var(--text-2); }
.terminal .lvl-warn { color: #f59e0b; }
.terminal .lvl-error { color: #f87171; }
</style>

<script>
window.KP_IMP = (function () {
  let jobId = null, pollId = null;
  const $ = s => document.querySelector(s);

  function opts() {
    const o = {};
    document.querySelectorAll('[data-opt]').forEach(i => o[i.dataset.opt] = i.checked);
    return o;
  }

  async function analyze() {
    const url = $('#imp_url').value.trim();
    if (!url) return;
    $('#imp_preview').innerHTML = '<div class="help">Analizando…</div>';
    const fd = new FormData(); fd.append('url', url);
    const r = await fetch('/import-api.php?action=analyze', { method: 'POST', body: fd }).then(r => r.json());
    if (r.error) { $('#imp_preview').innerHTML = `<div class="error">${r.error}</div>`; return; }
    $('#imp_preview').innerHTML = `
      <div class="row" style="gap:14px;align-items:flex-start">
        ${r.image ? `<img src="${r.image}" style="width:96px;height:96px;border-radius:10px;object-fit:cover">` : ''}
        <div style="flex:1">
          <div style="font-weight:600;font-size:15px">${r.title || '(sin título)'}</div>
          <div class="help" style="margin-top:4px">${r.description || ''}</div>
          <div class="row" style="gap:18px;margin-top:10px;font-size:12.5px">
            <span><strong>${r.items}</strong> episodios</span>
            <span>Idioma: <strong>${r.language || '—'}</strong></span>
            <span>Autor: <strong>${r.author || '—'}</strong></span>
          </div>
          <div style="margin-top:10px;display:flex;gap:6px;flex-wrap:wrap">
            ${(r.namespaces||[]).map(n => `<span class="tag">${n}</span>`).join('')}
          </div>
        </div>
      </div>`;
  }

  async function start() {
    const url = $('#imp_url').value.trim();
    if (!url) return alert('Pon una URL primero');
    const fd = new FormData();
    fd.append('url', url);
    fd.append('target_id', $('#imp_target').value);
    fd.append('options', JSON.stringify(opts()));
    const r = await fetch('/import-api.php?action=create', { method: 'POST', body: fd }).then(r => r.json());
    if (r.error) return alert(r.error);
    jobId = r.job_id;
    if (r.needs_trigger) {
      fetch(`/import-api.php?action=run_worker&job=${jobId}`).catch(()=>{});
    }
    $('#imp_progress').hidden = false;
    poll();
  }

  function poll() {
    clearInterval(pollId);
    pollId = setInterval(refresh, 1500);
    refresh();
  }

  async function refresh() {
    if (!jobId) return;
    const r = await fetch(`/import-api.php?action=status&job=${jobId}`).then(r => r.json());
    if (!r.job) return;
    const j = r.job, pct = j.total ? (j.done / j.total * 100) : 0;
    $('#imp_fill').style.width = pct + '%';
    $('#imp_done').textContent = j.done;
    $('#imp_total').textContent = j.total;
    $('#imp_failed').textContent = j.failed;
    $('#imp_mb').textContent = (j.bytes / 1048576).toFixed(1);
    // ETA grosero: tasa = done / (now - started_at)
    if (j.started_at && j.done > 0) {
      const elapsed = (Date.now() - new Date(j.started_at + 'Z').getTime()) / 1000;
      const rate = j.done / Math.max(1, elapsed);
      $('#imp_rate').textContent = rate.toFixed(2);
      const remaining = (j.total - j.done) / Math.max(0.01, rate);
      $('#imp_eta').textContent = remaining > 0 ? formatEta(remaining) : '—';
    }
    $('#imp_log').innerHTML = (r.events || []).map(e =>
      `<div class="lvl-${e.level}">[${e.at}] ${e.message}</div>`).join('');
    $('#imp_log').scrollTop = $('#imp_log').scrollHeight;
    if (j.status === 'done' || j.status === 'failed') clearInterval(pollId);
  }

  function formatEta(s) {
    if (s < 60) return Math.round(s) + 's';
    if (s < 3600) return Math.floor(s/60) + 'm ' + Math.round(s%60) + 's';
    return Math.floor(s/3600) + 'h ' + Math.floor((s%3600)/60) + 'm';
  }

  async function pause()  { if(jobId) await postAction('pause');  }
  async function resume() { 
    if(jobId) { 
      const r = await postAction('resume').then(res => res.json()); 
      if (r.needs_trigger) fetch(`/import-api.php?action=run_worker&job=${jobId}`).catch(()=>{});
      poll(); 
    } 
  }
  async function cancel() { if(jobId) await postAction('cancel'); }
  function postAction(a) {
    const fd = new FormData(); fd.append('job', jobId);
    return fetch('/import-api.php?action=' + a, { method:'POST', body: fd });
  }

  return { analyze, start, pause, resume, cancel };
})();
</script>
