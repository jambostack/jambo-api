# v1.10 — Champs conditionnels & Validation avancée — Design Spec

**Date :** 2026-06-18  
**Version cible :** v1.10  
**Roadmap :** Phase 1 — Rattrapage compétitif (été 2026)  
**Effort estimé :** 2–3 jours  
**Statut :** Spécification approuvée

---

## 1. Objectif

Permettre aux champs de collection d'avoir :
- **Visibilité conditionnelle** — un champ s'affiche/masque selon la valeur d'un autre champ
- **Règles de validation avancée** — regex, min/max, unicité, message custom, appliquées côté client ET serveur

Les champs calculés (`type: "computed"`) sont reportés à une version ultérieure.

---

## 2. Architecture des données

### 2.1 Migration base de données

Une nouvelle colonne JSON sur la table `field` :

```sql
ALTER TABLE field ADD validation_rules JSON DEFAULT NULL AFTER options;
```

### 2.2 Entité Field (PHP)

```php
// src/Entity/Field.php — nouvelle propriété
#[ORM\Column(type: 'json', nullable: true)]
public ?array $validationRules {
    set => array|null;
    get => $this->validationRules;
}
```

### 2.3 Structure de `options.conditions` (ajout dans le JSON existant)

```json
{
  "placeholder": "...",
  "helpText": "...",
  "conditions": [
    {
      "field": "status",
      "operator": "eq",
      "value": "published"
    }
  ]
}
```

