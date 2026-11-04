<?php
/**
 * Endpoint de déploiement — déclenche git pull via HTTP sécurisé.
 * Usage : GET /deploy.php?token=DEPLOY_TOKEN
 *
 * Ce fichier est standalone (ne dépend pas de Symfony) pour fonctionner
 * même si le vendor/ n'est pas à jour.
 */

$configuredToken = getenv('JAMBO_DEPLOY_TOKEN') ?: ($_ENV['JAMBO_DEPLOY_TOKEN'] ?? $_SERVER['JAMBO_DEPLOY_TOKEN'] ?? null);

// Ne pas laisser Symfony intercepter la requête
$_SERVER['SCRIPT_FILENAME'] = __FILE__;

// Pas d'affichage d'erreurs en clair
ini_set('display_errors', '0');
error_reporting(0);

header('Content-Type: text/plain; charset=utf-8');

if (empty($configuredToken) || !hash_equals((string) $configuredToken, (string) ($_GET['token'] ?? ''))) {
    http_response_code(403);
    die("Forbidden\n");
}

$rootDir = dirname(__DIR__);
$log = [];

// — git pull —
$git = shell_exec("cd " . escapeshellarg($rootDir) . " && git pull origin main 2>&1");
$log[] = "=== git pull ===";
$log[] = $git;

echo implode("\n", $log);
