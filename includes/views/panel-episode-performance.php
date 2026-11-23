<?php
// ============================================================================
// KutPod · Panel de Rendimiento Comparativo de Episodios (Episode Performance)
// ============================================================================
// Visualización interactiva inspirada en Apple Podcasts Connect con alineación en
// Día 0, curvas de retención/despegue, línea de mediana y comparación porcentual.
// ============================================================================

declare(strict_types=1);

$perfPodId  = (int)($_GET['perf_podcast'] ?? 0);
$perfLimit  = (int)($_GET['perf_limit'] ?? 50);
$perfDays   = (int)($_GET['perf_days'] ?? 60);
$perfMode   = ($_GET['perf_mode'] ?? 'median') === 'average' ? 'average' : 'median';

if (!in_array($perfLimit, [10, 25, 50, 100, 200], true)) $perfLimit = 50;
if (!in_array($perfDays, [7, 14, 30, 60, 90], true)) $perfDays = 60;

$perfData = kp_op3_get_performance_data($perfPodId, $perfLimit, $perfDays, $perfMode);
$allPods  = kp_q("SELECT id, title FROM podcasts WHERE hidden = 0 ORDER BY title ASC") ?: [];

$episodesList = $perfData['episodes'];
$medianCurve  = $perfData['median_curve'];
$maxVal       = $perfData['max_val'];
$maxDays      = $perfData['max_days'];

// Dimensiones SVG
$svgW = 920;
$svgH = 340;
$padL = 50;
$padR = 70;
$padT = 25;
$padB = 40;
$plotW = $svgW - $padL - $padR;
$plotH = $svgH - $padT - $padB;

$xScale = function(int $day) use ($plotW, $maxDays, $padL): float {
    return $padL + (($day / max(1, $maxDays)) * $plotW);
};
$yScale = function(float $val) use ($plotH, $maxVal, $padT): float {
    return $padT + $plotH - (($val / max(1, $maxVal)) * $plotH);
};

// Generar path SVG para la mediana
$medianPath = '';
for ($d = 0; $d <= $maxDays; $d++) {
    $x = $xScale($d);
    $y = $yScale((float)$medianCurve[$d]);
    $medianPath .= ($d === 0 ? "M $x $y" : " L $x $y");
}
?>

