# CHECKLIST OBJECTIVE DE PASSAGE EN PRODUCTION — JAMBO API
**Document de certification** : GATEWAY-PROD-2026-CERT  
**Statut** : Document de contrôle opposable — Porte d'embarquement (Release Gate)  
**Règle absolue** : Aucun déploiement en production n'est autorisé si un seul élément bloquant (P0/P1) reste non validé.

---

## 1. GOUVERNANCE ET SÉCURITÉ DES ACCÈS

| # | Point de Contrôle | Règle d'Exigence | Vérification Réalisée | Statut |
| :--- | :--- | :--- | :--- | :---: |
| **G-01** | **Assainissement des secrets Git** | Aucun mot de passe réel (DB, SMTP, JWT) dans `.env` ou l'historique git. `.env` présent dans `.gitignore`. | Inspection `git status` et `git log -S` | [ ] Non validé |
| **G-02** | **Renouvellement des identifiants** | Les mots de passe exposés dans l'ancien `.env` ont été révoqués et renouvelés chez les fournisseurs (OVH, Hostinger). | Changement effectif des mots de passe distants | [ ] Non validé |
| **G-03** | **Verrouillage de l'annuaire PII** | Les endpoints `GET /api/users`, `GET /api/roles`, `GET /api/permissions` renvoient 401 sur requête non authentifiée. | Test Curl non authentifié | [ ] Non validé |
| **G-04** | **Garde-fou multi-tenant actif** | `EnsureProjectMemberSubscriber` intercepte indifféremment `{uuid}` et `{projectUuid}` sur toutes les routes de projet. | Test d'accès croisé entre 2 projets | [ ] Non validé |
| **G-05** | **Protection contre le Path Traversal** | `PublishedSiteStorage::publish()` rejette tout chemin contenant des séquences `../` ou pointant hors du répertoire de projet. | Test d'injection de chemin relatif | [ ] Non validé |
| **G-06** | **Contrôle d'accès serveur MCP** | L'endpoint `/api/projects/{uuid}/mcp` exige un token valide ; `list_end_users` ne divulgue aucune donnée sans droit. | Test JSON-RPC non authentifié | [ ] Non validé |
| **G-07** | **Droits de suppression Admin API** | Seul le voter `ProjectVoter::MANAGE` autorise la modification ou destruction d'un projet ou d'une collection. | Test avec jeton au rôle « Viewer » | [ ] Non validé |
| **G-08** | **Sécurité SSRF / Ingestion externe** | Tout téléchargement d'image ou d'URL externe passe par `HttpClient` avec interdiction stricte des adresses IP privées. | Test vers `http://169.254.169.254` | [ ] Non validé |
| **G-09** | **Bac à sable Twig hermétique** | La politique de sécurité `NativeTwigSecurityPolicy` est active dans le conteneur Symfony pour les templates dynamiques. | Test de rendu de balise interdite | [ ] Non validé |

---

## 2. PERSISTANCE, BASE DE DONNÉES & MIGRATIONS

| # | Point de Contrôle | Règle d'Exigence | Vérification Réalisée | Statut |
| :--- | :--- | :--- | :--- | :---: |
| **D-01** | **Exécution linéaire des migrations** | `doctrine:migrations:migrate` s'exécute de la version 1 à la dernière sans doublon de table ni erreur SQL. | Test sur base MySQL vierge à froid | [ ] Non validé |
| **D-02** | **Validation du schéma Doctrine** | `php bin/console doctrine:schema:validate` indique une synchronisation parfaite entre entités et base de données. | Commande de validation de schéma | [ ] Non validé |
| **D-03** | **Procédures de sauvegarde / restauration** | Un script de sauvegarde automatique (dump MySQL + médias S3) est configuré et testé en restauration. | Test de restauration d'un backup | [ ] Non validé |
| **D-04** | **Intégrité des données de répéteurs** | Les champs complexes sont validés par schéma strict avant persistance. | Test d'insertion d'un payload JSON invalide | [ ] Non validé |

