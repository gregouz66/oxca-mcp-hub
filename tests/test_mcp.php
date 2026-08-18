<?php
/** Protocole MCP : le connecteur Instagram vu par un client. */

group('MCP — protocole');

/** Envoie un message JSON-RPC au serveur MCP et retourne [status, réponse]. */
function mcp_send(array $message, array $config, array $grant): array
{
    [$status, $body] = mcp_handle_body(json_encode($message), $config, $grant);
    return [$status, $body];
}

test('initialize annonce le connecteur et la capacité outils', function () {
    [$config, $grant] = fixture_config('instagram', fixture_ig_settings());
    [$status, $reply] = mcp_send([
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
        'params'  => ['protocolVersion' => '2025-06-18'],
    ], $config, $grant);

    assert_eq(200, $status);
    assert_eq('2025-06-18', $reply['result']['protocolVersion']);
    assert_true(isset($reply['result']['capabilities']['tools']));
    assert_contains('Instagram', $reply['result']['instructions']);
});

test('une version de protocole inconnue retombe sur celle du serveur', function () {
    [$config, $grant] = fixture_config('instagram');
    [, $reply] = mcp_send([
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
        'params'  => ['protocolVersion' => '1999-01-01'],
    ], $config, $grant);
    assert_eq(MCP_PROTOCOL_VERSIONS[0], $reply['result']['protocolVersion']);
});

test('tools/list expose les outils Instagram avec leurs schémas', function () {
    [$config, $grant] = fixture_config('instagram', fixture_ig_settings());
    [, $reply] = mcp_send(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], $config, $grant);

    $names = array_column($reply['result']['tools'], 'name');
    assert_true(in_array('instagram_publish_image', $names, true));
    assert_true(in_array('instagram_publish_carousel', $names, true));
    // Aucun champ interne du catalogue ne doit fuiter dans la réponse MCP.
    foreach ($reply['result']['tools'] as $tool) {
        assert_false(isset($tool['available']), 'champ interne « available » exposé');
        assert_false(isset($tool['requires']), 'champ interne « requires » exposé');
        assert_false(isset($tool['errors']), 'champ interne « errors » exposé');
    }
});

test('tools/call exécute un outil et renvoie du contenu structuré', function () {
    [$config, $grant] = fixture_config('instagram', fixture_ig_settings());
    http_fake(['GET /content_publishing_limit' => [200, [], [
        'data' => [['quota_usage' => 7, 'config' => ['quota_total' => 50]]],
    ]]]);

    [, $reply] = mcp_send([
        'jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call',
        'params'  => ['name' => 'instagram_publishing_limit', 'arguments' => []],
    ], $config, $grant);

    assert_false($reply['result']['isError']);
    assert_eq(43, $reply['result']['structuredContent']['remaining']);
    assert_contains('7 sur 50', $reply['result']['content'][0]['text']);
    http_real();
});

test('une erreur métier revient en isError, pas en erreur JSON-RPC', function () {
    [$config, $grant] = fixture_config('instagram', fixture_ig_settings());
    [, $reply] = mcp_send([
        'jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call',
        'params'  => ['name' => 'instagram_publish_image', 'arguments' => ['caption' => 'sans image']],
    ], $config, $grant);

    // La spec MCP demande que l'échec d'un outil soit un résultat, pas une
    // erreur de protocole : le modèle doit pouvoir lire le message et corriger.
    assert_false(isset($reply['error']), 'ne doit pas être une erreur JSON-RPC');
    assert_true($reply['result']['isError']);
    assert_contains('Aucune image fournie', $reply['result']['content'][0]['text']);
});

test('un outil inconnu est une erreur de protocole', function () {
    [$config, $grant] = fixture_config('instagram', fixture_ig_settings());
    [, $reply] = mcp_send([
        'jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call',
        'params'  => ['name' => 'instagram_inexistant', 'arguments' => []],
    ], $config, $grant);
    assert_eq(-32602, $reply['error']['code']);
});

test('une notification ne reçoit pas de réponse', function () {
    [$config, $grant] = fixture_config('instagram');
    [$status, $body] = mcp_handle_body(
        json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']),
        $config,
        $grant
    );
    assert_eq(202, $status);
    assert_eq(null, $body);
});

test('un lot de messages reçoit un lot de réponses', function () {
    [$config, $grant] = fixture_config('instagram', fixture_ig_settings());
    [$status, $replies] = mcp_handle_body(json_encode([
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
        ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'],
    ]), $config, $grant);

    assert_eq(200, $status);
    assert_eq(2, count($replies));
    assert_eq(1, $replies[0]['id']);
    assert_true(isset($replies[1]['result']['tools']));
});

test('un JSON invalide donne une erreur de parsing', function () {
    [$config, $grant] = fixture_config('instagram');
    [$status, $reply] = mcp_handle_body('{ pas du json', $config, $grant);
    assert_eq(400, $status);
    assert_eq(-32700, $reply['error']['code']);
});

test('les capacités non proposées répondent des listes vides', function () {
    [$config, $grant] = fixture_config('instagram');
    foreach ([['resources/list', 'resources'], ['prompts/list', 'prompts']] as [$method, $key]) {
        [, $reply] = mcp_send(['jsonrpc' => '2.0', 'id' => 9, 'method' => $method], $config, $grant);
        assert_eq([], $reply['result'][$key], "$method doit répondre une liste vide");
    }
});

test('un token d\'endpoint révoqué ne résout plus rien', function () {
    [$config, $grant, $token] = fixture_config('instagram', fixture_ig_settings());
    assert_true(grant_by_token($token) !== null, 'le token doit fonctionner avant révocation');

    grant_delete((int) $grant['id']);
    assert_eq(null, grant_by_token($token), 'le token doit être inutilisable après révocation');
});

test('un token régénéré invalide immédiatement l\'ancien', function () {
    [$config, $grant, $old] = fixture_config('instagram', fixture_ig_settings());
    $new = grant_regenerate($grant);
    assert_eq(null, grant_by_token($old), 'l\'ancien token doit cesser de fonctionner');
    assert_true(grant_by_token($new) !== null, 'le nouveau token doit fonctionner');
});

test('un token mal formé est rejeté sans requête en base', function () {
    assert_eq(null, grant_by_token('pas-un-token'));
    assert_eq(null, grant_by_token('oxm_' . str_repeat('z', 48)));
    assert_eq(null, grant_by_token(''));
});
