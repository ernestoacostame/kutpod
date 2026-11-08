<?php
// ============================================================================
// pages/update.php — Sistema de actualización de KutPod
// ============================================================================
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/updater.php';
require_once __DIR__ . '/../version.php';

$hasToken = !empty(kp_setting('update_github_token', ''));
$rollbackVersion = kp_setting('update_rollback_version', '');
$rollbackPath = kp_setting('update_rollback_path', '');
$hasRollback = $rollbackVersion && $rollbackPath && file_exists($rollbackPath);

// Fetch all available rollbacks
$rollbacks = kp_update_get_available_rollbacks();
$hasAnyRollback = !empty($rollbacks);

// Información de almacenamiento de actualizaciones
$storageInfo = kp_update_get_storage_info();

// Historial de actualizaciones
$history = [];
try {
  $history = kp_q("SELECT * FROM updates ORDER BY id DESC LIMIT 20");
} catch (Throwable $e) {}

$kp_update_cache = kp_update_cached();
$hasAvailableUpdate = false;
if ($kp_update_cache && !empty($kp_update_cache['available']) && !empty($kp_update_cache['new_version'])) {
  if (version_compare($kp_update_cache['new_version'], KUTPOD_VERSION, '>')) {
    $hasAvailableUpdate = true;
  }
}
?>
<div class="page-head">
  <div>
    <h1 class="page-title"><?= __('upd_title', 'Actualizaciones') ?></h1>
    <p class="page-sub"><?= __('upd_sub', 'Mantén KutPod al día · actualización con un click · rollback instantáneo') ?></p>
  </div>
</div>

<!-- Estado actual -->
<div class="stat-grid">
  <?= stat_card(__('upd_installed_version', 'Versión instalada'), 'v' . KUTPOD_VERSION, 'layers', ['sub' => __('upd_current_compilation', 'Compilación actual')]) ?>
  <?= stat_card(__('upd_channel', 'Canal'), $hasToken ? __('upd_channel_github', 'GitHub Directo') : __('upd_channel_cloud', 'KutStudio Cloud'), 'shield',
       $hasToken
         ? ['sub' => __('upd_sub_token_active', 'Token de desarrollo activo'), 'foot' => '<div style="margin-top:8px"><a href="/admin/preferences#update-token" style="color:var(--accent);font-size:12px">' . __('nav_preferences') . ' →</a></div>']
         : ['sub' => __('upd_sub_transparent', 'Actualización transparente'), 'foot' => '<div style="margin-top:8px;font-size:11px;color:var(--text-3)">' . __('upd_foot_auto', 'Automático') . '</div>']) ?>
  <?= stat_card(__('upd_rollback', 'Rollback'), $hasRollback ? 'v' . $rollbackVersion : __('upd_not_available', 'No disponible'), 'refreshCw',
       ['sub' => $hasRollback ? __('upd_sub_saved_prev', 'Versión anterior guardada') : __('upd_sub_created_before', 'Se crea antes de cada update')]) ?>
  <?= stat_card(__('upd_total_applied', 'Actualizaciones'), (string)count($history), 'check', ['sub' => __('upd_applied_total', 'aplicadas en total')]) ?>
</div>

