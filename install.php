<?php
/**
 * Installateur : vérifie l'environnement puis crée les tables (schema.sql).
 * À ouvrir dans le navigateur après avoir créé config.php.
 * Se verrouille de lui-même une fois l'application installée.
 */

// Sans config.php, on affiche les instructions sans dépendre du bootstrap.
if (!is_file(__DIR__ . '/config.php')) {
    $suggestedKey = bin2hex(random_bytes(32));
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Installation</title>'
        . '<link rel="stylesheet" href="assets/css/app.css"></head><body>'
        . '<main class="container"><section class="card rise"><header class="card-head">'
        . '<h2>1/2 — Créez votre fichier de configuration</h2>'
        . '<p class="muted">config.php est introuvable à la racine du site.</p></header>'
        . '<div class="steps">'
        . '<div class="step"><div><strong>Copiez config.sample.php en config.php</strong>'
        . '<p>Sur votre hébergement, dupliquez le fichier puis renommez la copie.</p></div></div>'
        . '<div class="step"><div><strong>Renseignez la base de données et l\'URL</strong>'
        . '<p>DB_HOST, DB_NAME, DB_USER, DB_PASS et APP_URL (l\'adresse publique du site).</p></div></div>'
        . '<div class="step"><div><strong>Collez cette clé générée pour vous dans APP_KEY</strong>'
        . '<p style="word-break:break-all"><code>' . htmlspecialchars($suggestedKey) . '</code></p></div></div>'
        . '<div class="step"><div><strong>Rechargez cette page</strong>'
        . '<p>L\'installateur vérifiera votre environnement puis créera les tables.</p></div></div>'
        . '</div></section></main></body></html>';
    exit;
}

require __DIR__ . '/app/bootstrap.php';

/* ---------------------------------------------------------- Diagnostics */
$checks = [
    ['PHP 8.1 ou plus (actuel : ' . PHP_VERSION . ')', PHP_VERSION_ID >= 80100],
    ['Extension pdo_mysql', extension_loaded('pdo_mysql')],
    ['Extension openssl', extension_loaded('openssl')],
    ['Extension curl', extension_loaded('curl')],
    ['APP_KEY définie (64 caractères hexadécimaux)', strlen((string) APP_KEY) === 64 && ctype_xdigit((string) APP_KEY)],
    ['APP_URL définie sans slash final', APP_URL !== '' && !str_ends_with(APP_URL, '/')],
];

$dbError   = null;
$installed = false;
try {
    db();
    $checks[] = ['Connexion à la base « ' . DB_NAME . ' »', true];
    $installed = (bool) q("SHOW TABLES LIKE 'users'")->fetch();
} catch (PDOException $e) {
    $dbError  = $e->getMessage();
    $checks[] = ['Connexion à la base « ' . DB_NAME . ' »', false];
}

$allOk = !in_array(false, array_column($checks, 1), true);

/* --------------------------------------------------------- Installation */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $allOk && !$installed) {
    csrf_check();
    $sql = (string) file_get_contents(__DIR__ . '/schema.sql');
    $sql = preg_replace('/^\s*--.*$/m', '', $sql); // retire les commentaires
    try {
        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) as $statement) {
            $statement = trim($statement);
            if ($statement !== '') {
                db()->exec($statement);
            }
        }
        flash('ok', 'Tables créées : l\'application est prête.');
    } catch (PDOException $e) {
        flash('error', 'Échec de la création des tables : ' . $e->getMessage());
    }
    redirect('/install.php');
}

/* ---------------------------------------------------------------- Rendu */

// Une fois installé, ne rien divulguer de l'environnement (version PHP, nom de
// base, erreurs de connexion) : page minimale rappelant de supprimer le fichier.
if ($installed) {
    ui_top('Installation');
    ui_page_header('Installation terminée', 'Application déjà installée.');
    ui_card_open('Tout est prêt', '', 1);
    echo '<p class="muted" style="margin-bottom:16px">Par sécurité, supprimez maintenant <code>install.php</code> de votre hébergement.</p>'
        . '<a class="btn btn-primary" href="' . e(base_url('/login.php')) . '">Se connecter</a>';
    ui_card_close();
    ui_bottom();
    exit;
}

ui_top('Installation');
ui_page_header('Installation', '2/2 — Vérification de l\'environnement.');

ui_card_open('Environnement', '', 1);
echo '<div class="rows">';
foreach ($checks as [$label, $ok]) {
    echo '<div class="row"><div class="row-main"><strong>' . e($label) . '</strong>'
        . (!$ok && str_contains($label, 'base') && $dbError ? '<span>' . e($dbError) . '</span>' : '')
        . '</div>' . ui_badge($ok ? 'OK' : 'À corriger', $ok ? 'ok' : 'warn') . '</div>';
}
echo '</div>';
ui_card_close();

// Aide : si l'APP_KEY est absente/invalide, on en propose une, prête à coller.
$appKeyOk = strlen((string) APP_KEY) === 64 && ctype_xdigit((string) APP_KEY);
if (!$appKeyOk) {
    ui_card_open('Générer votre APP_KEY', 'Collez cette clé dans la constante APP_KEY de config.php, puis rechargez.', 2);
    ui_copy_row('APP_KEY', bin2hex(random_bytes(32)),
        'Clé de chiffrement à ne plus modifier ensuite : les données chiffrées deviendraient illisibles.');
    ui_card_close();
}

if ($allOk) {
    ui_card_open('Créer les tables', 'Importe schema.sql dans la base ' . DB_NAME . '.', 3);
    echo '<form method="post">' . csrf_field()
        . '<button type="submit" class="btn btn-primary">Installer</button></form>';
    ui_card_close();
} else {
    ui_card_open('Corrigez les points ci-dessus', '', 3);
    echo '<p class="muted">Modifiez config.php (ou contactez votre hébergeur pour les extensions) puis rechargez cette page.</p>';
    ui_card_close();
}

ui_bottom();
