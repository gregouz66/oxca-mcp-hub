<?php
/**
 * Connecteur Instagram : validation des arguments, flux de publication,
 * traduction des erreurs de l'API et cycle de vie du token.
 *
 * Aucun appel réseau : http_fake() simule l'API Graph.
 */

group('Instagram — catalogue et exposition des outils');

test('sans connexion, aucun outil n\'est exposé mais tout est documenté', function () {
    $catalog = instagram_tool_catalog([]);
    assert_eq([], instagram_tools([]), 'aucun outil ne doit être exposé hors connexion');
    assert_true(count($catalog) >= 6, 'le catalogue doit rester complet pour la documentation');
    foreach ($catalog as $tool) {
        assert_true($tool['requires'] !== [], 'outil sans condition affichée : ' . $tool['name']);
    }
});

test('une fois connecté, les outils de publication sont exposés', function () {
    $names = array_column(instagram_tools(fixture_ig_settings()), 'name');
    assert_true(in_array('instagram_publish_image', $names, true));
    assert_true(in_array('instagram_publish_carousel', $names, true));
    assert_true(in_array('instagram_publish_container', $names, true));
    assert_true(in_array('instagram_publishing_limit', $names, true));
});

test('la suppression reste documentée mais jamais exposée', function () {
    $catalog = instagram_tool_catalog(fixture_ig_settings());
    $delete  = null;
    foreach ($catalog as $tool) {
        if ($tool['name'] === 'instagram_delete_media') {
            $delete = $tool;
        }
    }
    assert_true($delete !== null, 'instagram_delete_media doit figurer au catalogue');
    assert_false($delete['available'], 'Instagram Login ne permet pas la suppression');
    assert_contains('Facebook Login', $delete['requires'][0]);
    assert_false(in_array('instagram_delete_media', array_column(instagram_tools(fixture_ig_settings()), 'name'), true));
});

test('chaque outil exposé a un schéma d\'entrée exploitable', function () {
    foreach (instagram_tools(fixture_ig_settings()) as $tool) {
        assert_true(isset($tool['description']) && $tool['description'] !== '', 'description manquante : ' . $tool['name']);
        assert_eq('object', $tool['inputSchema']['type'], 'schéma invalide : ' . $tool['name']);
        assert_true(isset($tool['inputSchema']['properties']), 'propriétés manquantes : ' . $tool['name']);
    }
});

group('Instagram — validation des arguments');

test('une légende trop longue est refusée avant tout appel', function () {
    assert_throws(fn () => ig_check_caption(str_repeat('a', 2201)), 'pour un maximum de 2200', McpToolError::class);
    assert_eq(2200, strlen(ig_check_caption(str_repeat('a', 2200))));
});

test('les hashtags et mentions sont comptés', function () {
    $tooManyTags = implode(' ', array_map(fn ($i) => "#tag$i", range(1, 31)));
    assert_throws(fn () => ig_check_caption($tooManyTags), '31 hashtags', McpToolError::class);

    $tooManyMentions = implode(' ', array_map(fn ($i) => "@user$i", range(1, 21)));
    assert_throws(fn () => ig_check_caption($tooManyMentions), '21 mentions', McpToolError::class);

    // Une légende normale passe, emojis et retours à la ligne compris.
    $ok = ig_check_caption("Bonjour 👋\nUn test #photo #paris avec @ami");
    assert_contains('👋', $ok);
});

test('les accents comptent pour un caractère, pas pour deux octets', function () {
    // 2200 « é » = 4400 octets : compter en octets refuserait à tort.
    $caption = str_repeat('é', 2200);
    assert_eq($caption, ig_check_caption($caption), 'la longueur doit être comptée en caractères');
    assert_throws(fn () => ig_check_caption(str_repeat('é', 2201)), '2201 caractères', McpToolError::class);
});

test('un texte alternatif trop long est refusé', function () {
    assert_throws(fn () => ig_check_alt_text(str_repeat('a', 1001)), 'maximum de 1000', McpToolError::class);
});

