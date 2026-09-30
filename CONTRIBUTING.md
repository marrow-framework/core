# Contribuer à Marrow

Merci de l'intérêt que vous portez au projet ! Ce guide couvre tout ce dont vous avez besoin pour soumettre une contribution de qualité.

---

## Table des matières

- [Code de conduite](#code-de-conduite)
- [Prérequis](#prérequis)
- [Mise en place locale](#mise-en-place-locale)
- [Workflow de contribution](#workflow-de-contribution)
- [Conventions de code](#conventions-de-code)
- [Tests](#tests)
- [Nommage des branches](#nommage-des-branches)
- [Messages de commit](#messages-de-commit)
- [Ouvrir une Pull Request](#ouvrir-une-pull-request)
- [Signaler un bug](#signaler-un-bug)
- [Proposer une fonctionnalité](#proposer-une-fonctionnalité)
- [Vulnérabilités de sécurité](#vulnérabilités-de-sécurité)

---

## Code de conduite

Ce projet suit un code de conduite simple : soyez respectueux, constructif et patient. Les contributions toxiques ou les comportements harcelants conduiront à une exclusion immédiate.

---

## Prérequis

| Outil | Version minimale |
|-------|-----------------|
| PHP | 8.2+ |
| Composer | 2+ |
| Git | 2.30+ |

Extensions PHP requises : `pdo`, `pdo_sqlite` (tests), `mbstring`, `json`, `openssl`, `reflect`.

---

## Mise en place locale

```bash
# 1. Fork les deux dépôts sur GitHub, puis clone les localement
git clone https://github.com/<ton-fork>/framework
git clone https://github.com/<ton-fork>/skeleton

# 2. Installe les dépendances du framework (inclut les dev)
cd framework
composer install

# 3. Lance les tests pour vérifier que tout est au vert
vendor/bin/pest

# 4. Installe le skeleton (utilise le framework en symlink local)
cd ../skeleton
composer install
php forge serve
```

Le skeleton pointe vers `../framework` via un repository `path` dans son `composer.json` — aucune publication n'est nécessaire pour tester end-to-end.

---

## Workflow de contribution

```
main  ←  votre branche feature/fix  ←  commits atomiques
```

1. Créez une branche depuis `main` (voir [nommage](#nommage-des-branches))
2. Implémentez vos modifications avec des **commits atomiques**
3. Ajoutez ou mettez à jour les tests concernés
4. Vérifiez que la suite complète est au vert : `vendor/bin/pest`
5. Vérifiez l'analyse statique : `composer analyse`
6. Ouvrez une Pull Request vers `main`

---

## Conventions de code

### Standards obligatoires

- **PSR-12** — formatage strict
- **`declare(strict_types=1)`** en en-tête de chaque fichier PHP
- **Typages complets** : paramètres, retours, propriétés — pas de `mixed` inutile
- **PHP 8.2+** — utilisez les attributs, les enums, les propriétés readonly, les fibers si pertinent
- **PSR-4** — une classe par fichier, nom de fichier = nom de classe

### Style

```php
<?php

declare(strict_types=1);

namespace Marrow\Database;

use Marrow\Support\Collection;

final class QueryBuilder
{
    public function __construct(
        private readonly Connection $connection,
        private string $table = '',
    ) {}

    public function where(string $column, string $operator, mixed $value): static
    {
        // ...
        return $this;
    }
}
```

### Ce qu'on évite

- Les commentaires qui répètent ce que le code dit déjà
- Les `// TODO` sans issue GitHub associée
- Les abstractions anticipées — YAGNI s'applique
- Les dépendances externes non discutées en issue préalable
- Les `@suppress` ou `@phpstan-ignore` sans justification

---

## Tests

Chaque contribution **doit** inclure des tests. Nous utilisons [Pest v3](https://pestphp.com/).

```bash
# Lance toute la suite
vendor/bin/pest

# Lance uniquement les tests unitaires
vendor/bin/pest tests/Unit

# Lance avec couverture HTML
vendor/bin/pest --coverage --coverage-html coverage/
```

### Règles

| Situation | Exigence |
|-----------|----------|
| Nouvelle feature | Test unitaire + test d'intégration |
| Correction de bug | Test de non-régression qui échoue avant le fix |
| Refactoring interne | La suite existante doit rester au vert |
| Commande CLI | Test vérifiant la sortie et les effets de bord |

### Organisation

```
tests/
├── Unit/
│   ├── Fixtures/       ← classes de support PSR-4 (une par fichier)
│   └── *.php           ← tests unitaires en style Pest fonctionnel
└── Pest.php            ← bootstrap, uses(TestCase::class)->in('Unit')
```

Les fixtures (stubs, faux modèles, faux modules) vont dans `tests/Unit/Fixtures/` avec le namespace `Marrow\Tests\Unit\Fixtures` — **pas inline** dans les fichiers de test.

---

## Nommage des branches

```
feature/<description-courte>      # nouvelle fonctionnalité
fix/<description-courte>          # correction de bug
docs/<description-courte>         # documentation uniquement
chore/<description-courte>        # maintenance (CI, deps, config)
refactor/<description-courte>     # refactoring sans changement de comportement
security/<description-courte>     # correctif de sécurité (discutez en amont)
```

Exemples :

```
feature/cache-redis-driver
fix/container-circular-dependency
docs/orm-relations
chore/upgrade-pest-v3
```

---

## Messages de commit

Nous suivons la convention **Conventional Commits** :

```
<type>(<scope>): <description courte en impératif>

[corps optionnel — le POURQUOI, pas le QUOI]

[footer optionnel — Breaking change, Closes #xxx]
```

### Types autorisés

| Type | Quand l'utiliser |
|------|-----------------|
| `feat` | Nouvelle fonctionnalité |
| `fix` | Correction de bug |
| `docs` | Documentation uniquement |
| `test` | Ajout ou correction de tests |
| `refactor` | Refactoring sans changement de comportement |
| `chore` | Maintenance (CI, dépendances, config) |
| `perf` | Amélioration de performance |
| `security` | Correctif de sécurité |

### Exemples

```
feat(router): add optional route parameter support

fix(container): prevent infinite loop on circular autowire

test(orm): add regression test for findOrFail with soft-deleted records

docs(contributing): add fixtures directory convention

chore(deps): upgrade fakerphp/faker to ^1.23
```

### Règles

- Ligne de titre ≤ 72 caractères
- Impératif présent : "add", "fix", "remove" — pas "added", "fixes", "removed"
- Pas de point final sur la ligne de titre
- `BREAKING CHANGE:` en footer si l'API publique change

---

## Ouvrir une Pull Request

### Checklist avant de soumettre

- [ ] La suite de tests est au vert : `vendor/bin/pest`
- [ ] L'analyse statique passe : `composer analyse`
- [ ] Les nouvelles classes sont dans le bon namespace PSR-4
- [ ] Le `CHANGELOG.md` est mis à jour dans la section `[Unreleased]`
- [ ] Le titre de la PR suit le format Conventional Commits
- [ ] La description explique le **pourquoi** du changement, pas le quoi

### Template de description

```markdown
## Contexte
Décrivez le problème ou le besoin que cette PR adresse.

## Solution
Expliquez l'approche choisie et les alternatives écartées.

## Changements
- Liste des modifications principales

## Tests
Décrivez les tests ajoutés ou modifiés.

## Breaking changes
Aucun / [description si applicable]
```

### Processus de revue

1. Au moins **1 approbation** d'un mainteneur est requise
2. Tous les commentaires doivent être résolus avant le merge
3. Le merge se fait en **squash** si la branche contient plus de 3 commits
4. Les branches sont supprimées après merge

---

## Signaler un bug

Avant d'ouvrir une issue, vérifiez que le bug n'est pas déjà [signalé](https://github.com/marrow/framework/issues).

Utilisez le template **Bug Report** et incluez :

1. **Version d'Marrow** (`composer show marrow/framework`)
2. **Version de PHP** (`php -v`)
3. **Étapes minimales pour reproduire**
4. **Comportement attendu vs observé**
5. **Stack trace complète** si applicable

---

## Proposer une fonctionnalité

1. Vérifiez la [Roadmap](README.md#roadmap) et les issues ouvertes
2. Ouvrez une issue avec le label `enhancement`
3. Décrivez le cas d'usage — pas uniquement l'implémentation souhaitée
4. Attendez la validation d'un mainteneur avant de commencer le développement

Les fonctionnalités qui ajoutent des dépendances externes obligatoires seront examinées avec soin. Préférez les implémentations sans dépendance ou avec dépendance optionnelle.

---

## Vulnérabilités de sécurité

**Ne pas ouvrir d'issue publique.**  
Consultez [SECURITY.md](SECURITY.md) pour le processus de divulgation responsable.

---

Merci de contribuer à Marrow. Chaque issue, PR ou retour d'expérience compte.
