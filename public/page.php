<?php
// public/page.php — renderiza una página estática del editor de bloques
require_once __DIR__ . '/../includes/helpers.php';

$slug = preg_replace('/[^a-z0-9\-]/', '', $_GET['id'] ?? $_GET['slug'] ?? '');
$file = __DIR__ . '/../storage/pages/' . $slug . '.json';
if (!$slug || !file_exists($file)) { http_response_code(404); echo '<div class="pub-container" style="padding:80px 0"><h1>Página no encontrada</h1></div>'; return; }

$pg = json_decode(file_get_contents($file), true);
if (!$pg || empty($pg['published'])) { http_response_code(404); echo '<div class="pub-container" style="padding:80px 0"><h1>No disponible</h1></div>'; return; }
$title = $pg['title'];
$og_title = $pg['title'];
$og_description = mb_strimwidth(trim(strip_tags($pg['content_md'] ?? '')), 0, 200, '...');
?>
<article class="pub-container pub-static">
  <header style="padding:64px 0 32px;border-bottom:1px solid var(--pub-border);margin-bottom:48px">
    <div style="font-size:12px;color:var(--pub-muted);text-transform:uppercase;letter-spacing:0.08em">Página</div>
    <h1 style="font-size:48px;font-weight:700;letter-spacing:-0.02em;margin:8px 0 0;line-height:1.05"><?= e($pg['title']) ?></h1>
    <div style="font-size:13px;color:var(--pub-muted);margin-top:14px">Actualizado el <?= e(date('d M Y', strtotime($pg['updated'] ?? 'now'))) ?></div>
  </header>
  <div class="pub-prose">
    <?php
      $md = $pg['content_md'] ?? '';
      
      // Fallback para páginas viejas que no se han guardado con el nuevo editor Markdown
      if (!$md && !empty($pg['blocks'])) {
        foreach ($pg['blocks'] as $b) {
          if ($b['type'] === 'heading') $md .= str_repeat('#', $b['level'] ?? 2) . " {$b['text']}\n\n";
          if ($b['type'] === 'paragraph') $md .= "{$b['text']}\n\n";
          if ($b['type'] === 'list') { foreach ($b['items'] as $i) $md .= "- $i\n"; $md .= "\n"; }
          if ($b['type'] === 'quote') $md .= "> {$b['text']}\n\n";
          if ($b['type'] === 'image') $md .= "![{$b['alt']}]({$b['src']})\n\n";
          if ($b['type'] === 'embed') $md .= "{$b['url']}\n\n";
          if ($b['type'] === 'divider') $md .= "---\n\n";
        }
        $md = trim($md);
      }

      if (!$md) {
        echo '<p class="pub-muted">Esta página está vacía.</p>';
      } else {
        $h = e($md);
        $h = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $h);
        $h = preg_replace('/^## (.+)$/m', '<h2>$1</h2>', $h);
        $h = preg_replace('/^# (.+)$/m', '<h1>$1</h1>', $h);
        $h = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $h);
        $h = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $h);
        $h = preg_replace('/`([^`]+)`/', '<code>$1</code>', $h);
        $h = preg_replace('/!\[(.*?)\]\((.*?)\)/', '<figure><img src="$2" alt="$1" style="max-width:100%;border-radius:8px"></figure>', $h);
        $h = preg_replace('/\[(.+?)\]\((.+?)\)/', '<a href="$2" target="_blank">$1</a>', $h);
        $h = preg_replace('/^&gt; (.+)$/m', '<blockquote>$1</blockquote>', $h);
        $h = preg_replace('/^- (.+)$/m', '<li>$1</li>', $h);
        $h = preg_replace('/(<li>.*<\/li>)/s', '<ul>$1</ul>', $h);
        $h = preg_replace('/^---$/m', '<hr>', $h);
        $h = str_replace("\n\n", '</p><p>', $h);
        echo '<p>' . $h . '</p>';
      }
    ?>
  </div>
</article>