test('identifier quelqu\'un sans position est refusé, Instagram l\'exige', function () {
    assert_throws(fn () => ig_build_user_tags(['user_tags' => [['username' => 'ami']]]),
        'position', McpToolError::class);
    assert_throws(fn () => ig_build_user_tags(['user_tags' => [['username' => 'ami', 'x' => 1.5, 'y' => 0.5]]]),
        'entre 0.0 et 1.0', McpToolError::class);

    $json = ig_build_user_tags(['user_tags' => [['username' => '@ami', 'x' => 0.5, 'y' => 0.25]]]);
    assert_eq([['username' => 'ami', 'x' => 0.5, 'y' => 0.25]], json_decode($json, true),
        'le @ doit être retiré et la position conservée');
});

test('plus de trois co-auteurs est refusé', function () {
    $params = [];
    assert_throws(fn () => ig_apply_common_params($params, ['collaborators' => ['a', 'b', 'c', 'd']]),
        'maximum 3 co-auteurs', McpToolError::class);

    $params = [];
    ig_apply_common_params($params, ['collaborators' => ['@a', 'b']]);
    assert_eq(['a', 'b'], json_decode($params['collaborators'], true));
});

test('un carrousel hors bornes est refusé avec le compte exact', function () {
    $settings = fixture_ig_settings();
    assert_throws(fn () => ig_tool_publish_carousel($settings, ['items' => [['image_url' => 'https://x/1.jpg']]]),
        'entre 2 et 10', McpToolError::class);

    $eleven = array_fill(0, 11, ['image_url' => 'https://example.com/a.jpg']);
    assert_throws(fn () => ig_tool_publish_carousel($settings, ['items' => $eleven]),
        '11', McpToolError::class);
});

test('publier sans image est refusé avec une consigne claire', function () {
    assert_throws(fn () => ig_tool_publish_image(fixture_ig_settings(), ['caption' => 'coucou']),
        'Aucune image fournie', McpToolError::class);
    assert_throws(
        fn () => ig_resolve_image(fixture_ig_settings(), ['image_url' => 'https://a/b.jpg', 'image_base64' => 'eHg='], 'image', 'reject', 'auto'),
        'pas les deux',
        McpToolError::class
    );
});

test('un connecteur non connecté explique quoi faire', function () {
    assert_throws(fn () => ig_tool_publish_image([], []), 'n\'est pas connecté', McpToolError::class);
    assert_throws(
        fn () => ig_tool_publish_image(fixture_ig_settings(['token_expires_at' => gmdate('Y-m-d H:i:s', time() - 10)]), []),
        'a expiré',
        McpToolError::class
    );
});

group('Instagram — publication d\'une image');

test('le flux complet enchaîne conteneur, statut puis publication', function () {
    http_fake([
        'POST /media'         => [200, [], ['id' => 'CONTAINER1']],
        'GET  /CONTAINER1'    => [200, [], ['status_code' => 'FINISHED']],
        'POST /media_publish' => [200, [], ['id' => 'MEDIA9']],
        'GET  /MEDIA9'        => [200, [], ['permalink' => 'https://www.instagram.com/p/ABC/']],
    ]);

    $result = ig_tool_publish_image(fixture_ig_settings(), [
        'image_base64' => base64_encode(fixture_jpeg(1080, 1080)),
        'caption'      => 'Un test #photo',
        'alt_text'     => 'Un carré coloré',
    ]);

    assert_false($result['isError']);
    assert_eq('MEDIA9', $result['structuredContent']['media_id']);
    assert_eq('https://www.instagram.com/p/ABC/', $result['structuredContent']['permalink']);
    assert_contains('@compte_test', $result['content'][0]['text']);

    $calls = http_calls();
    assert_eq(4, count($calls), 'quatre appels attendus');
    // Le conteneur reçoit bien la légende, le texte alternatif et une URL de média.
    parse_str((string) $calls[0]['body'], $params);
    assert_eq('Un test #photo', $params['caption']);
    assert_eq('Un carré coloré', $params['alt_text']);
    assert_contains('/media.php?k=', $params['image_url']);
    // La publication référence le conteneur créé.
    parse_str((string) $calls[2]['body'], $publish);
    assert_eq('CONTAINER1', $publish['creation_id']);
    http_real();
});

