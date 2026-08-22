<?php
// ============================================================================
// KutPod · Plugin Foro Flarum (Módulo modular)
// ============================================================================
// Registra ganchos del sistema para auto-publicar discusiones en Flarum
// cuando se publica un episodio en KutPod, y borrarlas si se elimina.

// Evitar acceso directo
if (!defined('KUTPOD_VERSION')) {
    exit;
}

// ── Registro de Ganchos (Hooks) ─────────────────────────────────────────────

// Se ejecuta tras publicar un episodio (vía UI o API)
kp_add_action('episode_published', 'kp_flarum_plugin_handle_publish', 10, 5);

// Se ejecuta antes de borrar permanentemente un episodio de la base de datos
kp_add_action('episode_deleted', 'kp_flarum_plugin_handle_delete', 10, 1);

// Renderiza campos específicos en la sección Avanzada del formulario de podcast
kp_add_action('podcast_form_advanced', 'kp_flarum_plugin_render_podcast_fields', 10, 1);

// Guarda metadatos específicos del podcast al guardar el formulario
kp_add_action('podcast_saved', 'kp_flarum_plugin_save_podcast_fields', 10, 2);


// ── Callbacks de los Ganchos ────────────────────────────────────────────────

/**
 * Verifica si las credenciales de Flarum están definidas en settings.
 */
function kp_flarum_plugin_configured(): bool {
    $url = kp_setting('flarum_url', '');
    $key = kp_setting('flarum_api_key', '');
    return $url !== '' && $key !== '';
}

/**
 * Publicación automática en Flarum.
 */
function kp_flarum_plugin_handle_publish(int $episodeId, int $podcastId, string $title, string $description, string $episodeUrl): void {
    if (!kp_flarum_plugin_configured()) {
        return;
    }

    // Cargar los datos del podcast
    $podcast = kp_one("SELECT * FROM podcasts WHERE id = ?", [$podcastId]);
    if (!$podcast) {
        return;
    }

    // Comprobar si la opción de publicar en Flarum está activada para este podcast
    $flarum_enabled = (int)kp_get_podcast_meta($podcast, 'flarum_enabled', 0);
    if (!$flarum_enabled) {
        return;
    }

    // Resolver tag por slug (usa el slug del podcast si no tiene tag específico)
    $tagSlug = kp_get_podcast_meta($podcast, 'flarum_tag_slug', '');
    if (!$tagSlug) {
        $tagSlug = $podcast['slug'];
    }

    $result = kp_flarum_plugin_post_to_forum($title, $description, $tagSlug, $episodeUrl);

    // Si la publicación en Flarum fue exitosa, guardar el ID de discusión en los metadatos del episodio
    if ($result && isset($result['data']['id'])) {
        $discussionId = $result['data']['id'];
        kp_update_episode_meta($episodeId, 'flarum_discussion_id', $discussionId);
    }
}

/**
 * Eliminación de discusión en Flarum al eliminar el episodio.
 */
function kp_flarum_plugin_handle_delete(array $episode): void {
    $discussionId = kp_get_episode_meta($episode, 'flarum_discussion_id');
    if ($discussionId) {
        kp_flarum_plugin_delete_from_forum($discussionId);
    }
}

/**
 * Renderiza los campos en la configuración avanzada de cada podcast.
 */
function kp_flarum_plugin_render_podcast_fields(?array $podcast): void {
    if (!kp_flarum_plugin_configured()) {
        return;
    }

    $flarum_enabled = 0;
    $flarum_tag_slug = '';

    if ($podcast) {
        $flarum_enabled = (int)kp_get_podcast_meta($podcast, 'flarum_enabled', 0);
        $flarum_tag_slug = kp_get_podcast_meta($podcast, 'flarum_tag_slug', '');
    }

    ?>
    <label class="toggle-row" style="margin-top:14px">
      <span><strong>Publicar en Flarum</strong><br><span class="help">Al publicar un episodio, se creará automáticamente una discusión en el foro.</span></span>
      <label class="toggle">
        <input type="checkbox" name="flarum_enabled" value="1" <?= $flarum_enabled ? 'checked' : '' ?>>
        <span class="toggle-track"></span>
      </label>
    </label>
    <div class="field" style="margin-top:12px">
      <label class="label">Slug del tag en Flarum</label>
      <input class="input" name="flarum_tag_slug" value="<?= e($flarum_tag_slug) ?>" placeholder="<?= e($podcast['slug'] ?? 'mi-podcast') ?>" style="font-family:ui-monospace,monospace">
      <div class="help" style="margin-top:4px">Slug del tag en Flarum (ej: <code>como-pienso-digo</code>). Si lo dejas vacío se usará el slug del podcast.</div>
    </div>
    <?php
}

/**
 * Guarda los campos del formulario en el meta_json del podcast.
 */
