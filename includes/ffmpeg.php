<?php
// ============================================================================
// includes/ffmpeg.php — Helper para detectar y ejecutar FFmpeg
// ============================================================================

/**
 * Obtiene el comando base para ffmpeg o ffprobe.
 * Si el servidor tiene la herramienta instalada globalmente, la usa.
 * Si no, busca en la carpeta bin/ del proyecto.
 * Retorna false si no se encuentra.
 */
function kp_get_ffmpeg_cmd(string $cmd = 'ffmpeg') {
    // 1. Probar si existe globalmente
    if (function_exists('exec')) {
        @exec(escapeshellcmd($cmd) . ' -version 2>&1', $out, $ret);
        if ($ret === 0) {
            return $cmd;
        }
    }

    // 2. Probar si existe en la carpeta bin/ del proyecto
    $local_path = realpath(__DIR__ . '/../bin/' . $cmd);
    if ($local_path && file_exists($local_path) && is_executable($local_path)) {
        return escapeshellcmd($local_path);
    }

    // 3. Probar con extensión .exe para Windows
    $local_path_exe = realpath(__DIR__ . '/../bin/' . $cmd . '.exe');
    if ($local_path_exe && file_exists($local_path_exe)) {
        return escapeshellcmd($local_path_exe);
    }

    return false;
}

/**
 * Verifica si FFmpeg está disponible.
 */
function kp_has_ffmpeg(): bool {
    return kp_get_ffmpeg_cmd('ffmpeg') !== false;
}