test('stage=never transmet l\'URL publique sans la toucher ni la télécharger', function () {
    $url = 'https://cdn.example.com/photo.jpg';
    $ok  = ig_resolve_image(fixture_ig_settings(), ['image_url' => $url], 'image', 'reject', 'never');
    assert_eq($url, $ok['url'], 'l\'URL doit être transmise telle quelle');
    assert_eq(null, $ok['key'], 'aucun dépôt ne doit être créé');
    assert_eq([], $ok['notes']);
});

test('une image mise en scène est servie par une URL de ce hub', function () {
    $staged = ig_stage_bytes(fixture_png(1080, 1080), 'image', 'reject');
    assert_contains(base_url('/media.php?k='), $staged['url']);
    assert_true(media_key_valid((string) $staged['key']));
    // Les octets déposés sont bien le JPEG converti, prêt pour Instagram.
    assert_eq('image/jpeg', media_meta($staged['key'])['mime']);
    assert_contains('convertie de image/png', implode(' ', $staged['notes']));
    media_forget($staged['key']);
});

test('une URL non ASCII est refusée en amont, Instagram la rejetterait', function () {
    assert_throws(
        fn () => ig_resolve_image(fixture_ig_settings(), ['image_url' => 'https://example.com/été.jpg'], 'image', 'reject', 'never'),
        'non ASCII',
        McpToolError::class
    );
});

test('une URL interne est refusée (anti-SSRF)', function () {
    foreach (['http://127.0.0.1/a.jpg', 'http://192.168.1.4/a.jpg', 'http://[::1]/a.jpg'] as $url) {
        assert_throws(
            fn () => ig_resolve_image(fixture_ig_settings(), ['image_url' => $url], 'image', 'reject', 'never'),
            'adresse interne',
            McpToolError::class
        );
    }
    assert_throws(
        fn () => ig_resolve_image(fixture_ig_settings(), ['image_url' => 'file:///etc/passwd'], 'image', 'reject', 'never'),
        'URL http(s) complète',
        McpToolError::class
    );
});

group('Instagram — publication d\'un carrousel');

test('le flux crée les enfants, attend chacun, puis le parent', function () {
    $created = 0;
    http_fake_fn(function (string $method, string $url, array $h, ?string $body) use (&$created) {
        $params = http_fake_params($url, $body);
        if ($method === 'POST' && str_contains($url, '/media_publish')) {
            return [200, [], ['id' => 'MEDIA_CAROUSEL']];
        }
        if ($method === 'POST' && str_contains($url, '/media')) {
            $created++;
            return [200, [], ['id' => isset($params['children']) ? 'PARENT' : 'CHILD' . $created]];
        }
        if ($method === 'GET' && str_contains($url, 'permalink')) {
            return [200, [], ['permalink' => 'https://www.instagram.com/p/CAR/']];
        }
        return [200, [], ['status_code' => 'FINISHED']];
    });

    $items = [];
    foreach ([[1080, 1080], [1080, 1350], [1080, 566]] as [$w, $h]) {
        $items[] = ['image_base64' => base64_encode(fixture_jpeg($w, $h)), 'alt_text' => "image {$w}×{$h}"];
    }
    $result = ig_tool_publish_carousel(fixture_ig_settings(), ['items' => $items, 'caption' => 'Mon carrousel']);

    assert_false($result['isError']);
    assert_eq('MEDIA_CAROUSEL', $result['structuredContent']['media_id']);
    assert_contains('Carrousel de 3 images', $result['content'][0]['text']);

    $posts = array_values(array_filter(http_calls(), fn ($c) => $c['method'] === 'POST'));
    assert_eq(5, count($posts), '3 enfants + 1 parent + 1 publication');

    // Les enfants portent is_carousel_item et leur texte alternatif, jamais la légende.
    for ($i = 0; $i < 3; $i++) {
        parse_str((string) $posts[$i]['body'], $child);
        assert_eq('true', $child['is_carousel_item'], "l'enfant $i doit être marqué");
        assert_contains('image ', $child['alt_text']);
        assert_false(isset($child['caption']), 'une légende sur un enfant serait ignorée en silence par Instagram');
    }
    // Le parent porte le type, l'ordre des enfants et la légende.
    parse_str((string) $posts[3]['body'], $parent);
    assert_eq('CAROUSEL', $parent['media_type']);
    assert_eq('CHILD1,CHILD2,CHILD3', $parent['children'], 'l\'ordre fourni doit être conservé');
    assert_eq('Mon carrousel', $parent['caption']);
    http_real();
});

