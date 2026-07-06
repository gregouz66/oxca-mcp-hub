<?php
/**
 * MCP LinkedIn : définitions des outils, exécution des appels et client API.
 *
 * Fonctionne avec les produits GRATUITS du portail développeur LinkedIn :
 *   - « Sign In with LinkedIn using OpenID Connect » (identité)
 *   - « Share on LinkedIn » (publication, commentaires, réactions — scope
 *     w_member_social)
 *   - optionnel : « Community Management API » (statistiques de page
 *     entreprise — accès gratuit sur demande auprès de LinkedIn)
 *
 * NB : LinkedIn n'expose pas d'API de statistiques pour les posts d'un profil
 * personnel ; les outils de statistiques concernent les pages organisation.
 */

/* --------------------------------------------------------- Résumé (UI) */

/** État de la connexion LinkedIn d'une config, pour l'interface. */
function linkedin_summary(array $settings): array
{
    $expiresAt = $settings['token_expires_at'] ?? null;
    $connected = !empty($settings['access_token']);
    return [
        'connected'   => $connected,
        'expired'     => $connected && $expiresAt !== null && $expiresAt < now(),
        'member_name' => $settings['member_name'] ?? null,
        'expires_at'  => $expiresAt,
        'org_mode'    => !empty($settings['org_mode']),
    ];
}

/** Client ID effectif (réglage utilisateur, sinon celui de l'instance). */
function linkedin_client_id(array $settings): string
{
    return trim((string) ($settings['client_id'] ?? '')) ?: (string) LINKEDIN_DEFAULT_CLIENT_ID;
}

/** Client Secret effectif (réglage utilisateur, sinon celui de l'instance). */
function linkedin_client_secret(array $settings): string
{
    return trim((string) ($settings['client_secret'] ?? '')) ?: (string) LINKEDIN_DEFAULT_CLIENT_SECRET;
}

/* ------------------------------------------------------------ OAuth 2 */

const LINKEDIN_OAUTH_REDIRECT = '/oauth-linkedin.php';

/**
 * Type d'app LinkedIn du connecteur :
 *   'signin'    — produits « Sign In with LinkedIn » + « Share on LinkedIn »
 *                 (ajout instantané) : publication au nom du profil uniquement.
 *   'community' — app dédiée au seul produit « Community Management API »
 *                 (règle LinkedIn : il doit être l'unique produit de l'app) :
 *                 publication profil + page, statistiques des posts personnels
 *                 et de la page.
 */
function linkedin_app_type(array $settings): string
{
    return ($settings['app_type'] ?? '') === 'community' ? 'community' : 'signin';
}

/**
 * Scopes OAuth demandés, avec le produit LinkedIn qui fournit chacun.
 * Sert au flux OAuth et à l'affichage de diagnostic sur la page connecteur.
 */
function linkedin_scopes(array $settings): array
{
    if (linkedin_app_type($settings) === 'community') {
        // La Community Management API fournit à elle seule l'identité
        // (r_basicprofile — pas d'openid sur ce produit), la publication
        // membre et page, et les statistiques.
        return [
            'r_basicprofile'         => 'Community Management API',
            'w_member_social'        => 'Community Management API',
            'w_organization_social'  => 'Community Management API',
            'r_organization_social'  => 'Community Management API',
            'rw_organization_admin'  => 'Community Management API',
            'r_member_postAnalytics' => 'Community Management API',
        ];
    }
    return [
        'openid'          => 'Sign In with LinkedIn using OpenID Connect',
        'profile'         => 'Sign In with LinkedIn using OpenID Connect',
        'email'           => 'Sign In with LinkedIn using OpenID Connect',
        'w_member_social' => 'Share on LinkedIn',
    ];
}

