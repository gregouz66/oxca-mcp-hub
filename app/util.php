<?php
/**
 * Fonctions utilitaires transverses : échappement, redirections, CSRF,
 * messages flash, génération d'aléatoire.
 */

/** Échappe une chaîne pour l'insérer dans du HTML. */
function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/** URL absolue de l'application ($path commence par '/'). */
function base_url(string $path = ''): string
{
    return rtrim(APP_URL, '/') . $path;
}

/** Redirige vers un chemin de l'application et arrête le script. */
function redirect(string $path): never
{
    header('Location: ' . base_url($path));
    exit;
}

/** Date/heure courante en UTC, au format SQL. */
function now(): string
{
    return gmdate('Y-m-d H:i:s');
}

/** Formate une date SQL (UTC) pour l'affichage. */
function format_date(?string $sql, bool $withTime = false): string
{
    if ($sql === null || $sql === '') {
        return '—';
    }
    $ts = strtotime($sql . ' UTC');
    if ($ts === false) {
        return '—';
    }
    return $withTime ? gmdate('d/m/Y H\hi', $ts) . ' UTC' : gmdate('d/m/Y', $ts);
}

/** Token aléatoire hexadécimal ($bytes octets d'entropie). */
function random_hex(int $bytes): string
{
    return bin2hex(random_bytes($bytes));
}

/** Adresse IP du client (sans faire confiance aux en-têtes proxy). */
function client_ip(): string
{
    return substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45);
}

/** Normalise une adresse email (minuscules, espaces retirés). */
function normalize_email(string $email): string
{
    return strtolower(trim($email));
}

/* ------------------------------------------------------------------ CSRF */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = random_hex(20);
    }
    return $_SESSION['csrf'];
}

/** Champ caché à inclure dans chaque formulaire POST. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

/** À appeler en tête de chaque traitement POST. Interrompt si le jeton est invalide. */
function csrf_check(): void
{
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $sent)) {
        http_response_code(419);
        exit('Session expirée : rechargez la page puis réessayez.');
    }
}

/* ----------------------------------------------------------------- Flash */

/** Enregistre un message affiché sur la prochaine page ($type : ok|error|info). */
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/** Récupère puis efface les messages flash en attente. */
function flash_pull(): array
{
    $all = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $all;
}