test('une reprise réutilise les enfants déjà prêts sans renvoyer les images', function () {
    http_fake([
        'POST /media_publish' => [200, [], ['id' => 'MEDIA_REPRISE']],
        'POST /media'         => [200, [], ['id' => 'PARENT2']],
        'GET  /'              => [200, [], ['status_code' => 'FINISHED', 'permalink' => '']],
    ]);
    $result = ig_tool_publish_carousel(fixture_ig_settings(), [
        'children' => ['C1', 'C2'],
        'caption'  => 'Reprise',
    ]);
    assert_eq('MEDIA_REPRISE', $result['structuredContent']['media_id']);

    $posts = array_values(array_filter(http_calls(), fn ($c) => $c['method'] === 'POST'));
    assert_eq(2, count($posts), 'aucun enfant ne doit être recréé');
    parse_str((string) $posts[0]['body'], $parent);
    assert_eq('C1,C2', $parent['children']);
    http_real();
});

group('Instagram — interruptions et reprise');

test('un conteneur toujours en cours renvoie de quoi reprendre', function () {
    http_fake([
        'POST /media' => [200, [], ['id' => 'SLOW1']],
        'GET  /SLOW1' => [200, [], ['status_code' => 'IN_PROGRESS']],
    ]);
    $settings = fixture_ig_settings();
    $e = assert_throws(
        // Budget déjà épuisé : on n'attend pas réellement pendant le test.
        fn () => ig_container_await($settings, 'SLOW1', time() - 1, ['SLOW1']),
        'instagram_publish_container',
        McpToolError::class
    );
    assert_contains('creation_id=SLOW1', $e->getMessage());
    assert_contains('Rien n\'est perdu', $e->getMessage());
    http_real();
});

test('un carrousel interrompu renvoie les identifiants des enfants', function () {
    http_fake(['GET /' => [200, [], ['status_code' => 'IN_PROGRESS']]]);
    $e = assert_throws(
        fn () => ig_container_await(fixture_ig_settings(), 'C1', time() - 1, ['C1', 'C2'], true),
        'children=C1,C2',
        McpToolError::class
    );
    assert_contains('instagram_publish_carousel', $e->getMessage());
    http_real();
});

test('un conteneur en erreur remonte le détail porté par « status »', function () {
    http_fake(['GET /BAD' => [200, [], [
        'status_code' => 'ERROR',
        'status'      => 'Error: Media download failed (subcode 2207052)',
    ]]]);
    assert_throws(
        fn () => ig_container_await(fixture_ig_settings(), 'BAD', time() + 10, ['BAD']),
        'Media download failed',
        McpToolError::class
    );
    http_real();
});

test('un conteneur expiré le dit franchement', function () {
    http_fake(['GET /OLD' => [200, [], ['status_code' => 'EXPIRED']]]);
    assert_throws(
        fn () => ig_container_await(fixture_ig_settings(), 'OLD', time() + 10, ['OLD']),
        '24 heures',
        McpToolError::class
    );
    http_real();
});