/** URL d'autorisation LinkedIn (démarrage du flux OAuth). */
function linkedin_oauth_url(array $settings, string $state): string
{
    // PHP_QUERY_RFC3986 : la doc LinkedIn exige un scope « URL-encoded,
    // space-delimited » avec %20 (pas +).
    return 'https://www.linkedin.com/oauth/v2/authorization?' . http_build_query([
        'response_type' => 'code',
        'client_id'     => linkedin_client_id($settings),
        'redirect_uri'  => base_url(LINKEDIN_OAUTH_REDIRECT),
        'scope'         => implode(' ', array_keys(linkedin_scopes($settings))),
        'state'         => $state,
    ], '', '&', PHP_QUERY_RFC3986);
}

/**
 * Échange le code OAuth contre un token puis récupère le profil OpenID.
 * Retourne le patch de réglages à enregistrer. Lève RuntimeException en échec.
 */
function linkedin_oauth_exchange(array $settings, string $code): array
{
    [$status, , $data] = li_http('POST', 'https://www.linkedin.com/oauth/v2/accessToken', [
        'Content-Type: application/x-www-form-urlencoded',
    ], http_build_query([
        'grant_type'    => 'authorization_code',
        'code'          => $code,
        'client_id'     => linkedin_client_id($settings),
        'client_secret' => linkedin_client_secret($settings),
        'redirect_uri'  => base_url(LINKEDIN_OAUTH_REDIRECT),
    ]));
    if ($status !== 200 || empty($data['access_token'])) {
        throw new RuntimeException('Échange du code OAuth refusé par LinkedIn (HTTP ' . $status . ') : '
            . ($data['error_description'] ?? $data['error'] ?? 'réponse vide')
            . ' — vérifiez que le Client ID / Client Secret correspondent à votre app et que l\'URL de redirection déclarée est exactement celle affichée sur cette page.');
    }

    $token     = (string) $data['access_token'];
    $expiresAt = gmdate('Y-m-d H:i:s', time() + (int) ($data['expires_in'] ?? 5184000));
    $identity  = li_fetch_identity($settings, $token);

    return [
        'access_token'     => $token,
        'token_expires_at' => $expiresAt,
        // Scopes réellement accordés par LinkedIn, affichés pour diagnostic.
        'granted_scopes'   => (string) ($data['scope'] ?? ''),
        'member_urn'       => $identity['urn'],
        'member_name'      => $identity['name'],
    ];
}

/**
 * Identité du membre pour un token donné, selon le type d'app :
 * /v2/userinfo (scope openid, type « signin ») ou /v2/me (scope
 * r_basicprofile, app Community Management API — sans OpenID).
 * Lève RuntimeException avec un message actionnable.
 */
function li_fetch_identity(array $settings, string $token): array
{
    if (linkedin_app_type($settings) === 'community') {
        [$status, , $me] = li_http('GET', 'https://api.linkedin.com/v2/me', [
            'Authorization: Bearer ' . $token,
        ]);
        if ($status !== 200 || empty($me['id'])) {
            throw new RuntimeException('GET /v2/me a répondu HTTP ' . $status . ' : '
                . ($me['message'] ?? $me['error'] ?? 'réponse vide')
                . ' — le scope r_basicprofile a-t-il été accordé (produit « Community Management API ») ?');
        }
        return [
            'urn'   => 'urn:li:person:' . $me['id'],
            'name'  => trim(($me['localizedFirstName'] ?? '') . ' ' . ($me['localizedLastName'] ?? '')),
            'email' => null,
        ];
    }

    [$status, , $u] = li_http('GET', 'https://api.linkedin.com/v2/userinfo', [
        'Authorization: Bearer ' . $token,
    ]);
    if ($status !== 200 || empty($u['sub'])) {
        throw new RuntimeException('GET /v2/userinfo a répondu HTTP ' . $status . ' : '
            . ($u['message'] ?? $u['error'] ?? 'réponse vide')
            . ' — le scope openid a-t-il été accordé (produit « Sign In with LinkedIn using OpenID Connect ») ?');
    }
    return [
        'urn'   => 'urn:li:person:' . $u['sub'],
        'name'  => trim(($u['name'] ?? '') !== '' ? $u['name']
            : (($u['given_name'] ?? '') . ' ' . ($u['family_name'] ?? ''))),
        'email' => $u['email'] ?? null,
    ];
}

