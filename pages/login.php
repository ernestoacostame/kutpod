<?php
// pages/login.php · login real (sin credenciales pre-rellenadas)
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

$inst = kp_instance();
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $res = kp_login($_POST['email'] ?? '', $_POST['password'] ?? '');
  if ($res) {
    if (isset($res['2fa_pending'])) {
      kp_csrf_ensure_session();
      $_SESSION['pending_2fa_user_id'] = $res['user_id'];
      header('Location: /admin/login-2fa');
      exit;
    }
    header('Location: /admin');
    exit;
  }
  $error = 'Credenciales incorrectas.';
}
?>
<div style="min-height:calc(100vh - 120px);display:grid;place-items:center;padding:40px 16px;box-sizing:border-box">
  <form method="POST" class="card card-lg" style="width:100%;max-width:380px;padding:36px;box-sizing:border-box">
    <div style="text-align:center;margin-bottom:24px">
      <?php $logo = kp_setting('instance_logo'); if ($logo): ?>
        <img src="<?= e($logo) ?>" style="width:48px;height:48px;border-radius:12px;object-fit:cover;margin:0 auto 12px;display:block">
      <?php else: ?>
        <div style="width:48px;height:48px;border-radius:12px;background:var(--accent);color:white;display:grid;place-items:center;font-weight:800;font-size:22px;margin:0 auto 12px">K</div>
      <?php endif; ?>
      <h1 class="page-title" style="font-size:20px;margin:0">Entrar a <?= e($inst['name']) ?></h1>
      <p class="page-sub" style="margin-top:6px;font-size:12.5px">Tu studio de podcasting federado.</p>
    </div>
    <?php if ($error): ?><div class="error" style="margin-bottom:14px;padding:10px 12px;background:rgba(248,113,113,.1);border-radius:8px"><?= e($error) ?></div><?php endif; ?>
    <div class="field"><label class="label">Email</label><input class="input" type="email" name="email" required autofocus></div>
    <div class="field" style="margin-top:14px"><label class="label">Contraseña</label><input class="input" type="password" name="password" required></div>
    <button class="btn btn-primary" type="submit" style="width:100%;margin-top:20px;justify-content:center">Entrar</button>
    <div style="text-align:center;margin-top:16px;font-size:13px"><a href="/" style="color:var(--text);font-weight:500;text-decoration:none">← Volver al sitio público</a></div>
    <div style="text-align:center;margin-top:12px;font-size:13px"><a href="/admin/forgot" style="color:var(--text-3);text-decoration:none">¿Olvidaste tu contraseña?</a><span style="color:var(--border);margin:0 8px">|</span><a href="/admin/recover" style="color:var(--text-3);text-decoration:none">Usar código de respaldo</a></div>
  </form>
</div>
