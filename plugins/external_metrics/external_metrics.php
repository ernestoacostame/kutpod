<?php
// ============================================================================
// KutPod · Plugin Importador de Métricas Externas (Apple Podcasts / Spotify)
// ============================================================================

declare(strict_types=1);

if (!defined('KUTPOD_VERSION')) {
    exit;
}

function kp_external_metrics_is_active(): bool {
    return function_exists('kp_plugin_is_active') && kp_plugin_is_active('external_metrics');
}
