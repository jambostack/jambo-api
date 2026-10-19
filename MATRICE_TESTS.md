# MATRICE DES TESTS DE VALIDATION ET DE NON-RÉGRESSION — JAMBO API
**Référence** : MATRICE-TESTS-2026-V1  
**Objectif** : Fournir une suite exhaustive de scénarios de tests reproductibles pour valider les corrections, certifier l'absence de régression et vérifier la préparation à la production.  
**Outils d'exécution** : PHPUnit 10+ (`php bin/phpunit`), Playwright E2E (`npx playwright test`), Curl / HTTP Client.

---

## 1. STRATÉGIE GLOBALE DE TEST

La stratégie de test s'articule autour de 4 niveaux d'assurance qualité :
1. **Tests Unitaires (TU)** : Validation des composants isolés, algorithmes, politiques de sécurité et calculs de hachage.
2. **Tests d'Intégration & Contrôleurs (TI)** : Validation des flux HTTP, interactions avec la base de données Doctrine, votes de sécurité et événements.
3. **Tests de Sécurité Défensive (TS)** : Tentatives d'intrusion, injections SQL, Directory Traversal, SSRF, et contournements d'authentification.
4. **Tests de Bout en Bout (E2E)** : Validation des parcours utilisateurs complets dans le navigateur (Login, Studio, Création de contenu, Publication).

---

## 2. MATRICE DÉTAILLÉE DES SCÉNARIOS DE TEST

### 2.1. Sécurité & Contrôle d'Accès (TS)

| ID Test | Titre du Scénario | Méthode & Endpoint | Données d'Entrée | Comportement Attendu | Condition de Succès |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **TS-01** | Contrôle d'accès annuaire utilisateurs | `GET /api/users` | Aucun en-tête `Authorization` | Rejet immédiat de la requête | Code HTTP 401 Unauthorized |
| **TS-02** | Contrôle d'accès détail utilisateur | `GET /api/users/1` | Aucun en-tête `Authorization` | Rejet immédiat de la requête | Code HTTP 401 Unauthorized |
| **TS-03** | Contrôle d'accès rôles & permissions | `GET /api/roles`, `GET /api/permissions` | Aucun en-tête `Authorization` | Rejet immédiat de la requête | Code HTTP 401 Unauthorized |
| **TS-04** | Tentative de Directory Traversal en publication | `POST /api/projects/{uuid}/studio/publish` | Payload JSON : `{"files": {"../../public/shell.php": "<?php phpinfo();"}}` | Blocage strict et exception levée | Code HTTP 400 ou 422, aucun fichier créé dans `/public` |
| **TS-05** | Isolation multi-tenant sur GraphQL | `POST /api/projects/{uuid_A}/graphql` | Token API ou session appartenant au `Projet B` | Refus d'accès pour non-appartenance | Code HTTP 403 Forbidden |
| **TS-06** | Accès anonyme endpoint MCP de projet | `POST /api/projects/{uuid}/mcp` | Body JSON-RPC `{"method": "tools/call", "params": {"name": "list_end_users"}}` sans token | Rejet de la requête sans exécution de l'outil | Code HTTP 401 Unauthorized |
| **TS-07** | Élévation de privilège suppression collection | `DELETE /admin-api/v1/projects/{uuid}/collections/{slug}` | PAT d'un utilisateur au rôle « Viewer » avec scope `schema:write` | Refus de suppression (seul `MANAGE` est autorisé) | Code HTTP 403 Forbidden |
| **TS-08** | Élévation de privilège suppression projet | `DELETE /admin-api/v1/projects/{uuid}` | PAT d'un utilisateur au rôle « Viewer » avec scope `projects:write` | Refus de suppression du projet | Code HTTP 403 Forbidden |
| **TS-09** | Tentative SSRF via image IA | `POST /api/projects/{uuid}/studio/ai-generate-image` | Prompt forçant l'URL `http://169.254.169.254/latest/meta-data/` | Rejet immédiat de l'URL cible privée | Exception capturée, URL bloquée |
| **TS-10** | Injection Twig dynamique (SSTI) | Rendu de template dynamique | Chaîne `{{ app.user.password }}` ou `{{ ['id']|filter('system') }}` | Interception par la Sandbox Twig | `Twig\Sandbox\SecurityError` levée |

---

### 2.2. Intégrité des Données & Fonctionnalités Réelles (TI)

