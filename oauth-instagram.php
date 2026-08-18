<?php
/**
 * Flux OAuth 2.0 Instagram (Business Login for Instagram).
 *   ?action=start&id=N  → redirige vers l'écran d'autorisation Instagram
 *   ?code=…&state=…     → callback : échange le code, enregistre le token
 *
 * L'URL de redirection déclarée côté Meta est toujours
 * APP_URL . /oauth-instagram.php (sans paramètre) ; l'identifiant de la config
 * transite par la session et le state est vérifié strictement.
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
    if (instagram_app_id($settings) === '' || instagram_app_secret($settings) === '') {
        flash('error', 'Renseignez d\'abord l\'Instagram App ID et l\'App Secret.');
        redirect('/connector.php?id=' . $config['id']);
    }

    $state = random_hex(16);
    $_SESSION['ig_oauth'] = ['state' => $state, 'config_id' => (int) $config['id']];
    header('Location: ' . instagram_oauth_url($settings, $state));
    exit;
}

/* ------------------------------------------------------------- Callback */
$pending = $_SESSION['ig_oauth'] ?? null;
unset($_SESSION['ig_oauth']);

if (!$pending || !isset($_GET['state']) || !hash_equals($pending['state'], (string) $_GET['state'])) {
    flash('error', 'Session OAuth invalide ou expirée : relancez la connexion Instagram.');
    redirect('/dashboard.php');
}

$config = config_get((int) $pending['config_id']);
if (!$config || (int) $config['owner_id'] !== (int) $user['id']) {
    flash('error', 'Connecteur introuvable.');
    redirect('/dashboard.php');
}
$back = '/connector.php?id=' . $config['id'];

if (isset($_GET['error'])) {
    $code = (string) $_GET['error'];
    $desc = (string) ($_GET['error_description'] ?? '');
    $hint = match (true) {
        ($_GET['error_reason'] ?? '') === 'user_denied' || $code === 'access_denied'
            => 'Vous avez refusé l\'autorisation sur l\'écran Instagram. Relancez « Connecter Instagram » quand vous voulez.',
        default
            => 'Vérifiez que votre app Meta est de type « Business », que le produit Instagram y est ajouté, et que l\'URL de redirection déclarée est exactement celle affichée sur la page du connecteur.',
    };
    flash('error', 'Instagram a refusé l\'autorisation [' . $code . ']'
        . ($desc !== '' ? ' : ' . str_replace('+', ' ', $desc) : '') . ' — ' . $hint);
    redirect($back);
}
if (!isset($_GET['code'])) {
    flash('error', 'Réponse Instagram incomplète : relancez la connexion.');
    redirect($back);
}

try {
    $patch = instagram_oauth_exchange(config_settings($config), (string) $_GET['code']);
    config_update_settings($config, $patch);

    $isPro = in_array(strtoupper((string) $patch['account_type']), ['BUSINESS', 'MEDIA_CREATOR'], true);
    flash(
        $isPro ? 'ok' : 'error',
        $isPro
            ? 'Instagram connecté en tant que @' . $patch['username'] . '. Vos outils MCP sont opérationnels.'
            : 'Compte @' . $patch['username'] . ' connecté, mais son type (' . ($patch['account_type'] ?: 'inconnu')
              . ') n\'autorise pas la publication par API. Basculez-le en compte professionnel dans l\'application Instagram (Paramètres → Pour les professionnels), puis reconnectez-le.'
    );
} catch (RuntimeException $e) {
    flash('error', $e->getMessage());
}
redirect($back);