group('Instagram — traduction des erreurs de l\'API');

test('chaque sous-code connu produit un conseil actionnable', function () {
    $cases = [
        2207052 => 'sans redirection',
        2207004 => '8 Mo',
        2207005 => 'JPEG',
        2207009 => 'fit',
        2207042 => 'Quota de publication atteint',
        2207050 => 'restreint',
        2207027 => 'pas encore prêt',
        2207028 => 'entre 2 et 10',
    ];
    foreach ($cases as $subcode => $needle) {
        $error = ig_api_error('Test', 400, ['error' => [
            'message' => 'msg', 'code' => 100, 'error_subcode' => $subcode,
        ]]);
        assert_contains($needle, $error->getMessage(), "sous-code $subcode");
    }
});

test('un token invalidé oriente vers la reconnexion', function () {
    foreach ([460, 458] as $subcode) {
        $error = ig_api_error('Test', 400, ['error' => ['message' => 'x', 'code' => 190, 'error_subcode' => $subcode]]);
        assert_contains('reconnecter', $error->getMessage());
    }
    $expired = ig_api_error('Test', 400, ['error' => ['message' => 'x', 'code' => 190]]);
    assert_contains('expiré ou révoqué', $expired->getMessage());
});

test('le message d\'erreur d\'Instagram est repris tel quel', function () {
    $error = ig_api_error('Publication refusée', 400, ['error' => [
        'message' => 'Invalid parameter', 'code' => 100, 'error_subcode' => 2207009,
    ]]);
    assert_contains('Publication refusée (HTTP 400)', $error->getMessage());
    assert_contains('Invalid parameter', $error->getMessage());
});

group('Instagram — OAuth et cycle de vie du token');

test('l\'URL d\'autorisation porte les bons scopes et la redirection', function () {
    $url = instagram_oauth_url(['app_id' => 'APP1'], 'lestate');
    assert_contains('https://www.instagram.com/oauth/authorize?', $url);
    assert_contains('client_id=APP1', $url);
    assert_contains('instagram_business_basic', $url);
    assert_contains('instagram_business_content_publish', $url);
    assert_contains('state=lestate', $url);
    assert_contains(rawurlencode(base_url('/oauth-instagram.php')), $url);
});

test('le suffixe #_ du code est retiré et la réponse enveloppée est lue', function () {
    $seen = [];
    http_set_transport(function (string $method, string $url, array $h, ?string $body) use (&$seen) {
        $params = http_fake_params($url, $body);
        if (str_contains($url, 'api.instagram.com/oauth/access_token')) {
            $seen['code'] = $params['code'] ?? '';
            // Forme enveloppée, telle que la documente Meta pour cet endpoint.
            return [200, [], ['data' => [['access_token' => 'SHORT', 'user_id' => '999', 'permissions' => 'instagram_business_basic']]]];
        }
        if (str_contains($url, '/access_token')) {
            return [200, [], ['access_token' => 'LONG', 'expires_in' => 5183944]];
        }
        return [200, [], ['user_id' => '17841400000000000', 'id' => 'APPSCOPED', 'username' => 'moncompte', 'account_type' => 'BUSINESS']];
    });

    $patch = instagram_oauth_exchange(['app_id' => 'A', 'app_secret' => 'S'], 'abc123#_');
    assert_eq('abc123', $seen['code'], 'le fragment #_ doit être retiré du code');
    assert_eq('LONG', $patch['access_token'], 'le token court doit être échangé contre un token 60 jours');
    // « user_id » est l'identifiant du compte professionnel ; « id » est
    // propre à l'app et ne fonctionne pas pour publier.
    assert_eq('17841400000000000', $patch['ig_user_id']);
    assert_eq('moncompte', $patch['username']);
    assert_true(strtotime($patch['token_expires_at']) > time() + 55 * 86400);
    http_real();
});

