/* Theme toggle persistence (mirrors PHP cookie) + tweaks panel niceties */
(() => {
  // No tenemos React: si el server-side ya pintó data-theme, respétalo.
  // Atajos de teclado básicos: ⌘K abre el buscador.
  const searchInput = document.querySelector('.search input');
  document.addEventListener('keydown', (e) => {
    if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
      e.preventDefault();
      if (searchInput) searchInput.focus();
    }
  });

  // Búsqueda en vivo global (filtra listas)
  if (searchInput) {
    searchInput.addEventListener('input', (e) => {
      const q = e.target.value.toLowerCase();
      document.querySelectorAll('.list-row, .page-list-item, .list > .row, .list > a.row, .list > div > div > div > [style*="display:grid"]').forEach(row => {
        if (!row.textContent) return;
        row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
      });
    });
  }

  // Toast desde cookie de flash (kp_flash) — se limpia tras mostrar.
  const m = document.cookie.match(/(?:^|;\s*)kp_flash=([^;]+)/);
  if (m) {
    const toast = document.createElement('div');
    toast.className = 'toast';
    toast.textContent = decodeURIComponent(m[1]);
    document.body.appendChild(toast);
    document.cookie = 'kp_flash=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
    setTimeout(() => toast.remove(), 2400);
  }

  // Copiar URL de episodio al portapapeles
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('.admin-copy-url-btn');
    if (!btn) return;
    e.preventDefault();
    const url = btn.dataset.url;
    if (!url) return;
    navigator.clipboard.writeText(url).then(() => {
      const label = btn.querySelector('span');
      if (label) {
        const originalText = label.textContent;
        label.textContent = '¡Copiado!';
        btn.style.color = 'var(--accent)';
        setTimeout(() => {
          label.textContent = originalText;
          btn.style.color = '';
        }, 2000);
      }
    }).catch(err => {
      console.error('Error al copiar: ', err);
    });
  });

})();
