# Skills disponibles pour le projet Jambo

Ce document liste les skills Claude Code utilisables dans ce projet, leur rôle, et quand les utiliser.

## Processus de développement (superpowers)

| Skill | Rôle | Utilisation |
|---|---|---|
| `superpowers:brainstorming` | Concevoir une nouvelle feature avant de coder | Toujours en premier pour les fonctionnalités créatives |
| `superpowers:writing-plans` | Rédiger un plan d'implémentation détaillé | Après validation du design par l'utilisateur |
| `superpowers:subagent-driven-development` | Exécuter le plan tâche par tâche avec sous-agents | Mode recommandé pour exécuter un plan |
| `superpowers:executing-plans` | Exécuter le plan dans cette session | Alternative au subagent-driven |
| `superpowers:finishing-a-development-branch` | Finaliser une branche (merge/PR/discard) | En fin de cycle de développement |
| `superpowers:requesting-code-review` | Revue de code finale | Après toutes les tâches |
| `superpowers:verification-before-completion` | Vérifier qu'un changement fonctionne | Avant de déclarer une tâche terminée |
| `superpowers:using-git-worktrees` | Isoler le travail dans un worktree git | Quand on a besoin d'isolation |
| `superpowers:systematic-debugging` | Déboguer méthodiquement | Face à un bug complexe |
| `superpowers:test-driven-development` | Développer en TDD | Quand les tests sont requis |

## Qualité et revue (pr-review-toolkit)

| Skill | Rôle | Utilisation |
|---|---|---|
| `pr-review-toolkit:code-reviewer` | Revue générale de code | Après avoir écrit un bloc de code |
| `pr-review-toolkit:code-simplifier` | Simplifier le code sans changer le comportement | Après implémentation, avant commit |
| `pr-review-toolkit:silent-failure-hunter` | Traquer les erreurs silencieuses | Pour les blocs try/catch et fallbacks |
| `pr-review-toolkit:type-design-analyzer` | Analyser la conception des types | Avant d'introduire un nouveau type |
| `pr-review-toolkit:comment-analyzer` | Vérifier la qualité des commentaires | Après avoir écrit des docblocks |
| `pr-review-toolkit:pr-test-analyzer` | Vérifier la couverture de tests | Avant de merger une PR |

## Feature development

| Skill | Rôle | Utilisation |
|---|---|---|
| `feature-dev:feature-dev` | Développement complet de feature | Pour les features complexes multi-fichiers |
| `feature-dev:code-architect` | Concevoir l'architecture d'une feature | Design patterns, structure de fichiers |
| `feature-dev:code-explorer` | Explorer le codebase existant | Comprendre une fonctionnalité avant de la modifier |
| `feature-dev:code-reviewer` | Revue de code spécialisée feature | Bugs, logique, sécurité, conventions |

## Navigateur et test (chrome-devtools-mcp / playwright)

| Skill | Rôle | Utilisation |
|---|---|---|
| `chrome-devtools-mcp:chrome-devtools` | Contrôler Chrome via MCP | Tests navigateur automatisés |
| `chrome-devtools-mcp:chrome-devtools-cli` | Tests CLI dans le navigateur | Interagir avec l'interface via terminal |
| `chrome-devtools-mcp:a11y-debugging` | Déboguer l'accessibilité | Vérifier l'a11y de l'interface |
| `chrome-devtools-mcp:troubleshooting` | Résoudre les problèmes Chrome DevTools | Quand le navigateur ne répond pas |

## Frontend

| Skill | Rôle | Utilisation |
|---|---|---|
| `frontend-design:frontend-design` | Design d'interface | Pour concevoir des composants visuels |
| `build-with-wordpress:preview-designs` | Prévisualiser des designs | Mockups visuels |

## Documentation et config

| Skill | Rôle | Utilisation |
|---|---|---|
| `claude-md-management:revise-claude-md` | Mettre à jour CLAUDE.md | Après des changements majeurs au projet |
| `claude-md-management:claude-md-improver` | Améliorer CLAUDE.md | Optimiser les instructions |
| `skill-creator:skill-creator` | Créer un nouveau skill | Pour automatiser une tâche répétitive |
| `update-config` | Modifier la config Claude Code | Permissions, env vars, hooks |

## Commit et CI

| Skill | Rôle | Utilisation |
|---|---|---|
| `commit-commands:commit` | Faire un commit propre | Avant de commiter |
| `commit-commands:commit-push-pr` | Commit + push + PR | Workflow complet |
| `commit-commands:clean_gone` | Nettoyer les branches supprimées | Maintenance git |

## Utilitaires

| Skill | Rôle | Utilisation |
|---|---|---|
| `superpowers:using-superpowers` | Guide d'utilisation des skills | Au début de chaque session |
| `keybindings-help` | Aide sur les raccourcis clavier | Configuration des keybindings |
| `fewer-permission-prompts` | Réduire les demandes de permission | Optimiser le workflow |
| `verify` | Vérifier un changement dans l'app | Valider un fix ou une feature |
| `code-review:code-review` | Revue de code rapide | Pre-commit rapide |
| `simplify` | Simplifier le code modifié | Nettoyage post-implémentation |
| `security-review` | Revue de sécurité | Avant de push en production |

## Workflow recommandé

```
1. superpowers:brainstorming     → Concevoir
2. superpowers:writing-plans     → Planifier
3. superpowers:subagent-driven-development → Implémenter
4. chrome-devtools-mcp:chrome-devtools-cli → Tester dans le navigateur
5. pr-review-toolkit:code-reviewer → Revue finale
6. superpowers:finishing-a-development-branch → Finaliser
7. commit-commands:commit-push-pr → Pousser
```

## Règles importantes

1. **Toujours commencer par `brainstorming`** avant toute feature créative (même simple)
2. **Jamais implémenter sans plan validé** — le plan protège contre les erreurs
3. **Toujours tester dans le navigateur** après les changements frontend
4. **Toujours vérifier l'absence de `Co-Authored-By`** avant de push
5. **Ne jamais skipper la revue de code finale** sur des branches multi-tâches
