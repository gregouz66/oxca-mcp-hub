<?php
/**
 * Mise en scène des médias : dépôt temporaire de fichiers servis par une URL
 * publique.
 *
 * L'API Instagram ne reçoit jamais d'octets — ce sont les serveurs de Meta qui
 * téléchargent le média (« We cURL media used in publishing attempts, so the
 * media must be hosted on a publicly accessible server at the time of the
 * attempt »). Une image transmise en base64 par le client MCP doit donc être
 * matérialisée puis exposée le temps de la publication.
 *
 * Les fichiers vivent dans storage/media/, qu'Apache refuse de servir
 * (storage/.htaccess) : c'est media.php, à la racine, qui en sert les octets
 * après contrôle de la clé et de l'expiration. La clé de 128 bits est le seul
 * secret — Meta n'envoie aucune authentification.
 */

/** Durée de vie d'un média mis en scène (s). Alignée sur la vie d'un conteneur. */
const MEDIA_TTL = 86400;

/** Répertoire de dépôt, créé au besoin. */
function media_dir(): string
{
    $dir = dirname(__DIR__) . '/storage/media';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Impossible de créer storage/media : vérifiez les droits d\'écriture sur storage/.');
    }
    return $dir;
}

/** Chemin d'un fichier de dépôt (extension « bin » ou « json »). */
function media_path(string $key, string $ext): string
{
    return media_dir() . '/' . $key . '.' . $ext;
}

/** Une clé de dépôt est-elle bien formée ? */
function media_key_valid(string $key): bool
{
    return (bool) preg_match('/^[0-9a-f]{32}$/', $key);
}

/** Métadonnées d'un média non expiré, ou null (absent, illisible ou périmé). */
function media_meta(string $key): ?array
{
    if (!media_key_valid($key)) {
        return null;
    }
    $raw = @file_get_contents(media_path($key, 'json'));
    if ($raw === false) {
        return null;
    }
    $meta = json_decode($raw, true);
    if (!is_array($meta) || (int) ($meta['expires_at'] ?? 0) < time()) {
        return null;
    }
    if (!is_file(media_path($key, 'bin'))) {
        return null;
    }
    return $meta;
}

/**
 * Dépose des octets et retourne ['key' => …, 'url' => …].
 * $mime doit avoir été validé par l'appelant (voir image_inspect()).
 *
 * L'URL produite est volontairement en ASCII pur : Meta prévient qu'une URL
 * contenant autre chose que de l'US-ASCII fera échouer la requête.
 */
function media_put(string $bytes, string $mime): array
{
    media_gc();

    $key = random_hex(16);
    if (@file_put_contents(media_path($key, 'bin'), $bytes, LOCK_EX) === false) {
        throw new RuntimeException('Écriture impossible dans storage/media : vérifiez les droits du répertoire.');
    }
    $meta = [
        'mime'       => $mime,
        'size'       => strlen($bytes),
        'created_at' => time(),
        'expires_at' => time() + MEDIA_TTL,
    ];
    if (@file_put_contents(media_path($key, 'json'), json_encode($meta), LOCK_EX) === false) {
        @unlink(media_path($key, 'bin'));
        throw new RuntimeException('Écriture impossible dans storage/media : vérifiez les droits du répertoire.');
    }
    return ['key' => $key, 'url' => base_url('/media.php?k=' . $key)];
}

/** Supprime un média déposé. */
function media_forget(string $key): void
{
    if (!media_key_valid($key)) {
        return;
    }
    @unlink(media_path($key, 'bin'));
    @unlink(media_path($key, 'json'));
}

/**
 * Purge les médias expirés. Appelée à chaque dépôt et à chaque lecture :
 * aucune tâche planifiée n'est nécessaire, ce qui compte sur un mutualisé.
 */
function media_gc(): void
{
    foreach (@glob(media_dir() . '/*.json') ?: [] as $json) {
        $meta = json_decode((string) @file_get_contents($json), true);
        if (is_array($meta)) {
            if ((int) ($meta['expires_at'] ?? 0) >= time()) {
                continue;
            }
        } elseif ((int) @filemtime($json) > time() - MEDIA_TTL) {
            // Métadonnées illisibles : on se replie sur la date du fichier
            // plutôt que de supprimer un dépôt peut-être encore utile.
            continue;
        }
        @unlink($json);
        @unlink(substr($json, 0, -5) . '.bin');
    }
}

/**
 * Vérifie que les médias déposés sont réellement téléchargeables depuis
 * l'extérieur, en rejouant le parcours de Meta : dépôt d'une image de test,
 * puis requête sur son URL publique.
 *
 * Détecte les causes de loin les plus fréquentes d'un échec de publication
 * (APP_URL erronée, storage/ non inscriptible, redirection, WAF ou protection
 * anti-hotlink) — que Meta ne signale que par une erreur générique.
 *
 * Retourne ['ok' => bool, 'message' => string, 'detail' => string].
 */
function media_self_test(): array
{
    // Image JPEG minimale produite localement, sans dépendance à GD.
    $pixel = base64_decode(
        '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0a'
        . 'HBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAA'
        . 'AAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q=='
    );

    try {
        $put = media_put($pixel, 'image/jpeg');
    } catch (RuntimeException $e) {
        return ['ok' => false, 'message' => 'Le dépôt du fichier de test a échoué. ' . $e->getMessage(), 'detail' => ''];
    }

    $ch = curl_init($put['url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_FOLLOWLOCATION => false, // une redirection est en soi un échec
        CURLOPT_HEADER         => false,
        CURLOPT_USERAGENT      => APP_NAME . '/' . APP_VERSION,
    ]);
    $body     = curl_exec($ch);
    $error    = curl_error($ch);
    $status   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $mime     = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    media_forget($put['key']);
    $detail = 'URL testée : ' . $put['url'];

    if ($body === false) {
        return ['ok' => false, 'detail' => $detail, 'message' => 'Le serveur n\'a pas pu joindre sa propre URL publique ('
            . $error . '). Vérifiez qu\'APP_URL correspond bien à l\'adresse publique du site.'];
    }
    if ($status >= 300 && $status < 400) {
        return ['ok' => false, 'detail' => $detail, 'message' => 'L\'URL du média répond par une redirection (HTTP ' . $status
            . '). Meta ne suit pas les redirections de façon fiable : servez APP_URL dans sa forme définitive (https, avec ou sans www, sans slash surnuméraire).'];
    }
    if ($status !== 200) {
        return ['ok' => false, 'detail' => $detail, 'message' => 'L\'URL du média répond HTTP ' . $status
            . '. Une protection anti-hotlink, une authentification ou une règle WAF bloque probablement l\'accès à /media.php.'];
    }
    if (!str_starts_with($mime, 'image/jpeg')) {
        return ['ok' => false, 'detail' => $detail, 'message' => 'L\'URL du média répond avec le type « ' . $mime
            . ' » au lieu de image/jpeg : un module du serveur réécrit la réponse.'];
    }
    if ($body !== $pixel) {
        return ['ok' => false, 'detail' => $detail, 'message' => 'Les octets reçus diffèrent de ceux déposés : un cache ou un proxy altère la réponse.'];
    }
    return ['ok' => true, 'detail' => $detail, 'message' => 'Les médias sont bien servis publiquement : Instagram pourra les télécharger.'];
}
