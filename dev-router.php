<?php
// Routeur pour le serveur PHP intégré (développement JamboAPI CMS) :
// sert les fichiers statiques de public/, sinon passe la main à Symfony.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = __DIR__ . '/public' . $path;
if ($path !== '/' && is_file($file)) {
    return false; // le serveur intégré sert le fichier tel quel avec son type MIME correct
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/public/index.php';
require __DIR__ . '/public/index.php';
