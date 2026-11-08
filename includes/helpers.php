<?php
// ============================================================================
// KutPod · helpers + iconos (mismas firmas que icons.js/app.js)
// ============================================================================

function e(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

function admin_url(string $p = ''): string {
  return '/admin' . ($p ? '/' . $p : '');
}

// ============================================================================
// i18n · Detección y traducción
// ============================================================================
function kp_detect_lang(): string {
  global $kp_current_lang;
  if ($kp_current_lang !== null) {
    return $kp_current_lang;
  }
  // 1. Parámetro GET explícito (persiste en sesión y cookie)
  if (isset($_GET['lang']) && in_array($_GET['lang'], ['es', 'en'], true)) {
    kp_set_lang($_GET['lang']);
    return $_GET['lang'];
  }
  // 2. Cookie explícita
  if (!empty($_COOKIE['kp_lang']) && in_array($_COOKIE['kp_lang'], ['es', 'en'], true)) {
    return $_COOKIE['kp_lang'];
  }
  // 3. Sesión
  if (session_status() === PHP_SESSION_NONE && !headers_sent() && isset($_COOKIE[session_name()])) {
    @session_start();
  }
  if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['kp_lang']) && in_array($_SESSION['kp_lang'], ['es', 'en'], true)) {
    return $_SESSION['kp_lang'];
  }
  // 4. Ajuste guardado en la base de datos
  $dbLang = kp_setting('site_language');
  if ($dbLang && in_array($dbLang, ['es', 'en'], true)) {
    return $dbLang;
  }
  // 5. Cabecera HTTP Accept-Language del navegador
  $accept = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
  if ($accept) {
    $esPos = stripos($accept, 'es');
    $enPos = stripos($accept, 'en');
    if ($enPos !== false && ($esPos === false || $enPos < $esPos)) {
      return 'en';
    }
  }
  return 'es'; // predeterminado
}

function kp_set_lang(string $lang): void {
  global $kp_current_lang;
  if (!in_array($lang, ['es', 'en'], true)) {
    $lang = 'es';
  }
  $kp_current_lang = $lang;
  if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    @session_start();
  }
  if (session_status() === PHP_SESSION_ACTIVE) {
    $_SESSION['kp_lang'] = $lang;
  }
  $_COOKIE['kp_lang'] = $lang;
  if (!headers_sent()) {
    $isHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ||
               (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
               (isset($_SERVER['REQUEST_SCHEME']) && $_SERVER['REQUEST_SCHEME'] === 'https') ||
               (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
    setcookie('kp_lang', $lang, [
      'expires'  => time() + 31536000,
      'path'     => '/',
      'secure'   => $isHttps,
      'httponly' => false,
      'samesite' => 'Lax'
    ]);
  }
}

function __(string $key, $fallback = '', array $replace = []): string {
  if (is_array($fallback)) {
    $replace = $fallback;
    $fallback = '';
  }
  static $strings = null;
  static $loadedLang = null;
  $lang = kp_detect_lang();
  if ($strings === null || $loadedLang !== $lang) {
    $loadedLang = $lang;
    $file = __DIR__ . "/lang/{$lang}.php";
    $strings = file_exists($file) ? require $file : [];
    $fallbackFile = __DIR__ . "/lang/es.php";
    if ($lang !== 'es' && file_exists($fallbackFile)) {
      $esStrings = require $fallbackFile;
      $strings = array_merge($esStrings, $strings);
    }
  }
  $text = $strings[$key] ?? ($fallback !== '' ? $fallback : $key);
  if (strpos($text, '|') !== false && isset($replace['count'])) {
    $parts = explode('|', $text);
    $text = ((int)$replace['count'] === 1) ? $parts[0] : ($parts[1] ?? $parts[0]);
  }
  if (!empty($replace)) {
    foreach ($replace as $k => $v) {
      $text = str_replace(':' . $k, (string)$v, $text);
    }
  }
  return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function __raw(string $key, $fallback = '', array $replace = []): string {
  if (is_array($fallback)) {
    $replace = $fallback;
    $fallback = '';
  }
  static $strings_raw = null;
  static $loadedLangRaw = null;
  $lang = kp_detect_lang();
  if ($strings_raw === null || $loadedLangRaw !== $lang) {
    $loadedLangRaw = $lang;
    $file = __DIR__ . "/lang/{$lang}.php";
    $strings_raw = file_exists($file) ? require $file : [];
    $fallbackFile = __DIR__ . "/lang/es.php";
    if ($lang !== 'es' && file_exists($fallbackFile)) {
      $esStrings = require $fallbackFile;
      $strings_raw = array_merge($esStrings, $strings_raw);
    }
  }
  $text = $strings_raw[$key] ?? ($fallback !== '' ? $fallback : $key);
  if (strpos($text, '|') !== false && isset($replace['count'])) {
    $parts = explode('|', $text);
    $text = ((int)$replace['count'] === 1) ? $parts[0] : ($parts[1] ?? $parts[0]);
  }
  if (!empty($replace)) {
    foreach ($replace as $k => $v) {
      $text = str_replace(':' . $k, (string)$v, $text);
    }
  }
  return $text;
}

// Settings · clave → valor desde tabla `settings`, cacheado por request.
function kp_setting(string $k, $default = null) {
  static $cache = null;
  if ($cache === null) {
    $cache = [];
    try {
      require_once __DIR__ . '/db.php';
      foreach (kp_q("SELECT k, v FROM settings") as $r) $cache[$r['k']] = $r['v'];
      
      if (!empty($cache['timezone'])) {
        date_default_timezone_set($cache['timezone']);
      }
    } catch (Throwable $e) {}
  }
  return $cache[$k] ?? $default;
}

// Guarda o actualiza un valor de configuración global en la tabla settings.
function kp_update_setting(string $k, $v): bool {
  try {
    require_once __DIR__ . '/db.php';
    kp_db()->prepare("INSERT INTO settings (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v, updated_at = datetime('now')")
           ->execute([$k, $v]);
    return true;
  } catch (Throwable $e) {
    return false;
  }
}


// Datos de instancia agregados — usados por layout público, login y forms.
function kp_instance(): array {
  return [
    'name'    => kp_setting('instance_name', 'KutPod'),
    'tagline' => kp_setting('instance_tagline', 'Plataforma de podcasts federados'),
    'domain'  => kp_setting('instance_domain', $_SERVER['HTTP_HOST'] ?? 'localhost'),
    'owner'   => kp_setting('owner_name', 'Instancia KutPod'),
    'email'   => kp_setting('owner_email', ''),
    'year'    => date('Y'),
  ];
}

function kp_handle_domain(): string {
  $domain = kp_setting('instance_domain', $_SERVER['HTTP_HOST'] ?? 'localhost');
  $domain = preg_replace('#^https?://#i', '', $domain);
  return rtrim($domain, '/');
}

function kp_get_protocol(): string {
  if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    return 'https';
  }
  if (($_SERVER['SERVER_PORT'] ?? 80) == 443) {
    return 'https';
  }
  if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
    return 'https';
  }
  return 'http';
}

function kp_base_url(): string {
  return kp_get_protocol() . '://' . kp_handle_domain();
}

