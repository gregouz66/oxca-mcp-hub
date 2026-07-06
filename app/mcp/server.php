<?php
/**
 * Serveur MCP (Model Context Protocol) — transport « Streamable HTTP ».
 *
 * Implémentation volontairement sans état, adaptée à l'hébergement mutualisé :
 * chaque requête POST contient un message JSON-RPC 2.0 et reçoit une réponse
 * application/json. Compatible avec les connecteurs personnalisés de
 * Claude Code (`claude mcp add --transport http …`) et de claude.ai.
 *
 * Spécification : https://modelcontextprotocol.io/specification
 */

const MCP_PROTOCOL_VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];

/** Exception « erreur de protocole » convertie en erreur JSON-RPC. */
class McpError extends RuntimeException
{
    public function __construct(public readonly int $rpcCode, string $message)
    {
        parent::__construct($message);
    }
}

/**
 * Exception « erreur d'exécution d'un outil » : renvoyée dans le résultat
 * (isError = true) et non comme erreur JSON-RPC, conformément à la spec.
 */
class McpToolError extends RuntimeException
{
}

/**
 * Traite le corps d'une requête POST (message unique ou batch).
 * Retourne [statusHttp, corpsJson|null].
 */
function mcp_handle_body(string $rawBody, array $config, array $grant): array
{
    $decoded = json_decode($rawBody, true);
    if (!is_array($decoded)) {
        return [400, mcp_rpc_error(null, -32700, 'Parse error: invalid JSON')];
    }

    $isBatch  = array_is_list($decoded) && $decoded !== [];
    $messages = $isBatch ? $decoded : [$decoded];
    $replies  = [];

    foreach ($messages as $message) {
        if (!is_array($message)) {
            $replies[] = mcp_rpc_error(null, -32600, 'Invalid request');
            continue;
        }
        $reply = mcp_handle_message($message, $config, $grant);
        if ($reply !== null) {
            $replies[] = $reply;
        }
    }

    if ($replies === []) {
        return [202, null]; // uniquement des notifications
    }
    return [200, $isBatch ? $replies : $replies[0]];
}

/** Traite un message JSON-RPC. Retourne la réponse, ou null pour une notification. */
function mcp_handle_message(array $message, array $config, array $grant): ?array
{
    $id     = $message['id'] ?? null;
    $method = $message['method'] ?? null;
    $params = is_array($message['params'] ?? null) ? $message['params'] : [];

    if (($message['jsonrpc'] ?? '') !== '2.0' || !is_string($method)) {
        return mcp_rpc_error($id, -32600, 'Invalid request');
    }

    // Notifications : pas de réponse.
    if (!array_key_exists('id', $message)) {
        return null;
    }

    try {
        $result = mcp_dispatch($method, $params, $config, $grant);
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    } catch (McpError $e) {
        return mcp_rpc_error($id, $e->rpcCode, $e->getMessage());
    } catch (Throwable $e) {
        error_log('MCP: ' . $e->getMessage());
        return mcp_rpc_error($id, -32603, 'Internal error');
    }
}

/** Routage des méthodes MCP. */
function mcp_dispatch(string $method, array $params, array $config, array $grant): array|stdClass
{
    $type = mcp_type($config['type']);

    switch ($method) {
        case 'initialize':
            $requested = (string) ($params['protocolVersion'] ?? '');
            return [
                'protocolVersion' => in_array($requested, MCP_PROTOCOL_VERSIONS, true)
                    ? $requested
                    : MCP_PROTOCOL_VERSIONS[0],
                'capabilities' => ['tools' => new stdClass()],
                'serverInfo'   => [
                    'name'    => $config['name'],
                    'title'   => $config['name'] . ' — ' . APP_NAME,
                    'version' => APP_VERSION,
                ],
                'instructions' => sprintf(
                    'Connecteur %s « %s » fourni par %s. Utilisez tools/list pour découvrir les outils disponibles.',
                    $type['label'],
                    $config['name'],
                    APP_NAME
                ),
            ];

        case 'ping':
            return new stdClass(); // objet vide en JSON ({}), pas un tableau

        case 'tools/list':
            return ['tools' => ($type['tools_fn'])(config_settings($config))];

        case 'tools/call':
            $name = $params['name'] ?? null;
            $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
            if (!is_string($name) || $name === '') {
                throw new McpError(-32602, 'Missing tool name');
            }
            try {
                return ($type['call_fn'])($config, $name, $args);
            } catch (McpToolError $e) {
                return [
                    'content' => [['type' => 'text', 'text' => $e->getMessage()]],
                    'isError' => true,
                ];
            }

        // Capacités non proposées : listes vides plutôt qu'une erreur, certains
        // clients les interrogent sans vérifier les capacités annoncées.
        case 'resources/list':
            return ['resources' => []];
        case 'resources/templates/list':
            return ['resourceTemplates' => []];
        case 'prompts/list':
            return ['prompts' => []];

        default:
            throw new McpError(-32601, 'Method not found: ' . $method);
    }
}

/** Construit une réponse d'erreur JSON-RPC. */
function mcp_rpc_error(mixed $id, int $code, string $message): array
{
    return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
}

/** Résultat d'outil : bloc texte + données structurées optionnelles. */
function mcp_tool_result(string $text, ?array $structured = null): array
{
    $result = ['content' => [['type' => 'text', 'text' => $text]], 'isError' => false];
    if ($structured !== null) {
        $result['structuredContent'] = $structured;
    }
    return $result;
}
