# OXCA MCP Hub

Hébergez vos propres **connecteurs MCP pour Claude** sur un simple hébergement
mutualisé **PHP / MySQL** — sans framework, sans Composer, sans Node, sans
aucun coût.

- 🔐 **Connexion sans mot de passe** : code à 6 chiffres + lien magique envoyés
  par email.
- 🧩 **MCP pré-codés** : les outils sont déjà écrits ; l'utilisateur ne
  renseigne que le strict nécessaire pour les rendre fonctionnels.
- 💼 **LinkedIn inclus** : publier des posts, commenter, réagir, supprimer,
  consulter les statistiques d'une page organisation — uniquement via les
  produits **gratuits** de l'API LinkedIn.
- 🤝 **Partage maîtrisé** : partagez un connecteur par simple adresse email ;
  chaque invité reçoit sa **propre URL révocable**, sans jamais voir vos
  secrets. Révoquez un accès ou supprimez le connecteur à tout moment.
- 🖥️ **Prêt pour Claude Code** : chaque connecteur expose un endpoint MCP
  (Streamable HTTP) utilisable comme connecteur personnalisé, en une commande.
- 🍎 **Design sobre et intemporel**, mode sombre automatique, animations
  discrètes, composants réutilisables (une seule feuille CSS pilotée par
  variables).

---

## Sommaire

