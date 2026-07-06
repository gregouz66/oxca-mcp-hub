<?php
/** Déconnexion puis retour à l'accueil. */

require __DIR__ . '/app/bootstrap.php';

// Déconnexion uniquement via POST + CSRF : empêche qu'une page tierce force la
// déconnexion (logout CSRF) via un simple GET.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/dashboard.php');
}
csrf_check();

logout();
header('Location: ' . base_url('/'));
exit;
