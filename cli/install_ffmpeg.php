<?php
// ============================================================================
// cli/install_ffmpeg.php — Descarga binarios estáticos de FFmpeg para Linux
// ============================================================================

if (php_sapi_name() !== 'cli') {
    die("Este script solo puede ejecutarse desde la linea de comandos (CLI).\n");
}

$binDir = __DIR__ . '/../bin';
if (!is_dir($binDir)) {
    mkdir($binDir, 0755, true);
}

echo "Descargando binarios estáticos de FFmpeg (amd64) desde johnvansickle.com...\n";
$url = 'https://johnvansickle.com/ffmpeg/releases/ffmpeg-release-amd64-static.tar.xz';
$tarball = $binDir . '/ffmpeg-release.tar.xz';

// Intentar descargar con file_get_contents o wget/curl
if (function_exists('exec')) {
    exec('wget -qO ' . escapeshellarg($tarball) . ' ' . escapeshellarg($url), $out, $ret);
    if ($ret !== 0) {
        exec('curl -sL -o ' . escapeshellarg($tarball) . ' ' . escapeshellarg($url), $out, $ret);
    }
} else {
    $data = file_get_contents($url);
    if ($data) file_put_contents($tarball, $data);
}

if (!file_exists($tarball) || filesize($tarball) === 0) {
    die("Error: No se pudo descargar el archivo.\n");
}

echo "Extrayendo archivos...\n";
// En sistemas Linux con tar instalado
exec('tar -xf ' . escapeshellarg($tarball) . ' -C ' . escapeshellarg($binDir) . ' --strip-components=1', $out, $ret);

if (file_exists($binDir . '/ffmpeg') && file_exists($binDir . '/ffprobe')) {
    echo "FFmpeg y FFprobe instalados correctamente en bin/\n";
    @unlink($tarball);
} else {
    echo "Hubo un error al extraer los archivos. Asegúrate de tener 'tar' instalado.\n";
}
