# Export/Import de Projet — Document de Design

**Date :** 2026-05-26
**Statut :** Validé

## Résumé

Fonctionnalité d'export/import modulaire de projet permettant de sérialiser tout ou partie d'un projet (structure, contenu, médias, paramètres) dans une archive ZIP, et de le restaurer dans une instance nouvelle ou existante, avec résolution de conflits configurable.

---

## 1. Options d'export modulaires

L'utilisateur peut cocher parmi 4 composants exportables :

| Option | Contenu | Handler |
|---|---|---|
| Structure | Collections + Fields | `StructureExportHandler` |
| Contenu | ContentEntry + ContentFieldValue (EAV) + relations (media, relation fields) | `ContentExportHandler` |
| Médias | Fichiers binaires + Media entities + AssetMetadata | `MediaExportHandler` |
| Paramètres | Localisation (locales, defaultLocale), membres (ProjectMember), tokens API, webhooks | `SettingsExportHandler` |

---

## 2. Architecture

### 2.1 Classes — Approche modulaire par handlers

Tous les nouveaux fichiers sous `src/Service/ExportImport/` :

```
src/
├── Service/ExportImport/
│   ├── ExportHandlerInterface.php
│   ├── ImportHandlerInterface.php
│   ├── ProjectExporter.php          ← orchestrateur
│   ├── ProjectImporter.php          ← orchestrateur
│   ├── ConflictResolver.php
│   ├── Export/
│   │   ├── StructureExportHandler.php
│   │   ├── ContentExportHandler.php
│   │   ├── MediaExportHandler.php
│   │   └── SettingsExportHandler.php
│   └── Import/
│       ├── StructureImportHandler.php
│       ├── ContentImportHandler.php
│       ├── MediaImportHandler.php
│       └── SettingsImportHandler.php
├── Controller/Api/
│   └── ProjectExportImportController.php
├── Command/
│   ├── ProjectExportCommand.php
│   └── ProjectImportCommand.php
└── Dto/
    ├── ExportOptions.php
    ├── ImportOptions.php
    └── ConflictItem.php
```

### 2.2 Interfaces

```php
interface ExportHandlerInterface
{
    public function export(Project $project, string $tempDir): array;
    public static function getOptionKey(): string;
}

interface ImportHandlerInterface
{
    public function import(Project $project, string $extractedDir, ImportOptions $options): void;
    public function previewConflicts(Project $project, string $extractedDir): array;
    public static function getOptionKey(): string;
}
```

---

## 3. Flux d'export

1. L'utilisateur sélectionne les options (structure, contenu, médias, paramètres)
2. `ProjectExporter` crée un dossier temporaire `/tmp/export-{uuid}/`
3. Pour chaque handler activé : `export()` écrit les données JSON dans le dossier temp, retourne le manifest
4. Le dossier temporaire est compressé en ZIP
5. Réponse HTTP : stream du ZIP avec headers `Content-Disposition: attachment`
6. CLI : sauvegarde sur disque au chemin spécifié

## 4. Flux d'import

1. Le ZIP est uploadé (HTTP) ou lu depuis le disque (CLI), extrait dans `/tmp/import-{uuid}/`
2. `ProjectImporter` lit `manifest.json` pour connaître les composants présents
3. Deux modes :
   - **Nouveau projet** : création d'un `Project` vierge, l'utilisateur fournit nom + owner
   - **Fusion** : utilisation du projet cible existant identifié par UUID
4. `previewConflicts()` → chaque handler actif détecte les conflits potentiels
5. L'utilisateur choisit la stratégie globale + ajustements par conflit :
   - `overwrite` : écraser les données existantes
   - `skip` : ignorer les doublons
   - `new_uuids` : générer de nouveaux UUIDs pour tout (pas de conflits)
6. `import()` exécute l'import dans une transaction Doctrine :
   - Ordre : Structure → Médias → Contenu → Paramètres
   - Si un handler échoue → rollback complet
7. Nettoyage du dossier temporaire

## 5. Gestion des conflits

`ConflictResolver` détecte :
- **Collections** : conflit par slug (même slug = même collection)
- **ContentEntry** : conflit par UUID
- **Médias** : conflit par nom de fichier ou UUID

Prévisualisation : tableau listant chaque conflit avec :
- Type d'entité, nom, UUID
- Action suggérée (skip par défaut)
- Possibilité de choisir par conflit ou d'appliquer une stratégie globale

## 6. Structure du package ZIP

```
export-{project-name}-{date}.zip
├── manifest.json
│   {
│     "version": "1.0",
│     "exported_at": "2026-05-26T10:00:00+00:00",
│     "project": { "name": "...", "uuid": "...", "defaultLocale": "en" },
│     "included": ["structure", "content", "media", "settings"]
│   }
├── structure.json     ← collections + fields
├── content.json       ← content entries (EAV values + relations)
├── media.json         ← Media entities metadata (alt, caption, mime, size...)
├── settings.json      ← locales, members, tokens, webhooks
└── media/             ← fichiers binaires avec leur nom original préservé
    ├── abc123.jpg
    └── def456.png
```

## 7. API Endpoints

```
GET  /api/projects/{uuid}/export        ← déclenche l'export (stream ZIP)
POST /api/projects/{uuid}/export/preview ← prévisualisation (taille estimée, nombre d'entités)
POST /api/projects/import               ← upload ZIP + options, retourne le nouveau projet
POST /api/projects/import/preview       ← upload ZIP → analyse des conflits (sans importer)
POST /api/projects/{uuid}/import/merge  ← fusion dans un projet existant
```

## 8. Commandes CLI

```bash
bin/console project:export <uuid> --output=/path/to/export.zip
    --with-content --with-media --with-settings

bin/console project:import /path/to/export.zip
    --project-name="Nom" --owner=email@example.com

bin/console project:import /path/to/export.zip
    --target-project=<uuid> --strategy=overwrite|skip|new-uuids

bin/console project:import /path/to/export.zip
    --target-project=<uuid> --dry-run    ← preview seulement
```

## 9. Interface utilisateur (React)

Nouveaux composants :

- `ExportModal.tsx` — modale avec 4 checkboxes (structure coché par défaut, grisé), bouton Exporter
- `ImportModal.tsx` — modale avec dropzone ZIP, choix nouveau projet / fusion, sélecteur de projet cible
- `ConflictPreview.tsx` — tableau listant les conflits, sélecteur de stratégie par ligne + stratégie globale
- `ProgressBar.tsx` — barre de progression (réutilisable export + import)

## 10. Gestion d'erreurs

- Validation du `manifest.json` avant tout import (version supportée, JSON valide)
- Import wrappé dans une transaction Doctrine — échec → rollback
- Fichiers temporaires nettoyés en cas d'erreur (bloc finally)
- Limite de taille d'upload PHP (`upload_max_filesize`, `post_max_size`) signalée dans l'UI
- Export : streaming JSON pour éviter de tout charger en mémoire (grands volumes)

## 11. Tests

- **Unitaires** : chaque handler testé isolément avec des mocks
- **Intégration** : roundtrip export → import, vérification intégrité des données
- **Conflits** : import dans un projet avec données existantes, vérification des 3 stratégies
- **Médias** : vérification que les fichiers binaires survivent au roundtrip (hash comparé)
- **CLI** : test des commandes console avec différentes options
