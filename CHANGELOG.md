# Changelog

Toutes les modifications notables de ce projet sont documentées ici.

Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/) et le projet respecte le [Versionnement sémantique](https://semver.org/lang/fr/).

Les versions `0.1.x`/`0.2.0` (juin 2026) correspondent à la phase de prototypage initial du noyau (routage, ORM, conteneur, console) et ne sont pas détaillées individuellement ici — leur contenu est repris dans la description de la [1.0.0], la première version considérée stable.

---

## [Unreleased]

## [2.3.0] - 2026-09-25

### Added

- **`#[Route]` attribute routing** (`Marrow\Routing\Attributes\Route`) — an alternative to writing `$router->get(...)` by hand for each controller action: `#[Route('/{id}', method: 'POST', name: 'posts.update', middleware: 'auth')]` on a method, registered via `Router::controller(PostController::class)`. A class-level `#[Route('/posts', middleware: 'web')]` supplies a URI prefix and middleware shared by every attributed method. Still called explicitly from a module's `routes.php` like any other route — this changes where a route's metadata lives, not Marrow's routing-is-module-only convention — and honors the current `group()` prefix/middleware since it goes through the same `addRoute()` as every other registration method.
- **`#[Table]`/`#[Column]` model attributes** (`Marrow\Database\Attributes\{Table,Column}`) — an alternative to declaring `$table`/`$fillable`/`$hidden`/`$casts` arrays: `#[Table('posts')]` plus one repeatable `#[Column('title')]` per column on the class. Deliberately class-level, not property-level: `Model`'s fields are virtual (stored in an internal array, read/written through `__get()`/`__set()`) — a real declared property with the same name would shadow those magic methods and silently break attribute access, verified the hard way while building this (a `public string $title` property on the model bypasses `__get()`/`__set()` entirely, so nothing was actually reading from the intended array-backed storage). Merges with, rather than replaces, an explicit array declaration on the model: `$table`/`$fillable` only apply from attributes if the property was left empty, `$hidden` merges, `$casts` merges with the explicit array winning on a key collision.
- **Validation attributes on `FormRequest`** (`Marrow\Validation\Attributes\{Required,Nullable,Email,StringType,IntegerType,Min,Max,Confirmed,In,Rule}`) — an alternative to hand-writing the `rules()` array: `#[Required, StringType, Max(255)] public string $title;` then `rules(): array { return $this->rulesFromAttributes(); }`. Multiple attributes on one property combine into a single pipe rule in declaration order. `#[Rule('...')]` is a raw escape hatch for any rule without its own dedicated attribute. Unlike the `Model` attributes above, these sit on real declared properties safely — `FormRequest` has no `__get()`/`__set()` for a property to shadow, since `rulesFromAttributes()` only ever reflects the class definition and never reads the properties' actual values.

## [2.2.0] - 2026-09-23

### Added

- **`composer audit` en CI** — nouveau job `security-audit` dans `.github/workflows/ci.yml`, qui vérifie `composer.lock` contre la base d'avisories de sécurité (FriendsOfPHP/security-advisories) à chaque push/PR.
- **`.github/dependabot.yml`** — mises à jour automatiques hebdomadaires des dépendances `composer` (groupées pour `symfony/*`) et des actions GitHub, en PR vers `develop`.
- **`Route::auth(string $guard = 'session')` / `Route::throttle(int $maxAttempts = 60, int $decayMinutes = 1)`** — raccourcis chaînables pour les deux cas de middleware les plus courants, équivalents à `->middleware("auth:{$guard}")` / `->middleware("throttle:{$max},{$decay}")` mais plus difficiles à mal orthographier ; se combinent librement avec `name()`/`middleware()` dans n'importe quel ordre.
- **`Marrow\Health\Checks\QueueHealthCheck`** — vérifie que les tables `jobs`/`failed_jobs` restent sous un seuil configurable (`config/health.php → queue.*`, seuils distincts pour le backlog et les jobs en échec) ; dégrade en `warning` (pas `failed`) si les tables n'existent pas encore, pour qu'une queue simplement non migrée ne fasse pas échouer tout le rapport `/health`.
- **`HealthManager::report()` chronomètre désormais chaque check individuellement** — `duration_ms` est ajouté au résultat de chaque check, y compris les checks tiers qui ne se chronomètrent pas eux-mêmes, pour attribuer un `/health` lent à une sonde précise plutôt qu'à une supposition.
- **`BaseModule::path(string $suffix = '')`** — résout `Views/`, `routes.php`, `Database/Migrations/` par réflexion sur l'emplacement réel du fichier de la classe de module (`dirname((new \ReflectionClass($this))->getFileName())`), plutôt que par un chemin `modules/{Name}/...` construit en dur. C'est ce qui permet à un module d'être distribué comme package Composer ordinaire : `vendor/acme/blog-module/src/BlogModule.php` résout tout seul `vendor/acme/blog-module/src/Views/`, exactement comme un module local résout `modules/Blog/Views/`.
- **`Marrow\Module\PackageDiscovery::discover()`** — lit `vendor/composer/installed.json` (généré par Composer) et récupère la clé `extra.marrow.modules` de chaque package installé ; `Application::boot()` fusionne le résultat avec `config('modules.enabled')` (moins `config('modules.disabled')` pour un opt-out ciblé) avant d'enregistrer quoi que ce soit. Un `composer require acme/blog-module` suffit désormais à activer son module, sans édition manuelle de `config/modules.php`. Échoue silencieusement (tableau vide) si `installed.json` est absent ou illisible — un layout d'install inhabituel ne doit jamais faire planter le boot pour une simple commodité optionnelle.
- **`route:list --json`** — sortie machine-lisible de la table de routage (même structure que celle utilisée par le package `marrow/compass` pour générer `AGENTS.md` à l'intention des agents IA).
- **`vite_dev_mode()` / `vite_client()`** (fonctions Twig, `FrameworkExtension`) — respectivement un booléen indiquant si le serveur de dev Vite tourne, et le `<script>` de bootstrap HMR à injecter une seule fois par page en mode dev ; complètent `vite_asset()`, déjà existante, pour un pipeline Vite + Tailwind CSS complet côté skeleton (voir `docs/frontend.md`).

### Changed

- **Les 3 GitHub Actions de `ci.yml` (`actions/checkout`, `actions/cache`, `shivammathur/setup-php`) sont désormais épinglées sur leur SHA de commit complet** plutôt que sur un tag mutable (`@v4`, `@v2`) — empêche qu'un tag soit réécrit côté fournisseur pour injecter du code dans le pipeline CI (attaque de chaîne d'approvisionnement classique sur les Actions), au prix d'une mise à jour manuelle (ou via Dependabot, ci-dessus) à chaque nouvelle version voulue.
- **`ModuleManager` ne prend plus de second argument `$modulesPath` au constructeur** — chaque module résout désormais ses propres chemins via `BaseModule::path()` (voir ci-dessus) plutôt que de dépendre d'un unique dossier `modules/` connu à l'avance. `Application::bootModule()` et les tests unitaires du gestionnaire de modules mis à jour en conséquence.
- **Les 4 commandes `migrate*` (`MigrateCommand`, `MigrateFreshCommand`, `MigrateRollbackCommand`, `MigrateStatusCommand`) reçoivent désormais `ModuleManager` par injection** et appellent `Migrator::discoverPaths(base_path(), array_keys($modules->getModulePaths()))` au lieu de lire `config('modules.enabled')` directement — un module de package auto-découvert (voir `PackageDiscovery` ci-dessus) n'était auparavant jamais pris en compte par les migrations, seulement par le boot applicatif lui-même, alors qu'un `config('modules.enabled')` lu en dur ne contient jamais les modules découverts automatiquement.
- **`Migrator::dropAll()` (utilisée par `migrate --fresh`/`migrate:fresh`) est désormais consciente du dialecte SQL** : désactive `FOREIGN_KEY_CHECKS` le temps du drop sur MySQL/MariaDB, utilise `DROP TABLE ... CASCADE` sur PostgreSQL — un schéma avec des clés étrangères inter-tables ne pouvait pas être entièrement dropped dans l'ordre naïf précédent sur ces deux moteurs (SQLite, sans contrainte FK appliquée par défaut, n'était pas concerné).
- **`Migrator::discoverPaths()` déduplique désormais les chemins via `realpath()` + normalisation des séparateurs** — un même dossier de migrations trouvé à la fois par `glob()` (séparateurs `/`) et par réflexion de module (séparateurs natifs de l'OS, `\` sous Windows) apparaissait deux fois dans `migrate:status` et pouvait exécuter deux fois les mêmes fichiers.

### Fixed

- **`ThrottleRequests::handle()` levait un `TypeError` sur toute requête réelle passant par une route `throttle:N,M`.** Ses paramètres étaient typés `int $maxAttempts`/`int $decayMinutes`, alors que `Pipeline::resolve()` transmet toujours les paramètres `:params` d'un middleware sous forme de chaînes, sans coercition numérique nulle part en amont — sous `declare(strict_types=1)`, ça produisait une 500 avant même d'atteindre la logique de limitation. Aucun test existant ne l'avait détecté : les tests précédents instanciaient toujours le middleware directement avec de vrais `int`, jamais à travers un `Pipeline` avec des paramètres `:params` réels. Corrigé en `string|int` + cast interne (`(int) $maxAttempts`) ; 3 tests de non-régression ajoutés (appel direct, à travers un vrai `Pipeline`, comportement 429 au-delà du quota).
- **`Marrow\Template\FrameworkExtension` cherchait le fichier de statut du serveur de dev Vite à `public/build/hot`**, alors que `laravel-vite-plugin` l'écrit réellement à `public/hot` — `vite_asset()`/la nouvelle `vite_dev_mode()` ne détectaient donc jamais le mode dev, même serveur `npm run dev` lancé. Corrigé aux 3 emplacements concernés dans le fichier.
- **`FrameworkExtension::funcViteAsset()` ne cherchait le manifeste de build qu'à `public/build/.vite/manifest.json`** ; `laravel-vite-plugin` v3.2 + Vite 8 (versions réellement épinglées côté skeleton) l'écrivent en réalité à l'emplacement plat `public/build/manifest.json`. Les deux emplacements sont désormais vérifiés, le plat en premier.

## [2.1.1] - 2026-09-22

### Added (2.1.1)

- **CI GitHub Actions** (`.github/workflows/ci.yml`) — exécute `composer test` (Pest) sur PHP 8.2/8.3/8.4 en matrice et `composer analyse` (PHPStan niveau 6, annotations inline sur les PR) à chaque push sur `main`/`develop` et sur chaque pull request. Jusqu'ici la suite de tests et l'analyse statique ne tournaient que si quelqu'un pensait à les lancer manuellement. Badge de statut ajouté au README.

### Fixed (2.1.1)

- **`composer.lock` verrouillait des paquets Symfony `^8.1` qui exigent en réalité PHP ≥8.4.1, alors que `composer.json` annonce `"php": ">=8.2"`.** Invisible en local (poste de dev en PHP 8.5), révélé immédiatement par la CI qui vient d'être ajoutée : `composer install` échouait sur les jobs PHP 8.2/8.3. Les 8 paquets `symfony/*` sont rétrogradés vers `^7.0` (résolu en 7.4.x, compatible PHP 8.2+) ; `config.platform.php` fixé à `8.2.0` dans `composer.json` pour que toute résolution future de `composer.lock` respecte la borne basse annoncée, même sur un poste avec un PHP plus récent — c'est exactement ce qui a permis à cette incohérence de passer inaperçue jusqu'ici.
- **PHPStan niveau 6 est désormais entièrement propre (0 erreur)** — la CI faisait échouer `composer analyse` sur 5 erreurs jusque-là considérées "pré-existantes et sans rapport" en local, jamais réellement corrigées faute d'y être forcé. `Storage` gagne un accesseur `name()` pour son disque (au lieu d'une propriété jamais lue) et des annotations `@param`/`@return resource` sur `writeStream()`/`readStream()` (PHP n'a pas de type natif `resource`). `HttpClient::pool()` avait un vrai bug latent au passage : la déstructuration `[$method, $url, $opts]` sur un tuple à 2 éléments levait un warning PHP "Undefined array key 2" à chaque requête sans options — remplacée par un accès `$tuple[2] ?? []` qui évite le warning et satisfait correctement le type optionnel. `PendingMail` perd son paramètre `$config`, jamais lu : le `from` par défaut est déjà appliqué par `Mailer::send()` lui-même, qui a sa propre copie de la config.

---

## [2.1.0] - 2026-09-22

### Added (2.1.0)

- **Passe de documentation PHPDoc.** Ajout des docblocks de classe manquants sur les 30 commandes `make:*`/`migrate*` (Console/Commands) et les 6 middlewares qui n'en avaient aucun (`Authenticate`, `MaintenanceMode`, `RedirectIfAuthenticated`, `ShareErrorsFromSession`, `StartSession`, `TrimStrings`). Ajout de `@throws` sur ~25 fichiers où une méthode publique pouvait lever une exception sans que ce soit documenté (`Container::make()`, `AuthManager`, `Router`, `RouteCollection`, `Pipeline`, `Migrator`, `ModuleManager`, `Storage::disk()`, etc.). Ajout de formes de tableaux (`array<string, mixed>`, etc.) sur `Model::toArray()` et plusieurs méthodes de `Collection`. Corrections mineures au passage : alignement `@param` non standard dans `ApiController::paginate()`, commentaires `//` en paramètre de constructeur remplacés par un docblock dans `BelongsTo`, et une incohérence trouvée en chemin — `HttpResponse::throw()` documentait `HttpException` alors qu'il lève en réalité `\RuntimeException`.

### Changed (2.1.0)

- **README entièrement réécrit** pour présenter Marrow comme le cœur d'un framework (et non une application prête à l'emploi) : installation clarifiée, vue d'ensemble de l'architecture restructurée, documentation mise à jour pour les modules, l'injection par conteneur, le routage, l'ORM, la sécurité, le CLI, les tests et le flux de contribution — aligné sur l'usage recommandé via le dépôt "skeleton" associé.

