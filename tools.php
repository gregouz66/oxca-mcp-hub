<?php
/**
 * Documentation des outils d'un connecteur : tout ce qu'il expose (et tout ce
 * qu'il pourrait exposer), avec les arguments attendus, les données renvoyées
 * et les erreurs possibles.
 *
 * Deux façons d'y accéder, deux formats :
 *   - connecté à l'espace, via ?id=42        → page HTML
 *   - avec un token d'endpoint, via ?t=oxm_… → page HTML, ou JSON avec
 *     &format=json (ou un en-tête « Accept: application/json »)
 *
 * Le token accepte les trois formes de mcp.php (?t=…, /tools.php/oxm_…,
 * en-tête Authorization). Aucun secret n'est exposé ici : uniquement des
 * métadonnées d'outils et l'état de configuration déjà visible du connecteur.
 */

// Un appel authentifié par token est sans état : pas de cookie de session.
// Le test doit précéder bootstrap.php, qui ouvre la session sinon.
if (isset($_GET['t']) || !empty($_SERVER['PATH_INFO'])
    || !empty($_SERVER['HTTP_AUTHORIZATION']) || !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
    define('OXCA_NO_SESSION', true);
}
require __DIR__ . '/app/bootstrap.php';

/* ------------------------------------------------------ Authentification */

$config = null;
$grant  = null;
$user   = null;

$token = '';
if (isset($_GET['t']) && is_string($_GET['t'])) {
    $token = $_GET['t'];
} elseif (!empty($_SERVER['PATH_INFO'])) {
    $token = trim((string) $_SERVER['PATH_INFO'], '/');
} else {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\s+(\S+)$/i', $authHeader, $m)) {
        $token = $m[1];
    }
}

if ($token !== '') {
    $resolved = grant_by_token($token);
    if ($resolved !== null) {
        [$grant, $config] = $resolved;
    }
} else {
    $user   = require_login();
    $config = config_get((int) ($_GET['id'] ?? 0));
    $grant  = $config ? grant_for((int) $config['id'], (int) $user['id']) : null;
    if (!$grant) {
        $config = null; // pas d'accès : traité comme introuvable
    }
}

/* ------------------------------------------------------------- Format */