/* ---------------------------------------------------- Définition outils */

/** Liste des outils MCP exposés (les outils « organisation » exigent org_mode). */
function linkedin_tools(array $settings): array
{
    $tools = [
        [
            'name'        => 'linkedin_create_post',
            'description' => 'Publie un post LinkedIn. Par défaut au nom du profil connecté ; avec author=organization, au nom de la page entreprise configurée (connecteur de type Community Management API requis). Peut inclure un lien (article) avec titre et description. Retourne l\'URN et l\'URL publique du post.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'text' => ['type' => 'string', 'description' => 'Texte du post (max ~3000 caractères).'],
                    'author' => ['type' => 'string', 'enum' => ['member', 'organization'], 'description' => 'Qui signe le post : member = le profil connecté (défaut) ; organization = la page entreprise du connecteur (nécessite un connecteur de type Community Management API avec la page renseignée).'],
                    'link_url' => ['type' => 'string', 'description' => 'URL à partager (facultatif).'],
                    'link_title' => ['type' => 'string', 'description' => 'Titre affiché pour le lien (facultatif).'],
                    'link_description' => ['type' => 'string', 'description' => 'Description affichée pour le lien (facultatif).'],
                    'visibility' => ['type' => 'string', 'enum' => ['PUBLIC', 'CONNECTIONS'], 'description' => 'Visibilité du post (défaut : PUBLIC ; ignoré pour une page, toujours publique).'],
                    'disable_reshare' => ['type' => 'boolean', 'description' => 'Interdire le repartage (défaut : false).'],
                ],
                'required' => ['text'],
            ],
        ],
        [
            'name'        => 'linkedin_delete_post',
            'description' => 'Supprime un post publié via ce connecteur (URN urn:li:share:… ou urn:li:ugcPost:…).',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'post_urn' => ['type' => 'string', 'description' => 'URN du post à supprimer.'],
                ],
                'required' => ['post_urn'],
            ],
        ],
        [
            'name'        => 'linkedin_comment',
            'description' => 'Ajoute un commentaire sous un post LinkedIn au nom du profil connecté.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'post_urn' => ['type' => 'string', 'description' => 'URN du post à commenter (urn:li:share:… / urn:li:ugcPost:… / urn:li:activity:…).'],
                    'text'     => ['type' => 'string', 'description' => 'Texte du commentaire.'],
                ],
                'required' => ['post_urn', 'text'],
            ],
        ],
        [
            'name'        => 'linkedin_react',
            'description' => 'Réagit à un post LinkedIn (like, bravo, soutien, etc.) au nom du profil connecté.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'post_urn' => ['type' => 'string', 'description' => 'URN du post.'],
                    'reaction' => ['type' => 'string', 'enum' => ['LIKE', 'PRAISE', 'APPRECIATION', 'EMPATHY', 'INTEREST', 'ENTERTAINMENT'], 'description' => 'Type de réaction (défaut : LIKE).'],
                ],
                'required' => ['post_urn'],
            ],
        ],
        [
            'name'        => 'linkedin_get_profile',
            'description' => 'Retourne le profil LinkedIn connecté (nom, email, URN) — utile pour vérifier la connexion.',
            'inputSchema' => ['type' => 'object', 'properties' => new stdClass()],
        ],
    ];

    if (linkedin_app_type($settings) === 'community') {
        $tools[] = [
            'name'        => 'linkedin_my_post_stats',
            'description' => 'Statistiques de VOS posts personnels : impressions, membres atteints, réactions, commentaires, repartages. Sans post_urn : cumul sur l\'ensemble de vos posts ; avec post_urn : détail d\'un post. Période optionnelle (sinon : depuis toujours).',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'post_urn' => ['type' => 'string', 'description' => 'URN d\'un post précis (urn:li:share:… ou urn:li:ugcPost:…). Facultatif : sans lui, cumul de tous vos posts.'],
                    'metrics' => [
                        'type'        => 'array',
                        'items'       => ['type' => 'string', 'enum' => ['IMPRESSION', 'MEMBERS_REACHED', 'RESHARE', 'REACTION', 'COMMENT']],
                        'description' => 'Métriques à récupérer (défaut : les cinq).',
                    ],
                    'start_date' => ['type' => 'string', 'description' => 'Début de période AAAA-MM-JJ (inclus). Facultatif.'],
                    'end_date'   => ['type' => 'string', 'description' => 'Fin de période AAAA-MM-JJ (exclue). Facultatif.'],
                ],
            ],
        ];
    }

    if (linkedin_app_type($settings) === 'community' && trim((string) ($settings['org_urn'] ?? '')) !== '') {
        $tools[] = [
            'name'        => 'linkedin_org_share_stats',
            'description' => 'Statistiques de la page organisation LinkedIn : impressions, clics, réactions, commentaires, partages, taux d\'engagement. Sans argument : cumul sur l\'ensemble des posts ; avec post_urns : détail par post. Nécessite la Community Management API (gratuite sur demande).',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'post_urns' => [
                        'type'        => 'array',
                        'items'       => ['type' => 'string'],
                        'description' => 'URN des posts à détailler (urn:li:share:… ou urn:li:ugcPost:…). Facultatif.',
                    ],
                ],
            ],
        ];
        $tools[] = [
            'name'        => 'linkedin_org_follower_count',
            'description' => 'Nombre d\'abonnés de la page organisation LinkedIn configurée.',
            'inputSchema' => ['type' => 'object', 'properties' => new stdClass()],
        ];
    }

    return $tools;
}