---

## [2.0.0] - 2026-09-22

Chantier de durcissement sécurité et de correction de bugs de fond sur la couche données, doublé d'une suppression du pattern Facade au profit de l'injection de dépendances partout.

### Security (2.0.0)

- **`JWT_SECRET` vide ou trop court est désormais rejeté explicitement.** `JwtGuard` levait silencieusement une clé HMAC vide (`?? ''`) si `JWT_SECRET` n'était jamais configuré — n'importe qui pouvait forger des tokens valides. Vérifié maintenant en un seul point (`secret()`), avant le bloc `try/catch` de décodage pour que l'erreur de configuration ne soit jamais confondue avec un échec normal de token — et étendu à la longueur minimale (32 octets, requise par HS256), qu'une `DomainException` de `firebase/php-jwt` aurait sinon fait échouer silencieusement en "non authentifié".
- **`JwtGuard::createToken()` ne permet plus à `$claims` d'écraser les claims réservés** (`sub`, `iat`, `exp`, `iss`) — l'ordre de `array_merge()` était inversé.
- **Le chiffrement des secrets 2FA (`HasTwoFactor`) passe d'un XOR à clé statique à AES-256-GCM authentifié.** L'ancien schéma réutilisait la même clé dérivée pour tous les secrets de l'installation (cassable façon "two-time pad") et retombait sur la chaîne codée en dur `'marrow-totp-key'` si `APP_KEY` était absent — visible dans le code source public du framework. `deriveTwoFactorKey()` échoue désormais explicitement si `APP_KEY` n'est pas configuré ; toute donnée chiffrée avec l'ancien schéma XOR ne peut plus être déchiffrée (aucune installation réelle n'existe dans ce dépôt framework-only, donc pas de migration nécessaire).
- **`SessionGuard::attempt()` exécute désormais toujours une vérification Argon2id réelle**, même quand aucune ligne utilisateur n'est trouvée (contre un hash factice constant), pour que le temps de réponse ne révèle plus si un compte existe.
- **`SessionManager` positionne enfin le flag `Secure` du cookie de session** (actif sauf en local/debug) — le docblock l'affirmait depuis le début, mais `cookie_secure` n'était jamais réellement configuré.
- **`SessionManager::csrfToken()` lève une exception si la session n'a pas démarré**, au lieu de retourner silencieusement `''` — un `_token` vide soumis par un attaquant aurait pu passer `hash_equals('', '')` si `StartSession` n'avait jamais tourné.
- **`ValidatorFactory` ne masque plus l'échec de résolution du `Connection`** — un `catch (\Throwable) {}` transformait toute panne en `$db = null`, ce qui fait passer silencieusement **toutes** les règles `unique:`/`exists:` (`ValidatorInstance` les traite comme réussies quand `$db` est absent).
- **Une règle de validation au nom inconnu lève désormais une exception** (`\InvalidArgumentException`) au lieu de passer silencieusement (`default => true` dans `ValidatorInstance::applyRule()`) — une faute de frappe dans une règle (ex. `requird`) n'est plus interprétée comme "validé".
- **Validation de nom centralisée dans les ~19 commandes `make:*`** (`Command::validClassName()`, appelée dans chaque commande, erreur propre via un `try/catch` central dans `execute()`) — empêche l'écriture de fichiers hors du dossier prévu via un `name`/`--event` contenant `../` ou des caractères cassant la syntaxe PHP générée. `class_basename()` (`src/Support/helpers.php`) coupe désormais aussi sur `/`, pas seulement `\`, fermant la faille précise identifiée dans `make:form-request`/`make:resource`.
- **`QueueManager::pop()` restreint `unserialize()` aux seules sous-classes de `Job`** (`allowed_classes` calculé dynamiquement via `get_declared_classes()`, après autoload forcé de la classe nommée dans le payload) — élimine un vecteur d'injection d'objet PHP si la table `jobs` était un jour atteignable par autre chose que `push()`/`later()`.
- **Le cast `encrypted` de `Model` retombait silencieusement en clair si `APP_KEY` était absent, et chiffrait avec AES-256-CBC non authentifié.** Même classe de faille que l'ancien schéma 2FA (ci-dessus) : `decryptCast()`/`encryptCast()` retournaient/stockaient la valeur brute sans la moindre erreur si `APP_KEY` n'était pas configuré — un modèle déclarant `$casts = ['ssn' => 'encrypted']` pouvait persister des données sensibles en clair pendant des mois sans que rien ne le signale. Les deux méthodes délèguent désormais à un nouveau helper partagé `Marrow\Support\Crypto` (AES-256-GCM authentifié — même schéma que 2FA, dont la logique de chiffrement est extraite dans ce helper pour ne plus être dupliquée), qui échoue explicitement si `APP_KEY` est absent.

