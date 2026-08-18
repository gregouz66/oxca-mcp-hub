<?php
/**
 * MCP Instagram : publication d'images et de carrousels via l'API Instagram
 * « Content Publishing ».
 *
 * Chemin d'authentification retenu : « Instagram API with Instagram Login »
 * (Business Login for Instagram), qui ne réclame **aucune page Facebook**.
 *
 * Deux points structurent tout le connecteur :
 *
 *  1. Seuls les comptes **professionnels** (Entreprise ou Créateur) peuvent
 *     publier. Un compte personnel n'a aucune API de publication — la seule
 *     qui les touchait, Basic Display, a été arrêtée le 4 décembre 2024 et
 *     était en lecture seule.
 *
 *  2. L'API ne reçoit pas les octets d'une image : elle **télécharge une URL
 *     publique**. Les images fournies en base64 sont donc déposées par
 *     app/media.php et servies par media.php le temps de la publication.
 *     C'est l'inverse du connecteur LinkedIn, qui pousse ses octets.
 */

/* ------------------------------------------------------------ Constantes */

// Une instance installée avant l'arrivée de ce connecteur a un config.php qui
// ignore tout d'Instagram : sans ces valeurs de repli, la seule ouverture de la
// page d'un connecteur serait une erreur fatale. Elles n'écrasent jamais une
// valeur déjà définie dans config.php.
defined('INSTAGRAM_API_VERSION')        || define('INSTAGRAM_API_VERSION', 'v26.0');
defined('INSTAGRAM_DEFAULT_APP_ID')     || define('INSTAGRAM_DEFAULT_APP_ID', '');
defined('INSTAGRAM_DEFAULT_APP_SECRET') || define('INSTAGRAM_DEFAULT_APP_SECRET', '');

/** Hôte de l'API pour le chemin « Instagram Login ». */
const IG_API_HOST = 'https://graph.instagram.com';

/** Hôte d'échange du code d'autorisation. */
const IG_OAUTH_HOST = 'https://api.instagram.com';

/** Chemin de redirection OAuth, déclaré à l'identique côté Meta. */
const IG_OAUTH_REDIRECT = '/oauth-instagram.php';

/** Scopes nécessaires pour lire l'identité et publier. */
const IG_SCOPES = ['instagram_business_basic', 'instagram_business_content_publish'];

/** Budget d'attente du traitement d'un conteneur (s) — un mutualisé coupe vite. */
const IG_POLL_BUDGET = 25;

/** Bornes d'un carrousel. */
const IG_CAROUSEL_MIN = 2;
const IG_CAROUSEL_MAX = 10;

/** Limites de contenu imposées par Instagram. */
const IG_CAPTION_MAX_CHARS  = 2200;
const IG_CAPTION_MAX_TAGS   = 30;
const IG_CAPTION_MAX_MENTIONS = 20;
const IG_ALT_TEXT_MAX       = 1000;
const IG_COLLABORATORS_MAX  = 3;

/**
 * Plafonds du contenu base64 accepté, avant tout décodage.
 *
 * Ce ne sont pas les limites d'Instagram (une image source peut légitimement
 * peser plus que les 8 Mo finaux, on la réduit) mais celles de l'hébergement :
 * décoder puis décompresser une image en mémoire coûte plusieurs fois sa
 * taille, et un mutualisé plafonne souvent à 128 Mo. Dépasser sans le dire
 * produirait une erreur fatale de PHP — donc une réponse MCP tronquée,
 * illisible pour le client. Mieux vaut refuser tôt et expliquer.
 */
const IG_BASE64_MAX_CHARS       = 24000000;  // ~18 Mo par image
const IG_BASE64_MAX_TOTAL_CHARS = 43000000;  // ~32 Mo pour un carrousel entier

/** Quota de publication retenu par défaut, à défaut de lecture au runtime. */
const IG_QUOTA_FALLBACK = 50;

/**
 * Spécifications d'une image de fil Instagram.
 * Le poids est contrôlé à 8 000 000 octets : la documentation annonce « 8 MB »
 * mais le message d'erreur parle de 8 MiB — on retient la borne prudente.
 */
function ig_image_spec(): array
{
    return [
        'platform'   => 'Instagram',
        'mime'       => 'image/jpeg',
        'max_bytes'  => 8000000,
        'min_width'  => 320,
        'max_width'  => 1440,
        'min_ratio'  => 0.8,   // 4:5
        'max_ratio'  => 1.91,  // 1.91:1
    ];
}

/* --------------------------------------------------------- Résumé (UI) */

/** État de la connexion Instagram d'une config, pour l'interface. */
function instagram_summary(array $settings): array
{
    $expiresAt = $settings['token_expires_at'] ?? null;
    $connected = !empty($settings['access_token']) && !empty($settings['ig_user_id']);
    return [
        'connected'    => $connected,
        'expired'      => $connected && $expiresAt !== null && $expiresAt < now(),
        'member_name'  => $settings['username'] ?? null,
        'expires_at'   => $expiresAt,
        'account_type' => $settings['account_type'] ?? null,
    ];
}

/** Identifiant d'app Instagram (≠ App ID Facebook, piège d'intégration n°1). */
function instagram_app_id(array $settings): string
{
    return trim((string) ($settings['app_id'] ?? '')) ?: (string) INSTAGRAM_DEFAULT_APP_ID;
}

function instagram_app_secret(array $settings): string
{
    return trim((string) ($settings['app_secret'] ?? '')) ?: (string) INSTAGRAM_DEFAULT_APP_SECRET;
}

/* ------------------------------------------------------------ OAuth 2 */

/** URL d'autorisation Instagram (démarrage du flux). */
function instagram_oauth_url(array $settings, string $state): string
{
    return 'https://www.instagram.com/oauth/authorize?' . http_build_query([
        'client_id'     => instagram_app_id($settings),
        'redirect_uri'  => base_url(IG_OAUTH_REDIRECT),
        'response_type' => 'code',
        'scope'         => implode(',', IG_SCOPES),
        'state'         => $state,
    ], '', '&', PHP_QUERY_RFC3986);
}

/**
 * Échange le code d'autorisation contre un token longue durée, puis lit
 * l'identité du compte. Retourne le patch de réglages à enregistrer.
 * Lève RuntimeException avec un message actionnable.
 */
function instagram_oauth_exchange(array $settings, string $code): array
{
    // Meta ajoute « #_ » à la fin du code sur la redirection : ce fragment ne
    // fait pas partie du code lui-même.
    $code = preg_replace('/#_$/', '', $code) ?? $code;

    [$status, , $data] = http_request('POST', IG_OAUTH_HOST . '/oauth/access_token', [
        'Content-Type: application/x-www-form-urlencoded',
    ], http_build_query([
        'client_id'     => instagram_app_id($settings),
        'client_secret' => instagram_app_secret($settings),
        'grant_type'    => 'authorization_code',
        'redirect_uri'  => base_url(IG_OAUTH_REDIRECT),
        'code'          => $code,
    ]));

    // La réponse de cet endpoint est enveloppée dans un tableau « data ».
    // D'autres endpoints de la plateforme répondent à plat : on accepte les
    // deux formes partout plutôt que de dépendre de l'une.
    $payload = ig_unwrap($data);
    if ($status !== 200 || empty($payload['access_token'])) {
        throw new RuntimeException('Échange du code OAuth refusé par Instagram (HTTP ' . $status . ') : '
            . ig_error_text($data)
            . ' — vérifiez que l\'Instagram App ID / App Secret sont bien ceux de la section « API setup with Instagram business login » (et non ceux de l\'app Facebook), et que l\'URL de redirection déclarée est exactement celle affichée sur cette page.');
    }

    $shortToken = (string) $payload['access_token'];

    // Le token issu du flux OAuth ne vaut qu'une heure : on l'échange
    // immédiatement contre un token de 60 jours (appel serveur, il porte
    // l'app secret).
    [$status, , $long] = http_request('GET', IG_API_HOST . '/access_token?' . http_build_query([
        'grant_type'    => 'ig_exchange_token',
        'client_secret' => instagram_app_secret($settings),
        'access_token'  => $shortToken,
    ]));
    $long = ig_unwrap($long);
    if ($status !== 200 || empty($long['access_token'])) {
        throw new RuntimeException('Instagram a refusé de convertir le token en token longue durée (HTTP ' . $status . ') : '
            . ig_error_text($long) . ' — l\'App Secret est-il correct ?');
    }

    $token     = (string) $long['access_token'];
    $expiresIn = (int) ($long['expires_in'] ?? 5183944);
    $identity  = ig_fetch_identity($token);

    return [
        'access_token'      => $token,
        'token_expires_at'  => gmdate('Y-m-d H:i:s', time() + $expiresIn),
        'token_obtained_at' => now(),
        'granted_scopes'    => (string) ($payload['permissions'] ?? implode(',', IG_SCOPES)),
        'ig_user_id'        => $identity['ig_user_id'],
        'username'          => $identity['username'],
        'account_type'      => $identity['account_type'],
    ];
}

