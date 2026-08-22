<?php
// pages/new-podcast.php · edit-podcast.php — formulario completo (8 secciones)
require_once __DIR__ . '/../includes/helpers.php';

$mode = $page === 'edit-podcast' ? 'edit' : 'create';
$id = $_GET['id'] ?? '';
$p = null;
$assigned_users = [];
if ($mode === 'edit' && $id !== '') {
    $p = kp_one("SELECT * FROM podcasts WHERE slug = ?", [$id]);
    if ($p) {
        $p['db_id'] = $p['id'];
        $p['id'] = $p['slug'];
        $p['initial'] = strtoupper(substr($p['title'] ?: '?', 0, 2));
        $assigned_rows = kp_q("SELECT user_id FROM podcast_users WHERE podcast_id = ?", [$p['db_id']]) ?: [];
        $assigned_users = array_column($assigned_rows, 'user_id');
    }
}

$team_users = kp_q("SELECT id, name, email, avatar, role FROM users WHERE role IN ('editor','author') ORDER BY name ASC") ?: [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $pdo = kp_db();
  
  // Detect if post_max_size was exceeded (PHP empties $_POST and $_FILES in this case)
  if (empty($_POST) && $_SERVER['CONTENT_LENGTH'] > 0) {
      setcookie('kp_flash', 'Error: El archivo subido es demasiado grande. Por favor, sube imágenes más ligeras.', time() + 30, '/');
      header('Location: ' . $_SERVER['REQUEST_URI']); exit;
  }

  if (isset($_POST['action']) && $_POST['action'] === 'delete' && $p) {
      $pdo->prepare("DELETE FROM podcasts WHERE slug = ?")->execute([$p['id']]);
      setcookie('kp_flash', 'Podcast eliminado', time() + 30, '/');
      header('Location: /admin/podcasts'); exit;
  }

  // Limpiar notas fijas de todos los episodios de este podcast
  if (isset($_POST['action']) && $_POST['action'] === 'strip_fixed_notes' && $p) {
      $fixed = str_replace("\r\n", "\n", trim($p['fixed_notes'] ?? ''));
      if ($fixed) {
          $lines = array_filter(array_map('trim', explode("\n", $fixed)));
          $first_line = reset($lines);
          
          if ($first_line && mb_strlen($first_line) >= 4) {
              // Normalize arrow representations to ensure matching
              $first_line_norm = str_replace(['->', '=>'], ['→', '⇒'], $first_line);
              
              $eps = kp_q("SELECT e.id, e.notes_md FROM episodes e WHERE e.podcast_id = ?", [$p['db_id']]);
              $count = 0;
              foreach ($eps as $ep) {
                  $notes = $ep['notes_md'] ?? '';
                  $notes = str_replace("\r\n", "\n", $notes);
                  $notes_norm = str_replace(['->', '=>'], ['→', '⇒'], $notes);
                  
                  $pos = stripos($notes_norm, $first_line_norm);
                  if ($pos !== false) {
                      $before = rtrim(substr($notes, 0, $pos));
                      
                      // Look for the last '---' separator in $before
                      $last_sep = strrpos($before, '---');
                      if ($last_sep !== false) {
                          $distance = strlen($before) - $last_sep;
                          if ($distance < 150) {
                              $before = rtrim(substr($before, 0, $last_sep));
                          }
                      } elseif (str_ends_with($before, '---')) {
                          $before = rtrim(substr($before, 0, -3));
                      }
                      
                      $cleaned = $before;
                      
                      $pdo->prepare("UPDATE episodes SET notes_md = ? WHERE id = ?")->execute([trim($cleaned), $ep['id']]);
                      $count++;
                  }
              }
              setcookie('kp_flash', "Notas fijas/footer eliminados de $count episodios", time() + 30, '/');
          } else {
              setcookie('kp_flash', 'La primera línea de las notas fijas debe tener al menos 4 caracteres para realizar la limpieza.', time() + 30, '/');
          }
      } else {
          setcookie('kp_flash', 'No hay notas fijas configuradas para limpiar', time() + 30, '/');
      }
      header('Location: ' . $_SERVER['REQUEST_URI']); exit;
  }

  $title = $_POST['title'] ?? 'Sin título';
  $slug = !empty($_POST['slug']) ? strtolower($_POST['slug']) : kp_slugify($title);
  $description = $_POST['description'] ?? '';
  $language = $_POST['language'] ?? 'es';
  $category = $_POST['category'] ?? '';
  $subcategory = $_POST['subcategory'] ?? '';
  $author = $_POST['author'] ?? '';
  $publisher = $_POST['publisher'] ?? '';
  $owner_email = $_POST['owner_email'] ?? '';
  $copyright = $_POST['copyright'] ?? '';
  $type = $_POST['type'] ?? 'episodic';
  $parental = $_POST['parental'] ?? 'clean';
  $fediverse_handle = !empty($_POST['fediverse_handle']) ? strtolower($_POST['fediverse_handle']) : $slug;
  $feed_redirect_slug = !empty($_POST['feed_redirect_slug']) ? strtolower(preg_replace('/[^a-zA-Z0-9\-]/', '', $_POST['feed_redirect_slug'])) : null;


  $cover_url = $p['cover'] ?? '';
  if (!empty($_FILES['cover']) && $_FILES['cover']['error'] !== UPLOAD_ERR_NO_FILE) {
      if ($_FILES['cover']['error'] !== UPLOAD_ERR_OK) {
          setcookie('kp_flash', 'Error al subir la portada (Código: ' . $_FILES['cover']['error'] . ')', time() + 30, '/');
          header('Location: ' . $_SERVER['REQUEST_URI']); exit;
      }
      $ext = strtolower(pathinfo($_FILES['cover']['name'], PATHINFO_EXTENSION) ?: 'jpg');
      if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) $ext = 'jpg';
      $dest = __DIR__ . '/../media/covers/podcast_' . $slug . '.' . $ext;
      @mkdir(dirname($dest), 0775, true);
      if (move_uploaded_file($_FILES['cover']['tmp_name'], $dest)) {
          $cover_url = '/media/covers/podcast_' . $slug . '.' . $ext;
      } else {
          setcookie('kp_flash', 'Error de permisos: No se pudo guardar la imagen de portada en el servidor.', time() + 30, '/');
          header('Location: ' . $_SERVER['REQUEST_URI']); exit;
      }
  }

  $banner_url = $p['banner'] ?? '';
  if (!empty($_FILES['banner']) && $_FILES['banner']['error'] !== UPLOAD_ERR_NO_FILE) {
      if ($_FILES['banner']['error'] !== UPLOAD_ERR_OK) {
          setcookie('kp_flash', 'Error al subir el banner (Código: ' . $_FILES['banner']['error'] . ')', time() + 30, '/');
          header('Location: ' . $_SERVER['REQUEST_URI']); exit;
      }
      $ext = strtolower(pathinfo($_FILES['banner']['name'], PATHINFO_EXTENSION) ?: 'jpg');
      if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) $ext = 'jpg';
      $dest = __DIR__ . '/../media/covers/podcast_banner_' . $slug . '.' . $ext;
      @mkdir(dirname($dest), 0775, true);
      if (move_uploaded_file($_FILES['banner']['tmp_name'], $dest)) {
          $banner_url = '/media/covers/podcast_banner_' . $slug . '.' . $ext;
      } else {
          setcookie('kp_flash', 'Error de permisos: No se pudo guardar el banner en el servidor.', time() + 30, '/');
          header('Location: ' . $_SERVER['REQUEST_URI']); exit;
      }
  }

  $op3 = isset($_POST['op3']) ? 1 : 0;
  $locked = isset($_POST['locked']) ? 1 : 0;
  $hidden = isset($_POST['hidden']) ? 1 : 0;
  $complete = isset($_POST['complete']) ? 1 : 0;
  $remove_email = isset($_POST['remove_email']) ? 1 : 0;
  $federate = isset($_POST['federate']) ? 1 : 0;
  $custom_tags = $_POST['custom_tags'] ?? '';
  $ownership_txt = $_POST['ownership_txt'] ?? '';
  $fixed_notes = $_POST['fixed_notes'] ?? '';
  $status = $_POST['status'] ?? 'published';

  $guid = trim($_POST['guid'] ?? '');
  if ($guid === '') {
      $feed_url = kp_canonical_feed_url($slug);
      $guid = kp_deterministic_podcast_guid($feed_url);
  }

  $color_post = trim($_POST['color'] ?? '');
  if ($color_post === '') $color_post = $p['color'] ?? kp_random_color();
  $color = $color_post;

  if ($p) {
      $pdo->prepare("UPDATE podcasts SET title=?, slug=?, description=?, language=?, category=?, subcategory=?, author=?, publisher=?, owner_email=?, copyright=?, type=?, parental=?, fediverse_handle=?, federate=?, cover=?, banner=?, color=?, op3=?, locked=?, hidden=?, complete=?, remove_email=?, custom_tags=?, ownership_txt=?, fixed_notes=?, status=?, guid=?, feed_redirect_slug=? WHERE slug=?")
          ->execute([$title, $slug, $description, $language, $category, $subcategory, $author, $publisher, $owner_email, $copyright, $type, $parental, $fediverse_handle, $federate, $cover_url, $banner_url, $color, $op3, $locked, $hidden, $complete, $remove_email, $custom_tags, $ownership_txt, $fixed_notes, $status, $guid, $feed_redirect_slug, $p['id']]);
      $db_id = $p['db_id'];
  } else {
      $pdo->prepare("INSERT INTO podcasts (title, slug, description, language, category, subcategory, author, publisher, owner_email, copyright, type, parental, fediverse_handle, federate, cover, banner, color, op3, locked, hidden, complete, remove_email, custom_tags, ownership_txt, fixed_notes, status, guid, feed_redirect_slug) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
          ->execute([$title, $slug, $description, $language, $category, $subcategory, $author, $publisher, $owner_email, $copyright, $type, $parental, $fediverse_handle, $federate, $cover_url, $banner_url, $color, $op3, $locked, $hidden, $complete, $remove_email, $custom_tags, $ownership_txt, $fixed_notes, $status, $guid, $feed_redirect_slug]);
      $db_id = (int)$pdo->lastInsertId();
  }

  // Lanzar gancho para que los plugins procesen el guardado del podcast
  kp_do_action('podcast_saved', $db_id, $_POST);

  $pdo->prepare("DELETE FROM podcast_users WHERE podcast_id = ?")->execute([$db_id]);
  if (!empty($_POST['team_users']) && is_array($_POST['team_users'])) {
      $stmt = $pdo->prepare("INSERT INTO podcast_users (podcast_id, user_id) VALUES (?, ?)");
      foreach ($_POST['team_users'] as $uid) {
          $stmt->execute([$db_id, (int)$uid]);
      }
  }


  require_once __DIR__ . '/../includes/cache.php';
  kp_cache_clear();

  setcookie('kp_flash', 'Podcast guardado exitosamente', time() + 30, '/');
  header('Location: /admin/podcasts'); exit;
}

