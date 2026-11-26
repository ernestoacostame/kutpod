<?php
// ============================================================================
// pages/edit-user.php — Editar usuario y gestionar 2FA
// ============================================================================
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$uid = (int)($_GET['id'] ?? 0);
$user = kp_one("SELECT * FROM users WHERE id = ?", [$uid]);

if (!$user) {
  setcookie('kp_flash', 'Usuario no encontrado', time() + 30, '/');
  header('Location: ' . admin_url('users'));
  exit;
}

$current_user = kp_current_user();
$is_self = ($uid === ($current_user['id'] ?? 0));
$is_owner = ($current_user['role'] ?? '') === 'owner';
$is_admin_or_owner = in_array($current_user['role'] ?? '', ['owner', 'admin'], true);

// Access control: only owner, admin, or the user themselves can edit
if (!$is_self && !$is_admin_or_owner) {
  header('Location: ' . admin_url('users'));
  exit;
}

// Admins (non-owner) cannot edit/delete an owner
if ($user['role'] === 'owner' && !$is_self && !$is_owner) {
  setcookie('kp_flash', 'No tienes permisos para editar al propietario.', time() + 30, '/');
  header('Location: ' . admin_url('users'));
  exit;
}

$generated_codes = null;
$generated_codes_user = null;
$show_totp_setup = null;
$show_totp_disable = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  kp_csrf_verify();
  $action = $_POST['_action'] ?? '';

  if ($action === 'edit_user') {
    $email = trim($_POST['email'] ?? '');
    $role = $_POST['role'] ?? '';
    $password = $_POST['password'] ?? '';
    $name = trim($_POST['name'] ?? '');
    $url = trim($_POST['url'] ?? '');
    $bio = trim($_POST['bio'] ?? '');

    if ($email === '') {
      setcookie('kp_flash', 'El correo electrónico es requerido', time() + 30, '/');
      header('Location: ' . admin_url('edit-user?id=' . $uid));
      exit;
    }

    // Check if email already exists
    $exists = kp_one("SELECT id FROM users WHERE email = ? AND id != ?", [$email, $uid]);
    if ($exists) {
      setcookie('kp_flash', 'Error: el correo electrónico ya está registrado por otro usuario.', time() + 30, '/');
      header('Location: ' . admin_url('edit-user?id=' . $uid));
      exit;
    }

    // Avatar upload
    $avatar_path = null;
    if (!empty($_FILES['avatar']['tmp_name'])) {
      $ext = strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));
      if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) $ext = 'jpg';
      $name_file = 'avatar_' . uniqid() . '.' . $ext;
      $dest = __DIR__ . '/../media/' . $name_file;
      if (move_uploaded_file($_FILES['avatar']['tmp_name'], $dest)) {
        $avatar_path = '/media/' . $name_file;
      }
    }

    $updates = ['email = ?'];
    $params = [$email];
    
    if ($name !== '') { $updates[] = 'name = ?'; $params[] = $name; }
    $updates[] = 'url = ?'; $params[] = $url;
    $updates[] = 'bio = ?'; $params[] = $bio;
    
    if ($avatar_path) {
      $updates[] = 'avatar = ?';
      $params[] = $avatar_path;
    }
    
    if ($password !== '') {
      $updates[] = 'password_hash = ?';
      $params[] = password_hash($password, PASSWORD_DEFAULT);
    }

    // Owners can edit any role (except demoting themselves if needed); admins cannot edit owners or assign owner
    if ($is_admin_or_owner && ($is_owner || $user['role'] !== 'owner')) {
      if ($role === 'owner' && !$is_owner) {
        $role = 'admin';
      }
      if (in_array($role, ['owner', 'admin', 'editor', 'host', 'author', 'collaborator'], true)) {
        $updates[] = 'role = ?';
        $params[] = $role;
      }
    }

    $params[] = $uid;
    $sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?";
    kp_db()->prepare($sql)->execute($params);

    setcookie('kp_flash', 'Usuario actualizado correctamente', time() + 30, '/');
    header('Location: ' . admin_url('edit-user?id=' . $uid));
    exit;
  }

  if ($action === 'delete_user') {
    if (!$is_admin_or_owner || $is_self || ($user['role'] === 'owner' && !$is_owner)) {
      setcookie('kp_flash', 'No tienes permisos para eliminar este usuario.', time() + 30, '/');
      header('Location: ' . admin_url('edit-user?id=' . $uid));
      exit;
    }

    kp_db()->prepare("DELETE FROM users WHERE id = ?")->execute([$uid]);
    setcookie('kp_flash', 'Usuario eliminado permanentemente', time() + 30, '/');
    header('Location: ' . admin_url('users'));
    exit;
  }

  if ($action === 'generate_codes') {
    $codes = [];
    $hashes = [];
    for ($i=0; $i<10; $i++) {
      $code = bin2hex(random_bytes(4)); // 8 chars
      $codes[] = $code;
      $hashes[] = password_hash($code, PASSWORD_DEFAULT);
    }
    kp_db()->prepare("UPDATE users SET recovery_codes = ? WHERE id = ?")->execute([json_encode($hashes), $uid]);
    $generated_codes = $codes;
    $generated_codes_user = $user['name'];
    kp_alert('security', 'Códigos de respaldo generados', "Se generaron nuevos códigos para " . $user['name'], '', ['actor_name' => $current_user['name']]);
  }

  if ($action === 'setup_2fa') {
    $secret = kp_totp_generate_secret();
    $issuer = rawurlencode('KutPod');
    $account = rawurlencode($user['email']);
    $otpauth_url = "otpauth://totp/{$issuer}:{$account}?secret={$secret}&issuer={$issuer}";
    $qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=" . urlencode($otpauth_url);
    
    $show_totp_setup = [
      'secret' => $secret,
      'qr_url' => $qr_url
    ];
  }

  if ($action === 'confirm_2fa') {
    $secret = trim($_POST['secret'] ?? '');
    $code = trim($_POST['code'] ?? '');
    
    if (kp_totp_verify($secret, $code)) {
      kp_db()->prepare("UPDATE users SET totp_secret = ? WHERE id = ?")->execute([$secret, $uid]);
      kp_alert('security', '2FA Activado', "Se activó la autenticación en dos pasos para " . $user['name'], '', ['actor_name' => $current_user['name']]);
      
      if (empty($user['recovery_codes'])) {
        $codes = [];
        $hashes = [];
        for ($i=0; $i<10; $i++) {
          $code_resp = bin2hex(random_bytes(4)); // 8 chars
          $codes[] = $code_resp;
          $hashes[] = password_hash($code_resp, PASSWORD_DEFAULT);
        }
        kp_db()->prepare("UPDATE users SET recovery_codes = ? WHERE id = ?")->execute([json_encode($hashes), $uid]);
        $generated_codes = $codes;
        $generated_codes_user = $user['name'];
      }
      setcookie('kp_flash', 'Autenticación en dos pasos (2FA) activada correctamente.', time() + 30, '/');
      header('Location: ' . admin_url('edit-user?id=' . $uid));
      exit;
    } else {
      setcookie('kp_flash', 'Código de verificación incorrecto. No se activó el 2FA.', time() + 30, '/');
      header('Location: ' . admin_url('edit-user?id=' . $uid));
      exit;
    }
  }

  if ($action === 'disable_2fa') {
    $code = trim($_POST['code'] ?? '');
    $allowed = false;
    
    if (!$is_self) {
      $allowed = true; // Admin or owner disabling other user's 2FA
    } else {
      if (empty($user['totp_secret']) || kp_totp_verify($user['totp_secret'], $code)) {
        $allowed = true;
      }
    }
    
    if ($allowed) {
      kp_db()->prepare("UPDATE users SET totp_secret = NULL WHERE id = ?")->execute([$uid]);
      kp_alert('security', '2FA Desactivado', "Se desactivó la autenticación en dos pasos para " . $user['name'], '', ['actor_name' => $current_user['name']]);
      setcookie('kp_flash', '2FA desactivado correctamente.', time() + 30, '/');
      header('Location: ' . admin_url('edit-user?id=' . $uid));
      exit;
    } else {
      $show_totp_disable = true;
    }
  }
}