if (!function_exists('human_bytes')) {
  function human_bytes($n): string {
    if ($n === null) return '—';
    $u = ['B','KB','MB','GB','TB']; $i = 0; $n = (float)$n;
    while ($n >= 1024 && $i < count($u)-1) { $n /= 1024; $i++; }
    return number_format($n, $n < 10 && $i > 0 ? 2 : 0) . ' ' . $u[$i];
  }
}

if (!function_exists('get_public_platform_svg')) {
  function get_public_platform_svg(string $type, string $slug): string {
    $path = __DIR__ . '/../public/assets/platforms/' . $type . '/' . $slug . '.svg';
    if (!file_exists($path)) $path = __DIR__ . '/../public/assets/platforms/' . $type . '/default.svg';
    if (file_exists($path)) return file_get_contents($path);
    return icon('link', 20);
  }
}


function icon(string $name, int $size = 16): string {
  static $paths = null;
  if ($paths === null) $paths = [
    'grid'=>'<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
    'mic'=>'<rect x="9" y="3" width="6" height="12" rx="3"/><path d="M5 11a7 7 0 0 0 14 0"/><path d="M12 18v3"/>',
    'rss'=>'<path d="M4 11a9 9 0 0 1 9 9"/><path d="M4 4a16 16 0 0 1 16 16"/><circle cx="5" cy="19" r="1.5" fill="currentColor"/>',
    'plus'=>'<path d="M12 5v14M5 12h14"/>',
    'share'=>'<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="m8.6 13.5 6.8 4M15.4 6.5l-6.8 4"/>',
    'eye'=>'<path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/>',
    'copy'=>'<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
    'more'=>'<circle cx="5" cy="12" r="1.5" fill="currentColor"/><circle cx="12" cy="12" r="1.5" fill="currentColor"/><circle cx="19" cy="12" r="1.5" fill="currentColor"/>',
    'shield'=>'<path d="M12 2 4 6v6c0 5 3.5 9 8 10 4.5-1 8-5 8-10V6z"/>',
    'search'=>'<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
    'bell'=>'<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10 21a2 2 0 0 0 4 0"/>',
    'chart'=>'<path d="M3 3v18h18"/><path d="M7 14l3-4 4 3 5-7"/>',
    'users'=>'<circle cx="9" cy="8" r="4"/><path d="M2 21a7 7 0 0 1 14 0"/><path d="M16 3.5a4 4 0 0 1 0 9"/><path d="M22 21a7 7 0 0 0-5-6.7"/>',
    'settings'=>'<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 0 1-4 0v-.1a1.7 1.7 0 0 0-1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 0 1 0-4h.1a1.7 1.7 0 0 0 1.5-1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3h.1a1.7 1.7 0 0 0 1-1.5V3a2 2 0 0 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8v.1a1.7 1.7 0 0 0 1.5 1H21a2 2 0 0 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
    'file'=>'<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/>',
    'globe'=>'<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
    'arrowUp'=>'<path d="m7 17 10-10M9 7h8v8"/>',
    'arrowDown'=>'<path d="m7 7 10 10M9 17h8V9"/>',
    'chevronRight'=>'<path d="m9 6 6 6-6 6"/>',
    'upload'=>'<path d="M12 16V4M6 10l6-6 6 6"/><path d="M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3"/>',
    'download'=>'<path d="M12 4v12M6 10l6 6 6-6"/><path d="M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3"/>',
    'play'=>'<path d="M6 4v16l14-8z"/>',
    'pause'=>'<rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/>',
    'edit'=>'<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 1 1 3 3L7 19l-4 1 1-4z"/>',
    'kebab'=>'<circle cx="12" cy="6" r="1.5" fill="currentColor"/><circle cx="12" cy="12" r="1.5" fill="currentColor"/><circle cx="12" cy="18" r="1.5" fill="currentColor"/>',
    'sun'=>'<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/>',
    'moon'=>'<path d="M21 12.79A9 9 0 1 1 11.21 3a7 7 0 0 0 9.79 9.79z"/>',
    'image'=>'<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-5-5L5 21"/>',
    'headphones'=>'<path d="M3 18v-6a9 9 0 0 1 18 0v6"/><path d="M21 19a2 2 0 0 1-2 2h-1v-6h3zM3 19a2 2 0 0 0 2 2h1v-6H3z"/>',
    'inbox'=>'<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
    'check'=>'<path d="M5 12l4 4 10-10"/>',
    'x'=>'<path d="M18 6 6 18M6 6l12 12"/>',
    'trash'=>'<path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
    'logOut'=>'<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
    'palette'=>'<circle cx="12" cy="12" r="9"/><circle cx="7.5" cy="10.5" r="1" fill="currentColor"/><circle cx="12" cy="7.5" r="1" fill="currentColor"/><circle cx="16.5" cy="10.5" r="1" fill="currentColor"/><circle cx="14.5" cy="15.5" r="1" fill="currentColor"/><path d="M12 21a4 4 0 0 1 0-8c2 0 3-1 3-2"/>',
    'layers'=>'<path d="m12 2 10 6-10 6L2 8z"/><path d="m2 16 10 6 10-6M2 12l10 6 10-6"/>',
    'refreshCw'=>'<polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>',
    'lock'=>'<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
    'shoppingBag'=>'<path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/>',
    'star'=>'<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
    'award'=>'<circle cx="12" cy="8" r="7"/><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"/>',
    'externalLink'=>'<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>',
    'mapPin'=>'<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',
  ];
  $p = $paths[$name] ?? '';
  return "<svg width=\"$size\" height=\"$size\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"1.75\" stroke-linecap=\"round\" stroke-linejoin=\"round\">$p</svg>";
}

// Tiempo relativo "hace 2h" / "2h ago" desde un timestamp ISO o UNIX.
function kp_relative_time($when): string {
  if (!$when) return '';
  if (is_string($when) && !is_numeric($when)) {
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $when)) {
      $when .= ' UTC';
    }
  }
  $t = is_numeric($when) ? (int)$when : strtotime((string)$when);
  if (!$t) return (string)$when;
  $d = max(0, time() - $t);
  $lang = kp_detect_lang();
  if ($lang === 'en') {
    if ($d < 60)        return $d . 's ago';
    if ($d < 3600)      return (int)($d/60) . 'm ago';
    if ($d < 86400)     return (int)($d/3600) . 'h ago';
    if ($d < 86400*7)   return (int)($d/86400) . 'd ago';
    if ($d < 86400*30)  return (int)($d/86400/7) . 'w ago';
    if ($d < 86400*365) return (int)($d/86400/30) . 'mo ago';
    return (int)($d/86400/365) . 'y ago';
  }
  if ($d < 60)        return 'hace ' . $d . 's';
  if ($d < 3600)      return 'hace ' . (int)($d/60) . 'm';
  if ($d < 86400)     return 'hace ' . (int)($d/3600) . 'h';
  if ($d < 86400*7)   return 'hace ' . (int)($d/86400) . 'd';
  if ($d < 86400*30)  return 'hace ' . (int)($d/86400/7) . 'sem';
  if ($d < 86400*365) return 'hace ' . (int)($d/86400/30) . 'mes';
  return 'hace ' . (int)($d/86400/365) . 'a';
}

