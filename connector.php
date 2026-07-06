<?php
/**
 * Page d'un connecteur.
 *   - Propriétaire : réglages, connexion LinkedIn, endpoint MCP, partage,
 *     régénération de token, suppression.
 *   - Invité (partage) : son URL d'endpoint et les instructions Claude,
 *     possibilité de quitter le partage. Jamais accès aux secrets.
 */

require __DIR__ . '/app/bootstrap.php';
$user = require_login();

$config = config_get((int) ($_GET['id'] ?? $_POST['id'] ?? 0));
$grant  = $config ? grant_for((int) $config['id'], (int) $user['id']) : null;
if (!$config || !$grant) {
    http_response_code(404);
    ui_top('Introuvable', $user);
    ui_empty('alert', 'Connecteur introuvable', 'Il a peut-être été supprimé, ou son accès vous a été retiré.',
        '<a class="btn btn-primary" href="' . e(base_url('/dashboard.php')) . '">Retour au tableau de bord</a>');
    ui_bottom();
    exit;
}

$isOwner = $grant['role'] === 'owner';
$type    = mcp_type($config['type']);

/* ------------------------------------------------------------- Actions */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $back   = '/connector.php?id=' . $config['id'];

    // Actions accessibles à tous les détenteurs d'un accès.
    if ($action === 'regenerate') {
        grant_regenerate($grant);
        flash('ok', 'Nouveau token généré. L\'ancienne URL ne fonctionne plus : mettez à jour Claude.');
        redirect($back);
    }
    if ($action === 'leave' && !$isOwner) {
        grant_delete((int) $grant['id']);
        flash('ok', 'Vous n\'avez plus accès à ce connecteur.');
        redirect('/dashboard.php');
    }

    // Actions réservées au propriétaire.
    if (!$isOwner) {
        http_response_code(403);
        exit('Action réservée au propriétaire.');
    }

    switch ($action) {
        case 'save':
            $name = trim((string) ($_POST['name'] ?? ''));
            if ($name !== '' && $name !== $config['name']) {
                config_rename($config, mb_str_limit($name, 120));
            }
            $patch = [
                'client_id' => trim((string) ($_POST['client_id'] ?? '')),
                'org_mode'  => !empty($_POST['org_mode']),
            ];
            $secret = trim((string) ($_POST['client_secret'] ?? ''));
            if ($secret !== '') { // vide = conserver l'existant
                $patch['client_secret'] = $secret;
            }
            $orgUrn = trim((string) ($_POST['org_urn'] ?? ''));
            if ($orgUrn !== '' && ctype_digit($orgUrn)) {
                $orgUrn = 'urn:li:organization:' . $orgUrn;
            }
            $patch['org_urn'] = $orgUrn;
            config_update_settings($config, $patch);
            flash('ok', 'Réglages enregistrés.');
            redirect($back);

        case 'disconnect':
            config_update_settings($config, [
                'access_token' => null, 'token_expires_at' => null,
                'member_urn' => null, 'member_name' => null, 'granted_scopes' => null,
            ]);
            flash('ok', 'LinkedIn déconnecté de ce connecteur.');
            redirect($back);

        case 'test':
            // Appelle GET /v2/userinfo avec le token stocké : vérifie en un clic
            // que la connexion LinkedIn est réellement opérationnelle.
            $settings = config_settings($config);
            if (empty($settings['access_token'])) {
                flash('error', 'LinkedIn n\'est pas connecté sur ce connecteur.');
            } else {
                try {
                    [$status, , $data] = li_http('GET', 'https://api.linkedin.com/v2/userinfo', [
                        'Authorization: Bearer ' . $settings['access_token'],
                    ]);
                } catch (RuntimeException $e) {
                    flash('error', $e->getMessage());
                    redirect($back);
                }
                if ($status === 200 && !empty($data['sub'])) {
                    flash('ok', 'Connexion opérationnelle — /v2/userinfo répond : '
                        . ($data['name'] ?? '?') . (isset($data['email']) ? ' <' . $data['email'] . '>' : '')
                        . ' (urn:li:person:' . $data['sub'] . ').');
                } else {
                    flash('error', 'GET /v2/userinfo a répondu HTTP ' . $status . ' : '
                        . ($data['message'] ?? $data['error'] ?? 'réponse vide')
                        . ' — reconnectez LinkedIn ; si l\'erreur persiste, vérifiez les produits activés sur votre app.');
                }
            }
            redirect($back);

        case 'share':
            $err = share_add($config, (string) ($_POST['email'] ?? ''));
            flash($err === null ? 'ok' : 'error', $err ?? 'Connecteur partagé : la personne le retrouvera dans son espace après connexion, avec sa propre URL d\'accès. Une notification lui a été envoyée par email.');
            redirect($back);

        case 'unshare':
            $target = q('SELECT * FROM mcp_grants WHERE id = ? AND config_id = ? AND role = "shared"',
                [(int) ($_POST['grant_id'] ?? 0), $config['id']])->fetch();
            if ($target) {
                grant_delete((int) $target['id']);
                flash('ok', 'Accès révoqué : son URL d\'endpoint est immédiatement désactivée.');
            }
            redirect($back);

        case 'delete':
            config_delete((int) $config['id']);
            flash('ok', 'Connecteur supprimé. Tous les accès et tokens associés sont révoqués.');
            redirect('/dashboard.php');
    }
    redirect($back);
}

