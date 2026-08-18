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
            // Les réglages propres au type sont enregistrés par le connecteur
            // lui-même, qui peut compléter le message de confirmation.
            $extra = isset($type['settings_save_fn'])
                ? (string) ($type['settings_save_fn'])($config, $_POST) : '';
            flash('ok', 'Réglages enregistrés.' . ($extra !== '' ? ' ' . $extra : ''));
            redirect($back);

        case 'disconnect':
            flash('ok', isset($type['disconnect_fn'])
                ? (string) ($type['disconnect_fn'])($config)
                : 'Connexion supprimée de ce connecteur.');
            redirect($back);

        case 'test':
            // Vérifie en un clic que la connexion est opérationnelle : chaque
            // type interroge l'endpoint d'identité qui lui correspond.
            if (!isset($type['test_fn'])) {
                flash('error', 'Ce type de connecteur ne propose pas de test de connexion.');
                redirect($back);
            }
            try {
                flash('ok', (string) ($type['test_fn'])(config_settings($config)));
            } catch (RuntimeException $e) {
                flash('error', $e->getMessage());
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

    // Actions supplémentaires propres au type de connecteur (diagnostics…).
    if (isset($type['action_fn'])) {
        $handled = ($type['action_fn'])($config, $action);
        if ($handled !== null) {
            flash($handled[0], $handled[1]);
        }
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
    ui_card_open('Réglages', 'Seules les informations strictement nécessaires sont demandées.', 1);
    echo '<form method="post">' . csrf_field()
        . '<input type="hidden" name="id" value="' . (int) $config['id'] . '">'
        . '<input type="hidden" name="action" value="save">';

    ui_field(['label' => 'Nom du connecteur', 'name' => 'name', 'value' => $config['name'], 'required' => true]);

    // Champs propres au type de connecteur (identifiants d'app, options…).
    if (isset($type['settings_form_fn'])) {
        ($type['settings_form_fn'])($config, $settings);
    }

    echo '<div style="margin-top:20px"><button type="submit" class="btn btn-primary">Enregistrer</button></div></form>';
    ui_card_close();

    /* ---- 2. Connexion au service ------------------------------------- */
    if (isset($type['connect_card_fn'])) {
        ($type['connect_card_fn'])($config, $settings, $summary);
    }
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

echo '<div class="row-actions" style="margin-top:16px">'
    . '<a class="btn btn-ghost btn-sm" href="' . e(base_url('/tools.php?id=' . $config['id'])) . '">'
    . ui_icon('terminal') . 'Outils exposés</a>';
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
