<?php
/**
 * Chiffrement au repos des valeurs sensibles (secrets OAuth, tokens d'accès,
 * tokens d'endpoint MCP) avec AES-256-GCM dérivé de APP_KEY.
 *
 * Une fuite de la base de données seule ne suffit donc pas à exposer les
 * secrets : il faut aussi config.php.
 */

/** Clé binaire 256 bits dérivée de APP_KEY. */
function crypto_key(): string
{
    static $key = null;
    if ($key === null) {
        if (!defined('APP_KEY') || strlen((string) APP_KEY) < 32) {
            throw new RuntimeException('APP_KEY manquante ou trop courte dans config.php (64 caractères hexadécimaux attendus).');
        }
        $key = hash('sha256', 'oxca-hub|' . APP_KEY, true);
    }
    return $key;
}

/** Chiffre une chaîne. Retourne un blob autoportant préfixé `enc1:`. */
function encrypt_value(string $plain): string
{
    $iv  = random_bytes(12);
    $tag = '';
    $ct  = openssl_encrypt($plain, 'aes-256-gcm', crypto_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($ct === false) {
        throw new RuntimeException('Échec du chiffrement.');
    }
    return 'enc1:' . base64_encode($iv . $tag . $ct);
}

/** Déchiffre un blob produit par encrypt_value(). Retourne null si invalide. */
function decrypt_value(?string $blob): ?string
{
    if ($blob === null || $blob === '') {
        return null;
    }
    if (!str_starts_with($blob, 'enc1:')) {
        return $blob; // valeur héritée non chiffrée
    }
    $raw = base64_decode(substr($blob, 5), true);
    if ($raw === false || strlen($raw) < 29) {
        return null;
    }
    $iv    = substr($raw, 0, 12);
    $tag   = substr($raw, 12, 16);
    $ct    = substr($raw, 28);
    $plain = openssl_decrypt($ct, 'aes-256-gcm', crypto_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return $plain === false ? null : $plain;
}

/** Empreinte SHA-256 (hex) utilisée pour indexer les tokens sans les stocker en clair. */
function token_hash(string $token): string
{
    return hash('sha256', $token);
}