$sections = [
  's1-1' => 'Identidad',
  's1-2' => 'Clasificación',
  's1-3' => 'Autor',
  's1-9' => 'Equipo',
  // 's1-5' => 'Premium', // TODO: backend logic needed
  's1-6' => 'Estadísticas · OP3',
  's1-7' => 'Ubicación',
  's1-8' => 'Avanzados',
];
$sections = kp_apply_filters('podcast_form_sections', $sections);
?>
<form method="POST" enctype="multipart/form-data" class="form-podcast">
  <?= kp_csrf_field() ?>
  <div class="page-head">
    <div>
      <h1 class="page-title"><?= $mode === 'create' ? 'Nuevo podcast' : 'Editar podcast' ?></h1>
      <p class="page-sub"><?= $mode === 'create' ? 'Configura tu nuevo show en 8 secciones.' : 'Edita la configuración del podcast.' ?></p>
    </div>
  </div>

  <div class="grid-12" style="gap:24px">
    <div class="span-9 col" style="gap:24px">

      <!-- 1.1 Identidad -->
      <section id="s1-1" class="card card-lg">
        <header class="section-head"><h2>Identidad del Podcast</h2></header>
        <div class="row" style="gap:24px;align-items:flex-start;flex-wrap:wrap">
          <div class="cover-upload-container" style="flex:0 0 200px">
            <label for="cover" class="cover-upload" style="display:block;aspect-ratio:1;border-radius:12px;background:var(--surface-2);border:1px dashed var(--border);display:grid;place-items:center;color:var(--text-3);cursor:pointer;background-size:cover;background-position:center;<?= !empty($p['cover']) ? 'background-image:url('.e($p['cover']).');' : '' ?>">
              <?= empty($p['cover']) ? icon('image',28) : '' ?>
            </label>
            <input id="cover" name="cover" type="file" accept="image/png,image/jpeg,image/gif,image/webp" hidden>
            <div class="help" style="margin-top:8px;text-align:center">PNG/JPG · mín. 1400×1400</div>
            <button id="cover-cancel" type="button" class="btn btn-ghost" style="margin-top:8px;font-size:12px;display:none;color:var(--red);width:100%;justify-content:center;"><?= icon('x',12) ?> Cancelar selección</button>
          </div>
          <div style="flex:1" class="col">
            <div class="field"><label class="label">Título <span class="req">*</span></label><input class="input" name="title" id="title" required value="<?= e($p['title'] ?? '') ?>" placeholder="Mi nuevo podcast" oninput="updateSlugs(this.value)"></div>
            <div class="field" style="margin-top:14px">
              <label class="label">Slug · URL del feed</label>
              <div class="input-affix"><span><?= e(kp_setting('instance_domain', $_SERVER['HTTP_HOST'] ?? '')) ?>/@</span><input class="input" name="slug" id="slug" value="<?= e($p['id'] ?? '') ?>" pattern="[a-zA-Z0-9\-]+" style="font-family:ui-monospace,monospace" oninput="this.dataset.manual=1"></div>
              <div class="help">Solo minúsculas, números y guiones.</div>
            </div>
            <div class="field" style="margin-top:14px">
              <label class="label">Color del Podcast</label>
              <style>
              .color-swatch input[type="radio"]:checked + svg { display: block !important; }
              </style>
              <div class="row" style="flex-wrap:wrap;gap:8px;margin-top:6px">
                <?php foreach (kp_color_palette() as $cp): ?>
                  <label class="color-swatch" style="cursor:pointer;position:relative;width:24px;height:24px;border-radius:50%;background:<?= $cp ?>;box-shadow:inset 0 0 0 1px rgba(0,0,0,0.1)">
                    <input type="radio" name="color" value="<?= $cp ?>" <?= ($p['color'] ?? '') === $cp ? 'checked' : '' ?> style="opacity:0;position:absolute;width:100%;height:100%;margin:0;cursor:pointer">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="position:absolute;top:5px;left:5px;display:none"><path d="M5 12l4 4 10-10"/></svg>
                  </label>
                <?php endforeach; ?>
              </div>
              <div class="help" style="margin-top:8px">Selecciona el color de acento principal. Si no eliges, se asignará uno aleatorio.</div>
            </div>
            <div class="field" style="margin-top:14px"><label class="label">Descripción <span class="req">*</span></label><textarea class="textarea" name="description" required rows="4" placeholder="Una conversación semanal sobre…"><?= e($p['description'] ?? '') ?></textarea></div>
            <div class="field" style="margin-top:14px"><label class="label">Notas fijas</label><textarea class="textarea" name="fixed_notes" rows="4" placeholder="Texto que aparecerá siempre al final de cada episodio. Ej: enlaces de contacto, redes sociales, créditos…" style="font-size:13.5px"><?= e($p['fixed_notes'] ?? '') ?></textarea><div class="help" style="margin-top:4px">Este texto se añadirá automáticamente debajo de las notas de cada episodio. Soporta Markdown.</div>
            <?php if ($mode === 'edit' && !empty($p['fixed_notes'])): ?>
            <button type="submit" name="action" value="strip_fixed_notes" formnovalidate class="btn" style="margin-top:8px;font-size:12px" onclick="return confirm('Esto eliminará el texto de las notas fijas de las notas individuales de todos los episodios de este podcast. ¿Continuar?')"><?= icon('trash',12) ?> Limpiar de episodios existentes</button>
            <div class="help" style="margin-top:4px">Si importaste episodios que ya incluyen este texto en sus notas, usa este botón para eliminarlo y evitar duplicados.</div>
            <?php endif; ?>
            </div>
          </div>
        </div>
      </section>

      <!-- 1.2 Clasificación -->
      <section id="s1-2" class="card card-lg">
        <header class="section-head"><h2>Clasificación</h2></header>
        <div class="field-row">
          <div class="field"><label class="label">Idioma <span class="req">*</span></label>
            <select class="select" name="language" required>
              <option value="es" <?= ($p['language']??'es')==='es'?'selected':'' ?>>Español (es)</option>
              <option value="en" <?= ($p['language']??'')==='en'?'selected':'' ?>>Inglés (en)</option>
              <option value="pt" <?= ($p['language']??'')==='pt'?'selected':'' ?>>Portugués (pt)</option>
              <option value="fr" <?= ($p['language']??'')==='fr'?'selected':'' ?>>Francés (fr)</option>
              <option value="de" <?= ($p['language']??'')==='de'?'selected':'' ?>>Alemán (de)</option>
            </select>
          </div>
          <div class="field"><label class="label">Categoría principal <span class="req">*</span></label>
            <select class="select" name="category" required>
              <?php foreach (['Society & Culture','News','Technology','Politics','Business','Comedy','Education','Personal Journals','True Crime'] as $c): ?>
                <option <?= ($p['category'] ?? '') === $c ? 'selected' : '' ?>><?= e($c) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field"><label class="label">Subcategoría</label><input class="input" name="subcategory" value="<?= e($p['subcategory'] ?? '') ?>" placeholder="Opcional"></div>
        </div>
        <div class="field-row" style="margin-top:14px">
          <div class="field"><label class="label">Tipo de podcast</label>
            <select class="select" name="type"><option value="episodic" <?= ($p['type']??'episodic')==='episodic'?'selected':'' ?>>Episódico</option><option value="serial" <?= ($p['type']??'')==='serial'?'selected':'' ?>>Serial</option></select>
          </div>
          <div class="field"><label class="label">Parental advisory</label>
            <select class="select" name="parental"><option value="clean" <?= ($p['parental']??'clean')==='clean'?'selected':'' ?>>Apto para todos</option><option value="explicit" <?= ($p['parental']??'')==='explicit'?'selected':'' ?>>Explícito</option></select>
          </div>
        </div>
      </section>

      <!-- 1.3 Autor -->
      <section id="s1-3" class="card card-lg">
        <header class="section-head"><h2>Autor &amp; propiedad</h2></header>
        <div class="field-row">
          <div class="field"><label class="label">Nombre del autor</label><input class="input" name="author" value="<?= e($p['author'] ?? kp_setting('owner_name', '')) ?>"></div>
          <div class="field"><label class="label">Publisher / editorial</label><input class="input" name="publisher" value="<?= e($p['publisher'] ?? '') ?>" placeholder="Independiente"></div>
        </div>
        <div class="field-row" style="margin-top:14px">
          <div class="field"><label class="label">Email del owner (iTunes)</label><input class="input" type="email" name="owner_email" value="<?= e($p['owner_email'] ?? '') ?>" placeholder="owner@ejemplo.com"></div>
          <div class="field"><label class="label">Copyright</label><input class="input" name="copyright" value="<?= e($p['copyright'] ?? '© ' . date('Y') . ' ' . ($p['author'] ?? kp_setting('owner_name', ''))) ?>"></div>
        </div>
        <label class="toggle-row" style="margin-top:14px"><span><strong>Ocultar email</strong><br><span class="help">No incluir tu dirección de email en el feed RSS público</span></span><label class="toggle"><input type="checkbox" name="remove_email" <?= ($p['remove_email']??0)?'checked':'' ?>><span class="toggle-track"></span></label></label>
      </section>

      <?php kp_do_action('podcast_form_sections_render', $p); ?>



      <!-- 1.9 Equipo -->
      <section id="s1-9" class="card card-lg">
        <header class="section-head"><h2>Equipo y Permisos</h2></header>
        <?php if (empty($team_users)): ?>
          <div class="help" style="margin-top:14px">No hay usuarios con el rol de Editor o Autor en la plataforma.</div>
        <?php else: ?>
          <div class="help" style="margin-top:14px;margin-bottom:18px">Selecciona los usuarios que tendrán acceso para editar episodios en este podcast. Los Administradores y Owners tienen acceso global automático.</div>
          <div class="col" style="gap:12px">
            <?php foreach ($team_users as $u): 
              $is_checked = in_array($u['id'], $assigned_users);
            ?>
              <label class="row" style="gap:14px;padding:12px;border:1px solid var(--border);border-radius:10px;align-items:center;cursor:pointer">
                <input type="checkbox" name="team_users[]" value="<?= $u['id'] ?>" <?= $is_checked ? 'checked' : '' ?>>
                <div style="width:32px;height:32px;border-radius:50%;background:var(--surface-3);color:var(--text-3);display:grid;place-items:center;font-weight:700;font-size:12px;overflow:hidden">
                  <?php if (!empty($u['avatar'])): ?>
                    <img src="<?= e($u['avatar']) ?>" style="width:100%;height:100%;object-fit:cover">
                  <?php else: ?>
                    <?= e(strtoupper(substr($u['name'], 0, 2))) ?>
                  <?php endif; ?>
                </div>
                <div style="flex:1;min-width:0">
                  <div style="font-weight:600;font-size:14px"><?= e($u['name']) ?></div>
                  <div style="font-size:12px;color:var(--text-3);overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($u['email']) ?></div>
                </div>
                <div><span class="tag" style="background:var(--surface-3);font-size:11px"><?= e(ucfirst($u['role'])) ?></span></div>
              </label>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>

      <!-- 1.6 OP3 -->
      <section id="s1-6" class="card card-lg">
        <header class="section-head"><h2>Estadísticas · OP3</h2></header>
        <label class="toggle-row"><span><strong>Auditoría con OP3</strong><br><span class="help">Antepone el prefijo <code>op3.dev/e/</code> a las URLs de audio del feed para auditar descargas.</span></span><label class="toggle"><input type="checkbox" name="op3" <?= (is_array($p) ? ($p['op3'] ?? 1) : 1) ? 'checked' : '' ?>><span class="toggle-track"></span></label></label>
        <div class="field" style="margin-top:14px">
          <label class="label">Prefijo OP3</label>
          <?php
            $p_slug = is_array($p) ? ($p['id'] ?? 'slug') : 'slug';
            $p_guid = is_array($p) ? ($p['guid'] ?? '') : '';
            $display_base = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
            $display_tracker = $display_base . '/r/' . rawurlencode($p_slug) . '/';
            $display_guid = !empty($p_guid) ? $p_guid : kp_deterministic_podcast_guid(kp_canonical_feed_url($p_slug));
            $display_prefix = 'https://op3.dev/e,pg=' . $display_guid . '/' . preg_replace('#^https?://#', '', $display_tracker);
          ?>
          <input class="input" name="op3_prefix" value="<?= e($display_prefix) ?>" readonly style="font-family:ui-monospace,monospace">
        </div>
        <div class="field" style="margin-top:14px">
          <label class="label">Podcast GUID</label>
          <input class="input" name="guid" value="<?= e(is_array($p) ? ($p['guid'] ?? '') : '') ?>" placeholder="Ej: edc1dcff-dda4-5036-abdc-12dd1f71db7e" style="font-family:ui-monospace,monospace">
          <div class="help">Identificador único y permanente del podcast (podcast:guid). Si lo dejas vacío, se generará uno automáticamente.</div>
        </div>
      </section>

      <!-- 1.7 Ubicación -->
      <section id="s1-7" class="card card-lg">
        <header class="section-head"><h2>Ubicación</h2></header>
        <div class="field-row">
          <div class="field"><label class="label">Nombre del lugar</label><input class="input" name="location_name" placeholder="Caracas, Venezuela"></div>
          <div class="field"><label class="label">OpenStreetMap ID</label><input class="input tabular" name="osm_id" placeholder="R3489866"></div>
        </div>
        <div class="field-row" style="margin-top:14px">
          <div class="field"><label class="label">Latitud</label><input class="input tabular" type="number" step="any" name="lat" placeholder="10.4806"></div>
          <div class="field"><label class="label">Longitud</label><input class="input tabular" type="number" step="any" name="lon" placeholder="-66.9036"></div>
        </div>
      </section>

      <!-- 1.8 Avanzados -->
      <section id="s1-8" class="card card-lg">
        <header class="section-head"><h2>Parámetros avanzados</h2></header>

        <div class="field" style="margin-top:14px"><label class="label">Custom tags (RSS XML)</label><textarea class="textarea" name="custom_tags" rows="4" placeholder="&lt;itunes:keywords&gt;...&lt;/itunes:keywords&gt;" style="font-family:ui-monospace,monospace;font-size:12.5px"><?= e($p['custom_tags'] ?? '') ?></textarea></div>
        <div class="field" style="margin-top:14px"><label class="label">podcast:txt (ownership)</label><input class="input" name="ownership_txt" value="<?= e($p['ownership_txt'] ?? '') ?>" placeholder="<?= bin2hex(random_bytes(8)) ?>" style="font-family:ui-monospace,monospace"></div>
        <div class="field" style="margin-top:14px">
          <label class="label">Redirección de feed (Slug antiguo)</label>
          <div class="input-affix"><span><?= e(kp_setting('instance_domain', $_SERVER['HTTP_HOST'] ?? '')) ?>/feed/</span><input class="input" name="feed_redirect_slug" id="feed_redirect_slug" value="<?= e($p['feed_redirect_slug'] ?? '') ?>" pattern="[a-zA-Z0-9\-]*" style="font-family:ui-monospace,monospace"></div>
          <div class="help">Si se configura, cualquier petición a <code>/feed/slug_antiguo</code> o <code>/feed/slug_antiguo.xml</code> será redireccionada permanentemente a este podcast.</div>
        </div>
        <?php kp_do_action('podcast_form_advanced', $p); ?>
        <div class="col" style="gap:10px;margin-top:14px">
          <label class="toggle-row"><span><strong>Locked</strong><br><span class="help">Prohibir importar este feed en otros hosts</span></span><label class="toggle"><input type="checkbox" name="locked" <?= ($p['locked']??0)?'checked':'' ?>><span class="toggle-track"></span></label></label>
          <label class="toggle-row"><span><strong>Hidden</strong><br><span class="help">No listar en directorios públicos</span></span><label class="toggle"><input type="checkbox" name="hidden" <?= ($p['hidden']??0)?'checked':'' ?>><span class="toggle-track"></span></label></label>
          <label class="toggle-row"><span><strong>Complete</strong><br><span class="help">Marcar feed como finalizado</span></span><label class="toggle"><input type="checkbox" name="complete" <?= ($p['complete']??0)?'checked':'' ?>><span class="toggle-track"></span></label></label>
        </div>
      </section>

    </div>
    
    <aside class="span-3 form-toc" style="position:sticky; top:90px; align-self:start">
      <?php foreach ($sections as $anchor => $label): ?>
        <a href="#<?= $anchor ?>" class="form-toc-item"><?= e($label) ?></a>
      <?php endforeach; ?>
      
      <div style="margin-top:32px;display:flex;flex-direction:column;gap:10px">
        <button class="btn btn-primary" type="submit" name="status" value="published" style="width:100%;justify-content:center;padding:10px"><?= icon('check',13) ?> Guardar</button>
        <button class="btn" type="submit" name="status" value="draft" formnovalidate style="width:100%;justify-content:center"><?= icon('file',13) ?> Guardar borrador</button>
        <a class="btn" href="/admin/podcasts" style="width:100%;justify-content:center">Cancelar</a>
        <?php if ($mode === 'edit'): ?>
          <button class="btn" style="color:var(--red);border-color:var(--red-10);width:100%;justify-content:center" type="submit" name="action" value="delete" formnovalidate onclick="return confirm('¿Estás seguro de que quieres eliminar este podcast y TODOS sus episodios?')"><?= icon('x',13) ?> Eliminar</button>
        <?php endif; ?>
      </div>
    </aside>

  </div>
