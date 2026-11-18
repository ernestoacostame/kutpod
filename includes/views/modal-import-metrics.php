<?php
// ============================================================================
// KutPod · Modal para importar métricas de Apple Podcasts y Spotify
// ============================================================================
declare(strict_types=1);

$allPodcasts = kp_q("SELECT id, title, slug FROM podcasts WHERE hidden = 0 ORDER BY title ASC") ?: [];
?>
<dialog id="modal-import-metrics" class="dlg">
  <div class="card card-lg" style="padding:24px;max-width:540px;width:95vw;margin:auto;box-shadow:0 24px 60px rgba(0,0,0,0.6);border:1px solid var(--border);border-radius:14px">
    <div class="row" style="justify-content:space-between;align-items:center;margin-bottom:12px">
      <div class="card-title" style="display:flex;align-items:center;gap:8px;font-size:16px;margin:0">
        <?= icon('upload', 18) ?>
        <span><?= __('analytics_import_modal_title', 'Importar métricas de retención') ?></span>
      </div>
      <button type="button" class="btn btn-ghost" style="padding:4px 8px;font-size:16px;line-height:1" onclick="document.getElementById('modal-import-metrics').close()">✕</button>
    </div>

    <p style="font-size:12.5px;color:var(--text-3);line-height:1.5;margin:0 0 16px 0">
      <?= __('analytics_import_modal_desc', 'Sube el archivo CSV exportado desde Apple Podcasts Connect (Analytics → Episodios) o Spotify for Creators para vincular la retención y tiempo medio consumido con tus episodios y reportes de patrocinadores.') ?>
    </p>

    <form id="form-import-metrics" method="POST" action="/api/v1/stats/import" enctype="multipart/form-data">
      <?= kp_csrf_field() ?>
      <input type="hidden" name="redirect" id="import-metrics-redirect" value="<?= e($_SERVER['REQUEST_URI'] ?? '') ?>">

      <div class="field" style="margin-bottom:14px">
        <label class="label" style="font-size:12px;font-weight:600;margin-bottom:4px"><?= __('analytics_import_select_podcast', 'Podcast de destino') ?></label>
        <select class="select" name="podcast_id" id="import-metrics-podcast-id" style="width:100%">
          <option value="0">✨ Auto-detectar por nombre de podcast en el CSV</option>
          <?php foreach ($allPodcasts as $mp): ?>
            <option value="<?= (int)$mp['id'] ?>"><?= e($mp['title']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="field" style="margin-bottom:14px">
        <label class="label" style="font-size:12px;font-weight:600;margin-bottom:4px"><?= __('analytics_import_platform', 'Plataforma') ?></label>
        <select class="select" name="platform" id="import-metrics-platform" style="width:100%">
          <option value="auto"><?= __('analytics_import_platform_auto', '✨ Auto-detectar (Apple Podcasts o Spotify)') ?></option>
          <option value="apple"> Apple Podcasts Connect</option>
          <option value="spotify">Spotify for Creators</option>
          <option value="other"><?= __('analytics_import_platform_other', 'Otro servicio (CSV genérico)') ?></option>
        </select>
      </div>

      <div class="field" style="margin-bottom:16px">
        <label class="label" style="font-size:12px;font-weight:600;margin-bottom:4px"><?= __('analytics_import_file_label', 'Archivos CSV (.csv o .zip)') ?></label>
        <div id="import-drop-zone" style="border:2px dashed var(--border);border-radius:10px;padding:22px;text-align:center;background:var(--surface-2);cursor:pointer;transition:border-color .2s, background .2s">
          <div style="opacity:.6;margin-bottom:6px"><?= icon('file', 28) ?></div>
          <div id="import-file-name" style="font-size:13px;font-weight:500;color:var(--text-1)"><?= __('analytics_import_drop_hint', 'Arrastra uno o varios CSV aquí (o un .zip) o haz clic para seleccionarlos') ?></div>
          <div style="font-size:11.5px;color:var(--text-3);margin-top:4px"><?= __('analytics_import_compat_hint', 'Puedes seleccionar varios CSV a la vez. Compatible con Apple Podcasts y Spotify for Creators.') ?></div>
          <input type="file" name="files[]" id="import-metrics-file" accept=".csv,text/csv,text/plain,.zip,application/zip" multiple style="display:none">
        </div>
      </div>

      <!-- Área de estado y progreso en tiempo real -->
      <div id="import-status-area" style="display:none;margin-bottom:14px;padding:12px;border-radius:8px;font-size:12.5px;line-height:1.4"></div>

      <div class="row" style="justify-content:flex-end;gap:8px">
        <button type="button" class="btn" onclick="document.getElementById('modal-import-metrics').close()"><?= __('cancel', 'Cancelar') ?></button>
        <button type="submit" id="import-submit-btn" class="btn btn-primary" style="display:inline-flex;align-items:center;gap:6px">
          <?= icon('upload', 13) ?>
          <span><?= __('analytics_import_btn', 'Importar métricas') ?></span>
        </button>
      </div>
    </form>
  </div>
</dialog>

<script>
function openImportMetricsModal(podcastId, podcastTitle) {
  const dlg = document.getElementById('modal-import-metrics');
  if (!dlg) return;
  if (podcastId) {
    const sel = document.getElementById('import-metrics-podcast-id');
    if (sel) sel.value = String(podcastId);
  }
  const statusArea = document.getElementById('import-status-area');
  if (statusArea) {
    statusArea.style.display = 'none';
    statusArea.innerHTML = '';
  }
  const fileInput = document.getElementById('import-metrics-file');
  if (fileInput) fileInput.value = '';
  const fileName = document.getElementById('import-file-name');
  if (fileName) fileName.textContent = '<?= addslashes(__('analytics_import_drop_hint', 'Arrastra tu CSV aquí o haz clic para seleccionarlo')) ?>';
  dlg.showModal();
}

(function initImportMetricsEvents() {
  const dropZone = document.getElementById('import-drop-zone');
  const fileInput = document.getElementById('import-metrics-file');
  const fileName = document.getElementById('import-file-name');
  const form = document.getElementById('form-import-metrics');
  const submitBtn = document.getElementById('import-submit-btn');
  const statusArea = document.getElementById('import-status-area');

  if (!dropZone || !fileInput || !form) return;

  dropZone.addEventListener('click', () => fileInput.click());

  dropZone.addEventListener('dragover', (e) => {
    e.preventDefault();
    dropZone.style.borderColor = 'var(--accent)';
    dropZone.style.background = 'var(--surface-3, rgba(255,255,255,0.05))';
  });

  dropZone.addEventListener('dragleave', () => {
    dropZone.style.borderColor = 'var(--border)';
    dropZone.style.background = 'var(--surface-2)';
  });

  function updateFileLabels(fileList) {
    if (!fileList || fileList.length === 0) {
      fileName.textContent = '<?= addslashes(__('analytics_import_drop_hint', 'Arrastra uno o varios CSV aquí (o un .zip) o haz clic para seleccionarlos')) ?>';
      return;
    }
    if (fileList.length === 1) {
      fileName.textContent = fileList[0].name;
    } else {
      fileName.textContent = `📁 ${fileList.length} archivos seleccionados (${fileList[0].name}, +${fileList.length - 1} más)`;
    }
  }

  dropZone.addEventListener('drop', (e) => {
    e.preventDefault();
    dropZone.style.borderColor = 'var(--border)';
    dropZone.style.background = 'var(--surface-2)';
    if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length > 0) {
      fileInput.files = e.dataTransfer.files;
      updateFileLabels(fileInput.files);
    }
  });

  fileInput.addEventListener('change', () => {
    updateFileLabels(fileInput.files);
  });

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!fileInput.files || fileInput.files.length === 0) {
      alert('Por favor selecciona al menos un archivo CSV o ZIP.');
      return;
    }

    const count = fileInput.files.length;
    submitBtn.disabled = true;
    submitBtn.innerHTML = 'Procesando ' + count + ' archivo(s)...';
    statusArea.style.display = 'block';
    statusArea.style.background = 'var(--surface-2)';
    statusArea.style.color = 'var(--text-1)';
    statusArea.innerHTML = `Analizando ${count} archivo(s) y consolidando métricas...`;

    const formData = new FormData(form);
    formData.append('ajax', '1');

    try {
      const res = await fetch('/api/v1/stats/import', {
        method: 'POST',
        headers: {
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: formData
      });

      const data = await res.json();
      if (!res.ok || !data.success) {
        throw new Error(data.message || data.error || 'Error desconocido al importar CSV');
      }

      const pLabel = data.platform_label || 'CSV';
      const fileSummary = data.total_files ? `${data.total_files} archivo(s)` : `${count} archivo(s)`;
      statusArea.style.background = 'rgba(34, 197, 94, 0.15)';
      statusArea.style.color = '#22c55e';
      statusArea.innerHTML = `<strong>✅ Éxito:</strong> Se procesaron <strong>${fileSummary}</strong> y se consolidaron métricas para <strong>${data.matched}</strong> episodios (${pLabel}). Actualizando pantalla...`;

      setTimeout(() => {
        window.location.reload();
      }, 1400);

    } catch (err) {
      submitBtn.disabled = false;
      submitBtn.innerHTML = '<?= addslashes(icon('upload', 13)) ?> <span><?= addslashes(__('analytics_import_btn', 'Importar métricas')) ?></span>';
      statusArea.style.background = 'rgba(239, 68, 68, 0.15)';
      statusArea.style.color = '#ef4444';
      statusArea.innerHTML = `<strong>❌ Error:</strong> ${err.message}`;
    }
  });
})();
</script>
