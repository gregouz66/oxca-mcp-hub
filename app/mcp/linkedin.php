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

/**
 * Catalogue complet des outils LinkedIn — y compris ceux que la configuration
 * actuelle n'expose pas, avec ce qu'il faut faire pour les débloquer.
 *
 * Au-delà des champs MCP (name, description, inputSchema, outputSchema),
 * chaque entrée porte :
 *   - available : l'outil est-il exposé avec les réglages actuels ;
 *   - requires  : conditions à remplir pour l'exposer (vide si aucune) ;
 *   - errors    : erreurs métier propres à cet outil.
 *
 * C'est le format que lit la page de documentation (tools.php) : tout type de
 * MCP qui expose une fonction « catalog_fn » de cette forme y est documenté
 * sans code supplémentaire.
 */
function linkedin_tool_catalog(array $settings): array
{
    $community = linkedin_app_type($settings) === 'community';
    $withOrg   = $community && trim((string) ($settings['org_urn'] ?? '')) !== '';

    $needCommunity = 'Connecteur de type « Community Management API » : à choisir dans les réglages du connecteur, avec une app LinkedIn dédiée.';
    $needOrg       = 'Page organisation renseignée dans les réglages (urn:li:organization:… — vous devez être admin de la page).';

    // Fragments communs aux deux outils de publication.
    $authorArg = ['type' => 'string', 'enum' => ['member', 'organization'], 'description' => 'Qui signe le post : member = le profil connecté (défaut) ; organization = la page entreprise du connecteur (nécessite un connecteur de type Community Management API avec la page renseignée).'];
    $visibilityArg = ['type' => 'string', 'enum' => ['PUBLIC', 'CONNECTIONS'], 'description' => 'Visibilité du post (défaut : PUBLIC ; ignoré pour une page, toujours publique).'];
    $reshareArg = ['type' => 'boolean', 'description' => 'Interdire le repartage (défaut : false).'];
    $postOutput = [
        'type'       => 'object',
        'properties' => [
            'post_urn'   => ['type' => 'string', 'description' => 'URN du post créé (urn:li:share:… ou urn:li:ugcPost:…), à réutiliser avec linkedin_delete_post, linkedin_comment ou linkedin_react.'],
            'post_url'   => ['type' => 'string', 'description' => 'URL publique du post.'],
            'author'     => ['type' => 'string', 'description' => 'URN de l\'auteur retenu (profil ou page).'],
            'visibility' => ['type' => 'string', 'enum' => ['PUBLIC', 'CONNECTIONS'], 'description' => 'Visibilité effectivement appliquée.'],
        ],
        'required' => ['post_urn', 'post_url', 'author', 'visibility'],
    ];
    $postErrors = [
        'Le texte du post est vide.',
        'Le token LinkedIn actuel n\'a pas le scope w_organization_social (author=organization sans autorisation de page).',
        'HTTP 422 — contenu refusé par LinkedIn : doublon récent ou contenu invalide.',
    ];

    $catalog = [
        [
            'name'        => 'linkedin_create_post',
            'description' => 'Publie un post LinkedIn. Par défaut au nom du profil connecté ; avec author=organization, au nom de la page entreprise configurée (connecteur de type Community Management API requis). Peut inclure un lien (article) avec titre et description. Retourne l\'URN et l\'URL publique du post.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'text' => ['type' => 'string', 'description' => 'Texte du post (max ~3000 caractères).'],
                    'author' => $authorArg,
                    'link_url' => ['type' => 'string', 'description' => 'URL à partager (facultatif).'],
                    'link_title' => ['type' => 'string', 'description' => 'Titre affiché pour le lien (facultatif).'],
                    'link_description' => ['type' => 'string', 'description' => 'Description affichée pour le lien (facultatif).'],
                    'visibility' => $visibilityArg,
                    'disable_reshare' => $reshareArg,
                ],
                'required' => ['text'],
            ],
            'outputSchema' => $postOutput,
            'available'    => true,
            'requires'     => [],
            'errors'       => $postErrors,
        ],
        [
            'name'        => 'linkedin_create_document_post',
            'description' => 'Publie un post LinkedIn avec un document en pièce jointe (PDF, PPTX ou DOCX), affiché comme un carrousel swipable dans le fil. Par défaut au nom du profil connecté ; avec author=organization, au nom de la page entreprise configurée. Le fichier est fourni soit par son URL publique (document_url), soit encodé en base64 (document_base64) : ce serveur est hébergé à distance et ne peut pas lire un chemin de votre machine.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'text' => ['type' => 'string', 'description' => 'Texte du post (max ~3000 caractères).'],
                    'document_url' => ['type' => 'string', 'description' => 'URL publique http(s) du fichier à publier (.pdf, .ppt, .pptx, .doc, .docx). Alternative à document_base64.'],
                    'document_base64' => ['type' => 'string', 'description' => 'Contenu du fichier encodé en base64 — la façon de publier un fichier local (lisez-le puis encodez-le avant l\'appel). Au-delà de quelques Mo, préférez document_url.'],
                    'filename' => ['type' => 'string', 'description' => 'Nom du fichier avec son extension (ex. presentation.pdf). Requis avec document_base64 ; déduit de l\'URL sinon.'],
                    'title' => ['type' => 'string', 'description' => 'Titre affiché sous le carrousel (défaut : le nom du fichier).'],
                    'author' => $authorArg,
                    'visibility' => $visibilityArg,
                    'disable_reshare' => $reshareArg,
                ],
                'required' => ['text'],
            ],
            'outputSchema' => [
                'type'       => 'object',
                'properties' => $postOutput['properties'] + [
                    'document_urn' => ['type' => 'string', 'description' => 'URN du document déposé (urn:li:document:…).'],
                    'document'     => ['type' => 'string', 'description' => 'Nom du fichier publié.'],
                ],
                'required' => $postOutput['required'],
            ],
            'available' => true,
            'requires'  => [],
            'errors'    => array_merge($postErrors, [
                'Indiquez le document à publier : « document_url » ou « document_base64 » (aucune source fournie).',
                'Argument « filename » requis avec « document_base64 ».',
                'Format de document non supporté par LinkedIn (hors .pdf, .ppt, .pptx, .doc, .docx).',
                'Document trop volumineux : la limite LinkedIn est de 100 Mo.',
                '« document_url » pointe vers une adresse interne : seules les URL publiques sont acceptées.',
                'LinkedIn a rejeté le document pendant son traitement (PROCESSING_FAILED).',
                'Le document est toujours en cours de traitement après 25 s — le post n\'a pas été publié.',
                'HTTP 403 DOCUMENT_FORBIDDEN — vous n\'êtes pas admin de la page visée.',
            ]),
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
            'outputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'deleted'  => ['type' => 'boolean', 'description' => 'Toujours true en cas de succès.'],
                    'post_urn' => ['type' => 'string', 'description' => 'URN du post supprimé.'],
                ],
                'required' => ['deleted', 'post_urn'],
            ],
            'available' => true,
            'requires'  => [],
            'errors'    => [
                'Argument « post_urn » invalide : URN attendu commençant par urn:li:share: ou urn:li:ugcPost:.',
                'HTTP 404 — post introuvable, déjà supprimé, ou publié par un autre compte.',
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
            'outputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'commented' => ['type' => 'boolean', 'description' => 'Toujours true en cas de succès.'],
                    'post_urn'  => ['type' => 'string', 'description' => 'URN du post commenté.'],
                ],
                'required' => ['commented', 'post_urn'],
            ],
            'available' => true,
            'requires'  => [],
            'errors'    => [
                'Le texte du commentaire est vide.',
                'Argument « post_urn » invalide : URN attendu commençant par urn:li:share:, urn:li:ugcPost: ou urn:li:activity:.',
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
            'outputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'reacted'  => ['type' => 'boolean', 'description' => 'Toujours true en cas de succès.'],
                    'reaction' => ['type' => 'string', 'enum' => ['LIKE', 'PRAISE', 'APPRECIATION', 'EMPATHY', 'INTEREST', 'ENTERTAINMENT'], 'description' => 'Réaction effectivement appliquée.'],
                ],
                'required' => ['reacted', 'reaction'],
            ],
            'available' => true,
            'requires'  => [],
            'errors'    => [
                'Argument « post_urn » invalide : URN attendu commençant par urn:li:share:, urn:li:ugcPost: ou urn:li:activity:.',
                'HTTP 422 — réaction déjà posée sur ce post.',
            ],
        ],
        [
            'name'        => 'linkedin_get_profile',
            'description' => 'Retourne le profil LinkedIn connecté (nom, email, URN) — utile pour vérifier la connexion.',
            'inputSchema' => ['type' => 'object', 'properties' => new stdClass()],
            'outputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'urn'   => ['type' => 'string', 'description' => 'URN du membre connecté (urn:li:person:…).'],
                    'name'  => ['type' => 'string', 'description' => 'Nom affiché du membre.'],
                    'email' => ['type' => ['string', 'null'], 'description' => 'Email du membre — null sur un connecteur Community Management API, qui n\'expose pas OpenID.'],
                ],
                'required' => ['urn', 'name', 'email'],
            ],
            'available' => true,
            'requires'  => [],
            'errors'    => [
                'Lecture du profil refusée : scope openid ou r_basicprofile non accordé selon le type d\'app.',
            ],
        ],
        [
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
            'outputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'post_urn' => ['type' => ['string', 'null'], 'description' => 'URN interrogé, ou null pour un cumul sur tous vos posts.'],
                    'stats'    => [
                        'type'        => 'object',
                        'description' => 'Une clé par métrique demandée, valeur entière.',
                        'properties'  => [
                            'IMPRESSION'      => ['type' => 'integer'],
                            'MEMBERS_REACHED' => ['type' => 'integer'],
                            'RESHARE'         => ['type' => 'integer'],
                            'REACTION'        => ['type' => 'integer'],
                            'COMMENT'         => ['type' => 'integer'],
                        ],
                    ],
                ],
                'required' => ['post_urn', 'stats'],
            ],
            'available' => $community,
            'requires'  => $community ? [] : [$needCommunity],
            'errors'    => [
                'Les statistiques de posts personnels nécessitent un connecteur de type « Community Management API ».',
                'Argument « post_urn » invalide : URN attendu commençant par urn:li:share: ou urn:li:ugcPost:.',
                'HTTP 426 — LINKEDIN_API_VERSION trop ancienne (cet endpoint exige une version ≥ 202506).',
            ],
        ],
        [
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
            'outputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'elements' => [
                        'type'        => 'array',
                        'description' => 'Un élément par post demandé, ou un unique élément de cumul. Vide si LinkedIn n\'a encore aucune statistique.',
                        'items'       => [
                            'type'       => 'object',
                            'properties' => [
                                'share'                => ['type' => 'string', 'description' => 'URN du post concerné (absent sur la ligne de cumul).'],
                                'ugcPost'              => ['type' => 'string', 'description' => 'URN du post concerné, variante ugcPost.'],
                                'totalShareStatistics' => [
                                    'type'       => 'object',
                                    'properties' => [
                                        'impressionCount' => ['type' => 'integer'],
                                        'clickCount'      => ['type' => 'integer'],
                                        'likeCount'       => ['type' => 'integer'],
                                        'commentCount'    => ['type' => 'integer'],
                                        'shareCount'      => ['type' => 'integer'],
                                        'engagement'      => ['type' => 'number', 'description' => 'Taux d\'engagement, en fraction (0.0342 = 3,42 %).'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'required' => ['elements'],
            ],
            'available' => $withOrg,
            'requires'  => $withOrg ? [] : array_values(array_filter([$community ? null : $needCommunity, $needOrg])),
            'errors'    => [
                'Renseignez l\'identifiant de votre page organisation dans les réglages du connecteur.',
                'HTTP 403 — vous n\'êtes pas administrateur de la page, ou le produit Community Management API n\'est pas actif.',
            ],
        ],
        [
            'name'        => 'linkedin_org_follower_count',
            'description' => 'Nombre d\'abonnés de la page organisation LinkedIn configurée.',
            'inputSchema' => ['type' => 'object', 'properties' => new stdClass()],
            'outputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'followers' => ['type' => 'integer', 'description' => 'Nombre d\'abonnés de la page.'],
                ],
                'required' => ['followers'],
            ],
            'available' => $withOrg,
            'requires'  => $withOrg ? [] : array_values(array_filter([$community ? null : $needCommunity, $needOrg])),
            'errors'    => [
                'Renseignez l\'identifiant de votre page organisation dans les réglages du connecteur.',
                'HTTP 403 — vous n\'êtes pas administrateur de la page.',
            ],
        ],
    ];

    return $catalog;
}

