<?php
/**
 * Connexion sans mot de passe.
 *   1. L'utilisateur saisit son email → envoi d'un code + lien magique.
 *   2. Il saisit le code (ou clique le lien) → session ouverte.
 */

require __DIR__ . '/app/bootstrap.php';

if (current_user()) {
    redirect('/dashboard.php');
}

$error = null;
$step  = isset($_SESSION['pending_login']) ? 'code' : 'email';

// Lien magique reçu par email : on ne connecte JAMAIS sur un simple GET (sinon
// un lien magique de l'attaquant, cliqué par une victime déconnectée, la
// connecterait dans le compte de l'attaquant — login CSRF). On affiche une
// page de confirmation dont le POST est protégé par un jeton CSRF de session.
$linkToken = null;
if (isset($_GET['lt']) && is_string($_GET['lt']) && preg_match('/^[0-9a-f]{16}\.[0-9a-f]{48}$/', $_GET['lt'])) {
    $linkToken = $_GET['lt'];
    $step = 'confirm';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'send') {
        $error = login_request((string) ($_POST['email'] ?? ''));
        $step  = $error === null ? 'code' : 'email';
    } elseif ($action === 'verify') {
        $error = login_verify_code((string) ($_POST['code'] ?? ''));
        if ($error === null) {
            flash('ok', 'Vous êtes connecté.');
            redirect('/dashboard.php');
        }
        $step = isset($_SESSION['pending_login']) ? 'code' : 'email';
    } elseif ($action === 'confirm_link') {
        $error = login_verify_link((string) ($_POST['lt'] ?? ''));
        if ($error === null) {
            flash('ok', 'Vous êtes connecté.');
            redirect('/dashboard.php');
        }
        $step = 'email';
    } elseif ($action === 'restart') {
        unset($_SESSION['pending_login']);
        $step = 'email';
    }
}

ui_top('Connexion');

echo '<div style="max-width:400px;margin:6vh auto 0">';
ui_card_open('', '', 0);

if ($error !== null) {
    echo '<div class="flash flash-error" role="alert">' . ui_icon('alert') . '<span>' . e($error) . '</span></div>';
}

if ($step === 'confirm') {
    echo '<header class="card-head"><h2>Confirmer la connexion</h2>'
        . '<p class="muted">Cliquez pour vous connecter à ' . e(APP_NAME) . ' depuis ce navigateur.</p></header>';
    echo '<form method="post">' . csrf_field()
        . '<input type="hidden" name="action" value="confirm_link">'
        . '<input type="hidden" name="lt" value="' . e($linkToken) . '">'
        . '<button type="submit" class="btn btn-primary btn-block" autofocus>Me connecter</button></form>';
} elseif ($step === 'email') {
    echo '<header class="card-head"><h2>Connexion</h2>'
        . '<p class="muted">Pas de mot de passe : recevez un code par email.</p></header>';
    echo '<form method="post">' . csrf_field()
        . '<input type="hidden" name="action" value="send">';
    ui_field([
        'label' => 'Adresse email', 'name' => 'email', 'type' => 'email',
        'placeholder' => 'vous@exemple.com', 'required' => true, 'autofocus' => true,
        'autocomplete' => 'email',
    ]);
    echo '<button type="submit" class="btn btn-primary btn-block">Recevoir mon code</button></form>';
} else {
    $pendingEmail = $_SESSION['pending_login']['email'] ?? '';
    echo '<header class="card-head"><h2>Vérifiez vos emails</h2>'
        . '<p class="muted">Code envoyé à <strong>' . e($pendingEmail) . '</strong>. Vous pouvez aussi cliquer sur le lien contenu dans l\'email.</p></header>';
    echo '<form method="post">' . csrf_field()
        . '<input type="hidden" name="action" value="verify">';
    ui_field([
        'label' => 'Code à 6 chiffres', 'name' => 'code', 'class' => 'input-code',
        'placeholder' => '••••••', 'required' => true, 'autofocus' => true,
        'inputmode' => 'numeric', 'maxlength' => 6, 'autocomplete' => 'one-time-code',
    ]);
    echo '<button type="submit" class="btn btn-primary btn-block">Se connecter</button></form>';
    echo '<form method="post" style="margin-top:12px;text-align:center">' . csrf_field()
        . '<input type="hidden" name="action" value="restart">'
        . '<button type="submit" class="btn btn-ghost btn-sm">Utiliser une autre adresse</button></form>';
}

ui_card_close();
echo '</div>';
ui_bottom();