/**
 * Identité du compte pour un token donné.
 *
 * Deux identifiants coexistent et les confondre casse la publication :
 * « id » est propre à l'app (app-scoped), « user_id » est l'identifiant du
 * compte professionnel — c'est lui qu'attendent /media et /media_publish.
 */
function ig_fetch_identity(string $token): array
{
    [$status, , $data] = http_request('GET', IG_API_HOST . '/' . INSTAGRAM_API_VERSION . '/me?' . http_build_query([
        'fields'       => 'user_id,username,account_type,followers_count,media_count',
        'access_token' => $token,
    ]));
    $me = ig_unwrap($data);
    if ($status !== 200) {
        throw new RuntimeException('Lecture du profil Instagram refusée (HTTP ' . $status . ') : ' . ig_error_text($data)
            . ' — le compte est-il bien un compte professionnel (Entreprise ou Créateur) et le scope instagram_business_basic a-t-il été accordé ?');
    }
    // « id » est propre à l'application, « user_id » identifie le compte
    // professionnel : se rabattre sur le premier enregistrerait un identifiant
    // que /media et /media_publish refusent, avec une erreur incompréhensible
    // au moment de publier.
    if (empty($me['user_id'])) {
        throw new RuntimeException('Instagram n\'a pas renvoyé l\'identifiant de compte professionnel (« user_id »)'
            . ($me === [] ? '' : ' — champs reçus : ' . implode(', ', array_keys($me)))
            . '. C\'est le symptôme d\'un compte qui n\'est pas (ou plus) un compte professionnel : basculez-le en compte Entreprise ou Créateur dans l\'application Instagram, puis reconnectez-le.');
    }
    return [
        'ig_user_id'      => (string) $me['user_id'],
        'username'        => (string) ($me['username'] ?? ''),
        'account_type'    => (string) ($me['account_type'] ?? ''),
        'followers_count' => isset($me['followers_count']) ? (int) $me['followers_count'] : null,
        'media_count'     => isset($me['media_count']) ? (int) $me['media_count'] : null,
    ];
}

/**
 * Rafraîchit le token quand c'est utile et possible, puis retourne les
 * réglages à jour.
 *
 * Un mutualisé ne garantit pas de tâche planifiée : le rafraîchissement est
 * donc opportuniste, déclenché par l'usage. Trois conditions cumulatives côté
 * Meta : le token doit avoir plus de 24 h, être encore valide, et le scope
 * instagram_business_basic doit toujours être accordé. Un token non rafraîchi
 * pendant 60 jours meurt définitivement.
 */
function ig_refresh_if_due(array $config, array $settings): array
{
    $token = (string) ($settings['access_token'] ?? '');
    if ($token === '' || empty($settings['token_expires_at'])) {
        return $settings;
    }
    $expiresAt = strtotime($settings['token_expires_at'] . ' UTC');
    if ($expiresAt === false || $expiresAt < time()) {
        return $settings; // expiré : seule une reconnexion peut aider
    }
    // Meta refuse de rafraîchir un token de moins de 24 h. Une date d'obtention
    // absente ne doit pas bloquer le renouvellement à jamais : on tente, et un
    // refus est sans conséquence (le token en place est conservé).
    $obtained = isset($settings['token_obtained_at'])
        ? strtotime($settings['token_obtained_at'] . ' UTC') : false;
    if ($obtained !== false && $obtained > time() - 86400) {
        return $settings;
    }
    if ($expiresAt > time() + 10 * 86400) {
        return $settings;
    }

    try {
        [$status, , $data] = http_request('GET', IG_API_HOST . '/refresh_access_token?' . http_build_query([
            'grant_type'   => 'ig_refresh_token',
            'access_token' => $token,
        ]));
    } catch (McpToolError) {
        return $settings; // réseau indisponible : on réessaiera au prochain appel
    }
    $fresh = ig_unwrap($data);
    if ($status !== 200 || empty($fresh['access_token'])) {
        return $settings;
    }

    $patch = [
        'access_token'      => (string) $fresh['access_token'],
        'token_expires_at'  => gmdate('Y-m-d H:i:s', time() + (int) ($fresh['expires_in'] ?? 5183944)),
        'token_obtained_at' => now(),
    ];
    config_update_settings($config, $patch);
    return array_merge($settings, $patch);
}

/* ---------------------------------------------------- Définition outils */

/**
 * Catalogue complet des outils Instagram, y compris ceux que la configuration
 * courante n'expose pas — avec ce qu'il faut faire pour les débloquer.
 * Format lu tel quel par la page de documentation (tools.php).
 */