function stat_card(string $label, string $value, string $iconName, array $opts = []): string {
  $delta = ''; $sub = ''; $foot = $opts['foot'] ?? '';
  if (!empty($opts['delta'])) {
    $d = $opts['delta'];
    $delta = '<div class="delta '.e($d['dir']).'">'.icon($d['dir']==='up'?'arrowUp':'arrowDown',12).' '.e($d['val']).'</div>';
  }
  if (!empty($opts['sub'])) $sub = '<div class="stat-sub">'.e($opts['sub']).'</div>';
  return '<div class="stat">
    <div class="stat-head"><div class="stat-label">'.e($label).'</div><div class="stat-icon">'.icon($iconName,14).'</div></div>
    <div class="stat-value tabular">'.e($value).'</div>'.$delta.$sub.$foot.'</div>';
}

function spark(array $values, ?string $color = null): string {
  $w = 100; $h = 28; $max = max($values); $min = min($values); $range = max(1, $max - $min);
  $n = count($values); $pts = [];
  foreach ($values as $idx => $v) {
    $x = ($idx / max(1, $n-1)) * $w;
    $y = $h - (($v - $min) / $range) * $h;
    $pts[] = "$x,$y";
  }
  $stroke = $color ?? 'var(--accent)';
  $pstr = implode(' ', $pts);
  return '<svg viewBox="0 0 '.$w.' '.$h.'" preserveAspectRatio="none" style="width:100%;height:28px;display:block"><polyline points="'.$pstr.'" fill="none" stroke="'.$stroke.'" stroke-width="1.5"/></svg>';
}

function ep_header(bool $podcastView = false): string {
  $col3 = $podcastView ? __('ep_col_fediverse') : __('ep_col_show');
  return '<div style="display:grid;grid-template-columns:40px 1.6fr 1fr 90px 100px 90px 80px;gap:14px;padding:8px;font-size:11px;color:var(--text-3);text-transform:uppercase;letter-spacing:0.08em;font-weight:600">
    <div></div><div>' . __('ep_col_title') . '</div><div>' . $col3 . '</div><div>' . __('ep_col_duration') . '</div><div>' . __('ep_col_downloads') . '</div><div>' . __('ep_col_status') . '</div><div></div>
  </div>';
}

function ep_row(array $ep, bool $podcastView = false): string {
  $p = kp_podcast_or_placeholder($ep['podcast'] ?? '');
  $cover = $ep['cover'] ?: ($p['cover'] ?? null);
  $coverHtml = $cover
    ? '<img class="cover" src="'.e($cover).'" style="width:32px;height:32px;border-radius:8px;object-fit:cover" alt="">'
    : '<div class="cover" style="width:32px;height:32px;border-radius:8px;background:linear-gradient(135deg,'.$p['color'].','.$p['color'].'99);color:white;font-weight:700;font-size:12px">'.e($p['initial']).'</div>';

  if ($podcastView) {
    if (!empty($p['federate']) && kp_plugin_is_active('fediverse')) {
      $csrf = kp_csrf_field();
      $col3Html = '<div style="display:inline-flex;gap:6px">
      <form method="POST" style="margin:0;padding:0;display:inline-block" onsubmit="return confirm(\'' . e(__('ep_confirm_refederate')) . '\')">
        ' . $csrf . '
        <input type="hidden" name="action" value="refederate">
        <input type="hidden" name="episode_id" value="' . (int)$ep['db_id'] . '">
        <button type="submit" class="btn" style="padding:4px 8px;font-size:11px;color:var(--accent);border-color:var(--accent);display:inline-flex;align-items:center;gap:4px">' . icon('globe', 11) . ' ' . __('ep_action_refederate') . '</button>
      </form>
      <form method="POST" style="margin:0;padding:0;display:inline-block" onsubmit="return confirm(\'' . e(__('ep_confirm_defederate')) . '\')">
        ' . $csrf . '
        <input type="hidden" name="action" value="defederate">
        <input type="hidden" name="episode_id" value="' . (int)$ep['db_id'] . '">
        <button type="submit" class="btn" style="padding:4px 8px;font-size:11px;color:#ef4444;border-color:#ef4444;display:inline-flex;align-items:center;gap:4px">' . icon('trash', 11) . ' ' . __('ep_action_defederate') . '</button>
      </form>
      </div>';
    } else {
      $col3Html = '<span style="font-size:12px;color:var(--text-3)">' . __('disabled') . '</span>';
    }
  } else {
    $col3Html = '<div style="font-size:13px;color:var(--text-2)">'.e($p['title']).'</div>';
  }

  $publicUrl = kp_get_protocol() . '://' . kp_handle_domain() . '/@' . rawurlencode($ep['podcast']) . '/' . rawurlencode($ep['id']);
  $copyBtnHtml = '<button class="admin-copy-url-btn" data-url="' . e($publicUrl) . '" style="background:none;border:none;padding:0;font-family:inherit;font-size:11.5px;color:var(--text-3);cursor:pointer;display:inline-flex;align-items:center;gap:3px;text-decoration:underline;vertical-align:middle;margin-left:2px" onmouseover="this.style.color=\'var(--text-2)\'" onmouseout="this.style.color=\'var(--text-3)\'">' . icon('copy', 11) . ' <span>' . __('copy_link') . '</span></button>';

  return '<div style="display:grid;grid-template-columns:40px 1.6fr 1fr 90px 100px 90px 80px;gap:14px;align-items:center;padding:12px 8px;border-top:1px solid var(--border)">
    ' . $coverHtml . '
    <div>
      <div style="font-size:13.5px;font-weight:500"><a href="' . e($publicUrl) . '" target="_blank" style="color:inherit;text-decoration:none" onmouseover="this.style.textDecoration=\'underline\'" onmouseout="this.style.textDecoration=\'none\'">' . e($ep['title']) . '</a></div>
      <div style="font-size:11.5px;color:var(--text-3);margin-top:2px;display:flex;align-items:center;gap:6px;flex-wrap:wrap">
        <span>S'.(int)$ep['s'].' · E'.(int)$ep['n'] . ($ep['guest'] && $ep['guest'] !== '—' ? ' · '.e($ep['guest']) : '') . ' · '.e($ep['date']).'</span>
        <span>·</span>
        ' . $copyBtnHtml . '
      </div>
    </div>
    ' . $col3Html . '
    <div class="tabular" style="font-size:13px">'.e($ep['duration']).'</div>
    <div class="tabular" style="font-size:13px">'.number_format($ep['downloads']).'</div>
    <div><span class="tag '.e($ep['status']).'">'.e($ep['status']).'</span></div>
    <div style="text-align:right"><a href="/admin/new-episode?id='.urlencode($ep['id']).'&podcast='.urlencode($ep['podcast']).'" class="btn" style="padding:4px 8px;font-size:12px;color:var(--accent);border-color:var(--accent)">'.icon('edit',13).' ' . __('ep_action_edit') . '</a></div>
  </div>';
}

function fmt_num(int $n): string { return number_format($n); }

