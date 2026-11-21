<?php
// ============================================================================
// KutPod · API endpoint para importar estadísticas externas (Apple & Spotify)
// ============================================================================

declare(strict_types=1);

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/op3-tracker.php';
require_once __DIR__ . '/../../includes/op3-importer.php';

// 1. Autenticación: verificar sesión por cookie web o Bearer token
$user = kp_current_user();
$viaCookie = (bool)$user;
if (!$user) {
    $auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (!$auth_header && function_exists('getallheaders')) {
        $headers = array_change_key_case(getallheaders(), CASE_LOWER);
        $auth_header = $headers['authorization'] ?? '';
    }
    if ($auth_header && preg_match('~^Bearer\s+~i', $auth_header)) {
        try {
            $user = api_require_user();
        } catch (Throwable $e) {}
    }
}

if (!$user) {
    api_error(401, 'unauthorized', 'Usuario no autenticado');
}

if ($viaCookie) {
    kp_csrf_verify();
}

$role = $user['role'] ?? 'author';
if (!in_array($role, ['owner', 'admin', 'editor'], true)) {
    api_error(403, 'forbidden', 'No tienes permisos para importar métricas');
}

// 2. Parámetros
$podcastId = (int)($_POST['podcast_id'] ?? 0);
$platform  = trim((string)($_POST['platform'] ?? 'auto'));
$redirect  = trim((string)($_POST['redirect'] ?? ''));
if ($redirect !== '' && (!str_starts_with($redirect, '/') || str_starts_with($redirect, '//'))) {
    $redirect = '';
}

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))
    || !empty($_POST['ajax']);

// 3. Normalizar archivos subidos (soporta archivo único o selección múltiple de CSV / ZIP)
$uploaded = [];

$addFile = function($name, $tmp, $err) use (&$uploaded) {
    if ($err === UPLOAD_ERR_OK && !empty($tmp) && is_uploaded_file($tmp)) {
        $uploaded[] = [
            'name'     => (string)$name,
            'tmp_name' => (string)$tmp,
        ];
    }
};

if (!empty($_FILES['files']['name'])) {
    if (is_array($_FILES['files']['name'])) {
        foreach ($_FILES['files']['name'] as $i => $n) {
            $addFile($n, $_FILES['files']['tmp_name'][$i] ?? '', $_FILES['files']['error'][$i] ?? -1);
        }
    } else {
        $addFile($_FILES['files']['name'], $_FILES['files']['tmp_name'], $_FILES['files']['error']);
    }
}

if (!empty($_FILES['file']['name'])) {
    if (is_array($_FILES['file']['name'])) {
        foreach ($_FILES['file']['name'] as $i => $n) {
            $addFile($n, $_FILES['file']['tmp_name'][$i] ?? '', $_FILES['file']['error'][$i] ?? -1);
        }
    } else {
        $addFile($_FILES['file']['name'], $_FILES['file']['tmp_name'], $_FILES['file']['error']);
    }
}

if (empty($uploaded)) {
    $msg = 'No se ha seleccionado ningún archivo CSV válido para importar.';
    if ($isAjax) {
        api_error(400, 'upload_failed', $msg);
    } else {
        setcookie('kp_flash', 'Error: ' . $msg, time() + 30, '/');
        header('Location: ' . ($redirect ?: '/admin/analytics'));
        exit;
    }
}

// 4. Desempaquetar ZIPs si los hay para extraer todos sus CSVs
$csvQueue = [];
$tempDirs = [];