function instagram_tool_catalog(array $settings): array
{
    $connected = !empty($settings['access_token']) && !empty($settings['ig_user_id']);
    $needConnection = 'Compte Instagram professionnel connecté depuis la page du connecteur.';

    $imageSource = [
        'image_url'    => ['type' => 'string', 'description' => 'URL publique et directe de l\'image (https, sans redirection). Instagram télécharge l\'image depuis ses serveurs : un lien de partage Google Drive ou Dropbox, qui renvoie une page HTML, ne fonctionne pas.'],
        'image_base64' => ['type' => 'string', 'description' => 'Contenu de l\'image encodé en base64. Le connecteur la convertit au format attendu par Instagram puis l\'héberge publiquement le temps de la publication.'],
    ];
    $fitArg = ['type' => 'string', 'enum' => ['reject', 'pad'], 'description' => 'Que faire d\'une image dont le rapport sort des bornes 4:5–1.91:1 : reject = refuser en expliquant (défaut) ; pad = ajouter des marges blanches pour la ramener dans les bornes.'];
    $stageArg = ['type' => 'string', 'enum' => ['auto', 'never', 'always'], 'description' => 'Ré-héberger l\'image fournie via image_url : auto = seulement si elle n\'est pas conforme (défaut) ; never = transmettre l\'URL telle quelle ; always = toujours re-héberger.'];

    $mediaErrors = [
        'L\'image n\'est pas conforme (format, poids, rapport d\'aspect) et n\'a pas pu être convertie.',
        'Instagram n\'a pas réussi à télécharger l\'image depuis l\'URL fournie (redirection, authentification, protection anti-robot).',
        'Le compte Instagram est restreint ou inactif : connectez-vous à l\'application Instagram pour lever la restriction.',
        'Quota de publication des dernières 24 heures atteint.',
    ];
    $publishOutput = [
        'type'       => 'object',
        'properties' => [
            'media_id'  => ['type' => 'string', 'description' => 'Identifiant de la publication créée.'],
            'permalink' => ['type' => 'string', 'description' => 'URL publique de la publication (vide si Instagram ne l\'a pas renvoyée).'],
            'username'  => ['type' => 'string', 'description' => 'Compte sur lequel la publication a été faite.'],
            'notes'     => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Transformations appliquées aux images avant envoi.'],
        ],
        'required' => ['media_id', 'username'],
    ];

    $catalog = [
        [
            'name'        => 'instagram_publish_image',
            'description' => 'Publie une image seule dans le fil Instagram du compte connecté. L\'image est fournie soit par une URL publique (image_url), soit encodée en base64 (image_base64) ; elle est automatiquement convertie en JPEG, ramenée entre 320 et 1440 px de large et compressée sous 8 Mo. En revanche une image dont le rapport sort des bornes 4:5–1.91:1 est refusée, car Instagram la rejetterait : utilisez fit="pad" pour la compléter par des marges. Retourne l\'identifiant et l\'URL de la publication.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => $imageSource + [
                    'caption'         => ['type' => 'string', 'description' => 'Légende du post (2200 caractères, 30 hashtags et 20 mentions @ maximum).'],
                    'alt_text'        => ['type' => 'string', 'description' => 'Texte alternatif d\'accessibilité (1000 caractères max).'],
                    'user_tags'       => ['type' => 'array', 'description' => 'Comptes identifiés sur l\'image. Chaque entrée exige une position : x et y entre 0.0 et 1.0.', 'items' => [
                        'type' => 'object',
                        'properties' => [
                            'username' => ['type' => 'string', 'description' => 'Nom d\'utilisateur, sans @.'],
                            'x'        => ['type' => 'number', 'description' => 'Position horizontale, 0.0 (gauche) à 1.0 (droite).'],
                            'y'        => ['type' => 'number', 'description' => 'Position verticale, 0.0 (haut) à 1.0 (bas).'],
                        ],
                        'required' => ['username', 'x', 'y'],
                    ]],
                    'collaborators'   => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Jusqu\'à 3 comptes invités comme co-auteurs.'],
                    'location_id'     => ['type' => 'string', 'description' => 'Identifiant de lieu Instagram à associer au post.'],
                    'is_ai_generated' => ['type' => 'boolean', 'description' => 'Signale un contenu généré par IA (Instagram affiche alors une mention).'],
                    'fit'             => $fitArg,
                    'stage'           => $stageArg,
                ],
                'required' => [],
            ],
            'outputSchema' => $publishOutput,
            'available'    => $connected,
            'requires'     => $connected ? [] : [$needConnection],
            'errors'       => array_merge(['Ni « image_url » ni « image_base64 » n\'a été fourni.'], $mediaErrors),
        ],
        [
            'name'        => 'instagram_publish_carousel',
            'description' => 'Publie un carrousel de 2 à 10 images dans le fil Instagram. Chaque élément est fourni par une URL publique ou en base64, avec son texte alternatif éventuel ; la légende porte sur le carrousel entier. Attention : toutes les images sont recadrées d\'après la première. Un carrousel ne compte que pour une publication au regard du quota.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'items' => [
                        'type'        => 'array',
                        'minItems'    => IG_CAROUSEL_MIN,
                        'maxItems'    => IG_CAROUSEL_MAX,
                        'description' => 'Les images du carrousel, dans l\'ordre d\'affichage souhaité (2 à 10).',
                        'items'       => [
                            'type'       => 'object',
                            'properties' => $imageSource + [
                                'alt_text' => ['type' => 'string', 'description' => 'Texte alternatif de cette image (1000 caractères max).'],
                            ],
                        ],
                    ],
                    'caption'         => ['type' => 'string', 'description' => 'Légende du carrousel (2200 caractères, 30 hashtags et 20 mentions @ maximum).'],
                    'collaborators'   => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Jusqu\'à 3 comptes invités comme co-auteurs.'],
                    'location_id'     => ['type' => 'string', 'description' => 'Identifiant de lieu Instagram à associer au carrousel.'],
                    'is_ai_generated' => ['type' => 'boolean', 'description' => 'Signale un contenu généré par IA.'],
                    'fit'             => $fitArg,
                    'stage'           => $stageArg,
                    'children'        => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Reprise après interruption : identifiants de conteneurs enfants déjà créés, à réutiliser au lieu de renvoyer les images.'],
                ],
                'required' => [],
            ],
            'outputSchema' => $publishOutput,
            'available'    => $connected,
            'requires'     => $connected ? [] : [$needConnection],
            'errors'       => array_merge(
                ['Le carrousel doit comporter entre ' . IG_CAROUSEL_MIN . ' et ' . IG_CAROUSEL_MAX . ' images.'],
                $mediaErrors
            ),
        ],
        [
            'name'        => 'instagram_publish_container',
            'description' => 'Termine une publication interrompue. Quand la préparation des images dépasse le temps d\'exécution du serveur, les outils de publication renvoient les identifiants de conteneurs déjà créés : cet outil les publie. Les conteneurs restent valables 24 heures.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'creation_id' => ['type' => 'string', 'description' => 'Identifiant du conteneur à publier, tel que renvoyé par un outil de publication interrompu.'],
                ],
                'required' => ['creation_id'],
            ],
            'outputSchema' => $publishOutput,
            'available'    => $connected,
            'requires'     => $connected ? [] : [$needConnection],
            'errors'       => [
                'Le conteneur n\'existe plus ou a expiré (durée de vie : 24 heures).',
                'Le conteneur n\'est pas encore prêt : réessayez dans quelques instants.',
            ],
        ],
        [
            'name'        => 'instagram_get_profile',
            'description' => 'Retourne le compte Instagram connecté : nom d\'utilisateur, type de compte, nombre d\'abonnés et de publications, et la date d\'expiration du token.',
            'inputSchema' => ['type' => 'object', 'properties' => [], 'required' => []],
            'outputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'ig_user_id'      => ['type' => 'string'],
                    'username'        => ['type' => 'string'],
                    'account_type'    => ['type' => 'string', 'description' => 'BUSINESS ou MEDIA_CREATOR.'],
                    'followers_count' => ['type' => 'integer'],
                    'media_count'     => ['type' => 'integer'],
                ],
                'required' => ['ig_user_id', 'username'],
            ],
            'available' => $connected,
            'requires'  => $connected ? [] : [$needConnection],
            'errors'    => ['Le token a expiré ou a été révoqué.'],
        ],
        [
            'name'        => 'instagram_publishing_limit',
            'description' => 'Indique combien de publications ont été faites par l\'API dans les 24 dernières heures et combien il en reste. La valeur du quota est lue en direct auprès d\'Instagram, la documentation officielle se contredisant sur ce chiffre. Un carrousel compte pour une seule publication.',
            'inputSchema' => ['type' => 'object', 'properties' => [], 'required' => []],
            'outputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'used'      => ['type' => 'integer', 'description' => 'Publications effectuées sur les 24 dernières heures.'],
                    'total'     => ['type' => 'integer', 'description' => 'Quota total sur 24 heures.'],
                    'remaining' => ['type' => 'integer', 'description' => 'Publications encore possibles.'],
                    'quota_reported' => ['type' => 'boolean', 'description' => 'Vrai si Instagram a renvoyé la valeur du quota ; faux si c\'est la borne prudente par défaut qui est affichée.'],
                ],
                'required' => ['used', 'total', 'remaining', 'quota_reported'],
            ],
            'available' => $connected,
            'requires'  => $connected ? [] : [$needConnection],
            'errors'    => ['Le token a expiré ou a été révoqué.'],
        ],
        [
            'name'        => 'instagram_list_media',
            'description' => 'Liste les publications récentes du compte, avec leur identifiant, leur légende, leur type et leur URL publique. Utile pour vérifier qu\'une publication est bien passée.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'limit' => ['type' => 'integer', 'description' => 'Nombre de publications à retourner (1 à 50, défaut 10).'],
                ],
                'required' => [],
            ],
            'outputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'media' => ['type' => 'array', 'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'id'                 => ['type' => 'string'],
                            'caption'            => ['type' => 'string'],
                            'media_type'         => ['type' => 'string', 'description' => 'IMAGE, VIDEO ou CAROUSEL_ALBUM. Annonce IMAGE ou VIDEO même pour une story ou un reel.'],
                            'media_product_type' => ['type' => 'string', 'description' => 'FEED, REELS ou STORY — c\'est ce champ qui distingue un reel d\'une publication de fil.'],
                            'permalink'          => ['type' => 'string'],
                            'timestamp'          => ['type' => 'string'],
                        ],
                        'required' => ['id'],
                    ]],
                ],
                'required' => ['media'],
            ],
            'available' => $connected,
            'requires'  => $connected ? [] : [$needConnection],
            'errors'    => ['Le token a expiré ou a été révoqué.'],
        ],
        [
            'name'        => 'instagram_delete_media',
            'description' => 'Supprime une publication Instagram.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => ['media_id' => ['type' => 'string', 'description' => 'Identifiant de la publication à supprimer.']],
                'required'   => ['media_id'],
            ],
            'available' => false,
            'requires'  => ['Non disponible : Instagram ne propose la suppression de publication que par le chemin d\'authentification « Facebook Login », qui exige une page Facebook liée au compte. Ce connecteur utilise « Instagram Login ». Supprimez la publication depuis l\'application Instagram.'],
            'errors'    => [],
        ],
    ];

    return $catalog;
}