test('une réponse à plat est acceptée aussi bien qu\'enveloppée', function () {
    assert_eq(['a' => 1], ig_unwrap(['a' => 1]));
    assert_eq(['a' => 1], ig_unwrap(['data' => [['a' => 1]]]));
});

test('un échange refusé explique le piège de l\'App ID', function () {
    http_fake(['POST /oauth/access_token' => [400, [], ['error_message' => 'Invalid client id']]]);
    assert_throws(
        fn () => instagram_oauth_exchange(['app_id' => 'A', 'app_secret' => 'S'], 'code'),
        'et non ceux de l\'app Facebook',
        RuntimeException::class
    );
    http_real();
});

test('un token proche de l\'échéance est renouvelé et persisté', function () {
    [$config] = fixture_config('instagram', fixture_ig_settings([
        'token_expires_at'  => gmdate('Y-m-d H:i:s', time() + 5 * 86400),
        'token_obtained_at' => gmdate('Y-m-d H:i:s', time() - 55 * 86400),
    ]));
    http_fake(['GET /refresh_access_token' => [200, [], ['access_token' => 'RENOUVELE', 'expires_in' => 5183944]]]);

    $fresh = ig_refresh_if_due($config, config_settings($config));
    assert_eq('RENOUVELE', $fresh['access_token']);
    assert_eq('RENOUVELE', config_settings(config_get((int) $config['id']))['access_token'],
        'le nouveau token doit être enregistré, sinon il est perdu');
    assert_true(strtotime($fresh['token_expires_at']) > time() + 55 * 86400);
    http_real();
});

test('un token de moins de 24 h n\'est pas renouvelé, Meta le refuserait', function () {
    [$config] = fixture_config('instagram', fixture_ig_settings([
        'token_expires_at'  => gmdate('Y-m-d H:i:s', time() + 3 * 86400),
        'token_obtained_at' => gmdate('Y-m-d H:i:s', time() - 3600),
    ]));
    http_fake(['GET /refresh_access_token' => [200, [], ['access_token' => 'NE_DOIT_PAS_ARRIVER']]]);
    $fresh = ig_refresh_if_due($config, config_settings($config));
    assert_eq('IGQVJtoken', $fresh['access_token']);
    assert_eq(0, count(http_calls()), 'aucun appel de renouvellement ne devait partir');
    http_real();
});

test('un token encore loin de l\'échéance n\'est pas renouvelé inutilement', function () {
    [$config] = fixture_config('instagram', fixture_ig_settings());
    http_fake(['GET /refresh_access_token' => [200, [], ['access_token' => 'NON']]]);
    ig_refresh_if_due($config, config_settings($config));
    assert_eq(0, count(http_calls()));
    http_real();
});

test('un renouvellement en échec laisse le token existant en place', function () {
    [$config] = fixture_config('instagram', fixture_ig_settings([
        'token_expires_at'  => gmdate('Y-m-d H:i:s', time() + 5 * 86400),
        'token_obtained_at' => gmdate('Y-m-d H:i:s', time() - 55 * 86400),
    ]));
    http_fake(['GET /refresh_access_token' => [400, [], ['error' => ['message' => 'nope', 'code' => 190]]]]);
    $fresh = ig_refresh_if_due($config, config_settings($config));
    assert_eq('IGQVJtoken', $fresh['access_token'], 'un échec ne doit pas effacer le token en cours');
    http_real();
});

group('Instagram — quota et profil');

test('le quota est lu en direct, jamais codé en dur', function () {
    http_fake(['GET /content_publishing_limit' => [200, [], [
        'data' => [['quota_usage' => 12, 'config' => ['quota_total' => 100, 'quota_duration' => 86400]]],
    ]]]);
    $result = ig_tool_publishing_limit(fixture_ig_settings());
    assert_eq(12, $result['structuredContent']['used']);
    assert_eq(100, $result['structuredContent']['total'], 'la valeur renvoyée par le compte fait foi');
    assert_eq(88, $result['structuredContent']['remaining']);
    http_real();
});

