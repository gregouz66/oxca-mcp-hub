<?php
/**
 * Flux OAuth 2.0 LinkedIn (3-legged).
 *   ?action=start&id=N  → redirige vers l'écran d'autorisation LinkedIn
 *   ?code=…&state=…     → callback : échange le code, enregistre le token
 *
 * L'URL de redirection déclarée côté LinkedIn est toujours
 * APP_URL . /oauth-linkedin.php (sans paramètre) ; l'identifiant de la
 * config transite par la session, le state est vérifié strictement.
 */

require __DIR__ . '/app/bootstrap.php';
$user = require_login();

/* --------------------------------------------------------------- Départ */
if (($_GET['action'] ?? '') === 'start') {
    $config = config_get((int) ($_GET['id'] ?? 0));
    if (!$config || (int) $config['owner_id'] !== (int) $user['id']) {
        flash('error', 'Connecteur introuvable.');
        redirect('/dashboard.php');
    }
    $settings = config_settings($config);
    if (linkedin_client_id($settings) === '' || linkedin_client_secret($settings) === '') {
        flash('error', 'Renseignez d\'abord le Client ID et le Client Secret LinkedIn.');
        redirect('/connector.php?id=' . $config['id']);
    }

    $state = random_hex(16);
    $_SESSION['li_oauth'] = ['state' => $state, 'config_id' => (int) $config['id']];
    header('Location: ' . linkedin_oauth_url($settings, $state));
    exit;
}

/* ------------------------------------------------------------- Callback */
$pending = $_SESSION['li_oauth'] ?? null;
unset($_SESSION['li_oauth']);

if (!$pending || !isset($_GET['state']) || !hash_equals($pending['state'], (string) $_GET['state'])) {
    flash('error', 'Session OAuth invalide ou expirée : relancez la connexion LinkedIn.');
    redirect('/dashboard.php');
}

$config = config_get((int) $pending['config_id']);
if (!$config || (int) $config['owner_id'] !== (int) $user['id']) {
    flash('error', 'Connecteur introuvable.');
    redirect('/dashboard.php');
}
$back = '/connector.php?id=' . $config['id'];

if (isset($_GET['error'])) {
    flash('error', 'LinkedIn a refusé l\'autorisation : '
        . (string) ($_GET['error_description'] ?? $_GET['error'])
        . ' — vérifiez que les produits requis sont activés sur votre app (voir README).');
    redirect($back);
}
if (!isset($_GET['code'])) {
    flash('error', 'Réponse LinkedIn incomplète : relancez la connexion.');
    redirect($back);
}

try {
    $patch = linkedin_oauth_exchange(config_settings($config), (string) $_GET['code']);
    config_update_settings($config, $patch);
    flash('ok', 'LinkedIn connecté' . ($patch['member_name'] !== '' ? ' en tant que ' . $patch['member_name'] : '') . '. Vos outils MCP sont opérationnels.');
} catch (RuntimeException $e) {
    flash('error', $e->getMessage());
}
redirect($back);