// Fetch user data again in case it changed
$user = kp_one("SELECT * FROM users WHERE id = ?", [$uid]);

$role_meta = [
  'owner'        => [__('users_role_owner', 'Dueño'),        __('users_role_owner_desc', 'Poder total sobre la instancia, usuarios (incluso otros dueños) y podcasts'), '#ff5f7e'],
  'admin'        => [__('users_role_admin', 'Admin'),        __('users_role_admin_desc', 'Gestiona sitio, usuarios y podcasts (sin tocar dueños)'),                      '#6366f1'],
  'editor'       => [__('users_role_editor', 'Editor'),      __('users_role_editor_desc', 'Edita información de podcasts y episodios asignados'),                        '#10b981'],
  'host'         => [__('users_role_host', 'Host'),          __('users_role_host_desc', 'Presentador · publica y edita episodios propios en podcasts asignados'),        '#f59e0b'],
  'collaborator' => [__('users_role_collab', 'Colaborador'), __('users_role_collab_desc', 'Colabora creando borradores y episodios propios en podcasts asignados'),      '#06b6d4'],
];

$effective_role = ($user['role'] === 'author') ? 'host' : ($user['role'] ?? 'host');
[$rlabel, , $rcolor] = $role_meta[$effective_role] ?? ['Host','','#f59e0b'];
$color = $user['color'] ?? $rcolor;

