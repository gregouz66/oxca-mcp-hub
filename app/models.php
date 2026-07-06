<?php
/**
 * Accès aux données : utilisateurs, configs MCP, grants (accès + tokens).
 *
 * Vocabulaire :
 *   - config : une instance configurée d'un type de MCP (ex. « LinkedIn »),
 *     appartenant à un utilisateur.
 *   - grant  : le droit d'un utilisateur d'utiliser une config, matérialisé
 *     par un token d'endpoint qui lui est propre. Le propriétaire a un grant
 *     role='owner' ; chaque partage crée un grant role='shared'.
 */

/* ------------------------------------------------------------ Types MCP */

/**
 * Catalogue des types de MCP proposés par l'application.
 * Pour ajouter un type : créer app/mcp/<type>.php (voir README, section
 * « Ajouter un type de MCP ») puis le déclarer ici.
 */
function mcp_types(): array
{
    return [
        'linkedin' => [
            'label'       => 'LinkedIn',
            'tagline'     => 'Publiez des posts, commentez, réagissez et suivez vos statistiques LinkedIn depuis Claude.',
            'icon'        => 'linkedin',
            'tools_fn'    => 'linkedin_tools',
            'call_fn'     => 'linkedin_call',
            'summary_fn'  => 'linkedin_summary',
        ],
    ];
}

function mcp_type(string $type): ?array
{
    return mcp_types()[$type] ?? null;
}

/* -------------------------------------------------------------- Users */

function user_by_id(int $id): ?array
{
    return q('SELECT * FROM users WHERE id = ?', [$id])->fetch() ?: null;
}

function user_by_email(string $email): ?array
{
    return q('SELECT * FROM users WHERE email = ?', [normalize_email($email)])->fetch() ?: null;
}

function user_create(string $email): array
{
    q('INSERT INTO users (email, created_at) VALUES (?, ?)', [normalize_email($email), now()]);
    return user_by_id((int) db()->lastInsertId());
}

/* ------------------------------------------------------------ Configs */

/** Crée une config + le grant du propriétaire. Retourne la config. */
function config_create(int $ownerId, string $type, string $name): array
{
    if (mcp_type($type) === null) {
        throw new InvalidArgumentException("Type de MCP inconnu : $type");
    }
    q(
        'INSERT INTO mcp_configs (owner_id, type, name, settings, created_at, updated_at) VALUES (?,?,?,?,?,?)',
        [$ownerId, $type, $name, '{}', now(), now()]
    );
    $id = (int) db()->lastInsertId();
    grant_create($id, $ownerId, 'owner');
    return config_get($id);
}

function config_get(int $id): ?array
{
    return q('SELECT * FROM mcp_configs WHERE id = ?', [$id])->fetch() ?: null;
}

function configs_owned_by(int $userId): array
{
    return q(
        'SELECT c.*, (SELECT COUNT(*) FROM mcp_grants g WHERE g.config_id = c.id AND g.role = "shared") AS share_count
         FROM mcp_configs c WHERE c.owner_id = ? ORDER BY c.created_at DESC',
        [$userId]
    )->fetchAll();
}

function configs_shared_with(int $userId): array
{
    return q(
        'SELECT c.*, u.email AS owner_email, g.id AS grant_id
         FROM mcp_grants g
         JOIN mcp_configs c ON c.id = g.config_id
         JOIN users u ON u.id = c.owner_id
         WHERE g.user_id = ? AND g.role = "shared"
         ORDER BY g.created_at DESC',
        [$userId]
    )->fetchAll();
}

function config_delete(int $id): void
{
    q('DELETE FROM mcp_configs WHERE id = ?', [$id]); // grants supprimés en cascade
}

/* --------------------------------------------------- Réglages (settings) */

/** Clés chiffrées au repos dans la colonne `settings`, par type de MCP. */
function config_sensitive_keys(string $type): array
{
    return match ($type) {
        'linkedin' => ['client_secret', 'access_token'],
        default    => [],
    };
}

/** Réglages décodés et déchiffrés d'une config. */
function config_settings(array $config): array
{
    $settings = json_decode($config['settings'], true) ?: [];
    foreach (config_sensitive_keys($config['type']) as $key) {
        if (isset($settings[$key])) {
            $settings[$key] = decrypt_value($settings[$key]);
        }
    }
    return $settings;
}

/** Fusionne $patch dans les réglages (chiffre les clés sensibles). Une valeur null supprime la clé. */
function config_update_settings(array $config, array $patch): void
{
    $settings = config_settings($config);
    foreach ($patch as $key => $value) {
        if ($value === null) {
            unset($settings[$key]);
        } else {
            $settings[$key] = $value;
        }
    }
    foreach (config_sensitive_keys($config['type']) as $key) {
        if (isset($settings[$key]) && $settings[$key] !== '') {
            $settings[$key] = encrypt_value((string) $settings[$key]);
        }
    }
    q(
        'UPDATE mcp_configs SET settings = ?, updated_at = ? WHERE id = ?',
        [json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), now(), $config['id']]
    );
}

