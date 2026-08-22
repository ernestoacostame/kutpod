<?php
$days = [];
for ($i=29; $i>=0; $i--) $days[] = date('Y-m-d', strtotime("-$i days"));
echo implode(", ", $days);