/** Outils réellement exposés : le catalogue filtré, réduit aux champs MCP. */
function instagram_tools(array $settings): array
{
    $tools = [];
    foreach (instagram_tool_catalog($settings) as $tool) {
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
function instagram_call(array $config, string $name, array $args): array
{
    $settings = config_settings($config);

    // Le token se renouvelle à l'usage : sans tâche planifiée, c'est le seul
    // moment où l'on peut le faire.
    if (in_array($name, ['instagram_publish_image', 'instagram_publish_carousel',
        'instagram_publish_container', 'instagram_get_profile',
        'instagram_publishing_limit', 'instagram_list_media'], true)) {
        $settings = ig_refresh_if_due($config, $settings);
    }

    return match ($name) {
        'instagram_publish_image'     => ig_tool_publish_image($settings, $args),
        'instagram_publish_carousel'  => ig_tool_publish_carousel($settings, $args),
        'instagram_publish_container' => ig_tool_publish_container($settings, $args),
        'instagram_get_profile'       => ig_tool_profile($settings),
        'instagram_publishing_limit'  => ig_tool_publishing_limit($settings),
        'instagram_list_media'        => ig_tool_list_media($settings, $args),
        'instagram_delete_media'      => throw new McpToolError('La suppression de publication n\'est pas disponible avec l\'authentification « Instagram Login » : Instagram ne l\'expose que via « Facebook Login », qui exige une page Facebook liée. Supprimez la publication depuis l\'application Instagram.'),
        default => throw new McpError(-32602, 'Unknown tool: ' . $name),
    };
}

function ig_tool_publish_image(array $settings, array $args): array
{
    ig_require_connection($settings);

    $caption = ig_check_caption($args['caption'] ?? '');

    $media  = ig_resolve_image($settings, $args, 'image', ig_arg_fit($args), ig_arg_stage($args));
    $params = ['image_url' => $media['url']];
    if ($caption !== '') {
        $params['caption'] = $caption;
    }
    ig_apply_common_params($params, $args);
    $altText = ig_arg_text($args['alt_text'] ?? null, 'alt_text');
    if ($altText !== '') {
        $params['alt_text'] = ig_check_alt_text($altText);
    }
    if (($tags = ig_build_user_tags($args)) !== null) {
        $params['user_tags'] = $tags;
    }

    $containerId = ig_container_create($settings, $params);
    // Le budget démarre ici : le compter depuis le début de l'outil ferait
    // annoncer « Instagram traite encore » avant même qu'il ait eu la main.
    ig_container_await($settings, $containerId, ig_poll_deadline(), [$containerId]);
    $result = ig_publish($settings, $containerId);

    ig_sweep_media();

    return ig_publish_result($settings, $result, $media['notes'], 'Image publiée sur Instagram');
}

function ig_tool_publish_carousel(array $settings, array $args): array
{
    ig_require_connection($settings);

    $caption = ig_check_caption($args['caption'] ?? '');

    // Reprise : des conteneurs enfants déjà prêts peuvent être réutilisés.
    $children = [];
    foreach ((array) ($args['children'] ?? []) as $childId) {
        $childId = ig_arg_text($childId, 'children');
        if ($childId !== '') {
            $children[] = $childId;
        }
    }

    $notes = [];

    if ($children === []) {
        $items = $args['items'] ?? null;
        if (!is_array($items) || !array_is_list($items)) {
            throw new McpToolError('Argument « items » manquant : fournissez la liste des images du carrousel (entre '
                . IG_CAROUSEL_MIN . ' et ' . IG_CAROUSEL_MAX . ').');
        }
        if (count($items) < IG_CAROUSEL_MIN || count($items) > IG_CAROUSEL_MAX) {
            throw new McpToolError('Un carrousel Instagram comporte entre ' . IG_CAROUSEL_MIN . ' et '
                . IG_CAROUSEL_MAX . ' images ; ' . count($items) . ' ' . (count($items) > 1 ? 'ont' : 'a')
                . ' été fournie' . (count($items) > 1 ? 's' : '') . '.');
        }

        ig_check_total_payload($items);

        foreach ($items as $i => $item) {
            if (!is_array($item)) {
                throw new McpToolError('L\'élément ' . ($i + 1) . ' du carrousel n\'est pas un objet.');
            }
            $label   = 'image ' . ($i + 1);
            $altText = ig_arg_text($item['alt_text'] ?? null, 'alt_text');
            $media   = ig_resolve_image($settings, $item, $label, ig_arg_fit($args), ig_arg_stage($args));

            foreach ($media['notes'] as $note) {
                $notes[] = ucfirst($label) . ' : ' . $note;
            }

            // La légende et le lieu ne sont pas acceptés sur les enfants — et
            // une légende envoyée ici serait ignorée en silence par Instagram.
            $params = ['image_url' => $media['url'], 'is_carousel_item' => 'true'];
            if ($altText !== '') {
                $params['alt_text'] = ig_check_alt_text($altText);
            }
            $children[] = ig_container_create($settings, $params);
        }

        // Chaque enfant doit être prêt : créer le parent trop tôt échoue.
        // Le budget ne démarre qu'ici, une fois les conversions et les envois
        // terminés : sinon il serait déjà épuisé avant la première attente.
        $deadline = ig_poll_deadline();
        foreach ($children as $childId) {
            ig_container_await($settings, $childId, $deadline, $children, true);
        }
    }

    // Les bornes se contrôlent avant toute attente : proposer de reprendre un
    // carrousel qui ne peut pas exister serait un mauvais conseil.
    if (count($children) < IG_CAROUSEL_MIN || count($children) > IG_CAROUSEL_MAX) {
        throw new McpToolError('Un carrousel Instagram comporte entre ' . IG_CAROUSEL_MIN . ' et '
            . IG_CAROUSEL_MAX . ' images ; ' . count($children) . ' conteneur(s) ont été fournis dans « children ».');
    }

    // Sur une reprise, les enfants viennent de l'appelant : rien ne garantit
    // qu'Instagram a fini de les traiter, et créer le parent trop tôt échoue.
    if (($args['children'] ?? []) !== []) {
        $deadline = ig_poll_deadline();
        foreach ($children as $childId) {
            ig_container_await($settings, $childId, $deadline, $children, true);
        }
    }

    $params = ['media_type' => 'CAROUSEL', 'children' => implode(',', $children)];
    if ($caption !== '') {
        $params['caption'] = $caption;
    }
    ig_apply_common_params($params, $args);

    $parentId = ig_container_create($settings, $params);
    // Le parent puise dans la même enveloppe que les enfants : deux budgets
    // enchaînés dépasseraient le temps d'exécution alloué au script.
    ig_container_await($settings, $parentId, $deadline ?? ig_poll_deadline(), [$parentId]);
    $result = ig_publish($settings, $parentId);

    ig_sweep_media();

    return ig_publish_result($settings, $result, $notes,
        'Carrousel de ' . count($children) . ' images publié sur Instagram');
}

/**
 * Échéance des attentes d'un appel, en secondes depuis l'époque.
 *
 * Bornée par le temps d'exécution que l'hébergement laisse au script : être
 * tué en pleine attente priverait l'appelant de la réponse qui lui dirait
 * comment reprendre. On garde une marge pour l'envoi de la réponse.
 *
 * Le calcul compare volontairement du temps mural au plafond de
 * max_execution_time, qui sous Linux ne compte pas les appels bloquants comme
 * sleep(). La borne est donc prudente, et c'est voulu : ce n'est pas PHP seul
 * qui interrompt une requête trop longue, mais aussi le serveur web, le
 * gestionnaire FastCGI ou le proxy en amont — et ceux-là comptent bien en
 * temps mural. Trop attendre coûte la réponse ; attendre trop peu ne coûte
 * qu'une reprise, dont le message donne la marche à suivre.
 */
function ig_poll_deadline(): int
{
    $max = (int) ini_get('max_execution_time');
    if ($max <= 0) {
        return time() + IG_POLL_BUDGET; // pas de limite (CLI, ou réglage désactivé)
    }
    $reste = $max - (int) (microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true)));
    return time() + max(3, min(IG_POLL_BUDGET, $reste - 5));
}

function ig_tool_publish_container(array $settings, array $args): array
{
    ig_require_connection($settings);

    $creationId = ig_arg_text($args['creation_id'] ?? null, 'creation_id');
    if ($creationId === '') {
        throw new McpToolError('Argument « creation_id » manquant : indiquez le conteneur à publier.');
    }
    ig_container_await($settings, $creationId, ig_poll_deadline(), [$creationId]);
    $result = ig_publish($settings, $creationId);

    return ig_publish_result($settings, $result, [], 'Publication terminée');
}

function ig_tool_profile(array $settings): array
{
    ig_require_connection($settings);

    try {
        $identity = ig_fetch_identity((string) $settings['access_token']);
    } catch (RuntimeException $e) {
        throw new McpToolError('Lecture du profil refusée : ' . $e->getMessage());
    }

    $lines = [
        'Compte Instagram connecté :',
        '- Nom d\'utilisateur : @' . ($identity['username'] !== '' ? $identity['username'] : '—'),
        '- Type de compte : ' . ($identity['account_type'] !== '' ? $identity['account_type'] : '—'),
        '- Abonnés : ' . ($identity['followers_count'] ?? '—'),
        '- Publications : ' . ($identity['media_count'] ?? '—'),
        '- Identifiant : ' . $identity['ig_user_id'],
        '- Token valable jusqu\'au : ' . ($settings['token_expires_at'] ?? '—') . ' UTC',
    ];
    // L'outputSchema annonce des entiers : mieux vaut omettre un compteur
    // qu'Instagram n'a pas renvoyé que de le déclarer nul.
    return mcp_tool_result(implode("\n", $lines), array_filter($identity, fn ($v) => $v !== null));
}