/** Notifica a los Hubs WebSub (PubSubHubbub) que el feed RSS se ha actualizado. */
function kp_notify_websub(string $feed_url): void {
    $hubs = [
        'https://pubsubhubbub.appspot.com/',
        'https://pubsubhubbub.superfeedr.com/'
    ];
    $post_data = http_build_query([
        'hub.mode' => 'publish',
        'hub.url'  => $feed_url
    ]);

    $opts = [
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
            'content' => $post_data,
            'timeout' => 5, // No bloquear si un hub no responde
            'ignore_errors' => true
        ]
    ];
    $context = stream_context_create($opts);

    foreach ($hubs as $hub) {
        // Suprimir warnings en caso de que el hub esté caído
        @file_get_contents($hub, false, $context);
    }
}

function kp_unaccent(string $s): string {
  if (function_exists('iconv') && class_exists('Normalizer')) {
    return preg_replace('/[\x{0300}-\x{036f}]/u', '',
      iconv('UTF-8','UTF-8//IGNORE',
        Normalizer::normalize($s, Normalizer::FORM_D) ?: $s));
  }
  $map = [
    'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U',
    'à'=>'a','è'=>'e','ì'=>'i','ò'=>'o','ù'=>'u','À'=>'A','È'=>'E','Ì'=>'I','Ò'=>'O','Ù'=>'U',
    'ä'=>'a','ë'=>'e','ï'=>'i','ö'=>'o','ü'=>'u','Ä'=>'A','Ë'=>'E','Ï'=>'I','Ö'=>'O','Ü'=>'U',
    'â'=>'a','ê'=>'e','î'=>'i','ô'=>'o','û'=>'u','Â'=>'A','Ê'=>'E','Î'=>'I','Ô'=>'O','Û'=>'U',
    'ã'=>'a','õ'=>'o','Ã'=>'A','Õ'=>'O',
    'ñ'=>'n','Ñ'=>'N','ç'=>'c','Ç'=>'C'
  ];
  return strtr($s, $map);
}

function kp_slugify(string $s): string {
  $s = kp_unaccent($s);
  $s = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $s));
  return trim($s, '-') ?: 'item-' . substr(sha1($s), 0, 6);
}

function kp_extract_chapters_to_json(string $audioAbsPath): ?string {
  if (!file_exists($audioAbsPath)) return null;
  $cmd = "ffprobe -i " . escapeshellarg($audioAbsPath) . " -print_format json -show_chapters -loglevel error 2>/dev/null";
  $out = shell_exec($cmd);
  if (!$out) return null;

  $data = json_decode($out, true);
  if (empty($data['chapters'])) return null;

  $chapters = [];
  foreach ($data['chapters'] as $ch) {
      $startTime = floatval($ch['start_time'] ?? 0);
      $title = $ch['tags']['title'] ?? sprintf("Capítulo %d", count($chapters)+1);
      $chapters[] = [
          'startTime' => $startTime,
          'title' => $title,
          'img' => ''
      ];
  }

  if (empty($chapters)) return null;

  $jsonPath = preg_replace('/\.[a-z0-9]+$/i', '.json', $audioAbsPath);
  file_put_contents($jsonPath, json_encode(['chapters' => $chapters], JSON_UNESCAPED_UNICODE));
  return str_replace(__DIR__ . '/../media', '/media', $jsonPath);
}

/**
 * Inserta una nueva alerta en el sistema.
 * @param string $kind 'fediverse'|'import'|'op3'|'security'|'system'
 */
function kp_alert(string $kind, string $title, string $body = '', string $link = '', array $meta = []): void {
  try {
    $actor_url = $meta['actor_url'] ?? null;
    $action_type = $meta['action_type'] ?? null;
    $actor_avatar = $meta['actor_avatar'] ?? null;
    $actor_name = $meta['actor_name'] ?? null;

    kp_db()->prepare("INSERT INTO alerts (kind, title, body, link, actor_url, action_type, actor_avatar, actor_name, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, datetime('now'))")
           ->execute([$kind, $title, $body, $link, $actor_url, $action_type, $actor_avatar, $actor_name]);
  } catch (Throwable $e) {}
}

/**
 * Traduce el título de una alerta al idioma activo.
 */
function kp_alert_translate_title(?string $title): string {
  if ($title === null || $title === '') return '';
  $lang = kp_detect_lang();
  if ($lang === 'es') {
    $enToEs = [
      'Downloads processed' => 'Descargas procesadas',
      'Update applied' => 'Actualización aplicada',
      'Rollback completed' => 'Rollback completado',
      'Cleanup executed' => 'Limpieza ejecutada',
      'Episode published' => 'Episodio publicado',
      'Episode deleted' => 'Episodio eliminado',
      'Anti-bot rules synchronized' => 'Reglas anti-bot sincronizadas',
      'Import completed' => 'Importación completada',
      'Import failed' => 'Importación fallida',
      'Login' => 'Inicio de sesión',
      'Login (2FA)' => 'Inicio de sesión (2FA)',
      'Logout' => 'Cierre de sesión',
      'Failed login' => 'Login fallido',
      'Blocked due to excessive attempts' => 'Bloqueo por intentos excesivos',
      '2FA Enabled' => '2FA Activado',
      '2FA Disabled' => '2FA Desactivado',
      'Password reset' => 'Contraseña restablecida',
      'Password recovery' => 'Recuperación de contraseña',
      'Backup codes generated' => 'Códigos de respaldo generados',
      'Backup code used' => 'Código de respaldo utilizado',
      'API login failed' => 'Login API fallido',
      'API login successful' => 'Login API exitoso',
      'Invalid API token' => 'Token API inválido',
      'Profile updated' => 'Perfil actualizado',
      'New follower' => 'Nuevo seguidor',
      'New Like' => 'Nuevo Me gusta',
      'New Boost' => 'Nuevo Retoot',
      'New Comment' => 'Nuevo Comentario',
      'Episode re-sent to Fediverse' => 'Episodio re-enviado al Fediverso',
      'Episode published to Fediverse' => 'Episodio publicado en Fediverso',
      'Published to Flarum' => 'Publicado en Flarum',
      'YouTube views synchronized' => 'Vistas de YouTube sincronizadas',
      'New Premium Subscription' => 'Nueva Suscripción Premium',
      'Subscription Cancelled' => 'Suscripción Cancelada',
    ];
    return $enToEs[$title] ?? $title;
  }

  // Idioma 'en'
  $esToEn = [
    'Descargas procesadas' => 'Downloads processed',
    'Actualización aplicada' => 'Update applied',
    'Rollback completado' => 'Rollback completed',
    'Limpieza ejecutada' => 'Cleanup executed',
    'Episodio publicado' => 'Episode published',
    'Episodio eliminado' => 'Episode deleted',
    'Reglas anti-bot sincronizadas' => 'Anti-bot rules synchronized',
    'Importación completada' => 'Import completed',
    'Importación fallida' => 'Import failed',
    'Inicio de sesión' => 'Login',
    'Inicio de sesión (2FA)' => 'Login (2FA)',
    'Cierre de sesión' => 'Logout',
    'Login fallido' => 'Failed login',
    'Bloqueo por intentos excesivos' => 'Blocked due to excessive attempts',
    '2FA Activado' => '2FA Enabled',
    '2FA Desactivado' => '2FA Disabled',
    'Contraseña restablecida' => 'Password reset',
    'Recuperación de contraseña' => 'Password recovery',
    'Códigos de respaldo generados' => 'Backup codes generated',
    'Código de respaldo utilizado' => 'Backup code used',
    'Login API fallido' => 'API login failed',
    'Login API exitoso' => 'API login successful',
    'Token API inválido' => 'Invalid API token',
    'Error de conexión SMTP' => 'SMTP connection error',
    'Error de envío (SMTP)' => 'Email sending error (SMTP)',
    'Perfil actualizado' => 'Profile updated',
    'Nuevo seguidor' => 'New follower',
    'Nuevo Me gusta' => 'New Like',
    'Nuevo Retoot' => 'New Boost',
    'Nuevo Comentario' => 'New Comment',
    'Episodio re-enviado al Fediverso' => 'Episode re-sent to Fediverse',
    'Episodio publicado en Fediverso' => 'Episode published to Fediverse',
    'Error al federar episodio (no crítico)' => 'Error federating episode (non-critical)',
    'Publicado en Flarum' => 'Published to Flarum',
    'Error al publicar en Flarum (Plugin)' => 'Error publishing to Flarum (Plugin)',
    'Vistas de YouTube sincronizadas' => 'YouTube views synchronized',
    'Nueva Suscripción Premium' => 'New Premium Subscription',
    'Nueva Suscripción Premium (PayPal)' => 'New Premium Subscription (PayPal)',
    'Suscripción Cancelada (Stripe)' => 'Subscription Cancelled (Stripe)',
    'Suscripción Cancelada (PayPal)' => 'Subscription Cancelled (PayPal)',
    'Suscripción Pausada (Stripe)' => 'Subscription Paused (Stripe)',
    'Suscripción Suspendida (PayPal)' => 'Subscription Suspended (PayPal)',
    'Suscripción Reactivada (Stripe)' => 'Subscription Reactivated (Stripe)',
    'Suscripción Reactivada (PayPal)' => 'Subscription Reactivated (PayPal)',
    'Fallo de Pago (Stripe)' => 'Payment Failed (Stripe)',
    'Fallo de Pago (PayPal)' => 'Payment Failed (PayPal)',
    'Token Premium Suspendido' => 'Premium Token Suspended',
    'Error de Stripe Checkout' => 'Stripe Checkout Error',
    'Error en gancho de publicación (no crítico)' => 'Publishing hook error (non-critical)',
    'Episodio actualizado (API)' => 'Episode updated (API)',
    'Episodio publicado (API)' => 'Episode published (API)',
    'Episodio borrador (API)' => 'Episode draft (API)',
  ];

  if (isset($esToEn[$title])) {
    return $esToEn[$title];
  }

  // Patrones dinámicos
  if (preg_match('/^(.+?\s+)?([\d,\.]+)\s+descargas alcanzadas$/u', $title, $m)) {
    return trim($m[1] ?? '') . ' ' . $m[2] . ' downloads reached';
  }
  if (preg_match('/^(.+?\s+)?([\d,\.]+)\s+vistas alcanzadas en YouTube$/u', $title, $m)) {
    return trim($m[1] ?? '') . ' ' . $m[2] . ' views reached on YouTube';
  }
  if (preg_match('/^Nuevo\s+(.+)$/u', $title, $m)) {
    $act = match (trim($m[1])) {
      'Like', 'Me gusta' => 'Like',
      'Retoot' => 'Boost',
      'Comentario' => 'Comment',
      default => $m[1]
    };
    return 'New ' . $act;
  }

  return $title;
}

