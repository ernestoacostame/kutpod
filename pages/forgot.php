<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';

$inst = kp_instance();
$msg = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = strtolower(trim($_POST['email'] ?? ''));
  if ($email) {
    $u = kp_one("SELECT * FROM users WHERE email = ?", [$email]);
    if ($u) {
      $token = bin2hex(random_bytes(16));
      $expires = time() + 3600; // 1 hora
      kp_db()->prepare("UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?")->execute([$token, $expires, $u['id']]);
      
      $resetLink = 'https://' . ($inst['domain'] ?: $_SERVER['HTTP_HOST']) . '/admin/reset?token=' . $token;
      
      $subject = "Recuperación de contraseña en " . $inst['name'];
      $body = "Hola " . $u['name'] . ",\n\nHas solicitado restablecer tu contraseña.\n\nPor favor, haz clic en el siguiente enlace (válido por 1 hora):\n$resetLink\n\nSi no fuiste tú, ignora este mensaje.\n\nKutPod";
      
      require_once __DIR__ . '/../includes/mail.php';
      kp_send_mail($email, $subject, $body);
      
      kp_alert('security', 'Recuperación de contraseña', "Se ha enviado un enlace de recuperación al usuario " . $u['name'], '', ['actor_name' => $email]);
    }
    // Siempre mostrar el mismo mensaje para no revelar si el email existe
    $msg = 'Si el correo existe en nuestro sistema, te hemos enviado un enlace para restablecer tu contraseña.';
  } else {
    $error = 'Por favor, introduce un email válido.';
  }
}
?>
<div style="min-height:calc(100vh - 120px);display:grid;place-items:center;padding:40px">
  <form method="POST" class="card card-lg" style="width:380px;padding:36px">
    <div style="text-align:center;margin-bottom:24px">
      <div style="width:48px;height:48px;border-radius:12px;background:var(--accent);color:white;display:grid;place-items:center;font-weight:800;font-size:22px;margin:0 auto 12px">K</div>
      <h1 class="page-title" style="font-size:20px;margin:0">Recuperar contraseña</h1>
      <p class="page-sub" style="margin-top:6px;font-size:12.5px">Introduce tu correo y te enviaremos un enlace.</p>
    </div>
    <?php if ($error): ?><div class="error" style="margin-bottom:14px;padding:10px 12px;background:rgba(248,113,113,.1);border-radius:8px"><?= e($error) ?></div><?php endif; ?>
    <?php if ($msg): ?>
      <div style="margin-bottom:14px;padding:10px 12px;background:rgba(16,185,129,.1);color:#059669;border-radius:8px;font-size:13.5px;line-height:1.5"><?= e($msg) ?></div>
      <a class="btn" href="/admin/login" style="width:100%;justify-content:center">Volver al inicio de sesión</a>
    <?php else: ?>
      <div class="field"><label class="label">Email</label><input class="input" type="email" name="email" required autofocus></div>
      <button class="btn btn-primary" type="submit" style="width:100%;margin-top:20px;justify-content:center">Enviar enlace</button>
      <div style="text-align:center;margin-top:16px;font-size:13px"><a href="/admin/login" style="color:var(--text-3);text-decoration:none">Cancelar y volver</a><span style="color:var(--border);margin:0 8px">|</span><a href="/admin/recover" style="color:var(--text-3);text-decoration:none">Tengo un código</a></div>
    <?php endif; ?>
  </form>
</div>
