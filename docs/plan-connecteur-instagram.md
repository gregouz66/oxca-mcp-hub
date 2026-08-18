# Connecteur Instagram — étude de faisabilité et plan d'implémentation

> Étude au **18/08/2026**. Sources : documentation officielle Meta
> (`developers.facebook.com/docs/instagram-platform/…`), recherche puis
> contre-vérification adversariale de chaque affirmation chiffrée.
> Les points non tranchés par la documentation sont marqués **⚠️**.
>
> **✅ Ce plan a été implémenté.** Le connecteur est en place : voir
> `app/mcp/instagram.php`, `app/media.php`, `app/image.php`, `media.php`,
> `oauth-instagram.php`, et la section « Créer votre app Instagram » du
> README. Ce document reste la référence des **faits d'API** et des choix
> de conception — c'est à ce titre qu'il est conservé.
>
> Deux écarts assumés par rapport au plan initial, tous deux découverts en
> implémentant :
>
> 1. **Les garde-fous anti-SSRF ne résolvent plus le DNS** quand l'URL est
>    seulement transmise à Instagram sans être téléchargée par le hub : une
>    résolution indisponible refusait des URL parfaitement valides, pour une
>    protection qui n'a de sens que si *nous* joignons l'adresse.
> 2. **Un plafond de poids a été ajouté sur les envois en base64** (~18 Mo par
>    image, ~48 Mo par carrousel). Mesure à l'appui : dix images de 8 Mo
>    épuisent la mémoire d'un mutualisé et PHP s'arrête en cours de
>    traitement — le client MCP ne reçoit alors aucune réponse exploitable.

---

## 1. Réponse courte

**Oui, c'est faisable — mais uniquement pour un compte Instagram *professionnel*
(Business ou Créateur). Un compte *personnel* est définitivement exclu.**

| Type de compte | Publication par API | |
|---|---|---|
| **Personnel** | ❌ **Impossible** | Aucune API Meta ne publie sur un compte personnel. |
| **Créateur** | ✅ Feed image + carrousel | Pas de Stories par API, pas de tags produits. |
| **Entreprise** | ✅ Feed image + carrousel + Stories | Chemin le plus complet. |

Citations officielles concordantes :