<div id="panel-performance" class="stats-tab-panel <?= $active_tab === 'performance' ? 'active' : '' ?>">

  <!-- Encabezado y Barra de Filtros estilo Apple Podcasts Connect -->
  <div class="row" style="justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:12px">
    <div>
      <h2 style="margin:0;font-size:18px;font-weight:700;display:flex;align-items:center;gap:8px">
        <span><?= icon('activity', 20) ?></span>
        <span><?= __('analytics_perf_title', 'Rendimiento de Episodios') ?></span>
      </h2>
      <p class="muted" style="margin:4px 0 0;font-size:12.5px">
        <?= __('analytics_perf_desc', 'Alinea los episodios en el Día 0 de su lanzamiento para comparar velocidad de audiencia respecto a la mediana habitual.') ?>
      </p>
    </div>

    <!-- Filtros superiores -->
    <div class="row" style="gap:8px;flex-wrap:wrap;align-items:center">
      <?php if (count($allPods) > 1): ?>
        <select class="select" style="font-size:12px;padding:5px 10px;height:auto" onchange="updatePerfFilter('perf_podcast', this.value)">
          <option value="0" <?= $perfPodId === 0 ? 'selected' : '' ?>><?= __('pod_all_shows', 'Todos los podcasts') ?></option>
          <?php foreach ($allPods as $pod): ?>
            <option value="<?= (int)$pod['id'] ?>" <?= $perfPodId === (int)$pod['id'] ? 'selected' : '' ?>><?= e($pod['title']) ?></option>
          <?php endforeach; ?>
        </select>
      <?php endif; ?>

      <select class="select" style="font-size:12px;padding:5px 10px;height:auto" onchange="updatePerfFilter('perf_limit', this.value)">
        <option value="25" <?= $perfLimit === 25 ? 'selected' : '' ?>>Últimos 25 episodios</option>
        <option value="50" <?= $perfLimit === 50 ? 'selected' : '' ?>>Últimos 50 episodios</option>
        <option value="100" <?= $perfLimit === 100 ? 'selected' : '' ?>>Últimos 100 episodios</option>
        <option value="200" <?= $perfLimit === 200 ? 'selected' : '' ?>>Últimos 200 episodios</option>
      </select>

      <select class="select" style="font-size:12px;padding:5px 10px;height:auto" onchange="updatePerfFilter('perf_days', this.value)">
        <option value="7" <?= $perfDays === 7 ? 'selected' : '' ?>>7 días tras lanzamiento</option>
        <option value="30" <?= $perfDays === 30 ? 'selected' : '' ?>>30 días tras lanzamiento</option>
        <option value="60" <?= $perfDays === 60 ? 'selected' : '' ?>>60 días tras lanzamiento</option>
        <option value="90" <?= $perfDays === 90 ? 'selected' : '' ?>>90 días tras lanzamiento</option>
      </select>

      <select class="select" style="font-size:12px;padding:5px 10px;height:auto" onchange="updatePerfFilter('perf_mode', this.value)">
        <option value="median" <?= $perfMode === 'median' ? 'selected' : '' ?>><?= __('analytics_compare_median', 'Comparar con la Mediana') ?></option>
        <option value="average" <?= $perfMode === 'average' ? 'selected' : '' ?>><?= __('analytics_compare_avg', 'Comparar con el Promedio') ?></option>
      </select>
    </div>
  </div>

  <?php if (empty($episodesList)): ?>
    <div class="card card-lg" style="text-align:center;padding:48px 20px">
      <div style="opacity:.4;margin-bottom:12px"><?= icon('chart', 40) ?></div>
      <h3 style="margin:0 0 8px"><?= __('analytics_no_perf_data', 'No hay episodios con suficientes datos para graficar') ?></h3>
      <p class="muted" style="margin:0;font-size:13px">Publica nuevos episodios o importa reportes de Apple/Spotify para activar la comparativa.</p>
    </div>
  <?php else: ?>

    <!-- Contenedor del Gráfico Interactivo de Cohortes -->
    <div class="card card-lg" style="padding:20px;position:relative;overflow:hidden;background:var(--surface)">
      <div style="position:relative;width:100%;user-select:none" id="perf-chart-container">

        <svg viewBox="0 0 <?= $svgW ?> <?= $svgH ?>" style="width:100%;height:auto;display:block;overflow:visible" id="perf-svg">
          <defs>
            <linearGradient id="perf-grid-grad" x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stop-color="var(--border)" stop-opacity="0.3"/>
              <stop offset="100%" stop-color="var(--border)" stop-opacity="0.05"/>
            </linearGradient>
          </defs>

          <!-- Cuadrícula horizontal del eje Y -->
          <?php
            $ySteps = 4;
            for ($s = 0; $s <= $ySteps; $s++):
              $tickVal = (int)round(($maxVal / $ySteps) * $s);
              $tickY = $yScale((float)$tickVal);
          ?>
            <line x1="<?= $padL ?>" y1="<?= $tickY ?>" x2="<?= $svgW - $padR ?>" y2="<?= $tickY ?>" stroke="var(--border)" stroke-width="1" stroke-dasharray="<?= $s > 0 ? '3,3' : '0' ?>" opacity="0.4"/>
            <text x="<?= $padL - 10 ?>" y="<?= $tickY + 4 ?>" font-size="11" fill="var(--text-3)" text-anchor="end" font-family="Inter, system-ui, sans-serif"><?= number_format($tickVal) ?></text>
          <?php endfor; ?>

          <!-- Líneas y etiquetas del eje X (Días) -->
          <?php
            $dayInterval = ($maxDays <= 7) ? 1 : (($maxDays <= 30) ? 5 : 5);
            for ($d = 0; $d <= $maxDays; $d += $dayInterval):
              $tickX = $xScale($d);
          ?>
            <line x1="<?= $tickX ?>" y1="<?= $padT ?>" x2="<?= $tickX ?>" y2="<?= $padT + $plotH ?>" stroke="var(--border)" stroke-width="1" stroke-dasharray="2,4" opacity="0.25"/>
            <text x="<?= $tickX ?>" y="<?= $padT + $plotH + 20 ?>" font-size="11" fill="var(--text-3)" text-anchor="middle" font-family="Inter, system-ui, sans-serif"><?= $d ?></text>
          <?php endfor; ?>
          <text x="<?= $padL + ($plotW / 2) ?>" y="<?= $padT + $plotH + 34 ?>" font-size="11" fill="var(--text-3)" text-anchor="middle" font-weight="600"><?= __('analytics_perf_xaxis', 'Días tras lanzamiento') ?></text>

          <!-- Línea de la Mediana (Dashed line) -->
          <path id="perf-median-line" d="<?= $medianPath ?>" fill="none" stroke="var(--text-3)" stroke-width="2" stroke-dasharray="6,5" opacity="0.75"/>
          <text x="<?= $svgW - $padR + 8 ?>" y="<?= $yScale((float)$medianCurve[$maxDays]) + 4 ?>" font-size="11" fill="var(--text-3)" font-weight="600"><?= ucfirst($perfMode === 'average' ? 'Promedio' : 'Mediana') ?></text>

          <!-- Curvas de cada episodio -->
          <?php foreach ($episodesList as $ep):
            $dPath = '';
            $pts = [];
            for ($d = 0; $d <= $maxDays; $d++) {
              $x = $xScale($d);
              $y = $yScale((float)$ep['curve'][$d]);
              $dPath .= ($d === 0 ? "M $x $y" : " L $x $y");
              $pts[] = ['x' => $x, 'y' => $y, 'val' => $ep['curve'][$d]];
            }
          ?>
            <path id="curve-ep-<?= $ep['id'] ?>" class="perf-ep-curve" data-epid="<?= $ep['id'] ?>"
                  d="<?= $dPath ?>" fill="none" stroke="<?= e($ep['color']) ?>" stroke-width="2.6"
                  stroke-linecap="round" stroke-linejoin="round"
                  style="transition: opacity 0.2s, stroke-width 0.2s; display: <?= $ep['is_checked'] ? 'inline' : 'none' ?>"/>
          <?php endforeach; ?>

          <!-- Línea guía vertical interactiva para hover -->
          <line id="perf-crosshair" x1="0" y1="<?= $padT ?>" x2="0" y2="<?= $padT + $plotH ?>" stroke="var(--accent)" stroke-width="1.5" stroke-dasharray="3,3" style="display:none;pointer-events:none"/>

          <!-- Área transparente receptora de eventos de ratón -->
          <rect id="perf-hit-area" x="<?= $padL ?>" y="<?= $padT ?>" width="<?= $plotW ?>" height="<?= $plotH ?>" fill="transparent" style="cursor:crosshair"/>
        </svg>

        <!-- Tooltip flotante interactivo (estilo Apple Podcasts Connect) -->
        <div id="perf-tooltip" style="position:absolute;display:none;pointer-events:none;background:rgba(18,18,22,0.95);border:1px solid var(--border);border-radius:10px;padding:12px 14px;box-shadow:0 12px 30px rgba(0,0,0,0.6);font-size:12px;z-index:20;min-width:240px;max-width:320px;backdrop-filter:blur(8px)">
          <div id="perf-tooltip-head" style="font-weight:700;color:var(--text-1);margin-bottom:8px;border-bottom:1px solid var(--border);padding-bottom:4px;font-size:12.5px"></div>
          <div id="perf-tooltip-list" style="display:flex;flex-direction:column;gap:6px"></div>
        </div>

      </div>
    </div>

    <!-- Tabla de Episodios con Selector de Colores y Variación contra la Mediana -->
    <div class="card card-lg" style="margin-top:20px;padding:0;overflow:hidden">
      <div style="padding:14px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:8px">
        <div style="font-size:14px;font-weight:600"><?= __('analytics_perf_ep_list', 'Episodios y Desempeño Relativo') ?> (<?= count($episodesList) ?>)</div>
        <div style="font-size:12px;color:var(--text-3)">Haz clic en el círculo de color para comparar episodios en el gráfico</div>
      </div>

      <div style="overflow-x:auto">
        <table style="width:100%;border-collapse:collapse;font-size:12.5px" id="perf-table">
          <thead>
            <tr style="border-bottom:1px solid var(--border);color:var(--text-3);font-size:11px;text-transform:uppercase;letter-spacing:0.06em">
              <th style="padding:10px 14px;width:40px;text-align:center"></th>
              <th style="padding:10px 14px;width:90px">Número</th>
              <th style="padding:10px 14px">Nombre</th>
              <th style="padding:10px 14px;width:120px">Lanzamiento</th>
              <th style="padding:10px 14px;text-align:right;width:100px">Oyentes</th>
              <th style="padding:10px 14px;text-align:right;width:110px">Variación (%)</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($episodesList as $ep):
              $chg = (int)$ep['change_pct'];
              $chgClass = ($chg > 0) ? 'color:#22c55e' : (($chg < 0) ? 'color:#ef4444' : 'color:var(--text-3)');
              $chgStr = ($chg > 0 ? "+{$chg}%" : "{$chg}%");
            ?>
              <tr class="perf-row" data-epid="<?= $ep['id'] ?>" style="border-bottom:1px solid var(--border);cursor:pointer;transition:background 0.15s" onclick="togglePerfEpisode(<?= $ep['id'] ?>)">
                <td style="padding:10px 14px;text-align:center">
                  <!-- Checkbox circular con color del episodio -->
                  <div class="perf-chk" id="chk-ep-<?= $ep['id'] ?>"
                       style="width:18px;height:18px;border-radius:50%;margin:auto;display:flex;align-items:center;justify-content:center;transition:all 0.15s;<?= $ep['is_checked'] ? 'background:' . e($ep['color']) . ';border:2px solid ' . e($ep['color']) : 'border:2px solid var(--text-3);background:transparent' ?>">
                    <span class="perf-chk-icon" style="color:#fff;font-size:10px;font-weight:900;display:<?= $ep['is_checked'] ? 'inline' : 'none' ?>">✓</span>
                  </div>
                </td>
                <td style="padding:10px 14px;font-family:monospace;font-size:12px;color:var(--text-2)">
                  <?= e($ep['num_str']) ?>
                </td>
                <td style="padding:10px 14px;font-weight:500">
                  <a href="/admin/new-episode?id=<?= urlencode((string)$ep['id']) ?>" onclick="event.stopPropagation()" style="color:inherit;text-decoration:none" onmouseover="this.style.textDecoration='underline'" onmouseout="this.style.textDecoration='none'">
                    <?= e($ep['title']) ?>
                  </a>
                </td>
                <td style="padding:10px 14px;color:var(--text-3);font-size:12px">
                  <?= e($ep['pub_date_fmt']) ?>
                </td>
                <td class="tabular" style="padding:10px 14px;text-align:right;font-weight:600">
                  <?= number_format((int)$ep['current_value']) ?>
                </td>
                <td class="tabular" style="padding:10px 14px;text-align:right;font-weight:700;font-size:12.5px;<?= $chgClass ?>">
                  <?= $chgStr ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Script de interacción y Tooltip -->
    <script>
    const PERF_DATA = <?= json_encode([
      'episodes'    => $episodesList,
      'medianCurve' => $medianCurve,
      'maxDays'     => $maxDays,
      'maxVal'      => $maxVal,
      'svgW'        => $svgW,
      'svgH'        => $svgH,
      'padL'        => $padL,
      'padR'        => $padR,
      'padT'        => $padT,
      'padB'        => $padB,
      'plotW'       => $plotW,
      'plotH'       => $plotH,
      'perfMode'    => $perfMode
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    const activeEpIds = new Set(PERF_DATA.episodes.filter(e => e.is_checked).map(e => e.id));

    function togglePerfEpisode(id) {
      const chk = document.getElementById(`chk-ep-${id}`);
      const curve = document.getElementById(`curve-ep-${id}`);
      const ep = PERF_DATA.episodes.find(e => e.id === id);
      if (!chk || !curve || !ep) return;

      if (activeEpIds.has(id)) {
        activeEpIds.delete(id);
        curve.style.display = 'none';
        chk.style.background = 'transparent';
        chk.style.borderColor = 'var(--text-3)';
        chk.querySelector('.perf-chk-icon').style.display = 'none';
      } else {
        activeEpIds.add(id);
        curve.style.display = 'inline';
        chk.style.background = ep.color;
        chk.style.borderColor = ep.color;
        chk.querySelector('.perf-chk-icon').style.display = 'inline';
      }
    }

    function updatePerfFilter(param, val) {
      const url = new URL(window.location.href);
      url.searchParams.set('tab', 'performance');
      url.searchParams.set(param, val);
      window.location.href = url.toString();
    }

    (function initPerfChartInteractivity() {
      const hitArea = document.getElementById('perf-hit-area');
      const crosshair = document.getElementById('perf-crosshair');
      const tooltip = document.getElementById('perf-tooltip');
      const tooltipHead = document.getElementById('perf-tooltip-head');
      const tooltipList = document.getElementById('perf-tooltip-list');
      const container = document.getElementById('perf-chart-container');
      const svg = document.getElementById('perf-svg');

      if (!hitArea || !crosshair || !tooltip || !svg) return;

      hitArea.addEventListener('mousemove', (e) => {
        const rect = svg.getBoundingClientRect();
        const mouseX = e.clientX - rect.left;
        const svgScaleX = PERF_DATA.svgW / rect.width;
        const curSvgX = mouseX * svgScaleX;

        const relX = curSvgX - PERF_DATA.padL;
        const dayFrac = (relX / PERF_DATA.plotW) * PERF_DATA.maxDays;
        const day = Math.max(0, Math.min(PERF_DATA.maxDays, Math.round(dayFrac)));

        const crosshairX = PERF_DATA.padL + ((day / PERF_DATA.maxDays) * PERF_DATA.plotW);
        crosshair.setAttribute('x1', crosshairX);
        crosshair.setAttribute('x2', crosshairX);
        crosshair.style.display = 'inline';

        const medianVal = PERF_DATA.medianCurve[day] || 1;
        tooltipHead.textContent = `${day} días tras lanzamiento`;

        const activeEps = PERF_DATA.episodes
          .filter(ep => activeEpIds.has(ep.id))
          .map(ep => {
            const val = ep.curve[day] || 0;
            const chg = medianVal > 0 ? Math.round(((val - medianVal) / medianVal) * 100) : 0;
            return { title: ep.title, color: ep.color, val, chg };
          })
          .sort((a, b) => b.val - a.val);

        let listHtml = '';
        activeEps.forEach(item => {
          const chgStr = item.chg > 0 ? `+${item.chg}%` : `${item.chg}%`;
          const chgColor = item.chg > 0 ? '#22c55e' : (item.chg < 0 ? '#ef4444' : 'var(--text-3)');
          listHtml += `
            <div style="display:flex;align-items:center;justify-content:space-between;gap:8px">
              <div style="display:flex;align-items:center;gap:6px;min-width:0;flex:1">
                <span style="width:8px;height:8px;border-radius:50%;background:${item.color};flex-shrink:0"></span>
                <span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:11.5px;color:var(--text-1)">${escapeHtml(item.title)}</span>
              </div>
              <div style="display:flex;align-items:center;gap:6px;font-family:monospace;font-size:11px">
                <strong>${item.val.toLocaleString()}</strong>
                <span style="color:${chgColor};min-width:38px;text-align:right">${chgStr}</span>
              </div>
            </div>
          `;
        });

        // Fila de mediana
        listHtml += `
          <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-top:4px;padding-top:4px;border-top:1px dashed var(--border);color:var(--text-3);font-size:11px">
            <span style="font-weight:600">Mediana de referencia:</span>
            <span style="font-family:monospace;font-weight:700">${medianVal.toLocaleString()}</span>
          </div>
        `;

        tooltipList.innerHTML = listHtml;
        tooltip.style.display = 'block';

        // Posicionar tooltip inteligentemente
        const clientXRel = e.clientX - container.getBoundingClientRect().left;
        const clientYRel = e.clientY - container.getBoundingClientRect().top;
        if (clientXRel > container.offsetWidth / 2) {
          tooltip.style.left = `${clientXRel - tooltip.offsetWidth - 15}px`;
        } else {
          tooltip.style.left = `${clientXRel + 15}px`;
        }
        tooltip.style.top = `${Math.max(10, clientYRel - 40)}px`;
      });

      hitArea.addEventListener('mouseleave', () => {
        crosshair.style.display = 'none';
        tooltip.style.display = 'none';
      });

      function escapeHtml(str) {
        return (str || '').replace(/[&<>"']/g, m => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[m]));
      }
    })();
    </script>

  <?php endif; ?>

</div>
