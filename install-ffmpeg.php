<?php
// Endpoint web para instalar FFmpeg desde el Dashboard
require_once __DIR__ . '/includes/auth.php';
kp_require_role('owner');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['error' => 'Method not allowed']));
}

@set_time_limit(300);

$binDir = __DIR__ . '/bin';
if (!is_dir($binDir)) {
    @mkdir($binDir, 0755, true);
}

// Dependencia obligatoria nativa de PHP
if (!class_exists('ZipArchive')) {
    echo json_encode(['error' => 'La extensión ZipArchive no está habilitada en tu PHP. No se pueden extraer los binarios.']);
    exit;
}

// Usamos ZIPs precompilados de ffbinaries-prebuilt para poder extraerlos de forma nativa
$urls = [
    'https://github.com/ffbinaries/ffbinaries-prebuilt/releases/download/v6.1/ffmpeg-6.1-linux-64.zip',
    'https://github.com/ffbinaries/ffbinaries-prebuilt/releases/download/v6.1/ffprobe-6.1-linux-64.zip'
];

$hasCurl = function_exists('curl_init');
$hasExec = function_exists('exec') && strpos(ini_get('disable_functions'), 'exec') === false;

foreach ($urls as $url) {
    $zipFile = $binDir . '/' . basename($url);

    // Intentos de descarga en cascada (exec wget/curl -> ext-curl -> file_get_contents)
    $downloaded = false;
    if ($hasExec) {
        @exec('wget -qO ' . escapeshellarg($zipFile) . ' ' . escapeshellarg($url), $out, $ret);
        if ($ret === 0 && file_exists($zipFile) && filesize($zipFile) > 0) $downloaded = true;
        if (!$downloaded) {
            @exec('curl -sL -o ' . escapeshellarg($zipFile) . ' ' . escapeshellarg($url), $out, $ret);
            if ($ret === 0 && file_exists($zipFile) && filesize($zipFile) > 0) $downloaded = true;
        }
    }
    if (!$downloaded && $hasCurl) {
        $ch = curl_init($url);
        $fp = fopen($zipFile, 'wb');
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'KutPod/1.0 (PHP cURL)');
        curl_exec($ch);
        curl_close($ch);
        fclose($fp);
        $downloaded = filesize($zipFile) > 0;
    }
    if (!$downloaded) {
        $opts = ['http' => ['method' => 'GET', 'header' => "User-Agent: KutPod/1.0\r\n"]];
        $context = stream_context_create($opts);
        $data = @file_get_contents($url, false, $context);
        if ($data) {
            file_put_contents($zipFile, $data);
        }
    }

    if (!file_exists($zipFile) || filesize($zipFile) === 0) {
        echo json_encode(['error' => 'No se pudo descargar ' . basename($url) . '. Verifica la conexión a internet.']);
        exit;
    }

    // Extraer de forma 100% nativa con PHP
    $zip = new ZipArchive;
    if ($zip->open($zipFile) === TRUE) {
        $zip->extractTo($binDir);
        $zip->close();
        @unlink($zipFile);
    } else {
        @unlink($zipFile);
        echo json_encode(['error' => 'No se pudo descomprimir nativamente el archivo ' . basename($url)]);
        exit;
    }
}

// Otorgar permisos de ejecución
@chmod($binDir . '/ffmpeg', 0755);
@chmod($binDir . '/ffprobe', 0755);

if (file_exists($binDir . '/ffmpeg') && file_exists($binDir . '/ffprobe')) {
    echo json_encode(['ok' => true, 'message' => 'FFmpeg y FFprobe descargados y extraídos exitosamente mediante ZipArchive.']);
} else {
    echo json_encode(['error' => 'Hubo un error al extraer los archivos binarios.']);
}
