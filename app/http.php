<?php
/**
 * Socle HTTP partagé par les connecteurs : requêtes cURL, garde-fous
 * anti-SSRF et téléchargement borné en taille.
 *
 * Un connecteur ne doit jamais servir de relais vers le réseau interne de
 * l'hébergement : toute URL fournie par un outil MCP passe par
 * http_guard_public_url() avant d'être jointe, et l'adresse réellement
 * atteinte est revérifiée à chaque saut de redirection quand cURL le permet.
 */

/**
 * Transport effectif de http_request(). Point d'injection unique, utilisé par
 * la suite de tests pour simuler une API sans réseau. En production la valeur
 * reste nulle : c'est cURL qui répond.
 */
function http_transport(?callable $transport = null, bool $replace = false): ?callable
{
    static $current = null;
    if ($replace) {
        $current = $transport;
    }
    return $current;
}

/** Installe (ou retire, avec null) un transport de substitution. */
function http_set_transport(?callable $transport): void
{
    http_transport($transport, true);
}

/**
 * Requête HTTP bas niveau.
 * Retourne [statusHttp, en-têtes (clés en minuscules), corps décodé en JSON].
 */
function http_request(string $method, string $url, array $headers = [], ?string $rawBody = null, int $timeout = 30): array
{
    $stub = http_transport();
    if ($stub !== null) {
        return $stub($method, $url, $headers, $rawBody, $timeout);
    }

    $respHeaders = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$respHeaders) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $respHeaders[strtolower(trim($name))] = trim($value);
            }
            return strlen($line);
        },
    ]);
    if ($rawBody !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $rawBody);
    }

    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new McpToolError('Service injoignable : ' . $err);
    }
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $decoded = json_decode((string) $raw, true);
    return [$status, $respHeaders, is_array($decoded) ? $decoded : []];
}

/** Indique si cURL passera par un proxy sortant (variables d'environnement). */
function http_proxy_configured(): bool
{
    foreach (['http_proxy', 'https_proxy', 'all_proxy'] as $name) {
        if (trim((string) (getenv($name) ?: getenv(strtoupper($name)) ?: '')) !== '') {
            return true;
        }
    }
    return false;
}

/**
 * Refuse une URL qui ne serait pas un http(s) vers une adresse publique.
 * $label est le nom de l'argument d'outil concerné, cité dans les messages.
 *
 * $resolveDns doit rester vrai dès que *nous* allons joindre l'URL : c'est là
 * que la protection anti-SSRF a un sens. Quand l'URL est seulement transmise à
 * un tiers qui ira la chercher lui-même, on se contente des contrôles qui ne
 * dépendent pas du DNS : une résolution indisponible ne doit pas faire échouer
 * une URL parfaitement valide.
 */
function http_guard_public_url(string $url, string $label, bool $resolveDns = true): void
{
    $parts  = parse_url($url) ?: [];
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host   = (string) ($parts['host'] ?? '');
    if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
        throw new McpToolError("« $label » doit être une URL http(s) complète (ex. https://exemple.com/fichier).");
    }
    if (!$resolveDns) {
        // Une IP écrite en clair reste contrôlable sans DNS.
        $literal = trim($host, '[]');
        if (filter_var($literal, FILTER_VALIDATE_IP)) {
            http_guard_public_ip($literal, $label);
        }
        return;
    }
    foreach (http_resolve_host($host, $label) as $ip) {
        http_guard_public_ip($ip, $label);
    }
}

/** Adresses IP d'un hôte (qui peut déjà être une IP littérale). */
function http_resolve_host(string $host, string $label): array
{
    $host = trim($host, '[]'); // IPv6 littéral : [2001:db8::1]
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return [$host];
    }
    $ips = array_merge(
        gethostbynamel($host) ?: [],
        array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6')
    );
    if ($ips === []) {
        throw new McpToolError("Hôte introuvable pour « $label » : " . $host);
    }
    return $ips;
}

/** Refuse une adresse privée, de bouclage ou réservée. */
function http_guard_public_ip(string $ip, string $label): void
{
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        throw new McpToolError("« $label » pointe vers une adresse interne (" . $ip
            . ') : seules les URL publiques sont acceptées.');
    }
}

/**
 * Télécharge une URL publique en refusant de dépasser $maxBytes.
 * Le transfert est coupé dès le dépassement, sans attendre la fin.
 */
function http_download_limited(
    string $url,
    int $maxBytes,
    string $label,
    int $timeout,
    string $sizeMessage
): string {
    http_guard_public_url($url, $label);

    $abort = '';
    $ch    = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_TIMEOUT         => $timeout,
        CURLOPT_FOLLOWLOCATION  => true,
        CURLOPT_MAXREDIRS       => 3,
        CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_USERAGENT       => APP_NAME . '/' . APP_VERSION,
        // Coupe le transfert dès le dépassement de la limite (retour non nul).
        CURLOPT_NOPROGRESS       => false,
        CURLOPT_PROGRESSFUNCTION => static function ($ch, $expected, $received) use (&$abort, $maxBytes): int {
            if ($expected > $maxBytes || $received > $maxBytes) {
                $abort = 'size';
                return 1;
            }
            return 0;
        },
    ]);
    // Rejoue le contrôle anti-SSRF sur l'adresse réellement jointe, à chaque
    // saut (PHP 8.2+ / libcurl 7.80+ ; ailleurs, seule l'URL initiale est vue).
    // Inapplicable derrière un proxy sortant : l'adresse vue serait celle du
    // proxy — c'est alors le contrôle sur l'URL initiale qui protège.
    if (defined('CURLOPT_PREREQFUNCTION') && !http_proxy_configured()) {
        curl_setopt($ch, CURLOPT_PREREQFUNCTION, static function ($ch, $destIp) use (&$abort, $label): int {
            try {
                http_guard_public_ip((string) $destIp, $label);
                return CURL_PREREQFUNC_OK;
            } catch (McpToolError) {
                $abort = 'ip';
                return CURL_PREREQFUNC_ABORT;
            }
        });
    }

    $body = curl_exec($ch);
    if ($body === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new McpToolError(match ($abort) {
            'size'  => $sizeMessage,
            'ip'    => 'Le téléchargement a été redirigé vers une adresse interne : seules les URL publiques sont acceptées.',
            default => 'Téléchargement impossible : ' . $err,
        });
    }
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($status !== 200) {
        throw new McpToolError("Le fichier n'a pas pu être téléchargé (HTTP $status) : $url");
    }
    if ($body === '') {
        throw new McpToolError('Le fichier téléchargé est vide : ' . $url);
    }
    if (strlen($body) > $maxBytes) {
        throw new McpToolError($sizeMessage);
    }
    return $body;
}