if (!function_exists('initials_from')) {
  function initials_from($s) {
    $parts = preg_split('/\s+/', trim((string)$s));
    $a = $parts[0] ?? '';
    $b = $parts[1] ?? '';
    return strtoupper(mb_substr($a,0,1) . mb_substr($b,0,1));
  }
}
?>

<div style="margin-bottom:20px; font-size:13.5px; color:var(--text-3); display:flex; align-items:center; gap:6px;">
  <a href="<?= admin_url('users') ?>" style="color:inherit; text-decoration:none; display:flex; align-items:center; gap:4px">
    ← <?= __('users_back_to_users', 'Volver a Usuarios') ?>
  </a>
</div>

<div class="page-head" style="margin-bottom:24px">
  <div>
    <h1 class="page-title"><?= __('users_edit', 'Editar Usuario') ?></h1>
    <p class="page-sub"><?= __('users_edit_sub', 'Modifica el perfil, contraseña, rol y seguridad de dos factores (2FA).') ?></p>
  </div>
</div>

<div class="grid-12" style="gap:24px; align-items:start">
  
  <!-- Left/Main Column: Profile details -->
  <div class="span-8 col" style="gap:24px">
    <form method="POST" enctype="multipart/form-data" class="card" style="padding:24px">
      <div class="card-title" style="margin-bottom:20px"><?= __('users_profile_info', 'Información de Perfil') ?></div>
      <input type="hidden" name="_action" value="edit_user"/>
      <?= kp_csrf_field() ?>
      
      <div class="row" style="gap:20px; align-items:center; margin-bottom:24px">
        <div style="width:64px; height:64px; border-radius:50%; background:linear-gradient(135deg,<?= e($color) ?>,<?= e($color) ?>99); color:white; font-weight:700; font-size:22px; display:grid; place-items:center; overflow:hidden">
          <?php if (!empty($user['avatar'])): ?>
            <img src="<?= e($user['avatar']) ?>" style="width:100%;height:100%;object-fit:cover"/>
          <?php else: ?>
            <?= e(initials_from($user['name'] ?? '?')) ?>
          <?php endif; ?>
        </div>
        <div class="col" style="gap:4px">
          <div style="font-weight:600; font-size:16px"><?= e($user['name']) ?></div>
          <div><span class="tag" style="background:<?= e($rcolor) ?>22; color:<?= e($rcolor) ?>; font-size:11px"><?= e($rlabel) ?></span></div>
        </div>
      </div>

      <div class="field"><label class="label"><?= __('name', 'Nombre') ?></label><input class="input" name="name" value="<?= e($user['name']) ?>" required/></div>
      
      <div class="field" style="margin-top:16px">
        <label class="label"><?= __('users_avatar_optional', 'Avatar (opcional)') ?></label>
        <div style="display:flex; align-items:center; gap:10px">
          <input class="input" type="file" name="avatar" id="edit_user_avatar" accept="image/*" style="flex:1"/>
          <button id="edit_user_avatar_cancel" type="button" class="btn btn-ghost" style="display:none; color:var(--red); padding:4px 8px; font-size:12px"><?= icon('x',12) ?> <?= __('cancel', 'Cancelar') ?></button>
        </div>
      </div>
      
      <div class="field" style="margin-top:16px"><label class="label"><?= __('users_url_optional', 'URL (opcional)') ?></label><input class="input" name="url" value="<?= e($user['url']) ?>" type="url" placeholder="https://..."/></div>
      
      <div class="field" style="margin-top:16px"><label class="label"><?= __('users_bio_optional', 'Biografía / Contexto (opcional)') ?></label><textarea class="input" name="bio" style="resize:vertical; min-height:80px"><?= e($user['bio']) ?></textarea></div>
      
      <div class="field" style="margin-top:16px"><label class="label"><?= __('email', 'Email') ?></label><input class="input" name="email" value="<?= e($user['email']) ?>" type="email" required/></div>
      
      <?php if ($is_admin_or_owner && ($is_owner || $user['role'] !== 'owner')): ?>
        <div class="field" style="margin-top:16px"><label class="label"><?= __('role', 'Rol') ?></label>
          <select class="select" name="role">
            <?php if ($is_owner): ?>
            <option value="owner" <?= $user['role'] === 'owner' ? 'selected' : '' ?>><?= __('users_role_owner', 'Dueño') ?></option>
            <?php endif; ?>
            <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>><?= __('users_role_admin', 'Admin') ?></option>
            <option value="editor" <?= $user['role'] === 'editor' ? 'selected' : '' ?>><?= __('users_role_editor', 'Editor') ?></option>
            <option value="host" <?= ($user['role'] === 'host' || $user['role'] === 'author') ? 'selected' : '' ?>><?= __('users_role_host', 'Host') ?></option>
            <option value="collaborator" <?= $user['role'] === 'collaborator' ? 'selected' : '' ?>><?= __('users_role_collab', 'Colaborador') ?></option>
          </select>
        </div>
      <?php endif; ?>
      
      <div class="field" style="margin-top:16px"><label class="label"><?= __('users_new_password', 'Nueva Contraseña') ?></label><input class="input" name="password" type="password" placeholder="<?= __('users_keep_password_blank', 'Dejar en blanco para mantener actual') ?>"/></div>
      
      <div class="row" style="gap:12px; margin-top:24px; justify-content:flex-end">
        <button type="submit" class="btn btn-primary"><?= __('save_changes', 'Guardar cambios') ?></button>
      </div>
    </form>

    <?php if ($is_admin_or_owner && !$is_self && ($is_owner || $user['role'] !== 'owner')): ?>
      <div class="card" style="padding:24px; border-color:rgba(239, 68, 68, 0.2)">
        <div class="card-title" style="margin-bottom:12px; color:#ef4444"><?= __('danger_zone', 'Zona de Peligro') ?></div>
        <p style="font-size:12.5px; color:var(--text-3); margin-bottom:16px; line-height:1.4"><?= __('users_delete_danger_desc', 'Eliminar de forma permanente este usuario. Se revocará todo su acceso y sesiones activas.') ?></p>
        <form method="POST">
          <input type="hidden" name="_action" value="delete_user"/>
          <?= kp_csrf_field() ?>
          <button type="submit" class="btn" onclick="return confirm('<?= e(__('users_confirm_delete', '¿Estás seguro de que deseas eliminar este usuario de forma permanente? Se revocará todo su acceso.')) ?>')" style="background:#fee2e2; color:#ef4444; border-color:#fca5a5; font-weight:600">
            <?= icon('trash',13) ?> <?= __('users_delete_user', 'Eliminar Usuario') ?>
          </button>
        </form>
      </div>
    <?php endif; ?>
  </div>
  
  <!-- Right Column: 2FA and Backup codes -->
  <div class="span-4 col" style="gap:24px">
    
    <!-- 2FA Setup/Status -->
    <div class="card" style="padding:24px">
      <div class="card-title" style="margin-bottom:12px; display:flex; align-items:center; gap:8px">
        <?= icon('shield', 16) ?>
        <?= __('users_2fa_title', 'Autenticación en dos pasos (2FA)') ?>
      </div>
      
      <div style="font-size:13px; margin-bottom:16px; line-height:1.5">
        <?php if (!empty($user['totp_secret'])): ?>
          <span style="color:#10b981; font-weight:600"><?= __('users_2fa_active', '✓ Activo') ?></span>. <?= __('users_2fa_protected', 'Tu cuenta está protegida con autenticación en dos pasos.') ?><br><br>
          <div style="background:var(--surface-2); padding:10px; border-radius:6px; font-size:11.5px; border:1px solid var(--border); line-height:1.45">
            <strong><?= __('users_kuteditor_question', '¿Usas KutEditor?') ?></strong> <?= __('users_kuteditor_notice', 'Las aplicaciones externas deben usar un <a href="/admin/api" style="color:var(--accent); font-weight:600">Token de Aplicación</a> o Contraseña de Aplicación en lugar de tu contraseña de login, ya que la API no requiere 2FA.') ?>
          </div>
        <?php else: ?>
          <span style="color:var(--text-3)"><?= __('disabled', 'Desactivado') ?></span>. <?= __('users_2fa_layer_desc', 'Añade una capa adicional de seguridad a tu cuenta.') ?><br><br>
          <div style="background:var(--surface-2); padding:10px; border-radius:6px; font-size:11.5px; border:1px solid var(--border); line-height:1.45">
            <strong><?= __('users_api_notice_prefix', 'Aviso de API:') ?></strong> <?= __('users_2fa_api_notice', 'Si tienes habilitado el 2FA, el inicio de sesión web lo requerirá, pero las conexiones de KutEditor deberán usar <a href="/admin/api" style="color:var(--accent); font-weight:600">Tokens de Aplicación</a>.') ?>
          </div>
        <?php endif; ?>
      </div>
      
      <?php if (!empty($user['totp_secret'])): ?>
        <form method="POST">
          <input type="hidden" name="_action" value="disable_2fa"/>
          <?= kp_csrf_field() ?>
          <?php if (!$is_self): ?>
            <!-- Admin disabling other's 2FA: does not need a code -->
            <button class="btn" type="submit" style="color:#ef4444; border-color:#fca5a5; width:100%; justify-content:center" onclick="return confirm('<?= e(__('users_confirm_disable_2fa', '¿Desactivar la autenticación de dos factores para este usuario?')) ?>')">
              <?= icon('x', 13) ?> <?= __('users_2fa_disable', 'Desactivar 2FA') ?>
            </button>
          <?php else: ?>
            <!-- Self disabling: prompt for code -->
            <button class="btn" type="button" onclick="document.getElementById('totp_disable_modal').showModal()" style="color:#ef4444; border-color:#fca5a5; width:100%; justify-content:center">
              <?= icon('x', 13) ?> <?= __('users_2fa_disable', 'Desactivar 2FA') ?>
            </button>
          <?php endif; ?>
        </form>
      <?php else: ?>
        <form method="POST">
          <input type="hidden" name="_action" value="setup_2fa"/>
          <?= kp_csrf_field() ?>
          <button class="btn btn-primary" type="submit" style="width:100%; justify-content:center">
            <?= icon('shield', 13) ?> <?= __('users_2fa_config', 'Configurar 2FA') ?>
          </button>
        </form>
      <?php endif; ?>
    </div>

    <!-- Backup codes -->
    <div class="card" style="padding:24px">
      <div class="card-title" style="margin-bottom:12px; display:flex; align-items:center; gap:8px">
        <?= icon('lock', 16) ?>
        <?= __('users_recovery_codes', 'Códigos de Respaldo') ?>
      </div>
      <p style="font-size:12.5px; color:var(--text-3); margin-bottom:16px; line-height:1.4">
        <?= __('users_recovery_codes_desc', 'Genera 10 códigos de un solo uso para recuperar el acceso a tu cuenta si pierdes tu dispositivo 2FA.') ?>
      </p>
      
      <form method="POST">
        <input type="hidden" name="_action" value="generate_codes"/>
        <?= kp_csrf_field() ?>
        <button class="btn" type="submit" style="width:100%; justify-content:center" onclick="return confirm('<?= e(__('users_recovery_invalidate_confirm', 'Esto invalidará los códigos anteriores. ¿Continuar?')) ?>')">
          <?= icon('refreshCw', 13) ?> <?= __('users_recovery_generate_btn', 'Generar códigos') ?>
        </button>
      </form>
    </div>

  </div>
