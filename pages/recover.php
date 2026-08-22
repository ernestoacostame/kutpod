<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';

$inst = kp_instance();
$error = null;
$msg = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = strtolower(trim($_POST['email'] ?? ''));
  $code = trim($_POST['code'] ?? '');
  
  if (!$email || !$code) {
    $error = 'Rellena todos los campos.';
  } else {
    $u = kp_one("SELECT * FROM users WHERE email = ?", [$email]);
    if ($u && !empty($u['recovery_codes'])) {
      $codes = json_decode($u['recovery_codes'], true) ?: [];
      $matchedIndex = -1;
      
      foreach ($codes as $idx => $hash) {
        if (password_verify($code, $hash)) {
          $matchedIndex = $idx;
          break;
        }
      }
      
      if ($matchedIndex >= 0) {
        // Código válido! Eliminarlo para que no se pueda reusar
        unset($codes[$matchedIndex]);
        
        // Generar un token de reseteo automático válido por 15 minutos para saltar a reset.php
        $token = bin2hex(random_bytes(16));
        $expires = time() + 900;
        
        kp_db()->prepare("UPDATE users SET recovery_codes = ?, reset_token = ?, reset_expires = ? WHERE id = ?")
               ->execute([json_encode(array_values($codes)), $token, $expires, $u['id']]);
               
        kp_alert('security', 'Código de respaldo utilizado', "El usuario " . $u['name'] . " ha utilizado un código de respaldo para acceder.", '', ['actor_name' => $email]);
        
        header('Location: /admin/reset?token=' . $token);
        exit;
      } else {
        $error = 'El código es inválido o ya ha sido utilizado.';
      }
    } else {
      // Mensaje genérico para no filtrar emails
      $error = 'El código es inválido o ya ha sido utilizado.';
    }
  }
}
?>
<div style="min-height:calc(100vh - 120px);display:grid;place-items:center;padding:40px">
  <form method="POST" class="card card-lg" style="width:380px;padding:36px">
    <div style="text-align:center;margin-bottom:24px">
      <div style="width:48px;height:48px;border-radius:12px;background:var(--accent);color:white;display:grid;place-items:center;font-weight:800;font-size:22px;margin:0 auto 12px">K</div>
      <h1 class="page-title" style="font-size:20px;margin:0">Recuperación por código</h1>
      <p class="page-sub" style="margin-top:6px;font-size:12.5px;line-height:1.4">Utiliza uno de tus códigos de respaldo de 8 caracteres.</p>
    </div>
    <?php if ($error): ?><div class="error" style="margin-bottom:14px;padding:10px 12px;background:rgba(248,113,113,.1);border-radius:8px"><?= e($error) ?></div><?php endif; ?>
    <div class="field"><label class="label">Email de tu cuenta</label><input class="input" type="email" name="email" required autofocus></div>
    <div class="field" style="margin-top:14px"><label class="label">Código de respaldo</label><input class="input" type="text" name="code" required minlength="8" maxlength="8" placeholder="ej. a1b2c3d4" style="font-family:ui-monospace,monospace;letter-spacing:1px"></div>
    <button class="btn btn-primary" type="submit" style="width:100%;margin-top:20px;justify-content:center">Verificar código</button>
    <div style="text-align:center;margin-top:16px;font-size:13px"><a href="/admin/login" style="color:var(--text-3);text-decoration:none">Cancelar y volver</a></div>
  </form>
</div>
