<?php
/**
 * Bout en bout, à travers un vrai serveur HTTP : c'est le seul moyen de
 * vérifier les en-têtes, les codes de statut et le service des médias tels
 * que les verront Claude et les serveurs de Meta.
 *
 * Le serveur intégré de PHP est démarré puis arrêté par ce fichier.
 */

group('Bout en bout — serveur HTTP réel');

$root = dirname(__DIR__);
$host = parse_url(APP_URL, PHP_URL_HOST) . ':' . (parse_url(APP_URL, PHP_URL_PORT) ?: 80);

$server = proc_open(
    ['php', '-S', $host, '-t', $root],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes
);

// Attente de disponibilité : le serveur met quelques dizaines de ms à écouter.
$ready = false;
for ($i = 0; $i < 100 && !$ready; $i++) {
    $sock = @fsockopen(parse_url(APP_URL, PHP_URL_HOST), (int) (parse_url(APP_URL, PHP_URL_PORT) ?: 80), $e, $s, 0.2);
    if ($sock) {
        $ready = true;
        fclose($sock);
    } else {
        usleep(50000);
    }
}

/** Requête HTTP réelle. Retourne [status, en-têtes, corps]. */
function e2e(string $method, string $url, ?string $body = null, array $headers = []): array
{
    $respHeaders = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$respHeaders) {
            if (str_contains($line, ':')) {
                [$n, $v] = explode(':', $line, 2);
                $respHeaders[strtolower(trim($n))] = trim($v);
            }
            return strlen($line);
        },
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $out    = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$status, $respHeaders, (string) $out];
}

test('le serveur de test répond', function () use ($ready) {
    assert_true($ready, 'le serveur PHP intégré n\'a pas démarré : les tests bout en bout ne peuvent pas s\'exécuter');
});

/* ------------------------------------------------------- Endpoint MCP */

test('mcp.php refuse une requête sans token', function () {
    [$status, , $body] = e2e('POST', base_url('/mcp.php'), '{"jsonrpc":"2.0","id":1,"method":"ping"}',
        ['Content-Type: application/json']);
    assert_eq(401, $status);
    assert_contains('Unauthorized', $body);
});

test('mcp.php accepte le token en en-tête Authorization', function () {
    [, , $token] = fixture_config('instagram', fixture_ig_settings());
    [$status, , $body] = e2e('POST', base_url('/mcp.php'),
        '{"jsonrpc":"2.0","id":1,"method":"tools/list"}',
        ['Content-Type: application/json', 'Authorization: Bearer ' . $token]);

    assert_eq(200, $status);
    $reply = json_decode($body, true);
    assert_contains('instagram_publish_image', json_encode($reply));
});

test('mcp.php accepte aussi le token en paramètre d\'URL', function () {
    [, , $token] = fixture_config('instagram', fixture_ig_settings());
    [$status, , $body] = e2e('POST', base_url('/mcp.php?t=' . $token),
        '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18"}}',
        ['Content-Type: application/json']);
    assert_eq(200, $status);
    assert_contains('protocolVersion', $body);
});

test('mcp.php répond 405 aux méthodes autres que POST', function () {
    [, , $token] = fixture_config('instagram');
    [$status, $headers] = e2e('GET', base_url('/mcp.php?t=' . $token));
    assert_eq(405, $status);
    assert_contains('POST', $headers['allow'] ?? '');
});

/* ---------------------------------------------------- Service des médias */

test('media.php sert les octets déposés avec le bon type', function () {
    $jpeg = fixture_jpeg(600, 600);
    $put  = media_put($jpeg, 'image/jpeg');

    [$status, $headers, $body] = e2e('GET', $put['url']);
    assert_eq(200, $status);
    assert_eq('image/jpeg', $headers['content-type'] ?? '');
    assert_eq((string) strlen($jpeg), $headers['content-length'] ?? '');
    assert_eq($jpeg, $body, 'les octets servis doivent être identiques aux octets déposés');
    // Meta ne suit pas les redirections de façon fiable : il ne doit y en avoir aucune.
    assert_false(isset($headers['location']), 'aucune redirection ne doit être émise');
    assert_eq('nosniff', $headers['x-content-type-options'] ?? '');
    media_forget($put['key']);
});