/**
 * Traduce el cuerpo de una alerta al idioma activo.
 */
function kp_alert_translate_body(?string $body): string {
  if ($body === null || $body === '') return '';
  $lang = kp_detect_lang();
  if ($lang === 'es') {
    return $body;
  }

  // 1. Descargas de OP3
  if (preg_match('/^(\d+)\s+nuevas?\s+descargas?\s+registradas?\s+\((\d+)\s+duplicados\s+filtrados\)(?:\.\s*Detalles:\s*(.*))?$/us', $body, $m)) {
    $count = (int)$m[1];
    $dups = (int)$m[2];
    $details = $m[3] ?? '';
    $downloadWord = $count === 1 ? 'new download' : 'new downloads';
    $res = "{$count} {$downloadWord} recorded ({$dups} duplicates filtered).";
    if ($details !== '') {
      $details = preg_replace('/(\d+)\s+de\s+\'/u', '$1 for \'', $details);
      $details = preg_replace('/\'\s+en\s+\'/u', '\' in \'', $details);
      $details = preg_replace('/y\s+(\d+)\s+m[aá]s/u', 'and $1 more', $details);
      $res .= ' Details: ' . rtrim($details, '.') . '.';
    }
    return $res;
  }

  // 2. Actualización / Rollback del sistema
  if (preg_match('/^KutPod actualizado de v([^\s]+) a v([^\s]+)$/u', rtrim($body, '.'), $m)) {
    return "KutPod updated from v{$m[1]} to v{$m[2]}.";
  }
  if (preg_match('/^KutPod revertido a v([^\s]+)$/u', rtrim($body, '.'), $m)) {
    return "KutPod rolled back to v{$m[1]}.";
  }
  if (str_starts_with($body, 'El garbage collector de base de datos se ejecutó correctamente')) {
    return 'Database garbage collector executed successfully. Old alerts, completed AP deliveries (>30d), outbox (>90d), old OP3 hits (>90d), expired sessions were purged and VACUUM was executed.';
  }

  // 3. Hitos de descargas y YouTube
  if (preg_match('/^El episodio \'(.+?)\' superó las ([\d,\.]+) descargas únicas verificadas por OP3\.?$/u', $body, $m)) {
    return "Episode '{$m[1]}' exceeded {$m[2]} verified unique downloads via OP3.";
  }
  if (preg_match('/^El episodio "(.+?)" ha superado las ([\d,\.]+) vistas en YouTube \(([\d,\.]+) vistas actuales\)\.?$/u', $body, $m)) {
    return "Episode \"{$m[1]}\" exceeded {$m[2]} views on YouTube ({$m[3]} current views).";
  }
  if (preg_match('/^Se actualizaron las estadísticas de YouTube para \'(.+?)\': (.*?) en (\d+) episodios emparejados\.?$/u', $body, $m)) {
    $viewsMsg = $m[2];
    $viewsMsg = preg_replace('/^\+(\d+)\s+nuevas vistas\s+\((\d+)\s+totales\)$/u', '+$1 new views ($2 total)', $viewsMsg);
    $viewsMsg = preg_replace('/^(\d+)\s+vistas totales$/u', '$1 total views', $viewsMsg);
    return "YouTube stats updated for '{$m[1]}': {$viewsMsg} across {$m[3]} matched episodes.";
  }

  // 4. Episodios y publicaciones
  if (preg_match('/^El episodio \'(.+?)\' se ha publicado en la red\.?$/u', $body, $m)) {
    return "Episode '{$m[1]}' has been published to the network.";
  }
  if (preg_match('/^El episodio \'(.+?)\' fue eliminado de (.+?)\.?$/u', $body, $m)) {
    return "Episode '{$m[1]}' was deleted from {$m[2]}.";
  }
  if (preg_match('/^\'(.+?)\' fue actualizado en (.+?) vía Kut Editor\.?$/u', $body, $m)) {
    return "'{$m[1]}' was updated in {$m[2]} via Kut Editor.";
  }
  if (preg_match('/^\'(.+?)\' se ha publicado en (.+?) vía Kut Editor\.?$/u', $body, $m)) {
    return "'{$m[1]}' was published in {$m[2]} via Kut Editor.";
  }
  if (preg_match('/^\'(.+?)\' se ha guardado como borrador en (.+?) vía Kut Editor\.?$/u', $body, $m)) {
    return "'{$m[1]}' was saved as draft in {$m[2]} via Kut Editor.";
  }
  if (preg_match('/^\'(.+?)\' se ha (.+?) en (.+?) vía Kut Editor\.?$/u', $body, $m)) {
    $action = $m[2] === 'borrador' ? 'saved as draft' : ($m[2] === 'publicado' ? 'published' : $m[2]);
    return "'{$m[1]}' was {$action} in {$m[3]} via Kut Editor.";
  }

  // 5. Fediverso
  if (preg_match('/^El perfil del podcast en el Fediverso se ha encolado para actualización\.?$/u', $body)) {
    return 'The podcast profile in the Fediverse has been queued for update.';
  }
  if (preg_match('/^@(.+?) ha comenzado a seguir a tu podcast \'(.+?)\'\.?$/u', $body, $m)) {
    return "@{$m[1]} started following your podcast '{$m[2]}'.";
  }
  if (preg_match('/^@(.+?) ha comentado en tu podcast\.?$/u', $body, $m)) {
    return "@{$m[1]} commented on your podcast.";
  }
  if (preg_match('/^El episodio \'(.+?)\' se ha vuelto a encolar para entrega\.?$/u', $body, $m)) {
    return "Episode '{$m[1]}' has been re-queued for delivery.";
  }
  if (preg_match('/^El episodio \'(.+?)\' se ha encolado para entrega\.?$/u', $body, $m)) {
    return "Episode '{$m[1]}' has been queued for delivery.";
  }
  if (preg_match('/^A @(.+?) le gusta tu episodio \'(.+?)\'\.?$/u', $body, $m)) {
    return "@{$m[1]} liked your episode '{$m[2]}'.";
  }
  if (preg_match('/^@(.+?) compartió tu episodio \'(.+?)\'\.?$/u', $body, $m)) {
    return "@{$m[1]} boosted your episode '{$m[2]}'.";
  }
  if (preg_match('/^@(.+?) ha interactuado con \'(.+?)\'\.?$/u', $body, $m)) {
    return "@{$m[1]} interacted with '{$m[2]}'.";
  }

  // 6. Seguridad y autenticación
  if (preg_match('/^(.+?) accedió tras validar 2FA desde (.+?)\.?$/u', $body, $m)) {
    return "{$m[1]} logged in after 2FA validation from {$m[2]}.";
  }
  if (preg_match('/^(.+?) accedió al panel desde (.+?)\.?$/u', $body, $m)) {
    return "{$m[1]} accessed the dashboard from {$m[2]}.";
  }
  if (preg_match('/^(.+?) cerró sesión\.?$/u', $body, $m)) {
    return "{$m[1]} logged out.";
  }
  if (preg_match('/^Intento de acceso con email \'(.+?)\' desde (.+?)\.?$/u', $body, $m)) {
    return "Access attempt with email '{$m[1]}' from {$m[2]}.";
  }
  if (preg_match('/^IP (.+?) bloqueada tras demasiados intentos de acceso fallidos\.?$/u', $body, $m)) {
    return "IP {$m[1]} blocked after too many failed access attempts.";
  }
  if (preg_match('/^Se activó la autenticación en dos pasos para (.+?)\.?$/u', $body, $m)) {
    return "Two-factor authentication was enabled for {$m[1]}.";
  }
  if (preg_match('/^Se desactivó la autenticación en dos pasos para (.+?)\.?$/u', $body, $m)) {
    return "Two-factor authentication was disabled for {$m[1]}.";
  }
  if (preg_match('/^Se generaron nuevos códigos para (.+?)\.?$/u', $body, $m)) {
    return "New backup codes were generated for {$m[1]}.";
  }
  if (preg_match('/^El usuario (.+?) ha utilizado un código de respaldo para acceder\.?$/u', $body, $m)) {
    return "User {$m[1]} used a backup code to log in.";
  }
  if (preg_match('/^El usuario (.+?) ha restablecido su contraseña\.?$/u', $body, $m)) {
    return "User {$m[1]} has reset their password.";
  }
  if (preg_match('/^Se ha enviado un enlace de recuperación al usuario (.+?)\.?$/u', $body, $m)) {
    return "A recovery link has been sent to user {$m[1]}.";
  }
  if (preg_match('/^Intento de acceso vía API con usuario \'(.+?)\' desde (.+?)\.?$/u', $body, $m)) {
    return "API access attempt with user '{$m[1]}' from {$m[2]}.";
  }
  if (preg_match('/^(.+?) se conectó vía API \((.+?)\) desde (.+?)\.?$/u', $body, $m)) {
    return "{$m[1]} connected via API ({$m[2]}) from {$m[3]}.";
  }
  if (preg_match('/^Se intentó acceder a la API con un token inválido o expirado desde (.+?)\.?$/u', $body, $m)) {
    return "Attempted to access the API with an invalid or expired token from {$m[1]}.";
  }
  if (preg_match('/^No se pudo conectar a (.+?)\.?$/u', $body, $m)) {
    return "Could not connect to {$m[1]}.";
  }
  if (preg_match('/^Fallo enviando correo a (.+?)\.?$/u', $body, $m)) {
    return "Failed sending email to {$m[1]}.";
  }

  // 7. Importación y reglas OP3
  if (preg_match('/^Se sincronizaron (\d+) reglas de detección de bots desde OP3\.?$/u', $body, $m)) {
    return "Synchronized {$m[1]} bot detection rules from OP3.";
  }
  if (preg_match('/^Se importaron (\d+) episodios(?: \((\d+) fallidos\))?(?: en \'(.+?)\')?\.?$/u', $body, $m)) {
    $res = "Imported {$m[1]} episodes";
    if (!empty($m[2])) $res .= " ({$m[2]} failed)";
    if (!empty($m[3])) $res .= " into '{$m[3]}'";
    return $res . '.';
  }
  if (preg_match('/^El job #(\d+) falló:\s*(.*)$/u', $body, $m)) {
    return "Job #{$m[1]} failed: {$m[2]}";
  }

  // 8. Plugins
  if (preg_match('/^Se creó la discusión \'(.+?)\' en el foro\.?$/u', $body, $m)) {
    return "Discussion '{$m[1]}' was created in the forum.";
  }
  if (preg_match('/^No se encontró el tag \'(.+?)\' en Flarum\. Verifica que exista en tu foro\.?$/u', $body, $m)) {
    return "Tag '{$m[1]}' not found in Flarum. Please verify it exists in your forum.";
  }
  if (preg_match('/^El usuario (.+?) se ha suscrito mediante (.+?)\.?$/u', $body, $m)) {
    return "User {$m[1]} subscribed via {$m[2]}.";
  }
  if (preg_match('/^La suscripción de (.+?) ha sido cancelada en (.+?)\.?$/u', $body, $m)) {
    return "The subscription of {$m[1]} was cancelled in {$m[2]}.";
  }
  if (preg_match('/^La suscripción de (.+?) ha sido pausada en (.+?)\.?$/u', $body, $m)) {
    return "The subscription of {$m[1]} was paused in {$m[2]}.";
  }
  if (preg_match('/^La suscripción de (.+?) ha sido suspendida en (.+?)\.?$/u', $body, $m)) {
    return "The subscription of {$m[1]} was suspended in {$m[2]}.";
  }
  if (preg_match('/^La suscripción de (.+?) ha sido reactivada en (.+?)\.?$/u', $body, $m)) {
    return "The subscription of {$m[1]} was reactivated in {$m[2]}.";
  }
  if (preg_match('/^El cobro de la suscripción de (.+?) ha fallado en (.+?)\. Acceso suspendido temporalmente\.?$/u', $body, $m)) {
    return "Subscription payment for {$m[1]} failed in {$m[2]}. Access temporarily suspended.";
  }
  if (preg_match('/^El cobro de PayPal para (.+?) ha sido denegado\. Acceso suspendido temporalmente\.?$/u', $body, $m)) {
    return "PayPal payment for {$m[1]} was declined. Access temporarily suspended.";
  }
  if (preg_match('/^El token de (.+?) fue suspendido automáticamente al registrar accesos desde (\d+) direcciones IP distintas en 24h\.?$/u', $body, $m)) {
    return "Token for {$m[1]} was automatically suspended after detecting accesses from {$m[2]} different IP addresses in 24h.";
  }
  if (preg_match('/^Error al crear sesión de checkout:\s*(.*)$/u', $body, $m)) {
    return "Error creating checkout session: {$m[1]}";
  }

  return $body;
}