function kp_flarum_plugin_save_podcast_fields(int $podcastId, array $postData): void {
    $flarum_enabled = isset($postData['flarum_enabled']) ? 1 : 0;
    $flarum_tag_slug = trim($postData['flarum_tag_slug'] ?? '');

    kp_update_podcast_meta($podcastId, 'flarum_enabled', $flarum_enabled);
    kp_update_podcast_meta($podcastId, 'flarum_tag_slug', $flarum_tag_slug);
}


// ── Funciones de API Flarum (cURL) ──────────────────────────────────────────

/**
 * Resuelve el ID numérico de un tag de Flarum a partir de su slug.
 */
function kp_flarum_plugin_resolve_tag(string $slug): ?int {
    if (!$slug) return null;
    $url    = rtrim(kp_setting('flarum_url', ''), '/');
    $apiKey = kp_setting('flarum_api_key', '');
    $userId = (int)kp_setting('flarum_user_id', 1);

    if (!$url || !$apiKey) return null;

    $ch = curl_init("{$url}/api/tags");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            "Authorization: Token {$apiKey}; userId={$userId}",
        ],
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_COOKIE         => '',
        CURLOPT_COOKIEFILE     => '',
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 400 || !$response) return null;

    $data = json_decode($response, true);
    if (empty($data['data'])) return null;

    foreach ($data['data'] as $tag) {
        if (($tag['attributes']['slug'] ?? '') === $slug) {
            return (int)$tag['id'];
        }
    }
    return null;
}

/**
 * Publica una nueva discusión en Flarum.
 */
function kp_flarum_plugin_post_to_forum(string $title, string $content, string $tagSlug = '', string $epUrl = ''): ?array {
    $url    = rtrim(kp_setting('flarum_url', ''), '/');
    $apiKey = kp_setting('flarum_api_key', '');
    $userId = (int)kp_setting('flarum_user_id', 1);

    if (!$url || !$apiKey) return null;

    // Construir el cuerpo del post
    $body = $content;
    if ($epUrl) {
        $body .= "\n\n[Enlace al Episodio]({$epUrl})";
    }

    // JSON:API payload
    $payload = [
        'data' => [
            'type' => 'discussions',
            'attributes' => [
                'title'   => $title,
                'content' => $body,
            ],
        ],
    ];

    // Resolver tag por slug
    $tagId = $tagSlug ? kp_flarum_plugin_resolve_tag($tagSlug) : null;
    if (!$tagId) {
        kp_alert('system', 'Error al publicar en Flarum (Plugin)', "No se encontró el tag '{$tagSlug}' en Flarum. Verifica que exista en tu foro.", $url);
        return null;
    }
    $payload['data']['relationships'] = [
        'tags' => [
            'data' => [
                ['type' => 'tags', 'id' => (string)$tagId],
            ],
        ],
    ];

    $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $ch = curl_init("{$url}/api/discussions");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $jsonPayload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            "Authorization: Token {$apiKey}; userId={$userId}",
        ],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_COOKIE         => '',      // Bypass CSRF
        CURLOPT_COOKIEFILE     => '',
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($error || $httpCode >= 400) {
        $detail = $error ?: "HTTP $httpCode";
        $flarumError = '';
        if ($response) {
            $errData = json_decode($response, true);
            if (!empty($errData['errors'])) {
                $parts = [];
                foreach ($errData['errors'] as $e) {
                    $parts[] = ($e['detail'] ?? $e['code'] ?? 'unknown');
                }
                $flarumError = implode('; ', $parts);
            } else {
                $flarumError = substr($response, 0, 200);
            }
        }
        $msg = "No se pudo crear la discusión '{$title}': {$detail}";
        if ($flarumError) $msg .= " — {$flarumError}";
        kp_alert('system', 'Error al publicar en Flarum (Plugin)', $msg, $url);
        return null;
    }

    $result = json_decode($response, true);
    $discussionId = $result['data']['id'] ?? '?';
    $slug = $result['data']['attributes']['slug'] ?? '';
    $discussionUrl = $slug ? "{$url}/d/{$slug}" : "{$url}/d/{$discussionId}";

    kp_alert('system', 'Publicado en Flarum', "Se creó la discusión '{$title}' en el foro.", $discussionUrl);

    return $result;
}

/**
 * Elimina una discusión de Flarum a través de su API.
 */
function kp_flarum_plugin_delete_from_forum(string $discussionId): bool {
    $url    = rtrim(kp_setting('flarum_url', ''), '/');
    $apiKey = kp_setting('flarum_api_key', '');
    $userId = (int)kp_setting('flarum_user_id', 1);

    if (!$url || !$apiKey || !$discussionId) return false;

    $ch = curl_init("{$url}/api/discussions/{$discussionId}");
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'DELETE',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            "Authorization: Token {$apiKey}; userId={$userId}",
        ],
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_COOKIE         => '',
        CURLOPT_COOKIEFILE     => '',
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $httpCode === 200 || $httpCode === 204;
}
