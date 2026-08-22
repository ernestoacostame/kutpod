<?php
// ============================================================================
// KutPod · Sistema de Ganchos (Hooks: Actions y Filters)
// ============================================================================

require_once __DIR__ . '/../version.php';

if (!defined('KUTPOD_VERSION')) {
    define('KUTPOD_VERSION', '1.0.0'); // Fallback de seguridad
}

$kp_actions = [];
$kp_filters = [];

/**
 * Registra una función callback para una acción específica.
 */
function kp_add_action(string $hook, callable $callback, int $priority = 10): void {
    global $kp_actions;
    $kp_actions[$hook][$priority][] = $callback;
}

/**
 * Ejecuta todas las funciones registradas para una acción.
 */
function kp_do_action(string $hook, ...$args): void {
    global $kp_actions;
    if (empty($kp_actions[$hook])) {
        return;
    }

    $priorities = $kp_actions[$hook];
    ksort($priorities);

    foreach ($priorities as $priority => $callbacks) {
        foreach ($callbacks as $callback) {
            call_user_func_array($callback, $args);
        }
    }
}

/**
 * Registra una función callback para un filtro específico.
 */
function kp_add_filter(string $hook, callable $callback, int $priority = 10): void {
    global $kp_filters;
    $kp_filters[$hook][$priority][] = $callback;
}

/**
 * Aplica los filtros registrados a un valor dado.
 */
function kp_apply_filters(string $hook, $value, ...$args) {
    global $kp_filters;
    if (empty($kp_filters[$hook])) {
        return $value;
    }

    $priorities = $kp_filters[$hook];
    ksort($priorities);

    foreach ($priorities as $priority => $callbacks) {
        foreach ($callbacks as $callback) {
            // El primer parámetro del callback es el valor filtrado acumulado
            $value = call_user_func_array($callback, array_merge([$value], $args));
        }
    }

    return $value;
}
