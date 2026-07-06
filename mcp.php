<?php
/**
 * Endpoint MCP public (transport Streamable HTTP, sans état).
 *
 * Authentification par token d'endpoint, accepté sous trois formes :
 *   - .../mcp.php?t=oxm_…                (recommandé, fonctionne partout)
 *   - .../mcp.php/oxm_…                  (PATH_INFO)
 *   - en-tête « Authorization: Bearer oxm_… »
 *
 * Chaque token correspond à un grant (propriétaire ou partage) : révoquer
 * le grant ou régénérer le token invalide immédiatement l'URL.
 */

define('OXCA_NO_SESSION', true);
require __DIR__ . '/app/bootstrap.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Mcp-Session-Id, Mcp-Protocol-Version');
header('X-Content-Type-Options: nosniff');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if ($method !== 'POST') {
    // Pas de flux SSE initié par le serveur ni de session à clore : un
    // serveur sans état peut répondre 405 aux GET/DELETE (spec MCP).
    header('Allow: POST, OPTIONS');
    mcp_output(405, mcp_rpc_error(null, -32000, 'Method not allowed: this MCP server is stateless, use POST.'));
}

/* ------------------------------------------------------ Authentification */
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

$resolved = $token !== '' ? grant_by_token($token) : null;
if ($resolved === null) {
    mcp_output(401, mcp_rpc_error(null, -32001, 'Unauthorized: missing or revoked endpoint token.'));
}
[$grant, $config] = $resolved;

/* ------------------------------------------------------------ Traitement */
[$status, $body] = mcp_handle_body((string) file_get_contents('php://input'), $config, $grant);
mcp_output($status, $body);

/** Émet la réponse JSON (ou vide pour 202) puis termine. */
function mcp_output(int $status, ?array $body): never
{
    http_response_code($status);
    if ($body !== null) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    exit;
}