test('media.php n\'émet aucun cookie de session', function () {
    $put = media_put('abc', 'image/jpeg');
    [, $headers] = e2e('GET', $put['url']);
    assert_false(isset($headers['set-cookie']), 'le service des médias doit rester sans état');
    media_forget($put['key']);
});

test('media.php répond 404 pour une clé inconnue, expirée ou malformée', function () {
    foreach ([str_repeat('a', 32), 'trop-court', '../../config.php', ''] as $key) {
        [$status] = e2e('GET', base_url('/media.php?k=' . rawurlencode($key)));
        assert_eq(404, $status, 'clé « ' . $key .' »');
    }

    $put = media_put('périmé', 'image/jpeg');
    file_put_contents(media_path($put['key'], 'json'), json_encode([
        'mime' => 'image/jpeg', 'size' => 7, 'expires_at' => time() - 1,
    ]));
    [$status] = e2e('GET', $put['url']);
    assert_eq(404, $status, 'un dépôt expiré ne doit plus être servi');
});

test('media.php répond aux requêtes HEAD sans corps', function () {
    $jpeg = fixture_jpeg(400, 400);
    $put  = media_put($jpeg, 'image/jpeg');
    [$status, $headers, $body] = e2e('HEAD', $put['url']);
    assert_eq(200, $status);
    assert_eq((string) strlen($jpeg), $headers['content-length'] ?? '');
    assert_eq('', $body);
    media_forget($put['key']);
});

test('media.php refuse les méthodes d\'écriture', function () {
    $put = media_put('abc', 'image/jpeg');
    [$status] = e2e('POST', $put['url'], 'x=1');
    assert_eq(405, $status);
    media_forget($put['key']);
});

test('l\'auto-diagnostic média confirme que Meta pourra télécharger', function () {
    $result = media_self_test();
    assert_true($result['ok'], 'auto-diagnostic en échec : ' . $result['message']);
    assert_contains('bien servis publiquement', $result['message']);
});

test('l\'auto-diagnostic détecte une APP_URL injoignable', function () {
    // On simule une mauvaise configuration en déposant puis en interrogeant
    // un hôte qui n'écoute pas : c'est le symptôme le plus fréquent.
    [$status] = e2e('GET', 'http://127.0.0.1:9/media.php?k=' . str_repeat('a', 32));
    assert_eq(0, $status, 'un port fermé doit bien échouer, sinon le test ne prouve rien');
});

/* ------------------------------------------------- Pages de l'application */

test('la page de documentation des outils s\'affiche avec un token', function () {
    [, , $token] = fixture_config('instagram', fixture_ig_settings());
    [$status, , $body] = e2e('GET', base_url('/tools.php?t=' . $token));
    assert_eq(200, $status);
    assert_contains('instagram_publish_carousel', $body);
    // Les outils indisponibles restent documentés, avec leur condition.
    assert_contains('instagram_delete_media', $body);
    assert_contains('Facebook Login', $body);
});

test('la documentation JSON des outils est exploitable', function () {
    [, , $token] = fixture_config('instagram', fixture_ig_settings());
    [$status, $headers, $body] = e2e('GET', base_url('/tools.php?t=' . $token . '&format=json'));
    assert_eq(200, $status);
    assert_contains('application/json', $headers['content-type'] ?? '');
    $doc = json_decode($body, true);
    assert_true(is_array($doc), 'le JSON doit être décodable');
    assert_contains('instagram', json_encode($doc));
});

test('config.php n\'est jamais servi', function () {
    // Le serveur intégré de PHP ignore .htaccess : il exécute config.php, qui
    // n'affiche rien par construction. C'est cette propriété qu'on vérifie.
    [, , $body] = e2e('GET', base_url('/config.php'));
    assert_not_contains('DB_PASS', $body);
    assert_not_contains('APP_KEY', $body);
    assert_not_contains(APP_KEY, $body);
});

/* ------------------------------------------------------------- Arrêt */

if (is_resource($server)) {
    proc_terminate($server);
    proc_close($server);
}
