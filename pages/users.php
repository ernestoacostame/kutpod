<?php
// ============================================================================
// pages/users.php — Usuarios, roles, invitaciones
// ============================================================================
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  kp_csrf_verify();
  $action = $_POST['_action'] ?? '';

  if ($action === 'create_user') {
    if (!in_array($current_user['role'] ?? '', ['owner', 'admin'], true)) {
      header('Location: /admin'); exit;
    }
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password_plain = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'host';
    if (!in_array($role, ['owner', 'admin', 'editor', 'host', 'collaborator'], true)) {
      $role = 'host';
    }
    if ($role === 'owner' && ($current_user['role'] ?? '') !== 'owner') {
      $role = 'admin';
    }
    $url = trim($_POST['url'] ?? '');
    $bio = trim($_POST['bio'] ?? '');
    
    // Fallback: si no se provee password, se genera una aleatoria
    if (empty($password_plain)) $password_plain = bin2hex(random_bytes(4));
    $password = password_hash($password_plain, PASSWORD_DEFAULT);
    
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
    
    if ($name !== '' && $email !== '') {
      try {
        kp_db()->prepare("INSERT INTO users (email, name, password_hash, role, avatar, url, bio, is_guest) VALUES (?, ?, ?, ?, ?, ?, ?, 0)")
               ->execute([$email, $name, $password, $role, $avatar_path, $url, $bio]);
        setcookie('kp_flash', 'Usuario creado correctamente', time() + 30, '/');
      } catch (Throwable $e) {
        setcookie('kp_flash', 'Error: el correo ya existe o es inválido.', time() + 30, '/');
      }
    }
    header('Location: /admin/users'); exit;
  }
}