</div>

<!-- Modal Dialogs -->
<dialog id="totp_setup_modal" class="dlg">
  <form method="POST" class="card card-lg" style="padding:24px; max-width:400px; margin:auto; text-align:center">
    <input type="hidden" name="_action" value="confirm_2fa"/>
    <input type="hidden" name="secret" id="setup_2fa_secret" value=""/>
    <?= kp_csrf_field() ?>
    <div class="card-title" style="margin-bottom:14px; font-size:18px"><?= __('users_2fa_config', 'Configurar 2FA') ?></div>
    <p style="font-size:12.5px; color:var(--text-3); line-height:1.4; margin-bottom:16px"><?= __('users_2fa_scan_qr', 'Escanea este código QR con tu aplicación de autenticación (Google Authenticator, Authy, etc.):') ?></p>
    
    <div style="background:#fff; padding:12px; border-radius:12px; display:inline-block; margin-bottom:16px">
      <img id="totp_qr_img" src="" alt="Código QR" style="display:block; width:180px; height:180px">
    </div>
    
    <div style="margin-bottom:16px">
      <span style="font-size:12px; color:var(--text-3)"><?= __('users_2fa_secret_manual', 'Clave secreta (manual):') ?></span>
      <div id="totp_secret_key" style="font-family:ui-monospace,monospace; font-size:14px; font-weight:600; background:var(--surface-2); padding:6px; border-radius:6px; margin-top:4px; letter-spacing:1px; user-select:all"></div>
    </div>
    
    <div class="field" style="margin-bottom:20px">
      <label class="label"><?= __('users_2fa_verification_code', 'Código de verificación') ?></label>
      <input class="input tabular" type="text" name="code" pattern="[0-9]{6}" inputmode="numeric" placeholder="000000" required style="text-align:center; font-size:18px; letter-spacing:2px">
    </div>
    
    <div class="row" style="gap:8px; justify-content:center">
      <button type="button" class="btn" onclick="this.closest('dialog').close()"><?= __('cancel', 'Cancelar') ?></button>
      <button type="submit" class="btn btn-primary"><?= __('users_2fa_activate_btn', 'Activar 2FA') ?></button>
    </div>
  </form>
