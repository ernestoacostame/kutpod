<?php
/**
 * php/api/index.php
 *
 * Router único de la API /api/*. Mantenerlo plano hace el rewrite trivial:
 *   /api/auth/login                          → auth.login
 *   /api/podcasts                            → podcasts.list
 *   /api/podcasts/{id}/episodes              → episodes.list / episodes.create
 *
 * El rewrite del .htaccess pasa la ruta como ?route=<path-relativa-a-/api/>.
 */

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/../includes/cache.php';

$route  = trim((string)($_GET['route'] ?? ''), '/');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Match: auth/login
if ($route === 'auth/login' && $method === 'POST') {
    require __DIR__ . '/handlers/auth_login.php';
    exit;
}

// Match: podcasts
if ($route === 'podcasts' && $method === 'GET') {
    require __DIR__ . '/handlers/podcasts_list.php';
    exit;
}

// Match: podcasts/{id}/episodes (list y create)
if (preg_match('~^podcasts/([^/]+)/episodes$~', $route, $m)) {
    $GLOBALS['_podcast_id'] = $m[1];
    if ($method === 'GET')  { require __DIR__ . '/handlers/episodes_list.php';   exit; }
    if ($method === 'POST') { require __DIR__ . '/handlers/episode_create.php';  exit; }
    api_error(405, 'method_not_allowed', "Método $method no permitido aquí");
}

// Match: podcasts/{id}/episodes/{ep_id} (update)
if (preg_match('~^podcasts/([^/]+)/episodes/([0-9]+)$~', $route, $m)) {
    $GLOBALS['_podcast_id'] = $m[1];
    $GLOBALS['_episode_id'] = $m[2];
    if ($method === 'POST') { require __DIR__ . '/handlers/episode_update.php'; exit; }
    api_error(405, 'method_not_allowed', "Método $method no permitido aquí");
}

// Match: me (útil para sanity checks desde KutEditor)
if ($route === 'me' && $method === 'GET') {
    $u = api_require_user();
    unset($u['password_hash']);
    api_json(['user' => $u]);
}

// Match: v1/stats/export.csv (y stats/export.csv)
if (($route === 'v1/stats/export.csv' || $route === 'stats/export.csv') && $method === 'GET') {
    require __DIR__ . '/handlers/stats_export.php';
    exit;
}

// Match: update (check, apply, rollback — solo sesión admin, no API tokens)
if ($route === 'update') {
    require __DIR__ . '/handlers/update.php';
    exit;
}

api_error(404, 'not_found', "Ruta /api/$route no existe");
