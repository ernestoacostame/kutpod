<?php
// /r.php · endpoint de tracking. Rewrite recomendado:
//   Apache: RewriteRule ^r/([^/]+)/([^/]+)(?:/.*)?$ /r.php?show=$1&ep=$2 [QSA,L]
//   Nginx:  rewrite ^/r/([^/]+)/([^/]+) /r.php?show=$1&ep=$2 last;
require_once __DIR__ . '/includes/op3-tracker.php';
$show = preg_replace('#[^a-z0-9\-]#i', '', $_GET['show'] ?? '');
$ep   = preg_replace('#[^a-z0-9\-]#i', '', $_GET['ep']   ?? '');
if (!$show || !$ep) { http_response_code(400); exit('Bad request'); }
kp_op3_track_and_redirect($show, $ep);