</dialog>

<dialog id="totp_disable_modal" class="dlg">
  <form method="POST" class="card card-lg" style="padding:24px; max-width:400px; margin:auto; text-align:center">
    <input type="hidden" name="_action" value="disable_2fa"/>
    <?= kp_csrf_field() ?>
    <div class="card-title" style="margin-bottom:14px; font-size:18px; color:#ef4444"><?= __('users_2fa_disable', 'Desactivar 2FA') ?></div>
    <p style="font-size:12.5px; color:var(--text-3); line-height:1.4; margin-bottom:20px"><?= __('users_2fa_disable_prompt', 'Por seguridad, introduce un código de verificación de 6 dígitos actual para desactivar la autenticación en dos pasos:') ?></p>
    
    <div class="field" style="margin-bottom:20px">
      <label class="label"><?= __('users_2fa_verification_code', 'Código de verificación') ?></label>
      <input class="input tabular" type="text" name="code" pattern="[0-9]{6}" inputmode="numeric" placeholder="000000" required style="text-align:center; font-size:18px; letter-spacing:2px">
    </div>
    
    <div class="row" style="gap:8px; justify-content:center">
      <button type="button" class="btn" onclick="this.closest('dialog').close()"><?= __('cancel', 'Cancelar') ?></button>
      <button type="submit" class="btn" style="background:#fee2e2; color:#ef4444; border-color:#fca5a5"><?= __('users_2fa_disable', 'Desactivar') ?></button>
    </div>
  </form>
