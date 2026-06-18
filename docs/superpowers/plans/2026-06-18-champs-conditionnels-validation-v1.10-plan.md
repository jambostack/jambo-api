# v1.10 — Champs conditionnels & Validation avancée — Plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ajouter la visibilité conditionnelle des champs et des règles de validation avancée (regex, min/max, unicité) sur les champs de collection Jambo, côté serveur (Symfony) et client (React/TypeScript).

**Architecture:** Une nouvelle colonne `validation_rules` (JSON) sur l'entité Field stocke les règles de validation. Les conditions de visibilité sont stockées dans `options.conditions` (JSON existant). Un nouveau `FieldConditionEvaluator` gère l'évaluation des conditions côté serveur ; `validators.ts` + `ConditionalFieldWrapper.tsx` côté client. La validation s'exécute en deux points : client (feedback immédiat, lecture des règles depuis le schéma) et serveur (sécurité, dans `EavFieldHelperService`).

**Tech Stack:** Symfony 8.0, PHP 8.4, Doctrine ORM, React 18, TypeScript, Inertia.js

## Global Constraints

- PHP 8.4 style : propriétés avec hooks `get`/`set` (asymmetric visibility)
- Colonnes JSON : utiliser le type Doctrine natif `json`
- Frontend : composants React fonctionnels, shadcn/ui, Tailwind
- Format d'erreur API : `{ errors: { fieldSlug: "message" } }`
- Rétrocompatibilité `validationRules = null` → comportement inchangé
- `conditions = []` ou absent → champ toujours visible
- Toutes les conditions d'un champ combinées en ET (AND)
- Pas de champs calculés dans v1.10
- Token API : pas committé, pas exposé au navigateur

---

### Task 1: Migration — Ajouter la colonne `validation_rules` à la table `field`

**Files:**
- Create: `migrations/Version20260618XXXXXX.php`
- Modify: `src/Entity/Field.php`

**Interfaces:**
- Produces: `Field.validationRules` (nullable array via `?array` with get/set hooks)

- [ ] **Step 1: Générer la migration**

```bash
php bin/console make:migration "AddValidationRulesToField"
```

- [ ] **Step 2: Écrire le code de migration**

Vérifier que le fichier généré dans `migrations/` contient :

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260618XXXXXX extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add validation_rules JSON column to field table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE field ADD validation_rules JSON DEFAULT NULL AFTER options');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE field DROP validation_rules');
    }
}
```

- [ ] **Step 3: Ajouter la propriété PHP à l'entité Field**

Dans `src/Entity/Field.php`, ajouter après la propriété `options` (ligne 38) :

```php
#[ORM\Column(type: 'json', nullable: true)]
public ?array $validationRules = null {
    get => $this->validationRules;
    set { $this->validationRules = $value; }
}
```

- [ ] **Step 4: Exécuter la migration**

```bash
php bin/console doctrine:migrations:migrate --no-interaction
```
Expected: `[OK] Successfully migrated to version Version20260618XXXXXX`

- [ ] **Step 5: Commit**

```bash
git add src/Entity/Field.php migrations/Version20260618XXXXXX.php
git commit -m "feat(v1.10): add validation_rules JSON column to field table"
```

---

### Task 2: Service `FieldConditionEvaluator` (backend)

**Files:**
- Create: `src/Service/FieldConditionEvaluator.php`

**Interfaces:**
- Produces: `FieldConditionEvaluator::isVisible(Field $field, array $formData): bool`
- Produces: `FieldConditionEvaluator::evaluateCondition(array $condition, mixed $targetValue): bool`

- [ ] **Step 1: Créer le service**

Fichier `src/Service/FieldConditionEvaluator.php` :

```php
<?php

namespace App\Service;

use App\Entity\Field;

