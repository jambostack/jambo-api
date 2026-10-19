# REGISTRE DES DÉCISIONS TECHNIQUES ET ARBITRAGES D'ARCHITECTURE — JAMBO API
**Document de référence** : ARCH-DECISIONS-2026  
**Objectif** : Formaliser les ambiguïtés, contradictions fonctionnelles et choix d'architecture conditionnant la mise en production de Jambo API.  
**Public cible** : Architectes logiciels, Lead Developers, Product Owners, Agents de développement autonomes.

---

## 1. CADRE MÉTHODOLOGIQUE DE DÉCISION
Toute décision technique majeure doit faire l'objet d'une analyse d'impact comparant au minimum deux options viables sous l'angle :
1. De la sécurité et de la conformité réglementaire (RGPD).
2. De la maintenabilité et de la dette technique.
3. De la performance et du passage à l'échelle.
4. Du coût d'implémentation et du délai de livraison.

---

## 2. ARBITRAGES STRUCTURANTS

### D-01 : Modèle de Persistance des Répéteurs — EAV Relationnel pur vs Blobs JSON avec Schémas
- **Localisation** : `src/Entity/ContentFieldGroup.php`, `src/Service/EavDataFormatterService.php:135`, `ROADMAP.md` (v1.20).
- **Problématique** :
  La v1.20 a introduit l'entité `ContentFieldGroup` en prétendant remplacer les JSON blobs par des tables relationnelles. Cependant, le service `ContentEntryService` n'a pas été écrit, et les formateurs d'API continuent d'écrire et lire des JSON blobs. Le système est dans un état hybride inachevé.
