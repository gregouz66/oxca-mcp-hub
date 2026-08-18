<?php
/**
 * Non-régression du connecteur LinkedIn.
 *
 * Deux refontes l'ont touché sans devoir changer son comportement :
 * l'extraction de son interface derrière les hooks de mcp_types(), et le
 * passage de son client HTTP et de ses garde-fous au socle partagé.
 */

group('LinkedIn — non-régression');

test('le connecteur déclare tous les hooks attendus par la page', function () {
    $type = mcp_type('linkedin');
    foreach (['tools_fn', 'catalog_fn', 'call_fn', 'summary_fn',
        'settings_form_fn', 'settings_save_fn', 'connect_card_fn', 'disconnect_fn', 'test_fn'] as $hook) {
        assert_true(isset($type[$hook]), "hook manquant : $hook");
        assert_true(is_callable($type[$hook]), "hook non appelable : $hook (" . $type[$hook] . ')');
    }
});

test('les deux types de connecteurs déclarent des fonctions qui existent', function () {
    foreach (mcp_types() as $key => $type) {
        foreach ($type as $field => $value) {
            if (str_ends_with($field, '_fn')) {
                assert_true(function_exists($value), "$key.$field pointe vers une fonction absente : $value");
            }
        }
        assert_true(isset($type['label'], $type['tagline'], $type['icon']), "métadonnées incomplètes : $key");
    }
});

test('le catalogue LinkedIn reste complet et cohérent', function () {
    $catalog = linkedin_tool_catalog([]);
    $names   = array_column($catalog, 'name');
    foreach (['linkedin_create_post', 'linkedin_create_document_post', 'linkedin_delete_post',
        'linkedin_comment', 'linkedin_react', 'linkedin_get_profile',
        'linkedin_my_post_stats', 'linkedin_org_share_stats', 'linkedin_org_follower_count'] as $tool) {
        assert_true(in_array($tool, $names, true), "outil disparu du catalogue : $tool");
    }
});

test('les outils LinkedIn exposés dépendent toujours du type d\'app', function () {
    $signin    = array_column(linkedin_tools(['app_type' => 'signin']), 'name');
    $community = array_column(linkedin_tools(['app_type' => 'community', 'org_urn' => 'urn:li:organization:1']), 'name');

    assert_true(in_array('linkedin_create_post', $signin, true));
    assert_false(in_array('linkedin_my_post_stats', $signin, true),
        'les statistiques exigent une app Community Management');
    assert_true(in_array('linkedin_org_follower_count', $community, true));
});

test('les scopes demandés dépendent toujours du type d\'app', function () {
    assert_true(isset(linkedin_scopes(['app_type' => 'signin'])['w_member_social']));
    assert_true(isset(linkedin_scopes(['app_type' => 'community'])['w_organization_social']));
    assert_false(isset(linkedin_scopes(['app_type' => 'signin'])['w_organization_social']));
});

test('l\'enregistrement des réglages garde le comportement d\'avant', function () {
    [$config] = fixture_config('linkedin', ['client_id' => 'CID', 'client_secret' => 'SECRET']);

    // Un secret vide conserve l'existant.
    linkedin_settings_save(config_get((int) $config['id']), ['client_id' => 'CID2', 'client_secret' => '']);
    $settings = config_settings(config_get((int) $config['id']));
    assert_eq('CID2', $settings['client_id']);
    assert_eq('SECRET', $settings['client_secret']);

    // Un identifiant d'organisation numérique est normalisé en URN.
    linkedin_settings_save(config_get((int) $config['id']), ['app_type' => 'community', 'org_urn' => '4242']);
    assert_eq('urn:li:organization:4242', config_settings(config_get((int) $config['id']))['org_urn']);
});

test('changer de type d\'app déconnecte, car le token ne vaut plus rien', function () {
    [$config] = fixture_config('linkedin', [
        'app_type' => 'signin', 'client_id' => 'C', 'access_token' => 'TOK', 'member_urn' => 'urn:li:person:X',
    ]);
    $message = linkedin_settings_save(config_get((int) $config['id']), ['app_type' => 'community']);
    $settings = config_settings(config_get((int) $config['id']));

    assert_false(isset($settings['access_token']), 'le token de l\'ancienne app doit être effacé');
    assert_contains('Le type d\'app a changé', $message);
});

test('la déconnexion LinkedIn efface le token et garde son message', function () {
    [$config] = fixture_config('linkedin', ['client_id' => 'C', 'access_token' => 'TOK', 'member_urn' => 'urn:li:person:X']);
    $message  = linkedin_disconnect(config_get((int) $config['id']));
    $settings = config_settings(config_get((int) $config['id']));

    assert_eq('LinkedIn déconnecté de ce connecteur.', $message);
    assert_false(isset($settings['access_token']));
    assert_eq('C', $settings['client_id']);
});

