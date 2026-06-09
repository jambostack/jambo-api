<?php
/**
 * Endpoint de déploiement — déclenche git pull + npm run build via HTTP.
 * Usage : GET /deploy.php?token=DEPLOY_TOKEN
 *
 * Ce fichier est standalone (ne dépend pas de Symfony) pour fonctionner
 * même si le vendor/ n'est pas à jour.
 */

define('DEPLOY_TOKEN', 'jambo_deploy_2026_secure');

// Ne pas laisser Symfony intercepter la requête
$_SERVER['SCRIPT_FILENAME'] = __FILE__;

// Pas d'affichage d'erreurs en clair
ini_set('display_errors', '0');
error_reporting(0);

header('Content-Type: text/plain; charset=utf-8');

if (($_GET['token'] ?? '') !== DEPLOY_TOKEN) {
    http_response_code(403);
    die("Forbidden\n");
}

$workDir = __DIR__;
$log = [];

// — git pull —
$git = shell_exec("cd $workDir && git pull origin main 2>&1");
$log[] = "=== git pull ===";
$log[] = $git;

// — npm build —
$npm = shell_exec("cd $workDir && npm run build 2>&1");
$log[] = "=== npm run build ===";
$log[] = $npm;

echo implode("\n", $log);
