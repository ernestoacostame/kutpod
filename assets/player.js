/* Sticky audio player para el sitio público (kutpod.io) */
(() => {
  const audio = new Audio();
  let state = { ep: null, podcast: null, playing: false, speed: 1 };

  function $(s, r = document) { return r.querySelector(s); }
  function fmt(sec) { return Math.floor(sec/60) + ':' + String(Math.floor(sec%60)).padStart(2,'0'); }

  function ensurePlayerDOM() {
    if ($('.pub-player')) return;
    const el = document.createElement('div');
    el.className = 'pub-player';
    el.innerHTML = `<div class="pub-player-inner">
      <div class="pub-player-meta">
        <div class="pub-player-cover" id="kpCover"></div>
        <div style="min-width:0"><div class="pub-player-title" id="kpTitle"></div><div class="pub-player-sub" id="kpSub"></div></div>
      </div>
      <div class="pub-player-controls">
        <div class="row" style="gap:16px;justify-content:center;margin-bottom:8px">
          <button id="kpBack" class="pub-player-btn-s">← 15</button>
          <button id="kpPlay" class="pub-player-btn-play">▶</button>
          <button id="kpFwd" class="pub-player-btn-s">30 →</button>
        </div>
        <div class="row" style="gap:10px;align-items:center">
          <span id="kpCur" class="tabular" style="font-size:11px;color:var(--pub-muted);min-width:36px;text-align:right">0:00</span>
          <div class="pub-scrubber" id="kpScrub"><div class="pub-scrubber-fill" id="kpFill"></div><div class="pub-scrubber-thumb" id="kpThumb"></div></div>
          <span id="kpDur" class="tabular" style="font-size:11px;color:var(--pub-muted);min-width:36px">0:00</span>
        </div>
      </div>
      <div class="pub-player-extras">
        <div class="row" style="gap:6px;align-items:center;margin-right:10px">
          <button id="kpVolBtn" class="pub-player-chip" style="padding: 5px 8px; min-width: 32px">🔊</button>
          <input type="range" id="kpVolume" class="pub-volume" min="0" max="1" step="0.05" value="1">
        </div>
        <select id="kpSpeed" class="pub-player-chip" style="appearance:none;padding-right:10px;text-align:center;outline:none">
          <option value="0.75">0.75×</option>
          <option value="1" selected>1×</option>
          <option value="1.25">1.25×</option>
          <option value="1.5">1.5×</option>
          <option value="2">2×</option>
        </select>
        <button id="kpClose" class="pub-player-chip">×</button>
      </div>
    </div>`;
    document.body.appendChild(el);

    $('#kpPlay').onclick = () => { if (audio.paused) audio.play(); else audio.pause(); };
    $('#kpBack').onclick = () => { audio.currentTime = Math.max(0, audio.currentTime - 15); };
    $('#kpFwd').onclick  = () => { audio.currentTime = Math.min(audio.duration||0, audio.currentTime + 30); };
    $('#kpSpeed').onchange = (e) => {
      state.speed = parseFloat(e.target.value);
      audio.playbackRate = state.speed;
    };
    $('#kpClose').onclick = () => { audio.pause(); el.remove(); };

    let lastVolume = 1;
    const volBtn = $('#kpVolBtn');
    const volSlider = $('#kpVolume');

    const updateVolumeUI = (vol) => {
      volSlider.value = vol;
      const pct = vol * 100;
      volSlider.style.background = `linear-gradient(to right, var(--accent) 0%, var(--accent) ${pct}%, color-mix(in srgb, var(--pub-text) 15%, transparent) ${pct}%, color-mix(in srgb, var(--pub-text) 15%, transparent) 100%)`;
      if (vol === 0) {
        volBtn.textContent = '🔇';
      } else if (vol < 0.5) {
        volBtn.textContent = '🔉';
      } else {
        volBtn.textContent = '🔊';
      }
    };

    updateVolumeUI(audio.volume);

    volBtn.onclick = () => {
      if (audio.volume > 0) {
        lastVolume = audio.volume;
        audio.volume = 0;
        updateVolumeUI(0);
      } else {
        audio.volume = lastVolume > 0 ? lastVolume : 1;
        updateVolumeUI(audio.volume);
      }
    };

    volSlider.oninput = (e) => {
      const vol = parseFloat(e.target.value);
      audio.volume = vol;
      if (vol > 0) {
        lastVolume = vol;
      }
      updateVolumeUI(vol);
    };
    
    let scrubDragging = false;
    const scrubEl = $('#kpScrub');
    const updateScrub = (e) => {
      const r = scrubEl.getBoundingClientRect();
      let t = (e.clientX - r.left) / r.width;
      t = Math.max(0, Math.min(1, t));
      if (audio.duration) audio.currentTime = t * audio.duration;
    };
    scrubEl.addEventListener('pointerdown', (e) => {
      scrubDragging = true;
      scrubEl.setPointerCapture(e.pointerId);
      updateScrub(e);
    });
    scrubEl.addEventListener('pointermove', (e) => {
      if (scrubDragging) updateScrub(e);
    });
    scrubEl.addEventListener('pointerup', (e) => {
      scrubDragging = false;
      try { scrubEl.releasePointerCapture(e.pointerId); } catch(err){}
    });
    scrubEl.addEventListener('pointercancel', () => { scrubDragging = false; });

    audio.onplay = () => $('#kpPlay').textContent = '❚❚';
    audio.onpause = () => $('#kpPlay').textContent = '▶';
    audio.ontimeupdate = () => {
      const pct = audio.duration ? (audio.currentTime / audio.duration) * 100 : 0;
      $('#kpFill').style.width = pct + '%';
      $('#kpThumb').style.left = pct + '%';
      $('#kpCur').textContent = fmt(audio.currentTime);
      if (audio.duration) $('#kpDur').textContent = fmt(audio.duration);
    };
  }

  // Cualquier <button data-kp-play data-src data-title data-podcast data-color data-initial> dispara el player.
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-kp-play]');
    if (!btn) return;
    e.preventDefault();
    ensurePlayerDOM();
    audio.src = btn.dataset.src;
    audio.play();
    $('#kpTitle').textContent = btn.dataset.title || '';
    $('#kpSub').textContent = btn.dataset.podcast || '';
    const c = $('#kpCover');
    if (btn.dataset.cover) {
      c.style.backgroundImage = `url("${btn.dataset.cover}")`;
      c.style.backgroundSize = 'cover';
      c.style.backgroundPosition = 'center';
      c.textContent = '';
      c.style.display = 'block';
    } else {
      c.style.background = `linear-gradient(135deg, ${btn.dataset.color || '#ff5f7e'}, ${(btn.dataset.color || '#ff5f7e')}99)`;
      c.textContent = btn.dataset.initial || '·';
      c.style.color = '#fff'; c.style.fontWeight = '700'; c.style.fontSize = '14px';
      c.style.display = 'grid'; c.style.placeItems = 'center';
    }
    const color = btn.dataset.color || 'var(--accent)';
    $('#kpPlay').style.background = color;
    $('#kpPlay').style.color = '#fff';
    $('#kpClose').style.background = color;
    $('#kpClose').style.color = '#fff';
    $('#kpClose').style.borderColor = color;
  });

  // Capítulo click -> saltar tiempo
  document.addEventListener('click', (e) => {
    const chap = e.target.closest('[data-time]');
    if (!chap) return;
    const time = parseFloat(chap.dataset.time);
    if (!isNaN(time) && audio.src) {
        audio.currentTime = time;
        if (audio.paused) audio.play();
    }
  });

  // Motor de navegación PJAX (SPA) para mantener el reproductor sonando
  document.addEventListener('click', async (e) => {
    const a = e.target.closest('a');
    if (!a || !a.href || a.origin !== location.origin || a.hasAttribute('download') || a.target) return;
    
    // Ignorar rutas del Studio (admin) o rutas de raw/feed
    if (a.pathname.startsWith('/admin') || a.pathname.startsWith('/r/') || a.pathname.endsWith('.xml')) return;

    e.preventDefault();
    try {
      const res = await fetch(a.href);
      if (!res.ok) { location.href = a.href; return; }
      const html = await res.text();
      const doc = new DOMParser().parseFromString(html, 'text/html');
      
      const newBody = doc.querySelector('.pub-body');
      if (newBody) {
        document.querySelector('.pub-body').innerHTML = newBody.innerHTML;
        document.title = doc.title;
        history.pushState({}, '', a.href);
        const root = document.querySelector('.pub-root');
        if (root) root.scrollTop = 0;
        else window.scrollTo(0, 0);
      } else {
        location.href = a.href;
      }
    } catch (err) {
      location.href = a.href;
    }
  });

  window.addEventListener('popstate', () => {
    location.reload();
  });
})();