function ig_tool_publishing_limit(array $settings): array
{
    ig_require_connection($settings);

    [$status, , $data] = ig_graph($settings, 'GET',
        '/' . rawurlencode((string) $settings['ig_user_id']) . '/content_publishing_limit',
        ['fields' => 'config,quota_usage']);
    if ($status !== 200) {
        throw ig_api_error('Lecture du quota refusée', $status, $data);
    }

    $row   = $data['data'][0] ?? [];
    $used  = (int) ($row['quota_usage'] ?? 0);
    // La documentation annonce tantôt 50, tantôt 100 : la seule valeur sûre
    // est celle que renvoie le compte lui-même.
    $reported = isset($row['config']['quota_total']);
    $total    = $reported ? (int) $row['config']['quota_total'] : IG_QUOTA_FALLBACK;
    $left     = max(0, $total - $used);

    return mcp_tool_result(
        "Publications par l'API sur les 24 dernières heures : $used sur $total. Il en reste $left."
            . ($reported ? '' : ' (Instagram n\'a pas renvoyé la valeur du quota : '
                . IG_QUOTA_FALLBACK . ' est la borne prudente retenue par défaut.)'),
        ['used' => $used, 'total' => $total, 'remaining' => $left, 'quota_reported' => $reported]
    );
}

function ig_tool_list_media(array $settings, array $args): array
{
    ig_require_connection($settings);

    $limit = (int) ($args['limit'] ?? 10);
    $limit = max(1, min(50, $limit));

    [$status, , $data] = ig_graph($settings, 'GET',
        '/' . rawurlencode((string) $settings['ig_user_id']) . '/media',
        ['fields' => 'id,caption,media_type,media_product_type,permalink,timestamp', 'limit' => (string) $limit]);
    if ($status !== 200) {
        throw ig_api_error('Lecture des publications refusée', $status, $data);
    }

    $media = [];
    $lines = [];
    foreach ($data['data'] ?? [] as $item) {
        $media[] = [
            'id'                 => (string) ($item['id'] ?? ''),
            'caption'            => (string) ($item['caption'] ?? ''),
            // media_type annonce IMAGE ou VIDEO même pour une story ou un
            // reel : media_product_type est le champ qui départage. Les deux
            // sont renvoyés plutôt que l'un déguisé en l'autre.
            'media_type'         => (string) ($item['media_type'] ?? ''),
            'media_product_type' => (string) ($item['media_product_type'] ?? ''),
            'permalink'          => (string) ($item['permalink'] ?? ''),
            'timestamp'          => (string) ($item['timestamp'] ?? ''),
        ];
        $lines[] = '- ' . ($item['timestamp'] ?? '?') . ' — ' . mb_str_limit((string) ($item['caption'] ?? '(sans légende)'), 60)
            . ' — ' . ($item['permalink'] ?? '');
    }

    return mcp_tool_result(
        $media === [] ? 'Aucune publication trouvée.' : count($media) . " publication(s) :\n" . implode("\n", $lines),
        ['media' => $media]
    );
}

/* -------------------------------------------------- Publication : rouages */

/** Crée un conteneur média et retourne son identifiant. */
function ig_container_create(array $settings, array $params): string
{
    [$status, , $data] = ig_graph($settings, 'POST',
        '/' . rawurlencode((string) $settings['ig_user_id']) . '/media', $params);
    if ($status !== 200 || empty($data['id'])) {
        throw ig_api_error('Préparation du média refusée', $status, $data);
    }
    return (string) $data['id'];
}

/**
 * Attend qu'un conteneur soit prêt.
 *
 * Le budget est court volontairement : un hébergement mutualisé coupe souvent
 * l'exécution à 30 s. En cas de dépassement rien n'est perdu — les conteneurs
 * vivent 24 h — d'où le message qui indique comment reprendre.
 */
function ig_container_await(array $settings, string $containerId, int $deadline, array $ids, bool $isChild = false): void
{
    $depart = time();
    $delay  = 1;
    while (true) {
        [$status, , $data] = ig_graph($settings, 'GET', '/' . rawurlencode($containerId),
            ['fields' => 'status_code,status']);
        if ($status !== 200) {
            throw ig_api_error('Lecture de l\'état du média refusée', $status, $data);
        }

        $state = (string) ($data['status_code'] ?? '');
        if ($state === 'FINISHED' || $state === 'PUBLISHED') {
            return;
        }
        if ($state === 'ERROR') {
            // « status » porte le détail de l'erreur, pas « status_code ».
            throw new McpToolError('Instagram a rejeté le média pendant son traitement : '
                . ((string) ($data['status'] ?? '') ?: 'aucun détail fourni')
                . ' — vérifiez que l\'image est bien accessible publiquement et conforme (JPEG, moins de 8 Mo, rapport entre 4:5 et 1.91:1).');
        }
        if ($state === 'EXPIRED') {
            throw new McpToolError('Le conteneur a expiré avant d\'être publié (durée de vie : 24 heures). Relancez la publication.');
        }
        if (time() + $delay >= $deadline) {
            throw new McpToolError(ig_resume_message($ids, $isChild, max(1, time() - $depart)));
        }
        sleep($delay);
        $delay = min($delay + 1, 3);
    }
}

/** Message de reprise quand le budget d'attente est épuisé. */
function ig_resume_message(array $ids, bool $isChild, int $attendu): string
{
    if ($isChild) {
        return 'Instagram traite encore les images du carrousel après ' . $attendu
            . ' s. Rien n\'est perdu : relancez instagram_publish_carousel avec children = ['
            . implode(', ', array_map(fn ($id) => '"' . $id . '"', $ids))
            . '] (conteneurs valables 24 h) pour terminer sans renvoyer les images.';
    }
    return 'Instagram traite encore le média après ' . $attendu
        . ' s et la publication n\'est pas faite. Rien n\'est perdu : appelez instagram_publish_container avec creation_id = "'
        . implode('', $ids) . '" (conteneur valable 24 h) pour la terminer.';
}

/** Publie un conteneur préparé. */
function ig_publish(array $settings, string $creationId): array
{
    [$status, , $data] = ig_graph($settings, 'POST',
        '/' . rawurlencode((string) $settings['ig_user_id']) . '/media_publish',
        ['creation_id' => $creationId]);
    if ($status !== 200 || empty($data['id'])) {
        throw ig_api_error('Publication refusée', $status, $data);
    }
    return $data;
}

/** Compose le résultat d'outil d'une publication réussie. */
function ig_publish_result(array $settings, array $published, array $notes, string $headline): array
{
    $mediaId   = (string) ($published['id'] ?? '');
    $permalink = ig_fetch_permalink($settings, $mediaId);
    $username  = (string) ($settings['username'] ?? '');

    $text = $headline . ($username !== '' ? ' (@' . $username . ')' : '') . ".\nIdentifiant : $mediaId"
        . ($permalink !== '' ? "\nURL : $permalink" : '');
    if ($notes !== []) {
        $text .= "\nAjustements appliqués : " . implode(' ; ', $notes) . '.';
    }

    return mcp_tool_result($text, [
        'media_id'  => $mediaId,
        'permalink' => $permalink,
        'username'  => $username,
        'notes'     => $notes,
    ]);
}

/** URL publique d'une publication (best effort : l'absence n'est pas une erreur). */
function ig_fetch_permalink(array $settings, string $mediaId): string
{
    if ($mediaId === '') {
        return '';
    }
    try {
        [$status, , $data] = ig_graph($settings, 'GET', '/' . rawurlencode($mediaId), ['fields' => 'permalink']);
    } catch (McpToolError) {
        return '';
    }
    return $status === 200 ? (string) ($data['permalink'] ?? '') : '';
}

/** Paramètres communs au conteneur d'une image seule et au parent d'un carrousel. */
function ig_apply_common_params(array &$params, array $args): void
{
    $locationId = ig_arg_text($args['location_id'] ?? null, 'location_id');
    if ($locationId !== '') {
        $params['location_id'] = $locationId;
    }
    if (ig_arg_bool($args['is_ai_generated'] ?? null)) {
        $params['is_ai_generated'] = 'true';
    }

    $collaborators = array_values(array_filter(array_map(
        static fn ($c) => ltrim(ig_arg_text($c, 'collaborators'), '@'),
        (array) ($args['collaborators'] ?? [])
    ), static fn ($c) => $c !== ''));
    if ($collaborators !== []) {
        if (count($collaborators) > IG_COLLABORATORS_MAX) {
            throw new McpToolError('Instagram accepte au maximum ' . IG_COLLABORATORS_MAX
                . ' co-auteurs ; ' . count($collaborators) . ' ont été fournis.');
        }
        $params['collaborators'] = json_encode($collaborators, JSON_UNESCAPED_UNICODE);
    }
}

