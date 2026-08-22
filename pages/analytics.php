<?php
// ============================================================================
// pages/analytics.php — Estadísticas globales (OP3)
// ============================================================================
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/op3-stats.php';

// Nielsen DMA code → city name lookup
$DMA_NAMES = [
  200=>'New York',201=>'New York',202=>'New York',
  500=>'Portland, OR',501=>'New York',502=>'Binghamton',503=>'Macon',
  504=>'Philadelphia',505=>'Detroit',506=>'Boston',507=>'Savannah',
  508=>'Pittsburgh',509=>'Ft. Wayne',510=>'Cleveland',511=>'Washington DC',
  512=>'Baltimore',513=>'Flint-Saginaw',514=>'Buffalo',515=>'Cincinnati',
  516=>'Erie',517=>'Charlotte',518=>'Greensboro',519=>'Charleston, SC',
  520=>'Augusta',521=>'Providence',522=>'Columbus, GA',523=>'Burlington',
  524=>'Atlanta',525=>'Albany, GA',526=>'Utica',527=>'Indianapolis',
  528=>'Miami',529=>'Louisville',530=>'Tallahassee',531=>'Tri-Cities, TN-VA',
  532=>'Albany-Schenectady',533=>'Hartford',534=>'Orlando',535=>'Columbus, OH',
  536=>'Youngstown',537=>'Bangor',538=>'Rochester, NY',539=>'Tampa-St. Pete',
  540=>'Traverse City',541=>'Lexington',542=>'Dayton',543=>'Springfield, MA',
  544=>'Norfolk',545=>'Greenville-Spartanburg',546=>'Columbia, SC',
  547=>'Toledo',548=>'West Palm Beach',549=>'Watertown',550=>'Wilmington',
  551=>'Lansing',552=>'Presque Isle',553=>'Marquette',554=>'Wheeling',
  555=>'Syracuse',556=>'Richmond',557=>'Knoxville',558=>'Lima',559=>'Bluefield',
  560=>'Raleigh-Durham',561=>'Jacksonville',563=>'Grand Rapids',
  564=>'Charleston, WV',565=>'Elmira',566=>'Harrisburg',567=>'Greenvl-New Bern',
  569=>'Harrisonburg',570=>'Florence-Myrtle Beach',571=>'Ft. Myers',
  573=>'Roanoke',574=>'Johnstown-Altoona',575=>'Chattanooga',576=>'Salisbury',
  577=>'Wilkes-Barre',581=>'Terre Haute',582=>'Lafayette, IN',
  583=>'Alpena',584=>'Charlottesville',588=>'South Bend',592=>'Gainesville',
  596=>'Zanesville',597=>'Parkersburg',598=>'Clarksburg',600=>'Corpus Christi',
  602=>'Chicago',603=>'Joplin',604=>'Columbia-Jefferson City',605=>'Topeka',
  606=>'Dothan',609=>'St. Louis',610=>'Rockford',611=>'Rochester-Mason City',
  612=>'Shreveport',613=>'Minneapolis-St. Paul',616=>'Kansas City',
  617=>'Milwaukee',618=>'Houston',619=>'Springfield, MO',620=>'Tuscaloosa',
  622=>'New Orleans',623=>'Dallas-Ft. Worth',624=>'Sioux City',625=>'Waco-Temple',
  626=>'Victoria',627=>'Wichita Falls',628=>'Monroe-El Dorado',630=>'Birmingham',
  631=>'Ottumwa-Kirksville',632=>'Paducah',633=>'Odessa-Midland',
  634=>'Amarillo',635=>'Austin',636=>'Harlingen-Weslaco',637=>'Cedar Rapids',
  638=>'St. Joseph',639=>'Jackson, TN',640=>'Memphis',641=>'San Antonio',
  642=>'Lafayette, LA',643=>'Lake Charles',644=>'Alexandria',
  647=>'Greenwood-Greenville',648=>'Champaign',649=>'Evansville',
  650=>'Oklahoma City',651=>'Lubbock',652=>'Omaha',656=>'Panama City',
  657=>'Sherman-Ada',658=>'Green Bay',659=>'Nashville',
  661=>'San Angelo',662=>'Abilene-Sweetwater',669=>'Madison',
  670=>'Ft. Smith-Fayetteville',671=>'Tulsa',673=>'Columbus-Tupelo',
  675=>'Peoria-Bloomington',676=>'Duluth-Superior',678=>'Wichita-Hutchinson',
  679=>'Des Moines',682=>'Davenport',686=>'Mobile-Pensacola',
  687=>'Minot-Bismarck',691=>'Huntsville-Decatur',692=>'Beaumont-Port Arthur',
  693=>'Little Rock-Pine Bluff',698=>'Montgomery',702=>'La Crosse-Eau Claire',
  705=>'Wausau-Rhinelander',709=>'Tyler-Longview',710=>'Hattiesburg-Laurel',
  711=>'Meridian',716=>'Baton Rouge',717=>'Quincy-Hannibal',
  718=>'Jackson, MS',722=>'Lincoln-Hastings',724=>'Fargo-Valley City',
  725=>'Sioux Falls',734=>'Jonesboro',736=>'Bowling Green',
  737=>'Knoxville',740=>'North Platte',743=>'Anchorage',
  744=>'Honolulu',745=>'Fairbanks',746=>'Biloxi-Gulfport',
  747=>'Juneau',749=>'Laredo',751=>'Denver',752=>'Colorado Springs',
  753=>'Phoenix',754=>'Butte-Bozeman',755=>'Great Falls',756=>'Billings',
  757=>'Boise',758=>'Idaho Falls-Pocatello',759=>'Cheyenne-Scottsbluff',
  760=>'Twin Falls',762=>'Missoula',764=>'Rapid City',
  765=>'El Paso',766=>'Helena',767=>'Casper-Riverton',770=>'Salt Lake City',
  771=>'Yuma-El Centro',773=>'Grand Junction',789=>'Tucson',790=>'Albuquerque',
  798=>'Glendive',800=>'Bakersfield',801=>'Eugene',802=>'Eureka',
  803=>'Los Angeles',804=>'Palm Springs',807=>'San Francisco',
  810=>'Yakima-Pasco',811=>'Reno',813=>'Medford-Klamath Falls',
  819=>'Seattle-Tacoma',820=>'Portland, OR',821=>'Bend, OR',
  825=>'San Diego',828=>'Monterey-Salinas',839=>'Las Vegas',
  855=>'Santa Barbara',862=>'Sacramento',866=>'Fresno-Visalia',
  868=>'Chico-Redding',881=>'Spokane',
];