/* ------------------------------------------------------------ Exécution */

/** Exécute un outil. Lève McpToolError pour toute erreur « métier ». */
function linkedin_call(array $config, string $name, array $args): array
{
    $settings = config_settings($config);

    return match ($name) {
        'linkedin_create_post'        => li_tool_create_post($settings, $args),
        'linkedin_delete_post'        => li_tool_delete_post($settings, $args),
        'linkedin_comment'            => li_tool_comment($settings, $args),
        'linkedin_react'              => li_tool_react($settings, $args),
        'linkedin_get_profile'        => li_tool_profile($settings),
        'linkedin_my_post_stats'      => li_tool_my_post_stats($settings, $args),
        'linkedin_org_share_stats'    => li_tool_org_share_stats($settings, $args),
        'linkedin_org_follower_count' => li_tool_org_follower_count($settings),
        default => throw new McpError(-32602, 'Unknown tool: ' . $name),
    };
}

function li_tool_create_post(array $settings, array $args): array
{
    li_require_connection($settings);

    $text = trim((string) ($args['text'] ?? ''));
    if ($text === '') {
        throw new McpToolError('Le texte du post est vide.');
    }
    $visibility = in_array($args['visibility'] ?? '', ['PUBLIC', 'CONNECTIONS'], true)
        ? $args['visibility'] : 'PUBLIC';

    // Auteur du post : le membre connecté (défaut) ou la page organisation.
    $asOrg  = ($args['author'] ?? 'member') === 'organization';
    $author = $settings['member_urn'];
    if ($asOrg) {
        $author = li_require_org($settings);
        $granted = (string) ($settings['granted_scopes'] ?? '');
        if ($granted !== '' && !str_contains($granted, 'w_organization_social')) {
            throw new McpToolError('Le token LinkedIn actuel n\'a pas le scope w_organization_social : le propriétaire doit cliquer « Reconnecter » sur la page du connecteur (mode organisation activé) pour accorder les autorisations de page. Le produit « Community Management API » doit être actif sur l\'app LinkedIn.');
        }
        $visibility = 'PUBLIC'; // un post de page est toujours public
    }

    $payload = [
        'author'       => $author,
        'commentary'   => linkedin_escape_text($text),
        'visibility'   => $visibility,
        'distribution' => [
            'feedDistribution'               => 'MAIN_FEED',
            'targetEntities'                 => [],
            'thirdPartyDistributionChannels' => [],
        ],
        'lifecycleState'             => 'PUBLISHED',
        'isReshareDisabledByAuthor'  => (bool) ($args['disable_reshare'] ?? false),
    ];

    $linkUrl = trim((string) ($args['link_url'] ?? ''));
    if ($linkUrl !== '') {
        // L'API Posts exige un titre non vide pour un article : à défaut de
        // link_title, on retombe sur le nom d'hôte du lien (LinkedIn ne fait
        // aucun scraping pour le déduire).
        $title = trim((string) ($args['link_title'] ?? ''));
        if ($title === '') {
            $title = parse_url($linkUrl, PHP_URL_HOST) ?: $linkUrl;
        }
        $article = ['source' => $linkUrl, 'title' => mb_str_limit($title, 400)];
        if (!empty($args['link_description'])) {
            $article['description'] = (string) $args['link_description'];
        }
        $payload['content'] = ['article' => $article];
    }

    [$status, $headers, $data] = li_rest($settings, 'POST', '/rest/posts', $payload);
    if ($status !== 201) {
        throw li_api_error('Publication refusée', $status, $data);
    }

    $urn = $headers['x-restli-id'] ?? $headers['x-linkedin-id'] ?? '';
    $url = $urn !== '' ? 'https://www.linkedin.com/feed/update/' . rawurlencode($urn) . '/' : '';

    return mcp_tool_result(
        'Post publié avec succès au nom de ' . ($asOrg ? "la page $author" : 'votre profil')
            . ".\nURN : $urn" . ($url !== '' ? "\nURL : $url" : ''),
        ['post_urn' => $urn, 'post_url' => $url, 'author' => $author, 'visibility' => $visibility]
    );
}

