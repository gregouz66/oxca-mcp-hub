<?php
/**
 * Point d'entrée commun : configuration, session, chargement des modules.
 *
 * Chaque page publique commence par `require __DIR__ . '/app/bootstrap.php';`.
 * L'endpoint MCP (mcp.php) définit OXCA_NO_SESSION avant l'inclusion pour
 * rester sans état (pas de cookie de session).
 */

declare(strict_types=1);

const APP_VERSION = '1.0.0';

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
date_default_timezone_set('UTC');

$configFile = dirname(__DIR__) . '/config.php';
if (!is_file($configFile)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Configuration manquante.\n\nCopiez config.sample.php en config.php, renseignez vos valeurs,\npuis ouvrez /install.php. Voir le README pour le pas-à-pas.");
}
require $configFile;

require __DIR__ . '/util.php';
require __DIR__ . '/crypto.php';
require __DIR__ . '/db.php';
require __DIR__ . '/mail.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/models.php';
require __DIR__ . '/ui.php';
require __DIR__ . '/mcp/server.php';
require __DIR__ . '/mcp/linkedin.php';

if (!defined('OXCA_NO_SESSION')) {
    session_name('oxcahub');
    session_set_cookie_params([
        'lifetime' => 60 * 60 * 24 * 30,
        'path'     => '/',
        'secure'   => str_starts_with(APP_URL, 'https://'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