/* -------------------------------------------------------------- Rendu */
$settings = config_settings($config);
$summary  = ($type['summary_fn'])($settings);
$endpoint = grant_endpoint_url($grant);
$slug     = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($config['name'])) ?? '', '-') ?: $config['type'];

$statusBadge = $summary['connected']
    ? ($summary['expired'] ? ui_badge('Token expiré', 'warn') : ui_badge('Connecté', 'ok'))
    : ui_badge('À configurer', 'warn');

ui_top($config['name'], $user, true);
ui_page_header(
    $config['name'],
    $isOwner ? 'Connecteur ' . $type['label'] . ' — vous en êtes propriétaire.'
             : 'Connecteur ' . $type['label'] . ' partagé par ' . ($config ? user_by_id((int) $config['owner_id'])['email'] : ''),
    $statusBadge
);

/* ---- 1. Réglages (propriétaire uniquement) --------------------------- */
if ($isOwner) {
    $hasInstanceApp = LINKEDIN_DEFAULT_CLIENT_ID !== '' && LINKEDIN_DEFAULT_CLIENT_SECRET !== '';
    ui_card_open('Réglages', 'Seules les informations strictement nécessaires sont demandées.', 1);
    echo '<form method="post">' . csrf_field()
        . '<input type="hidden" name="id" value="' . (int) $config['id'] . '">'
        . '<input type="hidden" name="action" value="save">';

    ui_field(['label' => 'Nom du connecteur', 'name' => 'name', 'value' => $config['name'], 'required' => true]);

    if ($hasInstanceApp) {
        echo '<p class="hint" style="margin-bottom:18px">' . ui_icon('check')
            . ' Cette instance fournit déjà une app LinkedIn : vous n\'avez rien à renseigner ci-dessous, sauf pour utiliser la vôtre.</p>';
    }
    ui_field([
        'label' => 'Client ID LinkedIn' . ($hasInstanceApp ? ' (facultatif)' : ''),
        'name' => 'client_id', 'value' => $settings['client_id'] ?? '',
        'hint' => 'Créez une app gratuite sur <a href="https://developer.linkedin.com/" target="_blank" rel="noopener">developer.linkedin.com</a> puis copiez son Client ID (onglet Auth). Guide détaillé dans le README.',
    ]);
    ui_field([
        'label' => 'Client Secret LinkedIn' . ($hasInstanceApp ? ' (facultatif)' : ''),
        'name' => 'client_secret', 'type' => 'password',
        'placeholder' => !empty($settings['client_secret']) ? '••••••••  (enregistré — laisser vide pour conserver)' : '',
        'hint' => 'Stocké chiffré (AES-256-GCM). Jamais visible par les personnes avec qui vous partagez.',
        'autocomplete' => 'off',
    ]);

    echo '<details class="disclosure"' . (!empty($settings['org_mode']) ? ' open' : '') . '><summary>Page organisation — publier en tant que page et statistiques (facultatif)</summary>';
    ui_checkbox('org_mode', 'Activer le mode organisation', !empty($settings['org_mode']),
        'Permet de publier au nom de votre page entreprise (vous devez en être admin) et ajoute ses statistiques (impressions, clics, engagement, abonnés). Nécessite le produit gratuit « Community Management API » sur votre app LinkedIn, puis un clic sur « Reconnecter » pour accorder les nouvelles autorisations. Sans ce mode, les posts partent au nom de votre profil.');
    ui_field([
        'label' => 'Page organisation', 'name' => 'org_urn',
        'value' => $settings['org_urn'] ?? '',
        'placeholder' => 'urn:li:organization:12345678 ou simplement 12345678',
        'hint' => 'L\'identifiant apparaît dans l\'URL d\'admin de votre page : linkedin.com/company/<strong>12345678</strong>/admin.',
    ]);
    echo '</details>';

    echo '<div style="margin-top:20px"><button type="submit" class="btn btn-primary">Enregistrer</button></div></form>';
    ui_card_close();

    /* ---- 2. Connexion LinkedIn --------------------------------------- */
    ui_card_open('Connexion LinkedIn', '', 2);
    if ($summary['connected']) {
        echo '<div class="rows"><div class="row"><div class="row-main">'
            . '<strong>' . e($summary['member_name'] ?: 'Profil connecté') . '</strong>'
            . '<span>' . ($summary['expired']
                ? 'Token expiré — reconnectez-vous pour réactiver les outils.'
                : 'Token valable jusqu\'au ' . e(format_date($summary['expires_at'], true))
                  . ' (LinkedIn limite les tokens à 60 jours).') . '</span>'
            . '</div><div class="row-actions">';
        ui_post_button(base_url('/connector.php'), ['id' => $config['id'], 'action' => 'test'],
            'Tester la connexion', 'btn btn-ghost btn-sm', '', 'check');
        echo '<a class="btn btn-ghost btn-sm" href="' . e(base_url('/oauth-linkedin.php?action=start&id=' . $config['id'])) . '">' . ui_icon('refresh') . 'Reconnecter</a>';
        ui_post_button(base_url('/connector.php'), ['id' => $config['id'], 'action' => 'disconnect'], 'Déconnecter', 'btn btn-danger btn-sm');
        echo '</div></div></div>';
        if (!empty($settings['granted_scopes'])) {
            echo '<p class="hint">Scopes accordés par LinkedIn : <code>' . e(str_replace(',', ' ', $settings['granted_scopes'])) . '</code></p>';
        }
    } else {
        $ready = linkedin_client_id($settings) !== '' && linkedin_client_secret($settings) !== '';
        echo '<p class="muted" style="margin-bottom:16px">Autorisez l\'application à publier en votre nom :'
            . ' LinkedIn affichera un écran de consentement pour les autorisations ci-dessous.</p>';

        // Diagnostic : chaque scope demandé doit être couvert par un produit
        // actif sur l'app LinkedIn, sinon LinkedIn refuse l'autorisation
        // (« Invalid scope ») avant même l'écran de consentement.
        $byProduct = [];
        foreach (linkedin_scopes($settings) as $scope => $product) {
            $byProduct[$product][] = $scope;
        }
        echo '<div class="rows" style="margin-bottom:16px">';
        foreach ($byProduct as $product => $scopes) {
            echo '<div class="row"><div class="row-main">'
                . '<strong><code>' . e(implode(' ', $scopes)) . '</code></strong>'
                . '<span>Nécessite le produit « ' . e($product) . ' » (onglet Products de votre app LinkedIn).</span>'
                . '</div></div>';
        }
        echo '</div>';

        if ($ready) {
            echo '<a class="btn btn-primary" href="' . e(base_url('/oauth-linkedin.php?action=start&id=' . $config['id'])) . '">' . ui_icon('linkedin') . 'Connecter LinkedIn</a>';
        } else {
            echo '<p class="hint">Renseignez d\'abord le Client ID et le Client Secret ci-dessus.</p>';
        }
        ui_copy_row('URL de redirection à déclarer dans votre app LinkedIn (onglet Auth)', base_url('/oauth-linkedin.php'));
    }
    ui_card_close();
}