test('sans valeur renvoyée, le quota retombe sur la borne prudente de 50', function () {
    http_fake(['GET /content_publishing_limit' => [200, [], ['data' => [['quota_usage' => 3]]]]]);
    $result = ig_tool_publishing_limit(fixture_ig_settings());
    assert_eq(50, $result['structuredContent']['total']);
    assert_eq(47, $result['structuredContent']['remaining']);
    http_real();
});

test('le profil expose le type de compte et les compteurs', function () {
    http_fake(['GET /me' => [200, [], [
        'user_id' => '178414', 'username' => 'moi', 'account_type' => 'MEDIA_CREATOR',
        'followers_count' => 1234, 'media_count' => 56,
    ]]]);
    $result = ig_tool_profile(fixture_ig_settings());
    assert_eq('MEDIA_CREATOR', $result['structuredContent']['account_type']);
    assert_eq(1234, $result['structuredContent']['followers_count']);
    assert_contains('@moi', $result['content'][0]['text']);
    http_real();
});

test('les publications listées distinguent reels et stories des posts', function () {
    http_fake(['GET /media' => [200, [], ['data' => [
        ['id' => '1', 'media_type' => 'VIDEO', 'media_product_type' => 'REELS', 'permalink' => 'https://x/1', 'timestamp' => '2026-08-01T10:00:00+0000'],
        ['id' => '2', 'media_type' => 'IMAGE', 'media_product_type' => 'FEED', 'caption' => 'Coucou', 'permalink' => 'https://x/2', 'timestamp' => '2026-08-02T10:00:00+0000'],
    ]]]]);
    $result = ig_tool_list_media(fixture_ig_settings(), ['limit' => 5]);
    $media  = $result['structuredContent']['media'];
    // media_type annonce VIDEO même pour un reel : media_product_type départage.
    assert_eq('REELS', $media[0]['media_type']);
    assert_eq('FEED', $media[1]['media_type']);
    http_real();
});

group('Instagram — interface de la page connecteur');

test('un token collé à la main connecte le compte sans OAuth', function () {
    [$config] = fixture_config('instagram');
    http_fake(['GET /me' => [200, [], ['user_id' => '178499', 'username' => 'colle', 'account_type' => 'BUSINESS']]]);

    $message  = instagram_settings_save($config, ['app_id' => 'A', 'manual_token' => 'TOKEN_COLLE']);
    $settings = config_settings(config_get((int) $config['id']));
    assert_contains('@colle', $message);
    assert_eq('TOKEN_COLLE', $settings['access_token']);
    assert_eq('178499', $settings['ig_user_id']);
    assert_true(instagram_summary($settings)['connected']);
    http_real();
});

test('un token collé invalide est signalé sans casser les réglages', function () {
    [$config] = fixture_config('instagram');
    http_fake(['GET /me' => [400, [], ['error' => ['message' => 'Invalid OAuth access token', 'code' => 190]]]]);

    $message  = instagram_settings_save($config, ['app_id' => 'MONAPP', 'manual_token' => 'FAUX']);
    $settings = config_settings(config_get((int) $config['id']));
    assert_contains('n\'a pas été accepté', $message);
    assert_eq('MONAPP', $settings['app_id'], 'les autres réglages doivent être enregistrés malgré tout');
    assert_false(instagram_summary($settings)['connected']);
    http_real();
});

test('l\'App Secret vide conserve celui déjà enregistré', function () {
    [$config] = fixture_config('instagram', ['app_id' => 'A', 'app_secret' => 'SECRET_INITIAL']);
    instagram_settings_save(config_get((int) $config['id']), ['app_id' => 'B', 'app_secret' => '']);
    $settings = config_settings(config_get((int) $config['id']));
    assert_eq('SECRET_INITIAL', $settings['app_secret']);
    assert_eq('B', $settings['app_id']);
});

