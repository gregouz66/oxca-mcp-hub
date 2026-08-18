<?php
/**
 * Politique de confidentialité — page publique.
 *
 * C'est cette URL (https://votre-site/confidentialite.php) qu'il faut
 * renseigner comme « Privacy policy URL » dans votre app LinkedIn (portail
 * développeur, onglet Settings) et dans votre app Meta/Instagram (tableau de
 * bord, Paramètres de base). Meta exige qu'elle soit publiquement accessible,
 * lisible par ses robots d'indexation et non géobloquée.
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
      d'accès LinkedIn ; identifiants d'application Meta/Instagram (App ID,
      App Secret) ; jeton d'accès Instagram ; identifiant, nom d'utilisateur et
      type du compte Instagram connecté ; identifiant (URN), nom du profil LinkedIn connecté et
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

  <h2>5. Données Instagram</h2>
  <p>
    Lorsque vous connectez un compte Instagram professionnel, le service reçoit
    un jeton d'accès et les informations de profil de base (identifiant, nom
    d'utilisateur, type de compte). Ce jeton sert exclusivement à exécuter
    <strong>vos</strong> demandes : publier une image ou un carrousel, lire
    votre profil, votre quota de publication et vos publications récentes.
  </p>
  <p>
    Instagram ne reçoit pas les images&nbsp;: ce sont ses serveurs qui viennent
    les télécharger. Les images que vous transmettez sont donc déposées sur ce
    serveur, accessibles par une URL imprévisible (128&nbsp;bits d'aléa), le
    temps de la publication&nbsp;: elles sont <strong>supprimées
    automatiquement au plus tard 24&nbsp;heures</strong> après leur dépôt.
    Aucune image n'est conservée au-delà, ni analysée, ni réutilisée.
  </p>
  <p>
    Les données Instagram ne sont <strong>ni revendues, ni partagées avec des
    tiers, ni utilisées à des fins publicitaires ou d'entraînement</strong>.
    Vous pouvez révoquer l'accès à tout moment&nbsp;: bouton
    «&nbsp;Déconnecter&nbsp;» sur la page du connecteur, et/ou depuis
    l'application Instagram (Paramètres&nbsp;→&nbsp;Sécurité&nbsp;→&nbsp;Applications
    et sites web). Les jetons Instagram expirent automatiquement au bout de
    60&nbsp;jours sans utilisation.
  </p>

  <h2>6. Finalités et bases légales</h2>
  <ul>
    <li>Fourniture du service demandé (création de compte, connecteurs,
      exécution des outils) — exécution du contrat.</li>
    <li>Sécurité et prévention des abus (limitation de débit, journaux) —
      intérêt légitime.</li>
  </ul>

  <h2>7. Partage des données</h2>
  <p>
    Les données ne sont partagées avec personne, à l'exception&nbsp;: de
    LinkedIn (appels API nécessaires aux actions que vous demandez), de notre
    hébergeur Infomaniak Network SA (Suisse — pays reconnu adéquat par la
    Commission européenne), et des personnes avec lesquelles
    <strong>vous</strong> choisissez de partager un connecteur — celles-ci
    peuvent alors utiliser ses outils mais n'ont jamais accès à vos secrets
    (Client Secret, jetons).
  </p>

  <h2>8. Durées de conservation</h2>
  <ul>
    <li>Compte et connecteurs : tant que le compte est actif ; supprimés sur
      demande.</li>
    <li>Images déposées en vue d'une publication Instagram : conservées au
      maximum 24 heures sur le serveur, le temps qu'Instagram vienne les
      télécharger, puis supprimées automatiquement.</li>
    <li>Jeton Instagram : jusqu'à déconnexion, suppression du connecteur ou
      expiration (60 jours sans utilisation).</li>
    <li>Jeton LinkedIn : jusqu'à déconnexion, suppression du connecteur ou
      expiration (60 jours maximum).</li>
    <li>Codes et liens de connexion : 15 minutes (puis inutilisables) ;
      demandes purgées régulièrement.</li>
    <li>Suppression d'un connecteur : réglages, jetons et partages associés
      sont supprimés immédiatement et définitivement.</li>
  </ul>

  <h2>9. Cookies</h2>
  <p>
    Le service utilise un unique cookie technique de session
    (<code>oxcahub</code>), strictement nécessaire à l'authentification —
    exempté de consentement. Aucun cookie publicitaire, statistique ou tiers.
  </p>

  <h2>10. Sécurité</h2>
  <p>
    Connexions chiffrées (HTTPS), secrets chiffrés au repos (AES-256-GCM avec
    clé détenue hors base de données), codes de connexion hachés, jetons
    d'accès révocables et régénérables à tout moment, requêtes préparées
    contre l'injection SQL.
  </p>

  <h2>11. Vos droits</h2>
  <p>
    Conformément au RGPD, vous disposez des droits d'accès, de rectification,
    d'effacement, de limitation, de portabilité et d'opposition sur vos
    données. Exercez-les par email à
    <a href="mailto:oxca.fr@gmail.com">oxca.fr@gmail.com</a> — réponse sous un
    mois au plus. Vous pouvez également introduire une réclamation auprès de
    la CNIL (<a href="https://www.cnil.fr" target="_blank" rel="noopener">cnil.fr</a>).
  </p>

  <h2>12. Évolution de cette politique</h2>
  <p>
    Cette politique peut être mise à jour pour suivre l'évolution du service ;
    la date en tête de page fait foi. En cas de changement substantiel, les
    utilisateurs seront informés lors de leur connexion.
  </p>
</div>
<?php
ui_card_close();
ui_bottom();