/** Convierte texto plano/Markdown ligero a HTML para las notas públicas. */
function kp_md_to_html(string $md): string {
  if (!$md) return '';
  // Si contiene etiquetas HTML (como <p>, <br>, etc.), lo convertimos a markdown primero
  if (preg_match('/<[a-z\/][^>]*>/i', $md)) {
    $md = kp_html_to_markdown($md);
  }
  // Escapar todo el HTML primero para evitar inyección
  $h = htmlspecialchars($md, ENT_QUOTES, 'UTF-8');
  // Headers
  $h = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $h);
  $h = preg_replace('/^## (.+)$/m', '<h2>$1</h2>', $h);
  $h = preg_replace('/^# (.+)$/m', '<h1>$1</h1>', $h);
  // Bold, italic, code
  $h = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $h);
  $h = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $h);
  $h = preg_replace('/`([^`]+)`/', '<code>$1</code>', $h);
  // Markdown links [text](url) — solo http/https permitidos
  $h = preg_replace_callback('/\[(.+?)\]\((.+?)\)/', function($m) {
    $text = $m[1]; // ya escapado por htmlspecialchars arriba
    $url = $m[2];
    // Solo permitir protocolos seguros
    if (!preg_match('#^https?://#i', $url)) return $text;
    return '<a href="' . $url . '" target="_blank" rel="noopener">' . $text . '</a>';
  }, $h);
  // Blockquotes
  $h = preg_replace('/^&gt; (.+)$/m', '<blockquote>$1</blockquote>', $h);
  // Lists
  $h = preg_replace('/^- (.+)$/m', '<li>$1</li>', $h);
  $h = preg_replace('/(<li>.*<\/li>)/s', '<ul>$1</ul>', $h);
  // Auto-link bare URLs (not already inside an href or tag)
  $h = preg_replace('#(?<!["\'>=\/])(?<!\w)(https?://[^\s<\)]+)#i', '<a href="$1" target="_blank" rel="noopener">$1</a>', $h);
  // Paragraphs: double newlines become paragraph breaks, single newlines become <br>
  $h = preg_replace('/\n{2,}/', '</p><p>', $h);
  $h = str_replace("\n", '<br>', $h);
  return '<p>' . $h . '</p>';
}