function li_tool_delete_post(array $settings, array $args): array
{
    li_require_connection($settings);
    $urn = li_arg_urn($args, 'post_urn', ['urn:li:share:', 'urn:li:ugcPost:']);

    [$status, , $data] = li_rest($settings, 'DELETE', '/rest/posts/' . rawurlencode($urn));
    if (!in_array($status, [200, 204], true)) {
        throw li_api_error('Suppression refusée', $status, $data);
    }
    return mcp_tool_result("Post supprimé : $urn", ['deleted' => true, 'post_urn' => $urn]);
}

function li_tool_comment(array $settings, array $args): array
{
    li_require_connection($settings);
    $urn  = li_arg_urn($args, 'post_urn', ['urn:li:share:', 'urn:li:ugcPost:', 'urn:li:activity:']);
    $text = trim((string) ($args['text'] ?? ''));
    if ($text === '') {
        throw new McpToolError('Le texte du commentaire est vide.');
    }

    [$status, , $data] = li_rest($settings, 'POST', '/rest/socialActions/' . rawurlencode($urn) . '/comments', [
        'actor'   => $settings['member_urn'],
        'message' => ['text' => $text],
    ]);
    if (!in_array($status, [200, 201], true)) {
        throw li_api_error('Commentaire refusé', $status, $data);
    }
    return mcp_tool_result('Commentaire publié sous ' . $urn, ['commented' => true, 'post_urn' => $urn]);
}

function li_tool_react(array $settings, array $args): array
{
    li_require_connection($settings);
    $urn      = li_arg_urn($args, 'post_urn', ['urn:li:share:', 'urn:li:ugcPost:', 'urn:li:activity:']);
    $allowed  = ['LIKE', 'PRAISE', 'APPRECIATION', 'EMPATHY', 'INTEREST', 'ENTERTAINMENT'];
    $reaction = in_array($args['reaction'] ?? '', $allowed, true) ? $args['reaction'] : 'LIKE';

    [$status, , $data] = li_rest(
        $settings,
        'POST',
        '/rest/reactions?actor=' . rawurlencode($settings['member_urn']),
        ['root' => $urn, 'reactionType' => $reaction]
    );
    if (!in_array($status, [200, 201], true)) {
        throw li_api_error('Réaction refusée', $status, $data);
    }
    return mcp_tool_result("Réaction $reaction ajoutée sur $urn", ['reacted' => true, 'reaction' => $reaction]);
}

