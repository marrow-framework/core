# Politique de sécurité

## Versions supportées

| Version | Support sécurité |
|---------|:----------------:|
| 1.x.x   | ✅ Oui           |

Les versions antérieures à `1.0.0` ne reçoivent aucun correctif de sécurité.

---

## Signaler une vulnérabilité

**Ne pas ouvrir une issue GitHub publique pour un problème de sécurité.**

Nous utilisons les **GitHub Security Advisories** pour gérer les rapports de façon confidentielle :

1. Rendez-vous sur [Security → Report a vulnerability](https://github.com/marrow/framework/security/advisories/new)
2. Décrivez la vulnérabilité avec autant de détails que possible
3. Joignez un proof-of-concept si disponible

Vous recevrez un accusé de réception sous **48 h** et un bilan d'évaluation sous **7 jours**.

---

## Processus de traitement

| Étape | Délai cible |
|-------|-------------|
| Accusé de réception | 48 h |
| Confirmation / classification (CVSS) | 7 jours |
| Correctif en développement | selon gravité |
| Publication du correctif + advisory | coordonnée avec le rapporteur |

### Niveaux de gravité et délais de correction

| CVSS | Gravité | Délai cible |
|------|---------|-------------|
| 9.0 – 10.0 | Critique | 72 h |
| 7.0 – 8.9  | Haute    | 7 jours |
| 4.0 – 6.9  | Moyenne  | 30 jours |
| 0.1 – 3.9  | Faible   | Prochain cycle |

---

## Périmètre

Les éléments suivants sont **dans le périmètre** :

- Injection (SQL, commande, LDAP, etc.)
- Contournement d'authentification ou d'autorisation
- Fuite de données sensibles (tokens, mots de passe, clés)
- XSS, CSRF, clickjacking via le framework
- Traversée de chemin (path traversal)
- Désérialisation non sécurisée
- Exposition de secrets via la CLI ou les helpers

Les éléments suivants sont **hors périmètre** :

- Vulnérabilités dans les dépendances tierces (signalez-les directement à leur équipe)
- Problèmes de configuration de l'application hôte
- Attaques nécessitant un accès physique ou un accès admin préalable
- Scans automatisés sans contexte de preuve d'exploitation

---

## Divulgation coordonnée

Nous pratiquons la **divulgation coordonnée responsable** (*responsible disclosure*).
Un CVE sera demandé pour toute vulnérabilité de gravité Haute ou Critique.
Le rapporteur est crédité dans le GitHub Security Advisory et dans le `CHANGELOG.md`, sauf demande d'anonymat.

---

## Bonnes pratiques pour les utilisateurs

- Maintenez votre dépendance `marrow/framework` à jour
- Activez `APP_DEBUG=false` en production
- Utilisez `php forge key:generate` pour regénérer `APP_KEY` en cas de compromission suspectée
- Ne committez jamais le fichier `.env`
- Appliquez les migrations de sécurité RBAC fournies : `php forge migrate`
