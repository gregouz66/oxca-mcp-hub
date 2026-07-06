<?php
/** Déconnexion puis retour à l'accueil. */

require __DIR__ . '/app/bootstrap.php';

logout();
header('Location: ' . base_url('/'));
exit;