test('la déconnexion efface le token mais garde les identifiants d\'app', function () {
    [$config] = fixture_config('instagram', fixture_ig_settings());
    instagram_disconnect(config_get((int) $config['id']));
    $settings = config_settings(config_get((int) $config['id']));
    assert_false(isset($settings['access_token']));
    assert_false(isset($settings['ig_user_id']));
    assert_eq('IGAPP123', $settings['app_id'], 'inutile de resaisir l\'app pour se reconnecter');
});

test('les secrets sont chiffrés en base', function () {
    [$config] = fixture_config('instagram', fixture_ig_settings());
    $raw = config_get((int) $config['id'])['settings'];
    assert_not_contains('IGSECRET', $raw, 'l\'App Secret ne doit jamais être en clair en base');
    assert_not_contains('IGQVJtoken', $raw, 'le token ne doit jamais être en clair en base');
    assert_contains('enc1:', $raw);
    // …mais restent lisibles par l'application.
    assert_eq('IGSECRET', config_settings(config_get((int) $config['id']))['app_secret']);
});

group('Instagram — garde-fous de l\'hébergement mutualisé');

test('une image base64 démesurée est refusée avant d\'être décodée', function () {
    $enorme = str_repeat('A', IG_BASE64_MAX_CHARS + 4);
    $e = assert_throws(
        fn () => ig_resolve_image(fixture_ig_settings(), ['image_base64' => $enorme], 'image', 'reject', 'auto'),
        'trop volumineux',
        McpToolError::class
    );
    // Le message doit dire quoi faire, pas seulement constater.
    assert_contains('image_url', $e->getMessage());
});

test('un carrousel dont les images cumulées épuiseraient la mémoire est refusé', function () {
    // Chaque image passe seule, mais leur cumul dépasse ce que PHP peut traiter :
    // sans ce contrôle, l'erreur serait fatale au milieu du traitement et le
    // client MCP ne recevrait aucune réponse exploitable.
    $part  = str_repeat('A', 20000000);
    $items = array_fill(0, 5, ['image_base64' => $part]);

    $e = assert_throws(fn () => ig_check_total_payload($items), 'totalisent environ', McpToolError::class);
    assert_contains('image_url', $e->getMessage());

    // Trois images de la même taille restent sous le plafond.
    ig_check_total_payload(array_fill(0, 3, ['image_base64' => $part]));
});

test('un carrousel de dix photos ordinaires reste dans le budget mémoire', function () {
    $photo = fixture_jpeg(1600, 1200, 82);
    $items = array_fill(0, 10, ['image_base64' => base64_encode($photo)]);
    ig_check_total_payload($items); // ne doit pas lever

    $avant = memory_get_usage(true);
    $created = 0;
    http_fake_fn(function ($m, $u, $h, $b) use (&$created) {
        $p = http_fake_params($u, $b);
        if ($m === 'POST' && str_contains($u, '/media_publish')) {
            return [200, [], ['id' => 'M']];
        }
        if ($m === 'POST' && str_contains($u, '/media')) {
            $created++;
            return [200, [], ['id' => isset($p['children']) ? 'P' : 'C' . $created]];
        }
        return [200, [], ['status_code' => 'FINISHED', 'permalink' => '']];
    });
    $result = ig_tool_publish_carousel(fixture_ig_settings(), ['items' => $items, 'caption' => 'Dix photos']);

    assert_eq('M', $result['structuredContent']['media_id']);
    assert_eq(11, $created, '10 enfants + 1 parent');
    // Le traitement se fait image par image : la mémoire ne doit pas enfler
    // proportionnellement au nombre d'images.
    $consomme = (memory_get_usage(true) - $avant) / 1048576;
    assert_true($consomme < 64, 'mémoire consommée : ' . round($consomme, 1) . ' Mo');
    http_real();
});