function li_tool_profile(array $settings): array
{
    li_require_connection($settings);

    try {
        $identity = li_fetch_identity($settings, (string) $settings['access_token']);
    } catch (RuntimeException $e) {
        throw new McpToolError('Lecture du profil refusée : ' . $e->getMessage());
    }

    $lines = [
        'Profil LinkedIn connecté :',
        '- Nom : ' . ($identity['name'] !== '' ? $identity['name'] : '—'),
        '- Email : ' . ($identity['email'] ?? '—'),
        '- URN : ' . $identity['urn'],
        '- Token valable jusqu\'au : ' . ($settings['token_expires_at'] ?? '—') . ' UTC',
    ];
    return mcp_tool_result(implode("\n", $lines), $identity);
}

function li_tool_my_post_stats(array $settings, array $args): array
{
    li_require_connection($settings);
    if (linkedin_app_type($settings) !== 'community') {
        throw new McpToolError('Les statistiques de posts personnels nécessitent un connecteur de type « Community Management API » (voir Réglages sur la page du connecteur).');
    }

    $allowed = ['IMPRESSION', 'MEMBERS_REACHED', 'RESHARE', 'REACTION', 'COMMENT'];
    $metrics = array_values(array_intersect(
        array_map('strval', (array) ($args['metrics'] ?? $allowed)),
        $allowed
    )) ?: $allowed;

    // Cible : un post précis (finder « entity ») ou tous les posts du membre
    // (finder « me »). Encodage Restli 2.0 : entity=(share:urn%3Ali%3A…).
    $urn = trim((string) ($args['post_urn'] ?? ''));
    if ($urn !== '') {
        $key = str_starts_with($urn, 'urn:li:ugcPost:') ? 'ugc'
            : (str_starts_with($urn, 'urn:li:share:') ? 'share' : null);
        if ($key === null) {
            throw new McpToolError('Argument « post_urn » invalide : URN attendu commençant par urn:li:share: ou urn:li:ugcPost:.');
        }
        $target = 'q=entity&entity=(' . $key . ':' . rawurlencode($urn) . ')';
    } else {
        $target = 'q=me';
    }
    $range = li_date_range_param((string) ($args['start_date'] ?? ''), (string) ($args['end_date'] ?? ''));
    if ($range !== '') {
        $target .= '&dateRange=' . $range;
    }

    // L'endpoint n'accepte qu'une métrique par appel (queryType).
    $stats = [];
    foreach ($metrics as $metric) {
        [$status, , $data] = li_rest(
            $settings,
            'GET',
            '/rest/memberCreatorPostAnalytics?' . $target . '&queryType=' . $metric . '&aggregation=TOTAL'
        );
        if ($status !== 200) {
            throw li_api_error("Statistique $metric indisponible", $status, $data);
        }
        $count = 0;
        foreach (($data['elements'] ?? []) as $el) {
            $count += (int) ($el['count'] ?? 0);
        }
        $stats[$metric] = $count;
    }

    $labels = [
        'IMPRESSION'      => 'impressions',
        'MEMBERS_REACHED' => 'membres atteints',
        'RESHARE'         => 'repartages',
        'REACTION'        => 'réactions',
        'COMMENT'         => 'commentaires',
    ];
    $lines = [$urn !== '' ? "Statistiques du post $urn :" : 'Statistiques cumulées de vos posts :'];
    foreach ($stats as $metric => $count) {
        $lines[] = '- ' . $labels[$metric] . ' : ' . $count;
    }
    if ($range !== '') {
        $lines[] = 'Période : ' . ($args['start_date'] ?? 'début') . ' → ' . ($args['end_date'] ?? 'aujourd\'hui') . ' (fin exclue)';
    }
    return mcp_tool_result(implode("\n", $lines), ['post_urn' => $urn ?: null, 'stats' => $stats]);
}

