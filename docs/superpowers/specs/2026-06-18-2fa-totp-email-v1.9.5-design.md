# v1.9.5 — Authentification à deux facteurs (TOTP + Email)

## Contexte

Le CMS n'a aujourd'hui qu'une authentification simple (email + mot de passe). Aucun CMS headless concurrent ne propose de 2FA native complète (admin + end-users). C'est une opportunité de surpasser le marché sur un sujet critique de sécurité.

**Issue :** Un mot de passe compromis donne un accès complet. Les projets clients (end-users) n'ont aucune protection renforcée.

**Approche :** Ajouter la 2FA par TOTP (Google Authenticator / Authy) et par Email (code à usage unique) aux deux niveaux : utilisateurs admin CMS (`User`) et utilisateurs finaux par projet (`EndUser`). Activation facultative par admin, toggle par projet pour les end-users.

**Avantage concurrentiel :** Jambo devient le seul CMS headless avec 2FA native aux deux niveaux (admin + end-users). Craft CMS a la 2FA admin seulement, Strapi/Directus/Payload n'ont rien de natif.

---

## Volet 1 — Modèle de données

### Migration — nouveaux champs

Les champs suivants sont ajoutés aux tables `user` ET `end_user` :

```sql
twoFactorEnabled       TINYINT(1)   DEFAULT 0 NOT NULL
twoFactorMethod        VARCHAR(10)  NULL       -- 'totp' | 'email'
twoFactorSecret        VARCHAR(255) NULL       -- secret TOTP en base32
twoFactorBackupCodes   JSON         NULL       -- 8 codes de secours hashés (sha256)
twoFactorConfirmedAt   DATETIME     NULL       -- date de confirmation de l'activation
```

**Project.settings — nouvelle clé :**

```json
{
  "security": {
    "endUserTwoFactor": false,
    "endUserTwoFactorMethods": ["totp", "email"]
  }
}
```

### Entités modifiées

- `src/Entity/User.php` — ajout des 5 champs
- `src/Entity/EndUser.php` — ajout des 5 champs

Aucune nouvelle entité, aucune nouvelle table.

---

## Volet 2 — Packages

### Composer

| Package | Usage |
|---------|-------|
| `spomky-labs/otphp` `^11.3` | Génération/vérification TOTP (RFC 6238) |
| `endroid/qr-code` `^6.0` | QR code PNG pour le setup TOTP |
| `bacon/bacon-qr-code` `^3.0` | Dépendance de endroid/qr-code |

Aucun bundle Symfony supplémentaire — l'intégration est manuelle (service + controller).

### NPM

Aucun package supplémentaire — `input-otp` (shadcn/ui) est déjà installé.

---

## Volet 3 — Flux d'authentification

### Admin CMS (form_login + session)

Le flux actuel `email + mot de passe → session` devient un flux en deux étapes :

1. **Étape 1 — Login standard** : `POST /login` avec email + mot de passe. Symfony vérifie les credentials. Si l'utilisateur a `twoFactorEnabled = true`, au lieu de créer la session, le système stocke un **token 2FA temporaire** en session (`two_factor_auth_token`) et redirige vers `/two-factor-challenge`. Si 2FA désactivée → session directe (comportement inchangé).

2. **Étape 2 — Vérification 2FA** : Page `/two-factor-challenge`. L'utilisateur saisit un code :
   - **Méthode TOTP** : code 6 chiffres depuis l'app authenticator
   - **Méthode Email** : code 6 chiffres envoyé par mail (valable 5 minutes)
   - **Code de secours** : un des 8 codes backup (à usage unique)

   Validation réussie → session créée → redirection vers le dashboard.

**Sécurité :**
- Token temporaire signé en session (TTL 5 minutes)
- Rate limiting : 5 tentatives de code / 60 secondes
- Les backup codes sont hashés en base, usage unique (devient `null` après utilisation)

### End-User (API JWT)

1. `POST /api/{projectId}/auth/login` — comme aujourd'hui. Si le projet a `endUserTwoFactor = true` ET l'end-user a `twoFactorEnabled = true`, l'API retourne `{ "requires_2fa": true, "two_factor_token": "jwt_ephemere..." }` au lieu du JWT normal.

2. `POST /api/{projectId}/auth/verify-2fa` — body `{ "two_factor_token": "...", "code": "123456" }`. Vérifie le code, retourne le JWT normal `{ "access_token": "...", "refresh_token": "..." }`.

**Sécurité API :**
- `two_factor_token` est un JWT éphémère signé (TTL 60 secondes)
- 5 tentatives max avant de devoir refaire le login complet

---

## Volet 4 — Backend : Services et Contrôleurs

### Services

| Fichier | Rôle |
|---------|------|
| `src/Service/TwoFactorService.php` | Génération secret TOTP, vérification code TOTP/email/backup, gestion des backup codes |
| `src/Service/TwoFactorMailer.php` | Envoi du code 2FA par email (utilise `symfony/mailer`) |

