<?php
/**
 * OXCA MCP Hub — configuration.
 *
 * 1. Copiez ce fichier en `config.php` (à la racine, à côté d'index.php).
 * 2. Renseignez chaque valeur ci-dessous.
 * 3. Ouvrez /install.php dans votre navigateur pour créer les tables.
 */

/* ---------------------------------------------------------------- Général */

// URL publique de l'application, SANS slash final. HTTPS fortement recommandé
// (obligatoire pour utiliser le connecteur depuis claude.ai).
define('APP_URL', 'https://mcp.example.com');

define('APP_NAME', 'OXCA MCP Hub');

// Clé secrète servant à chiffrer les données sensibles en base (tokens,
// secrets OAuth). 64 caractères hexadécimaux. Pour en générer une :
//   php -r "echo bin2hex(random_bytes(32));"
// ⚠️  Ne la changez plus ensuite : les données chiffrées deviendraient illisibles.
define('APP_KEY', '');

/* ------------------------------------------------------- Base de données */

define('DB_HOST', 'localhost');
define('DB_PORT', 3306);
define('DB_NAME', 'oxca_mcp_hub');
define('DB_USER', '');
define('DB_PASS', '');

/* ------------------------------------------------------------------ Email */

// Transport d'envoi des codes de connexion :
//   'mail' — fonction mail() de PHP (fonctionne sur la plupart des mutualisés)
//   'smtp' — serveur SMTP (renseignez les constantes SMTP_* ci-dessous)
//   'log'  — DÉV UNIQUEMENT : n'envoie rien, écrit les emails (codes et liens)
//            dans storage/mail-<empreinte>.log. Ne pas utiliser en production.
define('MAIL_DRIVER', 'mail');

define('MAIL_FROM', 'no-reply@example.com');
define('MAIL_FROM_NAME', APP_NAME);

// Utilisé uniquement si MAIL_DRIVER = 'smtp'.
define('SMTP_HOST', '');
define('SMTP_PORT', 587);      // 587 avec STARTTLS, 465 avec SSL implicite
define('SMTP_USER', '');
define('SMTP_PASS', '');
define('SMTP_SECURE', 'tls');  // 'tls' (STARTTLS), 'ssl' (implicite) ou '' (aucun)

/* --------------------------------------------------------------- LinkedIn */

// Version de l'API LinkedIn (format AAAAMM). LinkedIn supporte chaque version
// environ un an : pensez à la mettre à jour de temps en temps.
define('LINKEDIN_API_VERSION', '202510');

// Optionnel : identifiants d'une app LinkedIn partagée par toute l'instance.
// Si renseignés, les utilisateurs n'ont plus rien à saisir : ils cliquent
// simplement sur « Connecter LinkedIn ». Sinon, chaque utilisateur renseigne
// le Client ID / Client Secret de sa propre app LinkedIn (gratuite).
define('LINKEDIN_DEFAULT_CLIENT_ID', '');
define('LINKEDIN_DEFAULT_CLIENT_SECRET', '');

/* -------------------------------------------------------------- Instagram */

// Version de l'API Graph utilisée pour Instagram. Meta maintient chaque version
// environ deux ans, puis bascule silencieusement les appels vers la plus
// ancienne encore valide : gardez cette valeur à jour.
define('INSTAGRAM_API_VERSION', 'v26.0');

// Optionnel : app Meta partagée par toute l'instance.
//
// ⚠️  À n'utiliser qu'en connaissance de cause. Tant que chaque utilisateur
// publie sur SON compte avec SA propre app Meta (dont il est administrateur),
// aucune revue n'est nécessaire : Meta accorde le « Standard Access »
// automatiquement. Dès qu'une app sert des personnes qui n'ont pas de rôle sur
// elle, il faut l'« Advanced Access » : App Review, vérification d'entreprise
// et captures vidéo par permission — plusieurs semaines de procédure.
define('INSTAGRAM_DEFAULT_APP_ID', '');
define('INSTAGRAM_DEFAULT_APP_SECRET', '');