</dialog>

<?php if (!empty($generated_codes)): ?>
<dialog open class="dlg">
  <div class="card card-lg" style="padding:32px; max-width:480px; margin:auto; text-align:center">
    <div style="width:56px; height:56px; border-radius:14px; background:rgba(16,185,129,.1); color:#10b981; display:grid; place-items:center; margin:0 auto 16px"><?= icon('shield', 28) ?></div>
    <div class="card-title" style="margin-bottom:12px; font-size:20px"><?= __('users_recovery_codes', 'Códigos de Recuperación') ?></div>
    <p style="font-size:13.5px; color:var(--text-3); line-height:1.5; margin-bottom:24px"><?= __raw('users_recovery_saved_notice', 'Estos son los 10 códigos de respaldo para <strong>:user</strong>. Por seguridad, guárdalos en un lugar seguro (como un gestor de contraseñas). <strong>No volverán a mostrarse.</strong>', ['user' => e($generated_codes_user)]) ?></p>
    
    <div style="background:var(--surface-2); border-radius:12px; padding:20px; display:grid; grid-template-columns:1fr 1fr; gap:12px; font-family:ui-monospace,monospace; font-size:15px; letter-spacing:1px; font-weight:600; margin-bottom:24px; text-align:center; color:var(--text)">
      <?php foreach ($generated_codes as $c): ?>
        <div><?= e($c) ?></div>
      <?php endforeach; ?>
    </div>
    
    <button class="btn btn-primary" onclick="this.closest('dialog').removeAttribute('open')" style="width:100%; justify-content:center"><?= __('users_recovery_understood', 'Entendido, los he guardado') ?></button>
  </div>
