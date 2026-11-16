<?php

use Illuminate\Http\Request;

/*
| Front controller en la raíz del proyecto (despliegue sin carpeta public/).
| .htaccess / web.config bloquean el acceso directo a los directorios
| internos (app, config, storage, vendor, ...) y a los archivos ocultos.
*/

define('LARAVEL_START', microtime(true));

// Modo mantenimiento
if (file_exists($maintenance = __DIR__.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require __DIR__.'/vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require_once __DIR__.'/bootstrap/app.php';

// La raíz del proyecto hace de directorio público
$app->usePublicPath(__DIR__);

$app->handleRequest(Request::capture());