### Fixed (2.0.0)

- **Le scope global de `SoftDeletes` n'était jamais activé.** `bootSoftDeletes()` n'était appelée par personne — `Model::boot()` était un stub jamais invoqué, sans mécanisme générique de boot des traits. Conséquence concrète : **les lignes soft-deleted restaient visibles dans toutes les requêtes** (`all()`, `find()`, relations...). `Model` gagne un vrai cycle de boot par classe (`bootIfNotBooted()`, appelé depuis `__construct()` et `query()`) qui invoque `boot()` puis tout `boot{NomDuTrait}()` détecté via une résolution récursive des traits utilisés (pattern Laravel `classUsesRecursive`/`traitUsesRecursive`). `SoftDeletes::withTrashed()` était en plus un no-op déguisé ("simplified" en commentaire) et `onlyTrashed()` entrait en conflit direct avec le scope une fois celui-ci actif (`deleted_at IS NULL AND deleted_at IS NOT NULL` — toujours zéro résultat) ; `ModelQueryBuilder`/`Model::query()` acceptent maintenant une liste de scopes globaux à exclure, ce qui corrige les deux.
- **Les contraintes de clé étrangère déclarées via `foreignId()->constrained()` n'étaient jamais créées.** `ForeignIdDefinition::registerForeign()` n'était appelée par personne — les tables se créaient sans intégrité référentielle, sans erreur. `Table::foreignId()` (ex-`Blueprint::foreignId()`) mémorise désormais chaque définition en attente ; `Schema::buildTable()` les finalise avant de lire les FK à générer.
- **`migrate --fresh` (drop toutes les tables) n'avait pas de confirmation**, contrairement à `migrate:fresh` qui protège la même action destructrice. `MigrateCommand` demande désormais confirmation (sauf `--force`), comme `MigrateFreshCommand`.
- **`migrate:status` découvrait les migrations différemment des trois autres commandes `migrate*`** (`glob()` ad hoc limité aux modules au lieu de `Migrator::discoverPaths()`) — n'affichait jamais les migrations de `database/migrations/` à la racine.
- **`make:model --module=X` n'a jamais créé le dossier cible** (le `mkdir` n'existait que dans la branche sans `--module`) — la commande échouait silencieusement à écrire le fichier dès que `modules/{X}/Models/` n'existait pas déjà.
- **`Migrator::run()`/`rollback()` enveloppent désormais chaque migration (up()/down() + l'écriture de suivi) dans une transaction** — une migration qui s'applique partiellement avant de lever une exception n'est plus enregistrée comme "ran", ce qui aurait fait échouer silencieusement le prochain `migrate` (skip d'une migration jamais réellement appliquée).
- **`QueryBuilder::min()`/`max()` ne castaient pas leur résultat en `float`**, contrairement à `sum()`/`avg()` — `min('age')` retournait un `int`/`string` brut du driver PDO selon le dialecte. Corrigé avec un cast conditionnel (`is_numeric($value) ? (float) $value : $value`) plutôt qu'un cast inconditionnel comme `sum()`/`avg()`, pour ne pas corrompre un `MIN()`/`MAX()` sur une colonne non-numérique (date, texte).
- **Bug de casse `--module` non corrigé sur 15 commandes `make:*` sur 16** — seule `make:controller` normalisait `--module` en PascalCase ; les autres écrivaient dans un dossier à la casse différente pour le même module (`modules/blog/` vs `modules/Blog/`), cassant l'autoload PSR-4 sur un filesystem sensible à la casse. Centralisé dans `Command::moduleOption()`.
- **L'auto-enregistrement dans `providers:` (isolation de module) ne concernait que `make:controller`** — étendu à `make:listener` et `make:policy` (classes résolues via `container->make()` à l'exécution, `Events/Dispatcher.php`/`Auth/Gate.php`) et `make:service` par cohérence, via `Command::registerAsProvider()` (extrait de `MakeControllerCommand`, partagé).
- **`BelongsToMany`/`HasManyThrough` contournaient entièrement `ModelQueryBuilder` et donc n'appliquaient jamais les scopes globaux** (dont `SoftDeletes`, réellement actif depuis le correctif ci-dessus) — contrairement à `HasOne`/`HasMany`/`BelongsTo`, qui passent déjà par `ModelQueryBuilder`. Les deux relations construisaient leur SQL à la main et hydrataient les modèles directement ; réécrites pour passer par `ModelQueryBuilder` + `join()`, comme les autres types de relation. Effet de bord positif : le callback de contrainte passé à `with(['relation' => fn($q) => ...])` était silencieusement ignoré pour ces deux types (le paramètre `$constraint` existait mais n'était jamais utilisé dans le corps) — désormais appliqué, comme pour `HasMany`/`BelongsTo`.
- **`withCount()` bypassait aussi les scopes globaux, sur tous les types de relation, et générait du SQL invalide pour deux d'entre eux.** `Relation::eagerLoadCount()` (utilisée par `HasOne`/`HasMany`) construisait du SQL brut sans passer par `ModelQueryBuilder` — réécrite pour l'utiliser, donc désormais scope-aware comme `getResults()`/`eagerLoad()`. `BelongsTo::eagerLoadCount()` héritait de cette même méthode de base alors qu'elle référence une colonne absente de la table du modèle "owner" (la FK vit sur la table enfant, pas sur celle du related) — aurait fait échouer `withCount()` sur n'importe quelle relation `belongsTo` avec une erreur SQL ; remplacée par un calcul local (0 ou 1 selon que la FK est renseignée), sans requête. `BelongsToMany`/`HasManyThrough` reçoivent le même traitement pour `eagerLoadCount()`, avec jointure vers la table related plutôt qu'un comptage brut de la table pivot/intermédiaire.
- `BelongsToMany::detach(int|array $ids = null)` — dépréciation PHP 8.4 (paramètre implicitement nullable) corrigée en `int|array|null`.
- **`firstOrCreate()`/`updateOrCreate()` : la fenêtre de course entre le SELECT et l'INSERT ne fait plus planter la requête.** Ces méthodes restent un SELECT puis un INSERT — ça ne peut être rendu réellement atomique que par une contrainte unique en base (aucun code applicatif seul ne peut le garantir) — mais si deux appels concurrents trouvent tous les deux "rien" et tentent tous les deux de créer la ligne, le perdant lève désormais une exception de conflit qui est interceptée pour relire la ligne que le gagnant vient de créer, au lieu de laisser planter tel quel une `UniqueConstraintViolationException` de Doctrine DBAL non gérée. Une vraie violation d'intégrité sans rapport avec une course (aucune ligne ne correspond aux critères de recherche même après la relecture) continue à se propager normalement.

