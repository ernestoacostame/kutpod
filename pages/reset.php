<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';

$inst = kp_instance();
$token = $_GET['token'] ?? '';
$error = null;
$msg = null;

if (!$token) {
  header('Location: /admin/login');
  exit;
}

$u = kp_one("SELECT * FROM users WHERE reset_token = ? AND reset_expires > ?", [$token, time()]);

if (!$u) {
  $error = 'El enlace de recuperación es inválido o ha expirado.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $password = $_POST['password'] ?? '';
  $password_confirm = $_POST['password_confirm'] ?? '';
  
  if (strlen($password) < 10) {
    $error = 'La contraseña debe tener al menos 10 caracteres.';
  } elseif ($password !== $password_confirm) {
    $error = 'Las contraseñas no coinciden.';
  } else {
    $hash = password_hash($password, PASSWORD_DEFAULT);
    kp_db()->prepare("UPDATE users SET password_hash = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?")->execute([$hash, $u['id']]);
    kp_alert('security', 'Contraseña restablecida', "El usuario " . $u['name'] . " ha restablecido su contraseña.", '', ['actor_name' => $u['email']]);
    $msg = 'Tu contraseña ha sido restablecida con éxito.';
  }
}
?>
<div style="min-height:calc(100vh - 120px);display:grid;place-items:center;padding:40px">
  <div class="card card-lg" style="width:380px;padding:36px">
    <div style="text-align:center;margin-bottom:24px">
      <div style="width:48px;height:48px;border-radius:12px;background:var(--accent);color:white;display:grid;place-items:center;font-weight:800;font-size:22px;margin:0 auto 12px">K</div>
      <h1 class="page-title" style="font-size:20px;margin:0">Restablecer contraseña</h1>
      <p class="page-sub" style="margin-top:6px;font-size:12.5px">Introduce tu nueva contraseña.</p>
    </div>
    
    <?php if ($error): ?><div class="error" style="margin-bottom:14px;padding:10px 12px;background:rgba(248,113,113,.1);border-radius:8px"><?= e($error) ?></div><?php endif; ?>
    
    <?php if ($msg): ?>
      <div style="margin-bottom:14px;padding:10px 12px;background:rgba(16,185,129,.1);color:#059669;border-radius:8px;font-size:13.5px;line-height:1.5"><?= e($msg) ?></div>
      <a class="btn btn-primary" href="/admin/login" style="width:100%;justify-content:center;margin-top:20px">Ir al inicio de sesión</a>
    <?php elseif ($u): ?>
      <form method="POST">
        <div class="field"><label class="label">Nueva contraseña</label><input class="input" type="password" name="password" required autofocus minlength="6"></div>
        <div class="field" style="margin-top:14px"><label class="label">Confirmar contraseña</label><input class="input" type="password" name="password_confirm" required minlength="6"></div>
        <button class="btn btn-primary" type="submit" style="width:100%;margin-top:20px;justify-content:center">Guardar contraseña</button>
      </form>
    <?php else: ?>
      <a class="btn" href="/admin/forgot" style="width:100%;justify-content:center;margin-top:20px">Solicitar nuevo enlace</a>
    <?php endif; ?>
  </div>
</div>