/** Construit le paramètre user_tags, ou null s'il n'y en a pas. */
function ig_build_user_tags(array $args): ?string
{
    $tags = $args['user_tags'] ?? null;
    if (!is_array($tags) || $tags === []) {
        return null;
    }
    $built = [];
    foreach ($tags as $i => $tag) {
        if (!is_array($tag)) {
            throw new McpToolError('L\'entrée ' . ($i + 1) . ' de « user_tags » n\'est pas un objet.');
        }
        $username = ltrim(ig_arg_text($tag['username'] ?? null, 'user_tags.username'), '@');
        if ($username === '') {
            throw new McpToolError('L\'entrée ' . ($i + 1) . ' de « user_tags » n\'indique pas de « username ».');
        }
        // Instagram exige une position pour identifier quelqu'un sur une image.
        if (!isset($tag['x']) || !isset($tag['y'])) {
            throw new McpToolError('L\'entrée « ' . $username . ' » de « user_tags » doit préciser sa position « x » et « y » (de 0.0 à 1.0) : Instagram l\'exige sur les images.');
        }
        if (!is_numeric($tag['x']) || !is_numeric($tag['y'])) {
            throw new McpToolError('La position de « ' . $username . ' » dans « user_tags » doit être numérique (de 0.0 à 1.0).');
        }
        $x = (float) $tag['x'];
        $y = (float) $tag['y'];
        if ($x < 0 || $x > 1 || $y < 0 || $y > 1) {
            throw new McpToolError('La position de « ' . $username . ' » dans « user_tags » doit être comprise entre 0.0 et 1.0.');
        }
        $built[] = ['username' => $username, 'x' => $x, 'y' => $y];
    }
    if (count($built) > IG_CAPTION_MAX_MENTIONS) {
        throw new McpToolError('Instagram accepte au maximum ' . IG_CAPTION_MAX_MENTIONS
            . ' comptes identifiés par publication.');
    }
    return json_encode($built, JSON_UNESCAPED_UNICODE);
}

/* --------------------------------------------------- Résolution des images */

/**
 * Ramène une image à une URL publique exploitable par Instagram.
 *
 * Retourne ['url', 'key' => ?string (dépôt à libérer), 'notes' => string[]].
 */
function ig_resolve_image(array $settings, array $args, string $label, string $fit, string $stage): array
{
    $url    = ig_arg_text($args['image_url'] ?? null, 'image_url');
    $base64 = ig_arg_text($args['image_base64'] ?? null, 'image_base64');

    if ($url !== '' && $base64 !== '') {
        throw new McpToolError("Pour « $label », fournissez « image_url » OU « image_base64 », pas les deux.");
    }
    if ($url === '' && $base64 === '') {
        throw new McpToolError("Aucune image fournie pour « $label » : indiquez « image_url » (URL publique directe) ou « image_base64 » (contenu encodé). Ce connecteur est hébergé à distance : il ne peut pas ouvrir un chemin de fichier de votre machine.");
    }

    if ($base64 !== '') {
        // Refus avant décodage : inutile de matérialiser en mémoire un contenu
        // qu'on rejettera de toute façon (base64 pèse ~4/3 des octets réels).
        if (strlen($base64) > IG_BASE64_MAX_CHARS) {
            throw new McpToolError("« image_base64 » est trop volumineux pour « $label » : "
                . image_format_bytes((int) (strlen($base64) * 3 / 4)) . ', alors que cet hébergement ne peut en traiter que '
                . image_format_bytes((int) (IG_BASE64_MAX_CHARS * 3 / 4)) . ' par image. Réduisez l\'image avant de l\'envoyer, ou publiez-la par « image_url ».');
        }
        $bytes = base64_decode(preg_replace('/\s+/', '', $base64) ?? $base64, true);
        if ($bytes === false || $bytes === '') {
            throw new McpToolError("« image_base64 » n'est pas un contenu base64 valide pour « $label ».");
        }
        return ig_stage_bytes($bytes, $label, $fit);
    }

    // Avec stage=never nous ne joignons pas l'URL — c'est Instagram qui ira la
    // chercher : inutile d'exiger que notre propre DNS la résolve.
    http_guard_public_url($url, 'image_url', $stage !== 'never');

    if ($stage === 'never') {
        // Meta prévient qu'une URL non ASCII fait échouer la requête.
        if (preg_match('/[^\x21-\x7E]/', $url)) {
            throw new McpToolError("L'URL fournie pour « $label » contient des caractères non ASCII, qu'Instagram refuse. Utilisez une URL sans accent ni espace, ou laissez « stage » à « auto » pour que le connecteur ré-héberge l'image.");
        }
        return ['url' => $url, 'key' => null, 'notes' => []];
    }

    $transfer = null;
    $bytes = http_download_limited($url, 20000000, 'image_url', 30,
        'L\'image téléchargée dépasse 20 Mo : trop lourde pour être préparée.', $transfer);

    if ($stage === 'auto' && ig_url_usable_as_is(image_inspect($bytes, $label), $transfer ?? [], $url)) {
        return ['url' => $url, 'key' => null, 'notes' => []];
    }

    return ig_stage_bytes($bytes, $label, $fit);
}

/**
 * L'URL fournie peut-elle être transmise telle quelle à Instagram ?
 *
 * Oui seulement si l'image qu'elle sert est déjà conforme, si l'URL est en
 * ASCII pur (Meta refuse le reste), et si elle a répondu SANS redirection :
 * nous suivons les redirections pour valider l'image, Instagram ne le fait pas
 * forcément. Transmettre l'URL de départ reviendrait alors à valider une image
 * et à en faire publier une autre — ou aucune.
 */
function ig_url_usable_as_is(array $info, array $transfer, string $url): bool
{
    // À défaut d'information sur les redirections, on ré-héberge : mieux vaut
    // un dépôt superflu qu'une publication qui échoue chez Instagram.
    if (!isset($transfer['redirects']) || (int) $transfer['redirects'] !== 0) {
        return false;
    }
    $spec = ig_image_spec();
    return $info['mime'] === $spec['mime']
        && $info['size'] <= $spec['max_bytes']
        && $info['width'] >= $spec['min_width'] && $info['width'] <= $spec['max_width']
        && $info['ratio'] >= $spec['min_ratio'] && $info['ratio'] <= $spec['max_ratio']
        && !preg_match('/[^\x21-\x7E]/', $url);
}

/** Normalise des octets puis les dépose pour qu'Instagram vienne les chercher. */
function ig_stage_bytes(string $bytes, string $label, string $fit): array
{
    $prepared = image_prepare($bytes, ig_image_spec(), $fit, $label);
    try {
        $put = media_put($prepared['bytes'], $prepared['mime']);
    } catch (RuntimeException $e) {
        throw new McpToolError('Impossible de préparer l\'image pour Instagram : ' . $e->getMessage()
            . ' Instagram télécharge les images depuis une URL publique servie par ce hub ; le répertoire storage/ doit être inscriptible.');
    }
    return ['url' => $put['url'], 'key' => $put['key'], 'notes' => $prepared['notes'] ?? []];
}

/**
 * Purge les dépôts périmés, après une publication réussie.
 *
 * On ne supprime PAS les images qui viennent de servir : Meta ne s'engage que
 * sur leur disponibilité « au moment de la tentative » et ne documente rien
 * au-delà. Elles s'effacent d'elles-mêmes à l'expiration (24 h, comme les
 * conteneurs) ; ici on se contente de faire le ménage des précédentes.
 */
function ig_sweep_media(): void
{
    // La publication est faite : plus rien ici ne doit pouvoir la faire
    // passer pour un échec aux yeux de l'appelant.
    try {
        media_gc();
    } catch (Throwable $e) {
        error_log('Instagram : purge des médias impossible — ' . $e->getMessage());
    }
}

/* ------------------------------------------------------------- Garde-fous */

/** Vérifie que le compte est connecté et que le token n'est pas expiré. */
function ig_require_connection(array $settings): void
{
    if (empty($settings['access_token']) || empty($settings['ig_user_id'])) {
        throw new McpToolError('Instagram n\'est pas connecté sur ce connecteur. Le propriétaire doit ouvrir la page du connecteur et connecter un compte Instagram professionnel.');
    }
    if (!empty($settings['token_expires_at']) && $settings['token_expires_at'] < now()) {
        throw new McpToolError('Le token Instagram a expiré (validité 60 jours, renouvelée automatiquement tant que le connecteur sert). Le propriétaire doit se reconnecter depuis la page du connecteur.');
    }
}

/**
 * Refuse un carrousel dont les images cumulées dépassent ce que l'hébergement
 * peut traiter. Sans ce contrôle, PHP s'arrête sur une erreur fatale au milieu
 * du traitement : le client MCP ne reçoit alors aucune réponse exploitable.
 */
function ig_check_total_payload(array $items): void
{
    $total = 0;
    foreach ($items as $item) {
        if (is_array($item)) {
            $total += strlen((string) ($item['image_base64'] ?? ''));
        }
    }
    if ($total > IG_BASE64_MAX_TOTAL_CHARS) {
        throw new McpToolError('Les ' . count($items) . ' images du carrousel totalisent '
            . image_format_bytes((int) ($total * 3 / 4)) . ', au-delà des '
            . image_format_bytes((int) (IG_BASE64_MAX_TOTAL_CHARS * 3 / 4))
            . ' que cet hébergement peut traiter en une fois. Réduisez les images avant de les envoyer, ou publiez-les par « image_url » : Instagram les téléchargera alors directement, sans passer par la mémoire du serveur.');
    }
}