### `TwoFactorService` — méthodes principales

```php
class TwoFactorService
{
    // Génère un secret TOTP en base32
    public function generateSecret(): string;

    // Retourne l'URI otpauth:// pour le QR code et la clé manuelle
    public function getProvisioningUri(string $secret, string $email, string $issuer = 'JamboAPI'): string;

    // Vérifie un code TOTP (avec fenêtre de ±1 période pour le décalage horaire)
    public function verifyTotp(string $secret, string $code): bool;

    // Génère et envoie un code email 6 chiffres (stocké en session, TTL 5 min)
    public function sendEmailCode(User|EndUser $user, string $email): void;

    // Vérifie un code email (comparaison avec la session)
    public function verifyEmailCode(User|EndUser $user, string $code, mixed $storedCode): bool;

    // Génère 8 codes de secours (8 octets aléatoires chacun, format XXXX-XXXX-XXXX-XXXX)
    public function generateBackupCodes(): array;

    // Vérifie et consomme un code de secours
    public function verifyBackupCode(array &$storedCodes, string $code): bool;
}
```

### Contrôleurs

#### `src/Controller/Auth/TwoFactorChallengeController.php` (nouveau)

| Méthode | Route | Description |
|---------|-------|-------------|
| `GET` | `/two-factor-challenge` | Affiche la page Inertia de saisie du code |
| `POST` | `/two-factor-challenge` | Vérifie le code et crée la session |

#### `src/Controller/Settings/SecurityController.php` (nouveau)

| Méthode | Route | Description |
|---------|-------|-------------|
| `GET` | `/api/settings/security` | État 2FA de l'utilisateur (`twoFactorEnabled`, `twoFactorMethod`) |
| `POST` | `/api/settings/security/totp/setup` | Génère secret + URI QR code. Retourne `{ secret, qr_code_uri }` |
| `POST` | `/api/settings/security/totp/confirm` | Vérifie un code TOTP et active la 2FA. Body: `{ code }` |
| `POST` | `/api/settings/security/email/enable` | Envoie un code de confirmation par email. Active temporairement |
| `POST` | `/api/settings/security/email/confirm` | Confirme le code email et active la 2FA. Body: `{ code }` |
| `POST` | `/api/settings/security/disable` | Désactive la 2FA. Body: `{ password }` |
| `POST` | `/api/settings/security/backup-codes` | Régénère les 8 codes de secours |

#### `src/Controller/Api/EndUserAuthController.php` (modifié)

| Modification | Description |
|-------------|-------------|
| `login()` | Si 2FA activée pour l'end-user ET le projet → retourner `{ requires_2fa: true, two_factor_token: "..." }` |
| Nouvelle méthode `verifyTwoFactor()` | `POST /api/{projectId}/auth/verify-2fa` — vérifie le code, retourne les JWT |

#### `src/Controller/Projects/Settings/ProjectController.php` (modifié)

Ajouter `security.endUserTwoFactor` et `security.endUserTwoFactorMethods` dans les champs acceptés par l'endpoint de mise à jour des paramètres projet.

### Email

Template `email/two_factor_code.html.twig` :

```
Sujet : Votre code de sécurité JamboAPI

Votre code de vérification : 123456

Ce code expire dans 5 minutes.

Si vous n'avez pas demandé ce code, ignorez cet email.
```

---

## Volet 5 — Frontend

### Page login — gestion du redirect 2FA

**Fichier modifié :** `assets/js/pages/auth/login.tsx`

Après soumission du formulaire, si la réponse redirige vers `/two-factor-challenge`, le composant suit la redirection (comportement Inertia standard).

### Page 2FA Challenge

**Fichier créé :** `assets/js/pages/auth/two-factor-challenge.tsx`

Composant avec :
- Titre "Vérification en deux étapes"
- `<InputOTP>` (6 chiffres, shadcn/ui déjà installé)
- Si méthode = `totp` : message "Entrez le code depuis votre application d'authentification"
- Si méthode = `email` : message "Un code a été envoyé à votre adresse email" + bouton "Renvoyer le code"
- Bouton "Utiliser un code de secours" (toggle vers un champ texte simple)
- Bouton "Vérifier"
- Affichage des erreurs (code invalide, expire, etc.)

### Onglet Sécurité dans les réglages

**Fichier créé :** `assets/js/pages/settings/security.tsx`

**Fichier modifié :** `assets/js/layouts/settings/layout.tsx` — ajout du 4ème onglet

Composant avec 3 sections :

1. **Statut** : badge "Activée" (vert) / "Désactivée" (gris) + méthode active
2. **Méthode** : radio buttons TOTP / Email
3. **Configuration TOTP** (si méthode = totp) :
   - QR code (image générée côté serveur via `endroid/qr-code`)
   - Clé manuelle en texte (format `ABCD EFGH IJKL MNOP`)
   - Champ `<InputOTP>` pour vérifier le code
   - Bouton "Vérifier et activer"