| ID Test | Titre du Scénario | Méthode & Endpoint | Données d'Entrée | Comportement Attendu | Condition de Succès |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **TI-01** | Parcours OAuth2 complet (RFC 6749) | `GET /oauth/authorize` puis `POST /oauth/token` | `client_id`, `redirect_uri`, `code_challenge`, identifiants EndUser | Émission d'un code d'autorisation puis échange contre un Access Token JWT | Token JWT valide retourné avec statut 200 |
| **TI-02** | Vrai streaming IA en Server-Sent Events | `POST /admin/api/content-ai/stream` | `{"text": "Bonjour", "action": "rewrite"}` avec mock du LLM | Émission continue de fragments générés distincts du texte d'entrée | Événements SSE `chunk` émis et payload JSON valide |
| **TI-03** | Persistance et restitution d'un répéteur EAV | `POST /api/projects/{uuid}/content/{col}/entries` | Objet contenant un champ répéteur avec 3 sous-éléments | Données enregistrées et restituées fidèlement par l'API publique | Données complètes retournées sur `GET .../entries/{id}` |
| **TI-04** | Déclenchement asynchrone des automatisations | `POST /api/projects/{uuid}/content/{col}/entries` | Nouvelle entrée avec règle d'automatisation déclenchée | Réponse HTTP < 500ms, message `ExecuteAutomationMessage` présent dans le bus | Temps de réponse rapide et message en file |
| **TI-05** | Requête non authentifiée sur settings mot de passe | `PUT /api/settings/password` | Aucun token | Réponse JSON 401 propre | Pas de crash 500 TypeError |
| **TI-06** | Requête non authentifiée sur profil | `GET /api/settings/profile` | Aucun token | Réponse JSON 401 propre | Pas de crash 500 TypeError |

---

### 2.3. Opérations, CDN, Build & Infrastructure (TO)

| ID Test | Titre du Scénario | Commande / URL | Environnement | Comportement Attendu | Condition de Succès |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **TO-01** | Validation stricte des dépendances Composer | `composer validate --strict` | CLI Local / CI | Lockfile synchronisé avec `composer.json` | Code de sortie 0, 0 erreur |
| **TO-02** | Exécution des migrations à froid | `php bin/console doctrine:migrations:migrate --no-interaction` | Base MySQL vierge | Application de toutes les versions de migration sans collision | Code de sortie 0, tables `form` et `form_submission` créées |
| **TO-03** | Compilation conteneur de production | `docker build -t jamboapi:test .` | Moteur Docker | Build multi-étapes réussi sans dépendance DB active | Image créée avec succès |
| **TO-04** | Redimensionnement dynamique CDN via Nginx | `GET /cdn/media/{uuid}?w=300&h=300&fit=crop` | Instance Docker / Nginx | Image redimensionnée générée à la volée en cas de cache-miss | Code HTTP 200 et `Content-Type: image/*` |
| **TO-05** | Accès assistant d'installation à froid | `GET /install` | Base sans aucun utilisateur | Affichage de l'assistant de configuration | Code HTTP 200, formulaire visible |
| **TO-06** | Verrouillage assistant post-installation | `GET /install` | Base contenant 1+ utilisateur | Refus d'accès | Code HTTP 403 Forbidden |

---

## 3. COMMANDES ET SCRIPTS DE REPRODUCTION RAPIDE

### Exécution locale de la suite de tests PHPUnit :
```bash
# Lancement complet avec rapport détaillé
php bin/phpunit --testdox

# Lancement ciblé sur la sécurité
php bin/phpunit tests/Security/

# Lancement ciblé sur les flux OAuth2
php bin/phpunit tests/Service/ProjectOAuthServiceTest.php
```

### Script de vérification automatisée de pré-déploiement (`scripts/preflight-check.sh`) :
```bash
#!/bin/bash
set -e

echo "=== 1. Validation Composer ==="
composer validate --strict

echo "=== 2. Vérification des Migrations Doctrine ==="
php bin/console doctrine:schema:validate --skip-sync

echo "=== 3. Exécution de la suite de tests ==="
php bin/phpunit --no-coverage

echo "=== 4. Contrôle d'absence de secrets commités ==="
if git grep -E "(mysql://[^:]+:[^@]+@[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+|smtp://[^:]+:[^@]+@)" -- ':!.env.example'; then
    echo "ERREUR : Des secrets réels sont détectés dans le dépôt !"
    exit 1
fi

echo "✓ Tous les contrôles de pré-vol sont validés."
```