### Removed (2.0.0)

- Suppression complète du pattern Facade (`Marrow\Support\Facade` et les 15 façades statiques concrètes : `Auth`, `Cache`, `Config`, `DB`, `Event`, `Gate`, `Http`, `Log`, `Mail`, `Notification`, `Queue`, `Router`, `Session`, `Validator`, `View`). Aucune n'était appelée depuis du code réel du framework — uniquement des exemples de documentation. Toute dépendance de service doit désormais passer par injection de constructeur.
- `Container::makeInternal()` — jamais utilisée, et son intention documentée ("résoudre sans vérification de module") devenait incompatible avec le nouveau mécanisme d'héritage de contexte.
- `JwtGuard::setRequest()` — aucun appelant nulle part dans le framework.

### Changed (2.0.0)

- **L'isolation de modules est maintenant appliquée à l'exécution, pas seulement déclarée.** `Container::make()` propage désormais un contexte de module ambiant à travers toute chaîne de résolution transitive (dépendances de constructeur), y compris pour les singletons déjà mis en cache, et gagne `resetModuleContext()` pour les process long-running. Auparavant, `$callerModule` n'était jamais passé nulle part dans le framework — le contrôle d'accès cross-module documenté dans le README n'avait donc jamais d'effet réel.
- `php forge make:controller --module=X` ajoute désormais automatiquement le contrôleur généré aux `providers:` de son module (isolation opt-in par déclaration) et corrige un bug de casse sur `--module` (`blog` → `Blog`).
- `Worker` (Queue) accepte désormais le `Container` et vide le contexte de module entre deux jobs, pour éviter qu'un contexte avorté ne fuite d'un job au suivant.
- `NotificationManager::toMail()` passe désormais explicitement le `Mailer` injecté à la notification (`toMail(object $notifiable, Mailer $mailer)`), remplaçant l'accès via la façade `Mail` supprimée.
- **`Controller`/`ApiController` reçoivent désormais `TemplateEngine`, `Router`, `Gate` et la `Request` courante par injection de constructeur.** `view()`, `redirect()`, `redirectToRoute()`, `back()`, `authorize()`, `can()`, `bouncer()` (Controller) et `serialize()`/`pageUrl()` (ApiController) utilisent ces dépendances injectées au lieu de `Application::getInstance()->getContainer()->make(...)` — même famille de problème que les façades supprimées (résolution globale ambiante, exceptions avalées silencieusement dans `back()`/`pageUrl()` en cas d'échec). `Response::view()`/`render()` et `RedirectResponse::route()` restent des échappatoires statiques documentées pour les contextes sans DI (même catégorie que le helper `app()`), mais les contrôleurs ne passent plus par elles.
- README : les exemples de routing utilisaient `Router::get(...)` (façade supprimée) — corrigés en `$router->get(...)`, reflétant l'usage réel dans `routes.php`. Corrections additionnelles : `imports: ['auth']` → FQCN, référence à un `ServiceProvider` inexistant supprimée, `Config::class` → `ConfigRepository::class`.
- **Sécurité HTTP unifiée sous `ShieldConfig`, inspirée d'AdonisJS Shield.** `SecurityHeaders` et `VerifyCsrfToken` reçoivent désormais un objet de config typé (`Marrow\Http\Shield\ShieldConfig`, source unique `config/shield.php`) par injection de constructeur, au lieu de lire `Config\Repository` ambiante avec un `catch (\Throwable) {}` qui masquait toute panne de configuration (headers de sécurité silencieusement désactivés en cas d'erreur). `ThrottleRequests`, `HandleCors`, `MaintenanceMode`, `HotReloadMiddleware` reçoivent de même leur dépendance (`RateLimiter`, `Config\Repository`, `Application`) par constructeur plutôt que via `Application::getInstance()`.
- `FrameworkExtension` (Twig) résout désormais `Application`/`Router` une seule fois, à la construction, depuis le `Container` déjà injecté — au lieu de rappeler `Application::getInstance()` à chaque appel de `asset()`/`vite_asset()`. `funcCan()`/`cannot()` ne masquent plus une panne de résolution du `Gate` derrière un simple "accès refusé".
- `Schedule::command()` échappe désormais chaque token de la commande individuellement (pas seulement le binaire PHP et `forge`), et résout le chemin de base via `Application` injecté au lieu d'`Application::getInstance()`.
- **`Blueprint` renommé en `Table`** (`Schema::create('posts', function (Table $table) {...})`) — terminologie plus directe que le vocabulaire Laravel, cohérent avec la volonté de ne pas juste calquer un framework existant.
- `Model::getDispatcher()` et `HasPermission::can()` ne masquent plus un échec de résolution derrière un `catch (\Throwable) {}` silencieux — `getDispatcher()` gagne un vrai mécanisme de test (`Model::setDispatcher()`, symétrique à `setConnection()`) pour rester utilisable en isolation sans booter une `Application` complète ; `can()` fait maintenant la même distinction que `FrameworkExtension::funcCan()` (une panne du `Gate` doit être visible, pas confondue avec un refus légitime).
- `Filesystem\Storage` ne retombe plus silencieusement sur le disque `local`/le dossier temp système en cas d'échec de résolution du conteneur (3 `catch (\Throwable) {}` retirés).
- `AboutCommand`/`TinkerCommand` reçoivent `Container`/`Application` par constructeur au lieu d'`Application::getInstance()` ; `MakePolicyCommand` utilise le helper global `base_path()` au lieu d'une méthode privée dupliquée.