<!-- Buscar actualización -->
<div class="card card-lg" style="padding:28px;margin-top:24px" id="update-card">
  <div class="card-head" style="margin-bottom:20px">
    <div>
      <div class="card-title"><?= __('upd_check_title', 'Buscar actualización') ?></div>
      <div style="font-size:12.5px;color:var(--text-3);margin-top:4px">
        <?= __('upd_check_desc', 'Consulta el repositorio oficial para verificar si hay una nueva versión disponible.') ?>
      </div>
    </div>
    <button class="btn btn-primary" id="btn-check" type="button" onclick="kpUpdateCheck()">
      <?= icon('search',13) ?> <span id="btn-check-text"><?= __('upd_check_btn', 'Buscar actualizaciones') ?></span>
    </button>
  </div>

  <!-- Resultado de la búsqueda (se llena por JS) -->
  <div id="update-status" style="display:none"></div>

  <!-- Panel de actualización disponible (se muestra por JS) -->
  <div id="update-available" style="display:none">
    <div style="background:var(--surface-2);border-radius:12px;padding:20px;margin-top:16px">
      <div class="row" style="justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:16px">
        <div>
          <div style="font-size:18px;font-weight:600;letter-spacing:-0.02em" id="update-version-label"></div>
          <div style="font-size:12.5px;color:var(--text-3);margin-top:4px" id="update-date-label"></div>
        </div>
        <div class="row" style="gap:8px;flex-wrap:wrap">
          <a id="update-gh-link" href="#" target="_blank" rel="noopener" class="btn btn-ghost" style="font-size:12px"><?= icon('arrowUp',13) ?> <?= __('upd_view_gh', 'Ver en GitHub') ?></a>
          <button class="btn btn-primary" id="btn-apply" onclick="kpUpdateApply()">
            <?= icon('download',13) ?> <?= __('upd_update_now', 'Actualizar ahora') ?>
          </button>
        </div>
      </div>

      <!-- Changelog -->
      <div id="update-changelog" style="margin-top:16px;padding-top:16px;border-top:1px solid var(--border)">
        <div style="font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:0.06em;color:var(--text-3);margin-bottom:8px"><?= __('upd_release_notes', 'Notas de la versión') ?></div>
        <div id="update-changelog-body" style="font-size:13.5px;line-height:1.7;color:var(--text-2);white-space:pre-wrap"></div>
      </div>
    </div>
  </div>

  <!-- Barra de progreso -->
  <div id="update-progress" style="display:none;margin-top:16px">
    <div style="background:var(--surface-2);border-radius:12px;padding:20px">
      <div style="font-size:14px;font-weight:500;margin-bottom:12px" id="progress-label"><?= __('upd_preparing', 'Preparando...') ?></div>
      <div style="background:var(--border);border-radius:6px;height:8px;overflow:hidden">
        <div id="progress-bar" style="background:var(--accent);height:100%;border-radius:6px;width:0%;transition:width 0.6s ease"></div>
      </div>
      <div style="font-size:12px;color:var(--text-3);margin-top:8px" id="progress-sub"></div>
    </div>
  </div>

  <!-- Resultado de la actualización -->
  <div id="update-result" style="display:none;margin-top:16px">
    <div style="border-radius:12px;padding:20px" id="update-result-card">
      <div style="font-size:16px;font-weight:600" id="result-title"></div>
      <div style="font-size:13.5px;color:var(--text-2);margin-top:6px" id="result-body"></div>
    </div>
  </div>
</div>

<!-- Actualización manual vía ZIP -->
<div class="card card-lg" style="padding:28px;margin-top:24px" id="manual-upload-card">
  <div class="card-head" style="margin-bottom:18px">
    <div>
      <div class="card-title"><?= icon('upload',16) ?> <?= __('upd_manual_upload_pkg', 'Subir paquete de actualización (.zip)') ?></div>
      <div style="font-size:12.5px;color:var(--text-3);margin-top:4px">
        <?= __('upd_manual_upload_pkg_desc', 'Si tu servidor no puede conectar a GitHub o prefieres actualizar manualmente, sube el archivo <code>.zip</code> descargado directamente desde los <a href="https://github.com/ernestoacostame/kutpod/releases" target="_blank" rel="noopener" style="color:var(--accent)">Releases de GitHub</a>.') ?>
      </div>
    </div>
  </div>

  <form id="form-manual-upload" onsubmit="kpManualUpdateUpload(event)">
    <div style="display:flex;flex-wrap:wrap;align-items:center;gap:12px">
      <input type="file" id="manual-zip-input" accept=".zip,application/zip" class="input" style="flex:1;min-width:240px;padding:8px 12px;background:var(--surface-2);border:1px solid var(--border);border-radius:8px" required>
      <button class="btn btn-primary" id="btn-manual-upload" type="submit">
        <?= icon('upload',13) ?> <?= __('upd_manual_upload_btn', 'Subir e instalar actualización') ?>
      </button>
    </div>
    <div style="font-size:11.5px;color:var(--text-3);margin-top:8px">
      <?= __('upd_manual_backup_notice', 'ℹ Se creará una copia de seguridad automática antes de aplicar los ficheros del archivo ZIP.') ?>
    </div>
  </form>

  <div id="manual-upload-status" style="display:none;margin-top:16px"></div>
</div>