/**
 * Lit un argument à choisir dans une liste fermée.
 *
 * Toute autre valeur retombe sur le défaut. Une liste, notamment, provoquerait
 * une TypeError en atteignant une fonction au paramètre typé — donc une
 * « Internal error » sans le moindre indice pour l'appelant.
 */
function ig_arg_choice(mixed $value, array $allowed, string $default): string
{
    return is_string($value) && in_array($value, $allowed, true) ? $value : $default;
}

/** Politique de recadrage demandée. */
function ig_arg_fit(array $args): string
{
    return ig_arg_choice($args['fit'] ?? null, ['reject', 'pad'], 'reject');
}

/** Politique de ré-hébergement demandée. */
function ig_arg_stage(array $args): string
{
    return ig_arg_choice($args['stage'] ?? null, ['auto', 'never', 'always'], 'auto');
}

/**
 * Lit un argument censé être du texte.
 *
 * Un client MCP peut envoyer n'importe quelle forme JSON. Sans ce contrôle,
 * un tableau passé en légende deviendrait la chaîne « Array » — et serait
 * publié tel quel sur Instagram.
 */
/**
 * Lit un argument booléen.
 *
 * Un client peut envoyer la chaîne « false », que !empty() tient pour vraie :
 * une publication se retrouverait alors marquée « générée par IA » contre
 * l'intention de l'utilisateur.
 */
function ig_arg_bool(mixed $value): bool
{
    if (is_string($value)) {
        return !in_array(strtolower(trim($value)), ['', '0', 'false', 'non', 'no'], true);
    }
    return (bool) $value;
}

function ig_arg_text(mixed $value, string $key): string
{
    if ($value === null) {
        return '';
    }
    if (is_array($value) || is_object($value) || is_bool($value)) {
        throw new McpToolError("L'argument « $key » doit être du texte, pas "
            . (is_bool($value) ? 'un booléen' : 'une liste ou un objet') . '.');
    }
    return trim((string) $value);
}

/** Contrôle la légende et la retourne nettoyée. */
function ig_check_caption(mixed $caption): string
{
    $caption = ig_arg_text($caption, 'caption');
    if ($caption === '') {
        return '';
    }
    $length = function_exists('mb_strlen') ? mb_strlen($caption, 'UTF-8') : strlen($caption);
    if ($length > IG_CAPTION_MAX_CHARS) {
        throw new McpToolError('La légende fait ' . $length . ' caractères pour un maximum de '
            . IG_CAPTION_MAX_CHARS . ' chez Instagram.');
    }
    $hashtags = preg_match_all('/(?<![\w#])#[\p{L}\p{N}_]+/u', $caption);
    if ($hashtags > IG_CAPTION_MAX_TAGS) {
        throw new McpToolError('La légende contient ' . $hashtags . ' hashtags pour un maximum de '
            . IG_CAPTION_MAX_TAGS . ' chez Instagram.');
    }
    $mentions = preg_match_all('/(?<![\w@])@[A-Za-z0-9._]+/u', $caption);
    if ($mentions > IG_CAPTION_MAX_MENTIONS) {
        throw new McpToolError('La légende contient ' . $mentions . ' mentions @ pour un maximum de '
            . IG_CAPTION_MAX_MENTIONS . ' chez Instagram.');
    }
    return $caption;
}

/** Contrôle le texte alternatif. */
function ig_check_alt_text(string $altText): string
{
    $length = function_exists('mb_strlen') ? mb_strlen($altText, 'UTF-8') : strlen($altText);
    if ($length > IG_ALT_TEXT_MAX) {
        throw new McpToolError('Le texte alternatif fait ' . $length . ' caractères pour un maximum de '
            . IG_ALT_TEXT_MAX . ' chez Instagram.');
    }
    return $altText;
}

/* ------------------------------------------------------------ Client API */

/**
 * Appel à l'API Graph d'Instagram.
 * Les paramètres sont toujours encodés par http_build_query : une légende
 * contenant emojis, retours à la ligne ou hashtags casse tout encodage
 * artisanal.
 */
function ig_graph(array $settings, string $method, string $path, array $params = []): array
{
    $params['access_token'] = (string) $settings['access_token'];
    $url  = IG_API_HOST . '/' . INSTAGRAM_API_VERSION . $path;
    $body = http_build_query($params);

    if ($method === 'GET') {
        return http_request('GET', $url . '?' . $body);
    }
    return http_request($method, $url, ['Content-Type: application/x-www-form-urlencoded'], $body);
}

/** Extrait la charge utile d'une réponse, qu'elle soit à plat ou enveloppée. */
function ig_unwrap(array $data): array
{
    if (isset($data['data'][0]) && is_array($data['data'][0])) {
        return $data['data'][0];
    }
    return $data;
}

/** Texte d'erreur lisible extrait d'une réponse Meta. */
function ig_error_text(array $data): string
{
    $error = $data['error'] ?? null;
    if (is_array($error)) {
        return (string) ($error['error_user_msg'] ?? $error['message'] ?? $error['type'] ?? 'erreur non détaillée');
    }
    return (string) ($data['error_message'] ?? $data['error_description'] ?? $data['error'] ?? 'réponse vide');
}

/**
 * Convertit une erreur de l'API en message actionnable.
 *
 * Meta renvoie un code générique et un « error_subcode » bien plus précis :
 * c'est ce dernier qui distingue une image trop lourde d'un mauvais rapport
 * d'aspect ou d'un quota atteint.
 */
function ig_api_error(string $prefix, int $status, array $data): McpToolError
{
    $error   = is_array($data['error'] ?? null) ? $data['error'] : [];
    $code    = (int) ($error['code'] ?? 0);
    $subcode = (int) ($error['error_subcode'] ?? 0);
    $detail  = ig_error_text($data);

    $hint = match (true) {
        $subcode === 2207052 => 'Instagram n\'a pas réussi à télécharger l\'image : l\'URL doit être publique, directe et sans redirection. Si vous avez fourni « image_url », relancez avec stage="always" pour que le connecteur héberge lui-même l\'image ; si l\'image venait déjà de ce connecteur, vérifiez qu\'aucune protection anti-robot ne bloque /media.php (bouton « Tester la publication média » sur la page du connecteur).',
        $subcode === 2207004 => 'Image trop lourde : la limite est de 8 Mo.',
        $subcode === 2207005 => 'Format d\'image non supporté : Instagram n\'accepte que le JPEG.',
        $subcode === 2207009 => 'Rapport d\'aspect refusé : Instagram n\'accepte que 4:5 à 1.91:1, et rejette au lieu de recadrer. Utilisez « fit »: « pad ».',
        $subcode === 2207010 => 'Légende trop longue (2200 caractères maximum).',
        $subcode === 2207003 => 'Le téléchargement de l\'image par Instagram a pris trop de temps : réessayez, ou hébergez l\'image sur un serveur plus rapide.',
        $subcode === 2207020 || $subcode === 2207008 => 'Le conteneur n\'existe plus ou a expiré : relancez la publication.',
        $subcode === 2207027 => 'Le média n\'est pas encore prêt : réessayez dans quelques secondes.',
        $subcode === 2207028 => 'Un carrousel comporte entre 2 et 10 images.',
        $subcode === 2207040 => 'Trop de comptes identifiés (20 maximum).',
        $subcode === 2207042 => 'Quota de publication atteint pour les dernières 24 heures : appelez instagram_publishing_limit pour savoir quand il se libère.',
        $subcode === 2207050 => 'Le compte Instagram est restreint ou inactif : connectez-vous à l\'application Instagram pour lever la restriction, puis réessayez.',
        $subcode === 2207051 => 'Instagram a temporairement restreint l\'activité de ce compte (protection anti-spam) : espacez les publications.',
        $subcode === 460     => 'La session Instagram a été invalidée — le plus souvent après un changement de mot de passe. Le propriétaire doit reconnecter le compte depuis la page du connecteur.',
        $subcode === 458     => 'L\'autorisation a été retirée à l\'application : le propriétaire doit reconnecter le compte.',
        $code === 190        => 'Le token Instagram est expiré ou révoqué : le propriétaire doit reconnecter le compte depuis la page du connecteur.',
        $code === 4 || $code === 17 || $status === 429 => 'Quota d\'appels Instagram atteint : réessayez plus tard.',
        $code === 10 || $code === 200 => 'Permission refusée : le compte doit être un compte professionnel et le scope instagram_business_content_publish accordé. Reconnectez le compte pour réaccorder les autorisations.',
        default => '',
    };

    return new McpToolError(trim(
        "$prefix (HTTP $status)." . ($detail !== '' ? " Instagram : $detail." : '') . ($hint !== '' ? " $hint" : '')
    ));
}