- « To use the APIs, your app users must have an Instagram **professional account**. »
  — [Instagram Platform Overview](https://developers.facebook.com/docs/instagram-platform/overview/)
- « The Instagram API with Facebook Login **cannot access Instagram consumer
  accounts** (i.e., non-Business or non-Creator Instagram accounts). »
- La seule API qui touchait les comptes personnels — **Instagram Basic Display
  API** — a été **arrêtée le 4 décembre 2024** (« All requests … will return an
  error message »). Elle était de toute façon **en lecture seule** : il n'a
  jamais existé d'API de publication pour compte personnel.

Les seuls mécanismes officiels sans compte professionnel (*Sharing to Stories*,
*Sharing to Feed*) sont des **deep links mobiles** qui ouvrent le composeur
Instagram avec validation manuelle : ni token, ni serveur-à-serveur.
**Inexploitables depuis un hub MCP.**

### Ce que ça implique concrètement pour l'utilisateur

La bascule perso → professionnel se fait **dans l'app Instagram**
(Paramètres → Pour les professionnels → Type de compte et outils). Elle est
**gratuite, réversible**, et conserve abonnés et publications. Une contrepartie
réelle : **le compte devient public** (le réglage « compte privé » est
désactivé pour les comptes professionnels).

Le connecteur devra donc **dire cela clairement dans son écran de réglages**,
plutôt que de laisser l'utilisateur découvrir l'échec après coup.

---

## 2. Chemin d'authentification retenu

Deux chemins existent. **Recommandation : « Instagram API with Instagram
Login »**, et laisser le second en évolution possible.

| | **Instagram Login** *(retenu)* | **Facebook Login** |
|---|---|---|
| Host API | `graph.instagram.com` | `graph.facebook.com` |
| Page Facebook | **non requise** | **obligatoire** (+ rôle admin sur la Page) |
| Identifiants | **Instagram App ID / Secret** *(≠ App ID Facebook)* | App ID/Secret Facebook + `config_id` |
| Scopes publication | `instagram_business_basic`, `instagram_business_content_publish` | `instagram_basic`, `instagram_content_publish`, `pages_read_engagement` |
| Token long | 60 j, **rafraîchissable** | 60 j user token, **Page token sans expiration** |
| Débloque en plus | — | Hashtag Search, tags produits, `DELETE /{ig_media_id}`, upload vidéo resumable |

**Pourquoi Instagram Login :**

1. **Onboarding divisé par trois** : pas de Page Facebook à créer, pas de
   liaison IG↔Page, pas de rôle Page à vérifier, et surtout pas de *Page
   Publishing Authorization* — dont Meta reconnaît qu'**aucun endpoint ne permet
   de détecter à l'avance qu'elle est exigée**.
2. Le modèle correspond exactement à celui déjà retenu pour LinkedIn : chaque
   utilisateur crée sa propre app gratuite.
3. Le token est **rafraîchissable sans réautorisation**, ce qui permet un
   connecteur qui ne se déconnecte jamais tant qu'il sert (voir §6).

**Le point le plus important du dossier** — et la raison pour laquelle ce
connecteur est réalisable sans procédure lourde :

> « Business, Consumer, and Gaming apps are **automatically approved for
> Standard Access** for all permissions and features. »
> « Permissions with Standard Access … can only be requested from app users who
> have a **role on the requesting app**. »
> « If your app will only be used by app users who have a role on the app
> itself **you do not need to complete** [Business] verification. »

Donc : **si chaque utilisateur du hub crée sa propre app Meta et y publie sur
son propre compte, il n'y a ni App Review, ni Business Verification, ni Data
Use Checkup.** C'est le modèle déjà en place pour LinkedIn (« chaque
utilisateur crée son app gratuite ») — il faut le **reconduire à
l'identique**, et *ne pas* proposer d'app Meta partagée par l'instance.

⚠️ **Corollaire à ne pas manquer** : la constante `INSTAGRAM_DEFAULT_APP_ID`
« app partagée par l'instance », équivalente à ce qui existe pour LinkedIn,
**ferait basculer l'instance en usage multi-utilisateurs** → Advanced Access,
App Review, Business Verification, screencasts par permission, plusieurs
semaines de procédure. On la prévoit dans `config.sample.php` **documentée
comme réservée à ce cas**, désactivée par défaut.

---

## 3. Flux de publication

Version d'API **v26.0** (sortie le 29/07/2026), **configurable** — jamais figée
en dur : à expiration d'une version, Meta bascule silencieusement vers la plus
ancienne encore valide, **sans erreur**.

### 3.1 Image simple — 3 appels

```http
POST https://graph.instagram.com/v26.0/<IG_ID>/media
     image_url=<url publique>&caption=…&alt_text=…
→ {"id": "<CONTAINER_ID>"}

GET  https://graph.instagram.com/v26.0/<CONTAINER_ID>?fields=status_code,status
→ {"status_code": "FINISHED"}

POST https://graph.instagram.com/v26.0/<IG_ID>/media_publish
     creation_id=<CONTAINER_ID>
→ {"id": "<IG_MEDIA_ID>"}
```

- `media_type` **doit être omis** pour une image de feed (il n'est requis que
  pour carrousels, stories et reels).
- `caption` : **2 200 caractères, 30 hashtags, 20 @mentions** maximum.
- `alt_text` : 1 000 caractères, **images uniquement**.
- `user_tags` : `[{username, x, y}]` — `x` et `y` (0.0–1.0) sont **requis** sur
  une image.
- Autres paramètres exploitables : `location_id`, `collaborators` (3 max),
  `is_ai_generated`.
