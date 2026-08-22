<?php
spl_autoload_register(function ($class) {
    if (strpos($class, 'GeoIp2\\') === 0 || strpos($class, 'MaxMind\\') === 0) {
        $file = __DIR__ . '/' . str_replace('\\', '/', $class) . '.php';
        if (file_exists($file)) {
            require $file;
        }
    }
});
