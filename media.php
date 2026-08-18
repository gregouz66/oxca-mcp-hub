<?php
/**
 * Service public des médias mis en scène.
 *
 * Les serveurs de Meta téléchargent ici les images à publier : l'accès est
 * donc volontairement anonyme, la clé de 128 bits tenant lieu de secret.
 * Aucune session n'est ouverte (pas de cookie émis), et storage/ reste
 * inaccessible directement — seul ce script en sert les octets.
 */

define('OXCA_NO_SESSION', true);
require __DIR__ . '/app/bootstrap.php';

header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    media_serve_error(405, 'Méthode non autorisée.');
}

$key = (string) ($_GET['k'] ?? '');
if (!media_key_valid($key)) {
    media_serve_error(404, 'Média introuvable.');
}

media_gc();

$meta = media_meta($key);
$size = $meta !== null ? @filesize(media_path($key, 'bin')) : false;
if ($meta === null || $size === false) {
    media_serve_error(404, 'Média introuvable ou expiré.');
}

header('Content-Type: ' . $meta['mime']);
header('Content-Length: ' . $size);
header('Content-Disposition: inline');
header('Cache-Control: private, max-age=600');

if ($method === 'HEAD') {
    exit;
}
readfile(media_path($key, 'bin'));
exit;

/** Réponse d'erreur en texte brut, sans divulguer l'état du dépôt. */
function media_serve_error(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message . "\n";
    exit;
}