function dma_name(string $code, array $map): string {
  return $map[(int)$code] ?? $code;
}

$range = $_GET['range'] ?? '30d';
$valid_ranges = ['7d'=>7, '30d'=>30, 'mtd'=>(int)date('j'), 'all'=>3650];
$days = $valid_ranges[$range] ?? 30;

$cache_key = 'analytics_' . $range;
$cached_data = kp_cache_get($cache_key);

if ($cached_data) {
    extract($cached_data);
} else {
    $podcasts = kp_q("SELECT id, slug, title FROM podcasts ORDER BY title");
    $has_op3 = kp_op3_tables_exist();
    $last_sync = kp_op3_last_sync();

// Totales globales
$tot = 0; $tot_period = 0; $unique_listeners = 0;
$series = []; // dia => downloads
foreach ($podcasts as $p) {
  $st = kp_op3_podcast_totals((int)$p['id'], 9999);
  $stp = kp_op3_podcast_totals((int)$p['id'], $days);
  $tot += $st['downloads']; $tot_period += $stp['downloads'];
  $unique_listeners += $stp['listeners'];
  foreach (kp_op3_podcast_series((int)$p['id'], $days) as $row) {
    $d = $row['date'] ?? null; $v = (int)($row['downloads'] ?? 0);
    if ($d) $series[$d] = ($series[$d] ?? 0) + $v;
  }
}
ksort($series);
$max_dl = max(array_merge([1], $series));

// Top apps + países + nuevas métricas agregadas
$apps_agg = []; $countries_agg = [];
$metros_agg = []; $latam_agg = []; $eu_agg = []; $as_agg = [];
$devices_agg = []; $browsers_agg = [];

foreach ($podcasts as $p) {
  foreach (kp_op3_top_apps((int)$p['id'], $days, 20) as $a) {
    $k = $a['app_name'] ?? 'Otros'; $apps_agg[$k] = ($apps_agg[$k] ?? 0) + (int)($a['downloads'] ?? 0);
  }
  foreach (kp_op3_top_countries((int)$p['id'], $days, 30) as $c) {
    $k = $c['country'] ?? 'XX'; $countries_agg[$k] = ($countries_agg[$k] ?? 0) + (int)($c['downloads'] ?? 0);
  }
  foreach (kp_op3_top_metros((int)$p['id'], $days, 10) as $m) {
    $k = $m['metro_code'] ?? 'Unknown'; $metros_agg[$k] = ($metros_agg[$k] ?? 0) + (int)($m['downloads'] ?? 0);
  }
  foreach (kp_op3_top_regions((int)$p['id'], 'SA', $days, 10) as $r) {
    $k = $r['region_name'] ?? 'Unknown'; $latam_agg[$k] = ($latam_agg[$k] ?? 0) + (int)($r['downloads'] ?? 0);
  }
  foreach (kp_op3_top_regions((int)$p['id'], 'EU', $days, 10) as $r) {
    $k = $r['region_name'] ?? 'Unknown'; $eu_agg[$k] = ($eu_agg[$k] ?? 0) + (int)($r['downloads'] ?? 0);
  }
  foreach (kp_op3_top_regions((int)$p['id'], 'AS', $days, 10) as $r) {
    $k = $r['region_name'] ?? 'Unknown'; $as_agg[$k] = ($as_agg[$k] ?? 0) + (int)($r['downloads'] ?? 0);
  }
  foreach (kp_op3_top_devices((int)$p['id'], $days, 10) as $d) {
    $k = $d['name'] ?? 'Unknown'; $devices_agg[$k] = ($devices_agg[$k] ?? 0) + (int)($d['downloads'] ?? 0);
  }
  foreach (kp_op3_top_browsers((int)$p['id'], $days, 10) as $b) {
    $k = $b['name'] ?? 'Unknown'; $browsers_agg[$k] = ($browsers_agg[$k] ?? 0) + (int)($b['downloads'] ?? 0);
  }
}
arsort($apps_agg); arsort($countries_agg);
arsort($metros_agg); arsort($latam_agg); arsort($eu_agg); arsort($as_agg);
arsort($devices_agg); arsort($browsers_agg);

$apps_total = max(1, array_sum($apps_agg));
$countries_total = max(1, array_sum($countries_agg));
$metros_total = max(1, array_sum($metros_agg));
$latam_total = max(1, array_sum($latam_agg));
$eu_total = max(1, array_sum($eu_agg));
$as_total = max(1, array_sum($as_agg));
$devices_total = max(1, array_sum($devices_agg));
$browsers_total = max(1, array_sum($browsers_agg));

    $cached_data = compact(
        'podcasts', 'has_op3', 'last_sync',
        'tot', 'tot_period', 'unique_listeners', 'series', 'max_dl',
        'apps_agg', 'countries_agg', 'metros_agg', 'latam_agg', 'eu_agg', 'as_agg', 'devices_agg', 'browsers_agg',
        'apps_total', 'countries_total', 'metros_total', 'latam_total', 'eu_total', 'as_total', 'devices_total', 'browsers_total'
    );
    kp_cache_set($cache_key, $cached_data, 1800); // 30 minutes
}