/**
 * Liste des outils MCP exposés par ce connecteur : le catalogue filtré sur la
 * disponibilité, réduit aux seuls champs prévus par la spécification MCP.
 */
function linkedin_tools(array $settings): array
{
    $tools = [];
    foreach (linkedin_tool_catalog($settings) as $tool) {
        if ($tool['available']) {
            $tools[] = array_intersect_key(
                $tool,
                array_flip(['name', 'description', 'inputSchema', 'outputSchema'])
            );
        }
    }
    return $tools;
}

/* ------------------------------------------------------------ Exécution */

/** Exécute un outil. Lève McpToolError pour toute erreur « métier ». */
function linkedin_call(array $config, string $name, array $args): array
{
    $settings = config_settings($config);

    return match ($name) {
        'linkedin_create_post'          => li_tool_create_post($settings, $args),
        'linkedin_create_document_post' => li_tool_create_document_post($settings, $args),
        'linkedin_delete_post'          => li_tool_delete_post($settings, $args),
        'linkedin_comment'              => li_tool_comment($settings, $args),
        'linkedin_react'                => li_tool_react($settings, $args),
        'linkedin_get_profile'          => li_tool_profile($settings),
        'linkedin_my_post_stats'        => li_tool_my_post_stats($settings, $args),
        'linkedin_org_share_stats'      => li_tool_org_share_stats($settings, $args),
        'linkedin_org_follower_count'   => li_tool_org_follower_count($settings),
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
    [$author, $asOrg, $visibility] = li_post_author($settings, $args);

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

/**
 * Post « document » (carrousel LinkedIn) : le fichier est d'abord déposé via
 * l'API Documents, puis référencé dans le post. Trois appels enchaînés —
 * initializeUpload, envoi du binaire, publication — plus une attente de
 * traitement, l'API Documents n'ayant pas d'upload synchrone.
 */
function li_tool_create_document_post(array $settings, array $args): array
{
    li_require_connection($settings);

    $text = trim((string) ($args['text'] ?? ''));
    if ($text === '') {
        throw new McpToolError('Le texte du post est vide.');
    }
    [$author, $asOrg, $visibility] = li_post_author($settings, $args);

    $document = li_document_bytes($args);
    $title    = mb_str_limit(trim((string) ($args['title'] ?? '')) ?: $document['filename'], 400);

    // Le document est déposé au nom de l'auteur du post : LinkedIn refuse
    // (DOCUMENT_FORBIDDEN) un document dont le propriétaire diffère de l'auteur.
    [$uploadUrl, $documentUrn] = li_document_initialize_upload($settings, $author);
    li_document_upload($settings, $uploadUrl, $document['bytes']);
    li_document_await($settings, $documentUrn);

    [$status, $headers, $data] = li_rest($settings, 'POST', '/rest/posts', [
        'author'       => $author,
        'commentary'   => linkedin_escape_text($text),
        'visibility'   => $visibility,
        'distribution' => [
            'feedDistribution'               => 'MAIN_FEED',
            'targetEntities'                 => [],
            'thirdPartyDistributionChannels' => [],
        ],
        'content'                   => ['media' => ['title' => $title, 'id' => $documentUrn]],
        'lifecycleState'            => 'PUBLISHED',
        'isReshareDisabledByAuthor' => (bool) ($args['disable_reshare'] ?? false),
    ]);
    if ($status !== 201) {
        throw li_api_error('Publication du document refusée', $status, $data);
    }

    $urn = $headers['x-restli-id'] ?? $headers['x-linkedin-id'] ?? '';
    $url = $urn !== '' ? 'https://www.linkedin.com/feed/update/' . rawurlencode($urn) . '/' : '';

    return mcp_tool_result(
        'Post avec document publié au nom de ' . ($asOrg ? "la page $author" : 'votre profil')
            . ' — « ' . $document['filename'] . ' » s\'affiche en carrousel dans le fil.'
            . "\nURN : $urn" . ($url !== '' ? "\nURL : $url" : ''),
        [
            'post_urn'     => $urn,
            'post_url'     => $url,
            'author'       => $author,
            'visibility'   => $visibility,
            'document_urn' => $documentUrn,
            'document'     => $document['filename'],
        ]
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
    $count = (int) ($data['firstDegreeSize'] ?? 0);
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

/**
 * Résout l'auteur d'un post depuis l'argument « author » : le membre connecté
 * (défaut) ou la page organisation du connecteur.
 * Retourne [URN de l'auteur, est-ce une page, visibilité effective].
 */
function li_post_author(array $settings, array $args): array
{
    $visibility = in_array($args['visibility'] ?? '', ['PUBLIC', 'CONNECTIONS'], true)
        ? (string) $args['visibility'] : 'PUBLIC';

    if (($args['author'] ?? 'member') !== 'organization') {
        return [(string) $settings['member_urn'], false, $visibility];
    }

    $orgUrn  = li_require_org($settings);
    $granted = (string) ($settings['granted_scopes'] ?? '');
    if ($granted !== '' && !str_contains($granted, 'w_organization_social')) {
        throw new McpToolError('Le token LinkedIn actuel n\'a pas le scope w_organization_social : le propriétaire doit cliquer « Reconnecter » sur la page du connecteur (mode organisation activé) pour accorder les autorisations de page. Le produit « Community Management API » doit être actif sur l\'app LinkedIn.');
    }
    return [$orgUrn, true, 'PUBLIC']; // un post de page est toujours public
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

/* ------------------------------------------------- API Documents LinkedIn */

/** Taille maximale d'un document acceptée par LinkedIn (100 Mo, 300 pages). */
const LINKEDIN_DOC_MAX_BYTES = 104857600;

/** Extensions acceptées par l'API Documents (le PDF est le format conseillé). */
const LINKEDIN_DOC_EXTENSIONS = ['pdf', 'ppt', 'pptx', 'doc', 'docx'];

/** Délai maximal d'un transfert de document (téléchargement ou envoi), en secondes. */
const LINKEDIN_DOC_TRANSFER_TIMEOUT = 120;

/** Budget d'attente du traitement du document chez LinkedIn, en secondes. */
const LINKEDIN_DOC_POLL_TIMEOUT = 25;

/**
 * Récupère le document à publier depuis les arguments de l'outil : URL
 * publique (document_url) ou contenu encodé (document_base64).
 * Retourne ['bytes' => octets bruts, 'filename' => nom avec extension].
 */
function li_document_bytes(array $args): array
{
    $url    = trim((string) ($args['document_url'] ?? ''));
    $base64 = trim((string) ($args['document_base64'] ?? ''));
    $name   = trim((string) ($args['filename'] ?? ''));

    if ($url !== '' && $base64 !== '') {
        throw new McpToolError('Fournissez « document_url » OU « document_base64 », pas les deux.');
    }

    if ($base64 !== '') {
        if ($name === '') {
            throw new McpToolError('Argument « filename » requis avec « document_base64 » : LinkedIn a besoin de l\'extension du fichier (.pdf, .pptx, .docx…).');
        }
        // strict = true : un contenu tronqué ou mal encodé doit échouer ici
        // plutôt que de produire un fichier corrompu envoyé à LinkedIn.
        $bytes = base64_decode(preg_replace('/\s+/', '', $base64) ?? $base64, true);
        if ($bytes === false || $bytes === '') {
            throw new McpToolError('« document_base64 » n\'est pas un contenu base64 valide.');
        }
        li_document_check_size(strlen($bytes));
        return ['bytes' => $bytes, 'filename' => li_document_filename($name)];
    }

    if ($url === '') {
        throw new McpToolError('Indiquez le document à publier : « document_url » (URL publique http(s)) ou « document_base64 » (contenu encodé en base64). Ce connecteur est hébergé à distance : il ne peut pas ouvrir un chemin de fichier de votre machine.');
    }

    $filename = li_document_filename($name !== '' ? $name : basename((string) parse_url($url, PHP_URL_PATH)));
    return ['bytes' => li_document_download($url), 'filename' => $filename];
}

/** Valide le nom et l'extension d'un document. Retourne un nom de fichier propre. */
function li_document_filename(string $name): string
{
    $name = trim(str_replace(["\r", "\n"], '', basename($name)));
    $ext  = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, LINKEDIN_DOC_EXTENSIONS, true)) {
        throw new McpToolError('Format de document non supporté par LinkedIn'
            . ($ext !== '' ? " (.$ext)" : '') . '. Formats acceptés : '
            . implode(', ', array_map(fn ($e) => '.' . $e, LINKEDIN_DOC_EXTENSIONS))
            . ' — précisez le nom du fichier avec son extension dans « filename ».');
    }
    return mb_str_limit($name, 200);
}

/** Refuse un document dépassant la limite LinkedIn. */
function li_document_check_size(int $bytes): void
{
    if ($bytes > LINKEDIN_DOC_MAX_BYTES) {
        throw new McpToolError('Document trop volumineux : ' . round($bytes / 1048576, 1)
            . ' Mo pour un maximum de 100 Mo côté LinkedIn.');
    }
}

/**
 * Télécharge un document depuis une URL publique, via le socle HTTP partagé
 * (garde-fous anti-SSRF et coupure au dépassement de taille inclus).
 */
function li_document_download(string $url): string
{
    return http_download_limited(
        $url,
        LINKEDIN_DOC_MAX_BYTES,
        'document_url',
        LINKEDIN_DOC_TRANSFER_TIMEOUT,
        'Document trop volumineux : la limite LinkedIn est de 100 Mo.'
    );
}

/**
 * Étape 1 — réserve un emplacement d'upload auprès de LinkedIn.
 * Retourne [URL d'upload à usage unique, URN du document].
 */
function li_document_initialize_upload(array $settings, string $owner): array
{
    [$status, , $data] = li_rest($settings, 'POST', '/rest/documents?action=initializeUpload', [
        'initializeUploadRequest' => ['owner' => $owner],
    ]);
    if ($status !== 200) {
        throw li_api_error('Préparation de l\'envoi du document refusée', $status, $data);
    }
    $uploadUrl = (string) ($data['value']['uploadUrl'] ?? '');
    $documentUrn = (string) ($data['value']['document'] ?? '');
    if ($uploadUrl === '' || $documentUrn === '') {
        throw new McpToolError('Réponse inattendue de LinkedIn à l\'initialisation de l\'envoi : ni URL d\'upload ni URN de document.');
    }
    return [$uploadUrl, $documentUrn];
}

/** Étape 2 — envoie les octets du document sur l'URL d'upload (PUT brut). */
function li_document_upload(array $settings, string $uploadUrl, string $bytes): void
{
    // Corps = octets bruts, comme `curl --upload-file` : ni JSON ni multipart,
    // et pas d'en-tête de version ici (le service d'upload n'est pas /rest).
    // « Expect: » neutralise le 100-continue ajouté par cURL au-delà de 1 Ko,
    // que ce service n'honore pas toujours.
    [$status, , $data] = li_http('PUT', $uploadUrl, [
        'Authorization: Bearer ' . $settings['access_token'],
        'Content-Type: application/octet-stream',
        'Expect:',
    ], $bytes, LINKEDIN_DOC_TRANSFER_TIMEOUT);

    if (!in_array($status, [200, 201], true)) {
        throw li_api_error('Envoi du document refusé', $status, $data);
    }
}

/**
 * Étape 3 — attend que LinkedIn ait fini de traiter le document.
 * L'API Documents ne propose pas d'upload synchrone : publier avant la fin du
 * traitement échoue. La lecture du statut demande r_member_social /
 * r_organization_social, absents de certaines apps : quand elle est refusée,
 * on laisse un court délai et on poursuit — c'est alors la publication qui
 * remontera l'erreur réelle, plutôt que de bloquer sur un contrôle facultatif.
 */
function li_document_await(array $settings, string $documentUrn): void
{
    $deadline = time() + LINKEDIN_DOC_POLL_TIMEOUT;
    $delay    = 1;

    while (true) {
        [$status, , $data] = li_rest($settings, 'GET', '/rest/documents/' . rawurlencode($documentUrn));
        if ($status !== 200) {
            sleep(3);
            return;
        }
        $state = (string) ($data['status'] ?? '');
        if ($state === 'AVAILABLE') {
            return;
        }
        if ($state === 'PROCESSING_FAILED') {
            throw new McpToolError('LinkedIn a rejeté le document pendant son traitement (PROCESSING_FAILED) : vérifiez qu\'il ne dépasse pas 100 Mo et 300 pages, et qu\'il n\'est ni protégé par mot de passe ni corrompu.');
        }
        if (time() + $delay >= $deadline) {
            throw new McpToolError('Le document est toujours en cours de traitement chez LinkedIn après '
                . LINKEDIN_DOC_POLL_TIMEOUT . ' s (statut : ' . ($state !== '' ? $state : 'inconnu')
                . '). Le post n\'a pas été publié : réessayez dans quelques instants.');
        }
        sleep($delay);
        $delay = min($delay * 2, 5);
    }
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

/**
 * Requête HTTP bas niveau, déléguée au socle partagé.
 * Retourne [status, en-têtes (clés minuscules), corps décodé].
 */
function li_http(string $method, string $url, array $headers = [], ?string $rawBody = null, int $timeout = 30): array
{
    try {
        return http_request($method, $url, $headers, $rawBody, $timeout);
    } catch (McpToolError $e) {
        // Message historique du connecteur, plus parlant que le générique.
        throw new McpToolError(str_replace('Service injoignable', 'Impossible de joindre LinkedIn', $e->getMessage()));
    }
}

/** Convertit une réponse d'erreur LinkedIn en message actionnable. */
function li_api_error(string $prefix, int $status, array $data): McpToolError
{
    $detail = (string) ($data['message'] ?? $data['error_description'] ?? $data['error'] ?? '');
    $hint   = match (true) {
        $status === 401 => 'Le token LinkedIn est expiré ou révoqué : le propriétaire doit se reconnecter depuis la page du connecteur.',
        $status === 403 && stripos($detail, 'document') !== false => 'Permission refusée sur le document : pour publier au nom d\'une page, vous devez en être administrateur (ou « DSC poster ») et le produit « Community Management API » doit être actif sur l\'app LinkedIn.',
        $status === 403 => 'Permission refusée par LinkedIn : vérifiez que le produit requis est activé sur votre app LinkedIn (« Share on LinkedIn » pour publier, « Community Management API » pour les statistiques — voir README).',
        $status === 422 => 'Requête refusée par LinkedIn (contenu invalide ou doublon récent).',
        $status === 429 => 'Quota d\'appels LinkedIn atteint : réessayez plus tard.',
        default         => '',
    };
    return new McpToolError(trim("$prefix (HTTP $status)." . ($detail !== '' ? " LinkedIn : $detail." : '') . ($hint !== '' ? " $hint" : '')));
}

/* --------------------------------------------- Interface (page connecteur) */

/**
 * Champs de réglages propres à LinkedIn, insérés dans le formulaire commun
 * de la page connecteur (entre le nom du connecteur et le bouton Enregistrer).
 */
function linkedin_settings_form(array $config, array $settings): void
{
    $hasInstanceApp = LINKEDIN_DEFAULT_CLIENT_ID !== '' && LINKEDIN_DEFAULT_CLIENT_SECRET !== '';
    $appType        = linkedin_app_type($settings);

    echo '<div class="field"><span class="field-label">Type d\'app LinkedIn</span>';
    ui_radio('app_type', 'signin', 'Profil — « Sign In with LinkedIn » + « Share on LinkedIn »', $appType === 'signin',
        'Les deux produits s\'ajoutent instantanément sur votre app. Publication au nom de votre profil uniquement ; pas de statistiques.');
    ui_radio('app_type', 'community', 'Community Management API — app LinkedIn dédiée', $appType === 'community',
        'Publication au nom du profil <strong>et</strong> d\'une page entreprise, statistiques de vos posts personnels et de la page. Règle LinkedIn : ce produit doit être <strong>le seul</strong> de l\'app — créez une app séparée pour lui (accès gratuit, sur demande). Après un changement de type : Enregistrer, saisir les identifiants de la nouvelle app, puis Connecter.');
    echo '</div>';

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

    if ($appType === 'community') {
        ui_field([
            'label' => 'Page organisation (facultatif)', 'name' => 'org_urn',
            'value' => $settings['org_urn'] ?? '',
            'placeholder' => 'urn:li:organization:12345678 ou simplement 12345678',
            'hint' => 'Nécessaire uniquement pour publier en tant que page et consulter ses statistiques — vous devez être <strong>admin</strong> de la page. L\'identifiant apparaît dans l\'URL d\'admin : linkedin.com/company/<strong>12345678</strong>/admin.',
        ]);
    }
}

/**
 * Enregistre les réglages LinkedIn soumis. Retourne un complément de message
 * flash (chaîne vide s'il n'y a rien à signaler).
 */
function linkedin_settings_save(array $config, array $post): string
{
    $oldSettings = config_settings($config);
    $appType     = in_array($post['app_type'] ?? '', ['signin', 'community'], true)
        ? $post['app_type'] : linkedin_app_type($oldSettings);

    $patch = [
        'client_id' => trim((string) ($post['client_id'] ?? '')),
        'app_type'  => $appType,
        'org_mode'  => null, // réglage remplacé par app_type
    ];
    $secret = trim((string) ($post['client_secret'] ?? ''));
    if ($secret !== '') { // vide = conserver l'existant
        $patch['client_secret'] = $secret;
    }
    // Le champ n'est affiché que pour le type community : ne pas effacer une
    // valeur existante quand il est absent du POST.
    if (array_key_exists('org_urn', $post)) {
        $orgUrn = trim((string) $post['org_urn']);
        if ($orgUrn !== '' && ctype_digit($orgUrn)) {
            $orgUrn = 'urn:li:organization:' . $orgUrn;
        }
        $patch['org_urn'] = $orgUrn;
    }
    // Changer de type d'app implique une autre app LinkedIn : le token en place
    // ne vaut plus rien, on déconnecte proprement.
    $typeChanged = $appType !== linkedin_app_type($oldSettings) && !empty($oldSettings['access_token']);
    if ($typeChanged) {
        $patch += ['access_token' => null, 'token_expires_at' => null,
            'member_urn' => null, 'member_name' => null, 'granted_scopes' => null];
    }
    config_update_settings($config, $patch);

    return $typeChanged
        ? 'Le type d\'app a changé : renseignez les identifiants de la nouvelle app puis cliquez « Connecter LinkedIn ».'
        : '';
}

/** Efface la connexion LinkedIn. Retourne le message flash de confirmation. */
function linkedin_disconnect(array $config): string
{
    config_update_settings($config, [
        'access_token' => null, 'token_expires_at' => null,
        'member_urn' => null, 'member_name' => null, 'granted_scopes' => null,
    ]);
    return 'LinkedIn déconnecté de ce connecteur.';
}

/**
 * Vérifie en un appel que la connexion LinkedIn est opérationnelle (endpoint
 * d'identité adapté au type d'app : userinfo ou /v2/me).
 * Retourne le message de succès ; lève RuntimeException avec le message d'échec.
 */
function linkedin_test(array $settings): string
{
    if (empty($settings['access_token'])) {
        throw new RuntimeException('LinkedIn n\'est pas connecté sur ce connecteur.');
    }
    try {
        $identity = li_fetch_identity($settings, (string) $settings['access_token']);
    } catch (RuntimeException $e) {
        throw new RuntimeException($e->getMessage()
            . ' Reconnectez LinkedIn ; si l\'erreur persiste, vérifiez les produits activés sur votre app.');
    }
    return 'Connexion opérationnelle — LinkedIn répond : '
        . ($identity['name'] !== '' ? $identity['name'] : '?')
        . ($identity['email'] !== null ? ' <' . $identity['email'] . '>' : '')
        . ' (' . $identity['urn'] . ').';
}

/** Carte « Connexion LinkedIn » de la page connecteur (propriétaire). */
function linkedin_connect_card(array $config, array $settings, array $summary): void
{
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
