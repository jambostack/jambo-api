# Publication planifiée (v1.7.0) — Design Spec

> **Statut :** Validé · **Date :** 2026-06-17 · **Version cible :** v1.7.0

---

## 1. Objectif

Permettre à un utilisateur de programmer la publication d'une entrée à une date future.
L'entrée passe en statut `scheduled`, puis une commande cron la publie automatiquement
à l'heure dite. Aucune rupture — `draft` et `published` restent le comportement par défaut.

---

## 2. Modèle de données

### 2.1 Entité `ContentEntry`

Ajout d'un champ :

```php
#[ORM\Column(nullable: true)]
public ?\DateTimeImmutable $scheduledAt = null;
```

### 2.2 Statut `scheduled`

Le setter de `status` évolue :

| Transition | Comportement |
|---|---|
| `draft` → `published` | `publishedAt = now` (inchangé) |
| `draft` → `scheduled` | `scheduledAt = <date future>` |
| `scheduled` → `published` | `publishedAt = now`, `scheduledAt = null` (par le cron) |

La colonne `status` reste `VARCHAR(50)` — pas de migration de schéma hormis l'ajout de `scheduled_at`.

### 2.3 Base de données

Migration Doctrine pour ajouter `scheduled_at DATETIME DEFAULT NULL` sur `content_entry`.

---

## 3. Commande cron

### 3.1 `app:publish-scheduled`

Exécutée toutes les minutes :

```
SELECT * FROM content_entry
WHERE scheduled_at <= NOW()
  AND status = 'scheduled'
  AND deleted_at IS NULL
```

Pour chaque entrée :
1. `status = 'published'` → le setter existant définit `publishedAt = now`
2. `scheduledAt = null`
3. `$em->flush()`
4. Dispatch `ContentPublishedMessage` async (pour webhooks / effets de bord futurs)

La commande utilise `EntityManagerInterface` + `ContentEntryRepository`. Pas d'appel HTTP bloquant.

### 3.2 Planification système

Crontab (ou planificateur Windows / Laragon) :

```
* * * * * php bin/console app:publish-scheduled
```

---

## 4. API

### 4.1 REST

- `POST /api/{project}/collections/{slug}` — le champ `status` accepte désormais `scheduled` en plus de `draft` et `published`
- Si `status = 'scheduled'`, le champ `scheduledAt` (ISO 8601) est requis
- `PATCH /api/{project}/collections/{slug}/{uuid}` — idem
- `GET` — le filtre `?status=scheduled` est accepté

### 4.2 `EavDataFormatterService::formatEntry()`

Ajout de `scheduled_at` dans la réponse plate :

```php
'scheduled_at' => $entry->scheduledAt?->format(\DateTimeInterface::ATOM),
```

### 4.3 GraphQL

`scheduledAt` → `Type::string()` (date ISO 8601). Aucun changement nécessaire dans `SchemaGenerator` pour le statut — `status` est déjà `string`.

---

## 5. Studio (frontend)

### 5.1 Formulaire d'édition (`ContentForm.tsx`)

Nouveau bouton **« Planifier »** à côté de « Enregistrer comme brouillon » et « Enregistrer et publier » :

- Ouvre un datepicker (date + heure)
- L'utilisateur choisit une date **future**
- Soumet avec `status = 'scheduled'` + `scheduledAt = <ISO 8601>`
- Si l'entrée est déjà `scheduled` : boutons « Publier maintenant » (bypass le cron) et « Replanifier »

Le datepicker utilise `react-day-picker` (déjà installé, `^10.0.1`).

### 5.2 Liste de contenu (`ContentList.tsx`)

- Badge distinct pour `scheduled` (bleu « Planifié »)
- Filtre `scheduled` dans le dropdown de statut (en plus de `draft` et `published`)

---

## 6. Ce qui ne change pas

- `draft` et `published` : comportement inchangé
- Webhooks : `content.updated` déjà dispatché lors du changement de statut
- Export/Import : `scheduledAt` exporté et importé comme les autres dates
- Permissions : pas de nouvelle capacité nécessaire

---

## 7. Hors périmètre

- Récurrence (publier tous les lundis)
- File d'attente de publication avec UI dédiée
- Annulation automatique si l'entrée est modifiée après planification
- Notifications utilisateur (arrivera en v1.12.0 avec la collaboration)

---

## 8. Auto-revue

- [x] Aucun placeholder — pas de TBD ni TODO
- [x] Cohérence : `scheduledAt` nommé identiquement dans l'entité, l'API, le formatter
- [x] Scope : 1 jalon, 1 feature. Pas de sous-systèmes imbriqués
- [x] Additif : aucun comportement existant modifié
