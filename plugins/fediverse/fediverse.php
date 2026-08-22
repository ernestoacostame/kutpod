<?php
// ============================================================================
// KutPod · Módulo de Federación (ActivityPub)
// ============================================================================

// Evitar acceso directo
if (!defined('KUTPOD_VERSION')) {
    exit;
}

// Cargar las funciones core de ActivityPub
require_once __DIR__ . '/../../activitypub.php';

// ── Registro de Ganchos (Hooks) ─────────────────────────────────────────────

// Se ejecuta tras publicar un episodio
kp_add_action('episode_published', 'kp_fediverse_plugin_handle_publish', 10, 5);

// Se ejecuta antes de eliminar permanentemente un episodio
kp_add_action('episode_deleted', 'kp_fediverse_plugin_handle_delete', 10, 1);

// Modifica la lista de secciones del formulario de podcast para incluir el Fediverso
kp_add_filter('podcast_form_sections', 'kp_fediverse_plugin_form_sections', 10, 1);

// Renderiza los campos del Fediverso en la sección correspondiente del formulario
kp_add_action('podcast_form_sections_render', 'kp_fediverse_plugin_render_section_html', 10, 1);

// Se ejecuta cuando el podcast es guardado
kp_add_action('podcast_saved', 'kp_fediverse_plugin_handle_podcast_saved', 10, 2);


// ── Callbacks de Ganchos ────────────────────────────────────────────────────

/**
 * Inserta la sección de Fediverso en el índice de secciones del formulario.
 */
function kp_fediverse_plugin_form_sections(array $sections): array {
    $new_sections = [];
    foreach ($sections as $k => $v) {
        $new_sections[$k] = $v;
        if ($k === 's1-3') {
            $new_sections['s1-4'] = 'Fediverso';
        }
    }
    return $new_sections;
}

/**
 * Renderiza el HTML de la sección "Identidad en el Fediverso".
 */
function kp_fediverse_plugin_render_section_html(?array $p): void {
    ?>
    <!-- 1.4 Fediverso -->
    <section id="s1-4" class="card card-lg">
      <header class="section-head"><h2>Identidad en el Fediverso</h2></header>
      <div class="field">
        <label class="label">Handle ActivityPub</label>
        <div class="input-affix"><span>@</span><input class="input" id="fediverse_handle" name="fediverse_handle" value="<?= e($p['fediverse_handle'] ?? ($p['slug'] ?? '')) ?>" pattern="[a-zA-Z0-9_]+" style="font-family:ui-monospace,monospace" oninput="this.dataset.manual=1"><span>@<?= e(kp_handle_domain()) ?></span></div>
        <div class="help">Citable desde Mastodon · perfil en <code>/.well-known/webfinger</code>.</div>
      </div>
      <div class="field" style="margin-top:18px">
        <label class="label">Banner (3:1)</label>
        <label for="banner" style="display:block;aspect-ratio:3/1;border-radius:12px;border:1px dashed var(--border);background:var(--surface-2);display:grid;place-items:center;color:var(--text-3);cursor:pointer;<?= !empty($p['banner']) ? 'background-image:url('.e($p['banner']).');background-size:cover;background-position:center;border:none;' : '' ?>">
          <?= empty($p['banner']) ? icon('image',32) : '' ?>
          <?= empty($p['banner']) ? '<span style="margin-top:8px;font-size:12.5px">Subir banner · 1500×500 recomendado</span>' : '' ?>
        </label>
        <input id="banner" name="banner" type="file" accept="image/png,image/jpeg,image/gif,image/webp" hidden>
        <button id="banner-cancel" type="button" class="btn btn-ghost" style="margin-top:8px;font-size:12px;display:none;color:var(--red);align-self:flex-start;"><?= icon('x',12) ?> Cancelar selección</button>
      </div>
      <label class="toggle-row" style="margin-top:14px"><span><strong>Federar este show</strong><br><span class="help">El podcast publica notas como cuenta de ActivityPub.</span></span><label class="toggle"><input type="checkbox" name="federate" <?= ($p['federate'] ?? 1) ? 'checked' : '' ?>><span class="toggle-track"></span></label></label>
    </section>
    <?php
}

/**
 * Notifica actualización al Fediverso si la federación está activa al guardar el podcast.
 */
function kp_fediverse_plugin_handle_podcast_saved(int $db_id, array $postData): void {
    $federate = isset($postData['federate']) ? 1 : 0;
    if ($federate) {
        if (function_exists('kp_ap_update_actor')) {
            try {
                kp_ap_update_actor($db_id);
            } catch (Throwable $e) {
                if (function_exists('kp_ap_log')) {
                    kp_ap_log("Error en kp_ap_update_actor al guardar podcast ID $db_id: " . $e->getMessage());
                }
            }
        }
    }
}

/**
 * Publicación automática en la red federada (Mastodon, Pleroma, etc.).
 */
function kp_fediverse_plugin_handle_publish(int $episodeId, int $podcastId, string $title, string $description, string $episodeUrl): void {
    if (function_exists('kp_ap_publish_episode')) {
        try {
            kp_ap_publish_episode($episodeId);
        } catch (Throwable $e) {
            kp_alert('system', 'Error al federar episodio (no crítico)', $e->getMessage(), '');
        }
    }
}

/**
 * Eliminación/Tombstone en el Fediverso.
 */
function kp_fediverse_plugin_handle_delete(array $episode): void {
    if (function_exists('kp_ap_delete_episode')) {
        try {
            kp_ap_delete_episode((int)$episode['id']);
        } catch (Throwable $e) {
            // Ignorar fallos de red al borrar
        }
    }
}