<!-- Rollback -->
<?php if ($hasAnyRollback): ?>
<div class="card card-lg" style="padding:28px;margin-top:24px">
  <div class="card-head" style="align-items:flex-start;flex-wrap:wrap;gap:16px">
    <div style="flex:1;min-width:250px">
      <div class="card-title"><?= icon('refreshCw',16) ?> <?= __('upd_rollback_title', 'Revertir actualización') ?></div>
      <div style="font-size:12.5px;color:var(--text-3);margin-top:4px">
        <?= __('upd_rollback_desc', 'Restaura una versión previamente guardada de KutPod. Los datos (episodios, podcasts, estadísticas) no se verán afectados.') ?>
      </div>
      <div style="margin-top:14px">
        <label class="label" style="display:block;margin-bottom:6px"><?= __('upd_rollback_select_label', 'Seleccionar copia de seguridad:') ?></label>
        <select class="select" id="rollback-select" style="max-width:100%;width:320px">
          <?php foreach ($rollbacks as $rb): 
            $isSelected = ($hasRollback && $rb['path'] === $rollbackPath) ? 'selected' : '';
          ?>
            <option value="<?= e($rb['filename']) ?>" <?= $isSelected ?>>
              v<?= e($rb['version']) ?> (<?= e($rb['date']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <button class="btn" id="btn-rollback" onclick="kpUpdateRollback()" style="color:#f59e0b;border-color:#f59e0b44;margin-top:24px">
      <?= icon('refreshCw',13) ?> <?= __('upd_rollback_btn', 'Revertir a la versión seleccionada') ?>
    </button>
  </div>
  <div id="rollback-status" style="display:none;margin-top:14px"></div>
</div>
<?php endif; ?>

<!-- Almacenamiento y limpieza de actualizaciones -->
<div class="card card-lg" style="padding:24px;margin-top:24px" id="card-clean-updates">
  <div class="card-head" style="align-items:flex-start;flex-wrap:wrap;gap:16px">
    <div style="flex:1;min-width:260px">
      <div class="card-title"><?= icon('trash', 16) ?> <?= __('upd_storage_title', 'Paquetes de actualización descargados') ?></div>
      <div style="font-size:12.5px;color:var(--text-3);margin-top:4px">
        <?= __('upd_storage_desc', 'Libera espacio en disco eliminando los paquetes de actualización descargados (.zip) y carpetas temporales.') ?>
      </div>
      <div style="margin-top:12px;display:flex;gap:18px;flex-wrap:wrap;font-size:13px;color:var(--text-2)">
        <div>
          <span style="color:var(--text-3)"><?= __('upd_storage_downloaded', 'Paquetes descargados:') ?></span>
          <strong id="storage-dl-text"><?= $storageInfo['downloaded_count'] ?> (<?= human_bytes($storageInfo['downloaded_bytes']) ?>)</strong>
        </div>
        <div>
          <span style="color:var(--text-3)"><?= __('upd_storage_rollbacks', 'Copias de rollback:') ?></span>
          <strong id="storage-rb-text"><?= $storageInfo['rollbacks_count'] ?> (<?= human_bytes($storageInfo['rollbacks_bytes']) ?>)</strong>
        </div>
        <div>
          <span style="color:var(--text-3)"><?= __('upd_storage_total', 'Espacio total ocupado:') ?></span>
          <strong id="storage-total-text"><?= human_bytes($storageInfo['total_bytes']) ?></strong>
        </div>
      </div>
      <div style="margin-top:12px">
        <label style="display:inline-flex;align-items:center;gap:6px;font-size:12.5px;color:var(--text-2);cursor:pointer">
          <input type="checkbox" id="clean-include-rollbacks" style="cursor:pointer">
          <?= __('upd_storage_include_rollbacks', 'Incluir también las copias de seguridad de versiones anteriores (rollback)') ?>
        </label>
      </div>
    </div>
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:4px">
      <button class="btn" id="btn-clean-downloads" type="button" onclick="kpCleanDownloads()" style="color:#ef4444;border-color:#ef444455;display:inline-flex;align-items:center;gap:6px">
        <?= icon('trash',13) ?> <?= __('upd_storage_btn_clean', 'Borrar actualizaciones descargadas') ?>
      </button>
    </div>
  </div>
  <div id="clean-status" style="display:none;margin-top:14px"></div>
</div>

<!-- Historial -->
<div class="card card-lg" style="padding:0;margin-top:24px">
  <div class="card-head" style="padding:20px 24px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
    <div class="card-title"><?= __('upd_history_title', 'Historial de actualizaciones') ?></div>
    <button class="btn btn-ghost" type="button" onclick="kpCleanDownloads()" style="font-size:12px;color:var(--text-2);padding:6px 12px;display:inline-flex;align-items:center;gap:6px">
      <?= icon('trash', 12) ?> <?= __('upd_storage_btn_clean', 'Borrar actualizaciones descargadas') ?>
    </button>
  </div>
  <?php if (!$history): ?>
    <div style="padding:48px;text-align:center;color:var(--text-3)">
      <?= __('upd_history_empty', 'Sin actualizaciones registradas todavía.') ?>
    </div>
  <?php else: ?>
    <div class="list">
      <div style="display:grid;grid-template-columns:1fr 1fr 130px 130px;gap:14px;padding:10px 24px;font-size:11px;color:var(--text-3);text-transform:uppercase;letter-spacing:.06em;font-weight:600;border-bottom:1px solid var(--border)">
        <div><?= __('upd_history_th_from_to', 'De → A') ?></div><div><?= __('upd_history_th_status', 'Estado') ?></div><div><?= __('upd_history_th_date', 'Fecha') ?></div><div><?= __('upd_history_th_backup', 'Backup') ?></div>
      </div>
      <?php foreach ($history as $h):
        $statusColor = $h['status'] === 'applied' ? '#10b981' : '#f59e0b';
        $statusLabel = $h['status'] === 'applied' ? __('upd_status_applied', 'Aplicada') : __('upd_status_reverted', 'Revertida');
      ?>
        <div style="display:grid;grid-template-columns:1fr 1fr 130px 130px;gap:14px;padding:14px 24px;align-items:center;border-bottom:1px solid var(--border);font-size:13.5px">
          <div>
            <span style="color:var(--text-3)">v<?= e($h['from_version']) ?></span>
            <span style="color:var(--text-3);margin:0 6px">→</span>
            <span style="font-weight:600">v<?= e($h['to_version']) ?></span>
          </div>
          <div><span class="tag" style="background:<?= $statusColor ?>22;color:<?= $statusColor ?>;font-size:11px"><?= e($statusLabel) ?></span></div>
          <div style="color:var(--text-2);font-size:12.5px"><?= e(kp_relative_time($h['applied_at'])) ?></div>
          <div style="font-size:12px;color:var(--text-3);font-family:ui-monospace,monospace">
            <?= !empty($h['backup_path']) && file_exists($h['backup_path']) ? '✓ ' . __('saved', 'guardado') : '—' ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<script>
let _updateData = <?= $hasAvailableUpdate ? json_encode($kp_update_cache) : 'null' ?>;

document.addEventListener('DOMContentLoaded', function() {
  if (_updateData && _updateData.available) {
    const status = document.getElementById('update-status');
    const available = document.getElementById('update-available');
    const applyBtn = document.getElementById('btn-apply');
    if (status) status.style.display = 'none';
    if (available) available.style.display = 'block';
    if (applyBtn) {
      applyBtn.disabled = false;
      applyBtn.innerHTML = '<?= icon('download',13) ?> ' + UPD_I18N.updateNow;
    }
    const verEl = document.getElementById('update-version-label');
    if (verEl) verEl.textContent = _updateData.name || ('v' + _updateData.new_version);
    const dateEl = document.getElementById('update-date-label');
    if (dateEl) {
      dateEl.textContent = _updateData.published_at
        ? UPD_I18N.publishedOn + new Date(_updateData.published_at).toLocaleDateString(UPD_I18N.dateLocale, {year:'numeric', month:'long', day:'numeric'})
        : '';
    }
    const chEl = document.getElementById('update-changelog-body');
    if (chEl) chEl.textContent = _updateData.changelog || UPD_I18N.noReleaseNotes;
    const ghLink = document.getElementById('update-gh-link');
    if (ghLink) ghLink.href = _updateData.html_url || '#';
  } else {
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('check') === '1' || urlParams.get('auto') === '1') {
      kpUpdateCheck();
    }
  }
});

const UPD_I18N = {
  searching: <?= json_encode(__('upd_btn_searching', 'Buscando...')) ?>,
  checkBtn: <?= json_encode(__('upd_check_btn', 'Buscar actualizaciones')) ?>,
  queryingGh: <?= json_encode(__('upd_querying_gh', 'Consultando GitHub...')) ?>,
  updateNow: <?= json_encode(__('upd_update_now', 'Actualizar ahora')) ?>,
  upToDate: <?= json_encode(__('upd_up_to_date', '✅ KutPod está actualizado — v')) ?>,
  connError: <?= json_encode(__('upd_conn_error', '❌ Error de conexión: ')) ?>,
  publishedOn: <?= json_encode(__('upd_published_on', 'Publicado el ')) ?>,
  noReleaseNotes: <?= json_encode(__('upd_no_release_notes', 'Sin notas de la versión.')) ?>,
  confirmApply: <?= json_encode(__('upd_confirm_apply', "¿Actualizar KutPod de v{from} a v{to}?\n\nSe creará un backup automáticamente antes de aplicar los cambios.")) ?>,
  step1Label: <?= json_encode(__('upd_step1_label', 'Paso 1/2: Descargando actualización...')) ?>,
  step1Sub: <?= json_encode(__('upd_step1_sub', 'Obteniendo v{version} desde GitHub')) ?>,
  step2Label: <?= json_encode(__('upd_step2_label', 'Paso 2/2: Creando respaldo y aplicando...')) ?>,
  step2Sub: <?= json_encode(__('upd_step2_sub', 'Respaldando y copiando ficheros nuevos')) ?>,
  serverErrorPrefix: <?= json_encode(__('upd_server_err_prefix', 'El servidor devolvió un error (HTTP ')) ?>,
  completedTitle: <?= json_encode(__('upd_completed_title', 'Actualización completada')) ?>,
  completedBody: <?= json_encode(__raw('upd_completed_body', 'KutPod actualizado de <strong>v{from}</strong> a <strong>v{to}</strong>. {files} ficheros actualizados.')) ?>,
  reloadPage: <?= json_encode(__('upd_reload_page', 'Recargar página')) ?>,
  rollbackSelectAlert: <?= json_encode(__('upd_rb_select_alert', 'Por favor, selecciona una copia de seguridad para restaurar.')) ?>,
  rollbackSelectedVersion: <?= json_encode(__('upd_rb_selected_version', 'la versión seleccionada')) ?>,
  rollbackConfirm: <?= json_encode(__('upd_rb_confirm', "¿Revertir KutPod a {version}?\n\nSe restaurarán todos los ficheros del código. Tus datos (podcasts, episodios, estadísticas) no se verán afectados.")) ?>,
  restoring: <?= json_encode(__('upd_rb_restoring', 'Restaurando...')) ?>,
  rollbackSuccess: <?= json_encode(__raw('upd_rb_success', '✅ Rollback completado. Restaurada versión <strong>v{version}</strong>.')) ?>,
  cleanPromptBoth: <?= json_encode(__('upd_clean_prompt_both', '¿Deseas eliminar las actualizaciones descargadas (.zip) Y también todas las copias de seguridad de versiones anteriores?')) ?>,
  cleanPromptDlOnly: <?= json_encode(__('upd_clean_prompt_dl_only', '¿Deseas eliminar los paquetes de actualizaciones descargados (.zip) y archivos temporales?')) ?>,
  cleaning: <?= json_encode(__('upd_cleaning', 'Borrando...')) ?>,
  deletingFiles: <?= json_encode(__('upd_deleting_files', 'Eliminando archivos...')) ?>,
  cleanCompleted: <?= json_encode(__('upd_clean_completed', 'Limpieza completada: {count} archivo(s) eliminados ({freed} liberados).')) ?>,
  btnClean: <?= json_encode(__('upd_storage_btn_clean', 'Borrar actualizaciones descargadas')) ?>,
  manualSelectZip: <?= json_encode(__('upd_manual_select_zip', 'Por favor selecciona un archivo .zip.')) ?>,
  manualMustBeZip: <?= json_encode(__('upd_manual_must_be_zip', 'El archivo seleccionado debe ser un .zip.')) ?>,
  manualConfirm: <?= json_encode(__('upd_manual_confirm', "¿Deseas aplicar la actualización manual desde \"{name}\"?\n\nSe creará un respaldo de seguridad previo de forma automática.")) ?>,
  manualInstallingBtn: <?= json_encode(__('upd_manual_installing_btn', 'Instalando actualización...')) ?>,
  manualUploading: <?= json_encode(__('upd_manual_uploading', 'Subiendo e instalando paquete...')) ?>,
  manualUnpacking: <?= json_encode(__('upd_manual_unpacking', 'Desempaquetando archivos, respaldando versión actual y sobrescribiendo core...')) ?>,
  manualSuccessTitle: <?= json_encode(__('upd_manual_success_title', '🎉 ¡KutPod actualizado con éxito!')) ?>,
  manualSuccessBody: <?= json_encode(__raw('upd_manual_success_body', 'Actualizado de v{from} a <strong>v{to}</strong> ({files} ficheros copiados).')) ?>,
  manualErrUpdate: <?= json_encode(__('upd_manual_err_update', '❌ Error al actualizar:')) ?>,
  manualUploadBtn: <?= json_encode(__('upd_manual_upload_btn', 'Subir e instalar actualización')) ?>,
  dateLocale: <?= json_encode(kp_detect_lang() === 'en' ? 'en-US' : 'es-ES') ?>
};

function kpUpdateCheck() {
  const btn = document.getElementById('btn-check');
  const status = document.getElementById('update-status');
  const available = document.getElementById('update-available');
  const progress = document.getElementById('update-progress');
  const result = document.getElementById('update-result');
  const applyBtn = document.getElementById('btn-apply');

  btn.disabled = true;
  btn.innerHTML = '<?= icon('search',13) ?> ' + UPD_I18N.searching;
  status.style.display = 'block';
  status.innerHTML = '<div style="padding:14px;color:var(--text-3);font-size:13.5px">' + UPD_I18N.queryingGh + '</div>';
  available.style.display = 'none';
  if (progress) progress.style.display = 'none';
  if (result) result.style.display = 'none';
  if (applyBtn) {
    applyBtn.disabled = false;
    applyBtn.innerHTML = '<?= icon('download',13) ?> ' + UPD_I18N.updateNow;
  }

  fetch('/api/update?action=check')
    .then(r => r.json())
    .then(data => {
      if (data.error) {
        status.innerHTML = '<div style="padding:14px;color:#ef4444;font-size:13.5px">❌ ' + data.error + '</div>';
        return;
      }
      if (data.up_to_date) {
        status.innerHTML = '<div style="padding:14px;color:#10b981;font-size:13.5px">' + UPD_I18N.upToDate + data.version + '</div>';
        return;
      }
      if (data.available) {
        _updateData = data;
        status.style.display = 'none';
        available.style.display = 'block';
        if (applyBtn) {
          applyBtn.disabled = false;
          applyBtn.innerHTML = '<?= icon('download',13) ?> ' + UPD_I18N.updateNow;
        }
        document.getElementById('update-version-label').textContent = data.name || ('v' + data.new_version);
        document.getElementById('update-date-label').textContent = data.published_at
          ? UPD_I18N.publishedOn + new Date(data.published_at).toLocaleDateString(UPD_I18N.dateLocale, {year:'numeric', month:'long', day:'numeric'})
          : '';
        document.getElementById('update-changelog-body').textContent = data.changelog || UPD_I18N.noReleaseNotes;
        document.getElementById('update-gh-link').href = data.html_url || '#';
      }
    })
    .catch(e => {
      status.innerHTML = '<div style="padding:14px;color:#ef4444;font-size:13.5px">' + UPD_I18N.connError + e.message + '</div>';
    })
    .finally(() => {
      btn.disabled = false;
      btn.innerHTML = '<?= icon('search',13) ?> ' + UPD_I18N.checkBtn;
    });
}

function kpUpdateApply() {
  if (!_updateData) return;
  const confirmMsg = UPD_I18N.confirmApply
    .replace('{from}', _updateData.current_version)
    .replace('{to}', _updateData.new_version);
  if (!confirm(confirmMsg)) return;

  const btn = document.getElementById('btn-apply');
  const progress = document.getElementById('update-progress');
  const progressBar = document.getElementById('progress-bar');
  const progressLabel = document.getElementById('progress-label');
  const progressSub = document.getElementById('progress-sub');
  const result = document.getElementById('update-result');
  const resultCard = document.getElementById('update-result-card');
  const available = document.getElementById('update-available');

  const showError = (msg) => {
    progress.style.display = 'none';
    result.style.display = 'block';
    resultCard.style.background = '#ef444422';
    document.getElementById('result-title').textContent = '❌ Error';
    document.getElementById('result-body').textContent = msg;
    
    // Permitir reintentar
    btn.disabled = false;
    available.style.display = 'block';
  };

  btn.disabled = true;
  available.style.display = 'none';
  result.style.display = 'none'; // Ocultar error anterior al reintentar
  progress.style.display = 'block';

  // Paso 1: Descargar
  progressLabel.textContent = UPD_I18N.step1Label;
  progressSub.textContent = UPD_I18N.step1Sub.replace('{version}', _updateData.new_version);
  progressBar.style.width = '25%';

  const dlBody = new URLSearchParams({
    action: 'download',
    zip_url: btoa(_updateData.zip_url)
  });

  fetch('/api/update', { method: 'POST', body: dlBody })
    .then(async r => {
      const text = await r.text();
      try {
        return JSON.parse(text);
      } catch(e) {
        throw new Error(UPD_I18N.serverErrorPrefix + r.status + '):\n' + text.substring(0, 150) + '...');
      }
    })
    .then(dlData => {
      if (dlData.error) return showError(dlData.error);

      // Paso 2: Aplicar
      progressLabel.textContent = UPD_I18N.step2Label;
      progressSub.textContent = UPD_I18N.step2Sub;
      progressBar.style.width = '65%';

      const applyBody = new URLSearchParams({
        action: 'apply',
        zip_path: dlData.path,
        new_version: _updateData.new_version
      });

      return fetch('/api/update', { method: 'POST', body: applyBody })
        .then(async r => {
          const text = await r.text();
          try {
            return JSON.parse(text);
          } catch(e) {
            throw new Error(UPD_I18N.serverErrorPrefix + r.status + '):\n' + text.substring(0, 150) + '...');
          }
        })
        .then(data => {
          if (data.error) return showError(data.error);
          if (data.success) {
            progressBar.style.width = '100%';
            progress.style.display = 'none';
            result.style.display = 'block';
            resultCard.style.background = '#10b98122';
            document.getElementById('result-title').textContent = '✅ ' + UPD_I18N.completedTitle;
            document.getElementById('result-body').innerHTML =
              UPD_I18N.completedBody
                .replace('{from}', data.from)
                .replace('{to}', data.to)
                .replace('{files}', data.files_updated) + '<br><br>' +
              '<a href="/admin/update" class="btn btn-primary" style="margin-top:8px">' + UPD_I18N.reloadPage + '</a>';
            btn.disabled = false;
            btn.innerHTML = '<?= icon('download',13) ?> ' + UPD_I18N.updateNow;
            _updateData = null;
          }
        });
    })
    .catch(e => showError(e.message));
}

function kpUpdateRollback() {
  const select = document.getElementById('rollback-select');
  const backupFile = select ? select.value : '';
  if (!backupFile) {
    alert(UPD_I18N.rollbackSelectAlert);
    return;
  }

  const option = select.options[select.selectedIndex];
  const versionText = option ? option.text : UPD_I18N.rollbackSelectedVersion;

  if (!confirm(UPD_I18N.rollbackConfirm.replace('{version}', versionText))) return;

  const btn = document.getElementById('btn-rollback');
  const status = document.getElementById('rollback-status');
  btn.disabled = true;
  status.style.display = 'block';
  status.innerHTML = '<div style="padding:14px;color:var(--text-3);font-size:13.5px"><?= icon('refreshCw',13) ?> ' + UPD_I18N.restoring + '</div>';

  fetch('/api/update', {
    method: 'POST',
    body: new URLSearchParams({ action: 'rollback', backup_file: backupFile })
  })
    .then(r => r.json())
    .then(data => {
      if (data.error) {
        status.innerHTML = '<div style="padding:14px;color:#ef4444;font-size:13.5px">❌ ' + data.error + '</div>';
        btn.disabled = false;
        return;
      }
      if (data.success) {
        status.innerHTML = '<div style="padding:14px;background:#10b98122;border-radius:8px;font-size:13.5px">' +
          UPD_I18N.rollbackSuccess.replace('{version}', data.restored_version) +
          '<br><br><a href="/admin/update" class="btn btn-primary" style="margin-top:8px">' + UPD_I18N.reloadPage + '</a></div>';
      }
    })
    .catch(e => {
      status.innerHTML = '<div style="padding:14px;color:#ef4444;font-size:13.5px">❌ Error: ' + e.message + '</div>';
      btn.disabled = false;
    });
}

function humanBytesJs(bytes) {
  if (!bytes || bytes === 0) return '0 B';
  const k = 1024;
  const sizes = ['B', 'KB', 'MB', 'GB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
}

function kpCleanDownloads() {
  const includeRollbacks = document.getElementById('clean-include-rollbacks')?.checked || false;
  const promptMsg = includeRollbacks
    ? UPD_I18N.cleanPromptBoth
    : UPD_I18N.cleanPromptDlOnly;

  if (!confirm(promptMsg)) return;

  const btn = document.getElementById('btn-clean-downloads');
  const status = document.getElementById('clean-status');
  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<?= icon('refreshCw',13) ?> ' + UPD_I18N.cleaning;
  }
  if (status) {
    status.style.display = 'block';
    status.innerHTML = '<div style="padding:10px;color:var(--text-3);font-size:13px">' + UPD_I18N.deletingFiles + '</div>';
  }

  const body = new URLSearchParams({
    action: 'clean_downloads',
    include_rollbacks: includeRollbacks ? '1' : '0'
  });

  fetch('/api/update', { method: 'POST', body })
    .then(r => r.json())
    .then(data => {
      if (data.error) {
        if (status) status.innerHTML = '<div style="padding:10px;color:#ef4444;font-size:13px;background:#ef444415;border-radius:8px">❌ ' + data.error + '</div>';
        else alert('Error: ' + data.error);
        return;
      }
      const msg = UPD_I18N.cleanCompleted.replace('{count}', data.deleted_count).replace('{freed}', data.freed_human);
      if (status) {
        status.innerHTML = '<div style="padding:10px;color:#10b981;font-size:13px;background:#10b98115;border-radius:8px">✅ ' + msg + '</div>';
      } else {
        alert(msg);
      }
      
      // Actualizar información de almacenamiento
      fetch('/api/update?action=storage_info')
        .then(r => r.json())
        .then(info => {
          if (info && !info.error) {
            const dl = document.getElementById('storage-dl-text');
            const rb = document.getElementById('storage-rb-text');
            const tot = document.getElementById('storage-total-text');
            if (dl) dl.textContent = info.downloaded_count + ' (' + humanBytesJs(info.downloaded_bytes) + ')';
            if (rb) rb.textContent = info.rollbacks_count + ' (' + humanBytesJs(info.rollbacks_bytes) + ')';
            if (tot) tot.textContent = humanBytesJs(info.total_bytes);
          }
        }).catch(() => {});
    })
    .catch(e => {
      if (status) status.innerHTML = '<div style="padding:10px;color:#ef4444;font-size:13px;background:#ef444415;border-radius:8px">❌ Error: ' + e.message + '</div>';
      else alert('Error: ' + e.message);
    })
    .finally(() => {
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = '<?= icon('trash',13) ?> ' + UPD_I18N.btnClean;
      }
    });
}