</form>

<script>
function slugify(s) {
  return (s||'').toString().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'')
    .replace(/[^a-z0-9]/g,'');
}
function updateSlugs(val) {
  const s = slugify(val);
  const slugInput = document.getElementById('slug');
  const fediInput = document.getElementById('fediverse_handle');
  if (slugInput && !slugInput.dataset.manual && !slugInput.defaultValue) slugInput.value = s;
  if (fediInput && !fediInput.dataset.manual && !fediInput.defaultValue) fediInput.value = s;
}

const coverInput = document.getElementById('cover');
const coverLabel = document.querySelector('label[for="cover"]');
const coverCancel = document.getElementById('cover-cancel');
let initialCoverBgImage = '';
let initialCoverBg = '';
let initialCoverBorder = '';
let initialCoverHTML = '';
if (coverLabel) {
  initialCoverBgImage = coverLabel.style.backgroundImage;
  initialCoverBg = coverLabel.style.background;
  initialCoverBorder = coverLabel.style.border;
  initialCoverHTML = coverLabel.innerHTML;
}

coverInput?.addEventListener('change', function() {
  if(this.files[0]){
    const r=new FileReader();
    r.onload=e=>{
      coverLabel.style.backgroundImage='url('+e.target.result+')';
      coverLabel.style.backgroundSize='cover';
      coverLabel.innerHTML='';
      if(coverCancel) coverCancel.style.display = 'inline-flex';
    };
    r.readAsDataURL(this.files[0]);
  } else {
    resetCover();
  }
});

