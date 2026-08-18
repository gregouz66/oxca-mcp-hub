# OXCA MCP Hub

Hébergez vos propres **connecteurs MCP pour Claude** sur un simple hébergement
mutualisé **PHP / MySQL** — sans framework, sans Composer, sans Node, sans
aucun coût.

- 🔐 **Connexion sans mot de passe** : code à 6 chiffres + lien magique envoyés
  par email.
- 🧩 **MCP pré-codés** : les outils sont déjà écrits ; l'utilisateur ne
  renseigne que le strict nécessaire pour les rendre fonctionnels.
- 💼 **LinkedIn inclus** : publier des posts (texte, lien, **carrousel PDF**),
  commenter, réagir, supprimer, consulter les statistiques d'une page
  organisation — uniquement via les produits **gratuits** de l'API LinkedIn.
- 📸 **Instagram inclus** : publier une **image** ou un **carrousel** (2 à 10
  images) sur un compte professionnel, avec conversion automatique au format
  exigé par Instagram, suivi du quota et reprise après interruption. Sans App
  Review ni vérification d'entreprise tant que vous publiez sur vos comptes.
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
8. [Créer votre app Instagram (gratuite)](#créer-votre-app-instagram-gratuite)
9. [Outils Instagram disponibles](#outils-instagram-disponibles)
10. [Documentation des outils d'un connecteur](#documentation-des-outils-dun-connecteur)
11. [Sécurité](#sécurité)
12. [Structure du projet](#structure-du-projet)
13. [Ajouter un type de MCP](#ajouter-un-type-de-mcp)
14. [Tests](#tests)
15. [Dépannage](#dépannage)

---

## Prérequis

| Composant | Minimum |
|---|---|
| PHP | 8.1+ avec `pdo_mysql`, `openssl`, `curl` (présents sur la quasi-totalité des mutualisés) ; `gd` recommandé pour Instagram (conversion des images) |
| Base de données | MySQL 5.7+ ou MariaDB 10.3+ |
| Serveur web | Apache (`.htaccess` fourni) ou équivalent |
| HTTPS | Fortement recommandé — **obligatoire** pour utiliser le connecteur depuis claude.ai |
| Email sortant | `mail()` PHP **ou** un compte SMTP (souvent fourni par l'hébergeur) |
| `storage/` inscriptible | Requis pour Instagram : les images à publier y sont déposées le temps qu'Instagram vienne les télécharger |

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

> 📄 Le hub inclut des pages **Mentions légales** (`mentions-legales.php`) et
> **Politique de confidentialité** (`confidentialite.php`), liées dans le pied
> de page. La seconde sert d'URL de politique de confidentialité pour votre
> app LinkedIn. Si vous forkez ce projet, **remplacez l'identité de l'éditeur
> et l'hébergeur** dans ces deux fichiers par les vôtres.

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

Chaque utilisateur crée sa propre app LinkedIn (gratuit), **ou**
l'administrateur de l'instance en déclare une pour tout le monde via
`LINKEDIN_DEFAULT_CLIENT_ID` / `LINKEDIN_DEFAULT_CLIENT_SECRET` dans
`config.php` — les utilisateurs n'ont alors plus rien à saisir.

Le connecteur propose **deux types d'app** (réglage sur la page du
connecteur), qui correspondent aux deux offres gratuites de LinkedIn :

| | Type « Profil » (défaut) | Type « Community Management API » |
|---|---|---|
| Produits LinkedIn | *Sign In with LinkedIn using OpenID Connect* + *Share on LinkedIn* | *Community Management API* **seule** |
| Activation | Immédiate (self-serve) | Sur demande (formulaire, review LinkedIn de quelques jours) |
| Publier au nom du profil | ✅ | ✅ |
| Publier au nom d'une page entreprise | ❌ | ✅ (`author: organization`, être admin de la page) |
| Statistiques de vos posts personnels | ❌ | ✅ (impressions, membres atteints, réactions, commentaires, repartages) |
| Statistiques et abonnés de la page | ❌ | ✅ |

> ⚠️ **Règle LinkedIn** : la Community Management API doit être **le seul
> produit** de l'app. Si votre app a déjà d'autres produits, LinkedIn
> affiche *« This API product requires that it be the only product on the
> application »* — créez alors une **seconde app dédiée** et demandez-y ce
> produit. Un compte LinkedIn peut avoir plusieurs apps ; dans le hub, vous
> pouvez soit basculer un connecteur existant sur le type Community
> Management (avec les identifiants de la nouvelle app), soit créer un
> second connecteur.

Mise en place (identique pour les deux types) :

1. Rendez-vous sur <https://developer.linkedin.com/> → **Create app**
   (une page LinkedIn — même personnelle d'entreprise — est demandée comme
   « app owner »). Dans le champ **Privacy policy URL** (création puis onglet
   Settings), renseignez la page fournie par le hub :
   `https://votre-site/confidentialite.php`.
2. Onglet **Products** : ajoutez le ou les produits du type choisi
   (tableau ci-dessus).
3. Onglet **Auth** :
   - copiez le **Client ID** et le **Client Secret** dans la page du
     connecteur (ils ne sont demandés qu'une fois, le secret est chiffré) ;
   - dans **Authorized redirect URLs**, ajoutez l'URL affichée sur la page du
     connecteur : `https://votre-site/oauth-linkedin.php`.
4. De retour sur la page du connecteur : choisissez le **type d'app**,
   enregistrez, puis **Connecter LinkedIn** → LinkedIn demande votre accord →
   les outils sont opérationnels (bouton « Tester la connexion » pour
   vérifier).

### Bon à savoir

- Sans type Community Management, les posts partent toujours au nom du
  **profil**, jamais d'une page — c'est une limite LinkedIn, pas du hub.
- Les statistiques de posts **personnels** ne sont disponibles que via la
  Community Management API (endpoint `memberCreatorPostAnalytics`, versions
  d'API ≥ 202506).
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

| Outil MCP | Description | Type d'app requis |
|---|---|---|
| `linkedin_create_post` | Publie un post (texte, lien avec titre/description, visibilité, blocage du repartage). `author: member` (défaut, profil connecté) ou `author: organization` (au nom de la page entreprise configurée) | Les deux ; `author: organization` → Community Management |
| `linkedin_create_document_post` | Publie un post avec un **document en pièce jointe** (PDF, PPT, PPTX, DOC, DOCX), affiché en **carrousel swipable**. Mêmes options d'auteur et de visibilité que `linkedin_create_post` | Les deux ; `author: organization` → Community Management |
| `linkedin_delete_post` | Supprime un post | Les deux |
| `linkedin_comment` | Commente un post | Les deux |
| `linkedin_react` | Réagit à un post (like, bravo, soutien…) | Les deux |
| `linkedin_get_profile` | Vérifie le profil connecté (nom, email, URN) | Les deux |
| `linkedin_my_post_stats` | Statistiques de **vos posts personnels** : impressions, membres atteints, réactions, commentaires, repartages — cumul ou par post, période optionnelle | Community Management |
| `linkedin_org_share_stats` | Statistiques de la page : impressions, clics, réactions, commentaires, partages, engagement — cumul ou par post | Community Management + page renseignée |
| `linkedin_org_follower_count` | Nombre d'abonnés de la page | Community Management + page renseignée |

### Publier un carrousel (`linkedin_create_document_post`)

LinkedIn appelle « document post » ce que le fil affiche comme un carrousel
swipable. Le hub enchaîne pour vous les trois appels de l'[API Documents](https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/documents-api)
(réservation de l'upload → envoi du binaire → publication), en attendant que
LinkedIn ait fini de traiter le fichier avant de publier.

Le connecteur étant **hébergé à distance**, il ne peut pas lire un chemin de
fichier de votre machine : le document se transmet de deux façons.

| Paramètre | Quand l'utiliser |
|---|---|
| `document_url` | Le fichier est déjà en ligne (URL publique `http(s)`). Le hub le télécharge. **À privilégier** au-delà de quelques Mo. |
| `document_base64` + `filename` | Le fichier est local : Claude le lit et l'encode en base64. Pratique, mais la requête grossit d'environ 33 % — attention aux limites `post_max_size` / `memory_limit` de votre hébergement. |

- Formats acceptés : **PDF** (recommandé), PPT, PPTX, DOC, DOCX.
  Limites LinkedIn : **100 Mo** et **300 pages**.
- `title` fixe le libellé affiché sous le carrousel (défaut : le nom du fichier).
- Aucune autorisation supplémentaire : `w_member_social` couvre les documents
  d'un profil, `w_organization_social` ceux d'une page — les scopes déjà
  demandés par `linkedin_create_post`.
- Une URL interne ou privée (`localhost`, `10.0.0.0/8`, `169.254.169.254`…)
  est refusée : le connecteur ne sert pas de relais vers le réseau de
  l'hébergement.

## Créer votre app Instagram (gratuite)

### Le prérequis qui n'est pas négociable

**Instagram ne permet de publier que depuis un compte professionnel** —
Entreprise ou Créateur. Un compte personnel n'a *aucune* API de publication :
la seule qui les touchait, *Basic Display*, a été arrêtée le 4 décembre 2024,
et elle était de toute façon en lecture seule.

La bascule se fait dans l'application Instagram — **Paramètres → Pour les
professionnels → Type de compte et outils**. Elle est gratuite, réversible, et
conserve vos abonnés et vos publications. Une contrepartie réelle : **le compte
devient public** (le réglage « compte privé » est désactivé pour les comptes
professionnels).

| | Entreprise | Créateur |
|---|---|---|
| Image et carrousel dans le fil | ✅ | ✅ |
| Stories par API | ✅ | ❌ |
| Tags produits | ✅ | ❌ |

Aucune page Facebook n'est nécessaire : le connecteur utilise
« Instagram API with Instagram Login ».

### Les étapes, une fois

1. Sur [developers.facebook.com](https://developers.facebook.com/apps/),
   **créez une app de type « Business »**. Si votre app existante n'est pas de
   ce type, il faut en créer une nouvelle : le type n'est pas modifiable.
2. Dans l'app : **Instagram → API setup with Instagram business login**.
3. Ouvrez **Business login settings** et relevez l'**Instagram App ID** et
   l'**Instagram App Secret**. ⚠️ Ce ne sont **pas** l'App ID / App Secret
   *Facebook* affichés ailleurs dans le tableau de bord — c'est le piège
   d'intégration le plus fréquent.
4. Déclarez l'**OAuth Redirect URI** affichée sur la page du connecteur, soit
   `https://votre-site/oauth-instagram.php`. Meta ajoute parfois un slash final
   à l'URL enregistrée : vérifiez-la caractère pour caractère après validation.
5. Renseignez la **Privacy policy URL** :
   `https://votre-site/confidentialite.php`.
6. Dans le hub : créez un connecteur Instagram, collez l'App ID et l'App
   Secret, enregistrez, puis cliquez **Connecter Instagram**.

### Le raccourci : sans OAuth du tout

Pour publier sur **votre propre** compte, vous pouvez sauter entièrement le
flux OAuth. Dans **API setup with Instagram business login**, le bouton
**« Generate token »** délivre directement un jeton valable **60 jours**.
Collez-le dans le champ « Token d'accès collé à la main » des réglages du
connecteur : il est validé immédiatement et le connecteur devient opérationnel.

### App Review : pourquoi vous n'en avez pas besoin

Meta accorde le **Standard Access** automatiquement aux apps de type Business,
et le Standard Access suffit dès lors que **les personnes qui utilisent l'app
ont un rôle sur cette app**. En pratique : si chacun crée sa propre app Meta et
publie sur son propre compte, il n'y a **ni App Review, ni vérification
d'entreprise, ni Data Use Checkup**.

C'est exactement pour cette raison que `INSTAGRAM_DEFAULT_APP_ID` /
`INSTAGRAM_DEFAULT_APP_SECRET` (une app partagée par toute l'instance) sont
**vides par défaut** : les activer ferait basculer l'instance en usage
multi-utilisateurs, donc en **Advanced Access** — App Review, vérification
d'entreprise et captures vidéo par autorisation, soit plusieurs semaines.

### Le point technique à connaître

**Instagram ne reçoit pas vos images : ce sont ses serveurs qui viennent les
télécharger.** Les images transmises par Claude sont donc déposées sur votre
serveur et servies par `media.php` derrière une URL imprévisible, puis
supprimées automatiquement (24 h au plus).

Il faut donc que votre site soit **publiquement joignable en HTTPS**, sans
redirection ni protection anti-robot sur `/media.php`. Le bouton **« Tester la
publication média »**, sur la page du connecteur, vérifie tout cela en un clic
et nomme précisément ce qui bloque.

## Outils Instagram disponibles

| Outil | Ce qu'il fait |
|---|---|
| `instagram_publish_image` | Publie une image dans le fil, avec légende, texte alternatif, comptes identifiés, co-auteurs et lieu. |
| `instagram_publish_carousel` | Publie un carrousel de 2 à 10 images. |
| `instagram_publish_container` | Termine une publication interrompue (voir ci-dessous). |
| `instagram_get_profile` | Compte connecté : nom d'utilisateur, type, abonnés, publications. |
| `instagram_publishing_limit` | Quota de publication consommé et restant sur 24 h. |
| `instagram_list_media` | Publications récentes, avec leur lien public. |

### Les images sont converties pour vous

Instagram **rejette** — sans recadrer — toute image hors de ses clous. Le
connecteur normalise donc systématiquement avant l'envoi :

| Contrainte Instagram | Ce que fait le connecteur |
|---|---|
| JPEG uniquement | Convertit PNG, WebP, etc. |
| 8 Mo maximum | Compresse en qualité dégressive, puis réduit si nécessaire |
| 320 à 1440 px de large | Redimensionne dans les bornes |
| Rapport entre 4:5 et 1.91:1 | **Refuse** en expliquant — ou complète par des marges avec `fit: "pad"` |
| Orientation EXIF | Redresse les photos prises au téléphone |

Les images sont fournies soit par `image_url` (URL publique directe), soit par
`image_base64`. Avec une URL, le connecteur ne ré-héberge l'image que si elle
n'est pas conforme (`stage: "auto"`, par défaut).

> **Dimensions des images.** Une image est refusée au-delà de
> **32 mégapixels**, quel que soit le poids du fichier. Ce n'est pas une
> coquetterie : un JPEG uni de 4 Mo peut couvrir 256 mégapixels et occuper
> **1 Go de mémoire** une fois décodé — mesuré à environ 4 Mo par mégapixel,
> alloués par GD donc **hors du `memory_limit` de PHP**, qui ne les plafonne
> pas. Sans cette borne, le processus se ferait tuer par le système au lieu
> d'échouer proprement. La limite laisse passer toutes les photos d'appareils
> courants (24 Mpx sur un capteur haut de gamme).

> **Poids des envois en base64.** Décoder puis décompresser une image coûte
> plusieurs fois sa taille en mémoire, et un mutualisé plafonne souvent à
> 128 Mo. Le connecteur refuse donc, avec un message explicite, au-delà de
> ~18 Mo par image et ~32 Mo pour un carrousel entier — plutôt que de laisser
> PHP s'arrêter en cours de route et renvoyer une réponse tronquée. Un
> carrousel de dix photos ordinaires consomme environ 18 Mo : la limite ne se
> rencontre qu'avec des images non redimensionnées. Au-delà, passez par
> `image_url` : Instagram télécharge alors directement, sans passer par la
> mémoire du serveur.

### Publier un carrousel

```
Publie un carrousel Instagram avec ces trois images et la légende
« Trois vues du même endroit ».
```

Claude appelle `instagram_publish_carousel`. Trois points à connaître :

- **Toutes les images sont recadrées d'après la première** — c'est elle qui
  fixe le format du carrousel.
- La légende porte sur le carrousel entier, jamais sur une image : Instagram
  *ignore silencieusement* une légende posée sur un élément, le connecteur la
  refuse donc explicitement.
- Un carrousel ne compte que pour **une seule publication** dans le quota.

### Si la publication est interrompue

Préparer dix images peut dépasser le temps d'exécution alloué par un
hébergement mutualisé. Dans ce cas **rien n'est perdu** : les conteneurs créés
chez Instagram restent valables 24 h, et le message d'erreur donne la commande
de reprise exacte (`instagram_publish_container` avec le `creation_id`, ou
`instagram_publish_carousel` avec les `children` déjà prêts).

### Le jeton se renouvelle tout seul

Un jeton Instagram vaut 60 jours et **meurt définitivement** s'il n'est pas
renouvelé à temps. Comme un mutualisé ne garantit pas de tâche planifiée, le
connecteur le renouvelle **à l'usage** : dès qu'il a plus de 24 h (condition de
Meta) et qu'il expire dans moins de 10 jours. Tant que le connecteur sert au
moins une fois tous les 60 jours, il ne se déconnecte jamais.

## Documentation des outils d'un connecteur

Chaque connecteur expose sa propre référence, à jour par construction puisqu'elle
est lue directement dans le code des outils : bouton **« Outils exposés »** sur la
page du connecteur, ou `tools.php` en direct.

Elle liste **tout le catalogue du type de MCP**, pas seulement ce qui est actif :
un outil non exposé apparaît avec la mention *Non exposé* et **ce qu'il faut faire
pour l'activer** (passer le connecteur en Community Management API, renseigner la
page organisation…). Pour chaque outil : les arguments avec leur type, leur
caractère requis ou facultatif et leurs valeurs autorisées ; les données
renvoyées dans `structuredContent` ; et les erreurs possibles.

| Accès | URL |
|---|---|
| Depuis votre espace | `/tools.php?id=<id du connecteur>` |
| Avec un token d'endpoint | `/tools.php?t=oxm_…` |
| En JSON | ajoutez `&format=json`, ou envoyez `Accept: application/json` |

```bash
curl -s -H "Authorization: Bearer oxm_…" "https://votre-site/tools.php?format=json"
```

Le JSON contient le connecteur et son état, les coordonnées de l'endpoint MCP
(versions de protocole et méthodes acceptées), le catalogue complet des outils
(`input_schema`, `output_schema`, `available`, `requires`, `errors`) et les
erreurs communes. Les trois formes de token de `mcp.php` sont acceptées
(`?t=`, `/tools.php/oxm_…`, en-tête `Authorization`). Aucun secret n'y figure :
ni Client ID/Secret, ni token LinkedIn.

> Les mêmes schémas alimentent `tools/list` sur l'endpoint MCP : les outils y
> déclarent désormais un `outputSchema`, ce qui permet à Claude d'exploiter les
> données structurées sans les redeviner depuis le texte.

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
├── tools.php               Documentation des outils d'un connecteur (HTML + JSON)
├── oauth-linkedin.php      Flux OAuth LinkedIn
├── oauth-instagram.php     Flux OAuth Instagram (Business Login)
├── mcp.php                 Endpoint MCP public (Streamable HTTP, JSON-RPC 2.0)
├── media.php               Service public des images à publier (Instagram)
├── mentions-legales.php    Mentions légales (⚠️ adaptez l'identité si vous forkez)
├── confidentialite.php     Politique de confidentialité — URL à donner à LinkedIn et à Meta
├── install.php             Installateur (à supprimer après usage)
├── config.sample.php       Modèle de configuration
├── schema.sql              Schéma MySQL
├── assets/
│   ├── css/app.css         Design system complet (variables, composants)
│   └── js/app.js           Interactions (copier, confirmation, code OTP)
├── tests/                  Suite de tests (php tests/run.php)
└── app/                    Code interne (protégé, jamais servi)
    ├── bootstrap.php       Chargement commun + session
    ├── auth.php            Authentification sans mot de passe
    ├── models.php          Utilisateurs, configs, grants, partage
    ├── crypto.php          AES-256-GCM + empreintes de tokens
    ├── mail.php            mail() / SMTP intégré / journal
    ├── ui.php              Composants d'interface réutilisables
    ├── util.php, db.php    Aides transverses, PDO
    ├── http.php            Socle HTTP partagé + garde-fous anti-SSRF
    ├── media.php           Dépôt temporaire des images (Instagram vient les chercher)
    ├── image.php           Conversion des images au format d'une plateforme
    └── mcp/
        ├── server.php      Protocole MCP (initialize, tools/list, tools/call…)
        ├── linkedin.php    Outils LinkedIn + client API + OAuth
        └── instagram.php   Outils Instagram + client API + OAuth
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
   - Facultatif mais recommandé : une quatrième fonction
     `montype_tool_catalog(array $settings): array`, déclarée en `catalog_fn`.
     Elle renvoie **tous** les outils du type — y compris ceux que la
     configuration courante n'expose pas — chacun avec, en plus des champs MCP
     (`inputSchema`, `outputSchema`) : `available` (booléen), `requires`
     (conditions d'activation, lisibles telles quelles) et `errors` (erreurs
     métier). `montype_tools()` se réduit alors à filtrer ce catalogue sur
     `available`, et `tools.php` documente le type sans une ligne de code
     supplémentaire. Sans `catalog_fn`, seuls les outils actifs sont
     documentés, sans conditions ni erreurs.
4. Si certains réglages sont sensibles, listez-les dans
   `config_sensitive_keys()` : ils seront chiffrés automatiquement.
5. Pour que la page du connecteur affiche vos réglages et votre bouton de
   connexion, déclarez les hooks d'interface — `connector.php` ne connaît
   aucun type en particulier :

   | Hook | Signature | Rôle |
   |---|---|---|
   | `settings_form_fn` | `(array $config, array $settings): void` | Champs du formulaire de réglages |
   | `settings_save_fn` | `(array $config, array $post): string` | Enregistre ; retourne un complément de message |
   | `connect_card_fn` | `(array $config, array $settings, array $summary): void` | Carte « Connexion » |
   | `disconnect_fn` | `(array $config): string` | Efface la connexion ; retourne le message |
   | `test_fn` | `(array $settings): string` | Teste la connexion ; lève `RuntimeException` en échec |
   | `action_fn` | `(array $config, string $action): ?array` | Actions supplémentaires ; retourne `[type, message]` ou `null` |

Le catalogue, la création, le partage, les tokens et l'endpoint MCP
fonctionnent alors sans autre modification.

## Tests

Le dépôt embarque une suite de tests sans dépendance — ni Composer, ni
PHPUnit :

```bash
php tests/run.php              # tout
php tests/run.php instagram    # un fichier en particulier
```

Elle couvre la préparation des images, le dépôt et le service des médias, les
flux de publication Instagram (image, carrousel, reprise), la traduction des
erreurs de l'API, le cycle de vie des jetons, le protocole MCP, et la
non-régression du connecteur LinkedIn. Les appels aux API sont **simulés** :
aucun accès réseau ni compte réel n'est nécessaire.

Les tests bout en bout démarrent le serveur intégré de PHP sur l'adresse
d'`APP_URL` pour vérifier les en-têtes réellement émis par `mcp.php` et
`media.php`.

> ⚠️ Les tests écrivent dans la base configurée par `config.php` (utilisateurs
> et connecteurs jetables). Faites-les pointer vers une **base de test**, jamais
> vers votre base de production.

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

**« Connecter LinkedIn » échoue (`unauthorized_scope_error` / « Invalid
scope »)** — c'est l'échec le plus courant, et il vient de l'app LinkedIn,
pas des endpoints : le hub utilise exactement ceux du [discovery document
officiel](https://www.linkedin.com/oauth/.well-known/openid-configuration)
(`/oauth/v2/authorization`, `/oauth/v2/accessToken`, `/v2/userinfo`).
LinkedIn refuse l'autorisation quand un scope demandé n'est couvert par
aucun **produit actif** de votre app. Symptôme typique : l'onglet Products
de votre app ne liste que *Sign In with LinkedIn using OpenID Connect*
(son tableau d'endpoints n'affiche que `/v2/userinfo`) — il manque alors
**Share on LinkedIn**, qui fournit `w_member_social`. La page du connecteur
affiche la liste exacte des scopes demandés et le produit requis pour
chacun ; vérifiez que chaque produit est actif (onglet Products → ajout
immédiat pour *Share on LinkedIn*, demande d'accès pour *Community
Management API* si mode organisation), puis relancez la connexion. Une fois
connecté, le bouton **« Tester la connexion »** appelle `/v2/userinfo` et
affiche le résultat.

**LinkedIn : « This API product requires that it be the only product on the
application »** — en demandant la Community Management API sur une app qui a
déjà d'autres produits (ou demandes en attente). C'est une règle LinkedIn :
créez une **nouvelle app** dédiée (Create app), demandez-y uniquement la
Community Management API, déclarez la même URL de redirection, puis dans le
hub passez le connecteur (ou un second connecteur) en type « Community
Management API » avec le Client ID / Secret de cette nouvelle app.

**LinkedIn : `invalid redirect_uri`** — l'URL de redirection déclarée dans
l'app LinkedIn doit être exactement `APP_URL/oauth-linkedin.php` (HTTPS,
même domaine, pas de slash final surnuméraire).

**Instagram : « Le compte n'est pas un compte professionnel »** — la
publication par API est réservée aux comptes Entreprise et Créateur. Basculez
le compte dans l'application Instagram (Paramètres → Pour les professionnels),
puis reconnectez-le depuis la page du connecteur.

**Instagram : l'échange OAuth est refusé** — dans neuf cas sur dix, ce sont
l'App ID et l'App Secret *Facebook* qui ont été collés au lieu de ceux
d'**Instagram** (app Meta → Instagram → API setup with Instagram business login
→ Business login settings). Vérifiez aussi l'URL de redirection : Meta y ajoute
parfois un slash final.

**Instagram : « n'a pas réussi à télécharger l'image »** (`9004 / 2207052`) —
Instagram vient chercher les images sur votre serveur. Cliquez sur **« Tester
la publication média »** sur la page du connecteur : le diagnostic distingue
une `APP_URL` erronée, un `storage/` non inscriptible, une redirection et un
blocage par WAF ou protection anti-hotlink. Si vous aviez fourni `image_url`,
réessayez sans : le connecteur hébergera l'image lui-même.

**Instagram : « rapport de X:1, hors des bornes acceptées »** — Instagram
*rejette* les images hors 4:5–1.91:1 au lieu de les recadrer. Demandez à Claude
de repasser avec `fit: "pad"` pour compléter l'image par des marges.

**Instagram : la publication s'arrête sur « Instagram traite encore… »** — le
temps d'exécution de l'hébergement a été atteint. Rien n'est perdu : le message
donne l'appel de reprise à faire (les conteneurs restent valables 24 h).

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