- **Analyse des options** :
  - **Option A : Terminer la migration relationnelle (ContentFieldGroup)**
    - *Avantages* : Intégrité référentielle garantie par clés étrangères MySQL, possibilité d'exécuter des requêtes SQL `JOIN` directes sur les sous-champs des répéteurs.
    - *Inconvénients* : Explosion du nombre de lignes dans `content_field_value` (problème d'échelle EAV), complexité d'hydratation élevée, réécriture substantielle des contrôleurs de saisie et de l'Admin API. Coût estimé : 5 à 7 jours-homme.
  - **Option B : Consolider le stockage JSON avec validation JSON Schema (Recommandée pour la production immédiate)**
    - *Avantages* : Excellentes performances de lecture (zéro jointure), alignement immédiat avec l'existant sans régression sur l'API, exploitation des fonctions JSON natives de MySQL 8.0/MariaDB pour l'indexation éventuelle (`JSON_EXTRACT`), simplicité de maintenance.
    - *Inconvénients* : Pas de contraintes de clé étrangère au niveau moteur SQL.
- **Décision recommandée** : **Option B**.
  Acter officiellement que les répéteurs sont stockés au format JSON dans `ContentFieldValue::jsonValue`. Supprimer l'entité orpheline `ContentFieldGroup` pour éliminer le code mort, et ajouter un validateur de schéma strict lors de l'enregistrement des entrées.
- **Impact en cas de report** : Confusion continue dans l'équipe et maintien d'entités inutilisées dans la base de données.

---

### D-02 : Rôle et Périmètre du Module OAuth2 — Fournisseur d'Identité (IdP) vs Authentification API Directe
- **Localisation** : `src/Controller/ProjectOAuthController.php`, `src/Service/ProjectOAuthService.php`.
- **Problématique** :
  Le module OAuth2 implémente l'échange de token (`/token`) avec support PKCE, mais omet l'endpoint central `/authorize`. Les tiers ne peuvent donc pas rediriger l'utilisateur vers Jambo pour recueillir son consentement.
- **Analyse des options** :
  - **Option A : Implémenter l'endpoint `/authorize` complet (Recommandée)**
    - *Avantages* : Jambo API devient un véritable IdP OIDC/OAuth2 conforme RFC 6749, permettant aux applications frontend ou mobiles de déporter entièrement la gestion des comptes clients.
    - *Inconvénients* : Nécessite la création d'un écran d'authentification et de consentement par projet (`templates/oauth/authorize.html.twig`), gestion des sessions d'utilisateurs finaux (EndUsers). Coût estimé : 2 jours-homme.
  - **Option B : Réduire la voilure et désactiver le grant `authorization_code`**
    - *Avantages* : Zéro interface web à maintenir, suppression de code complexe.
    - *Inconvénients* : Rupture de promesse fonctionnelle par rapport au `README.md`, impossibilité d'intégrer des clients OAuth2 standards.
- **Décision recommandée** : **Option A**.
  Développer l'endpoint `/authorize` minimaliste respectant la spécification RFC 7636 (PKCE S256).

---

### D-03 : Politique Globale de Sécurité du Pare-Feu — Whitelist Stricte vs Exceptions Particulières
- **Localisation** : `config/packages/security.yaml:72-77`.
- **Problématique** :
  La configuration actuelle déclare `path: ^/api, roles: PUBLIC_ACCESS`. Cela a directement causé les fuites de données critiques sur `UserController`, `RoleController` et `McpController`.
- **Analyse des options** :
  - **Option A : Conserver `^/api` en `PUBLIC_ACCESS` et corriger au cas par cas**
    - *Avantages* : Aucun risque de casser des endpoints existants non documentés.
    - *Inconvénients* : Vulnérabilité par défaut. Tout nouveau contrôleur créé sans `#[IsGranted]` sera automatiquement exposé publiquement.
  - **Option B : Verrouiller `^/api` sous `ROLE_USER` et n'ouvrir que les routes publiques explicitement (Recommandée)**
    - *Avantages* : Sécurité par conception (Secure by Default). Zéro risque d'exposition accidentelle future.
    - *Inconvénients* : Nécessite de lister exhaustivement les routes devant rester publiques (ex: `/api/{uuid}/auth/*`, `/api/{uuid}/forms/*`, `/api/public-content/*`).
- **Décision recommandée** : **Option B**.
  Inverser la politique de sécurité : tout est fermé par défaut sauf autorisation explicite dans `security.yaml`.

---

### D-04 : Stratégie de Cache et Rendu CDN d'Images — Abstraction Flysystem vs Rendu Synchrone
- **Localisation** : `src/Controller/ImageTransformController.php`, `docker/nginx.conf:24-27`.
- **Problématique** :
  Le CDN est conçu avec l'hypothèse que les fichiers originaux sont présents sur le disque local du serveur, ce qui rend l'architecture incompatible avec un cluster conteneurisé ou un bucket S3. De plus, Nginx court-circuite la génération à la volée.
- **Analyse des options** :
  - **Option A : Maintenir le stockage local exclusif**
    - *Avantages* : Simplicité, pas de latence réseau pour récupérer le fichier source.
    - *Inconvénients* : Impossible de faire du multi-serveur ou du multi-région.
  - **Option B : Intégrer Flysystem avec cache local éphémère (Recommandée)**
    - *Avantages* : Le contrôleur télécharge le média source depuis S3/R2 dans un cache local temporaire s'il n'est pas présent, applique la transformation GD, puis sert le flux avec un en-tête `Cache-Control: public, max-age=31536000, immutable`.
    - *Inconvénients* : Petite latence lors du tout premier rendu d'une image distante.
- **Décision recommandée** : **Option B**.
  Corriger `docker/nginx.conf` pour basculer vers PHP en cas de cache-miss, et adapter `ImageTransformController` pour exploiter `StorageManager`.

---

### D-05 : Exécution des Automatisations — File de Messages Asynchrone vs Traitement Synchrone
- **Localisation** : `src/EventSubscriber/AutomationTriggerSubscriber.php:58`, `src/MessageHandler/ExecuteAutomationMessageHandler.php`.
- **Problématique** :
  Les automatisations (envois d'emails, appels de webhooks, traitements IA) s'exécutent de façon synchrone lors de la création d'entrées, dégradant fortement les temps de réponse de l'API.
- **Analyse des options** :
  - **Option A : Conserver le traitement synchrone**
    - *Avantages* : L'utilisateur sait immédiatement si l'automatisation a réussi ou échoué.
    - *Inconvénients* : Temps de réponse imprévisibles, saturation des workers PHP, risque de timeout HTTP.
  - **Option B : Basculer vers Symfony Messenger en asynchrone (Recommandée)**
    - *Avantages* : Réponses API immédiates (< 50ms), résilience avec politique de réessai automatique (retry strategy), absorption des pics de charge.
    - *Inconvénients* : Nécessite un worker de file en arrière-plan (`php bin/console messenger:consume async`).
- **Décision recommandée** : **Option B**.
  Les automatisations doivent impérativement être dispatchées sur le bus asynchrone Messenger. Un mode synchrone peut être conservé uniquement pour le bouton « Tester » dans l'interface d'administration.

---

## 3. SYNTHÈSE DES DÉCISIONS À VALIDER

| ID | Domaine | Décision retenue | Impact de livraison | Risque de rejet |
| :--- | :--- | :--- | :--- | :--- |
| **D-01** | Modèle EAV | Officialiser le stockage JSON blob des répéteurs | Suppression code mort `ContentFieldGroup` | Aucun si validation JSON schema active |
| **D-02** | OAuth2 IdP | Développer l'endpoint `/authorize` complet | Ajout template consentement et route | Inutilisabilité des clients tiers |
| **D-03** | Pare-feu | Verrouiller `^/api` sous `ROLE_USER` par défaut | Whitelist explicite des routes publiques | Risque de 401 sur route oubliée |
| **D-04** | CDN Médias | Fallback Nginx dynamique + compatibilité S3 | Modification `nginx.conf` et `ImageTransformController` | 404 sur images non en cache |
| **D-05** | Automatisations | Exécution 100% asynchrone via Messenger | Dispatch de `ExecuteAutomationMessage` | Nécessite un worker supervisord |