/* --------------------------------------------- Interface (page connecteur) */

/** Champs de réglages propres à Instagram. */
function instagram_settings_form(array $config, array $settings): void
{
    $hasInstanceApp = INSTAGRAM_DEFAULT_APP_ID !== '' && INSTAGRAM_DEFAULT_APP_SECRET !== '';

    echo '<p class="hint" style="margin-bottom:18px">' . ui_icon('info')
        . ' Instagram ne permet de publier que depuis un <strong>compte professionnel</strong> (Entreprise ou Créateur).'
        . ' La bascule depuis un compte personnel se fait dans l\'application Instagram — Paramètres → Pour les professionnels —'
        . ' elle est gratuite et réversible, mais rend le compte public.</p>';

    if ($hasInstanceApp) {
        echo '<p class="hint" style="margin-bottom:18px">' . ui_icon('check')
            . ' Cette instance fournit déjà une app Meta : vous n\'avez rien à renseigner ci-dessous, sauf pour utiliser la vôtre.</p>';
    }
    ui_field([
        'label' => 'Instagram App ID' . ($hasInstanceApp ? ' (facultatif)' : ''),
        'name' => 'app_id', 'value' => $settings['app_id'] ?? '',
        'hint' => 'Dans votre app Meta (type <strong>Business</strong>) : Instagram → « API setup with Instagram business login » → Business login settings. Attention, ce n\'est <strong>pas</strong> l\'App ID Facebook.',
    ]);
    ui_field([
        'label' => 'Instagram App Secret' . ($hasInstanceApp ? ' (facultatif)' : ''),
        'name' => 'app_secret', 'type' => 'password',
        'placeholder' => !empty($settings['app_secret']) ? '••••••••  (enregistré — laisser vide pour conserver)' : '',
        'hint' => 'Stocké chiffré (AES-256-GCM). Jamais visible par les personnes avec qui vous partagez.',
        'autocomplete' => 'off',
    ]);
    ui_field([
        'label' => 'Token d\'accès collé à la main (facultatif)', 'name' => 'manual_token', 'type' => 'password',
        'placeholder' => 'Raccourci : évite tout le flux OAuth',
        'hint' => 'Pour publier sur <strong>votre propre</strong> compte, le bouton « Generate token » du tableau de bord Meta délivre directement un token valable 60 jours : collez-le ici et le connecteur est opérationnel, sans OAuth ni revue d\'application.',
        'autocomplete' => 'off',
    ]);
}

/** Enregistre les réglages Instagram soumis. */
function instagram_settings_save(array $config, array $post): string
{
    $patch  = ['app_id' => trim((string) ($post['app_id'] ?? ''))];
    $secret = trim((string) ($post['app_secret'] ?? ''));
    if ($secret !== '') { // vide = conserver l'existant
        $patch['app_secret'] = $secret;
    }
    config_update_settings($config, $patch);

    // Un token collé à la main court-circuite OAuth : on valide immédiatement
    // qu'il fonctionne, plutôt que de laisser l'échec pour le premier outil.
    $manual = trim((string) ($post['manual_token'] ?? ''));
    if ($manual === '') {
        return '';
    }
    try {
        $identity = ig_fetch_identity($manual);
    } catch (RuntimeException $e) {
        return 'En revanche, le token collé n\'a pas été accepté : ' . $e->getMessage();
    }
    config_update_settings(config_get((int) $config['id']), [
        'access_token'      => $manual,
        // Un token « Generate token » vaut 60 jours ; il sera renouvelé
        // automatiquement à l'usage.
        'token_expires_at'  => gmdate('Y-m-d H:i:s', time() + 60 * 86400),
        'token_obtained_at' => now(),
        'granted_scopes'    => implode(',', IG_SCOPES),
        'ig_user_id'        => $identity['ig_user_id'],
        'username'          => $identity['username'],
        'account_type'      => $identity['account_type'],
    ]);
    return 'Token accepté : compte @' . $identity['username'] . ' connecté.';
}

/** Efface la connexion Instagram. */
function instagram_disconnect(array $config): string
{
    config_update_settings($config, [
        'access_token' => null, 'token_expires_at' => null, 'token_obtained_at' => null,
        'ig_user_id' => null, 'username' => null, 'account_type' => null, 'granted_scopes' => null,
    ]);
    return 'Compte Instagram déconnecté de ce connecteur.';
}

/** Teste la connexion Instagram. */
function instagram_test(array $settings): string
{
    if (empty($settings['access_token'])) {
        throw new RuntimeException('Aucun compte Instagram n\'est connecté sur ce connecteur.');
    }
    try {
        $identity = ig_fetch_identity((string) $settings['access_token']);
    } catch (RuntimeException $e) {
        throw new RuntimeException($e->getMessage()
            . ' Reconnectez le compte depuis cette page ; si l\'erreur persiste, vérifiez que le compte est bien professionnel.');
    }
    return 'Connexion opérationnelle — Instagram répond : @' . $identity['username']
        . ' (' . ($identity['account_type'] !== '' ? $identity['account_type'] : 'type inconnu') . ', '
        . ($identity['followers_count'] ?? '?') . ' abonnés).';
}

/** Actions supplémentaires de la page connecteur propres à Instagram. */
function instagram_action(array $config, string $action): ?array
{
    if ($action !== 'media_test') {
        return null;
    }
    $result = media_self_test();
    return [$result['ok'] ? 'ok' : 'error', $result['message'] . ' (' . $result['detail'] . ')'];
}

/** Carte « Connexion Instagram » de la page connecteur (propriétaire). */
function instagram_connect_card(array $config, array $settings, array $summary): void
{
    ui_card_open('Connexion Instagram', '', 2);

    if ($summary['connected']) {
        echo '<div class="rows"><div class="row"><div class="row-main">'
            . '<strong>@' . e($summary['member_name'] ?: 'compte connecté') . '</strong>'
            . '<span>' . ($summary['account_type'] ? e($summary['account_type']) . ' · ' : '')
            . ($summary['expired']
                ? 'Token expiré — reconnectez le compte pour réactiver les outils.'
                : 'Token valable jusqu\'au ' . e(format_date($summary['expires_at'], true))
                  . ' — renouvelé automatiquement tant que le connecteur sert.') . '</span>'
            . '</div><div class="row-actions">';
        ui_post_button(base_url('/connector.php'), ['id' => $config['id'], 'action' => 'test'],
            'Tester la connexion', 'btn btn-ghost btn-sm', '', 'check');
        echo '<a class="btn btn-ghost btn-sm" href="' . e(base_url('/oauth-instagram.php?action=start&id=' . $config['id'])) . '">' . ui_icon('refresh') . 'Reconnecter</a>';
        ui_post_button(base_url('/connector.php'), ['id' => $config['id'], 'action' => 'disconnect'], 'Déconnecter', 'btn btn-danger btn-sm');
        echo '</div></div></div>';
    } else {
        $ready = instagram_app_id($settings) !== '' && instagram_app_secret($settings) !== '';
        echo '<p class="muted" style="margin-bottom:16px">Autorisez l\'application à publier sur votre compte Instagram professionnel.</p>';

        echo '<div class="rows" style="margin-bottom:16px"><div class="row"><div class="row-main">'
            . '<strong><code>' . e(implode(' ', IG_SCOPES)) . '</code></strong>'
            . '<span>Autorisations demandées : lecture du profil et publication de contenu.</span>'
            . '</div></div></div>';

        if ($ready) {
            echo '<a class="btn btn-primary" href="' . e(base_url('/oauth-instagram.php?action=start&id=' . $config['id'])) . '">' . ui_icon('instagram') . 'Connecter Instagram</a>';
        } else {
            echo '<p class="hint">Renseignez d\'abord l\'Instagram App ID et l\'App Secret ci-dessus — ou collez directement un token d\'accès.</p>';
        }
        ui_copy_row('URL de redirection à déclarer dans votre app Meta (Business login settings)', base_url(IG_OAUTH_REDIRECT),
            'Meta ajoute parfois un slash final à l\'URL enregistrée : vérifiez qu\'elle correspond exactement, caractère pour caractère.');
    }

    // Instagram télécharge les images depuis ce serveur : mieux vaut le
    // vérifier ici qu'échouer plus tard sur une erreur générique.
    echo '<div class="rows" style="margin-top:16px"><div class="row"><div class="row-main">'
        . '<strong>Hébergement des images</strong>'
        . '<span>Instagram ne reçoit pas les images : il vient les télécharger sur ce serveur. Ce test vérifie qu\'elles sont bien accessibles publiquement.</span>'
        . '</div><div class="row-actions">';
    ui_post_button(base_url('/connector.php'), ['id' => $config['id'], 'action' => 'media_test'],
        'Tester la publication média', 'btn btn-ghost btn-sm', '', 'shield');
    echo '</div></div></div>';

    ui_card_close();
}