test('le test de connexion LinkedIn passe toujours par l\'identité', function () {
    assert_throws(fn () => linkedin_test([]), 'n\'est pas connecté', RuntimeException::class);

    http_fake(['GET /v2/userinfo' => [200, [], ['sub' => 'ABC', 'name' => 'Jean Test', 'email' => 'j@example.com']]]);
    $message = linkedin_test(['access_token' => 'TOK']);
    assert_contains('Jean Test', $message);
    assert_contains('urn:li:person:ABC', $message);
    http_real();
});

test('une app Community lit l\'identité sur /v2/me, pas sur userinfo', function () {
    http_fake(['GET /v2/me' => [200, [], ['id' => 'XYZ', 'localizedFirstName' => 'Ada', 'localizedLastName' => 'L']]]);
    $identity = li_fetch_identity(['app_type' => 'community'], 'TOK');
    assert_eq('urn:li:person:XYZ', $identity['urn']);
    assert_eq('Ada L', $identity['name']);
    http_real();
});

group('LinkedIn — socle HTTP partagé');

test('li_http délègue au socle tout en gardant son message d\'erreur', function () {
    http_fake(['GET /v2/userinfo' => [200, ['x-test' => '1'], ['sub' => 'S']]]);
    [$status, $headers, $data] = li_http('GET', 'https://api.linkedin.com/v2/userinfo');
    assert_eq(200, $status);
    assert_eq('1', $headers['x-test']);
    assert_eq('S', $data['sub']);

    // Le socle parle de « Service injoignable » ; LinkedIn garde sa formule.
    http_set_transport(function () {
        throw new McpToolError('Service injoignable : timeout');
    });
    assert_throws(fn () => li_http('GET', 'https://api.linkedin.com/v2/userinfo'),
        'Impossible de joindre LinkedIn', McpToolError::class);
    http_real();
});

test('les garde-fous anti-SSRF protègent toujours le téléchargement', function () {
    foreach (['http://127.0.0.1/x.pdf', 'http://10.0.0.1/x.pdf', 'http://169.254.169.254/x.pdf'] as $url) {
        assert_throws(fn () => li_document_download($url), 'adresse interne', McpToolError::class);
    }
    assert_throws(fn () => li_document_download('ftp://example.com/x.pdf'), 'URL http(s)', McpToolError::class);
    assert_throws(fn () => li_document_download('file:///etc/passwd'), 'URL http(s)', McpToolError::class);
});

test('le contrôle d\'extension des documents est inchangé', function () {
    assert_throws(fn () => li_document_filename('notes.txt'), 'non supporté', McpToolError::class);
    assert_eq('deck.pdf', li_document_filename('deck.pdf'));
    assert_eq('deck.pptx', li_document_filename('/chemin/vers/deck.pptx'));
});

test('le contrôle de taille des documents est inchangé', function () {
    assert_throws(fn () => li_document_check_size(LINKEDIN_DOC_MAX_BYTES + 1), '100 Mo', McpToolError::class);
    li_document_check_size(LINKEDIN_DOC_MAX_BYTES); // ne doit pas lever
});

test('la publication LinkedIn fonctionne toujours de bout en bout', function () {
    http_fake(['POST /rest/posts' => [201, ['x-restli-id' => 'urn:li:share:999'], []]]);
    $result = li_tool_create_post([
        'access_token' => 'TOK', 'member_urn' => 'urn:li:person:X',
    ], ['text' => 'Bonjour le monde']);

    assert_false($result['isError']);
    assert_eq('urn:li:share:999', $result['structuredContent']['post_urn']);
    assert_contains('linkedin.com/feed/update', $result['structuredContent']['post_url']);
    http_real();
});

test('les erreurs LinkedIn gardent leurs conseils d\'origine', function () {
    assert_contains('doit se reconnecter', li_api_error('X', 401, [])->getMessage());
    assert_contains('Quota', li_api_error('X', 429, [])->getMessage());
    assert_contains('doublon', li_api_error('X', 422, [])->getMessage());
});

group('Isolation des deux connecteurs');

test('chaque type chiffre ses propres secrets', function () {
    assert_eq(['client_secret', 'access_token'], config_sensitive_keys('linkedin'));
    assert_eq(['app_secret', 'access_token'], config_sensitive_keys('instagram'));
    assert_eq([], config_sensitive_keys('inconnu'));
});

test('un connecteur d\'un type n\'expose jamais les outils de l\'autre', function () {
    $li = array_column(linkedin_tools(['app_type' => 'signin']), 'name');
    $ig = array_column(instagram_tools(fixture_ig_settings()), 'name');
    foreach ($li as $name) {
        assert_true(str_starts_with($name, 'linkedin_'), "outil LinkedIn mal nommé : $name");
    }
    foreach ($ig as $name) {
        assert_true(str_starts_with($name, 'instagram_'), "outil Instagram mal nommé : $name");
    }
    assert_eq([], array_intersect($li, $ig));
});

test('un type de MCP inconnu est refusé à la création', function () {
    $user = user_create('tests+type' . random_hex(3) . '@example.com');
    assert_throws(fn () => config_create((int) $user['id'], 'tiktok', 'X'),
        'Type de MCP inconnu', InvalidArgumentException::class);
});