function rng_btn($k, $l, $cur, $page='analytics') {
  $active = $k === $cur ? ' active' : '';
  return "<a class=\"btn$active\" href=\"/admin/$page?range=$k\">$l</a>";
}
?>
<div class="page-head">
  <div>
    <h1 class="page-title">Estadísticas</h1>
    <p class="page-sub">
      Métricas servidas por OP3 nativo · auditables · sin tracking de usuario
      <?php if ($last_sync): ?> · sincronizado <?= e(kp_relative_time($last_sync['updated_at'] ?? $last_sync['v'] ?? 0)) ?><?php endif; ?>
    </p>
  </div>
  <div class="row">
    <div class="seg">
      <?= rng_btn('7d','7 días', $range) ?>
      <?= rng_btn('30d','30 días', $range) ?>
      <?= rng_btn('mtd','Este mes', $range) ?>
      <?= rng_btn('all','Histórico', $range) ?>
    </div>
    <a class="btn" href="/api/v1/stats/export.csv?range=<?= e($range) ?>"><?= icon('download',13) ?> Exportar CSV</a>
  </div>
</div>

<?php if (!$has_op3 || $tot === 0): ?>
  <div class="card card-lg" style="text-align:center;padding:64px 24px">
    <div style="opacity:.4;margin-bottom:12px"><?= icon('chart',48) ?></div>
    <h3 style="margin:0 0 8px">Sin datos todavía</h3>
    <p class="muted" style="margin:0;max-width:520px;margin-inline:auto">
      OP3 acumula descargas en cuanto las URLs de tus feeds pasen por <code>/r/&lt;show&gt;/&lt;ep&gt;</code>.
      Las estadísticas aparecerán aquí en cuanto el worker procese los primeros logs.
    </p>
    <div class="row" style="justify-content:center;gap:8px;margin-top:18px">
      <a class="btn" href="/admin/preferences#op3"><?= icon('settings',13) ?> Configurar OP3</a>
      <code style="background:var(--surface-2);padding:6px 10px;border-radius:6px;font-size:12px">php cli/op3-worker.php</code>
    </div>
  </div>