/** Paramètre Restli dateRange=(start:(day:J,month:M,year:A),end:(…)) à partir de dates AAAA-MM-JJ. */
function li_date_range_param(string $start, string $end): string
{
    $part = function (string $date): ?string {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
            return null;
        }
        return sprintf('(day:%d,month:%d,year:%d)', (int) $m[3], (int) $m[2], (int) $m[1]);
    };
    $s = $part($start);
    $e = $part($end);
    if ($s === null && $e === null) {
        return '';
    }
    $inner = [];
    if ($s !== null) {
        $inner[] = 'start:' . $s;
    }
    if ($e !== null) {
        $inner[] = 'end:' . $e;
    }
    return '(' . implode(',', $inner) . ')';
}

function li_tool_org_share_stats(array $settings, array $args): array
{
    li_require_connection($settings);
    $orgUrn = li_require_org($settings);

    $query = 'q=organizationalEntity&organizationalEntity=' . rawurlencode($orgUrn);
    $urns  = array_values(array_filter((array) ($args['post_urns'] ?? []), 'is_string'));
    if ($urns !== []) {
        $shares   = array_filter($urns, fn ($u) => str_starts_with($u, 'urn:li:share:'));
        $ugcPosts = array_filter($urns, fn ($u) => str_starts_with($u, 'urn:li:ugcPost:'));
        if ($shares !== []) {
            $query .= '&shares=List(' . implode(',', array_map('rawurlencode', $shares)) . ')';
        }
        if ($ugcPosts !== []) {
            $query .= '&ugcPosts=List(' . implode(',', array_map('rawurlencode', $ugcPosts)) . ')';
        }
    }

    [$status, , $data] = li_rest($settings, 'GET', '/rest/organizationalEntityShareStatistics?' . $query);
    if ($status !== 200) {
        throw li_api_error('Statistiques indisponibles', $status, $data);
    }

    $elements = $data['elements'] ?? [];
    if ($elements === []) {
        return mcp_tool_result('Aucune statistique disponible pour cette organisation.', ['elements' => []]);
    }

    $lines = ['Statistiques de la page ' . $orgUrn . ' :'];
    foreach ($elements as $el) {
        $s     = $el['totalShareStatistics'] ?? [];
        $scope = $el['share'] ?? $el['ugcPost'] ?? 'ensemble des posts (cumul)';
        $lines[] = sprintf(
            "• %s\n  impressions : %s | clics : %s | réactions : %s | commentaires : %s | partages : %s | engagement : %s",
            $scope,
            $s['impressionCount'] ?? '0',
            $s['clickCount'] ?? '0',
            $s['likeCount'] ?? '0',
            $s['commentCount'] ?? '0',
            $s['shareCount'] ?? '0',
            isset($s['engagement']) ? round((float) $s['engagement'] * 100, 2) . ' %' : '—'
        );
    }
    return mcp_tool_result(implode("\n", $lines), ['elements' => $elements]);
}

function li_tool_org_follower_count(array $settings): array
{
    li_require_connection($settings);
    $orgUrn = li_require_org($settings);

    // Endpoint versionné (/rest) + enum majuscule COMPANY_FOLLOWED_BY_MEMBER :
    // l'ancien /v2/networkSizes et l'enum CamelCase sont retirés (426).
    [$status, , $data] = li_rest(
        $settings,
        'GET',
        '/rest/networkSizes/' . rawurlencode($orgUrn) . '?edgeType=COMPANY_FOLLOWED_BY_MEMBER'
    );
    if ($status !== 200) {
        throw li_api_error('Nombre d\'abonnés indisponible', $status, $data);
    }
    $count = $data['firstDegreeSize'] ?? 0;
    return mcp_tool_result("La page $orgUrn compte $count abonnés.", ['followers' => $count]);
}

/* ------------------------------------------------------------- Garde-fous */

/** Vérifie que LinkedIn est connecté et que le token n'est pas expiré. */
function li_require_connection(array $settings): void
{
    if (empty($settings['access_token']) || empty($settings['member_urn'])) {
        throw new McpToolError('LinkedIn n\'est pas connecté sur ce connecteur. Le propriétaire doit ouvrir la page du connecteur et cliquer sur « Connecter LinkedIn ».');
    }
    if (!empty($settings['token_expires_at']) && $settings['token_expires_at'] < now()) {
        throw new McpToolError('Le token LinkedIn a expiré (validité 60 jours). Le propriétaire doit se reconnecter depuis la page du connecteur.');
    }
}