/* ---- 3. Endpoint MCP + instructions Claude --------------------------- */
$mcpBase = base_url('/mcp.php');
$token   = grant_token($grant);
ui_card_open('Utiliser avec Claude', 'Votre token vaut authentification : ne le publiez pas.', 3);
ui_copy_row('Token d\'accès', $token,
    'Transmis via l\'en-tête Authorization ci-dessous — il n\'apparaît ainsi pas dans les journaux du serveur.'
    . ($isOwner ? '' : ' Ce token vous est propre : le propriétaire peut le révoquer sans affecter les autres.'));
ui_code_block('Claude Code — une seule commande', 'claude mcp add --transport http ' . $slug . ' ' . $mcpBase . ' --header "Authorization: Bearer ' . $token . '"');
ui_code_block('Ou dans un fichier .mcp.json (à la racine du projet)', json_encode(
    ['mcpServers' => [$slug => ['type' => 'http', 'url' => $mcpBase, 'headers' => ['Authorization' => 'Bearer ' . $token]]]],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
));
echo '<details class="disclosure"><summary>Client sans en-têtes personnalisés (claude.ai, etc.)</summary>';
ui_copy_row('URL avec token intégré', $endpoint,
    'Pratique quand le client n\'accepte qu\'une URL (claude.ai → Paramètres → Connecteurs → « Ajouter un connecteur personnalisé »). À éviter si possible : le token figure alors dans les journaux d\'accès du serveur.');