class FieldConditionEvaluator
{
    /**
     * Détermine si un champ doit être visible selon les données du formulaire.
     * Un champ sans conditions est toujours visible.
     * Toutes les conditions sont combinées en ET (AND).
     */
    public function isVisible(Field $field, array $formData): bool
    {
        $conditions = $field->options['conditions'] ?? null;

        if (!is_array($conditions) || empty($conditions)) {
            return true;
        }

        foreach ($conditions as $condition) {
            $targetSlug = $condition['field'] ?? null;
            if ($targetSlug === null) {
                continue;
            }

            $targetValue = $formData[$targetSlug] ?? null;

            if (!$this->evaluateCondition($condition, $targetValue)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Évalue une condition unique contre une valeur cible.
     */
    public function evaluateCondition(array $condition, mixed $targetValue): bool
    {
        $operator = $condition['operator'] ?? 'eq';
        $expectedValue = $condition['value'] ?? null;

        return match ($operator) {
            'empty'     => empty($targetValue) || $targetValue === '' || $targetValue === null || $targetValue === [],
            'notEmpty'  => !empty($targetValue) && $targetValue !== '' && $targetValue !== null && $targetValue !== [],
            'eq'        => $targetValue == $expectedValue,
            'neq'       => $targetValue != $expectedValue,
            'in'        => is_array($expectedValue) && in_array($targetValue, $expectedValue),
            'contains'  => is_string($targetValue) && is_string($expectedValue) && str_contains($targetValue, $expectedValue),
            'startsWith'=> is_string($targetValue) && is_string($expectedValue) && str_starts_with($targetValue, $expectedValue),
            'gt'        => is_numeric($targetValue) && is_numeric($expectedValue) && $targetValue > $expectedValue,
            'gte'       => is_numeric($targetValue) && is_numeric($expectedValue) && $targetValue >= $expectedValue,
            'lt'        => is_numeric($targetValue) && is_numeric($expectedValue) && $targetValue < $expectedValue,
            'lte'       => is_numeric($targetValue) && is_numeric($expectedValue) && $targetValue <= $expectedValue,
            default     => false,
        };
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Service/FieldConditionEvaluator.php
git commit -m "feat(v1.10): add FieldConditionEvaluator service"
```

---

### Task 3: Activer et étendre `EavFieldHelperService::validateValue()` pour lire `validationRules`

**Files:**
- Modify: `src/Service/EavFieldHelperService.php`

**Interfaces:**
- Modifies: `validateValue()` signature inchangée, mais lit maintenant `Field.validationRules`
- Produces: nouvelle méthode `validateFieldValue(Field $field, mixed $value, ?ContentEntry $existingEntry = null): array`

- [ ] **Step 1: Ajouter la méthode de validation enrichie**

Dans `src/Service/EavFieldHelperService.php`, après la méthode `validateValue` (ligne 60), ajouter :

```php
/**
 * Valide une valeur selon les règles de validation du champ ET le type.
 * Retourne un tableau de messages d'erreur (vide = valide).
 */
public function validateFieldValue(Field $field, mixed $value, ?\App\Entity\ContentEntry $existingEntry = null): array
{
    $errors = [];

    // 1. Required check (already enforced elsewhere, but double-check)
    if ($field->isRequired && ($value === null || $value === '' || $value === [])) {
        $errors[] = sprintf('Le champ "%s" est requis.', $field->name);
        return $errors;
    }

    // Si la valeur est vide et non requise, pas de validation supplémentaire
    if ($value === null || $value === '' || $value === []) {
        return [];
    }

    // 2. Validation par type (existante)
    $typeErrors = $this->validateValue($field->type, $value);
    $errors = array_merge($errors, $typeErrors);

    // 3. Validation par validationRules
    $rules = $field->validationRules;
    if (!is_array($rules) || empty($rules)) {
        return $errors;
    }

    // regex
    if (!empty($rules['regex'])) {
        if (is_string($value) && !preg_match($rules['regex'], $value)) {
            $msg = $rules['regexMessage'] ?? sprintf('Le champ "%s" ne respecte pas le format attendu.', $field->name);
            $errors[] = $msg;
        }
    }

    // minLength / maxLength (champs texte)
    if (is_string($value)) {
        $len = mb_strlen($value);
        if (isset($rules['minLength']) && $len < (int)$rules['minLength']) {
            $errors[] = sprintf('Le champ "%s" doit contenir au moins %d caractères.', $field->name, $rules['minLength']);
        }
        if (isset($rules['maxLength']) && $len > (int)$rules['maxLength']) {
            $errors[] = sprintf('Le champ "%s" ne doit pas dépasser %d caractères.', $field->name, $rules['maxLength']);
        }
    }

    // min / max (champs numériques)
    if (is_numeric($value)) {
        $num = (float)$value;
        if (isset($rules['min']) && $num < (float)$rules['min']) {
            $errors[] = sprintf('Le champ "%s" doit être supérieur ou égal à %s.', $field->name, $rules['min']);
        }
        if (isset($rules['max']) && $num > (float)$rules['max']) {
            $errors[] = sprintf('Le champ "%s" doit être inférieur ou égal à %s.', $field->name, $rules['max']);
        }
    }

    // unique (dans la collection, hors entrée courante)
    if (!empty($rules['unique']) && $existingEntry !== null && $field->collection !== null) {
        // On ne fait pas la vérification d'unicité ici — elle nécessite
        // un accès au repository. Elle sera faite dans le contrôleur.
    }

    // custom message (surcharge le dernier message d'erreur si défini)
    if (!empty($rules['custom']) && !empty($errors)) {
        $errors[count($errors) - 1] = $rules['custom'];
    }

    return $errors;
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Service/EavFieldHelperService.php
git commit -m "feat(v1.10): extend EavFieldHelperService with validationRules support"
```

---

### Task 4: Appeler la validation dans les contrôleurs de contenu

**Files:**
- Modify: `src/Controller/ContentController.php` (admin)
- Modify: `src/Controller/Api/ContentController.php` (public API)

**Interfaces:**
- Consumes: `EavFieldHelperService::validateFieldValue(Field $field, mixed $value): array`
- Produces: `{ errors: { fieldSlug: "message" } }` dans les réponses 422

- [ ] **Step 1: Injecter le service dans les contrôleurs**

Dans `src/Controller/ContentController.php`, ajouter le paramètre au constructeur (vérifier si déjà présent). Chercher la ligne du constructeur et ajouter :

```php
private \App\Service\EavFieldHelperService $fieldValidator,
```

Si le service n'est pas déjà injecté. Sinon, l'utiliser directement. Vérifier avec :

```bash
grep -n "use App\\Service\\EavFieldHelperService" src/Controller/ContentController.php
```

Si pas de résultat, ajouter le `use` et le paramètre constructeur. Même chose pour `src/Controller/Api/ContentController.php`.

- [ ] **Step 2: Ajouter la validation dans ContentController (admin) — méthode `create`**

Dans `ContentController::create()` (vers ligne 96), après la création de l'entrée mais avant le flush, ajouter après `$this->saveFieldValues(...)` (ligne 148) :

```php
// Validation des champs
$validationErrors = [];
foreach ($collection->fields as $field) {
    if ($field->isDeleted()) continue;
    $fieldValue = $data['fields'][$field->slug] ?? null;
    $fieldErrors = $this->fieldValidator->validateFieldValue($field, $fieldValue);
    if (!empty($fieldErrors)) {
        $validationErrors['fields.' . $field->slug] = $fieldErrors[0];
    }
}
if (!empty($validationErrors)) {
    return $this->json(['errors' => $validationErrors], 422);
}
```

Même ajout dans la méthode `update()` (vers ligne 167), après avoir setté les valeurs des champs mais avant le flush.

- [ ] **Step 3: Ajouter la validation dans Api\ContentController — méthodes `store` et `update`**

Dans `src/Controller/Api/ContentController.php`, méthode `store()` (vers ligne 153), après `hydrateFieldValues(...)` (ligne 202) et avant `$this->em->flush()` (ligne 204) :

```php
// Validation des champs
$validationErrors = [];
foreach ($collection->fields as $field) {
    if ($field->isDeleted()) continue;
    $fieldValue = $data[$field->slug] ?? null;
    $fieldErrors = $this->fieldValidator->validateFieldValue($field, $fieldValue);
    if (!empty($fieldErrors)) {
        $validationErrors[$field->slug] = $fieldErrors[0];
    }
}
if (!empty($validationErrors)) {
    return $this->json(['errors' => $validationErrors], 422);
}
```

Même ajout dans `update()` après l'hydratation des champs.

**Note:** Dans `Api\ContentController`, `$data` contient les champs à plat (pas dans `fields.`), donc la clé d'erreur est directement `$field->slug`. Dans `ContentController`, le corps est imbriqué `{ fields: { ... } }` donc la clé est `fields.{$field->slug}` (format que `FieldBase.getFieldError()` lit déjà).

- [ ] **Step 4: Vérification — lancer un appel API avec une valeur invalide**

```bash
# Test manuel : créer une entrée avec un champ email invalide
curl -X POST http://localhost/api/PROJECT_UUID/COLLECTION_SLUG \
  -H "Authorization: Bearer TOKEN_WITH_CREATE" \
  -H "Content-Type: application/json" \
  -d '{"email_field": "not-an-email"}'
```
Expected: `422` avec `{"errors":{"email_field":"Format email invalide"}}`

- [ ] **Step 5: Commit**

```bash
git add src/Controller/ContentController.php src/Controller/Api/ContentController.php
git commit -m "feat(v1.10): wire validation into content controllers"
```

---

### Task 5: Accepter `validationRules` dans FieldController et StudioController

**Files:**
- Modify: `src/Controller/FieldController.php`
- Modify: `src/Controller/StudioController.php`

**Interfaces:**
- Produces: `Field.validationRules` persisté depuis les appels API

- [ ] **Step 1: Ajouter `validationRules` dans FieldController**

Dans `src/Controller/FieldController.php`, trouver la méthode qui crée/met à jour un champ (chercher `$field->options`). Ajouter après la ligne qui set `$field->options` :

```php
if (isset($fieldData['validationRules'])) {
    $field->validationRules = $fieldData['validationRules'];
}
```

- [ ] **Step 2: Ajouter dans StudioController::applySchema**

Dans `src/Controller/StudioController.php`, méthode `applySchema()`, à l'intérieur de la boucle qui construit les champs (vers ligne 1271, après `$field->options = ...`) :

```php
if (isset($fieldData['validationRules'])) {
    $field->validationRules = $fieldData['validationRules'];
}
```

- [ ] **Step 3: Commit**

```bash
git add src/Controller/FieldController.php src/Controller/StudioController.php
git commit -m "feat(v1.10): accept validationRules in FieldController and StudioController"
```

---

### Task 6: Validation côté client — `validators.ts`

**Files:**
- Create: `assets/js/lib/validators.ts`

**Interfaces:**
- Produces: `validateFieldValue(value: unknown, field: Field): ValidationError | null`

- [ ] **Step 1: Créer le fichier**

Fichier `assets/js/lib/validators.ts` :

```typescript
import type { Field } from '@/types';

export interface ValidationError {
    fieldSlug: string;
    message: string;
}

/**
 * Valide une valeur de champ selon ses validationRules et son type.
 * Mêmes règles que le serveur (EavFieldHelperService::validateFieldValue).
 * Retourne null si valide, ou une ValidationError si invalide.
 */
export function validateFieldValue(
    value: unknown,
    field: Field
): ValidationError | null {
    // Required check
    if (field.required && (value === null || value === '' || value === undefined || (Array.isArray(value) && value.length === 0))) {
        return { fieldSlug: field.slug, message: `Le champ "${field.label || field.name}" est requis.` };
    }

    // Skip if empty and not required
    if (value === null || value === '' || value === undefined) return null;
    if (Array.isArray(value) && value.length === 0) return null;

    // Type-based validation (mirrors PHP EavFieldHelperService::validateValue)
    if (field.type === 'email' && typeof value === 'string') {
        const emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRe.test(value)) {
            return { fieldSlug: field.slug, message: 'Format email invalide' };
        }
    }
    if (field.type === 'url' && typeof value === 'string') {
        try { new URL(value); } catch { return { fieldSlug: field.slug, message: 'Format URL invalide' }; }
    }
    if ((field.type === 'number' || field.type === 'decimal' || field.type === 'rating') && value !== null && value !== '') {
        if (isNaN(Number(value))) {
            return { fieldSlug: field.slug, message: 'Valeur numérique attendue' };
        }
    }

    // validationRules checks
    const rules = field.validationRules;
    if (!rules) return null;

    // regex
    if (rules.regex && typeof value === 'string') {
        try {
            const re = new RegExp(rules.regex);
            if (!re.test(value)) {
                return { fieldSlug: field.slug, message: rules.regexMessage || `Le champ "${field.label || field.name}" ne respecte pas le format attendu.` };
            }
        } catch {
            // invalid regex — skip silently, server will catch mismatch
        }
    }

    // minLength / maxLength
    if (typeof value === 'string') {
        if (rules.minLength !== undefined && value.length < rules.minLength) {
            return { fieldSlug: field.slug, message: `Le champ "${field.label || field.name}" doit contenir au moins ${rules.minLength} caractères.` };
        }
        if (rules.maxLength !== undefined && value.length > rules.maxLength) {
            return { fieldSlug: field.slug, message: `Le champ "${field.label || field.name}" ne doit pas dépasser ${rules.maxLength} caractères.` };
        }
    }

    // min / max (numeric)
    if (typeof value === 'number' || (typeof value === 'string' && !isNaN(Number(value)))) {
        const num = Number(value);
        if (rules.min !== undefined && num < rules.min) {
            return { fieldSlug: field.slug, message: `Le champ "${field.label || field.name}" doit être supérieur ou égal à ${rules.min}.` };
        }
        if (rules.max !== undefined && num > rules.max) {
            return { fieldSlug: field.slug, message: `Le champ "${field.label || field.name}" doit être inférieur ou égal à ${rules.max}.` };
        }
    }

    return null;
}
```

- [ ] **Step 2: Commit**

```bash
git add assets/js/lib/validators.ts
git commit -m "feat(v1.10): add client-side field validators"
```

---

### Task 7: Hook `useConditionalVisibility` + composant `ConditionalFieldWrapper`

**Files:**
- Create: `assets/js/pages/Content/Fields/ConditionalFieldWrapper.tsx`
- Modify: `assets/js/pages/Content/ContentForm.tsx`

**Interfaces:**
- Consumes: `validateFieldValue()` from `@/lib/validators`
- Produces: `<ConditionalFieldWrapper field={field} formData={formData}>` composant

- [ ] **Step 1: Créer `ConditionalFieldWrapper.tsx`**

Fichier `assets/js/pages/Content/Fields/ConditionalFieldWrapper.tsx` :

```tsx
import React from 'react';
import type { Field } from '@/types';
import { Badge } from '@/components/ui/badge';

interface Props {
    field: Field;
    formData: Record<string, any>;
    children: React.ReactNode;
}

/**
 * Évalue les conditions d'affichage du champ.
 * Masque le champ si les conditions ne sont pas remplies.
 * Affiche un badge "⚡ Conditionnel" si le champ a des conditions.
 */
export default function ConditionalFieldWrapper({ field, formData, children }: Props) {
    const conditions = field.options?.conditions;

    // Pas de conditions → toujours visible
    if (!conditions || !Array.isArray(conditions) || conditions.length === 0) {
        return <>{children}</>;
    }

    // Évaluer toutes les conditions (AND)
    const allMet = conditions.every(cond => {
        const targetValue = formData[cond.field];

        switch (cond.operator) {
            case 'empty':
                return targetValue === null || targetValue === undefined || targetValue === '' || (Array.isArray(targetValue) && targetValue.length === 0);
            case 'notEmpty':
                return targetValue !== null && targetValue !== undefined && targetValue !== '' && !(Array.isArray(targetValue) && targetValue.length === 0);
            case 'eq':
                return targetValue == cond.value;
            case 'neq':
                return targetValue != cond.value;
            case 'in':
                return Array.isArray(cond.value) && cond.value.includes(targetValue);
            case 'contains':
                return typeof targetValue === 'string' && typeof cond.value === 'string' && targetValue.includes(cond.value);
            case 'startsWith':
                return typeof targetValue === 'string' && typeof cond.value === 'string' && targetValue.startsWith(cond.value);
            case 'gt':
                return Number(targetValue) > Number(cond.value);
            case 'gte':
                return Number(targetValue) >= Number(cond.value);
            case 'lt':
                return Number(targetValue) < Number(cond.value);
            case 'lte':
                return Number(targetValue) <= Number(cond.value);
            default:
                return false;
        }
    });

    if (!allMet) {
        return null;
    }

    return (
        <div className="relative">
            <div className="absolute -top-1 right-0 z-10">
                <Badge variant="outline" className="text-[10px] px-1.5 py-0 h-4 bg-amber-50 text-amber-700 border-amber-300">
                    ⚡ Conditionnel
                </Badge>
            </div>
            {children}
        </div>
    );
}
```

- [ ] **Step 2: Intégrer dans `ContentForm.tsx` — boucle des champs**

Dans `assets/js/pages/Content/ContentForm.tsx`, ajouter l'import en haut :

```tsx
import ConditionalFieldWrapper from './Fields/ConditionalFieldWrapper';
```

Puis remplacer la boucle de rendu des champs (lignes 370-383) :

```tsx
{collection.fields.map(field => (
    <ConditionalFieldWrapper key={field.id} field={field} formData={formData}>
        <div className="border border-gray-200 dark:border-gray-800 border-dashed w-full p-4 rounded-md">
            <React.Fragment>
                {renderField({
                    field,
                    value: formData[field.slug],
                    onChange: handleFieldChange,
                    processing,
                    errors,
                    project
                })}
            </React.Fragment>
        </div>
    </ConditionalFieldWrapper>
))}
```

- [ ] **Step 3: Commit**

```bash
git add assets/js/pages/Content/Fields/ConditionalFieldWrapper.tsx assets/js/pages/Content/ContentForm.tsx
git commit -m "feat(v1.10): add ConditionalFieldWrapper and integrate into ContentForm"
```

---

### Task 8: Feedback de validation en temps réel dans ContentForm

**Files:**
- Modify: `assets/js/pages/Content/ContentForm.tsx`

**Interfaces:**
- Consumes: `validateFieldValue()` from `@/lib/validators`

- [ ] **Step 1: Ajouter la validation inline dans `handleFieldChange`**

Dans `assets/js/pages/Content/ContentForm.tsx`, ajouter l'import :

```tsx
import { validateFieldValue } from '@/lib/validators';
```

Dans la fonction `handleFieldChange` (ligne 311), après `setFormData(newData)`, ajouter :

```tsx
// Validation temps réel du champ modifié
const validationError = validateFieldValue(value, field);
setErrors(prev => {
    const next = { ...prev };
    const errorKey = field.options?.repeatable
        ? `fields.${field.slug}`
        : field.slug;
    if (validationError) {
        next[errorKey] = validationError.message;
    } else {
        delete next[errorKey];
    }
    return next;
});
```

Note: Le format de clé d'erreur côté admin (ContentController) utilise `fields.${slug}`, tandis que l'API publique utilise `${slug}` directement. Ici on choisit `fields.${slug}` car ContentForm parle au ContentController (admin). Le composant `FieldBase` lit déjà `errors[fields.${fieldSlug}]` via `getFieldError()`.

- [ ] **Step 2: Valider TOUS les champs à la soumission**

Dans la fonction `handleSubmit` (vers ligne 130), avant l'appel API, ajouter :

```tsx
// Valider tous les champs avant soumission
const allErrors: Record<string, string> = {};
collection.fields.forEach(field => {
    const error = validateFieldValue(formData[field.slug], field);
    if (error) {
        const errorKey = field.options?.repeatable
            ? `fields.${field.slug}`
            : field.slug;
        allErrors[errorKey] = error.message;
    }
});
if (Object.keys(allErrors).length > 0) {
    setErrors(allErrors);
    toast.error(t('content.form.save_error'));
    return; // bloquer la soumission
}
```

- [ ] **Step 3: Commit**

```bash
git add assets/js/pages/Content/ContentForm.tsx
git commit -m "feat(v1.10): add real-time validation feedback in ContentForm"
```

---

### Task 9: UI SchemaBuilder — Blocs Conditions + Validations dans FieldOptionsEditor

**Files:**
- Modify: `assets/js/pages/Projects/Settings/Studio/SchemaBuilder.tsx`
- Modify: `assets/js/types/project.d.ts`

**Interfaces:**
- Produces: interface `FieldValidationRules` dans `project.d.ts`
- Modifies: interface `SchemaField` — ajout de `validationRules`
- Modifies: interface `FieldOptions` — ajout de `conditions`
- Modifies: `FieldOptionsEditor` — ajout de deux blocs pliables

- [ ] **Step 1: Ajouter les interfaces TypeScript**

Dans `assets/js/types/project.d.ts`, ajouter après l'interface `Field` (vers ligne 101) :

```typescript
export interface FieldValidationRules {
    regex?: string;
    regexMessage?: string;
    minLength?: number;
    maxLength?: number;
    min?: number;
    max?: number;
    unique?: boolean;
    custom?: string;
}

export interface FieldCondition {
    field: string;
    operator: 'eq' | 'neq' | 'empty' | 'notEmpty' | 'in' | 'contains' | 'startsWith' | 'gt' | 'gte' | 'lt' | 'lte';
    value: string | number | boolean | string[];
}
```

Puis dans l'interface `Field` existante, ajouter la propriété avant la fermeture :

```typescript
validationRules?: FieldValidationRules;
```

Et dans l'interface `FieldOptions`, ajouter :

```typescript
conditions?: FieldCondition[];
```

- [ ] **Step 2: Ajouter l'interface `SchemaField` étendue dans SchemaBuilder**

Dans `assets/js/pages/Projects/Settings/Studio/SchemaBuilder.tsx`, remplacer l'interface `SchemaField` (ligne 42) :

```tsx
interface SchemaField {
    key: string;
    name: string;
    slug: string;
    type: string;
    isRequired: boolean;
    options?: Record<string, any>;
    validationRules?: Record<string, any>;
}
```

- [ ] **Step 3: Ajouter les blocs Conditions + Validations dans FieldOptionsEditor**

Dans `FieldOptionsEditor` (ligne 1457), après le `return` de chaque bloc de type et avant le `{general}`, ajouter le bloc Conditions et Validations. L'approche la plus propre est d'ajouter une fonction helper et d'insérer ces blocs APRÈS le `{general}` dans chaque `return`.

Ajouter ces deux fonctions helper AVANT `FieldOptionsEditor` (vers ligne 1455) :

```tsx
function ConditionEditor({ field, onChange, otherFields, S }: {
    field: SchemaField;
    onChange: (opts: FieldOptions) => void;
    otherFields: SchemaField[];
    S: { input: React.CSSProperties; label: React.CSSProperties };
}) {
    const t = useTranslation();
    const conditions = (field.options?.conditions as FieldCondition[]) ?? [];
    const OPERATORS: { value: string; label: string }[] = [
        { value: 'eq', label: 'Égal à' },
        { value: 'neq', label: 'Différent de' },
        { value: 'empty', label: 'Est vide' },
        { value: 'notEmpty', label: 'N\'est pas vide' },
        { value: 'contains', label: 'Contient' },
        { value: 'startsWith', label: 'Commence par' },
        { value: 'in', label: 'Dans la liste' },
        { value: 'gt', label: '>' },
        { value: 'gte', label: '>=' },
        { value: 'lt', label: '<' },
        { value: 'lte', label: '<=' },
    ];

    const addCondition = () => {
        const firstField = otherFields[0];
        onChange({
            ...field.options,
            conditions: [
                ...conditions,
                { field: firstField?.slug ?? '', operator: 'eq' as const, value: '' },
            ],
        });
    };

    const updateCondition = (i: number, patch: Partial<FieldCondition>) => {
        const next = conditions.map((c, j) => j === i ? { ...c, ...patch } : c);
        onChange({ ...field.options, conditions: next });
    };

    const removeCondition = (i: number) => {
        onChange({ ...field.options, conditions: conditions.filter((_, j) => j !== i) });
    };

    const needsValue = (op: string) => !['empty', 'notEmpty'].includes(op);

    return (
        <details style={{ marginTop: '8px', fontSize: '10px' }}>
            <summary style={{ cursor: 'pointer', color: 'var(--studio-accent)', fontWeight: 600 }}>
                ⚡ Conditions d'affichage {conditions.length > 0 ? `(${conditions.length})` : ''}
            </summary>
            <div style={{ marginTop: '6px', padding: '6px', border: '1px solid var(--studio-border)', borderRadius: '6px', background: 'var(--studio-surface)', display: 'flex', flexDirection: 'column', gap: '4px' }}>
                <span style={{ ...S.label, color: 'var(--studio-text-dim)', fontSize: '9px' }}>
                    Afficher ce champ uniquement si TOUTES ces conditions sont remplies (ET logique).
                </span>
                {conditions.map((cond, i) => (
                    <div key={i} style={{ display: 'flex', gap: '4px', alignItems: 'center' }}>
                        <select
                            value={cond.field}
                            onChange={e => updateCondition(i, { field: e.target.value })}
                            style={{ ...S.input, flex: 2 }}
                        >
                            <option value="">Champ...</option>
                            {otherFields.map(of => (
                                <option key={of.slug} value={of.slug}>{of.name || of.slug}</option>
                            ))}
                        </select>
                        <select
                            value={cond.operator}
                            onChange={e => updateCondition(i, { operator: e.target.value as any })}
                            style={{ ...S.input, flex: 2 }}
                        >
                            {OPERATORS.map(op => (
                                <option key={op.value} value={op.value}>{op.label}</option>
                            ))}
                        </select>
                        {needsValue(cond.operator) && (
                            <input
                                value={String(cond.value ?? '')}
                                onChange={e => updateCondition(i, { value: e.target.value })}
                                style={{ ...S.input, flex: 1 }}
                                placeholder="Valeur"
                            />
                        )}
                        <button onClick={() => removeCondition(i)}
                            style={{ background: 'none', border: 'none', cursor: 'pointer', padding: '2px' }}>
                            <Trash2 className="w-3 h-3" style={{ color: 'var(--studio-red)' }} />
                        </button>
                    </div>
                ))}
                <Button size="sm" variant="outline" onClick={addCondition}
                    style={{ height: '20px', fontSize: '9px' }}>
                    <Plus className="w-2.5 h-2.5 mr-1" />Ajouter une condition
                </Button>
            </div>
        </details>
    );
}

function ValidationRulesEditor({ rules, onChange, fieldType, S }: {
    rules: Record<string, any>;
    onChange: (r: Record<string, any>) => void;
    fieldType: string;
    S: { input: React.CSSProperties; label: React.CSSProperties };
}) {
    const t = useTranslation();
    const setRules = (r: Record<string, any>) => {
        onChange(r);
    };

    const isTextType = ['text', 'longtext', 'url', 'markdown', 'code', 'slug', 'email', 'password'].includes(fieldType);
    const isNumericType = ['number', 'decimal', 'rating'].includes(fieldType);

    return (
        <details style={{ marginTop: '8px', fontSize: '10px' }}>
            <summary style={{ cursor: 'pointer', color: 'var(--studio-green)', fontWeight: 600 }}>
                ✅ Règles de validation {Object.keys(rules).filter(k => rules[k] !== undefined && rules[k] !== '').length > 0 ? '(configurées)' : ''}
            </summary>
            <div style={{ marginTop: '6px', padding: '6px', border: '1px solid var(--studio-border)', borderRadius: '6px', background: 'var(--studio-surface)', display: 'flex', flexDirection: 'column', gap: '6px' }}>
                {isTextType && (
                    <>
                        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '4px' }}>
                            <div>
                                <span style={S.label}>Longueur min</span>
                                <input type="number" value={rules.minLength ?? ''} onChange={e => setRules({ ...rules, minLength: e.target.value ? Number(e.target.value) : undefined })}
                                    style={{ ...S.input, width: '100%' }} />
                            </div>
                            <div>
                                <span style={S.label}>Longueur max</span>
                                <input type="number" value={rules.maxLength ?? ''} onChange={e => setRules({ ...rules, maxLength: e.target.value ? Number(e.target.value) : undefined })}
                                    style={{ ...S.input, width: '100%' }} />
                            </div>
                        </div>
                        <div>
                            <span style={S.label}>Regex (pattern)</span>
                            <input value={rules.regex ?? ''} onChange={e => setRules({ ...rules, regex: e.target.value })}
                                style={{ ...S.input, width: '100%', fontFamily: 'var(--studio-mono)' }}
                                placeholder="^[a-z0-9_-]+$" />
                        </div>
                        <div>
                            <span style={S.label}>Message si regex invalide</span>
                            <input value={rules.regexMessage ?? ''} onChange={e => setRules({ ...rules, regexMessage: e.target.value })}
                                style={{ ...S.input, width: '100%' }}
                                placeholder="Format attendu : lettres et chiffres uniquement" />
                        </div>
                    </>
                )}
                {isNumericType && (
                    <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '4px' }}>
                        <div>
                            <span style={S.label}>Valeur min</span>
                            <input type="number" value={rules.min ?? ''} onChange={e => setRules({ ...rules, min: e.target.value ? Number(e.target.value) : undefined })}
                                style={{ ...S.input, width: '100%' }} />
                        </div>
                        <div>
                            <span style={S.label}>Valeur max</span>
                            <input type="number" value={rules.max ?? ''} onChange={e => setRules({ ...rules, max: e.target.value ? Number(e.target.value) : undefined })}
                                style={{ ...S.input, width: '100%' }} />
                        </div>
                    </div>
                )}
                {(isTextType || fieldType === 'email') && (
                    <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
                        <Switch checked={rules.unique === true}
                            onCheckedChange={v => setRules({ ...rules, unique: v || undefined })} />
                        <span style={S.label}>Valeur unique dans la collection</span>
                    </div>
                )}
                <div>
                    <span style={S.label}>Message d'erreur personnalisé</span>
                    <input value={rules.custom ?? ''} onChange={e => setRules({ ...rules, custom: e.target.value })}
                        style={{ ...S.input, width: '100%' }}
                        placeholder="Surcharge le message d'erreur par défaut" />
                </div>
            </div>
        </details>
    );
}
```

- [ ] **Step 4: Modifier la signature de `FieldOptionsEditor` et insérer les blocs**

Remplacer la signature de `FieldOptionsEditor` (ligne 1457) :

```tsx
function FieldOptionsEditor({ field, allCollections, allFields, onValidationRulesChange, onChange }: {
    field: SchemaField;
    allCollections: SchemaCollection[];
    allFields: SchemaField[];
    onValidationRulesChange: (rules: Record<string, any>) => void;
    onChange: (opts: FieldOptions) => void;
}) {
```

Dans chaque bloc `return` de `FieldOptionsEditor`, après `{general}` et avant la fermeture du `<div>` principal, ajouter :

```tsx
<ConditionEditor field={field} onChange={(newOpts) => onChange(newOpts)} otherFields={allFields.filter(f => f.key !== field.key)} S={S} />
<ValidationRulesEditor rules={field.validationRules ?? {}} onChange={onValidationRulesChange} fieldType={field.type} S={S} />
```

Dans le composant `FieldRow` (ligne ~2000), qui appelle `FieldOptionsEditor`, mettre à jour les props passées :

```tsx
<FieldOptionsEditor
    field={field}
    allCollections={allCollections}
    allFields={collection.fields}
    onValidationRulesChange={(rules) => updateField(field.key, { validationRules: rules })}
    onChange={(opts) => updateField(field.key, { options: opts })}
/>
```

Où `updateField` est la fonction existante qui met à jour un champ dans le state local du SchemaBuilder.

- [ ] **Step 5: Gérer `validationRules` change dans FieldRow**

Dans `FieldRow`, voir comment `field.options` est modifié via `onChange`. Il faut aussi gérer `validationRules`. Modifier le composant pour propager les changements :

Dans le composant parent qui gère `collections`, lors de la sauvegarde vers `applySchema`, les `validationRules` doivent être inclus. Vérifier que le payload envoyé inclut `validationRules` pour chaque champ. Dans la fonction qui construit le payload (chercher `handleSave` ou `POST /api/projects/.../studio/schema`), s'assurer que :

```tsx
fields: collection.fields.map(f => ({
    name: f.name,
    slug: f.slug,
    type: f.type,
    isRequired: f.isRequired,
    options: f.options,
    validationRules: f.validationRules,  // ← ajout
})),
```

- [ ] **Step 6: Commit**

```bash
git add assets/js/pages/Projects/Settings/Studio/SchemaBuilder.tsx assets/js/types/project.d.ts
git commit -m "feat(v1.10): add Conditions and ValidationRules editors in SchemaBuilder"
```

---

### Task 10: Vérification d'unicité serveur (unique constraint)

**Files:**
- Modify: `src/Service/EavFieldHelperService.php`
- Modify: `src/Controller/ContentController.php`
- Modify: `src/Controller/Api/ContentController.php`

**Interfaces:**
- Consumes: `ContentEntryRepository` pour chercher les doublons

- [ ] **Step 1: Ajouter la vérification d'unicité dans les contrôleurs**

Dans `ContentController::create()` et `ContentController::update()`, après la validation des champs et avant le flush, pour chaque champ avec `validationRules.unique === true` :

```php
// Vérification d'unicité
foreach ($collection->fields as $field) {
    if ($field->isDeleted()) continue;
    $rules = $field->validationRules;
    if (empty($rules['unique'])) continue;

    $fieldValue = $data['fields'][$field->slug] ?? null;
    if ($fieldValue === null || $fieldValue === '' || $fieldValue === []) continue;

    // Chercher une autre entrée avec la même valeur pour ce champ
    $existingEntry = $this->entryRepository->findOneByFieldValue(
                $collection,
                $field,
                $fieldValue,
                isset($entry) ? $entry->uuid : null
            );
    if ($existingEntry !== null) {
        $validationErrors['fields.' . $field->slug] = sprintf(
                    'La valeur "%s" existe déjà pour le champ "%s".',
                    is_array($fieldValue) ? json_encode($fieldValue) : (string)$fieldValue,
                    $field->name
                );
    }
}
```

Note: `findOneByFieldValue` n'existe pas dans ContentEntryRepository. Il faut soit :
1. Ajouter une méthode dans le repository, soit
2. Faire une requête DQL simple

Pour v1.10, on utilise l'approche directe avec le `EntityManagerInterface` :

```php
// Pour les champs texte (cas le plus commun pour unique)
$qb = $this->em->createQueryBuilder();
$qb->select('COUNT(cfv.id)')
   ->from(\App\Entity\ContentFieldValue::class, 'cfv')
   ->join('cfv.contentEntry', 'ce')
   ->where('ce.collection = :collection')
   ->andWhere('cfv.field = :field')
   ->andWhere('cfv.textValue = :value')
   ->andWhere('ce.deletedAt IS NULL')
   ->setParameter('collection', $collection)
   ->setParameter('field', $field)
   ->setParameter('value', (string)$fieldValue);

if (isset($entry)) {
    $qb->andWhere('ce.id != :entryId')
       ->setParameter('entryId', $entry->id);
}

$count = $qb->getQuery()->getSingleScalarResult();
if ($count > 0) {
    $validationErrors['fields.' . $field->slug] = sprintf(
        'La valeur "%s" existe déjà pour le champ "%s".',
        (string)$fieldValue,
        $field->name
    );
}
```

- [ ] **Step 2: Même ajout dans Api\ContentController**

Adapter la même logique pour `Api\ContentController` avec les clés d'erreur plates (`$field->slug` au lieu de `fields.{$field->slug}`).

- [ ] **Step 3: Commit**

```bash
git add src/Controller/ContentController.php src/Controller/Api/ContentController.php
git commit -m "feat(v1.10): add server-side unique constraint validation"
```

---

### Task 11: Test manuel complet du workflow

**Files:** Aucun (test manuel)

- [ ] **Step 1: Configurer un champ conditionnel via le SchemaBuilder**

1. Ouvrir le Studio → Schema Builder
2. Créer une collection test avec deux champs :
   - `status` (enumeration avec valeurs `draft`, `published`)
   - `published_date` (date)
3. Dans les options de `published_date` → bloc `⚡ Conditions d'affichage`
4. Ajouter une condition : `status` `Égal à` `published`
5. Sauvegarder le schéma

- [ ] **Step 2: Configurer des règles de validation**

1. Dans le même champ `status` → bloc `✅ Règles de validation`
2. Ajouter une regex : `^(draft|published)$`
3. Message si invalide : `Le statut doit être draft ou published`
4. Sauvegarder

- [ ] **Step 3: Tester les conditions dans le formulaire**

1. Créer une nouvelle entrée dans la collection test
2. Vérifier que `published_date` est masqué quand `status` = `draft`
3. Passer `status` à `published`
4. Vérifier que `published_date` apparaît avec le badge `⚡ Conditionnel`

- [ ] **Step 4: Tester la validation**

1. Mettre une valeur invalide dans `status` (ex: `archived`)
2. Vérifier que le message d'erreur s'affiche (feedback client)
3. Soumettre le formulaire
4. Vérifier que le serveur retourne aussi l'erreur

- [ ] **Step 5: Vérifier les champs `validationRules` en base**

```sql
SELECT id, name, slug, validation_rules FROM field WHERE validation_rules IS NOT NULL;
```

Expected: Le champ `status` doit avoir `{"regex":"^(draft|published)$","regexMessage":"Le statut doit être draft ou published"}`

- [ ] **Step 6: Vérifier les `conditions` dans `options`**

```sql
SELECT id, name, slug, options FROM field WHERE JSON_EXTRACT(options, '$.conditions') IS NOT NULL;
```

Expected: Le champ `published_date` doit avoir une entrée `conditions` dans son JSON options.

---

### Task 12: Nettoyage & commit final

**Files:** Aucun

- [ ] **Step 1: Lancer les tests existants**

```bash
php bin/phpunit
```

Expected: Tous les tests passent (pas de régression).

- [ ] **Step 2: Vérifier la compilation frontend**

```bash
npm run build
```

Expected: Compilation sans erreur.

- [ ] **Step 3: Commit final**

```bash
git add -A
git commit -m "feat(v1.10): conditional fields & advanced validation

- Add validation_rules JSON column to Field entity
- Add FieldConditionEvaluator service (backend condition logic)
- Extend EavFieldHelperService with validationRules support
- Wire validation into ContentController (admin + public API)
- Accept validationRules in FieldController and StudioController
- Add client-side validators.ts (regex, min/max, unique, required)
- Add ConditionalFieldWrapper component with visual badge
- Add real-time validation feedback in ContentForm
- Add Conditions and ValidationRules editor blocks in SchemaBuilder
- Add server-side unique constraint validation
- Update TypeScript interfaces (FieldValidationRules, FieldCondition)"
```