- **Toujours lire `status` en plus de `status_code`** : c'est `status` qui porte
  le sous-code d'erreur quand `status_code = ERROR`.

### 3.2 Carrousel — 2 + N appels

```http
# 1. un conteneur par image (2 à 10)
POST /v26.0/<IG_ID>/media    image_url=<url_i>&is_carousel_item=true&alt_text=…
# 2. attendre FINISHED sur CHAQUE enfant
# 3. le conteneur parent porte la légende
POST /v26.0/<IG_ID>/media    media_type=CAROUSEL&children=<ID1>,<ID2>,…&caption=…
# 4. publication
POST /v26.0/<IG_ID>/media_publish    creation_id=<ID_PARENT>
```

Contraintes vérifiées :

| Point | Valeur |
|---|---|
| Nombre d'éléments | **2 à 10** |
| `caption`, `location_id`, `collaborators`, `is_ai_generated` | **sur le parent uniquement** |
| `alt_text` | **sur les enfants image** |
| Recadrage | toutes les images sont recadrées **d'après la première**, par défaut en 1:1 |
| Quota de publication | **un carrousel = 1 post** |
| Quota de conteneurs | N+1 (10 enfants + 1 parent = 11) sur le plafond de 400/24 h |

Deux pièges à intégrer dès l'écriture du code :

1. **Une légende envoyée à un enfant n'échoue pas : elle est ignorée
   silencieusement.** Le connecteur doit donc refuser explicitement ce cas
   plutôt que produire un post sans légende.
2. L'attente du statut `FINISHED` de chaque enfant est **obligatoire en
   pratique** : créer le parent trop tôt renvoie `9007 / 2207027` « The media is
   not ready for publishing ».
3. ⚠️ La documentation **n'affirme nulle part** que l'ordre des IDs dans
   `children` détermine l'ordre des diapositives — seul le comportement de
   recadrage « d'après la première image » l'implique. À valider au premier test
   réel.

---

## 4. Le point dur : Instagram *tire* les médias, il ne les reçoit pas

> « We **cURL** media used in publishing attempts, so the media must be hosted on
> a **publicly accessible server** at the time of the attempt. »

C'est la différence structurante avec le connecteur LinkedIn existant, qui
*pousse* les octets (`PUT` sur une URL d'upload). Ici, il n'existe **aucun
upload binaire pour les images** : le protocole *resumable*
(`rupload.facebook.com`) ne couvre que REELS/STORIES/VIDEO et exige Facebook
Login.

Or Claude transmettra les images **en base64**. Le hub doit donc les exposer
publiquement, temporairement. → **sous-système « mise en scène des médias »**.

### 4.1 Conception (prototypée et testée)

```
storage/media/<clé>.bin     octets
storage/media/<clé>.json    {mime, size, expires_at}
```

- `clé` = `random_hex(16)` → 128 bits d'entropie. C'est **le seul secret** :
  Meta n'envoie aucune authentification.
- `storage/.htaccess` interdit déjà l'accès direct par Apache — c'est un script
  **`media.php`** dédié qui sert les octets, après contrôle de la clé et de
  l'expiration (`OXCA_NO_SESSION`, pas de cookie, `readfile()` en flux,
  `nosniff`, `X-Robots-Tag: noindex`).
- Ramasse-miettes à chaque écriture et à chaque lecture — **aucun cron requis**.
- **URL strictement ASCII** (`/media.php?k=<32 hex>`) : Meta avertit qu'une URL
  contenant autre chose que de l'US-ASCII **fera échouer la requête**.
