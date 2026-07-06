<?php
/**
 * Tableau de bord : mes connecteurs, connecteurs partagés avec moi,
 * catalogue des types disponibles.
 */

require __DIR__ . '/app/bootstrap.php';
$user = require_login();

// Création d'un connecteur depuis le catalogue.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    csrf_check();
    $typeKey = (string) ($_POST['type'] ?? '');
    $type    = mcp_type($typeKey);
    if ($type === null) {
        flash('error', 'Type de connecteur inconnu.');
        redirect('/dashboard.php');
    }
    $config = config_create((int) $user['id'], $typeKey, $type['label']);
    flash('ok', 'Connecteur créé. Configurez-le, il ne demande que le strict nécessaire.');
    redirect('/connector.php?id=' . $config['id']);
}

$owned  = configs_owned_by((int) $user['id']);
$shared = configs_shared_with((int) $user['id']);

ui_top('Tableau de bord', $user, true);
ui_page_header(
    'Bonjour 👋',
    'Gérez vos connecteurs MCP et branchez-les à Claude Code.'
);

/* ------------------------------------------------------- Mes connecteurs */
echo '<h2 class="rise" style="margin-bottom:14px">Mes connecteurs</h2>';

if ($owned === []) {
    ui_empty(
        'bolt',
        'Aucun connecteur pour le moment',
        'Choisissez un type dans le catalogue ci-dessous : la configuration prend moins de deux minutes.'
    );
} else {
    echo '<div class="grid">';
    foreach ($owned as $i => $config) {
        $summary = (mcp_type($config['type'])['summary_fn'])(config_settings($config));
        $badge   = $summary['connected']
            ? ($summary['expired'] ? ui_badge('Token expiré', 'warn') : ui_badge('Connecté', 'ok'))
            : ui_badge('À configurer', 'warn');
        $meta = (int) $config['share_count'] > 0
            ? 'Partagé avec ' . $config['share_count'] . ' personne' . ($config['share_count'] > 1 ? 's' : '')
            : 'Privé';
        ui_config_card($config, base_url('/connector.php?id=' . $config['id']), $badge, $meta, $i + 1);
    }
    echo '</div>';
}

/* -------------------------------------------------- Partagés avec moi */
if ($shared !== []) {
    echo '<h2 class="rise" style="margin:10px 0 14px">Partagés avec moi</h2><div class="grid">';
    foreach ($shared as $i => $config) {
        $summary = (mcp_type($config['type'])['summary_fn'])(config_settings($config));
        $badge   = $summary['connected'] && !$summary['expired']
            ? ui_badge('Actif', 'ok')
            : ui_badge('En attente du propriétaire', 'warn');
        ui_config_card($config, base_url('/connector.php?id=' . $config['id']), $badge, 'Partagé par ' . $config['owner_email'], $i + 1);
    }
    echo '</div>';
}

/* ------------------------------------------------------------- Catalogue */
echo '<h2 class="rise" style="margin:10px 0 14px">Catalogue</h2><div class="grid">';
$i = 0;
foreach (mcp_types() as $key => $type) {
    ui_type_card($key, $type, ++$i);
}
echo '</div>';

ui_bottom();
