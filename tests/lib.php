<?php
/**
 * Micro-cadre de test, sans dépendance : quelques assertions et un compteur.
 *
 * Chaque fichier tests/test_*.php déclare ses cas avec test('…', fn) ; c'est
 * tests/run.php qui les charge et affiche le bilan.
 */

$GLOBALS['t_passed'] = 0;
$GLOBALS['t_failed'] = 0;
$GLOBALS['t_errors'] = [];
$GLOBALS['t_group']  = '';

/** Déclare et exécute un cas de test. */
function test(string $name, callable $fn): void
{
    try {
        $fn();
        $GLOBALS['t_passed']++;
        echo "  \033[32m✓\033[0m $name\n";
    } catch (Throwable $e) {
        $GLOBALS['t_failed']++;
        $where = basename($e->getFile()) . ':' . $e->getLine();
        $GLOBALS['t_errors'][] = $GLOBALS['t_group'] . ' › ' . $name . "\n      " . $e->getMessage() . "\n      ($where)";
        echo "  \033[31m✗\033[0m $name\n      \033[31m" . $e->getMessage() . "\033[0m\n";
    }
}

/** Ouvre un groupe de tests (titre affiché). */
function group(string $title): void
{
    $GLOBALS['t_group'] = $title;
    echo "\n\033[1m$title\033[0m\n";
}

function assert_true(mixed $value, string $message = ''): void
{
    if ($value !== true && $value != true) {
        throw new RuntimeException($message !== '' ? $message : 'Attendu vrai, obtenu ' . var_export($value, true));
    }
}

function assert_false(mixed $value, string $message = ''): void
{
    if ($value) {
        throw new RuntimeException($message !== '' ? $message : 'Attendu faux, obtenu ' . var_export($value, true));
    }
}

function assert_eq(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(($message !== '' ? $message . ' — ' : '')
            . 'attendu ' . var_export($expected, true) . ', obtenu ' . var_export($actual, true));
    }
}

function assert_contains(string $needle, string $haystack, string $message = ''): void
{
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException(($message !== '' ? $message . ' — ' : '')
            . '« ' . $needle . ' » absent de : ' . mb_str_limit($haystack, 400));
    }
}

function assert_not_contains(string $needle, string $haystack, string $message = ''): void
{
    if (str_contains($haystack, $needle)) {
        throw new RuntimeException(($message !== '' ? $message . ' — ' : '')
            . '« ' . $needle . ' » présent alors qu\'il ne devrait pas.');
    }
}

/**
 * Vérifie qu'un appel lève une exception dont le message contient $needle.
 * Retourne l'exception, pour des vérifications complémentaires.
 */
function assert_throws(callable $fn, string $needle = '', string $class = Throwable::class): Throwable
{
    try {
        $fn();
    } catch (Throwable $e) {
        if (!($e instanceof $class)) {
            throw new RuntimeException('Exception de type ' . get_class($e) . ' au lieu de ' . $class . ' : ' . $e->getMessage());
        }
        if ($needle !== '' && !str_contains($e->getMessage(), $needle)) {
            throw new RuntimeException('Message inattendu : « ' . $e->getMessage() . ' » ne contient pas « ' . $needle . ' »');
        }
        return $e;
    }
    throw new RuntimeException('Aucune exception levée (attendu ' . $class
        . ($needle !== '' ? ' contenant « ' . $needle . ' »' : '') . ')');
}

/* ------------------------------------------------------- Simulation HTTP */

/**
 * Installe un transport HTTP simulé.
 *
 * $routes associe un motif « MÉTHODE fragment-d-url » à une réponse, soit un
 * tableau [status, headers, corps], soit un callable(params) qui la produit.
 * Les appels effectués sont consignés dans $GLOBALS['t_http_calls'].
 */
function http_fake(array $routes): void
{
    $GLOBALS['t_http_calls'] = [];
    http_set_transport(function (string $method, string $url, array $headers, ?string $body, int $timeout) use ($routes) {
        $GLOBALS['t_http_calls'][] = ['method' => $method, 'url' => $url, 'body' => $body];

        // Le motif le plus long l'emporte : « POST /media_publish » doit
        // gagner sur « POST /media », quel que soit l'ordre de déclaration.
        $patterns = array_keys($routes);
        usort($patterns, fn ($a, $b) => strlen($b) <=> strlen($a));

        foreach ($patterns as $pattern) {
            [$wantMethod, $fragment] = array_pad(explode(' ', $pattern, 2), 2, '');
            $fragment = trim($fragment); // tolère l'alignement des motifs
            if (trim($wantMethod) !== $method || !str_contains($url, $fragment)) {
                continue;
            }
            $response = $routes[$pattern];
            if (is_callable($response)) {
                return $response(http_fake_params($url, $body));
            }
            return $response;
        }
        throw new RuntimeException("Appel HTTP non simulé : $method $url");
    });
}