function config_rename(array $config, string $name): void
{
    q('UPDATE mcp_configs SET name = ?, updated_at = ? WHERE id = ?', [$name, now(), $config['id']]);
}

/* -------------------------------------------------------------- Grants */

/** Crée un grant + son token d'endpoint. Retourne le token en clair. */
function grant_create(int $configId, int $userId, string $role = 'shared'): string
{
    $token = 'oxm_' . random_hex(24);
    q(
        'INSERT INTO mcp_grants (config_id, user_id, role, token_hash, token_enc, created_at) VALUES (?,?,?,?,?,?)',
        [$configId, $userId, $role, token_hash($token), encrypt_value($token), now()]
    );
    return $token;
}

/** Grant d'un utilisateur sur une config (ou null). */
function grant_for(int $configId, int $userId): ?array
{
    return q('SELECT * FROM mcp_grants WHERE config_id = ? AND user_id = ?', [$configId, $userId])->fetch() ?: null;
}

/** Tous les partages (role='shared') d'une config, avec l'email du bénéficiaire. */
function grants_shared(int $configId): array
{
    return q(
        'SELECT g.*, u.email FROM mcp_grants g JOIN users u ON u.id = g.user_id
         WHERE g.config_id = ? AND g.role = "shared" ORDER BY g.created_at ASC',
        [$configId]
    )->fetchAll();
}

/** Token en clair d'un grant (pour affichage de l'URL d'endpoint). */
function grant_token(array $grant): string
{
    return (string) decrypt_value($grant['token_enc']);
}

/** URL d'endpoint MCP correspondant à un grant. */
function grant_endpoint_url(array $grant): string
{
    return base_url('/mcp.php?t=' . grant_token($grant));
}

/** Régénère le token d'un grant (l'ancien cesse immédiatement de fonctionner). */
function grant_regenerate(array $grant): string
{
    $token = 'oxm_' . random_hex(24);
    q(
        'UPDATE mcp_grants SET token_hash = ?, token_enc = ? WHERE id = ?',
        [token_hash($token), encrypt_value($token), $grant['id']]
    );
    return $token;
}

function grant_delete(int $grantId): void
{
    q('DELETE FROM mcp_grants WHERE id = ?', [$grantId]);
}

/**
 * Résout un token d'endpoint : retourne [grant, config] ou null.
 * Recherche par empreinte SHA-256, en temps constant côté token.
 */
function grant_by_token(string $token): ?array
{
    if (!preg_match('/^oxm_[0-9a-f]{48}$/', $token)) {
        return null;
    }
    $grant = q('SELECT * FROM mcp_grants WHERE token_hash = ?', [token_hash($token)])->fetch();
    if (!$grant) {
        return null;
    }
    $config = config_get((int) $grant['config_id']);
    if (!$config) {
        return null;
    }
    // Rafraîchit last_used_at au plus une fois par minute.
    if ($grant['last_used_at'] === null || $grant['last_used_at'] < gmdate('Y-m-d H:i:s', time() - 60)) {
        q('UPDATE mcp_grants SET last_used_at = ? WHERE id = ?', [now(), $grant['id']]);
    }
    return [$grant, $config];
}

/* ------------------------------------------------------------- Partage */

/**
 * Partage une config avec une adresse email. Si l'adresse est inconnue, un
 * compte est créé automatiquement (l'invité se connectera par email).
 * Retourne null en cas de succès, sinon un message d'erreur.
 */
function share_add(array $config, string $email): ?string
{
    $email = normalize_email($email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'Adresse email invalide.';
    }
    $user = user_by_email($email) ?? user_create($email);
    if ((int) $user['id'] === (int) $config['owner_id']) {
        return 'Vous êtes déjà propriétaire de ce connecteur.';
    }
    if (grant_for((int) $config['id'], (int) $user['id'])) {
        return 'Ce connecteur est déjà partagé avec cette adresse.';
    }
    grant_create((int) $config['id'], (int) $user['id'], 'shared');

    // Notification (best effort : le partage reste valable si l'email échoue).
    $owner = user_by_id((int) $config['owner_id']);
    $html  = mail_template('Un connecteur a été partagé avec vous', '
        <p style="font-size:15px;color:#424245;line-height:1.5;margin:0 0 20px;">'
        . e($owner['email']) . ' a partagé le connecteur « ' . e($config['name']) . ' » avec vous sur ' . e(APP_NAME) . '.</p>
        <p style="text-align:center;margin:0;">
          <a href="' . e(base_url('/login.php')) . '" style="display:inline-block;background:#0071e3;color:#ffffff;text-decoration:none;font-size:15px;padding:12px 28px;border-radius:980px;">Accéder à mon espace</a></p>');
    $text = $owner['email'] . ' a partagé le connecteur « ' . $config['name'] . ' » avec vous sur ' . APP_NAME . ".\n\n"
        . 'Connectez-vous (sans mot de passe) : ' . base_url('/login.php');
    send_mail($email, 'Connecteur partagé avec vous — ' . APP_NAME, $html, $text);

    return null;
}