<?php else: ?>

  <div class="stat-grid">
    <?= stat_card('Descargas totales', number_format($tot), 'download') ?>
    <?= stat_card('Descargas · '.e($range), number_format($tot_period), 'chart') ?>
    <?= stat_card('Oyentes únicos', number_format($unique_listeners), 'users', ['sub'=>'identificados por OP3']) ?>
    <?= stat_card('Podcasts activos', (string)count($podcasts), 'mic') ?>
  </div>

  <div class="grid-12" style="gap:24px;margin-top:24px">
    <!-- gráfico de barras -->
    <div class="card card-lg span-8" style="padding:28px">
      <div class="card-head">
        <div>
          <div class="card-title">Descargas por día</div>
          <div class="page-sub" style="margin-top:4px;font-size:12.5px">Agregado entre todos los podcasts · últimos <?= count($series) ?> días</div>
        </div>
      </div>
      <div style="position:relative;height:260px;margin-top:24px;display:flex;align-items:flex-end;gap:5px">
        <?php foreach ($series as $d => $v): $h = ($v/$max_dl)*100; ?>
          <div style="flex:1;display:flex;flex-direction:column;justify-content:flex-end;height:100%" title="<?= e($d) ?>: <?= number_format($v) ?>">
            <div style="background:var(--accent);opacity:0.85;border-radius:3px 3px 0 0;height:<?= $h ?>%"></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- por podcast -->
    <div class="card card-lg span-4" style="padding:24px">
      <div class="card-title">Por podcast</div>
      <div class="col" style="gap:10px;margin-top:14px">
        <?php foreach ($podcasts as $p):
          $st = kp_op3_podcast_totals((int)$p['id'], $days);
          $pct = $tot_period ? round(($st['downloads']/$tot_period)*100) : 0;
        ?>
          <div>
            <div class="row" style="justify-content:space-between;align-items:center;margin-bottom:4px">
              <a href="/admin/podcast/<?= e($p['slug']) ?>?tab=analytics" style="font-size:13px;color:inherit"><?= e($p['title']) ?></a>
              <span class="tabular" style="font-size:12px;color:var(--text-3)"><?= number_format($st['downloads']) ?> · <?= $pct ?>%</span>
            </div>
            <div style="height:6px;border-radius:3px;background:var(--surface-2);overflow:hidden">
              <div style="height:100%;width:<?= $pct ?>%;background:var(--accent)"></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="grid-12" style="gap:24px;margin-top:24px">
    <!-- Top 5 episodios globales -->
    <div class="card card-lg span-6" style="padding:24px">
      <div class="card-title" style="display:flex;align-items:center;gap:8px">
        <span style="color:var(--accent);display:inline-flex"><?= icon('star', 16) ?></span>
        Top 5 Episodios
      </div>
      <div class="help" style="margin-top:6px;margin-bottom:14px">Los 5 episodios más descargados de todos los podcasts</div>
      <?php
        $top5 = kp_op3_top_episodes(5);
        if (empty($top5)):
      ?>
        <div class="muted" style="padding:24px 0;text-align:center">Sin datos de descargas acumuladas todavía</div>
      <?php else: ?>
        <div class="col" style="gap:12px">
          <?php foreach ($top5 as $idx => $ep):
            $p = kp_podcast_or_placeholder($ep['podcast']);
            $cover = $ep['cover'] ?: ($p['cover'] ?? null);
          ?>
            <div class="row" style="align-items:center;gap:12px;padding:8px 0;<?= $idx > 0 ? 'border-top:1px solid var(--border)' : '' ?>">
              <div style="font-size:16px;font-weight:700;color:var(--text-3);width:20px;text-align:center">#<?= $idx + 1 ?></div>
              <?php if ($cover): ?>
                <img class="cover" src="<?= e($cover) ?>" style="width:36px;height:36px;border-radius:8px;object-fit:cover;flex-shrink:0" alt="">
              <?php else: ?>
                <div class="cover" style="width:36px;height:36px;border-radius:8px;background:linear-gradient(135deg,<?= e($p['color']) ?>,<?= e($p['color']) ?>99);color:white;font-weight:700;font-size:11px;flex-shrink:0"><?= e($p['initial']) ?></div>
              <?php endif; ?>
              <div style="flex:1;min-width:0">
                <div style="font-size:13.5px;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                  <a href="/admin/new-episode?id=<?= urlencode($ep['id']) ?>&podcast=<?= urlencode($ep['podcast']) ?>" style="color:inherit;text-decoration:none" onmouseover="this.style.textDecoration='underline'" onmouseout="this.style.textDecoration='none'">
                    <?= e($ep['title']) ?>
                  </a>
                </div>
                <div style="font-size:11.5px;color:var(--text-3);margin-top:2px">
                  <?= e($p['title']) ?> · S<?= (int)$ep['s'] ?> · E<?= (int)$ep['n'] ?>
                </div>
              </div>
              <div class="tabular" style="text-align:right">
                <div style="font-size:13.5px;font-weight:600"><?= fmt_num((int)$ep['downloads']) ?></div>
                <div style="font-size:10.5px;color:var(--text-3);margin-top:1px">descargas</div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- Episodio líder por podcast -->
    <div class="card card-lg span-6" style="padding:24px">
      <div class="card-title" style="display:flex;align-items:center;gap:8px">
        <span style="color:var(--accent);display:inline-flex"><?= icon('award', 16) ?></span>
        Líder de cada podcast
      </div>
      <div class="help" style="margin-top:6px;margin-bottom:14px">El episodio más descargado de cada show activo</div>
      <?php
        $leaders = [];
        foreach ($podcasts as $p) {
          $leader_ep = kp_op3_most_downloaded_episode((int)$p['id']);
          if ($leader_ep && $leader_ep['downloads'] > 0) {
            $full_p = kp_podcast_or_placeholder($p['slug']);
            $leaders[] = [
              'podcast_title' => $full_p['title'],
              'podcast_color' => $full_p['color'],
              'podcast_initial' => $full_p['initial'],
              'podcast_cover' => $full_p['cover'],
              'ep' => $leader_ep
            ];
          }
        }
        if (empty($leaders)):
      ?>
        <div class="muted" style="padding:24px 0;text-align:center">Sin datos de descargas acumuladas todavía</div>
      <?php else: ?>
        <div class="col" style="gap:12px">
          <?php foreach ($leaders as $idx => $lead):
            $p_cover = $lead['podcast_cover'];
            $ep = $lead['ep'];
            $ep_cover = $ep['cover'] ?: $p_cover;
          ?>
            <div class="row" style="align-items:center;gap:12px;padding:8px 0;<?= $idx > 0 ? 'border-top:1px solid var(--border)' : '' ?>">
              <?php if ($ep_cover): ?>
                <img class="cover" src="<?= e($ep_cover) ?>" style="width:36px;height:36px;border-radius:8px;object-fit:cover;flex-shrink:0" alt="">
              <?php else: ?>
                <div class="cover" style="width:36px;height:36px;border-radius:8px;background:linear-gradient(135deg,<?= e($lead['podcast_color']) ?>,<?= e($lead['podcast_color']) ?>99);color:white;font-weight:700;font-size:11px;flex-shrink:0"><?= e($lead['podcast_initial']) ?></div>
              <?php endif; ?>
              <div style="flex:1;min-width:0">
                <div style="font-size:13.5px;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                  <a href="/admin/new-episode?id=<?= urlencode($ep['id']) ?>&podcast=<?= urlencode($ep['podcast']) ?>" style="color:inherit;text-decoration:none" onmouseover="this.style.textDecoration='underline'" onmouseout="this.style.textDecoration='none'">
                    <?= e($ep['title']) ?>
                  </a>
                </div>
                <div style="font-size:11.5px;color:var(--text-3);margin-top:2px">
                  Show: <strong style="color:var(--text-2)"><?= e($lead['podcast_title']) ?></strong> · S<?= (int)$ep['s'] ?> · E<?= (int)$ep['n'] ?>
                </div>
              </div>
              <div class="tabular" style="text-align:right">
                <div style="font-size:13.5px;font-weight:600"><?= fmt_num((int)$ep['downloads']) ?></div>
                <div style="font-size:10.5px;color:var(--text-3);margin-top:1px">descargas</div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>


  <div class="grid-12" style="gap:24px;margin-top:24px">
    <!-- Apps Podcasting 2.0 -->
    <div class="card card-lg span-6" style="padding:24px">
      <div class="card-title">Apps · Podcasting 2.0</div>
      <div class="help" style="margin-top:6px;margin-bottom:14px">Detectadas por <code>?aid=</code> en User-Agent vía Podcast Index</div>
      <?php if (!$apps_agg): ?>
        <div class="muted" style="padding:24px 0;text-align:center">Sin apps PC 2.0 detectadas todavía</div>
      <?php else: ?>
        <div class="col" style="gap:10px">
          <?php $i = 0; foreach ($apps_agg as $name => $v): if ($i++ >= 8) break; $pct = round(($v/$apps_total)*100); ?>
            <div>
              <div class="row" style="justify-content:space-between;font-size:13px;margin-bottom:4px">
                <span><?= e($name) ?></span>
                <span class="tabular" style="color:var(--text-3);font-size:12px"><?= $pct ?>% · <?= number_format($v) ?></span>
              </div>
              <div style="height:5px;border-radius:3px;background:var(--surface-2);overflow:hidden">
                <div style="height:100%;width:<?= $pct ?>%;background:var(--accent)"></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- Países -->
    <div class="card card-lg span-6" style="padding:24px">
      <div class="card-title">Países</div>
      <div class="help" style="margin-top:6px;margin-bottom:14px">Detección por cascada CF-IPCountry → GeoLite2 → ASN</div>
      <?php if (!$countries_agg): ?>
        <div class="muted" style="padding:24px 0;text-align:center">Sin datos de geolocalización todavía</div>
      <?php else: ?>
        <div class="col" style="gap:10px">
          <?php $i = 0; foreach ($countries_agg as $cc => $v): if ($i++ >= 10) break; $pct = round(($v/$countries_total)*100); ?>
            <div>
              <div class="row" style="justify-content:space-between;font-size:13px;margin-bottom:4px">
                <span><?= e($cc) ?></span>
                <span class="tabular" style="color:var(--text-3);font-size:12px"><?= $pct ?>%</span>
              </div>
              <div style="height:5px;border-radius:3px;background:var(--surface-2);overflow:hidden">
                <div style="height:100%;width:<?= $pct*2.6 ?>%;background:var(--accent)"></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="grid-12" style="gap:24px;margin-top:24px">
    <!-- Metros U.S. -->
    <div class="card card-lg span-6" style="padding:24px">
      <div class="card-title">Top U.S. Metros</div>
      <?php if (!$metros_agg): ?>
        <div class="muted" style="padding:24px 0;text-align:center">Sin datos de metros en EE.UU.</div>
      <?php else: ?>
        <div class="col" style="gap:10px;margin-top:14px">
          <?php $i = 0; foreach ($metros_agg as $m => $v): if ($i++ >= 10) break; $pct = round(($v/$metros_total)*100); ?>
            <div>
              <div class="row" style="justify-content:space-between;font-size:13px;margin-bottom:4px">
                <span><?= e(dma_name($m, $DMA_NAMES)) ?></span>
                <span class="tabular" style="color:var(--text-3);font-size:12px"><?= $pct ?>%</span>
              </div>
              <div style="height:5px;border-radius:3px;background:var(--surface-2);overflow:hidden">
                <div style="height:100%;width:<?= $pct*2.6 ?>%;background:var(--accent)"></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- Regiones LatAm -->
    <div class="card card-lg span-6" style="padding:24px">
      <div class="card-title">Top Latinoamérica</div>
      <?php if (!$latam_agg): ?>
        <div class="muted" style="padding:24px 0;text-align:center">Sin datos de Latinoamérica</div>
      <?php else: ?>
        <div class="col" style="gap:10px;margin-top:14px">
          <?php $i = 0; foreach ($latam_agg as $r => $v): if ($i++ >= 10) break; $pct = round(($v/$latam_total)*100); ?>
            <div>
              <div class="row" style="justify-content:space-between;font-size:13px;margin-bottom:4px">
                <span><?= e($r) ?></span>
                <span class="tabular" style="color:var(--text-3);font-size:12px"><?= $pct ?>%</span>
              </div>
              <div style="height:5px;border-radius:3px;background:var(--surface-2);overflow:hidden">
                <div style="height:100%;width:<?= $pct*2.6 ?>%;background:var(--accent)"></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="grid-12" style="gap:24px;margin-top:24px">
    <!-- Navegadores -->
    <div class="card card-lg span-6" style="padding:24px">
      <div class="card-title">Top Navegadores</div>
      <?php if (!$browsers_agg): ?>
        <div class="muted" style="padding:24px 0;text-align:center">Sin datos de navegadores</div>
      <?php else: ?>
        <div class="col" style="gap:10px;margin-top:14px">
          <?php $i = 0; foreach ($browsers_agg as $b => $v): if ($i++ >= 10) break; $pct = round(($v/$browsers_total)*100); ?>
            <div>
              <div class="row" style="justify-content:space-between;font-size:13px;margin-bottom:4px">
                <span><?= e($b) ?></span>
                <span class="tabular" style="color:var(--text-3);font-size:12px"><?= $pct ?>%</span>
              </div>
              <div style="height:5px;border-radius:3px;background:var(--surface-2);overflow:hidden">
                <div style="height:100%;width:<?= $pct*2.6 ?>%;background:var(--accent)"></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- Dispositivos -->
    <div class="card card-lg span-6" style="padding:24px">
      <div class="card-title">Top Dispositivos</div>
      <?php if (!$devices_agg): ?>
        <div class="muted" style="padding:24px 0;text-align:center">Sin datos de dispositivos</div>
      <?php else: ?>
        <div class="col" style="gap:10px;margin-top:14px">
          <?php $i = 0; foreach ($devices_agg as $d => $v): if ($i++ >= 10) break; $pct = round(($v/$devices_total)*100); ?>
            <div>
              <div class="row" style="justify-content:space-between;font-size:13px;margin-bottom:4px">
                <span><?= e($d) ?></span>
                <span class="tabular" style="color:var(--text-3);font-size:12px"><?= $pct ?>%</span>
              </div>
              <div style="height:5px;border-radius:3px;background:var(--surface-2);overflow:hidden">
                <div style="height:100%;width:<?= $pct*2.6 ?>%;background:var(--accent)"></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="grid-12" style="gap:24px;margin-top:24px">
    <!-- Regiones Europa -->
    <div class="card card-lg span-6" style="padding:24px">
      <div class="card-title">Top Europa</div>
      <?php if (!$eu_agg): ?>
        <div class="muted" style="padding:24px 0;text-align:center">Sin datos de Europa</div>
      <?php else: ?>
        <div class="col" style="gap:10px;margin-top:14px">
          <?php $i = 0; foreach ($eu_agg as $r => $v): if ($i++ >= 10) break; $pct = round(($v/$eu_total)*100); ?>
            <div>
              <div class="row" style="justify-content:space-between;font-size:13px;margin-bottom:4px">
                <span><?= e($r) ?></span>
                <span class="tabular" style="color:var(--text-3);font-size:12px"><?= $pct ?>%</span>
              </div>
              <div style="height:5px;border-radius:3px;background:var(--surface-2);overflow:hidden">
                <div style="height:100%;width:<?= $pct*2.6 ?>%;background:var(--accent)"></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- Regiones Asia -->
    <div class="card card-lg span-6" style="padding:24px">
      <div class="card-title">Top Asia</div>
      <?php if (!$as_agg): ?>
        <div class="muted" style="padding:24px 0;text-align:center">Sin datos de Asia</div>
      <?php else: ?>
        <div class="col" style="gap:10px;margin-top:14px">
          <?php $i = 0; foreach ($as_agg as $r => $v): if ($i++ >= 10) break; $pct = round(($v/$as_total)*100); ?>
            <div>
              <div class="row" style="justify-content:space-between;font-size:13px;margin-bottom:4px">
                <span><?= e($r) ?></span>
                <span class="tabular" style="color:var(--text-3);font-size:12px"><?= $pct ?>%</span>
              </div>
              <div style="height:5px;border-radius:3px;background:var(--surface-2);overflow:hidden">
                <div style="height:100%;width:<?= $pct*2.6 ?>%;background:var(--accent)"></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>



<?php endif; ?>
