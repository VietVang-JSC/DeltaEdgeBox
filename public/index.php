<?php

$pharPath = __DIR__ . '/../edge-box.phar';
$basePath = realpath(__DIR__ . '/..');

if (file_exists($pharPath)) {
    require 'phar://' . $pharPath . '/vendor/autoload.php';
} else {
    require $basePath . '/vendor/autoload.php';
}

$app = require_once $basePath . '/bootstrap/app.php';

// Override paths to load from PHAR when available
if (file_exists($pharPath)) {
    $app->useAppPath('phar://' . $pharPath . '/app');
    $app->useConfigPath('phar://' . $pharPath . '/config');
    $app->useDatabasePath('phar://' . $pharPath . '/database');
    $app->useLangPath('phar://' . $pharPath . '/resources/lang');
    $app->instance('path.resources', 'phar://' . $pharPath . '/resources');
    $app->usePublicPath($basePath . '/public');
    $app->useStoragePath($basePath . '/storage');
}

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle(
    $request = Illuminate\Http\Request::capture()
)->send();
$kernel->terminate($request, $response);