$wantsJson = ($_GET['format'] ?? '') === 'json'
    || str_contains(strtolower($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');

if ($config === null) {
    if ($wantsJson) {
        http_response_code($token !== '' ? 401 : 404);
        header('Content-Type: application/json; charset=utf-8');
        exit(json_encode(['error' => $token !== ''
            ? 'Token d\'accès inconnu ou révoqué.'
            : 'Connecteur introuvable ou accès retiré.'], JSON_UNESCAPED_UNICODE));
    }
    http_response_code(404);
    ui_top('Introuvable', $user);
    ui_empty('alert', 'Connecteur introuvable', 'Il a peut-être été supprimé, ou son accès vous a été retiré.',
        '<a class="btn btn-primary" href="' . e(base_url('/dashboard.php')) . '">Retour au tableau de bord</a>');
    ui_bottom();
    exit;
}

$type     = mcp_type($config['type']);
$settings = config_settings($config);
$summary  = ($type['summary_fn'])($settings);
$catalog  = isset($type['catalog_fn'])
    ? ($type['catalog_fn'])($settings)
    // Repli pour un type de MCP qui ne fournit pas de catalogue : les outils
    // exposés sont alors documentés sans les indisponibles ni les erreurs.
    : array_map(
        fn (array $t) => $t + ['available' => true, 'requires' => [], 'errors' => []],
        ($type['tools_fn'])($settings)
    );

/* --------------------------------------------------------------- JSON */

if ($wantsJson) {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Access-Control-Allow-Origin: *');

    echo json_encode([
        'connector' => [
            'name'      => $config['name'],
            'type'      => $config['type'],
            'label'     => $type['label'],
            'app'       => APP_NAME,
            'version'   => APP_VERSION,
            'state'     => [
                'connected' => (bool) $summary['connected'],
                'expired'   => (bool) $summary['expired'],
            ],
        ],
        'mcp' => [
            'endpoint'          => base_url('/mcp.php'),
            'transport'         => 'http',
            'authentication'    => 'En-tête « Authorization: Bearer <token> », ou ?t=<token> dans l\'URL.',
            'protocol_versions' => MCP_PROTOCOL_VERSIONS,
            'methods'           => ['initialize', 'ping', 'tools/list', 'tools/call', 'resources/list', 'resources/templates/list', 'prompts/list'],
        ],
        'tools'         => array_map('tools_json_entry', $catalog),
        'common_errors' => tools_common_errors(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** Normalise une entrée de catalogue pour la sortie JSON. */
function tools_json_entry(array $tool): array
{
    return [
        'name'          => $tool['name'],
        'description'   => $tool['description'],
        'available'     => (bool) ($tool['available'] ?? true),
        'requires'      => array_values($tool['requires'] ?? []),
        'input_schema'  => $tool['inputSchema'] ?? new stdClass(),
        'output_schema' => $tool['outputSchema'] ?? new stdClass(),
        'errors'        => array_values($tool['errors'] ?? []),
    ];
}

/** Erreurs communes à tous les outils d'un connecteur. */
function tools_common_errors(): array
{
    return [
        ['code' => -32601, 'type' => 'jsonrpc', 'message' => 'Method not found', 'when' => 'Méthode MCP inconnue.'],
        ['code' => -32602, 'type' => 'jsonrpc', 'message' => 'Unknown tool', 'when' => 'Outil inexistant ou non exposé par cette configuration.'],
        ['code' => -32001, 'type' => 'http', 'message' => 'Unauthorized', 'when' => 'Token d\'endpoint absent, révoqué ou régénéré (HTTP 401).'],
        ['code' => null, 'type' => 'tool', 'message' => 'LinkedIn n\'est pas connecté sur ce connecteur.', 'when' => 'Le propriétaire n\'a pas encore autorisé LinkedIn.'],
        ['code' => null, 'type' => 'tool', 'message' => 'Le token LinkedIn a expiré (validité 60 jours).', 'when' => 'Reconnexion nécessaire depuis la page du connecteur.'],
        ['code' => null, 'type' => 'tool', 'message' => 'Quota d\'appels LinkedIn atteint (HTTP 429).', 'when' => 'Trop d\'appels sur la période : réessayer plus tard.'],
    ];
}

/* --------------------------------------------------------------- HTML */

/** Rend un schéma JSON comme une liste d'arguments lisible. */
function tools_render_schema(array $schema, string $emptyLabel): void
{
    $properties = $schema['properties'] ?? [];
    if ($properties instanceof stdClass) {
        $properties = (array) $properties;
    }
    if (!is_array($properties) || $properties === []) {
        echo '<p class="hint">' . e($emptyLabel) . '</p>';
        return;
    }
    $required = (array) ($schema['required'] ?? []);

    echo '<div class="rows">';
    foreach ($properties as $name => $spec) {
        $spec = (array) $spec;
        $type = $spec['type'] ?? 'string';
        $type = is_array($type) ? implode(' | ', $type) : (string) $type;
        if (!empty($spec['enum'])) {
            $type .= ' : ' . implode(' | ', array_map('strval', $spec['enum']));
        }
        echo '<div class="row"><div class="row-main">'
            . '<strong><code>' . e((string) $name) . '</code> '
            . (in_array($name, $required, true)
                ? ui_badge('requis', 'accent')
                : ui_badge('facultatif', 'muted'))
            . '</strong>'
            . '<span><code>' . e($type) . '</code>'
            . (!empty($spec['description']) ? ' — ' . e((string) $spec['description']) : '')
            . '</span></div></div>';
    }
    echo '</div>';
}

$exposed = array_filter($catalog, fn ($t) => $t['available']);

ui_top('Outils — ' . $config['name'], $user, true);
ui_page_header(
    'Outils de « ' . $config['name'] . ' »',
    count($exposed) . ' outil' . (count($exposed) > 1 ? 's' : '') . ' exposé'
        . (count($exposed) > 1 ? 's' : '') . ' sur ' . count($catalog) . ' au catalogue '
        . $type['label'] . '. Arguments, données renvoyées et erreurs possibles.',
    $user ? '<a class="btn btn-ghost btn-sm" href="' . e(base_url('/connector.php?id=' . $config['id'])) . '">'
        . ui_icon('arrow') . 'Retour au connecteur</a>' : ''
);

/* ---- Accès programmatique -------------------------------------------- */
// Cette page a vocation à être partagée (à un développeur, à un agent, en PDF) :
// par défaut elle ne montre donc AUCUN token, seulement des exemples en
// « oxm_… ». Le vrai token reste accessible, mais derrière un dépliant, pour
// qu'un partage ou une capture d'écran ne l'emporte pas par inadvertance.
ui_card_open('Cette page en JSON', 'Même contenu, exploitable par un script ou un agent.', 1);
ui_code_block('En ligne de commande', 'curl -s -H "Authorization: Bearer oxm_VOTRE_TOKEN" \\'
    . "\n  \"" . base_url('/tools.php') . '?format=json"');
ui_code_block('Lister les outils via le protocole MCP', 'curl -s -X POST "' . base_url('/mcp.php') . '" \\'
    . "\n  -H \"Authorization: Bearer oxm_VOTRE_TOKEN\" \\"
    . "\n  -H \"Content-Type: application/json\" \\"
    . "\n  -d '" . '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' . "'");
echo '<p class="hint">' . ui_icon('shield')
    . ' Cette page ne contient aucun secret : vous pouvez la partager ou l\'exporter telle quelle.'
    . ' Le JSON non plus — il ne renvoie que des métadonnées d\'outils.</p>';
echo '<details class="disclosure"><summary>Afficher mon token d\'accès</summary>';
ui_copy_row('Token d\'accès', grant_token($grant),
    'À coller dans l\'en-tête <code>Authorization</code> à la place de <code>oxm_VOTRE_TOKEN</code>.'
    . ' Il vaut authentification : ne le partagez pas, et régénérez-le depuis la page du connecteur au moindre doute.');
echo '</details>';
ui_card_close();

/* ---- Les outils ------------------------------------------------------- */
$delay = 2;
foreach ($catalog as $tool) {
    $available = (bool) $tool['available'];
    ui_card_open('', '', $delay++);
    echo '<div class="card-link-head" style="margin-bottom:10px">'
        . '<h2 style="margin:0"><code>' . e($tool['name']) . '</code></h2>'
        . ($available ? ui_badge('Disponible', 'ok') : ui_badge('Non exposé', 'warn'))
        . '</div>';
    echo '<p class="muted">' . e($tool['description']) . '</p>';

    // Note permanente : surtout pas un « flash », que app.js efface au bout
    // de quelques secondes — cette information doit rester lisible.
    if (!$available && ($tool['requires'] ?? []) !== []) {
        echo '<div class="field" style="margin-top:18px"><span class="field-label">Pour l\'activer</span></div>'
            . '<div class="rows">';
        foreach ($tool['requires'] as $requirement) {
            echo '<div class="row"><div class="row-main"><span>' . ui_icon('info') . ' ' . e($requirement) . '</span></div></div>';
        }
        echo '</div>';
    }

    echo '<div class="field" style="margin-top:18px"><span class="field-label">Arguments</span></div>';
    tools_render_schema((array) ($tool['inputSchema'] ?? []), 'Aucun argument.');

    echo '<div class="field" style="margin-top:18px"><span class="field-label">Données renvoyées (structuredContent)</span></div>';
    tools_render_schema((array) ($tool['outputSchema'] ?? []), 'Réponse textuelle uniquement.');

    if (($tool['errors'] ?? []) !== []) {
        echo '<details class="disclosure" style="margin-top:18px"><summary>Erreurs possibles ('
            . count($tool['errors']) . ')</summary><ul class="prose">';
        foreach ($tool['errors'] as $error) {
            echo '<li>' . e($error) . '</li>';
        }
        echo '</ul></details>';
    }
    ui_card_close();
}

/* ---- Erreurs communes ------------------------------------------------- */
ui_card_open('Erreurs communes à tous les outils', 'Renvoyées quel que soit l\'outil appelé.', $delay);
echo '<div class="rows">';
foreach (tools_common_errors() as $error) {
    echo '<div class="row"><div class="row-main">'
        . '<strong>' . e($error['message']) . ($error['code'] !== null ? ' <code>' . (int) $error['code'] . '</code>' : '') . '</strong>'
        . '<span>' . e($error['when']) . '</span></div></div>';
}
echo '</div>';
ui_card_close();

ui_bottom();
