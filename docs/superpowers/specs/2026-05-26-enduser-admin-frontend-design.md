# EndUser Admin Frontend — Spécification de design

> **Date:** 2026-05-26 | **Statut:** Validé

## Objectif

Ajouter une interface d'administration des EndUsers dans les paramètres projet du CMS JamboAPI. Permettre à un admin CMS de lister, rechercher, créer, éditer et supprimer les EndUsers d'un projet, ainsi que changer leur statut (bannir/débannir).

## Architecture

**Pattern:** Inertia.js server-side (comme tout le reste du CMS). Les pages sont servies par des contrôleurs Symfony qui passent les données en props. Aucun appel axios direct à l'API REST EndUser depuis le CMS.

**Pourquoi pas l'API REST admin existante:** Les endpoints `/api/{projectId}/users/*` sont protégés par JWT EndUser. L'admin CMS utilise l'auth session Symfony (`User`). Mélanger les deux créerait de la complexité sans valeur ajoutée. L'API REST admin existante reste disponible pour les consommateurs externes.

## Routes (Symfony + Inertia)

| URL | Méthode | Page Inertia | Action |
|---|---|---|---|
| `/projects/{project}/settings/end-users` | GET | `Projects/Settings/EndUsers/Index` | Liste paginée avec filtre |
| `/projects/{project}/settings/end-users/create` | GET | `Projects/Settings/EndUsers/Create` | Formulaire création |
| `/projects/{project}/settings/end-users/create` | POST | — | Store + redirect |
| `/projects/{project}/settings/end-users/{uuid}` | GET | `Projects/Settings/EndUsers/Show` | Détail |
| `/projects/{project}/settings/end-users/{uuid}/edit` | GET | `Projects/Settings/EndUsers/Edit` | Formulaire édition |
| `/projects/{project}/settings/end-users/{uuid}/edit` | PATCH | — | Update + redirect |
| `/projects/{project}/settings/end-users/{uuid}/status` | PATCH | — | Changer statut |
| `/projects/{project}/settings/end-users/{uuid}/delete` | DELETE | — | Supprimer + redirect |

## Composants React

```
assets/js/pages/Projects/Settings/EndUsers/
  Index.tsx        — Tableau avec recherche, filtre statut, pagination, dropdown actions
  Show.tsx         — Page détail avec info user et boutons d'action
  Create.tsx       — Page formulaire de création (nom, email, password, statut)
  Edit.tsx         — Page formulaire d'édition (nom, email, custom_fields, nouveau password)
```

Modifications:
- `assets/js/pages/Projects/Settings/layout.tsx` — Ajouter l'onglet "End Users" dans la sidebar

## Contrôleurs Symfony

- `src/Controller/Admin/EndUserAdminController.php` — CRUD Inertia, auth session CMS
  - `index(Project $project, Request $request)` — liste paginée + filtre statut + recherche
  - `create(Project $project)` — formulaire création
  - `store(Project $project, Request $request)` — persister + redirect
  - `show(Project $project, string $uuid)` — détail
  - `edit(Project $project, string $uuid)` — formulaire édition
  - `update(Project $project, string $uuid, Request $request)` — persister + redirect
  - `updateStatus(Project $project, string $uuid, Request $request)` — changer statut
  - `destroy(Project $project, string $uuid)` — supprimer + redirect

## Flux de données

- Toutes les données passent par des props Inertia (pas d'API REST)
- Validation côté serveur via les contraintes existantes
- Actions destructrices (ban, delete) avec boîte de dialogue de confirmation
- Toast notifications via `sonner` après chaque action réussie
- Pagination côté serveur avec `KnpPaginatorBundle` ou pagination manuelle via le repository

## Gestion des statuts

- **Active** → bouton "Ban User" disponible
- **Banned** → bouton "Unban User" disponible
- **Pending** → boutons "Activate" et "Ban" disponibles
- Changement de statut → `tokenVersion++` invalide tous les tokens JWT existants

## Création manuelle

Formulaire : Name (optionnel), Email (requis), Password (requis, min 8), Status (Active/Pending)
- Le hash du password est fait côté serveur via `PasswordHasherInterface`
- Après création, un JWT n'est PAS généré (l'utilisateur devra se login via l'API)

## Sécurité

- Toutes les routes sont protégées par le firewall `main` (session CMS)
- Accès limité aux admins ayant accès au projet
- L'utilisateur CMS doit avoir un rôle/permission approprié (`ROLE_ADMIN` ou permission projet)