function kpManualUpdateUpload(e) {
  e.preventDefault();
  const fileInput = document.getElementById('manual-zip-input');
  const btn = document.getElementById('btn-manual-upload');
  const status = document.getElementById('manual-upload-status');

  if (!fileInput.files || !fileInput.files[0]) {
    alert(UPD_I18N.manualSelectZip);
    return;
  }

  const file = fileInput.files[0];
  if (!file.name.toLowerCase().endsWith('.zip')) {
    alert(UPD_I18N.manualMustBeZip);
    return;
  }

  if (!confirm(UPD_I18N.manualConfirm.replace('{name}', file.name))) {
    return;
  }

  btn.disabled = true;
  btn.innerHTML = '<?= icon('upload',13) ?> ' + UPD_I18N.manualInstallingBtn;
  status.style.display = 'block';
  status.innerHTML = '<div style="background:var(--surface-2);border:1px solid var(--border);border-radius:10px;padding:16px;color:var(--text-2);font-size:13.5px">'
    + '<div style="font-weight:600;margin-bottom:4px">' + UPD_I18N.manualUploading + '</div>'
    + '<div style="font-size:12px;color:var(--text-3)">' + UPD_I18N.manualUnpacking + '</div>'
    + '</div>';

  const formData = new FormData();
  formData.append('action', 'upload');
  formData.append('zip_file', file);

  fetch('/api/update', {
    method: 'POST',
    body: formData
  })
  .then(async r => {
    const text = await r.text();
    try {
      return JSON.parse(text);
    } catch(err) {
      throw new Error(UPD_I18N.serverErrorPrefix + r.status + '): ' + text.substring(0, 150));
    }
  })
  .then(data => {
    if (data.error) {
      status.innerHTML = '<div style="background:#ef444418;border:1px solid #ef444455;border-radius:10px;padding:16px;color:#ef4444;font-size:13.5px">'
        + '<strong>' + UPD_I18N.manualErrUpdate + '</strong><div style="margin-top:6px;font-size:13px">' + data.error + '</div>'
        + '</div>';
      btn.disabled = false;
      btn.innerHTML = '<?= icon('upload',13) ?> ' + UPD_I18N.manualUploadBtn;
      return;
    }

    status.innerHTML = '<div style="background:#10b98118;border:1px solid #10b98155;border-radius:10px;padding:20px;color:#10b981;font-size:14px">'
      + '<div style="font-size:16px;font-weight:700">' + UPD_I18N.manualSuccessTitle + '</div>'
      + '<div style="margin-top:6px;font-size:13px;color:var(--text-2)">' +
        UPD_I18N.manualSuccessBody
          .replace('{from}', data.from)
          .replace('{to}', data.to)
          .replace('{files}', data.files_updated) +
        '</div>'
      + '<div style="margin-top:14px"><button class="btn btn-primary" onclick="window.location.reload()">' + '<?= icon('refreshCw',13) ?> ' + UPD_I18N.reloadPage + '</button></div>'
      + '</div>';
    fileInput.value = '';
    btn.disabled = false;
    btn.innerHTML = '<?= icon('upload',13) ?> ' + UPD_I18N.manualUploadBtn;
  })
  .catch(err => {
    status.innerHTML = '<div style="background:#ef444418;border:1px solid #ef444455;border-radius:10px;padding:16px;color:#ef4444;font-size:13.5px">'
      + '<strong>' + UPD_I18N.connError + '</strong><div style="margin-top:6px;font-size:13px">' + err.message + '</div>'
      + '</div>';
    btn.disabled = false;
    btn.innerHTML = '<?= icon('upload',13) ?> ' + UPD_I18N.manualUploadBtn;
  });
}
</script>