/** Convierte texto HTML a Markdown limpio. */
function kp_html_to_markdown(string $html): string {
  if (!$html) return '';

  // Normalizar saltos de línea
  $md = str_replace(["\r\n", "\r"], "\n", $html);

  // Convertir <br> a salto de línea real al principio para que afecte blockquotes y párrafos
  $md = preg_replace('/<br\s*\/?>/is', "\n", $md);

  // Convertir encabezados h1, h2, h3
  $md = preg_replace('/<h1[^>]*>(.*?)<\/h1>/is', "# $1\n\n", $md);
  $md = preg_replace('/<h2[^>]*>(.*?)<\/h2>/is', "## $1\n\n", $md);
  $md = preg_replace('/<h3[^>]*>(.*?)<\/h3>/is', "### $1\n\n", $md);

  // Bold / Strong / b
  $md = preg_replace('/<(strong|b)[^>]*>(.*?)<\/ \1>/is', '**$2**', $md);
  $md = preg_replace('/<(strong|b)[^>]*>(.*?)<\/\1>/is', '**$2**', $md);

  // Italic / Em / i
  $md = preg_replace('/<(em|i)[^>]*>(.*?)<\/ \1>/is', '*$2*', $md);
  $md = preg_replace('/<(em|i)[^>]*>(.*?)<\/\1>/is', '*$2*', $md);

  // Enlaces: <a href="URL">Texto</a>
  $md = preg_replace_callback('/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', function($m) {
    $url = trim($m[1]);
    $text = trim($m[2]);
    if (empty($text)) {
      $text = $url;
    }
    return '[' . $text . '](' . $url . ')';
  }, $md);

  // Blockquotes: <blockquote>...</blockquote>
  $md = preg_replace_callback('/<blockquote[^>]*>(.*?)<\/blockquote>/is', function($m) {
    $content = trim($m[1]);
    $lines = explode("\n", $content);
    $quoted = array_map(function($line) {
      return '> ' . ltrim($line);
    }, $lines);
    return "\n\n" . implode("\n", $quoted) . "\n\n";
  }, $md);

  // Listas: li, ul, ol
  $md = preg_replace('/<li[^>]*>(.*?)<\/li>/is', "- $1\n", $md);
  $md = preg_replace('/<\/?(ul|ol)[^>]*>/is', "\n", $md);

  // Párrafos <p>...</p>
  $md = preg_replace('/<p[^>]*>(.*?)<\/p>/is', "$1\n\n", $md);

  // Eliminar cualquier etiqueta HTML sobrante
  $md = strip_tags($md);

  // Decodificar entidades HTML (como &gt; a >, &lt; a <, &amp; a &, &nbsp; a espacio, etc.)
  $md = html_entity_decode($md, ENT_QUOTES | ENT_HTML5, 'UTF-8');

  // Limpiar saltos de línea múltiples a un máximo de 2 consecutivos
  $md = preg_replace('/\n{3,}/', "\n\n", $md);

  return trim($md);
}