/** Vérifie que le mode organisation est configuré. Retourne l'URN de l'organisation. */
function li_require_org(array $settings): string
{
    if (linkedin_app_type($settings) !== 'community') {
        throw new McpToolError('Les actions au nom d\'une page nécessitent un connecteur de type « Community Management API » : sur la page du connecteur, sélectionnez ce type (app LinkedIn dédiée), enregistrez puis reconnectez.');
    }
    $orgUrn = trim((string) ($settings['org_urn'] ?? ''));
    if ($orgUrn === '') {
        throw new McpToolError('Renseignez l\'identifiant de votre page organisation (urn:li:organization:…) dans les réglages du connecteur, puis réessayez.');
    }
    return $orgUrn;
}

/** Extrait et valide un argument URN. */
function li_arg_urn(array $args, string $key, array $prefixes): string
{
    $urn = trim((string) ($args[$key] ?? ''));
    foreach ($prefixes as $prefix) {
        if (str_starts_with($urn, $prefix)) {
            return $urn;
        }
    }
    throw new McpToolError("Argument « $key » invalide : URN attendu commençant par " . implode(' ou ', $prefixes) . '.');
}

/**
 * Échappe les caractères réservés du format « Little Text » de LinkedIn
 * (champ commentary de l'API Posts).
 */
function linkedin_escape_text(string $text): string
{
    return preg_replace('/([\\\\|{}@\[\]()<>#*~_])/', '\\\\$1', $text) ?? $text;
}

/* ------------------------------------------------------------ Client HTTP */

/**
 * Appel à l'API REST versionnée de LinkedIn (préfixe /rest).
 * Retourne [statusHttp, en-têtes (clés minuscules), corps décodé].
 */
function li_rest(array $settings, string $method, string $pathAndQuery, ?array $body = null): array
{
    return li_http($method, 'https://api.linkedin.com' . $pathAndQuery, [
        'Authorization: Bearer ' . $settings['access_token'],
        'LinkedIn-Version: ' . LINKEDIN_API_VERSION,
        'X-Restli-Protocol-Version: 2.0.0',
        'Content-Type: application/json',
    ], $body === null ? null : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

/** Requête HTTP bas niveau (cURL). Retourne [status, headers, corps décodé]. */
function li_http(string $method, string $url, array $headers = [], ?string $rawBody = null): array
{
    $respHeaders = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$respHeaders) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $respHeaders[strtolower(trim($name))] = trim($value);
            }
            return strlen($line);
        },
    ]);
    if ($rawBody !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $rawBody);
    }

    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new McpToolError('Impossible de joindre LinkedIn : ' . $err);
    }
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $decoded = json_decode((string) $raw, true);
    return [$status, $respHeaders, is_array($decoded) ? $decoded : []];
}

/** Convertit une réponse d'erreur LinkedIn en message actionnable. */
function li_api_error(string $prefix, int $status, array $data): McpToolError
{
    $detail = (string) ($data['message'] ?? $data['error_description'] ?? $data['error'] ?? '');
    $hint   = match (true) {
        $status === 401 => 'Le token LinkedIn est expiré ou révoqué : le propriétaire doit se reconnecter depuis la page du connecteur.',
        $status === 403 => 'Permission refusée par LinkedIn : vérifiez que le produit requis est activé sur votre app LinkedIn (« Share on LinkedIn » pour publier, « Community Management API » pour les statistiques — voir README).',
        $status === 422 => 'Requête refusée par LinkedIn (contenu invalide ou doublon récent).',
        $status === 429 => 'Quota d\'appels LinkedIn atteint : réessayez plus tard.',
        default         => '',
    };
    return new McpToolError(trim("$prefix (HTTP $status)." . ($detail !== '' ? " LinkedIn : $detail." : '') . ($hint !== '' ? " $hint" : '')));
}
