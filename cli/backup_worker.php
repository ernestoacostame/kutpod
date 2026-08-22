<?php
// ============================================================================
// cli/backup_worker.php — Crea archivos .zip de respaldo en segundo plano
// Uso: php cli/backup_worker.php <backup_id> <destination_path>
// ============================================================================
$db_path = file_exists(__DIR__ . '/includes/db.php') ? __DIR__ . '/includes/db.php' : __DIR__ . '/../includes/db.php';
require_once $db_path;

if ($argc < 3) {
    die("Uso: php backup_worker.php <id> <dest>\n");
}

$id = (int)$argv[1];
$dest = $argv[2];
$dbPath = __DIR__ . '/../storage/kutpod.db';

// Evitar que el script aborte por tiempo de ejecución en CLI
set_time_limit(0);
ini_set('memory_limit', '512M'); // Prevenir quedarse sin memoria al iterar muchos archivos

$zip = new ZipArchive();
if ($zip->open($dest, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
    
    // 1. Añadir la base de datos
    if (file_exists($dbPath)) {
        $zip->addFile($dbPath, 'kutpod.db');
    }
    
    // Obtener información del backup para saber si pertenece a un podcast específico
    $bk = kp_one("SELECT podcast_id FROM backups WHERE id = ?", [$id]);
    $podcast_id = $bk ? $bk['podcast_id'] : null;
    $podcast_slug = null;
    if ($podcast_id) {
        $p = kp_one("SELECT slug FROM podcasts WHERE id = ?", [$podcast_id]);
        if ($p) $podcast_slug = $p['slug'];
    }

    // 2. Iterar sobre todos los archivos multimedia
    $mediaDir = realpath(__DIR__ . '/../media');
    if ($mediaDir && is_dir($mediaDir)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($mediaDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($files as $name => $file) {
            if (!$file->isDir()) {
                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, strlen($mediaDir) + 1);
                
                // Excluir la carpeta de respaldos para no crear bucles infinitos
                if (strpos($relativePath, 'backups/') === 0) continue;

                // Si hay un podcast_id, filtrar los archivos
                if ($podcast_id && $podcast_slug) {
                    $keep = false;
                    // audios: media/audio/<podcast_id>/*
                    if (strpos($relativePath, "audio/{$podcast_id}/") === 0) $keep = true;
                    // portadas de episodios: media/covers/<podcast_id>/*
                    else if (strpos($relativePath, "covers/{$podcast_id}/") === 0) $keep = true;
                    // imagenes de capítulos: media/chapters/<podcast_id>/*
                    else if (strpos($relativePath, "chapters/{$podcast_id}/") === 0) $keep = true;
                    // portada del podcast: media/covers/podcast_<slug>.*
                    else if (preg_match("/^covers\/podcast_{$podcast_slug}\.[a-z0-9]+$/i", $relativePath)) $keep = true;
                    // banner del podcast: media/covers/podcast_banner_<slug>.*
                    else if (preg_match("/^covers\/podcast_banner_{$podcast_slug}\.[a-z0-9]+$/i", $relativePath)) $keep = true;
                    
                    if (!$keep) continue;
                }

                $zip->addFile($filePath, 'media/' . $relativePath);
            }
        }
    }
    $zip->close();
    
    // Finalizado con éxito
    clearstatcache(true, $dest);
    $finalSize = file_exists($dest) ? filesize($dest) : -1;
    kp_exec("UPDATE backups SET bytes = ? WHERE id = ?", [$finalSize, $id]);
    echo "Backup $id completado: $finalSize bytes.\n";
    
} else {
    // Error al crear el ZIP
    kp_exec("UPDATE backups SET bytes = -1 WHERE id = ?", [$id]);
    echo "Error al crear el archivo ZIP.\n";
}
