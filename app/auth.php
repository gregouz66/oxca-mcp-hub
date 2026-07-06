<?php
/**
 * Authentification sans mot de passe : un code à 6 chiffres et un lien
 * magique sont envoyés par email. Les deux sont stockés hachés, expirent
 * après 15 minutes et sont à usage unique.
 */

const LOGIN_TTL_MINUTES  = 15;
const LOGIN_MAX_ATTEMPTS = 5;   // essais de code par demande
const LOGIN_MAX_PER_HOUR = 5;   // demandes de connexion par email ou IP / heure

/** Utilisateur connecté (ligne `users`) ou null. */
function current_user(): ?array
{
    static $user = false;
    if ($user === false) {
        $uid  = $_SESSION['uid'] ?? null;
        $user = $uid ? user_by_id((int) $uid) : null;
    }
    return $user;
}

/** Redirige vers la page de connexion si personne n'est connecté. */
function require_login(): array
{
    $user = current_user();
    if ($user === null) {
        redirect('/login.php');
    }
    return $user;
}

/**
 * Crée une demande de connexion et envoie l'email (code + lien magique).
 * Retourne null en cas de succès, sinon un message d'erreur à afficher.
 */
function login_request(string $email): ?string
{
    $email = normalize_email($email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'Adresse email invalide.';
    }

    $since = gmdate('Y-m-d H:i:s', time() - 3600);
    $count = (int) q(
        'SELECT COUNT(*) c FROM login_tokens WHERE (email = ? OR ip = ?) AND created_at > ?',
        [$email, client_ip(), $since]
    )->fetch()['c'];
    if ($count >= LOGIN_MAX_PER_HOUR) {
        return 'Trop de demandes. Patientez une heure puis réessayez.';
    }

    $code      = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $selector  = random_hex(8);
    $validator = random_hex(24);

    q(
        'INSERT INTO login_tokens (email, code_hash, link_selector, link_hash, expires_at, created_at, ip)
         VALUES (?,?,?,?,?,?,?)',
        [
            $email,
            hash('sha256', $code),
            $selector,
            hash('sha256', $validator),
            gmdate('Y-m-d H:i:s', time() + LOGIN_TTL_MINUTES * 60),
            now(),
            client_ip(),
        ]
    );
    $_SESSION['pending_login'] = ['id' => (int) db()->lastInsertId(), 'email' => $email];

    $link = base_url('/login.php?lt=' . $selector . '.' . $validator);
    $html = mail_template('Votre code de connexion', '
        <p style="font-size:15px;color:#424245;line-height:1.5;margin:0 0 20px;">
          Saisissez ce code sur la page de connexion :</p>
        <p style="font-size:34px;font-weight:700;letter-spacing:10px;color:#1d1d1f;text-align:center;margin:0 0 24px;">' . e($code) . '</p>
        <p style="text-align:center;margin:0 0 24px;">
          <a href="' . e($link) . '" style="display:inline-block;background:#0071e3;color:#ffffff;text-decoration:none;font-size:15px;padding:12px 28px;border-radius:980px;">Ou connectez-vous en un clic</a></p>
        <p style="font-size:13px;color:#86868b;margin:0;">Ce code expire dans ' . LOGIN_TTL_MINUTES . ' minutes et ne peut servir qu\'une fois.</p>');
    $text = "Votre code de connexion : $code\n\n"
        . "Ou connectez-vous en un clic : $link\n\n"
        . 'Ce code expire dans ' . LOGIN_TTL_MINUTES . " minutes et ne peut servir qu'une fois.";

    if (!send_mail($email, 'Votre code de connexion — ' . APP_NAME, $html, $text)) {
        return "L'email n'a pas pu être envoyé. Vérifiez la configuration email (voir README).";
    }
    return null;
}

/**
 * Vérifie le code à 6 chiffres saisi (lié à la demande en session).
 * Retourne null si la connexion a réussi, sinon un message d'erreur.
 */
function login_verify_code(string $code): ?string
{
    $pending = $_SESSION['pending_login'] ?? null;
    if (!$pending) {
        return 'Demande introuvable : recommencez la connexion.';
    }

    $row = q('SELECT * FROM login_tokens WHERE id = ?', [$pending['id']])->fetch();
    $err = login_token_usable($row);
    if ($err !== null) {
        return $err;
    }

    q('UPDATE login_tokens SET attempts = attempts + 1 WHERE id = ?', [$row['id']]);
    if ($row['attempts'] + 1 > LOGIN_MAX_ATTEMPTS) {
        return 'Trop d\'essais : demandez un nouveau code.';
    }

    $code = preg_replace('/\D/', '', $code) ?? '';
    if (!hash_equals($row['code_hash'], hash('sha256', $code))) {
        return 'Code incorrect.';
    }

    login_consume($row);
    return null;
}

/**
 * Vérifie un lien magique `selector.validator` reçu par email.
 * Retourne null si la connexion a réussi, sinon un message d'erreur.
 */
function login_verify_link(string $lt): ?string
{
    $parts = explode('.', $lt, 2);
    if (count($parts) !== 2 || !ctype_xdigit($parts[0]) || !ctype_xdigit($parts[1])) {
        return 'Lien invalide.';
    }

    $row = q('SELECT * FROM login_tokens WHERE link_selector = ?', [$parts[0]])->fetch();
    $err = login_token_usable($row);
    if ($err !== null) {
        return $err;
    }
    if (!hash_equals($row['link_hash'], hash('sha256', $parts[1]))) {
        return 'Lien invalide.';
    }

    login_consume($row);
    return null;
}

/** Contrôles communs (existence, expiration, usage unique). */
function login_token_usable(mixed $row): ?string
{
    if (!$row) {
        return 'Demande introuvable : recommencez la connexion.';
    }
    if ($row['consumed_at'] !== null) {
        return 'Ce code a déjà été utilisé : demandez-en un nouveau.';
    }
    if ($row['expires_at'] < now()) {
        return 'Code expiré : demandez-en un nouveau.';
    }
    return null;
}

/** Marque la demande consommée puis ouvre la session utilisateur. */
function login_consume(array $row): void
{
    q('UPDATE login_tokens SET consumed_at = ? WHERE id = ?', [now(), $row['id']]);
    unset($_SESSION['pending_login']);

    $user = user_by_email($row['email']) ?? user_create($row['email']);
    session_regenerate_id(true);
    $_SESSION['uid'] = (int) $user['id'];
    q('UPDATE users SET last_login_at = ? WHERE id = ?', [now(), $user['id']]);
}

/** Déconnexion complète. */
function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}