function resetCover() {
  if (coverInput) coverInput.value = '';
  if (coverLabel) {
    coverLabel.style.background = initialCoverBg;
    coverLabel.style.backgroundImage = initialCoverBgImage;
    coverLabel.style.border = initialCoverBorder;
    coverLabel.innerHTML = initialCoverHTML;
  }
  if (coverCancel) coverCancel.style.display = 'none';
}
coverCancel?.addEventListener('click', resetCover);

const bannerInput = document.getElementById('banner');
const bannerLabel = document.querySelector('label[for="banner"]');
const bannerCancel = document.getElementById('banner-cancel');
let initialBannerBgImage = '';
let initialBannerBg = '';
let initialBannerBorder = '';
let initialBannerHTML = '';
if (bannerLabel) {
  initialBannerBgImage = bannerLabel.style.backgroundImage;
  initialBannerBg = bannerLabel.style.background;
  initialBannerBorder = bannerLabel.style.border;
  initialBannerHTML = bannerLabel.innerHTML;
}

bannerInput?.addEventListener('change', function() {
  if(this.files[0]){
    const r=new FileReader();
    r.onload=e=>{
      bannerLabel.style.backgroundImage='url('+e.target.result+')';
      bannerLabel.style.backgroundSize='cover';
      bannerLabel.style.border='none';
      bannerLabel.innerHTML='';
      if(bannerCancel) bannerCancel.style.display = 'inline-flex';
    };
    r.readAsDataURL(this.files[0]);
  } else {
    resetBanner();
  }
});

function resetBanner() {
  if (bannerInput) bannerInput.value = '';
  if (bannerLabel) {
    bannerLabel.style.background = initialBannerBg;
    bannerLabel.style.backgroundImage = initialBannerBgImage;
    bannerLabel.style.border = initialBannerBorder;
    bannerLabel.innerHTML = initialBannerHTML;
  }
  if (bannerCancel) bannerCancel.style.display = 'none';
}
bannerCancel?.addEventListener('click', resetBanner);
</script>