---

## 3. INFRASTRUCTURE, BUILD & DÉPLOIEMENT

| # | Point de Contrôle | Règle d'Exigence | Vérification Réalisée | Statut |
| :--- | :--- | :--- | :--- | :---: |
| **I-01** | **Intégrité de `composer.lock`** | `composer validate --strict` retourne 0 avertissement. `composer install` est 100% reproductible. | Exécution locale et CI | [ ] Non validé |
| **I-02** | **Conteneur Docker de production** | L'image `Dockerfile` se compile sans erreur et démarre Nginx/PHP-FPM/Supervisor sans crash. | `docker build` et `docker run` | [ ] Non validé |
| **I-03** | **Délégation Nginx vers CDN dynamique** | Les requêtes `/cdn/media/{uuid}` absentes du cache disque sont correctement transmises à PHP-FPM pour génération. | Appel curl sur image non mise en cache | [ ] Non validé |
| **I-04** | **Assistant d'installation à froid** | `/install` est accessible sur base vide et se verrouille définitivement après création du premier compte admin. | Test du cycle de vie d'installation | [ ] Non validé |
| **I-05** | **Procédure de déploiement atomique** | Remplacement de `deploy.py` par un protocole incluant `composer install`, migrations, compilation d'assets et `cache:warmup`. | Test de déploiement complet | [ ] Non validé |
| **I-06** | **Pipeline CI/CD opérationnel** | Un workflow GitHub Actions valide automatiquement les tests et la conformité du code à chaque PR. | Exécution réussie sur GitHub Actions | [ ] Non validé |

---

## 4. ROBUSTESSE, PERFORMANCE & EXPLOITATION

| # | Point de Contrôle | Règle d'Exigence | Vérification Réalisée | Statut |
| :--- | :--- | :--- | :--- | :--- :--- |
| **O-01** | **Déport asynchrone des tâches lourdes** | Les automatisations et envois de courriels passent par le bus Symfony Messenger et sont consommés par un worker. | Vérification de la file et des workers | [ ] Non validé |
| **O-02** | **Surveillance et alertes d'erreurs** | Un outil de centralisation des logs (Sentry / Monolog structuré) est configuré pour intercepter les exceptions 500. | Déclenchement d'une exception de test | [ ] Non validé |
| **O-03** | **Endpoint de santé (Healthcheck)** | Une route `/health` ou `/api/health` retourne l'état de la base de données, du cache et du stockage de fichiers. | Test HTTP GET `/health` | [ ] Non validé |
| **O-04** | **Zéro fonctionnalité simulée (Fake)** | L'assistant streaming IA, le module OAuth2 et les formateurs de contenu utilisent du code réel connecté aux services. | Validation des tests d'intégration réels | [ ] Non validé |
| **O-05** | **Documentation alignée sur le code réel** | `README.md` et `ROADMAP.md` ne revendiquent que les fonctionnalités effectivement livrées et testées. | Relecture contradictoire doc/code | [ ] Non validé |

---

## 5. DÉCISION DE MISE EN PRODUCTION

- **Nombre total de contrôles** : 24
- **Contrôles validés** : 0 / 24 (État initial de l'audit)
- **Contrôles restants** : 24

**Verdict d'audit** :
> [!CAUTION]
> **MISE EN PRODUCTION FORMELLEMENT REFUSÉE DANS L'ÉTAT ACTUEL DU DÉPÔT.**  
> Le système présente des risques de compromission critique (RCE, fuite PII, secrets en clair) et des blocages fatals d'installation (migrations et lockfile corrompus).  
> La mise en production ne pourra être autorisée qu'après exécution complète et validée des phases 0 à 5 documentées dans [ROADMAP_AUDIT_CORRECTIONS.md](file:///c:/laragon/www/jamboapicms/ROADMAP_AUDIT_CORRECTIONS.md).
