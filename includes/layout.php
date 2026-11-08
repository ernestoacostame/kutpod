<?php
// ============================================================================
// KutPod · layout admin (sidebar + topbar + footer) · rutas /admin/*
// ============================================================================
// Variables esperadas: $page (slug actual), $title (string), $body (HTML).

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../version.php';
require_once __DIR__ . '/updater.php';

$kp_update_cache = kp_update_cached();
$kp_update_available = false;
$kp_available_ver = '';
if ($kp_update_cache && !empty($kp_update_cache['available']) && !empty($kp_update_cache['new_version'])) {
  if (version_compare($kp_update_cache['new_version'], KUTPOD_VERSION, '>')) {
    $kp_update_available = true;
    $kp_available_ver = $kp_update_cache['new_version'];
  }
}
$kp_check_stale = kp_update_cache_stale();

$is_podcast_section = in_array($page, ['podcasts','new-podcast','edit-podcast','new-episode','podcast','import']);

$unread_alerts = 0;
try {
  $unread_alerts = (int)(kp_one("SELECT count(*) c FROM alerts WHERE read_at IS NULL")['c'] ?? 0);
} catch (Throwable $e) {}

// Removed admin_url (moved to helpers.php)
$cur_lang = kp_detect_lang();
?><!doctype html>
<html lang="<?= e($cur_lang) ?>" data-theme="<?= e(kp_setting('admin_theme', 'dark')) ?>">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title><?= e($title ?? 'KutPod Studio') ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com"/>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="/assets/styles.css?v=<?= filemtime(__DIR__ . '/../assets/styles.css') ?>">
<?php
$acc = kp_setting('admin_accent', '#ff5f7e');
$acc_rgb = sscanf($acc, "#%02x%02x%02x");
if ($acc_rgb && count($acc_rgb) === 3) {
    $rgb_str = $acc_rgb[0].', '.$acc_rgb[1].', '.$acc_rgb[2];
    echo "<style>:root { --accent: {$acc}; --accent-soft: rgba({$rgb_str}, 0.14); --accent-glow: 0 0 0 1px rgba({$rgb_str}, 0.35), 0 18px 60px -20px rgba({$rgb_str}, 0.55); }</style>\n";
}
$logo = kp_setting('instance_logo');
if ($logo) {
    echo '<link rel="icon" href="' . e($logo) . '">';
}
?>
</head>
<body>
<?php
$is_auth_page = in_array($page, ['login', 'login-2fa', 'forgot', 'reset', 'recover'], true);
?>
<div class="app <?= $is_auth_page ? 'app-login' : '' ?>">
  <?php if (!$is_auth_page): ?>
  <aside class="sidebar">
    <div class="brand" style="justify-content:space-between">
      <div style="display:flex;align-items:center;gap:12px">
        <?php if ($logo): ?>
          <img src="<?= e($logo) ?>" style="width:24px;height:24px;border-radius:6px;object-fit:cover">
        <?php else: ?>
          <div class="brand-mark">K</div>
        <?php endif; ?>
        <div><div class="brand-name">KutPod</div><div class="brand-sub">Studio · v<?= KUTPOD_VERSION ?></div></div>
      </div>
      <a href="/" target="_blank" rel="noopener" class="btn btn-ghost" style="padding:4px 8px;font-size:11px"><?= __('view_site') ?></a>
    </div>
    <div class="nav-section">
      <a class="nav-item <?= $page==='overview'?'active':'' ?>" href="<?= admin_url() ?>"><?= icon('grid') ?> <?= __('nav_overview') ?></a>
      <a class="nav-item <?= $page==='alerts'?'active':'' ?>" href="<?= admin_url('alerts') ?>">
        <?= icon('bell') ?> <?= __('nav_alerts') ?>
        <?php if ($unread_alerts): ?>
          <span class="badge" style="<?= $page==='alerts' ? '' : 'background:var(--accent);color:var(--bg-1)' ?>"><?= $unread_alerts ?></span>
        <?php endif; ?>
      </a>
    </div>
    <div class="nav-section">
      <div class="nav-label"><?= __('nav_podcasts') ?></div>
      <a class="nav-item <?= in_array($page,['podcasts','podcast'])?'active':'' ?>" href="<?= admin_url('podcasts') ?>"><?= icon('mic') ?> <?= __('nav_all_podcasts') ?></a>
      <a class="nav-item <?= $page==='new-podcast'?'active':'' ?>" href="<?= admin_url('new-podcast') ?>"><?= icon('plus') ?> <?= __('nav_new_podcast') ?></a>
      <a class="nav-item <?= $page==='import'?'active':'' ?>" href="<?= admin_url('import') ?>"><?= icon('rss') ?> <?= __('nav_import_rss') ?></a>
    </div>
    <div class="nav-section">
      <div class="nav-label"><?= __('nav_network') ?></div>
      <a class="nav-item <?= $page==='analytics'?'active':'' ?>" href="<?= admin_url('analytics') ?>"><?= icon('chart') ?> <?= __('nav_analytics') ?></a>
      <?php if (kp_plugin_is_active('fediverse')): ?>
        <a class="nav-item <?= $page==='fediverse'?'active':'' ?>" href="<?= admin_url('fediverse') ?>"><?= icon('globe') ?> <?= __('nav_fediverse') ?></a>
      <?php endif; ?>
      <a class="nav-item <?= $page==='pages'?'active':'' ?>" href="<?= admin_url('pages') ?>"><?= icon('file') ?> <?= __('nav_pages') ?></a>
    </div>
    <div class="nav-section">
      <div class="nav-label"><?= __('nav_admin') ?></div>
      <a class="nav-item <?= $page==='update'?'active':'' ?>" href="<?= admin_url('update') ?>">
        <?= icon('refreshCw') ?> <span><?= __('nav_update') ?></span>
        <span class="badge" id="kp-update-badge" style="background:var(--accent);color:var(--bg-1);margin-left:auto;font-size:10px;font-weight:700;<?= $kp_update_available ? '' : 'display:none;' ?>">v<?= e($kp_available_ver) ?></span>
      </a>
      <a class="nav-item <?= $page==='queue'?'active':'' ?>" href="<?= admin_url('queue') ?>"><?= icon('layers') ?> <?= __('nav_queue') ?></a>
      <a class="nav-item <?= $page==='backups'?'active':'' ?>" href="<?= admin_url('backups') ?>"><?= icon('layers') ?> <?= __('nav_backups') ?></a>
      <a class="nav-item <?= $page==='preferences'?'active':'' ?>" href="<?= admin_url('preferences') ?>"><?= icon('settings') ?> <?= __('nav_preferences') ?></a>
      <a class="nav-item <?= $page==='plugins'?'active':'' ?>" href="<?= admin_url('plugins') ?>"><?= icon('layers') ?> <?= __('nav_plugins') ?></a>
      <a class="nav-item <?= $page==='users'?'active':'' ?>" href="<?= admin_url('users') ?>"><?= icon('users') ?> <?= __('nav_users') ?></a>
    </div>
    <div class="nav-section">
      <div class="nav-label"><?= __('nav_other') ?></div>
      <a class="nav-item <?= $page==='api'?'active':'' ?>" href="<?= admin_url('api') ?>"><?= icon('share') ?> <?= __('nav_api') ?></a>
    </div>
    <div class="sidebar-footer">
      <a href="<?= admin_url('users') ?>" style="display:flex; align-items:center; gap:10px; flex:1; min-width:0; text-decoration:none; color:inherit;">
        <?php if (!empty($current_user['avatar'])): ?>
          <img src="<?= e($current_user['avatar']) ?>" class="avatar" style="object-fit:cover" alt="">
        <?php else: ?>
          <div class="avatar"><?= e(strtoupper(substr($current_user['name'] ?? 'EA',0,2))) ?></div>
        <?php endif; ?>
        <div style="min-width:0;flex:1">
          <div style="font-size:13px;font-weight:500"><?= e($current_user['name'] ?? 'Owner') ?></div>
          <div style="font-size:11px;color:var(--text-3)"><?= e(ucfirst($current_user['role'] ?? 'owner')) ?></div>
        </div>
      </a>
      <a class="kebab" href="/admin?action=logout" title="<?= __('logout') ?>"><?= icon('logOut') ?></a>
    </div>
  </aside>
  <?php endif; ?>

  <main class="main">
    <?php if (!$is_auth_page): ?>
    <div class="topbar">
      <div class="crumbs">
        <a class="btn btn-ghost" style="padding:4px 8px;font-size:13px" href="<?= admin_url() ?>">KutPod</a>
        <?= icon('chevronRight',14) ?>
        <span class="crumb-current"><?= e($title ?? __('page')) ?></span>
      </div>
      <div style="display:flex;align-items:center;gap:12px">
        <!-- Selector rápido de Idioma -->
        <div style="display:inline-flex;gap:4px;align-items:center;background:var(--surface-2, rgba(255,255,255,0.06));padding:3px 6px;border-radius:6px;font-size:11.5px;font-weight:700;">
          <a href="?lang=es" onclick="document.cookie='kp_lang=es;path=/;max-age=31536000;SameSite=Lax'" style="color:var(--text-1, white);text-decoration:none;padding:2px 6px;border-radius:4px;<?= $cur_lang === 'es' ? 'background:var(--accent);color:var(--bg-1, #000);' : 'opacity:0.6;' ?>">ES</a>
          <a href="?lang=en" onclick="document.cookie='kp_lang=en;path=/;max-age=31536000;SameSite=Lax'" style="color:var(--text-1, white);text-decoration:none;padding:2px 6px;border-radius:4px;<?= $cur_lang === 'en' ? 'background:var(--accent);color:var(--bg-1, #000);' : 'opacity:0.6;' ?>">EN</a>
        </div>
        <form class="search" action="<?= admin_url('search') ?>" method="GET" autocomplete="off" role="search">
          <label style="display:flex;align-items:center;gap:8px;width:100%;cursor:text">
            <?= icon('search',14) ?>
            <input name="q" placeholder="<?= __('search_placeholder_podcasts') ?>" value="<?= e($_GET['q'] ?? '') ?>" autocomplete="off" spellcheck="false"/>
          </label>
          <kbd>⌘K</kbd>
          <div class="search-dropdown" id="search-dropdown" style="display:none;" role="listbox"></div>
        </form>
      </div>
    </div>
    <?php else: ?>
    <!-- Selector rápido de idioma en páginas de acceso -->
    <div style="position:fixed;top:16px;right:16px;z-index:999;display:inline-flex;gap:4px;align-items:center;background:var(--surface-2, rgba(255,255,255,0.06));padding:3px 6px;border-radius:6px;font-size:11.5px;font-weight:700;box-shadow:0 2px 8px rgba(0,0,0,0.2);">
      <a href="?lang=es" onclick="document.cookie='kp_lang=es;path=/;max-age=31536000;SameSite=Lax'" style="color:var(--text-1, white);text-decoration:none;padding:2px 6px;border-radius:4px;<?= $cur_lang === 'es' ? 'background:var(--accent);color:var(--bg-1, #000);' : 'opacity:0.6;' ?>">ES</a>
      <a href="?lang=en" onclick="document.cookie='kp_lang=en;path=/;max-age=31536000;SameSite=Lax'" style="color:var(--text-1, white);text-decoration:none;padding:2px 6px;border-radius:4px;<?= $cur_lang === 'en' ? 'background:var(--accent);color:var(--bg-1, #000);' : 'opacity:0.6;' ?>">EN</a>
    </div>
    <?php endif; ?>
    <?php if (!$is_auth_page && $page !== 'update'): ?>
    <div id="kp-update-banner" class="update-notification-banner" style="<?= $kp_update_available ? 'display:flex;' : 'display:none;' ?>">
      <div class="update-banner-icon">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z"></path><path d="m12 15-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"></path><path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0"></path><path d="M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5"></path></svg>
      </div>
      <div class="update-banner-body">
        <div class="update-banner-title"><?= __('kp_update_available_title', '¡Nueva versión disponible!') ?></div>
        <div class="update-banner-desc">
          <?= __raw('kp_update_available_desc', 'Hay una nueva versión de KutPod (:version). Actualmente tienes instalada la v:current.', [
            'version' => '<strong id="kp-update-ver-label">v' . e($kp_available_ver) . '</strong>',
            'current' => KUTPOD_VERSION
          ]) ?>
        </div>
      </div>
      <div class="update-banner-actions">
        <a href="<?= admin_url('update') ?>" class="btn btn-accent btn-sm" style="text-decoration:none;font-weight:600"><?= __('kp_update_now_btn', 'Actualizar ahora') ?> →</a>
        <button type="button" class="btn btn-ghost btn-sm" onclick="dismissUpdateBanner('kp')" style="padding:4px 8px;font-size:14px;color:var(--text-3);border:none;cursor:pointer" title="Descartar">✕</button>
      </div>
    </div>
    <?php endif; ?>
    <div class="page"><?= $body ?></div>
  </main>
