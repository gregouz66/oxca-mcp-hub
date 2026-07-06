<?php
/**
 * Politique de confidentialité — page publique.
 *
 * C'est cette URL (https://votre-site/confidentialite.php) qu'il faut
 * renseigner comme « Privacy policy URL » dans votre app LinkedIn
 * (portail développeur, onglet Settings).
 *
 * ⚠️ Si vous forkez ce projet : remplacez l'identité du responsable de
 * traitement et l'hébergeur par les vôtres avant toute mise en ligne.
 */

require __DIR__ . '/app/bootstrap.php';

ui_top('Politique de confidentialité', current_user());
ui_page_header('Politique de confidentialité', 'Dernière mise à jour : ' . format_date(now()));

ui_card_open();
?>
<div class="prose">
  <h2>1. Responsable du traitement</h2>
  <p>
    <strong>OXCA</strong>, SAS au capital de 900&nbsp;€, 35&nbsp;rue Jean Bouin,
    66280&nbsp;Saleilles, France — SIRET 894&nbsp;325&nbsp;414&nbsp;00015 —
    Email : <a href="mailto:oxca.fr@gmail.com">oxca.fr@gmail.com</a>.
  </p>

  <h2>2. Ce que fait le service</h2>
  <p>
    <?= e(APP_NAME) ?> permet de configurer des connecteurs MCP (dont un
    connecteur LinkedIn) et de les utiliser depuis les produits Claude. Les
    actions sur les services tiers (publier un post, lire des statistiques…)
    ne sont réalisées <strong>qu'à la demande de l'utilisateur</strong>, via
    les connecteurs qu'il a lui-même configurés et connectés.
  </p>

  <h2>3. Données collectées</h2>
  <ul>
    <li><strong>Compte</strong> : adresse email (identifiant de connexion, sans
      mot de passe), dates de création et de dernière connexion.</li>
    <li><strong>Connexion</strong> : codes de connexion et liens magiques
      (stockés hachés en SHA-256, valables 15 minutes, à usage unique) et
      adresse IP de la demande (limitation anti-abus, conservée avec la
      demande).</li>
    <li><strong>Connecteurs</strong> : nom et réglages du connecteur ;
      identifiants d'application LinkedIn (Client ID, Client Secret) ; jeton
      d'accès LinkedIn ; identifiant (URN), nom du profil LinkedIn connecté et
      autorisations accordées ; identifiant de page organisation le cas
      échéant. Le Client Secret, le jeton LinkedIn et les jetons d'accès aux
      connecteurs sont chiffrés au repos (AES-256-GCM).</li>
    <li><strong>Journaux techniques</strong> : journaux standards du serveur
      web tenus par l'hébergeur (adresses IP, URL, horodatages).</li>
  </ul>
  <p>Aucune donnée n'est collectée à des fins publicitaires. Aucun traceur tiers.</p>

  <h2>4. Données LinkedIn</h2>
  <p>
    Lorsque vous connectez LinkedIn, le service reçoit un jeton d'accès et les
    informations de profil de base (identifiant, nom — et email selon les
    autorisations accordées). Ce jeton sert exclusivement à exécuter
    <strong>vos</strong> demandes : publier, commenter, réagir, supprimer un
    post, consulter les statistiques de vos posts ou de votre page. Les
    données LinkedIn ne sont <strong>ni revendues, ni partagées avec des
    tiers, ni utilisées à des fins publicitaires ou d'entraînement</strong> ;
    elles ne sont pas conservées au-delà de ce qui est nécessaire à
    l'exécution de la demande (les statistiques et contenus lus sont
    retransmis à l'utilisateur sans être stockés par le service).
  </p>
  <p>
    Vous pouvez révoquer l'accès à tout moment : bouton «&nbsp;Déconnecter&nbsp;»
    sur la page du connecteur, et/ou depuis votre compte LinkedIn
    (Préférences&nbsp;→&nbsp;Confidentialité des données&nbsp;→&nbsp;Autres applications —
    <a href="https://www.linkedin.com/mypreferences/d/categories/permitted-services" target="_blank" rel="noopener">services autorisés</a>).
    Les jetons LinkedIn expirent en outre automatiquement au bout de 60 jours.
  </p>

  <h2>5. Finalités et bases légales</h2>
  <ul>
    <li>Fourniture du service demandé (création de compte, connecteurs,
      exécution des outils) — exécution du contrat.</li>
    <li>Sécurité et prévention des abus (limitation de débit, journaux) —
      intérêt légitime.</li>
  </ul>

  <h2>6. Partage des données</h2>
  <p>
    Les données ne sont partagées avec personne, à l'exception&nbsp;: de
    LinkedIn (appels API nécessaires aux actions que vous demandez), de notre
    hébergeur Infomaniak Network SA (Suisse — pays reconnu adéquat par la
    Commission européenne), et des personnes avec lesquelles
    <strong>vous</strong> choisissez de partager un connecteur — celles-ci
    peuvent alors utiliser ses outils mais n'ont jamais accès à vos secrets
    (Client Secret, jetons).
  </p>

  <h2>7. Durées de conservation</h2>
  <ul>
    <li>Compte et connecteurs : tant que le compte est actif ; supprimés sur
      demande.</li>
    <li>Jeton LinkedIn : jusqu'à déconnexion, suppression du connecteur ou
      expiration (60 jours maximum).</li>
    <li>Codes et liens de connexion : 15 minutes (puis inutilisables) ;
      demandes purgées régulièrement.</li>
    <li>Suppression d'un connecteur : réglages, jetons et partages associés
      sont supprimés immédiatement et définitivement.</li>
  </ul>

  <h2>8. Cookies</h2>
  <p>
    Le service utilise un unique cookie technique de session
    (<code>oxcahub</code>), strictement nécessaire à l'authentification —
    exempté de consentement. Aucun cookie publicitaire, statistique ou tiers.
  </p>

  <h2>9. Sécurité</h2>
  <p>
    Connexions chiffrées (HTTPS), secrets chiffrés au repos (AES-256-GCM avec
    clé détenue hors base de données), codes de connexion hachés, jetons
    d'accès révocables et régénérables à tout moment, requêtes préparées
    contre l'injection SQL.
  </p>

  <h2>10. Vos droits</h2>
  <p>
    Conformément au RGPD, vous disposez des droits d'accès, de rectification,
    d'effacement, de limitation, de portabilité et d'opposition sur vos
    données. Exercez-les par email à
    <a href="mailto:oxca.fr@gmail.com">oxca.fr@gmail.com</a> — réponse sous un
    mois au plus. Vous pouvez également introduire une réclamation auprès de
    la CNIL (<a href="https://www.cnil.fr" target="_blank" rel="noopener">cnil.fr</a>).
  </p>

  <h2>11. Évolution de cette politique</h2>
  <p>
    Cette politique peut être mise à jour pour suivre l'évolution du service ;
    la date en tête de page fait foi. En cas de changement substantiel, les
    utilisateurs seront informés lors de leur connexion.
  </p>
</div>
<?php
ui_card_close();
ui_bottom();
