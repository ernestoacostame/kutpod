<?php
// pages/pages.php — editor visual de páginas estáticas (block editor)
require_once __DIR__ . '/../includes/helpers.php';

// Persistencia demo: archivos JSON por slug. En producción → tabla `pages`.
$dir = __DIR__ . '/../storage/pages';
if (!is_dir($dir)) @mkdir($dir, 0755, true);

function kp_load_pages(string $dir): array {
  $items = [];
  foreach (glob($dir . '/*.json') ?: [] as $f) {
    $j = json_decode(file_get_contents($f), true);
    if ($j) $items[$j['slug']] = $j;
  }
  return $items;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
  $a = $_POST['_action'];
  if ($a === 'save' && !empty($_POST['payload'])) {
    $payload = json_decode($_POST['payload'], true);
    if ($payload && !empty($payload['slug'])) {
      $payload['updated'] = date('Y-m-d');
      file_put_contents($dir . '/' . preg_replace('/[^a-z0-9\-]/','', $payload['slug']) . '.json',
        json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
      setcookie('kp_flash', 'Página guardada', time() + 30, '/');
    }
  } elseif ($a === 'delete' && !empty($_POST['slug'])) {
    @unlink($dir . '/' . preg_replace('/[^a-z0-9\-]/','', $_POST['slug']) . '.json');
    setcookie('kp_flash', 'Página eliminada', time() + 30, '/');
  }
  header('Location: /admin/pages'); exit;
}

$pages = kp_load_pages($dir);
$slug = $_GET['slug'] ?? array_key_first($pages);
$current = $pages[$slug] ?? reset($pages);
if (!$current) {
  $current = ['slug' => 'home', 'title' => 'Inicio', 'published' => false, 'content_md' => ''];
}

// Migración backwards-compatible: Si la página tiene 'blocks' pero no 'content_md', convertir.
if (!isset($current['content_md']) && !empty($current['blocks'])) {
  $md = '';
  foreach ($current['blocks'] as $b) {
    if ($b['type'] === 'heading') $md .= str_repeat('#', $b['level'] ?? 2) . " {$b['text']}\n\n";
    if ($b['type'] === 'paragraph') $md .= "{$b['text']}\n\n";
    if ($b['type'] === 'list') { foreach ($b['items'] as $i) $md .= "- $i\n"; $md .= "\n"; }
    if ($b['type'] === 'quote') $md .= "> {$b['text']}\n\n";
    if ($b['type'] === 'image') $md .= "![{$b['alt']}]({$b['src']})\n\n";
    if ($b['type'] === 'embed') $md .= "{$b['url']}\n\n";
    if ($b['type'] === 'divider') $md .= "---\n\n";
  }
  $current['content_md'] = trim($md);
}
?>
<div class="page-head">
  <div>
    <h1 class="page-title">Páginas</h1>
    <p class="page-sub">Editor visual de páginas estáticas del sitio público.</p>
  </div>
  <div class="row">
    <button class="btn" type="button" onclick="KP_PAGES.del()" style="color:var(--red)"><?= icon('trash',13) ?> Eliminar</button>
    <a class="btn" href="/p/<?= e($current['slug']) ?>" target="_blank"><?= icon('globe',13) ?> Ver pública</a>
    <button class="btn btn-primary" type="button" onclick="KP_PAGES.save()"><?= icon('check',13) ?> Guardar</button>
  </div>
</div>

<div class="grid-12" style="gap:24px">
  <!-- Sidebar páginas -->
  <aside class="span-3 card" style="padding:0;overflow:hidden;align-self:flex-start">
    <div style="padding:14px 16px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
      <strong style="font-size:13px">Tus páginas</strong>
      <button class="btn btn-sm" type="button" onclick="KP_PAGES.create()"><?= icon('plus',13) ?> Nueva</button>
    </div>
    <div class="page-list">
      <?php foreach ($pages as $sl => $pg): ?>
        <a class="page-list-item <?= $sl === $current['slug'] ? 'active' : '' ?>" href="/admin/pages?slug=<?= e($sl) ?>">
          <div>
            <div class="strong"><?= e($pg['title']) ?></div>
            <div class="help"><code>/<?= e($sl) ?></code> · <?= e($pg['updated']) ?></div>
          </div>
          <span class="tag <?= $pg['published'] ? 'published' : 'draft' ?>"><?= $pg['published'] ? 'Publicada' : 'Borrador' ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </aside>

  <!-- Editor + Inspector -->
  <div class="span-9 col" style="gap:24px">
    <div class="card card-lg">
      <div class="field-row">
        <div class="field" style="flex:2"><label class="label">Título de la página</label><input class="input" id="pg_title" value="<?= e($current['title']) ?>"></div>
        <div class="field" style="flex:1"><label class="label">Slug (URL)</label>
          <div class="input-affix"><span><?= e(kp_setting('instance_domain', $_SERVER['HTTP_HOST'] ?? '')) ?>/</span><input class="input" id="pg_slug" value="<?= e($current['slug']) ?>" pattern="[a-z0-9\-]+" style="font-family:ui-monospace,monospace"></div>
        </div>
        <div class="field" style="flex:0 0 170px">
          <label class="label">Aparece en</label>
          <select class="input" id="pg_menu">
            <?php $cur = $current['menu'] ?? 'header'; foreach (['header'=>'Header','footer'=>'Footer','legal'=>'Footer · Legal','none'=>'Oculta del menú'] as $k=>$lb): ?>
              <option value="<?= $k ?>" <?= $cur===$k?'selected':'' ?>><?= $lb ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field" style="flex:0 0 150px;align-self:flex-end">
          <label class="toggle-row" style="padding:10px 14px"><span style="font-size:13.5px"><strong>Publicada</strong></span><label class="toggle"><input type="checkbox" id="pg_pub" <?= $current['published'] ? 'checked' : '' ?>><span class="toggle-track"></span></label></label>
        </div>
      </div>
    </div>

    <div class="card card-lg" style="padding:0;overflow:hidden">
      <header style="padding: 14px 20px 0; border-bottom: 1px solid var(--border);">
        <div class="tab-strip md-tabs" style="margin-bottom: 0;">
          <button type="button" class="tab active" data-md="edit">Editar</button>
          <button type="button" class="tab" data-md="preview">Vista previa</button>
          <button type="button" class="tab" data-md="split">Dividida</button>
        </div>
      </header>
      <div class="md-toolbar" style="padding: 10px 20px; border-bottom: 1px solid var(--border); display: flex; gap: 8px;">
        <button class="btn btn-sm btn-ghost" type="button" data-md-cmd="bold"><strong>B</strong></button>
        <button class="btn btn-sm btn-ghost" type="button" data-md-cmd="italic"><em>I</em></button>
        <button class="btn btn-sm btn-ghost" type="button" data-md-cmd="h2">H2</button>
        <button class="btn btn-sm btn-ghost" type="button" data-md-cmd="link">🔗</button>
        <button class="btn btn-sm btn-ghost" type="button" data-md-cmd="list">• lista</button>
        <button class="btn btn-sm btn-ghost" type="button" data-md-cmd="quote">❝</button>
        <button class="btn btn-sm btn-ghost" type="button" data-md-cmd="code">{ }</button>
      </div>
      <div class="md-editor" style="display: flex; min-height: 400px;">
        <textarea class="textarea md-source" id="pg_content" style="flex:1; border:none; border-radius:0; resize:none; padding: 20px; outline:none;" placeholder="Escribe aquí el contenido de la página. Puedes usar Markdown."><?= e($current['content_md'] ?? '') ?></textarea>
        <div class="md-preview prose" style="flex:1; padding: 20px; overflow-y:auto; border-left:1px solid var(--border);" hidden></div>
      </div>
      <div style="padding: 10px 20px; border-top: 1px solid var(--border); background: var(--surface-2);">
        <span class="help"><span class="md-count">0</span> caracteres · Soporta Markdown estándar.</span>
      </div>
    </div>
  </div>
</div>

<form method="POST" id="pg_form" hidden>
  <?= kp_csrf_field() ?>
  <input type="hidden" name="_action" id="pg_action" value="save">
  <input type="hidden" name="payload" id="pg_payload">
  <input type="hidden" name="slug" value="<?= e($current['slug']) ?>">
</form>

<script>
window.KP_PAGES = (function () {
  const initial = <?= json_encode($current, JSON_UNESCAPED_UNICODE) ?>;
  const state = JSON.parse(JSON.stringify(initial));
  
  const src = document.getElementById('pg_content');
  const prev = document.querySelector('.md-preview');
  const count = document.querySelector('.md-count');
  
  function mdRender(md) {
    if (!md) return '<p class="muted">Aún no has escrito nada.</p>';
    let h = md;
    h = h.replace(/^### (.+)$/gm,'<h3>$1</h3>')
         .replace(/^## (.+)$/gm,'<h2>$1</h2>')
         .replace(/^# (.+)$/gm,'<h1>$1</h1>')
         .replace(/\*\*(.+?)\*\*/g,'<strong>$1</strong>')
         .replace(/\*(.+?)\*/g,'<em>$1</em>')
         .replace(/`([^`]+)`/g,'<code>$1</code>')
         .replace(/!\[(.*?)\]\((.*?)\)/g,'<img src="$2" alt="$1" style="max-width:100%;border-radius:8px">')
         .replace(/\[(.+?)\]\((.+?)\)/g,'<a href="$2" target="_blank">$1</a>')
         .replace(/^&gt; (.+)$/gm,'<blockquote>$1</blockquote>')
         .replace(/^- (.+)$/gm,'<li>$1</li>')
         .replace(/(<li>.*<\/li>)/s,'<ul>$1</ul>')
         .replace(/^---$/gm,'<hr>')
         .replace(/\n\n+/g,'</p><p>');
    return '<p>'+h+'</p>';
  }
  
  function refreshPreview() {
    prev.innerHTML = mdRender(src.value);
    count.textContent = src.value.length;
  }
  src && src.addEventListener('input', refreshPreview);
  
  document.querySelectorAll('.md-tabs .tab').forEach(t => t.addEventListener('click', () => {
    document.querySelectorAll('.md-tabs .tab').forEach(x => x.classList.remove('active'));
    t.classList.add('active');
    const mode = t.dataset.md;
    src.hidden = mode === 'preview';
    prev.hidden = mode === 'edit';
    if (mode === 'split') {
        src.hidden = false; prev.hidden = false;
        document.querySelector('.md-editor').style.flexDirection = 'row';
    } else {
        document.querySelector('.md-editor').style.flexDirection = 'column';
    }
    if (mode !== 'edit') refreshPreview();
  }));

  document.querySelectorAll('[data-md-cmd]').forEach(b => b.addEventListener('click', () => {
    const wraps = { bold:['**','**'], italic:['*','*'], code:['`','`'] };
    const lines = { h2:'## ', list:'- ', quote:'> ' };
    const sel = src.value.substring(src.selectionStart, src.selectionEnd);
    const c = b.dataset.mdCmd;
    let insert;
    if (wraps[c]) insert = wraps[c][0] + (sel||'texto') + wraps[c][1];
    else if (lines[c]) insert = lines[c] + (sel||'');
    else if (c === 'link') insert = `[${sel||'texto'}](https://)`;
    const start = src.selectionStart;
    src.value = src.value.slice(0,start) + insert + src.value.slice(src.selectionEnd);
    src.focus();
    src.setSelectionRange(start + insert.length, start + insert.length);
    refreshPreview();
  }));

  function save() {
    state.title = document.getElementById('pg_title').value;
    state.slug = document.getElementById('pg_slug').value;
    state.published = document.getElementById('pg_pub').checked;
    state.menu = document.getElementById('pg_menu').value;
    state.content_md = src.value;
    state.blocks = []; // Se limpia el formato viejo
    document.getElementById('pg_payload').value = JSON.stringify(state);
    document.getElementById('pg_form').submit();
  }

  function create() {
    const slug = prompt('Slug de la nueva página (a-z, 0-9, -):');
    if (!slug) return;
    const blank = { 
        slug: slug.toLowerCase().replace(/[^a-z0-9\-]/g,''), 
        title: 'Nueva página', 
        published: false, 
        content_md: '# Nueva página\n\nEscribe aquí...' 
    };
    document.getElementById('pg_payload').value = JSON.stringify(blank);
    document.getElementById('pg_form').submit();
  }

  function del() {
    if (!confirm('¿Estás seguro de que quieres eliminar esta página de forma permanente?')) return;
    document.getElementById('pg_action').value = 'delete';
    document.getElementById('pg_form').submit();
  }

  refreshPreview();
  return { save, create, del };
})();
</script>