/** Paramètres d'un appel simulé (query string et corps confondus). */
function http_fake_params(string $url, ?string $body): array
{
    $params = [];
    $query  = parse_url($url, PHP_URL_QUERY);
    if (is_string($query)) {
        parse_str($query, $params);
    }
    if ($body !== null && $body !== '') {
        parse_str($body, $fromBody);
        $params += $fromBody;
    }
    return $params;
}

/**
 * Comme http_fake(), mais avec une fonction libre pour produire la réponse.
 * Le journal des appels est tenu de la même façon.
 */
function http_fake_fn(callable $responder): void
{
    $GLOBALS['t_http_calls'] = [];
    http_set_transport(function (string $method, string $url, array $headers, ?string $body, int $timeout) use ($responder) {
        $GLOBALS['t_http_calls'][] = ['method' => $method, 'url' => $url, 'body' => $body];
        return $responder($method, $url, $headers, $body, $timeout);
    });
}

/** Retire le transport simulé. */
function http_real(): void
{
    http_set_transport(null);
    $GLOBALS['t_http_calls'] = [];
}

/** Les appels HTTP simulés depuis le dernier http_fake(). */
function http_calls(): array
{
    return $GLOBALS['t_http_calls'] ?? [];
}

/* ---------------------------------------------------------- Jeux d'essai */

/** Produit une image PNG de test aux dimensions demandées. */
function fixture_png(int $width, int $height): string
{
    $im = imagecreatetruecolor($width, $height);
    imagefilledrectangle($im, 0, 0, $width, $height, imagecolorallocate($im, 40, 90, 200));
    imagefilledellipse($im, (int) ($width / 2), (int) ($height / 2), max(2, (int) ($width / 2)), max(2, (int) ($height / 2)), imagecolorallocate($im, 250, 200, 40));
    ob_start();
    imagepng($im);
    imagedestroy($im);
    return (string) ob_get_clean();
}

/** Produit une image JPEG de test aux dimensions demandées. */
function fixture_jpeg(int $width, int $height, int $quality = 85): string
{
    $im = imagecreatetruecolor($width, $height);
    // Un dégradé bruité : une image unie se compresse trop pour tester le poids.
    for ($x = 0; $x < $width; $x += 4) {
        for ($y = 0; $y < $height; $y += 4) {
            $c = imagecolorallocate($im, ($x * 7) % 256, ($y * 13) % 256, ($x * $y) % 256);
            imagefilledrectangle($im, $x, $y, $x + 3, $y + 3, $c);
        }
    }
    ob_start();
    imagejpeg($im, null, $quality);
    imagedestroy($im);
    return (string) ob_get_clean();
}

/** Crée une config de test jetable et retourne [config, grant, token]. */
function fixture_config(string $type, array $settings = []): array
{
    $email = 'tests+' . random_hex(4) . '@example.com';
    $user   = user_create($email);
    $config = config_create((int) $user['id'], $type, 'Test ' . $type);
    if ($settings !== []) {
        config_update_settings($config, $settings);
        $config = config_get((int) $config['id']);
    }
    $grant = grant_for((int) $config['id'], (int) $user['id']);
    return [$config, $grant, grant_token($grant)];
}

/** Réglages d'un connecteur Instagram déjà connecté. */
function fixture_ig_settings(array $extra = []): array
{
    return $extra + [
        'app_id'            => 'IGAPP123',
        'app_secret'        => 'IGSECRET',
        'access_token'      => 'IGQVJtoken',
        'token_expires_at'  => gmdate('Y-m-d H:i:s', time() + 50 * 86400),
        'token_obtained_at' => gmdate('Y-m-d H:i:s', time() - 5 * 86400),
        'ig_user_id'        => '17841400000000000',
        'username'          => 'compte_test',
        'account_type'      => 'BUSINESS',
    ];
}