1. [Prérequis](#prérequis)
2. [Installation en 5 minutes](#installation-en-5-minutes)
3. [Configuration email](#configuration-email)
4. [Créer votre app LinkedIn (gratuite)](#créer-votre-app-linkedin-gratuite)
5. [Brancher Claude Code](#brancher-claude-code)
6. [Partage et révocation](#partage-et-révocation)
7. [Outils LinkedIn disponibles](#outils-linkedin-disponibles)
8. [Sécurité](#sécurité)
9. [Structure du projet](#structure-du-projet)
10. [Ajouter un type de MCP](#ajouter-un-type-de-mcp)
11. [Dépannage](#dépannage)

---

## Prérequis

| Composant | Minimum |
|---|---|
| PHP | 8.1+ avec `pdo_mysql`, `openssl`, `curl` (présents sur la quasi-totalité des mutualisés) |
| Base de données | MySQL 5.7+ ou MariaDB 10.3+ |
| Serveur web | Apache (`.htaccess` fourni) ou équivalent |
| HTTPS | Fortement recommandé — **obligatoire** pour utiliser le connecteur depuis claude.ai |
| Email sortant | `mail()` PHP **ou** un compte SMTP (souvent fourni par l'hébergeur) |

Aucune dépendance externe : déposez les fichiers, c'est tout.

## Installation en 5 minutes

1. **Récupérez le code** — fork puis clone, ou téléchargement du ZIP — et
   déposez tout le contenu à la racine de votre site (FTP, SFTP, gestionnaire
   de fichiers…).

2. **Créez une base MySQL** depuis le panneau de votre hébergeur et notez
   l'hôte, le nom, l'utilisateur et le mot de passe.

3. **Créez la configuration** :

   ```bash
   cp config.sample.php config.php
   ```

   Renseignez `APP_URL` (l'adresse publique du site, sans slash final),
   les constantes `DB_*`, et `APP_KEY` :

   ```bash
   php -r "echo bin2hex(random_bytes(32));"   # génère une APP_KEY
   ```

   > Pas de terminal ? Laissez `APP_KEY` vide pour l'instant : à l'étape
   > suivante, `/install.php` détecte l'absence de clé et en génère une,
   > prête à coller dans `config.php`.

4. **Ouvrez `https://votre-site/install.php`** : l'installateur vérifie
   l'environnement puis crée les tables en un clic. Supprimez ensuite
   `install.php` du serveur.

5. **Connectez-vous** sur `https://votre-site/` avec votre adresse email —
   le code de connexion arrive dans votre boîte. C'est terminé.

## Configuration email

Le transport se choisit dans `config.php` via `MAIL_DRIVER` :

| Valeur | Usage |
|---|---|
| `mail` | Fonction `mail()` de PHP — fonctionne telle quelle chez la plupart des hébergeurs mutualisés (OVH, o2switch, Ionos…). |
| `smtp` | Client SMTP intégré (STARTTLS, SSL, AUTH LOGIN). Renseignez `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASS`, `SMTP_SECURE`. Recommandé si les emails `mail()` arrivent en spam. |
| `log` | **Développement uniquement.** N'envoie rien : les emails (codes et liens magiques) sont écrits en clair dans `storage/mail-<empreinte>.log` (nom dérivé d'`APP_KEY`, protégé par `.htaccess`). Ne l'utilisez jamais en production : quiconque lit ce fichier peut se connecter. |

Utilisez une adresse `MAIL_FROM` du même domaine que votre site pour une
meilleure délivrabilité (SPF/DKIM configurés chez l'hébergeur).

## Créer votre app LinkedIn (gratuite)

Chaque utilisateur crée sa propre app LinkedIn (2 minutes, gratuit), **ou**
l'administrateur de l'instance en déclare une pour tout le monde via
`LINKEDIN_DEFAULT_CLIENT_ID` / `LINKEDIN_DEFAULT_CLIENT_SECRET` dans
`config.php` — les utilisateurs n'ont alors plus rien à saisir.

1. Rendez-vous sur <https://developer.linkedin.com/> → **Create app**
   (une page LinkedIn — même personnelle d'entreprise — est demandée comme
   « app owner »).
2. Onglet **Products** : ajoutez les deux produits gratuits à validation
   immédiate :
   - **Sign In with LinkedIn using OpenID Connect** (identité) ;
   - **Share on LinkedIn** (publication).
3. Onglet **Auth** :
   - copiez le **Client ID** et le **Client Secret** dans la page du
     connecteur (ils ne sont demandés qu'une fois, le secret est chiffré) ;
   - dans **Authorized redirect URLs**, ajoutez l'URL affichée sur la page du
     connecteur : `https://votre-site/oauth-linkedin.php`.
4. De retour sur la page du connecteur : **Connecter LinkedIn** → LinkedIn
   demande votre accord → les outils sont opérationnels.

### Ce que LinkedIn autorise gratuitement (et ce qu'il ne permet pas)

- ✅ Publier, commenter, réagir, supprimer **au nom du profil connecté**
  (produit *Share on LinkedIn*, scope `w_member_social`).
- ✅ **Statistiques d'une page organisation** (impressions, clics, réactions,
  engagement, abonnés) : activez le « mode organisation » du connecteur.
  Nécessite le produit **Community Management API** — gratuit, mais soumis à
  une demande d'accès auprès de LinkedIn depuis l'onglet Products de votre app.
  Une fois le produit accordé, **reconnectez LinkedIn** : le connecteur demande
  alors les autorisations `r_organization_social`, `w_organization_social` et
  `rw_organization_admin` (cette dernière étant requise par LinkedIn pour les
  statistiques de reporting et le nombre d'abonnés). Vous devez être
  **administrateur** de la page concernée.
- ❌ LinkedIn n'expose **pas d'API de statistiques pour les posts d'un profil
  personnel** (quelle que soit l'app, gratuite ou non) — c'est une limite de
  LinkedIn, pas de ce projet.
- ⏳ Les tokens LinkedIn expirent après **60 jours** : un clic sur
  « Reconnecter » suffit (l'app vous prévient sur la page du connecteur).

## Brancher Claude Code

Sur la page du connecteur, section **Utiliser avec Claude**, copiez la
commande affichée. Le token est transmis dans un en-tête `Authorization`, il
n'apparaît donc pas dans les journaux d'accès du serveur :

```bash
claude mcp add --transport http linkedin https://votre-site/mcp.php \
  --header "Authorization: Bearer oxm_…"
```

Ou de façon déclarative dans le `.mcp.json` d'un projet :

```json
{
  "mcpServers": {
    "linkedin": {
      "type": "http",
      "url": "https://votre-site/mcp.php",
      "headers": { "Authorization": "Bearer oxm_…" }
    }
  }
}
```

Puis, dans Claude Code : *« Publie sur LinkedIn un post qui annonce … »*

Sur **claude.ai** (abonnements payants) : Paramètres → Connecteurs →
**Ajouter un connecteur personnalisé**. Ce type de client n'accepte qu'une
URL : dépliez « Client sans en-têtes personnalisés » sur la page du connecteur
et collez l'URL avec token intégré (`…/mcp.php?t=oxm_…`).

> Le token **vaut authentification**. Ne le publiez pas ; régénérez-le en un
> clic en cas de doute. Préférez la forme en-tête `Authorization` à l'URL avec
> token, qui peut être journalisée par le serveur ou les intermédiaires.

L'endpoint accepte aussi le token en `Authorization: Bearer oxm_…` ou en
suffixe de chemin (`/mcp.php/oxm_…`) selon vos préférences d'intégration.

## Partage et révocation

- **Partager** : page du connecteur → section Partage → saisissez l'adresse
  email. Si la personne n'a pas encore de compte, il est créé automatiquement
  (elle se connectera par email, comme vous). Elle reçoit une notification et
  retrouve le connecteur dans son espace, avec **sa propre URL d'endpoint**.
- **Ce que voit un invité** : le nom du connecteur, son état, son URL
  personnelle et les instructions Claude. **Jamais** vos Client ID/Secret ni
  votre token LinkedIn.
- **Révoquer** : bouton « Révoquer » en face de l'invité — son URL cesse de
  fonctionner immédiatement. L'invité peut aussi quitter le partage lui-même.
- **Supprimer le connecteur** : zone de danger → tous les accès, tokens et la
  connexion LinkedIn associés sont détruits.

## Outils LinkedIn disponibles

| Outil MCP | Description | Prérequis |
|---|---|---|
| `linkedin_create_post` | Publie un post (texte, lien avec titre/description, visibilité publique ou connexions, blocage du repartage) | Share on LinkedIn |
| `linkedin_delete_post` | Supprime un post | Share on LinkedIn |
| `linkedin_comment` | Commente un post | Share on LinkedIn |
| `linkedin_react` | Réagit à un post (like, bravo, soutien…) | Share on LinkedIn |
| `linkedin_get_profile` | Vérifie le profil connecté (nom, email, URN) | Sign In with LinkedIn |
| `linkedin_org_share_stats` | Impressions, clics, réactions, commentaires, partages, engagement — cumul ou par post | Mode organisation + Community Management API |
| `linkedin_org_follower_count` | Nombre d'abonnés de la page | Mode organisation + Community Management API |

## Sécurité

- **Sans mot de passe** : codes à 6 chiffres et liens magiques **hachés
  (SHA-256)** en base, expiration 15 min, usage unique, 5 essais maximum,
  5 demandes/heure par email ou IP.
- **Chiffrement au repos** : secrets OAuth, tokens LinkedIn et tokens
  d'endpoint sont chiffrés **AES-256-GCM** avec une clé dérivée d'`APP_KEY`
  (hors base de données). Une fuite de la base seule n'expose aucun secret.
- **Tokens d'endpoint** : 96 bits d'entropie minimum, recherchés par empreinte
  SHA-256, régénérables, révoqués instantanément avec le partage.
- **CSRF** : jeton de session vérifié sur chaque POST. Cookies `HttpOnly`,
  `SameSite=Lax`, `Secure` en HTTPS.
- **SQL** : 100 % requêtes préparées (PDO).
- `config.php` et les répertoires internes sont en plus protégés par
  `.htaccess`.

## Structure du projet

```
├── index.php               Accueil public
├── login.php               Connexion par code / lien magique
├── dashboard.php           Tableau de bord (connecteurs, catalogue, partages)
├── connector.php           Gestion d'un connecteur (réglages, partage, endpoint)
├── oauth-linkedin.php      Flux OAuth LinkedIn
├── mcp.php                 Endpoint MCP public (Streamable HTTP, JSON-RPC 2.0)
├── install.php             Installateur (à supprimer après usage)
├── config.sample.php       Modèle de configuration
├── schema.sql              Schéma MySQL
├── assets/
│   ├── css/app.css         Design system complet (variables, composants)
│   └── js/app.js           Interactions (copier, confirmation, code OTP)
└── app/                    Code interne (protégé, jamais servi)
    ├── bootstrap.php       Chargement commun + session
    ├── auth.php            Authentification sans mot de passe
    ├── models.php          Utilisateurs, configs, grants, partage
    ├── crypto.php          AES-256-GCM + empreintes de tokens
    ├── mail.php            mail() / SMTP intégré / journal
    ├── ui.php              Composants d'interface réutilisables
    ├── util.php, db.php    Aides transverses, PDO
    └── mcp/
        ├── server.php      Protocole MCP (initialize, tools/list, tools/call…)
        └── linkedin.php    Outils LinkedIn + client API + OAuth
```

## Ajouter un type de MCP

Le hub est conçu pour accueillir d'autres connecteurs pré-codés :

1. Créez `app/mcp/montype.php` avec trois fonctions :
   - `montype_tools(array $settings): array` — définitions des outils
     (nom, description, `inputSchema` JSON Schema) ;
   - `montype_call(array $config, string $name, array $args): array` —
     exécution ; levez `McpToolError` pour toute erreur « métier », renvoyez
     `mcp_tool_result($texte, $donnéesStructurées)` en succès ;
   - `montype_summary(array $settings): array` — état pour l'interface
     (`connected`, `expired`, …).
2. Chargez le fichier dans `app/bootstrap.php` (à côté de `linkedin.php`).
3. Déclarez le type dans `mcp_types()` (`app/models.php`) : libellé, phrase du
   catalogue, icône (`ui_icon`), noms des trois fonctions.
4. Si certains réglages sont sensibles, listez-les dans
   `config_sensitive_keys()` : ils seront chiffrés automatiquement.

Le catalogue, la création, le partage, les tokens et l'endpoint MCP
fonctionnent alors sans autre modification.

## Dépannage

**Erreur 500 dès l'accueil** — certains mutualisés interdisent des directives
`.htaccess` (`AllowOverride` restrictif). Supprimez la ligne `Options
-Indexes` du `.htaccess` racine ; les protections restantes suffisent
(`config.php` n'affiche rien par construction).

**Le code de connexion n'arrive pas** — passez `MAIL_DRIVER` à `'smtp'` avec
un compte email de votre hébergeur ; vérifiez le dossier spam ; en dev, mettez
`'log'` et lisez `storage/mail.log`.

**« Session expirée » sur chaque formulaire** — vérifiez qu'`APP_URL`
correspond exactement au domaine utilisé (avec/sans `www`), sinon le cookie de
session n'est pas partagé.

**LinkedIn : `unauthorized_scope_error`** — le produit *Share on LinkedIn*
(ou *Community Management API* en mode organisation) n'est pas activé sur
votre app LinkedIn : onglet Products → Request access.

**LinkedIn : `invalid redirect_uri`** — l'URL de redirection déclarée dans
l'app LinkedIn doit être exactement `APP_URL/oauth-linkedin.php` (HTTPS,
même domaine, pas de slash final surnuméraire).

**Claude Code ne voit pas les outils** — testez l'endpoint à la main :

```bash
curl -s -X POST "https://votre-site/mcp.php?t=oxm_…" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
```

Une réponse JSON avec la liste des outils doit s'afficher. Un code 401
signifie que le token a été révoqué ou régénéré.

**Version d'API LinkedIn périmée (erreur 426)** — mettez à jour
`LINKEDIN_API_VERSION` dans `config.php` (format `AAAAMM`, une version de
moins d'un an).

## Licence

[MIT](LICENSE) — utilisez, modifiez, redistribuez librement.
