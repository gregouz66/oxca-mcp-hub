<?php
/**
 * Point d'entrée commun : configuration, session, chargement des modules.
 *
 * Chaque page publique commence par `require __DIR__ . '/app/bootstrap.php';`.
 * L'endpoint MCP (mcp.php) définit OXCA_NO_SESSION avant l'inclusion pour
 * rester sans état (pas de cookie de session).
 */

declare(strict_types=1);

const APP_VERSION = '1.1.0';

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
date_default_timezone_set('UTC');

// Garde de version : les modules internes utilisent la syntaxe PHP 8.1+.
// Ce test s'exécute avant leur chargement pour afficher un message clair
// plutôt qu'une page blanche 500 sur un hébergement configuré en PHP < 8.1.
if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    exit('<!doctype html><meta charset="utf-8"><div style="font-family:-apple-system,system-ui,sans-serif;max-width:520px;margin:12vh auto;padding:0 24px;color:#1d1d1f;line-height:1.5">'
        . '<h1 style="font-size:22px">PHP 8.1 ou plus requis</h1>'
        . '<p>Cet hébergement exécute actuellement PHP ' . PHP_VERSION . '. Dans le panneau de votre hébergeur, sélectionnez PHP&nbsp;8.1 ou une version plus récente pour ce site, puis rechargez la page.</p>'
        . '</div>');
}

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
require __DIR__ . '/http.php';
require __DIR__ . '/media.php';
require __DIR__ . '/image.php';
require __DIR__ . '/mcp/linkedin.php';
require __DIR__ . '/mcp/instagram.php';

if (!defined('OXCA_NO_SESSION')) {
    $lifetime = 60 * 60 * 24 * 30; // 30 jours

    // Aligne la durée de vie serveur sur celle du cookie : sinon le GC par
    // défaut du mutualisé (souvent 24 min) détruirait la session — et son
    // jeton CSRF — bien avant l'expiration du cookie.
    ini_set('session.gc_maxlifetime', (string) $lifetime);

    // Répertoire de sessions dédié : isole nos sessions du GC agressif des
    // autres sites d'un save_path partagé (best effort ; retombe sur le défaut).
    $sessionDir = dirname(__DIR__) . '/storage/sessions';
    if (is_dir($sessionDir) || @mkdir($sessionDir, 0700, true)) {
        session_save_path($sessionDir);
    }

    session_name('oxcahub');
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path'     => '/',
        'secure'   => str_starts_with(APP_URL, 'https://'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