foreach ($uploaded as $item) {
    $ext = strtolower(pathinfo($item['name'], PATHINFO_EXTENSION));
    if ($ext === 'zip' && class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($item['tmp_name']) === true) {
            $zipSafe = true;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->getNameIndex($i);
                $stat = $zip->statIndex($i);
                $isSymlink = $stat && ((($stat['external_attributes'] >> 16) & 0170000) === 0120000);
                if ($entry === false || $isSymlink || str_contains($entry, '..') || str_starts_with($entry, '/') || str_contains($entry, '\\') || str_contains($entry, "\0")) {
                    $zipSafe = false;
                    break;
                }
            }
            if ($zipSafe) {
                $extractPath = sys_get_temp_dir() . '/kp_zip_' . uniqid('', true);
                mkdir($extractPath, 0755, true);
                $tempDirs[] = $extractPath;
                $zip->extractTo($extractPath);
            }
            $zip->close();

            if ($zipSafe) {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($extractPath, FilesystemIterator::SKIP_DOTS)
                );
                foreach ($iterator as $fItem) {
                    if ($fItem->isFile() && strtolower($fItem->getExtension()) === 'csv') {
                        $csvQueue[] = [
                            'name'     => $fItem->getBasename(),
                            'tmp_name' => $fItem->getPathname(),
                        ];
                    }
                }
            }
        }
    } else {
        $csvQueue[] = $item;
    }
}

if (empty($csvQueue)) {
    $msg = 'No se encontraron archivos .csv legibles en los archivos seleccionados.';
    if ($isAjax) {
        api_error(400, 'no_csv_found', $msg);
    } else {
        setcookie('kp_flash', 'Error: ' . $msg, time() + 30, '/');
        header('Location: ' . ($redirect ?: '/admin/analytics'));
        exit;
    }
}

// 5. Procesar cola de CSVs
$totalMatched = 0;
$totalUnmatched = 0;
$processedFiles = [];
$allUpdated = [];
$platforms = [];

try {
    foreach ($csvQueue as $cItem) {
        try {
            $res = kp_op3_import_external_csv($podcastId, $platform, $cItem['tmp_name'], $cItem['name']);
            $totalMatched += $res['matched'];
            $totalUnmatched += $res['unmatched'];
            $platforms[$res['platform']] = true;
            if (!empty($res['updated'])) {
                foreach ($res['updated'] as $u) {
                    $allUpdated[$u['id']] = $u;
                }
            }
            $processedFiles[] = [
                'file'      => $cItem['name'],
                'status'    => 'ok',
                'matched'   => $res['matched'],
                'unmatched' => $res['unmatched'],
                'platform'  => $res['platform'],
            ];
        } catch (Throwable $fileErr) {
            $processedFiles[] = [
                'file'   => $cItem['name'],
                'status' => 'skipped',
                'error'  => $fileErr->getMessage(),
            ];
        }
    }

    // Limpiar carpetas temporales de descompresión
    foreach ($tempDirs as $td) {
        try {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($td, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $fileinfo) {
                $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
                $todo($fileinfo->getRealPath());
            }
            rmdir($td);
        } catch (Throwable $e) {}
    }

    $detectedPlatform = count($platforms) === 1 ? array_key_first($platforms) : 'multi';
    $pName = ($detectedPlatform === 'apple') ? 'Apple Podcasts' : (($detectedPlatform === 'spotify') ? 'Spotify' : 'CSV');

    $summaryResult = [
        'success'         => true,
        'total_files'     => count($processedFiles),
        'matched'         => count($allUpdated),
        'total_records'   => $totalMatched,
        'unmatched'       => $totalUnmatched,
        'platform'        => $detectedPlatform,
        'platform_label'  => $pName,
        'updated'         => array_values($allUpdated),
        'files'           => $processedFiles,
    ];

    if ($isAjax) {
        api_json($summaryResult);
    } else {
        $epCount = count($allUpdated);
        $fileCount = count($processedFiles);
        $flash = "Se procesaron {$fileCount} archivo(s) CSV e importaron métricas para {$epCount} episodios ({$pName}).";
        setcookie('kp_flash', $flash, time() + 30, '/');
        $target = $redirect ?: ('/admin/podcast?id=' . $podcastId);
        header('Location: ' . $target);
        exit;
    }
} catch (Throwable $e) {
    if ($isAjax) {
        api_error(500, 'import_failed', $e->getMessage());
    } else {
        setcookie('kp_flash', 'Error al importar CSV: ' . $e->getMessage(), time() + 30, '/');
        header('Location: ' . ($redirect ?: '/admin/analytics'));
        exit;
    }
}