function kp_color_palette(): array {
  return [
    '#e63946', '#2a9d8f', '#e76f51', '#264653', '#f4a261', '#3a86ff', '#8338ec', '#ff006e', '#fb5607', '#ffbe0b', 
    '#0077b6', '#0096c7', '#023e8a', '#d90429', '#ef233c', '#00b4d8', '#48cae4', '#4cc9f0', '#4361ee', '#3f37c9', 
    '#560bad', '#7209b7', '#b5179e', '#f72585', '#b91d47', '#e3a21a', '#00a300', '#2d89ef', '#2b5797', '#ff0097', 
    '#603cba', '#1e7145', '#00aba9', '#2e8b57', '#d2691e', '#cd5c5c', '#4682b4', '#d43f3a', '#238d74', '#c71585', 
    '#8b008b', '#483d8b', '#2f4f4f', '#008080', '#008b8b', '#b8860b', '#059669', '#0284c7'
  ];
}

/** Genera un color rico y de alto contraste (evitando colores extremadamente brillantes o claros). */
function kp_random_color(): string {
  $palette = kp_color_palette();
  return $palette[array_rand($palette)];
}

// Inicializar la zona horaria globalmente al cargar helpers
kp_setting('timezone');

/** Genera un UUIDv5 compatible con la RFC 4122. */
function kp_uuidv5(string $namespace, string $name): string {
  $nbytes = str_replace(['-', '{', '}'], '', $namespace);
  $nbytes = pack('H*', $nbytes);
  $hash = sha1($nbytes . $name);
  return sprintf('%08s-%04s-%04x-%04x-%12s',
    substr($hash, 0, 8),
    substr($hash, 8, 4),
    (hexdec(substr($hash, 12, 4)) & 0x0fff) | 0x5000,
    (hexdec(substr($hash, 16, 4)) & 0x3fff) | 0x8000,
    substr($hash, 20, 12)
  );
}

/** Genera el Podcast GUID estándar (UUIDv5) a partir de la URL del feed. */
function kp_deterministic_podcast_guid(string $feed_url): string {
  // Limpiar esquema y slash final
  $clean = preg_replace('#^https?://#', '', $feed_url);
  $clean = rtrim($clean, '/');
  
  // Namespace oficial de Podcasting 2.0 para podcast:guid
  $namespace = 'ead4c236-bf58-58c6-a2c6-a6b28d128cb6';
  
  return kp_uuidv5($namespace, $clean);
}

/** Genera la URL del feed RSS canónico para un podcast dado su slug. */
function kp_canonical_feed_url(string $slug): string {
  $domain = function_exists('kp_setting') ? kp_setting('instance_domain') : '';
  if (!$domain) {
    $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
  }
  $domain = preg_replace('#^https?://#', '', $domain);
  return 'https://' . $domain . '/@' . $slug . '/feed.xml';
}

/**
 * Obtiene la URL de estadísticas públicas del show en OP3.dev.
 * Si no está cacheada, consulta la API de OP3 usando el GUID o la URL del feed.
 * Si no está registrado o la consulta falla, usa el fallback con el GUID.
 */
function kp_op3_show_url(array $p): ?string {
  if (empty($p['op3'])) return null;
  
  $feed_url = kp_canonical_feed_url($p['id']);
  $guid = !empty($p['guid']) ? $p['guid'] : kp_deterministic_podcast_guid($feed_url);
  
  return 'https://op3.dev/show/' . $guid;
}

/**
 * Convierte una fecha de la base de datos (generalmente UTC) a un timestamp UNIX.
 */
function kp_db_strtotime(?string $dateStr): int {
  if (!$dateStr) return 0;
  if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $dateStr)) {
    $dateStr .= ' UTC';
  }
  return (int)strtotime($dateStr);
}

// Helpers para metadatos dinámicos de Podcasts
function kp_get_podcast_meta(array $podcast, string $key, $default = null) {
  $meta = !empty($podcast['meta_json']) ? json_decode($podcast['meta_json'], true) : [];
  return $meta[$key] ?? $default;
}

function kp_update_podcast_meta(int $podcastId, string $key, $value): bool {
  try {
    require_once __DIR__ . '/db.php';
    $p = kp_one("SELECT meta_json FROM podcasts WHERE id = ?", [$podcastId]);
    $meta = ($p && !empty($p['meta_json'])) ? json_decode($p['meta_json'], true) : [];
    $meta[$key] = $value;
    kp_db()->prepare("UPDATE podcasts SET meta_json = ? WHERE id = ?")
           ->execute([json_encode($meta, JSON_UNESCAPED_UNICODE), $podcastId]);
    return true;
  } catch (Throwable $e) {
    return false;
  }
}

// Helpers para metadatos dinámicos de Episodios
function kp_get_episode_meta(array $episode, string $key, $default = null) {
  if ($key === 'premium_enabled' && isset($episode['premium'])) {
    return (int)$episode['premium'];
  }
  $meta = !empty($episode['meta_json']) ? json_decode($episode['meta_json'], true) : [];
  return $meta[$key] ?? $default;
}

function kp_update_episode_meta(int $episodeId, string $key, $value): bool {
  try {
    require_once __DIR__ . '/db.php';
    $e = kp_one("SELECT meta_json FROM episodes WHERE id = ?", [$episodeId]);
    $meta = ($e && !empty($e['meta_json'])) ? json_decode($e['meta_json'], true) : [];
    $meta[$key] = $value;
    kp_db()->prepare("UPDATE episodes SET meta_json = ? WHERE id = ?")
           ->execute([json_encode($meta, JSON_UNESCAPED_UNICODE), $episodeId]);
    return true;
  } catch (Throwable $e) {
    return false;
  }
}

/**
 * Comprueba si un plugin específico está activo.
 */
function kp_plugin_is_active(string $plugin_id): bool {
  static $active_plugins = null;
  if ($active_plugins === null) {
    try {
      $active_plugins = json_decode(kp_setting('active_plugins', '[]'), true);
      if (!is_array($active_plugins)) {
        $active_plugins = [];
      }
      // Auto-migrar legacy brisacms -> kutblog
      if (in_array('brisacms', $active_plugins, true)) {
        $active_plugins = array_values(array_filter($active_plugins, fn($id) => $id !== 'brisacms'));
        if (!in_array('kutblog', $active_plugins, true)) {
          $active_plugins[] = 'kutblog';
        }
        kp_update_setting('active_plugins', json_encode($active_plugins));
      }
    } catch (Throwable $e) {
      $active_plugins = [];
    }
  }
  return in_array($plugin_id, $active_plugins, true) || ($plugin_id === 'kutblog' && in_array('brisacms', $active_plugins, true));
}

// Cargar sistema de ganchos y plugins
require_once __DIR__ . '/hooks.php';

if (file_exists(__DIR__ . '/../storage/kutpod.db')) {
  try {
    $active = json_decode(kp_setting('active_plugins', '[]'), true);
    if (is_array($active)) {
      foreach ($active as $plugin) {
        $file = __DIR__ . "/../plugins/$plugin/$plugin.php";
        if (!file_exists($file)) {
          foreach ([$plugin, strtolower($plugin), ucfirst($plugin), 'KutBlog'] as $pDir) {
            foreach ([$plugin, strtolower($plugin), ucfirst($plugin), 'kutblog'] as $pFile) {
              $candidate = __DIR__ . "/../plugins/$pDir/$pFile.php";
              if (file_exists($candidate)) {
                $file = $candidate;
                break 2;
              }
            }
          }
        }
        if (file_exists($file)) {
          include_once $file;
        }
      }
    }
  } catch (Throwable $e) {}
  
  // Ejecutar inicialización global
  kp_do_action('init');
}