echo '</details>';

echo '<div style="margin-top:16px">';
ui_post_button(base_url('/connector.php'), ['id' => $config['id'], 'action' => 'regenerate'],
    'Régénérer mon token', 'btn btn-ghost btn-sm',
    'Régénérer ? L\'URL actuelle cessera immédiatement de fonctionner.', 'key');
echo '</div>';
ui_card_close();

/* ---- 4. Partage (propriétaire) / Quitter (invité) -------------------- */
if ($isOwner) {
    ui_card_open('Partage', 'Chaque personne reçoit sa propre URL, révocable individuellement. Vos secrets restent invisibles.', 4);
    $shares = grants_shared((int) $config['id']);
    if ($shares !== []) {
        echo '<div class="rows">';
        foreach ($shares as $share) {
            echo '<div class="row"><div class="row-main">'
                . '<strong>' . e($share['email']) . '</strong>'
                . '<span>Partagé le ' . e(format_date($share['created_at']))
                . ($share['last_used_at'] ? ' · utilisé le ' . e(format_date($share['last_used_at'])) : ' · jamais utilisé')
                . '</span></div><div class="row-actions">';
            ui_post_button(base_url('/connector.php'), ['id' => $config['id'], 'action' => 'unshare', 'grant_id' => $share['id']],
                'Révoquer', 'btn btn-danger btn-sm',
                'Révoquer l\'accès de ' . $share['email'] . ' ? Son URL sera désactivée immédiatement.');
            echo '</div></div>';
        }
        echo '</div>';
    }
    echo '<form method="post" class="copy-row" style="margin-top:' . ($shares !== [] ? '16px' : '0') . '">' . csrf_field()
        . '<input type="hidden" name="id" value="' . (int) $config['id'] . '">'
        . '<input type="hidden" name="action" value="share">'
        . '<input class="input" type="email" name="email" placeholder="email@exemple.com" aria-label="Adresse email de la personne avec qui partager" required>'
        . '<button type="submit" class="btn btn-primary">' . ui_icon('share') . 'Partager</button></form>';
    ui_card_close();

    /* ---- 5. Zone de danger ------------------------------------------- */
    ui_card_open('Zone de danger', '', 5);
    echo '<div class="rows"><div class="row"><div class="row-main">'
        . '<strong>Supprimer ce connecteur</strong>'
        . '<span>Révoque définitivement tous les accès, tokens et la connexion LinkedIn associés.</span>'
        . '</div><div class="row-actions">';
    ui_post_button(base_url('/connector.php'), ['id' => $config['id'], 'action' => 'delete'],
        'Supprimer', 'btn btn-danger btn-sm',
        'Supprimer définitivement « ' . $config['name'] . '» ? Cette action est irréversible.', 'trash');
    echo '</div></div></div>';
    ui_card_close();
} else {
    ui_card_open('Votre accès', '', 4);
    echo '<div class="rows"><div class="row"><div class="row-main">'
        . '<strong>Quitter ce partage</strong>'
        . '<span>Votre URL d\'endpoint sera désactivée. Le propriétaire pourra vous réinviter.</span>'
        . '</div><div class="row-actions">';
    ui_post_button(base_url('/connector.php'), ['id' => $config['id'], 'action' => 'leave'],
        'Quitter', 'btn btn-danger btn-sm', 'Quitter ce partage ?');
    echo '</div></div></div>';
    ui_card_close();
}

ui_bottom();
