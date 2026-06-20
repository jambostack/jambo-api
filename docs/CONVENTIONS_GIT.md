# Conventions Git — Jambo

## Règles de commit

### Auteur unique
- **TOUS les commits doivent avoir comme auteur unique :** `jprud67 <jprud67@gmail.com>`
- **JAMAIS de co-auteur Claude** (`Co-Authored-By: Claude Opus ...`)
- Avant de push, vérifier avec :
  ```bash
  git log --format="%h %an <%ae>" HEAD~5..HEAD
  git log --format="%h %s%n%b" HEAD~5..HEAD | grep "Co-Authored-By"
  ```
- Si des `Co-Authored-By` sont trouvés, les supprimer avec :
  ```bash
  git filter-branch -f --msg-filter "sed '/^Co-Authored-By: Claude/d'" <base>..HEAD
  ```

### Convention de message
- **Format :** `type(scope): description courte`
- **Types :** `feat`, `fix`, `chore`, `docs`, `refactor`, `test`
- **Langue :** Français (l'utilisateur ne comprend pas l'anglais)
- Exemples :
  ```
  feat(v1.14a): créer PreviewTokenService — génération/validation JWT preview
  fix(v1.14a): corriger infinite loop + Select.Item empty value
  chore: ajouter .playwright-mcp/ au .gitignore
  docs: roadmap v3.2 — v1.12b/v1.14a/v1.14b terminés
  ```

### Sécurité
- **Ne jamais commiter de backdoor** (comme `_test_auth.php`)
- **Ne jamais commiter de secrets** (mots de passe, clés API, JWT secrets) — ils doivent rester dans `.env` (gitignored)
- **Ne jamais commiter** `.playwright-mcp/` (captures navigateur)
- Les fichiers de test temporaires doivent être supprimés AVANT le commit final

## Règles de push

### Avant de push
1. `php bin/console cache:clear` doit réussir
2. `npm run build` doit réussir (webpack compiled successfully)
3. `npx tsc --noEmit` sans nouvelle erreur dans les fichiers modifiés
4. `php -l <fichier.php>` sur chaque fichier PHP modifié
5. Vérifier l'absence de `Co-Authored-By` dans les messages de commit
6. L'interface doit être testée dans le navigateur (quand applicable)

### Éviter
- **`--force` sur `git push`** sauf absolument nécessaire (filter-branch)
- **Pousser sur `master`** — utiliser `main`
- **Commit direct sur main** sans test préalable

### Tags
- Format : `v1.12b`, `v1.14a`, `v1.14b`
- Un tag par version livrée
- Mettre à jour le tag après un filter-branch (les commits sont réécrits) :
  ```bash
  git tag -f -a v1.14b <commit> -m "description"
  git push origin v1.14b --force
  ```

## Conventions de code

### PHP (Symfony 8, PHP 8.4)
- **Docblocks :** En français (ou bilingue quand l'API est en anglais)
- **Messages d'erreur API :** En anglais (convention REST)
- **Services :** Injectés via constructeur (`private readonly`)
- **Nommage :** camelCase pour les méthodes, PascalCase pour les classes
- **Commentaires :** `// ─── Section ─────────────────────────────────────`

### TypeScript / React
- **Commentaires :** En français
- **Nommage :** camelCase pour les fonctions/variables, PascalCase pour les composants
- **Imports :** Utiliser les alias `@/` pour les chemins internes
- **Types :** Définir les interfaces dans le fichier du composant ou dans `@/types`

### SDK npm (`@jambostack/live-preview`)
- **Code :** En anglais (package public)
- **Docstrings :** En anglais
- **README :** En anglais
- **Exports :** Fonctions nommées, pas de default export
