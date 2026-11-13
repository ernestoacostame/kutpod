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

/**
 * Extrae la duración en segundos de un archivo de audio (MP3, WAV, etc.)
 * 1. Primero intenta con ffprobe (usando kp_get_ffmpeg_cmd).
 * 2. Si ffprobe no está disponible o falla, utiliza un parser nativo en PHP para MP3 y WAV.
 */
function kp_extract_audio_duration(string $filePath): int {
    if (!file_exists($filePath) || !is_readable($filePath)) return 0;

    // 1. Intentar con ffprobe
    $ffprobe = kp_get_ffmpeg_cmd('ffprobe');
    if ($ffprobe && function_exists('shell_exec')) {
        $cmd = escapeshellcmd($ffprobe) . ' -v quiet -print_format json -show_format ' . escapeshellarg($filePath);
        $probeJson = @shell_exec($cmd);
        if ($probeJson) {
            $probe = json_decode($probeJson, true);
            if (!empty($probe['format']['duration'])) {
                $dur = (int)round((float)$probe['format']['duration']);
                if ($dur > 0) return $dur;
            }
        }
    }

    // 2. Parser nativo PHP para WAV
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $handle = @fopen($filePath, 'rb');
    if (!$handle) return 0;

    $fileSize = filesize($filePath);

    if ($ext === 'wav') {
        $riff = fread($handle, 12);
        if (substr($riff, 0, 4) === 'RIFF' && substr($riff, 8, 4) === 'WAVE') {
            $byteRate = 0;
            $dataSize = 0;
            while (!feof($handle)) {
                $chunk = fread($handle, 8);
                if (strlen($chunk) < 8) break;
                $chunkId = substr($chunk, 0, 4);
                $chunkSize = unpack('V', substr($chunk, 4, 4))[1];
                if ($chunkId === 'fmt ') {
                    $fmtData = fread($handle, $chunkSize);
                    if (strlen($fmtData) >= 12) {
                        $byteRate = unpack('V', substr($fmtData, 8, 4))[1];
                    }
                } elseif ($chunkId === 'data') {
                    $dataSize = $chunkSize;
                    break;
                } else {
                    fseek($handle, $chunkSize, SEEK_CUR);
                }
            }
            fclose($handle);
            if ($byteRate > 0 && $dataSize > 0) {
                return (int)round($dataSize / $byteRate);
            }
        }
        fclose($handle);
        return 0;
    }

    // 3. Parser nativo PHP para MP3
    $header = fread($handle, 10);
    $offset = 0;

    // Saltar cabecera ID3v2 si existe
    if (substr($header, 0, 3) === 'ID3') {
        $sizeBytes = substr($header, 6, 4);
        $id3Size = ((ord($sizeBytes[0]) & 0x7F) << 21) |
                   ((ord($sizeBytes[1]) & 0x7F) << 14) |
                   ((ord($sizeBytes[2]) & 0x7F) << 7)  |
                   (ord($sizeBytes[3]) & 0x7F);
        $offset = 10 + $id3Size;
        fseek($handle, $offset);
    } else {
        fseek($handle, 0);
    }

    $buffer = fread($handle, 65536);
    fclose($handle);

    $bufLen = strlen($buffer);
    for ($i = 0; $i < $bufLen - 4; $i++) {
        if (ord($buffer[$i]) === 0xFF && (ord($buffer[$i + 1]) & 0xE0) === 0xE0) {
            $b1 = ord($buffer[$i + 1]);
            $b2 = ord($buffer[$i + 2]);

            $versionBits = ($b1 >> 3) & 0x03; // 3 = MPEG1, 2 = MPEG2, 0 = MPEG2.5
            $layerBits   = ($b1 >> 1) & 0x03; // 1 = Layer III, 2 = Layer II, 3 = Layer I
            if ($versionBits === 1 || $layerBits === 0) continue;

            $sampleRateIdx = ($b2 >> 2) & 0x03;
            if ($sampleRateIdx === 3) continue;

            $rates = [
                3 => [44100, 48000, 32000],      // MPEG 1
                2 => [22050, 24000, 16000],      // MPEG 2
                0 => [11025, 12000, 8000]        // MPEG 2.5
            ];
            $sampleRate = $rates[$versionBits][$sampleRateIdx] ?? 44100;

            $samplesPerFrame = 1152;
            if ($versionBits !== 3 && $layerBits === 1) { // MPEG 2 / 2.5 Layer III
                $samplesPerFrame = 576;
            } elseif ($layerBits === 3) { // Layer I
                $samplesPerFrame = 384;
            }

            // Buscar cabecera Xing o Info (VBR o LAME)
            $frameSnippet = substr($buffer, $i, min(250, $bufLen - $i));
            $xingPos = strpos($frameSnippet, 'Xing');
            if ($xingPos === false) {
                $xingPos = strpos($frameSnippet, 'Info');
            }

            if ($xingPos !== false && strlen($frameSnippet) >= $xingPos + 8) {
                $flags = unpack('N', substr($frameSnippet, $xingPos + 4, 4))[1];
                if ($flags & 0x0001) { // Frames field present
                    if (strlen($frameSnippet) >= $xingPos + 12) {
                        $totalFrames = unpack('N', substr($frameSnippet, $xingPos + 8, 4))[1];
                        if ($totalFrames > 0 && $sampleRate > 0) {
                            return (int)round(($totalFrames * $samplesPerFrame) / $sampleRate);
                        }
                    }
                }
            }

            // Fallback CBR: calcular duración por bitrate
            $bitrateIdx = ($b2 >> 4) & 0x0F;
            if ($bitrateIdx > 0 && $bitrateIdx < 15) {
                $bitrateTable = [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320];
                if ($versionBits !== 3) {
                    $bitrateTable = [0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160];
                }
                $kbps = $bitrateTable[$bitrateIdx] ?? 128;
                if ($kbps > 0) {
                    $audioBytes = max(1, $fileSize - $offset);
                    return (int)round(($audioBytes * 8) / ($kbps * 1000));
                }
            }
            break;
        }
    }

    return 0;
}