### Added (2.0.0)

- `Command::validClassName()`, `Command::moduleOption()` et `Command::registerAsProvider()` — helpers partagés par toutes les commandes `make:*` (le dernier extrait de `MakeControllerCommand`).
- `Model::setDispatcher()` — override de test pour `Dispatcher`, symétrique à `setConnection()`.
- `php forge migrate --fresh --force` — bypass la confirmation pour les pipelines CI/CD non-interactifs qui invoquent le flag délibérément.
- `ModelQueryBuilder`/`Model::query(array $withoutGlobalScopes = [])` — permet d'exclure un scope global nommé pour une requête donnée (utilisé par `SoftDeletes::withTrashed()`/`onlyTrashed()`).
- `Marrow\Support\Crypto` — helper de chiffrement symétrique partagé (AES-256-GCM authentifié, clé dérivée de `APP_KEY` via SHA-256), utilisé par `HasTwoFactor` et le cast `encrypted` de `Model` (auparavant deux implémentations dupliquées, l'une vulnérable — voir Security).
- **`Pipeline::carry()` gagne un 3ᵉ hook Django-inspired : `processException(Request, \Throwable, ...$params): ?Response`**, aux côtés de `processRequest`/`processResponse` pour les middlewares "hook-style". Une exception levée plus loin dans le pipeline peut être récupérée par un middleware englobant (retour d'une `Response`) ou continuer à se propager (retour `null`) — la réponse récupérée traverse ensuite normalement `processResponse`. Documenté dans le README, section Middlewares.
- `Router::getCurrentRoute()` et la fonction Twig `current_route()` — la route courante était documentée dans le README (`current_route()`) mais jamais réellement implémentée (le global Twig `current_route` retournait toujours `null`, désormais supprimé au profit de la fonction).
- **`ScheduledEvent::withoutOverlapping()`** — protège une tâche planifiée contre l'exécution simultanée de deux instances (ex. une tâche lente sur un cron à la minute) via un verrou fichier (`flock`) scopé à la description de l'événement ; un process tué ou planté relâche le verrou automatiquement, sans logique d'expiration manuelle à maintenir. `Schedule::run()` rapporte `'skipped (overlapping)'` pour une exécution ignorée.
- Tests pour `SoftDeletes`, les contraintes FK de `Table`, `ValidatorFactory`, la règle de validation inconnue, `JwtGuard`, `HasTwoFactor`, `SessionGuard`, `QueueManager` (durcissement `unserialize`), `Migrator` (transactions), `ScheduledEvent::withoutOverlapping()`, `Router::getCurrentRoute()`, le hook `Pipeline::processException()`, `CacheManager`, `Config\Repository`, `Logger`, `Paginator`, `NotificationManager`, `BelongsToMany`/`HasManyThrough` (jointures, colonnes pivot, eager loading, respect des scopes globaux, `withCount()`), `Crypto`/le cast `encrypted`, le respect des scopes globaux par `withCount()` sur `HasMany`/`BelongsTo`, et le comportement de `firstOrCreate()`/`updateOrCreate()` en cas de conflit — la plupart de ces composants n'avaient aucune couverture auparavant.

---

## [1.2.0] - 2026-06-15

Nouveaux composants applicatifs (queue, scheduler, notifications, mail, HTTP client, health checks), remplacement des implémentations maison par des bibliothèques matures, et large campagne de stabilisation (revue adversariale + conformité PHP 8.4).

### Added (1.2.0)

- **Queue** — jobs persistés en base, `Worker`, commande `queue:work`.
- **Scheduler** — tâches planifiées façon cron, commande `schedule:run`.
- **Notifications** — canaux mail/base de données/personnalisés.
- **Health checks** — vérifications DB, cache, disque, exposées via un rapport `/health`.
- **Mail** — wrapper `symfony/mailer` (`Mailer`, `Mailable`, `PendingMail`).
- **HTTP client** — wrapper `symfony/http-client` (`HttpClient`, `HttpResponse`).
- **RateLimiter** — limitation par fenêtre glissante, façades `Http`/`Mail`/`Queue`/`Notification`.
- Scaffolding CLI : `make:job`, `make:mail`, `make:notification`, `make:test`, `queue:work`, `schedule:run`.
- **Base `Http\Controller`** (`view()`/`json()`/`redirect()`/`validate()`) avec bouncer de policy façon AdonisJS v6 (`Controller::bouncer()`/`authorizePolicy()`, `Gate::with()` + `PolicyGate`) ; `ApiController` en hérite désormais.
- Règles de validation supplémentaires : `alpha`, `alpha_num`, `alpha_dash`, `uuid`, `ip`, `json`, `array`, `digits`, `starts_with`, `ends_with`, `same`, `different`, `gt`/`gte`/`lt`/`lte`, `between`, `exists`.
- `Blueprint::timestamp()`/`dropColumn()`, `ColumnDefinition::useCurrent()`/`after()` (clause `AFTER` MySQL), `Migrator::discoverPaths()` (scanne `database/migrations` + `modules/*/Database/Migrations` + `modules/*/Migrations`).
- Documentation : `CHANGELOG.md`, `CONTRIBUTING.md`, `SECURITY.md`.
- Logo et identité visuelle du projet (`.github/assets/`).

### Changed (1.2.0)

- **Middlewares consolidés** sous le namespace `Marrow\Middleware` (auparavant dispersés dans `Http\Middleware`), avec un `MiddlewareResolver` unifié partagé par `Kernel` et `Router` (groupes, alias, paramètres `:params`, résolution récursive avec garde anti-cycle) — corrige au passage les groupes de middlewares déclarés au niveau route qui n'étaient jamais réellement développés.
- `Pipeline` transmet désormais les `:params` aux middlewares "hook-style", avec des messages d'erreur plus clairs.
- `SecurityHeaders` gagne un générateur de CSP, HSTS, et les en-têtes COOP/CORP.
- **`FakeGenerator` réécrit en wrapper `fakerphp/faker`** (remplace l'implémentation maison) ; `words()`/`dateTimeBetween()` gardent leur signature d'origine, `password()` reste un helper spécifique Marrow (bcrypt). `Factory::fake()` gagne un paramètre `locale`.
- **`Cache` migre vers `symfony/cache`** (PSR-6 : drivers file/apcu/redis/array) et **`Storage` vers `league/flysystem`** (local/public/s3) — remplacent les implémentations internes.

### Fixed (1.2.0)

Campagne de correction issue d'une revue adversariale du code existant :

- `Router` : les routes `any()` ne matchaient jamais ; correction de la singularisation dans `resource()`.
- `Route::generateUrl()` : slash double ou en trop quand un paramètre optionnel est omis.
- `QueryBuilder` : `ltrim` corrompait une clause `OR` ; `where`/`having` avec valeur `null` ne généraient pas `IS NULL` ; `min()`/`max()` ne castaient toujours pas en `float`.
- `Model::toArray()` avec des relations cross-classe ; état incohérent après `refresh()`/`replicate()`.
- `BelongsToMany` : ne respectait pas `primaryKey` ; `withCount()` à travers la pivot incorrect.
- `JwtGuard` ne recevait pas la `Request` active, empêchant l'authentification JWT de fonctionner.
- `Hash::needsRehash()` ignorait les options de coût Argon2id.
- `RateLimiter::availableIn()` : temps de retry incorrect en fenêtre glissante.
- Scheduler : sémantique `OR` incorrecte entre jour-du-mois/jour-de-semaine dans les expressions cron ; `command()` masquait les échecs au lieu de les remonter.
- `Cache::increment()` ne préservait pas le TTL ; `Storage` : métadonnées non protégées contre les exceptions, chemin de stockage incorrect.
- `HttpResponse::json()` plantait sur un corps de réponse scalaire ; retry de `HttpClient` scopé désormais aux seules erreurs de transport.
- `Container` : suppression d'un `class_basename` dupliqué ; `Notifications` utilisent désormais `getKey()`.
- **Conformité PHPStan / PHP 8.4** : paramètres implicitement nullable de `Model::isDirty()`/`getOriginal()` ; `@return` erroné sur `Permission::grouped()` ; `TinkerCommand` appelait une méthode PsySH inexistante (`setScopeVariables()` au lieu de `setStartingVariables()`) ; `Handler` : null-coalesce redondant sur une clé de trace toujours présente ; `ResourceCollection::toArray(Request)` manquant (appelée par `ApiController::serialize()`) ; `UploadedFile::isValid()` appelait une méthode parente inexistante.
- **Moteur de migrations** : support des migrations en classe anonyme (`return new class`) via `resolveMigration()` ; extraction de `dropAll()` pour que `fresh()` sur plusieurs chemins ne tente de dropper les tables qu'une fois ; `MigrateCommand`/`MigrateFreshCommand` utilisent désormais `Command::call()` (Symfony `ArrayInput`) ; `MigrateFreshCommand --seed` n'appelait pas réellement `db:seed`.
- **Suite de tests** : extraction des fixtures inline vers `tests/Unit/Fixtures/` (conformité PSR-4, une classe par fichier) ; correction de namespaces manquants ; déclaration explicite des propriétés de `TestCase` (dépréciation des propriétés dynamiques en PHP 8.2+).

---

## [1.1.0] - 2026-06-11

Système RBAC/permissions, contrôleur API dédié, et durcissement de la couche HTTP (upload, CSRF, en-têtes de sécurité).

### Added (1.1.0)

- **RBAC & autorisation** : `Gate` (capacités globales via `define()`/`allows()`/`denies()`), `Policy` (classes de politique auto-découvertes par convention), traits `HasRole`, `HasPermission`, `HasTwoFactor`, `Auditable`, modèles `Auth\RBAC\Role`/`Permission`, module d'audit pour la traçabilité des actions sensibles.
- `Http\ApiController` — contrôleur dédié aux réponses JSON/API.
- `Filesystem\Storage` — première version (avant le passage à `league/flysystem` en 1.2.0).
- Middlewares `HandleCors`, `HotReloadMiddleware`, `SanitizeInput`.
- `Http\UploadedFile` et validation de fichiers uploadés.
- `make:policy` — scaffolding CLI pour les classes `Policy`.

### Changed (1.1.0)

- **`Exceptions\Handler` réécrit** : page de debug interactive en développement (CSS inline, sans CDN), templates d'erreur Twig stylés en production, enveloppe JSON pour les requêtes API, `ValidationException` → 422 JSON ou redirection avec erreurs flashées.
- `Schema` étendu de façon significative (types de colonnes, modificateurs, génération SQL par dialecte).
- Extension du moteur de validation (`ValidatorInstance`) et de la gestion des requêtes (`Http\Request`, `FormRequest`).
- `RouteListCommand`, `MigrateCommand`/`MigrateFreshCommand`/`MigrateRollbackCommand`/`MigrateStatusCommand`, `ServeCommand`, `TinkerCommand`, `AboutCommand`, `DownCommand` retravaillées.

---

## [1.0.0] - 2026-06-11

Première version publique d'Marrow. Le noyau est complet et testé (91 assertions, 0 échec).

### Added (1.0.0)

#### Conteneur DI

- Résolution automatique par réflexion sur les type hints
- Attribut `#[Injectable]` et `#[Inject('key')]` pour l'injection de scalaires
- Liaison explicite via `bind()`, `singleton()`, `instance()`
- Résolution avec surcharges ponctuelles (`make($class, ['Dep' => $obj])`)

#### Architecture modulaire HMVC

- Attribut `#[Module]` avec déclaration `imports`, `providers`, `exports`
- Graphe de dépendances orienté — tri topologique (Kahn) au boot
- Détection des cycles au démarrage avec message d'erreur lisible
- Isolation : un provider est privé par défaut, exposé seulement via `exports`
- Commandes `module:graph` et `module:graph --check` (vérification CI)

#### Routeur

- Verbes HTTP : `get`, `post`, `put`, `patch`, `delete`, `options`
- Paramètres nommés `{id}`, paramètres optionnels `{slug?}`
- Contraintes regex via `->where('id', '[0-9]+')`
- Routes nommées avec `->name()` et génération d'URL via `route()`
- Groupes avec `prefix`, `middleware`, `namespace`
- Routes ressource RESTful en une ligne (`resource()` → 7 routes)
- Erreurs HTTP structurées : 404 introuvable, 405 méthode non autorisée

#### ORM Active Record

- Modèle de base avec `$table`, `$fillable`, `$hidden`, `$casts`, `$timestamps`
- CRUD complet : `create()`, `find()`, `findOrFail()`, `all()`, `save()`, `delete()`
- `firstOrCreate()`, `updateOrCreate()`
- Suivi de la saleté des attributs via `isDirty()` / `getOriginal()`
- Casts : `bool`, `int`, `float`, `json`, `datetime`, enums PHP 8.1+
- Scopes locaux et globaux
- Soft deletes via trait `SoftDeletes`
- Relations : `hasOne`, `hasMany`, `belongsTo`, `belongsToMany`, `hasManyThrough`
- Eager loading anti-N+1 via `with()`
- Événements de modèle : `creating`, `created`, `updating`, `updated`, `deleting`, `deleted`

#### QueryBuilder

- Interface fluide : `where`, `whereIn`, `whereNotIn`, `orWhere`, `orderBy`, `limit`, `offset`
- Agrégats : `count`, `sum`, `avg`, `min`, `max`
- `select`, `pluck`, `toSql`, `when()`
- `insertGetId`, `update`, `delete`

#### Schema Builder & Migrations

- `Schema::create()`, `table()`, `drop()`, `dropIfExists()`
- Blueprint : `id`, `string`, `text`, `integer`, `bigInteger`, `boolean`, `decimal`, `enum`, `json`, `timestamp`, `timestamps`, `softDeletes`, `foreignId`, `constrained`
- Modificateurs : `nullable`, `default`, `unsigned`, `index`, `unique`
- Migrator : batch tracking, `migrate`, `rollback`, `fresh`

#### CLI Forge

- `serve`, `route:list`, `module:graph [--check]`
- `migrate [--fresh] [--seed] [--rollback]`, `db:seed [--class=]`
- `key:generate`, `jwt:secret`
- `make:module`, `make:controller`, `make:model`, `make:middleware`, `make:command`
- `make:service`, `make:event`, `make:listener`, `make:seeder`, `make:factory`
- `make:form-request`, `make:resource`, `make:component`
- `down [--message=] [--retry=]`, `up`

#### Authentification

- Auth par session (login, logout, remember me)
- Auth JWT : génération, vérification, refresh tokens
- Hachage bcrypt via `Hash::make()` / `Hash::check()`
- Middlewares `auth` et `guest`

#### Validation

- Règles : `required`, `email`, `min`, `max`, `in`, `not_in`, `confirmed`, `nullable`, `integer`, `url`, `boolean`, `regex`
- `FormRequest` avec injection automatique dans les contrôleurs
- Messages d'erreur par champ, méthode `validated()` filtrante

#### Bus d'événements

- `Dispatcher::listen()`, `dispatch()`, `until()`
- Arrêt de propagation via `return false`
- Auto-découverte des listeners via attribut `#[EventListener]`

#### Templates Twig

- Namespaces par module (`@blog/posts/index.html.twig`)
- Fonctions : `route()`, `asset()`, `csrf_field()`, `old()`, `errors()`, `auth_user()`, `is_auth()`
- Filtres : `time_ago`, `markdown`, `slug`, `money`, `truncate`
- View composers

#### Factories & Seeders

- `Factory` abstraite : `definition()`, `count()`, `state()`, `make()`, `create()`
- `FakeGenerator` — wrapper maison (remplacé par `fakerphp/faker` en 1.2.0)
- `Seeder` abstraite : `run()`, `call()` pour l'enchaînement

#### Tests

- Suite Pest v3, 91 assertions, 0 échec
- Fixtures PSR-4 conformes dans `tests/Unit/Fixtures/`
- `TestCase` avec helpers HTTP et assertions (`assertStatus`, `assertOk`, `assertRedirect`)
- Trait `RefreshDatabase` pour les tests avec base de données

---

[Unreleased]: https://github.com/marrow/framework/compare/v2.3.0...HEAD
[2.3.0]: https://github.com/marrow/framework/compare/v2.2.0...v2.3.0
[2.2.0]: https://github.com/marrow/framework/compare/v2.1.1...v2.2.0
[2.1.1]: https://github.com/marrow/framework/compare/v2.1.0...v2.1.1
[2.1.0]: https://github.com/marrow/framework/compare/v2.0.0...v2.1.0
[2.0.0]: https://github.com/marrow/framework/compare/v1.2.0...v2.0.0
[1.2.0]: https://github.com/marrow/framework/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/marrow/framework/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/marrow/framework/releases/tag/v1.0.0