- **TTL 24 h** (durée de vie d'un conteneur), pas de suppression immédiate après
  publication : Meta ne s'engage que sur « at the time of the attempt » et ne
  documente rien au-delà. ⚠️ Valeur prudentielle, pas documentée.

*État : écriture, lecture, expiration et purge validées en local.*

### 4.2 Deux chemins d'entrée pour une image

1. **`image_url`** — URL publique existante, transmise telle quelle. Option
   `stage: true` pour la re-servir depuis le hub si la source est capricieuse
   (les gardes anti-SSRF déjà écrites pour LinkedIn s'appliquent alors).
2. **`image_base64`** — mise en scène obligatoire.

### 4.3 Transcodage : nécessaire, pas optionnel

Les spécifications images sont **strictes et pour partie rejetantes** :

| Contrainte | Valeur | Hors bornes |
|---|---|---|
| Format | **JPEG uniquement** (JPS/MPO exclus) | rejet `36001 / 2207005` |
| Poids | 8 Mo — l'erreur parle de 8 **Mio** | **contrôler à 8 000 000 octets** |
| Ratio | **4:5 à 1.91:1** | **REJET** `36003 / 2207009` — *pas* de recadrage |
| Largeur | 320 à 1 440 px | **redimensionnement silencieux** |
| Espace couleur | sRGB | converti automatiquement |

Conséquence : un PNG produit par Claude, ou une capture d'écran 16:9, **échoue**.
Le connecteur doit donc **transcoder systématiquement en JPEG** (GD) : largeur
ramenée à ≤ 1 440 px, qualité dégressive jusqu'à passer sous 8 000 000 octets,
et **refus explicite et pédagogique** si le ratio sort des bornes — avec une
option `fit: pad` qui complète l'image en 4:5 ou 1.91:1 plutôt que d'échouer.

`getimagesizefromstring()` (disponible sans GD) sert au contrôle préalable ;
si GD est absent de l'hébergement, on dégrade proprement : seuls les JPEG déjà
conformes sont acceptés, avec un message qui le dit.

### 4.4 Pré-requis d'hébergement — à documenter, et à auto-diagnostiquer

- `APP_URL` **publiquement joignable en HTTPS**, certificat valide et chaîne
  complète (les certificats auto-signés sont refusés).
- **Aucune redirection** sur le chemin média : ni http→https, ni www/non-www, ni
  slash final. ⚠️ Meta ne documente pas sa politique de suivi des 3xx ; les
  retours terrain convergent vers « non fiable ».
- Ni protection anti-hotlink, ni basic auth, ni allowlist IP, ni règle WAF /
  Cloudflare anti-bot sur `/media.php`. Ces cas échouent avec une **erreur
  générique** (`9004 / 2207052`) qui ne dit pas pourquoi.
- ⚠️ Le **User-Agent réel** du fetcher de publication n'est pas documenté
  (`facebookexternalhit/1.1` est le crawler d'aperçu de lien, pas
  nécessairement celui-ci) : aucune règle d'allowlist fiable *a priori*. À
  capturer dans les journaux d'accès à la première publication.

**Bouton « Tester la publication média »** sur la page du connecteur : écrit une
image de test, la retélécharge par son URL publique, vérifie code HTTP,
`Content-Type`, `Content-Length` et **l'absence de 3xx**. Détecte en un clic
`APP_URL` erroné, `storage/` non inscriptible ou WAF bloquant — au lieu d'un
échec opaque côté Meta.

---

## 5. Outils MCP exposés

| Outil | Rôle |
|---|---|
| `instagram_publish_image` | Image simple. `image_url` **ou** `image_base64`, `caption`, `alt_text`, `user_tags`, `location_id`, `collaborators`, `is_ai_generated`, `fit`. |
| `instagram_publish_carousel` | 2 à 10 éléments, légende sur le parent, `alt_text` par élément. |
| `instagram_publish_container` | **Reprise** : publie un conteneur déjà créé (`creation_id`). Indispensable — voir §7. |
| `instagram_get_profile` | `username`, `account_type`, `followers_count`, `media_count`, validité du token. |
| `instagram_publishing_limit` | Quota restant, **lu au runtime**. |
| `instagram_list_media` | Publications récentes (`permalink`, `caption`, `timestamp`) — vérification après publication. |
| `instagram_delete_media` | **Non exposé** avec Instagram Login (réservé à Facebook Login) → entrée de catalogue `available: false` avec sa condition, comme le fait déjà LinkedIn. |

Le catalogue suit le format `catalog_fn` déjà en place (`available`, `requires`,
`errors`) : **`tools.php` documente le connecteur sans une ligne de code
supplémentaire**.

---

## 6. Cycle de vie du token — la vraie fragilité

| Élément | Durée |
|---|---|
| Code d'autorisation | 1 h, **usage unique** (et suffixé `#_`, à retirer) |
| Token court | 1 h |
| Token long | **60 jours** |
| Rafraîchissement | possible **à partir de 24 h d'âge**, tant que le token est valide et `instagram_business_basic` accordé |
| Non rafraîchi en 60 j | **mort définitive** → réautorisation complète |
| Permission inutilisée 90 j | **doit être ré-accordée** par l'utilisateur |

**Mode de panne composé, à modéliser explicitement** : la permission
`instagram_business_basic` expire par inactivité (90 j) → la 3ᵉ condition de
rafraîchissement n'est plus remplie → le token meurt à J+60 sans avertissement.

**Conception retenue** : rafraîchissement **opportuniste**, sans cron (un
mutualisé n'en garantit pas). À chaque appel MCP *et* à chaque affichage de la
page du connecteur : si `login_mode = instagram`, que le token a plus de 24 h et
qu'il expire dans moins de 10 jours → appel de `refresh_access_token`, stockage
du nouveau token et de sa date. Tant que le connecteur sert au moins une fois
tous les 60 jours, **il ne se déconnecte jamais**. La page affiche la date
d'expiration effective, comme pour LinkedIn.

À traiter en plus comme des révocations à tout instant (jamais un simple
*retry*, toujours un chemin de réauthentification) : `190/463` token expiré,
`190/460` **changement de mot de passe** ou déconnexion, `190/458` app
désautorisée.

---

## 7. Quotas, et pourquoi `instagram_publish_container` existe

- **Quota de publication** : la documentation officielle **se contredit** — 100
  posts/24 h dans la section « Rate Limit », **50** dans la section
  « Carousels » de *la même page*, et `quota_total: 50` dans la référence.
  ⚠️ **Ne jamais coder la valeur en dur** : la lire au runtime via
  `content_publishing_limit`, avec plancher défensif à 50. Dépassement →
  `9 / 2207042`.
- **400 conteneurs / 24 h**, et **aucun endpoint ne le monitore** : comptage
  côté client si le besoin apparaît.
- Rate limiting : `X-Business-Use-Case-Usage` (formule `4800 × impressions`).
  ⚠️ Ironie à surveiller : la métrique `impressions` a été **supprimée** de
  l'API en 2025 alors que le quota reste indexé dessus — **lire l'en-tête est le
  seul moyen** de connaître sa consommation. ⚠️ Aucun plancher documenté pour un
  compte à zéro impression : c'est le risque principal d'un test sur un compte
  neuf.

**Pourquoi un outil de reprise** : un mutualisé coupe souvent à 30–60 s
d'exécution. Un carrousel de 10 images = 11 conteneurs + attentes. Si le budget
d'attente (≈ 25 s, comme le flux Documents LinkedIn) est dépassé, on **ne perd
rien** : les conteneurs vivent 24 h. L'outil renvoie alors les `creation_id` et
un message explicite, et `instagram_publish_container` termine le travail au
message suivant. C'est la différence entre « ça a échoué » et « reprends ici ».

---

## 8. Découpage en lots

### Lot 0 — Généraliser la page connecteur *(pré-requis)*

`connector.php` est aujourd'hui codé en dur pour LinkedIn : actions
`save` / `test` / `disconnect` et rendu des cartes « Réglages » et
« Connexion » (`connector.php:55-252`). Sans cette étape, aucun second
connecteur n'est ajoutable proprement.

Cinq hooks à déclarer dans `mcp_types()`, et le code LinkedIn existant déplacé
derrière eux **sans changement de comportement** :

| Hook | Signature |
|---|---|
| `settings_form_fn` | `(array $config, array $settings): void` |
| `settings_save_fn` | `(array $config, array $post): array` |
| `connect_card_fn` | `(array $config, array $settings, array $summary): void` |
| `disconnect_fn` | `(array $config): void` |
| `test_fn` | `(array $settings): string` |

*Critère de recette : la page du connecteur LinkedIn est identique avant/après.*
**À livrer isolément**, c'est le seul lot qui touche à de l'existant qui marche.

### Lot 1 — Socle HTTP partagé

Extraire de `app/mcp/linkedin.php` vers `app/http.php` : `li_http` →
`http_request`, les gardes anti-SSRF (`li_guard_public_url`, `li_resolve_host`,
`li_guard_public_ip`) et `li_document_download` → `http_download_limited`.
Les fonctions `li_*` restent comme alias minces : **zéro changement d'appelant**.

### Lot 2 — Mise en scène des médias ✅ *prototypé et testé*

`media.php` + `app/media.php` (`media_put` / `media_meta` / `media_forget` /
`media_gc`), création de `storage/media/`, contrôle d'écriture ajouté à
`install.php`.

### Lot 3 — Transcodage image

`ig_image_prepare()` : décodage, contrôle du ratio, redimensionnement,
conversion JPEG à qualité dégressive, option `fit: pad`, dégradation propre si
GD est absent.

### Lot 4 — OAuth Instagram

`oauth-instagram.php`, miroir de `oauth-linkedin.php` (`state` en session,
redirect URI = `APP_URL/oauth-instagram.php`).

Trois pièges d'intégration à traiter dès l'écriture :

1. Le code d'autorisation revient **suffixé `#_`**, à retirer avant l'échange.
2. La réponse de `POST https://api.instagram.com/oauth/access_token` est
   **enveloppée** : `{"data":[{"access_token":…,"user_id":…}]}` — piège de
   parsing confirmé sur la documentation officielle. Par prudence, **parser les
   deux formes partout** (`$data['data'][0] ?? $data`) : le reste de la
   plateforme mélange réponses plates et enveloppées.
   Attention aussi à **quel identifiant** on stocke : `id` est un identifiant
   *app-scoped*, `user_id` est l'**Instagram professional account ID** — c'est
   ce dernier qu'attendent `/media` et `/media_publish`.
3. L'App Dashboard **ajoute parfois un slash final** aux Redirect URIs
   enregistrées : le message d'erreur du hub doit le mentionner, comme il le
   fait déjà pour LinkedIn.

**Raccourci majeur à offrir aussi** : pour son propre compte, le bouton
*« Generate token »* du dashboard Meta délivre **directement un token
long-lived de 60 jours**, sans écrire une ligne d'OAuth. Le connecteur doit
accepter un **token collé à la main** en plus du flux OAuth — c'est le chemin
le plus court pour un usage personnel, et il évite entièrement l'App Review.

### Lot 5 — Connecteur Instagram

`app/mcp/instagram.php` : `instagram_tool_catalog`, `instagram_tools`,
`instagram_call`, `instagram_summary`, client HTTP Graph, table des erreurs.

### Lot 6 — Interface et documentation

Icône `instagram` dans `ui_icon()`, entrée `mcp_types()`,
`config_sensitive_keys('instagram') = ['app_secret', 'access_token']`,
constantes `config.sample.php`, section README (création de l'app Meta,
bascule du compte en professionnel, dépannage), et mention du traitement
Instagram dans `confidentialite.php` — **Meta exige une URL de politique de
confidentialité accessible à ses crawlers et non géobloquée**.

### Ce qui ne bouge pas

`mcp.php`, `app/mcp/server.php`, `tools.php`, `dashboard.php`, le partage, les
grants, les tokens — et **`schema.sql` : aucune migration**, tout tient dans
`mcp_configs.settings` (JSON, clés sensibles chiffrées AES-256-GCM).

### Volume estimé

≈ **1 700 à 1 900 lignes**, dont ~1 000 pour `app/mcp/instagram.php` — soit un
ordre de grandeur comparable au connecteur LinkedIn existant (1 270 lignes).

---

## 9. Table des erreurs à implémenter

Comme `li_api_error()`, mais indexée sur le **sous-code**, bien plus précis.

| Code / sous-code | Sens | Traitement |
|---|---|---|
| `9004 / 2207052` | média non téléchargeable depuis l'URI | **terminal** — URL, redirection, WAF |
| `36000 / 2207004` | image trop lourde (8 Mio) | terminal — transcoder |
| `36001 / 2207005` | format non supporté | terminal — transcoder en JPEG |
| `36003 / 2207009` | ratio hors 4:5–1.91:1 | terminal |
| `36004 / 2207010` | légende > 2 200 caractères | terminal |
| `-2 / 2207003` | téléchargement trop long | **transitoire** — réessayer |
| `-2 / 2207020` | média expiré | recréer le conteneur |
| `24 / 2207008` | conteneur inexistant ou expiré | réessayer 30 s à 2 min |
| `25 / 2207050` | **compte Instagram restreint** | **action utilisateur** — se connecter à Instagram |
| `4 / 2207051` | activité restreinte (anti-spam) | ralentir |
| `9 / 2207042` | **quota 24 h atteint** | attendre |
| `9007 / 2207027` | média pas prêt | attendre `FINISHED` |
| `100 / 2207028` | carrousel hors 2–10 | terminal |
| `100 / 2207040` | plus de 20 @mentions | terminal |
| `190 / 460` | mot de passe changé / déconnexion | **réautoriser** |
| `190 / 463` | token expiré | réautoriser |
| `190 / 458` | app désautorisée | réautoriser |

---

## 10. Incertitudes assumées

À lever au premier test réel, pas avant :

1. **Quota réel : 50 ou 100 posts/24 h ?** Contradiction active dans la
   documentation, sur une même page. → lecture au runtime, plancher à 50.
2. **Ordre des diapositives d'un carrousel** : `children` fait-il foi ?
   Non documenté.
3. **Politique de suivi des redirections** par le fetcher de Meta, et **valeur
   de son timeout** : non publiées.
4. **User-Agent du fetcher** : inconnu → aucune règle WAF fiable *a priori*.
5. **Durée de vie requise de l'URL après publication** : non documentée
   (24 h retenu par prudence).
6. **Plancher de quota BUC pour un compte à zéro impression** : non documenté —
   principal risque lors d'un test sur compte neuf.
7. Une **image simple** passe-t-elle systématiquement par `IN_PROGRESS` ?
   Non garanti → le polling est conservé même pour une image.
8. **Comptes mineurs / Teen Accounts** : zone grise totale côté documentation
   développeur. À exclure des conditions d'utilisation du hub, ou à tester.

---

## 11. Ce que je recommande

1. **Lot 0 d'abord, seul, et validé** — c'est le seul qui touche du code qui
   marche déjà.
2. **Périmètre v1 : image simple + carrousel + profil + quota + reprise.**
   Stories, Reels, tags produits, commentaires et statistiques sont des
   extensions naturelles, pas des prérequis. La vidéo en particulier (300 Mo
   servis depuis un mutualisé, avec un fetch Meta soumis à timeout) est
   structurellement fragile et mérite d'être différée.
3. **Ne pas activer d'app Meta partagée par l'instance** : c'est ce qui fait
   basculer tout le projet dans App Review + Business Verification.
4. **Prévoir le chemin « token collé à la main »** dès la v1 : pour l'usage
   personnel, il supprime l'OAuth, l'App Review et la moitié du support.