$users = [];
try {
  $users = kp_q("SELECT u.id, u.name, u.email, u.role, u.avatar, u.url, u.bio, u.is_guest, u.last_login, u.totp_secret,
                        (SELECT GROUP_CONCAT(p.title, ' · ')
                           FROM podcast_users m JOIN podcasts p ON p.id = m.podcast_id
                           WHERE m.user_id = u.id) as pods
                 FROM users u ORDER BY
                   CASE u.role WHEN 'owner' THEN 0 WHEN 'admin' THEN 1 WHEN 'editor' THEN 2 WHEN 'host' THEN 3 WHEN 'author' THEN 3 ELSE 4 END,
                   u.name") ?: [];
} catch (Throwable $e) {}

// Sin fallback: si la BD está vacía la tabla muestra empty state.

$role_meta = [
  'owner'        => [__('users_role_owner', 'Dueño'),        __('users_role_owner_desc', 'Poder total sobre la instancia, usuarios (incluso otros dueños) y podcasts'), '#ff5f7e'],
  'admin'        => [__('users_role_admin', 'Admin'),        __('users_role_admin_desc', 'Gestiona sitio, usuarios y podcasts (sin tocar dueños)'),                      '#6366f1'],
  'editor'       => [__('users_role_editor', 'Editor'),      __('users_role_editor_desc', 'Edita información de podcasts y episodios asignados'),                        '#10b981'],
  'host'         => [__('users_role_host', 'Host'),          __('users_role_host_desc', 'Presentador · publica y edita episodios propios en podcasts asignados'),        '#f59e0b'],
  'collaborator' => [__('users_role_collab', 'Colaborador'), __('users_role_collab_desc', 'Colabora creando borradores y episodios propios en podcasts asignados'),      '#06b6d4'],
];

// Cuenta por rol
$by_role = ['owner'=>0,'admin'=>0,'editor'=>0,'host'=>0,'collaborator'=>0];
foreach ($users as $u) {
  $r = $u['role'] ?? 'host';
  if ($r === 'author') $r = 'host';
  if (isset($by_role[$r])) $by_role[$r]++;
}

function initials_from($s) {
  $parts = preg_split('/\s+/', trim((string)$s));
  $a = $parts[0] ?? '';
  $b = $parts[1] ?? '';
  return strtoupper(mb_substr($a,0,1) . mb_substr($b,0,1));
}
?>
<div class="page-head">
  <div>
    <h1 class="page-title"><?= __('users_title', 'Usuarios') ?></h1>
    <p class="page-sub"><?= __raw('users_sub', 'Roles, permisos y asignación de podcasts · :count cuentas', ['count' => count($users)]) ?></p>
  </div>
  <div class="row" style="gap:8px">
    <button class="btn" type="button" onclick="document.getElementById('roles_modal').showModal()"><?= icon('settings',13) ?> <?= __('users_roles_permissions', 'Roles') ?></button>
    <button class="btn btn-primary" type="button" onclick="document.getElementById('create_user_modal').showModal()"><?= icon('plus',13) ?> <?= __('users_create', 'Crear Usuario') ?></button>
  </div>
</div>

<div class="grid-12" style="gap:18px">
  <?php foreach ($role_meta as $key => [$label, $desc, $color]): ?>
    <div class="card span-3" style="padding:18px">
      <div class="row" style="gap:10px;align-items:center">
        <div style="width:32px;height:32px;border-radius:8px;background:<?= $color ?>22;color:<?= $color ?>;display:grid;place-items:center;font-weight:700"><?= e($label[0]) ?></div>
        <div style="font-weight:600"><?= e($label) ?></div>
      </div>
      <div style="font-size:12.5px;color:var(--text-3);margin-top:10px;line-height:1.5"><?= e($desc) ?></div>
      <div style="margin-top:12px;font-size:11.5px;color:var(--text-3)"><?= __raw('users_accounts_count', ':count cuenta(s)', ['count' => $by_role[$key]]) ?></div>
    </div>
  <?php endforeach; ?>
</div>

<div class="card card-lg" style="padding:0;margin-top:24px">
  <div class="card-head" style="padding:20px 24px">
    <div class="card-title"><?= __('users_all', 'Todos los usuarios') ?></div>
    <div class="row"><input class="input" id="user_search" placeholder="<?= __('search', 'Buscar…') ?>" style="width:240px"/></div>
  </div>
  <div class="list" id="user_list">
    <div style="overflow-x:auto;">
      <div style="min-width: 800px;">
        <div style="display:grid;grid-template-columns:48px 1fr 220px 110px 1fr 110px 80px;gap:14px;padding:10px 24px;font-size:11px;color:var(--text-3);text-transform:uppercase;letter-spacing:0.06em;font-weight:600;border-bottom:1px solid var(--border)">
      <div></div><div><?= __('user', 'Usuario') ?></div><div><?= __('email', 'Email') ?></div><div><?= __('role', 'Rol') ?></div><div><?= __('nav_podcasts', 'Podcasts') ?></div><div><?= __('users_last_login', 'Último acceso') ?></div><div></div>
    </div>
    <?php foreach ($users as $u):
      $r = $u['role'] ?? 'host';
      if ($r === 'author') $r = 'host';
      [$rlabel, , $rcolor] = $role_meta[$r] ?? ['Host','',$u['color'] ?? '#f59e0b'];
      $color = $u['color'] ?? $rcolor;
    ?>
      <div class="user-row" data-q="<?= e(strtolower(($u['name']??'').' '.($u['email']??''))) ?>"
           style="display:grid;grid-template-columns:48px 1fr 220px 110px 1fr 110px 80px;gap:14px;padding:14px 24px;align-items:center;border-bottom:1px solid var(--border);font-size:13.5px">
        <div style="width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,<?= e($color) ?>,<?= e($color) ?>99);color:white;font-weight:700;font-size:13px;display:grid;place-items:center;overflow:hidden">
          <?php if (!empty($u['avatar'])): ?>
            <img src="<?= e($u['avatar']) ?>" style="width:100%;height:100%;object-fit:cover"/>
          <?php else: ?>
            <?= e(initials_from($u['name'] ?? '?')) ?>
          <?php endif; ?>
        </div>
        <div style="font-weight:500">
          <?= e($u['name'] ?? '—') ?>
          <?php if (!empty($u['is_guest'])): ?>
            <span class="tag" style="margin-left:6px;background:var(--surface-3);color:var(--text-3);font-size:10px"><?= __('users_guest', 'Invitado') ?></span>
          <?php endif; ?>
        </div>
        <div style="color:var(--text-2);font-size:12.5px;font-family:ui-monospace,monospace;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= !empty($u['is_guest']) ? '—' : e($u['email'] ?? '') ?></div>
        <div><span class="tag" style="background:<?= e($rcolor) ?>22;color:<?= e($rcolor) ?>;font-size:11px"><?= e($rlabel) ?></span></div>
        <div style="color:var(--text-3);font-size:12.5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($u['pods'] ?? '—') ?></div>
        <div style="color:var(--text-3);font-size:12px"><?= e($u['last_login'] ?? '—') ?></div>
        <a class="btn" href="<?= admin_url('edit-user?id=' . $u['id']) ?>" style="padding:4px 8px;font-size:12px;color:var(--accent);border-color:var(--accent);text-decoration:none;display:inline-flex;align-items:center;gap:4px"><?= icon('edit',13) ?> <?= __('edit', 'Editar') ?></a>
      </div>
    <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>



<!-- Modal: roles -->
<dialog id="roles_modal" class="dlg">
  <div class="card card-lg" style="padding:24px;max-width:560px;margin:auto">
    <div class="card-title" style="margin-bottom:14px"><?= __('users_roles_permissions', 'Roles y permisos') ?></div>
    <div class="col" style="gap:10px">
      <?php foreach ($role_meta as [$label, $desc, $color]): ?>
        <div style="padding:12px;border:1px solid var(--border);border-radius:10px">
          <div class="row" style="gap:10px;align-items:center">
            <div style="width:28px;height:28px;border-radius:7px;background:<?= $color ?>22;color:<?= $color ?>;display:grid;place-items:center;font-weight:700;font-size:12px"><?= e($label[0]) ?></div>
            <strong style="font-size:14px"><?= e($label) ?></strong>
          </div>
          <div style="font-size:12.5px;color:var(--text-3);margin-top:8px;line-height:1.5"><?= e($desc) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="row" style="justify-content:flex-end;margin-top:18px"><button class="btn" type="button" onclick="this.closest('dialog').close()"><?= __('close', 'Cerrar') ?></button></div>
  </div>
</dialog>

<style>
.dlg { background: transparent; border: 0; padding: 0; }
.dlg::backdrop { background: rgba(0,0,0,0.55); backdrop-filter: blur(2px); }
</style>

<dialog id="create_user_modal" class="dlg">
  <form method="POST" action="<?= admin_url('users') ?>" enctype="multipart/form-data" class="card card-lg" style="padding:24px;max-width:400px;margin:auto">
    <div class="card-title" style="margin-bottom:14px"><?= __('users_create', 'Crear Usuario') ?></div>
    <div style="font-size:12.5px;color:var(--text-3);margin-bottom:12px"><?= __('users_create_desc', 'El usuario podrá iniciar sesión inmediatamente. Asegúrate de enviarle su correo y contraseña.') ?></div>
    <input type="hidden" name="_action" value="create_user"/>
    <?= kp_csrf_field() ?>
    <div class="field"><label class="label"><?= __('name', 'Nombre') ?></label><input class="input" name="name" required/></div>
    <div class="field" style="margin-top:12px"><label class="label"><?= __('email', 'Email') ?></label><input class="input" name="email" type="email" required/></div>
    <div class="field" style="margin-top:12px"><label class="label"><?= __('role', 'Rol') ?></label>
      <select class="select" name="role" required>
        <?php if (($current_user['role'] ?? '') === 'owner'): ?>
        <option value="owner"><?= __('users_role_owner', 'Dueño') ?></option>
        <?php endif; ?>
        <option value="admin"><?= __('users_role_admin', 'Admin') ?></option>
        <option value="editor"><?= __('users_role_editor', 'Editor') ?></option>
        <option value="host" selected><?= __('users_role_host', 'Host') ?></option>
        <option value="collaborator"><?= __('users_role_collab', 'Colaborador') ?></option>
      </select>
    </div>
    <div class="field" style="margin-top:12px"><label class="label"><?= __('password', 'Contraseña') ?></label><input class="input" name="password" type="password" required/></div>
    <div class="field" style="margin-top:12px">
      <label class="label"><?= __('users_avatar_optional', 'Avatar (opcional)') ?></label>
      <div style="display:flex;align-items:center;gap:10px">
        <input class="input" type="file" name="avatar" id="create_user_avatar" accept="image/*" style="flex:1"/>
        <button id="create_user_avatar_cancel" type="button" class="btn btn-ghost" style="display:none;color:var(--red);padding:4px 8px;font-size:12px"><?= icon('x',12) ?> <?= __('cancel', 'Cancelar') ?></button>
      </div>
    </div>
    <div class="field" style="margin-top:12px"><label class="label"><?= __('users_url_optional', 'URL (opcional)') ?></label><input class="input" name="url" type="url" placeholder="https://..."/></div>
    <div class="field" style="margin-top:12px"><label class="label"><?= __('users_bio_optional', 'Biografía / Contexto (opcional)') ?></label><textarea class="input" name="bio" style="resize:vertical;min-height:60px"></textarea></div>
    <div class="row" style="gap:8px;margin-top:18px;justify-content:flex-end">
      <button type="button" class="btn" onclick="this.closest('dialog').close()"><?= __('cancel', 'Cancelar') ?></button>
      <button type="submit" class="btn btn-primary"><?= __('users_create', 'Crear Usuario') ?></button>
    </div>
  </form>
</dialog>

<script>
(() => {
  const q = document.getElementById('user_search');
  if (!q) return;
  q.addEventListener('input', () => {
    const v = q.value.trim().toLowerCase();
    document.querySelectorAll('.user-row').forEach(r => {
      r.style.display = !v || r.dataset.q.includes(v) ? '' : 'none';
    });
  });
})();

const createAvatarInput = document.getElementById('create_user_avatar');
const createAvatarCancel = document.getElementById('create_user_avatar_cancel');
createAvatarInput?.addEventListener('change', function() {
  if (this.files[0]) {
    if (createAvatarCancel) createAvatarCancel.style.display = 'inline-flex';
  } else {
    if (createAvatarCancel) createAvatarCancel.style.display = 'none';
  }
});
createAvatarCancel?.addEventListener('click', function() {
  if (createAvatarInput) createAvatarInput.value = '';
  this.style.display = 'none';
});

document.getElementById('create_user_modal')?.addEventListener('close', () => {
  if (createAvatarInput) createAvatarInput.value = '';
  if (createAvatarCancel) createAvatarCancel.style.display = 'none';
});
</script>