4. **Configuration Email** (si méthode = email) :
   - Message "Un code sera envoyé à {email}"
   - Bouton "Envoyer le code de confirmation"
   - Champ `<InputOTP>` pour vérifier
   - Bouton "Vérifier et activer"
5. **Codes de secours** (si 2FA activée) :
   - Liste des 8 codes masqués par défaut (toggle afficher/masquer)
   - Codes utilisés affichés comme "✓ Utilisé"
   - Bouton "Régénérer les codes"
   - Bouton "Télécharger (.txt)"
6. **Désactiver** : bouton avec confirmation par mot de passe

### Toggle end-users dans les paramètres projet

**Fichier modifié :** `assets/js/pages/Projects/Settings/Project.tsx`

Dans la section existante des paramètres, ajouter un bloc "Sécurité" :
- Toggle "Authentification à deux facteurs pour les utilisateurs finaux"
- Si activé : checkboxes pour les méthodes disponibles (TOTP, Email)
- Info-bulle : "Les utilisateurs finaux devront configurer la 2FA dans leur espace personnel"

---

## Fichiers modifiés

| Fichier | Changement |
|---------|-----------|
| `migrations/Version20260618000001.php` | Ajout colonnes 2FA sur `user` et `end_user` |
| `src/Entity/User.php` | +5 champs 2FA |
| `src/Entity/EndUser.php` | +5 champs 2FA |
| `src/Service/TwoFactorService.php` | **Nouveau** — logique TOTP + backup codes |
| `src/Service/TwoFactorMailer.php` | **Nouveau** — envoi code email |
| `src/Controller/Auth/TwoFactorChallengeController.php` | **Nouveau** — page/vérification 2FA |
| `src/Controller/Settings/SecurityController.php` | **Nouveau** — gestion 2FA profil admin |
| `src/Controller/Api/EndUserAuthController.php` | Modifié — `verifyTwoFactor()` + adaptation login |
| `src/Controller/Projects/Settings/ProjectController.php` | Modifié — toggle `endUserTwoFactor` |
| `config/packages/rate_limiter.yaml` | Modifié — ajout `two_factor_limiter` (5 tentatives / 60s) |
| `config/routes.yaml` | Modifié — route `/two-factor-challenge` |
| `templates/email/two_factor_code.html.twig` | **Nouveau** |
| `assets/js/pages/auth/login.tsx` | Modifié — gestion redirect 2FA |
| `assets/js/pages/auth/two-factor-challenge.tsx` | **Nouveau** — page saisie code |
| `assets/js/pages/settings/security.tsx` | **Nouveau** — page réglages 2FA |
| `assets/js/layouts/settings/layout.tsx` | Modifié — +onglet Sécurité |
| `assets/js/pages/Projects/Settings/Project.tsx` | Modifié — bloc sécurité projet |

---

## Rétrocompatibilité

- Si `twoFactorEnabled = false` → comportement login inchangé
- Si le projet n'a pas `endUserTwoFactor = true` → end-users non affectés
- Aucune migration de données existantes nécessaire (les nouveaux champs ont des valeurs par défaut)
- L'ancien flux d'auth (sans 2FA) continue de fonctionner

---

## Cas limites

| Cas | Comportement |
|-----|-------------|
| Utilisateur perd son téléphone ET ses codes de secours | L'admin peut désactiver la 2FA via la base de données ou une commande console |
| Code TOTP expiré (décalage horaire) | Fenêtre de ±1 période (30s), soit le code précédent ou suivant est accepté |
| Code email non reçu | Bouton "Renvoyer le code", rate limité à 3 renvois par 5 minutes |
| Attaque brute force sur le code 2FA | Rate limiter : 5 tentatives / 60s |
| Changement de méthode (TOTP → Email) | L'ancien secret est effacé, backup codes régénérés |
| EndUser non vérifié (email pas confirmé) | La 2FA email n'est pas disponible tant que l'email n'est pas vérifié |
| Session 2FA expirée (5 min) | Redirection vers la page de login avec message "Session expirée" |

---

## Vérification

1. **Migration** : `php bin/console doctrine:migrations:migrate` → colonnes ajoutées
2. **TOTP** : Setup dans Profil > Sécurité → scanner QR code → saisir code → activé
3. **Email 2FA** : Setup → recevoir code → saisir → activé
4. **Login avec 2FA** : Déconnexion → login → page challenge → code → dashboard
5. **Backup codes** : Utiliser un code de secours → connexion OK → code marqué utilisé
6. **End-user 2FA** : Activer toggle projet → login end-user → `requires_2fa` → verify → JWT
7. **Désactivation** : Profil > Sécurité → désactiver → mot de passe → désactivé
8. **Rétrocompatibilité** : Login sans 2FA → comportement inchangé
