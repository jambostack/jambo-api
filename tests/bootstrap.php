<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG'] ?? false) {
    umask(0000);
}

// Ensure Webpack Encore build directory and manifest/entrypoint files exist for testing environment
$buildDir = dirname(__DIR__) . '/public/build';
if (!is_dir($buildDir)) {
    @mkdir($buildDir, 0777, true);
}
if (!file_exists($buildDir . '/entrypoints.json')) {
    @file_put_contents($buildDir . '/entrypoints.json', json_encode(['entrypoints' => []]));
}
if (!file_exists($buildDir . '/manifest.json')) {
    @file_put_contents($buildDir . '/manifest.json', json_encode([]));
}

