<?php
// pages/broadcast.php — configuración de redes sociales y plataformas de podcast
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/platforms.php';

$podcast_slug = $_GET['podcast'] ?? '';
$p = kp_find_podcast($podcast_slug);

if (!$p) {
    header('Location: /admin/podcasts');
    exit;
}

$pdo = kp_db();

// Load existing broadcast configuration
$broadcast_links = [];
if (!empty($p['broadcast_links'])) {
    $broadcast_links = json_decode($p['broadcast_links'], true) ?: [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_links = [];
    $raw_links = $_POST['links'] ?? [];
    
    foreach ($raw_links as $type => $slugs) {
        foreach ($slugs as $slug => $data) {
            $link = trim($data['link'] ?? '');
            $account_id = trim($data['account_id'] ?? '');
            $visible = isset($data['visible']) ? true : false;
            
            // Only save if either link or ID is provided, or if it was previously configured
            if ($link !== '' || $account_id !== '' || $visible) {
                $new_links[$slug] = [
                    'type' => $type,
                    'link' => $link,
                    'account_id' => $account_id,
                    'visible' => $visible
                ];
            }
        }
    }
    
    $json = json_encode($new_links);
    $pdo->prepare("UPDATE podcasts SET broadcast_links = ? WHERE slug = ?")->execute([$json, $p['id']]);
    
    setcookie('kp_flash', 'Enlaces de plataformas guardados', time() + 30, '/');
    header('Location: /admin/broadcast?podcast=' . urlencode($p['id']));
    exit;
}

$platformsData = \KutPod\KpPlatforms::DATA;

function get_platform_svg($type, $slug) {
    $path = __DIR__ . '/../public/assets/platforms/' . $type . '/' . $slug . '.svg';
    if (!file_exists($path)) {
        $path = __DIR__ . '/../public/assets/platforms/' . $type . '/default.svg';
    }
    if (file_exists($path)) {
        return file_get_contents($path);
    }
    return icon('link', 24); // Fallback to internal icon
}

?>

<div class="page-head">
    <div class="row" style="gap:16px;align-items:center">
        <a class="btn btn-ghost" href="/admin/podcast/<?= e($p['id']) ?>" style="padding:0;width:32px;height:32px;justify-content:center"><?= icon('arrowLeft',16) ?></a>
        <div>
            <h1 class="page-title">Broadcast</h1>
            <p class="page-sub">Publica <?= e($p['title']) ?> en plataformas y enlaza tus redes sociales.</p>
        </div>
    </div>
</div>

<form method="POST" id="broadcast-form">
    <?= kp_csrf_field() ?>
    <div class="grid-12" style="gap:24px;margin-top:24px">
        <div class="span-8 col" style="gap:32px">
            <?php foreach (['podcasting' => 'Directorios de Podcasts', 'social' => 'Redes Sociales', 'funding' => 'Plataformas de Financiamiento'] as $type => $title): ?>
            <section id="<?= $type ?>" class="card card-lg" style="padding:0;overflow:hidden">
                <header class="section-head" style="padding:24px 24px 16px;border-bottom:1px solid var(--border);margin:0">
                    <h2 style="font-size:18px;font-weight:600"><?= $title ?></h2>
                </header>
                <div class="list" style="padding:12px">
                    <?php 
                    $platforms = $platformsData[$type] ?? [];
                    // Sort platforms alphabetically by label
                    uasort($platforms, function($a, $b) { return strcasecmp($a['label'], $b['label']); });
                    
                    foreach ($platforms as $slug => $plat): 
                        $cfg = $broadcast_links[$slug] ?? ['link'=>'', 'account_id'=>'', 'visible'=>false];
                        $hasData = ($cfg['link'] !== '' || $cfg['account_id'] !== '');
                    ?>
                    <div class="row" style="align-items:flex-start;padding:16px;border-bottom:1px solid var(--border-soft);gap:20px;background:<?= $hasData ? 'var(--surface-2)' : 'transparent' ?>;border-radius:12px;margin-bottom:8px">
                        <div style="flex:0 0 48px;height:48px;border-radius:12px;background:var(--surface);display:grid;place-items:center;color:var(--text);border:1px solid var(--border)">
                            <div style="width:24px;height:24px;font-size:24px;display:grid;place-items:center">
                                <?= get_platform_svg($type, $slug) ?>
                            </div>
                        </div>
                        <div style="flex:1;min-width:0">
                            <div class="row" style="justify-content:space-between;margin-bottom:12px">
                                <div>
                                    <div style="font-size:15px;font-weight:600"><?= e($plat['label']) ?></div>
                                    <div class="row" style="gap:12px;margin-top:4px;font-size:12px">
                                        <a href="<?= e($plat['home_url']) ?>" target="_blank" style="color:var(--text-3);text-decoration:none"><?= icon('externalLink',10) ?> Website</a>
                                        <?php if (!empty($plat['submit_url'])): ?>
                                        <a href="<?= e($plat['submit_url']) ?>" target="_blank" style="color:var(--accent);text-decoration:none"><?= icon('plus',10) ?> Registrarse</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <label class="toggle-row" style="gap:10px">
                                    <span style="font-size:13px;color:var(--text-2);font-weight:500">Mostrar en web</span>
                                    <label class="toggle">
                                        <input type="checkbox" name="links[<?= $type ?>][<?= $slug ?>][visible]" <?= $cfg['visible'] ? 'checked' : '' ?>>
                                        <span class="toggle-track"></span>
                                    </label>
                                </label>
                            </div>
                            <div class="grid-12" style="gap:16px">
                                <div class="field span-8">
                                    <label class="label">URL / Enlace</label>
                                    <input class="input" type="url" name="links[<?= $type ?>][<?= $slug ?>][link]" value="<?= e($cfg['link']) ?>" placeholder="<?= e($plat['home_url']) ?>...">
                                </div>
                                <div class="field span-4">
                                    <label class="label">Account ID (Opcional)</label>
                                    <input class="input" type="text" name="links[<?= $type ?>][<?= $slug ?>][account_id]" value="<?= e($cfg['account_id']) ?>" placeholder="@usuario o ID">
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endforeach; ?>
        </div>

        <div class="span-4 col" style="gap:16px;align-items:stretch">
            <aside class="form-toc" style="position:sticky;top:88px">
                <a href="#podcasting" class="form-toc-item active">Podcasting</a>
                <a href="#social" class="form-toc-item">Redes Sociales</a>
                <a href="#funding" class="form-toc-item">Financiamiento</a>
            </aside>
            <div class="col" style="gap:8px;position:sticky;top:220px">
                <button type="submit" form="broadcast-form" class="btn btn-primary" style="width:100%;justify-content:center"><?= icon('check',13) ?> Guardar</button>
            </div>
        </div>
    </div>
</form>

<script>
// Simple ScrollSpy para el ToC de la izquierda
document.addEventListener('DOMContentLoaded', () => {
  const sections = document.querySelectorAll('section.card');
  const navLinks = document.querySelectorAll('.form-toc-item');
  
  window.addEventListener('scroll', () => {
    let current = '';
    sections.forEach(section => {
      const sectionTop = section.offsetTop;
      if (scrollY >= (sectionTop - 120)) {
        current = section.getAttribute('id');
      }
    });
    navLinks.forEach(link => {
      link.classList.remove('active');
      if (link.getAttribute('href').substring(1) === current) {
        link.classList.add('active');
      }
    });
  });
});
</script>