- `field` : slug du champ cible dans la même collection
- `operator` : un parmi `eq`, `neq`, `empty`, `notEmpty`, `in`, `contains`, `startsWith`, `gt`, `gte`, `lt`, `lte`
- `value` : string | number | boolean | string[] (selon l'opérateur)

Toutes les conditions d'un champ sont combinées en **ET** (AND logique).

### 2.4 Structure de `validationRules` (nouvelle colonne JSON)

```json
{
  "regex": "^[a-z0-9_-]+$",
  "regexMessage": "Uniquement lettres minuscules, chiffres, tirets",
  "minLength": 3,
  "maxLength": 255,
  "min": 0,
  "max": 999,
  "unique": true,
  "custom": "Ce champ doit correspondre à la convention interne"
}
```

Toutes les clés sont optionnelles. `null` signifie « pas de règle ».

### 2.5 Interfaces TypeScript

```typescript
// assets/js/types/project.d.ts

interface FieldCondition {
  field: string;
  operator: 'eq' | 'neq' | 'empty' | 'notEmpty' | 'in' | 'contains' | 'startsWith' | 'gt' | 'gte' | 'lt' | 'lte';
  value: string | number | boolean | string[];
}

interface FieldValidationRules {
  regex?: string;
  regexMessage?: string;
  minLength?: number;
  maxLength?: number;
  min?: number;
  max?: number;
  unique?: boolean;
  custom?: string;
}

// Étendu dans FieldOptions :
interface FieldOptions {
  // ... existant (helpText, placeholder, min, max, pattern, etc.) ...
  conditions?: FieldCondition[];
}

// Étendu dans Field :
interface Field {
  // ... existant ...
  validationRules?: FieldValidationRules;
}
```

---

## 3. Backend — Nouveaux services & modifications

### 3.1 `FieldConditionEvaluator` (nouveau)

```php
// src/Service/FieldConditionEvaluator.php
class FieldConditionEvaluator
{
    /**
     * Retourne true si le champ doit être visible selon les données du formulaire.
     * Un champ sans conditions est toujours visible.
     */
    public function isVisible(Field $field, array $formData): bool;

    /**
     * Évalue une condition unique.
     */
    public function evaluateCondition(FieldCondition $condition, mixed $targetValue): bool;
}
```

Opérateurs supportés côté PHP :

| Opérateur | Logique |
|-----------|---------|
| `eq` | `==` (égalité faible, cohérence formulaire) |
| `neq` | `!=` |
| `empty` | `empty()` ou `=== ''` ou `=== null` |
| `notEmpty` | inverse de `empty` |
| `in` | `in_array($value, $conditionValue)` |
| `contains` | `str_contains()` |
| `startsWith` | `str_starts_with()` |
| `gt` / `gte` / `lt` / `lte` | `>` / `>=` / `<` / `<=` |

### 3.2 Activation de `EavFieldHelperService::validateValue()`

Le service existe déjà mais n'est jamais appelé. Modifications :

- La méthode `validateValue()` est étendue pour lire `Field.validationRules`
- Appelée depuis `ContentController` (admin) ET `Api\ContentController` (API publique)
- Retourne un tableau d'erreurs au format `['fieldSlug' => 'message']`

Règles exécutées par le serveur :
1. **required** → déjà géré par `field.isRequired` (existant)
2. **regex** → `preg_match()` sur `validationRules.regex`
3. **minLength / maxLength** → `mb_strlen()` sur les champs texte
4. **min / max** → comparaison numérique sur les champs number/decimal
5. **unique** → requête Doctrine pour vérifier l'unicité dans la collection (hors entrée courante)
6. **custom** → message d'erreur si une autre règle échoue (surcharge le message par défaut)

Format de réponse d'erreur (déjà supporté par le frontend) :
```json
{
  "errors": {
    "slug": "Le slug ne respecte pas le format attendu (lettres, chiffres, tirets uniquement)."
  }
}
```

### 3.3 Contrôleurs modifiés

| Contrôleur | Modification |
|---|---|
| `ContentController.php` | Appeler `EavFieldHelperService::validateValue()` avant save |
| `Api\ContentController.php` | Appeler la validation (API publique) |
| `FieldController.php` | Accepter `validationRules` dans PUT/POST (pas de validation du JSON, stockage brut) |
| `StudioController.php` | Accepter `validationRules` + `conditions` dans `applySchema` |

---

## 4. Frontend — Composants & hooks

### 4.1 `validators.ts` (nouveau)

```typescript
// assets/js/lib/validators.ts

export interface ValidationError {
  fieldSlug: string;
  message: string;
}

export function validateFieldValue(
  value: unknown,
  field: Field,
  allValues?: Record<string, unknown>
): ValidationError | null;
```

Implémente les mêmes règles que le serveur (regex, min/max, unique locale, required). Appelée côté client pour feedback en temps réel.

### 4.2 `ConditionalFieldWrapper` (nouveau)

```typescript
// assets/js/pages/Content/Fields/ConditionalFieldWrapper.tsx

// Enveloppe le rendu d'un champ. Évalue ses conditions et :
// - Visible : rend le champ normalement avec badge "⚡ Conditionnel"
// - Masqué : ne rend rien (retourne null)
// - En attente : pas d'évaluation si le champ source n'a pas encore de valeur
```

### 4.3 `useConditionalVisibility` (nouveau hook dans ContentForm)

```typescript
// Hook utilisé dans ContentForm.tsx

function useConditionalVisibility(
  fields: Field[],
  formData: Record<string, unknown>
): {
  isVisible: (field: Field) => boolean;
  hiddenCount: number;
};
```

### 4.4 Boucle de rendu dans `ContentForm.tsx`

Modification de la fonction qui itère sur `fields` pour rendre le formulaire :

```tsx
{fields.map(field => (
  <ConditionalFieldWrapper key={field.slug} field={field} formData={formData}>
    {renderField(field, formData, onChange, errors)}
  </ConditionalFieldWrapper>
))}
```

### 4.5 Blocs dans `FieldOptionsEditor` (SchemaBuilder.tsx)

Deux nouvelles sections pliables (collapsible) ajoutées **en bas** du panneau d'options, sous les options de type. Par défaut repliées (fermées).

**Bloc « ⚡ Conditions d'affichage » :**
- Texte d'aide : *"Afficher ce champ uniquement si les conditions suivantes sont remplies (ET logique)."*
- Par condition : select champ source → select opérateur → input valeur
- Bouton `+ Ajouter une condition`
- Bouton 🗑️ pour supprimer une condition
- Stocké dans `field.options.conditions`

**Bloc « ✅ Règles de validation » :**
- Règles affichées selon le type de champ :
  - Texte/Longtexte/URL/Markdown/Code : minLength, maxLength, regex + message, unique
  - Nombre/Note : min, max
  - Email : regex + message, unique
  - Tous types : message custom
- Chaque règle = un input avec label et unité
- Stocké dans `field.validationRules` (pas dans `options`)

---

## 5. Flux de validation (source unique)

```
Schéma GET /api/{project}/{collection}/schema
  ↓
Field: { options: { conditions: [...] }, validationRules: { regex, min, ... } }
  ↓
Client: validateFieldValue(value, field.validationRules)  ← onChange, feedback instantané
  ↓
Soumission POST/PUT /api/.../entries
  ↓
Serveur: EavFieldHelperService::validateValue()           ← sécurité, pas de confiance client
  ↓ (si erreur)
{ errors: { slug: "Message d'erreur" } }                  ← affiché par FieldBase
```

---

## 6. Fichiers impactés

| Fichier | Changement |
|---|---|
| `src/Entity/Field.php` | Nouvelle propriété `validationRules` |
| `migrations/VersionYYYYMMDDXXXXXX.php` | Colonne `validation_rules JSON` |
| `src/Service/FieldConditionEvaluator.php` | **Nouveau** — évaluateur de conditions |
| `src/Service/EavFieldHelperService.php` | Activer `validateValue()` avec lecture de `validationRules` |
| `src/Controller/ContentController.php` | Appel validation à la sauvegarde (admin) |
| `src/Controller/Api/ContentController.php` | Appel validation (API publique) |
| `src/Controller/FieldController.php` | Accepter `validationRules` dans PUT/POST |
| `src/Controller/StudioController.php` | Accepter `validationRules` + `conditions` dans applySchema |
| `assets/js/types/project.d.ts` | Nouvelles interfaces |
| `assets/js/lib/fields.json` | Icône/description champ `computed` réservé mais non implémenté |
| `assets/js/lib/validators.ts` | **Nouveau** — validation côté client |
| `assets/js/pages/Projects/Settings/Studio/SchemaBuilder.tsx` | Blocs Conditions + Validations dans `FieldOptionsEditor` |
| `assets/js/pages/Content/ContentForm.tsx` | Hook `useConditionalVisibility` + wrapper |
| `assets/js/pages/Content/Fields/ConditionalFieldWrapper.tsx` | **Nouveau** — wrapper conditionnel |
| `assets/js/pages/Content/Fields/FieldBase.tsx` | Badge conditionnel dans le label |

---

## 7. Rétrocompatibilité

- `validationRules` = `null` → aucune validation ajoutée, comportement inchangé
- `conditions` absent ou `[]` → champ toujours visible, comportement inchangé
- Les collections existantes sans ces propriétés fonctionnent exactement comme avant
- La migration ajoute une colonne nullable, pas de valeur par défaut requise
- Le `pattern` existant dans `options` n'est PAS migré automatiquement — les utilisateurs ajoutent les règles explicitement

---

## 8. Non inclus (périmètre exclu)

- Champs calculés (`type: "computed"`) — reporté
- Conditions avec logique OR — seulement AND en v1.10
- Conditions imbriquées (A AND (B OR C)) — sera ajouté si la demande émerge
- Opérateur `regex` dans les conditions — complexité inutile pour v1
- Migration automatique de `pattern` → `validationRules.regex` — manuel
- Validation client de l'unicité en temps réel — nécessiterait un endpoint dédié

---

## 9. Message de commit prévu

```
feat(v1.10): conditional fields & advanced validation
```

---

*Document généré le 18 juin 2026 — basé sur l'analyse du code source (Field.php, ContentController.php, SchemaBuilder.tsx, ContentForm.tsx, FieldFormModal.tsx).*