</dialog>
<?php endif; ?>

<style>
.dlg { background: transparent; border: 0; padding: 0; }
.dlg::backdrop { background: rgba(0,0,0,0.55); backdrop-filter: blur(2px); }
</style>

<script>
const editAvatarInput = document.getElementById('edit_user_avatar');
const editAvatarCancel = document.getElementById('edit_user_avatar_cancel');
editAvatarInput?.addEventListener('change', function() {
  if (this.files[0]) {
    if (editAvatarCancel) editAvatarCancel.style.display = 'inline-flex';
  } else {
    if (editAvatarCancel) editAvatarCancel.style.display = 'none';
  }
});
editAvatarCancel?.addEventListener('click', function() {
  if (editAvatarInput) editAvatarInput.value = '';
  this.style.display = 'none';
});
</script>

<?php if (!empty($show_totp_setup)): ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
  document.getElementById('setup_2fa_secret').value = "<?= $show_totp_setup['secret'] ?>";
  document.getElementById('totp_qr_img').src = "<?= $show_totp_setup['qr_url'] ?>";
  document.getElementById('totp_secret_key').textContent = "<?= $show_totp_setup['secret'] ?>";
  document.getElementById('totp_setup_modal').showModal();
});
</script>
<?php endif; ?>

<?php if ($show_totp_disable): ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
  document.getElementById('totp_disable_modal').showModal();
});
</script>
<?php endif; ?>
