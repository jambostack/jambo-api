# Jambo — Vision Plateforme & Feuille de route

> Date : 2026-05-29
> Document de synthèse issu du brainstorming. Consolide la vision, l'analyse technique et la feuille de route des chantiers.

---

## 1. Vision — Le tout-en-un

Jambo devient une **plateforme complète** : on **construit** une application (Workbench IA), on l'**héberge** directement dans Jambo (domaine custom), et on **fait tourner un vrai SaaS** grâce aux capacités serveur qu'offre Symfony — le tout sans infrastructure externe.

```
Schéma Jambo → Workbench IA → App générée → Hébergée par Jambo → Domaine custom
                                   +
        Capacités serveur (auth end-users, email/SMTP, anti-spam, paiement…)
```

L'app front (statique) n'est qu'un **client** ; Jambo est le **backend complet** (données, API, auth, fichiers, email, hébergement).

---

## 2. Sur quoi repose Jambo (inventaire Symfony)

Jambo est une application **Symfony 8 / PHP 8.4**. Capacités déjà présentes et exploitables :

| Capacité | Présent | Usage pour les apps / projets |
|---|---|---|
| **Twig** (twig-bundle + extra + intl) + `templates/` | ✅ | Rendu serveur (SSR), SEO, zéro build |
| **Symfony UX Turbo** (Hotwire) | ✅ | Navigation type SPA sans JS, sur HTML serveur |
| **GraphQL** (webonyx/graphql-php) | ✅ | API riche |
| **Serializer / Security / Intl** | ✅ | Sérialisation, auth, multi-locale |
| **Mailer** (`symfony/mailer`) + envoi async (Messenger) | ✅ | Emails transactionnels (global aujourd'hui) |
| **Messenger** (transport `async`) | ✅ | Tâches asynchrones, retries |
| **RateLimiter** | ✅ | Limitation anti-abus |
| Services **EAV** (`EavDataFormatterService`, `EavFieldHelperService`, repos contenu) | ✅ | Accès direct aux données (sans round-trip HTTP) |
| **Auth end-users** (`EndUserJwtService`, API JWT par projet) | ✅ | Inscription / login / refresh / reset mdp |
| **gregwar/captcha-bundle** (^2.5) | ✅ (installé) | Captcha self-hosted |
| Recherche **Meilisearch** | ✅ | Recherche full-text |
| **Webhooks** | ✅ | Intégrations sortantes |
| Mercure (temps réel) / API Platform / FrankenPHP | ❌ | Non installés |
| **Paiement / abonnements** (Stripe…) | ❌ | Manquant — clé pour un SaaS |

**Insight clé** : aujourd'hui le Workbench génère des fronts JS *découplés* qui consomment l'API par HTTP. Comme Jambo **est** Symfony, on peut exploiter ces capacités bien plus directement (rendu serveur, accès EAV direct, email, auth, etc.).

---

## 3. Décomposition en sous-systèmes

Chaque sous-système est **indépendant** et suit son propre cycle *spec → plan → implémentation*.

### 3.1 Jambo Sites — hébergement statique + domaine custom
**Statut : spec écrit** (`docs/superpowers/specs/2026-05-29-jambo-sites-design.md`).

- L'app construite dans le Workbench est buildée **côté navigateur** (WebContainer), publiée dans Jambo (`var/published_sites/<uuid>/`).
- Liaison **domaine ↔ projet** (`SiteDomain`), résolution par en-tête **`Host`** via un `EventSubscriber` (`kernel.request`), fallback SPA, protection traversal.
- **Table de variables d'environnement** par projet (`WorkbenchEnvVar`) injectées au build.
- DNS + SSL gérés **manuellement par le devops** (le domaine pointe vers le serveur Jambo). Indépendant du type de serveur.
- On **garde l'export ZIP** (déploiement externe manuel) ; on **supprime** le 1-clic (Vercel/Netlify/Railway) et Jambo Cloud Docker/Traefik pour simplifier.
- **Contrainte** : sorties **statiques** uniquement (Astro, ou Next/Nuxt/SvelteKit en export statique). SSR hors scope (couvert par l'export ZIP).

### 3.2 Reskin « bolt.diy × palette Jambo »
**Statut : intégré au spec Jambo Sites.**

- On adopte la **structure et le feeling de bolt.diy** (header fin ~54px, split chat/workbench, panneaux arrondis, bordures subtiles, terminal, sombre par défaut).
- **Couleurs = palette Jambo existante** (vert émeraude), pas le violet de bolt. Mapping sans nouvelle couleur :

| Rôle bolt.diy | Token Jambo réutilisé |
|---|---|
| depth-1 `#0A0A0A` | `--background` `oklch(0.13 0.012 165)` |
| depth-2 `#171717` | `--card` `oklch(0.17 0.014 165)` |
| depth-3 `#262626` | `--muted` `oklch(0.22 0.016 165)` |
| accent violet | **`--primary` émeraude** `oklch(0.68 0.20 158)` |
| bordure alpha-white-10 | `--border` (blanc 9 %) |

### 3.3 Email/SMTP par projet + Captcha anti-spam
**Statut : cadré, prêt à spec.** (À spec ensemble car le captcha protège l'endpoint email.)

**Email/SMTP**
1. **Paramètres SMTP par projet** — entité `ProjectMailerSettings` : `host`, `port`, `username`, `password` (**chiffré** via `kernel.secret`), `encryption`, `fromEmail`, `fromName`. DSN Mailer assemblé côté serveur.
2. **Endpoint** `POST /api/{projectUuid}/email`.
3. **Service** `ProjectMailerService` — construit le transport depuis la config projet, envoie via `MailerInterface` en **async** (Messenger).

**Garde-fous (dès le MVP)**
- 🔒 **Rate limiting** (par IP + par projet).
- 🔒 **Mot de passe SMTP chiffré** au repos.
- 🔒 **Pas de relais ouvert** : mode **(A) formulaire de contact** recommandé — destinataire **fixe** (adresse de contact du projet), endpoint public protégé. Modes (B) authentifié / (C) les deux possibles plus tard.

**Captcha (gregwar) — *stateless***
- ⚠️ gregwar est **basé sur la session** → problématique pour un front statique **cross-origin**.
- **Solution** : endpoint `GET /api/{projectUuid}/captcha` → image + **token** ; réponse attendue stockée dans le **cache Jambo** (courte durée, usage unique), pas de session. Validation par token + réponse.
- Utiliser la **lib `gregwar/captcha`** directement pour le flux stateless ; le **form-type du bundle** reste dispo pour les apps Twig natives (session OK).
- **Capacité réutilisable** : un validateur que **n'importe quel endpoint peut exiger « à la demande »**. Premier consommateur : l'endpoint email/contact.
- **Défense en profondeur** : captcha **+** rate limit (+ *honeypot* optionnel).

### 3.4 Auth end-users avec un front statique
**Statut : déjà disponible — aucun développement nouveau.**

- API REST + **JWT** par projet : `/api/{projectId}/auth/{register,login,refresh,me,logout,forgot-password,reset-password}` (`EndUserJwtService`).
- Le front statique consomme l'API comme un SPA : login → JWT → `Authorization: Bearer` → refresh.
- **Stateless** → aucun problème de session cross-origin.
- ⚠️ Stockage du token côté front : `localStorage` + Bearer (simple, sensible XSS) **ou** cookie `httpOnly Secure SameSite=None` (plus sûr, nécessite CORS *credentials*). MVP : Bearer + refresh court.

### 3.5 Jambo Native — Twig/Turbo (SSR)
**Statut : analysé, à spec plus tard (sécurité à cadrer).**

- Nouveau **type de template** : l'app est rendue **côté serveur par Jambo** (Twig + Turbo), avec **accès direct à l'EAV** (zéro HTTP), SSR/SEO, sécurité, cache, i18n — gratuitement.
- La couche `Host` devient **bi-mode** : servir des fichiers statiques **ou** rendre du Twig.
- ⚠️ **Sécurité critique** : du Twig généré par IA exécuté dans le process Jambo doit passer par le **Twig Sandbox** (liste blanche tags/filtres/fonctions). C'est le point d'attention n°1 → mérite son propre cycle brainstorm/spec.

### 3.6 Paiement / abonnements (gap SaaS)
**Statut : nouveau, à explorer.**

- Capacité serveur : intégration Stripe (ou autre), abonnements, webhooks entrants (Jambo a déjà l'infra webhooks).
- Nécessaire pour un **SaaS complet** (facturation).

---

## 4. Peut-on développer un SaaS complet avec un projet Jambo ?

**Oui, en grande partie.** Jambo couvre déjà le plus dur d'un backend SaaS.

| Brique SaaS | Jambo | État |
|---|---|---|
| Modélisation données (collections/EAV) | ✅ | Existe |
| Auth end-users + inscription + reset mdp (JWT) | ✅ | Existe |
| API REST + GraphQL | ✅ | Existe |
| Fichiers / médias | ✅ | Existe |
| Recherche (Meilisearch) | ✅ | Existe |
| Webhooks | ✅ | Existe |
| Email/SMTP par projet | 🟡 | À faire |
| Anti-spam (captcha) | 🟡 | À faire |
| Hébergement front + domaine custom | 🟡 | En cours (Jambo Sites) |
| **Paiement / abonnements** | ❌ | **Manque — clé SaaS** |
| Autorisation fine / plans / quotas end-users | 🟡 | Partiel (rôles + RateLimiter) |
| Temps réel (Mercure) | ❌ | À installer si besoin |

**Verdict** : avec les capacités serveur ajoutées (email, captcha) **+ un module paiement/abonnements**, on a un vrai SaaS. Le principal manquant est la **facturation/paiement**.

---

## 5. Décisions prises (brainstorming)

1. **Front statique, sans serveur Node** : l'app consomme l'API Jambo. SSR hors scope (export ZIP pour l'externe).
2. **Jambo sert les fichiers buildés lui-même** (PHP/Symfony) → indépendant du type de serveur (mutualisé, VPS, control panel).
3. **DNS & SSL hors scope code** : gérés par le devops ; le domaine pointe vers le serveur Jambo.
4. **On garde l'export ZIP** ; on **supprime** 1-clic + Jambo Cloud Docker pour simplifier.
5. **Entités** : `WorkbenchEnvVar`, `SiteDomain`, `WorkbenchProject.publishedAt`.
6. **Esthétique** : structure/feeling bolt.diy + **palette Jambo (émeraude sur sombre)**, zéro nouvelle couleur.
7. **Email** : mode **(A) formulaire de contact** en MVP (destinataire fixe, public + protégé).
8. **Captcha** : **stateless** (cache + token), opt-in par formulaire, défense en profondeur avec le rate limit.
9. **Conserver l'AES-256-GCM** du code Deploy (au lieu de tout supprimer) pour chiffrer les creds SMTP — *à confirmer*.

---

## 6. Feuille de route séquencée

> Principe : un sous-système à la fois, chacun *spec → plan → implémentation* (mode subagent).

| Ordre | Chantier | Statut |
|---|---|---|
| **1** | **Jambo Sites** (statique + domaine) + reskin bolt.diy | Spec écrit — à valider |
| **2** | **Email/SMTP par projet + Captcha** | Cadré — à spec |
| **3** | **Paiement / abonnements** | À explorer |
| **4** | **Jambo Native (Twig/Turbo + sandbox)** | Analysé — à spec |

Auth end-users (3.4) : **déjà disponible**, rien à développer.

---

## 7. Points d'attention & sécurité

- **Email — pas de relais ouvert** : rate limit + (mode A) destinataire fixe. Ne jamais accepter de destinataire arbitraire sans auth.
- **Captcha cross-origin** : impérativement **stateless** (pas de session) pour les fronts statiques.
- **Secrets côté front statique** : toute variable injectée au build se retrouve dans le JS livré. Les vrais secrets restent **côté serveur** (API Jambo) ; le flag `isSecret` ne fait que masquer l'affichage UI.
- **JWT** : stockage du token (XSS vs CSRF/cross-origin) — choisir selon le besoin.
- **Twig natif (3.5)** : **Twig Sandbox obligatoire** (exécution de code généré par IA dans le process Jambo).
- **Résolution Host** : normalisation du chemin (`realpath`) confinée à `var/published_sites/<uuid>/` (anti-traversal).

---

## 8. Hors périmètre (YAGNI)

- SSR / runtime Node hébergé par Jambo (export ZIP pour l'externe).
- Vérification DNS et provisioning SSL automatique (devops).
- Déploiements 1-clic (Vercel/Netlify/Railway) et orchestration Docker/Traefik (supprimés ; réexplorables plus tard sur base propre).
- Build côté serveur (le build reste dans le navigateur via WebContainer).
- Cache/CDN, invalidation, rollback de versions publiées (itération future).
- Multi-tenancy interne d'un projet (orgs/teams) — modélisable via collections au besoin.

---

*Document vivant — mis à jour au fil des décisions. Prochain pas : valider Jambo Sites (1) et/ou écrire le spec Email+Captcha (2).*