</div>
<script src="/assets/app.js?v=<?= filemtime(__DIR__ . '/../assets/app.js') ?>" defer></script>
<script>
function dismissUpdateBanner(app) {
  var ver = document.getElementById(app + '-update-ver-label')?.innerText || '1';
  sessionStorage.setItem('dismissed_update_' + app, ver);
  var b = document.getElementById(app + '-update-banner');
  if (b) b.style.display = 'none';
}

document.addEventListener('DOMContentLoaded', function() {
  var banner = document.getElementById('kp-update-banner');
  var badge = document.getElementById('kp-update-badge');
  var verLabel = document.getElementById('kp-update-ver-label');

  if (banner && banner.style.display !== 'none') {
    var dismissed = sessionStorage.getItem('dismissed_update_kp');
    if (dismissed && verLabel && dismissed === verLabel.innerText) {
      banner.style.display = 'none';
    }
  }

  <?php if (!empty($kp_check_stale)): ?>
  fetch('/api/update?action=check', { method: 'GET', headers: { 'Accept': 'application/json' } })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (data && data.available && data.new_version) {
        var currentVer = '<?= KUTPOD_VERSION ?>';
        if (data.new_version !== currentVer) {
          if (verLabel) verLabel.innerText = 'v' + data.new_version;
          if (badge) {
            badge.innerText = 'v' + data.new_version;
            badge.style.display = 'inline-block';
          }
          var dismissed = sessionStorage.getItem('dismissed_update_kp');
          if (banner && dismissed !== ('v' + data.new_version) && '<?= $page ?>' !== 'update') {
            banner.style.display = 'flex';
          }
        }
      }
    })
    .catch(function() {});
  <?php endif; ?>
});
</script>
</body>
</html>
