# Publication planifiée (v1.7.0) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permettre de planifier la publication d'une entrée à une date future via un champ `scheduledAt` et une commande cron `app:publish-scheduled`.

**Architecture:** Approche cron simple — une commande Symfony exécutée toutes les minutes vérifie les entrées dont `scheduledAt <= now()` en statut `scheduled` et les publie. Statut `scheduled` ajouté au modèle existant `draft`/`published` sans casser l'existant. Aucune nouvelle dépendance.

**Tech Stack:** Symfony 8 / PHP 8.4, Doctrine ORM 3.6, PHPUnit, React 19 + TypeScript + Inertia, react-day-picker (déjà installé).

## Global Constraints

- PHP 8.4, Symfony 8, Doctrine ORM 3.6
- Tout est additif — `draft` et `published` restent le comportement par défaut
- Le statut `scheduled` est une troisième valeur, pas un remplacement
- `scheduledAt` est un `DateTimeImmutable` nullable, format ISO 8601 dans l'API
- La commande cron utilise `EntityManagerInterface` + requête DQL directe — pas d'appel HTTP
- Messages d'erreur et libellés UI en français
- Auteur des commits : `jprud67 <jprud67@gmail.com>`, jamais de Co-Authored-By

---

## File Structure

**Backend (modifiés) :**
- `src/Entity/ContentEntry.php` — ajout `scheduledAt` + setter `status` évolué
- `src/Service/EavDataFormatterService.php` — `formatEntry()` expose `scheduled_at`
- `src/Controller/Api/ContentController.php` — accepte `scheduled` + `scheduledAt` dans create/update

**Backend (créés) :**
- `src/Command/PublishScheduledEntriesCommand.php` — `app:publish-scheduled`
- `migrations/` — migration Doctrine auto-générée pour `scheduled_at`

**Frontend (modifiés) :**
- `assets/js/pages/Content/ContentForm.tsx` — bouton Planifier + datepicker
- `assets/js/pages/Content/ContentList.tsx` — badge bleu « Planifié » + filtre `scheduled`
- `assets/js/pages/Content/ContentTrash.tsx` — badge `scheduled` dans la corbeille

**Tests (créés/modifiés) :**
- `tests/Command/PublishScheduledEntriesCommandTest.php` — test unitaire de la commande
- `tests/Service/EavDataFormatterServiceTest.php` — ajout test `scheduled_at`
- `tests/Controller/Api/ContentControllerTest.php` — ajout test création avec `status=scheduled`

---

## Task 1 : Migration + Entité — `scheduledAt`

**Files:**
- Modify: `src/Entity/ContentEntry.php`
- Create: `migrations/VersionXXXXXXXXXXXXXXAddScheduledAt.php` (via `make:migration`)

**Interfaces:**
- Consumes: rien
- Produces: `ContentEntry.scheduledAt: ?\DateTimeImmutable`, setter `status` accepte `'scheduled'`

- [ ] **Step 1 : Ajouter le champ `scheduledAt` à l'entité**

Dans `src/Entity/ContentEntry.php`, après la ligne 75 (`public ?\DateTimeImmutable $publishedAt = null;`), ajouter :

```php
    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $scheduledAt = null;
```

- [ ] **Step 2 : Modifier le setter `status` pour accepter `scheduled`**

Dans `src/Entity/ContentEntry.php`, remplacer le setter `status` (lignes 30-37) par :

```php
    #[ORM\Column(length: 50)]
    public string $status = 'draft' {
        get => $this->status;
        set {
            if ($value === 'published' && $this->status !== 'published' && $this->publishedAt === null) {
                $this->publishedAt = new \DateTimeImmutable();
            }
            if ($value === 'published' && $this->status === 'scheduled') {
                $this->scheduledAt = null;
            }
            $this->status = $value;
        }
    }
```

- [ ] **Step 3 : Générer la migration**

```bash
php bin/console make:migration
```

Vérifier que la migration générée contient bien :

```sql
ALTER TABLE content_entry ADD scheduled_at DATETIME DEFAULT NULL;
```

- [ ] **Step 4 : Lancer les tests existants pour vérifier l'absence de régression**

```bash
vendor/bin/phpunit
```
Expected: tous les tests passants actuellement restent passants. Aucune régression.

- [ ] **Step 5 : Commit**

```bash
git add src/Entity/ContentEntry.php migrations/
git commit -m "feat(scheduled-publishing): add scheduledAt field and scheduled status to ContentEntry"
```

---

## Task 2 : API — accepter `scheduled` + exposer `scheduled_at`

**Files:**
- Modify: `src/Service/EavDataFormatterService.php:29-43`
- Modify: `src/Controller/Api/ContentController.php`
- Modify: `tests/Service/EavDataFormatterServiceTest.php`
- Modify: `tests/Controller/Api/ContentControllerTest.php`

**Interfaces:**
- Consumes: `ContentEntry.scheduledAt` (de Task 1)
- Produces: `scheduled_at` dans le JSON de sortie API ; `status=scheduled` accepté en entrée

- [ ] **Step 1 : Ajouter `scheduled_at` dans le formatter**

Dans `src/Service/EavDataFormatterService.php`, méthode `formatEntry`, remplacer le bloc `$data = [...]` (lignes 31-43). Ajouter `'scheduled_at'` après `'published_at'` (ligne 40) :

```php
        $data = [
            'id'           => $entry->id,
            'uuid'         => $entry->uuid?->toRfc4122(),
            'locale'       => $entry->locale,
            'status'       => $entry->status,
            'collection'   => $entry->collection?->slug,
            'created_at'   => $entry->createdAt->format(\DateTimeInterface::ATOM),
            'updated_at'   => $entry->updatedAt->format(\DateTimeInterface::ATOM),
            'deleted_at'   => $entry->deletedAt?->format(\DateTimeInterface::ATOM),
            'published_at' => $entry->publishedAt?->format(\DateTimeInterface::ATOM),
            'scheduled_at' => $entry->scheduledAt?->format(\DateTimeInterface::ATOM),
            'creator'      => $entry->createdBy ? ['name' => $entry->createdBy->name ?: $entry->createdBy->email] : null,
            'updater'      => $entry->updatedBy ? ['name' => $entry->updatedBy->name ?: $entry->updatedBy->email] : null,
        ];
```

- [ ] **Step 2 : Ajouter le test du `scheduled_at` dans le formatter**

Dans `tests/Service/EavDataFormatterServiceTest.php`, ajouter cette méthode avant `makeEntry` :

```php
    public function testFormatEntryIncludesScheduledAtWhenSet(): void
    {
        $entry = $this->makeEntry();
        $entry->scheduledAt = new \DateTimeImmutable('2026-07-01T12:00:00+00:00');

        $result = $this->formatter->formatEntry($entry);

        $this->assertSame('2026-07-01T12:00:00+00:00', $result['scheduled_at']);
    }

    public function testFormatEntryScheduledAtNullWhenNotSet(): void
    {
        $entry = $this->makeEntry();

        $result = $this->formatter->formatEntry($entry);

        $this->assertNull($result['scheduled_at']);
    }
```

- [ ] **Step 3 : Lancer le test pour vérifier l'échec**

```bash
vendor/bin/phpunit --filter EavDataFormatterServiceTest
```
Expected: FAIL — `scheduled_at` absent ou incorrect

- [ ] **Step 4 : Vérifier le succès après Step 1**

```bash
vendor/bin/phpunit --filter EavDataFormatterServiceTest
```
Expected: PASS

- [ ] **Step 5 : Accepter `status=scheduled` dans le contrôleur API**

Dans `src/Controller/Api/ContentController.php`, trouver la ligne :

```php
$statusFilter = in_array($status, ['draft', 'published'], true) ? $status : null;
```

Remplacer par :

```php
$statusFilter = in_array($status, ['draft', 'published', 'scheduled'], true) ? $status : null;
```

- [ ] **Step 6 : Vérifier l'acceptation de `scheduled` dans les paramètres OpenAPI**

Dans `src/Controller/Api/ContentController.php`, trouver les annotations/attributs OA\Parameter et OA\Property qui listent `enum: ['draft', 'published']` et ajouter `'scheduled'` :

Pour `listAction` (ligne ~48) :
```php
new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['draft', 'published', 'scheduled'])),
```

Pour `createAction` (ligne ~133) :
```php
new OA\Property(property: 'status', type: 'string', enum: ['draft', 'published', 'scheduled'], default: 'draft'),
```

Pour `updateAction` (ligne ~186) :
```php
new OA\Property(property: 'status', type: 'string', enum: ['draft', 'published', 'scheduled']),
```

- [ ] **Step 7 : Lancer les tests API**

```bash
vendor/bin/phpunit tests/Controller/Api/ContentControllerTest.php
```
Expected: PASS ou échecs préexistants uniquement (pas de nouvelle régression)

- [ ] **Step 8 : Commit**

```bash
git add src/Service/EavDataFormatterService.php src/Controller/Api/ContentController.php tests/Service/EavDataFormatterServiceTest.php
git commit -m "feat(scheduled-publishing): expose scheduled_at in API output, accept scheduled status"
```

---

## Task 3 : Commande cron `app:publish-scheduled`

**Files:**
- Create: `src/Command/PublishScheduledEntriesCommand.php`
- Create: `tests/Command/PublishScheduledEntriesCommandTest.php`

**Interfaces:**
- Consumes: `ContentEntry.status = 'scheduled'`, `ContentEntry.scheduledAt`, `ContentEntryRepository`
- Produces: `app:publish-scheduled` commande console

- [ ] **Step 1 : Créer le test de la commande**

Créer `tests/Command/PublishScheduledEntriesCommandTest.php` :

```php
<?php

namespace App\Tests\Command;

use App\Command\PublishScheduledEntriesCommand;
use App\Entity\ContentEntry;
use App\Repository\ContentEntryRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class PublishScheduledEntriesCommandTest extends TestCase
{
    public function testCommandPublishesScheduledEntries(): void
    {
        $entry = $this->createMock(ContentEntry::class);
        $entry->status = 'scheduled';

        $repository = $this->createMock(ContentEntryRepository::class);
        $repository->method('findScheduledToPublish')
            ->willReturn([$entry]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $command = new PublishScheduledEntriesCommand($em);
        $command->setRepository($repository);

        $tester = new CommandTester($command);
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $this->assertSame('published', $entry->status);
        $this->assertNull($entry->scheduledAt);
        $this->assertStringContainsString('1 entrée(s) publiée(s)', $tester->getDisplay());
    }

    public function testCommandDoesNothingWhenNoScheduledEntries(): void
    {
        $repository = $this->createMock(ContentEntryRepository::class);
        $repository->method('findScheduledToPublish')
            ->willReturn([]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('flush');

        $command = new PublishScheduledEntriesCommand($em);
        $command->setRepository($repository);

        $tester = new CommandTester($command);
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('Aucune entrée à publier', $tester->getDisplay());
    }
}
```

- [ ] **Step 2 : Lancer le test pour vérifier l'échec**

```bash
vendor/bin/phpunit tests/Command/PublishScheduledEntriesCommandTest.php
```
Expected: FAIL — la classe `PublishScheduledEntriesCommand` n'existe pas

- [ ] **Step 3 : Créer la méthode `findScheduledToPublish` dans le repository**

Dans `src/Repository/ContentEntryRepository.php`, ajouter la méthode :

```php
    /**
     * @return ContentEntry[]
     */
    public function findScheduledToPublish(): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.status = :status')
            ->andWhere('e.scheduledAt <= :now')
            ->andWhere('e.deletedAt IS NULL')
            ->setParameter('status', 'scheduled')
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getResult();
    }
```

- [ ] **Step 4 : Créer la commande**

Créer `src/Command/PublishScheduledEntriesCommand.php` :

```php
<?php

namespace App\Command;

use App\Repository\ContentEntryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:publish-scheduled',
    description: 'Publie les entrées dont la date de planification est atteinte.',
)]
class PublishScheduledEntriesCommand extends Command
{
    private ?ContentEntryRepository $repository = null;

    public function __construct(
        private EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    /**
     * @internal pour les tests uniquement
     */
    public function setRepository(ContentEntryRepository $repository): void
    {
        $this->repository = $repository;
    }

    private function getRepository(): ContentEntryRepository
    {
        // Utilisé uniquement en test via setRepository
        return $this->repository;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $entries = $this->getRepository()->findScheduledToPublish();

        if (empty($entries)) {
            $output->writeln('Aucune entrée à publier.');
            return Command::SUCCESS;
        }

        $count = 0;
        foreach ($entries as $entry) {
            $entry->status = 'published'; // le setter définit publishedAt = now et scheduledAt = null
            $count++;
        }

        $this->em->flush();

        $output->writeln(sprintf('%d entrée(s) publiée(s).', $count));
        return Command::SUCCESS;
    }
}
```

- [ ] **Step 5 : Lancer le test pour vérifier le succès**

```bash
vendor/bin/phpunit tests/Command/PublishScheduledEntriesCommandTest.php
```
Expected: PASS (2 tests, 2 assertions)

- [ ] **Step 6 : Commit**

```bash
git add src/Command/PublishScheduledEntriesCommand.php src/Repository/ContentEntryRepository.php tests/Command/PublishScheduledEntriesCommandTest.php
git commit -m "feat(scheduled-publishing): add app:publish-scheduled command"
```

---

## Task 4 : Frontend — ContentForm (bouton Planifier + datepicker)

**Files:**
- Modify: `assets/js/pages/Content/ContentForm.tsx`
- Modify: `assets/js/types/content.ts` (si `SaveStatus` est défini ailleurs)

**Interfaces:**
- Consumes: `status = 'scheduled'`, `scheduledAt` (ISO 8601) à envoyer à l'API
- Produces: bouton « Planifier » dans le formulaire d'édition

- [ ] **Step 1 : Lire le ContentForm actuel**

Lire `assets/js/pages/Content/ContentForm.tsx` pour comprendre :
- La définition de `SaveStatus` (ligne ~33)
- La fonction `handleSubmit` et comment elle envoie `status`
- Les boutons d'action existants (brouillon, publier)

- [ ] **Step 2 : Ajouter `scheduled` à `SaveStatus`**

Dans `assets/js/pages/Content/ContentForm.tsx`, modifier la ligne :

```tsx
type SaveStatus = 'draft' | 'published';
```

En :

```tsx
type SaveStatus = 'draft' | 'published' | 'scheduled';
```

- [ ] **Step 3 : Ajouter un state pour la date de planification**

Après les states existants (chercher `const [localStatus`), ajouter :

```tsx
const [showSchedulePicker, setShowSchedulePicker] = useState(false);
const [scheduledDate, setScheduledDate] = useState<string>('');
const [scheduledTime, setScheduledTime] = useState<string>('12:00');
```

- [ ] **Step 4 : Ajouter les boutons d'action « Planifier »**

Trouver les boutons « Enregistrer comme brouillon » et « Enregistrer et publier ».
Après le bouton « Enregistrer et publier », ajouter :

```tsx
{!showSchedulePicker && (
    <Button
        type="button"
        variant="outline"
        onClick={() => setShowSchedulePicker(true)}
        disabled={processing}
    >
        <Calendar className="w-4 h-4 mr-2" />
        Planifier
    </Button>
)}
```

- [ ] **Step 5 : Ajouter le datepicker de planification**

Après les boutons d'action, ajouter le panneau de planification :

```tsx
{showSchedulePicker && (
    <div className="flex items-center gap-3 p-3 border rounded-md bg-muted/30">
        <div className="flex items-center gap-2">
            <Label htmlFor="scheduled-date" className="text-sm">Date</Label>
            <Input
                id="scheduled-date"
                type="date"
                value={scheduledDate}
                min={new Date().toISOString().split('T')[0]}
                onChange={(e) => setScheduledDate(e.target.value)}
                className="w-36"
            />
        </div>
        <div className="flex items-center gap-2">
            <Label htmlFor="scheduled-time" className="text-sm">Heure</Label>
            <Input
                id="scheduled-time"
                type="time"
                value={scheduledTime}
                onChange={(e) => setScheduledTime(e.target.value)}
                className="w-28"
            />
        </div>
        <Button
            type="button"
            variant="default"
            onClick={async () => {
                if (!scheduledDate) return;
                const scheduledAt = `${scheduledDate}T${scheduledTime}:00`;
                await handleSubmit('stay', 'scheduled', scheduledAt);
                setShowSchedulePicker(false);
            }}
            disabled={processing || !scheduledDate}
        >
            Confirmer la planification
        </Button>
        <Button
            type="button"
            variant="ghost"
            size="icon"
            onClick={() => setShowSchedulePicker(false)}
        >
            <X className="w-4 h-4" />
        </Button>
    </div>
)}
```

- [ ] **Step 6 : Modifier `handleSubmit` pour accepter `scheduledAt`**

Modifier la signature de `handleSubmit` pour accepter un paramètre optionnel `scheduledAt` :

```tsx
const handleSubmit = async (action: SaveAction, status: SaveStatus, scheduledAt?: string) => {
```

Et dans le corps, ajouter `scheduledAt` dans les données envoyées quand il est présent :

```tsx
const payload: any = { fields: formData, status, locale };
if (scheduledAt) {
    payload.scheduledAt = scheduledAt;
}
```

- [ ] **Step 7 : Ajouter le bouton « Publier maintenant » pour une entrée `scheduled`**

Si l'entrée est déjà en statut `scheduled` (mode édition), remplacer les boutons standard par :

```tsx
{isEditMode && contentEntry?.status === 'scheduled' && (
    <>
        <Button
            type="button"
            variant="default"
            onClick={() => handleSubmit('stay', 'published')}
            disabled={processing}
        >
            Publier maintenant
        </Button>
        <Button
            type="button"
            variant="outline"
            onClick={() => setShowSchedulePicker(true)}
            disabled={processing}
        >
            Replanifier
        </Button>
    </>
)}
```

- [ ] **Step 8 : Vérifier la compilation TypeScript**

```bash
npm run build
```
Expected: build réussi, aucune erreur TypeScript dans ContentForm. (Erreur swagger-ui-react = préexistante, ignorée.)

- [ ] **Step 9 : Commit**

```bash
git add assets/js/pages/Content/ContentForm.tsx
git commit -m "feat(studio): add scheduling UI — datepicker + Planifier button in ContentForm"
```

---

## Task 5 : Frontend — Badge « Planifié » dans les listes

**Files:**
- Modify: `assets/js/pages/Content/ContentList.tsx`
- Modify: `assets/js/pages/Content/ContentTrash.tsx`

**Interfaces:**
- Consumes: `ContentEntry.status` peut valoir `'scheduled'`
- Produces: badge bleu « Planifié », filtre `scheduled` dans le dropdown

- [ ] **Step 1 : Ajouter `scheduled` au filtre de statut**

Dans `assets/js/pages/Content/ContentList.tsx`, trouver le filtre de statut (lignes ~210-211) :

```tsx
{ label: t('content.draft'), value: 'draft' },
{ label: t('content.published'), value: 'published' },
```

Ajouter :

```tsx
{ label: t('content.scheduled'), value: 'scheduled' },
```

- [ ] **Step 2 : Ajouter le badge bleu « Planifié »**

Dans la colonne de statut (lignes ~215-220), ajouter le cas `scheduled` :

```tsx
<Badge variant={item.status === 'published' ? 'default' : item.status === 'scheduled' ? 'secondary' : item.status === 'trashed' ? 'destructive' : 'outline'} className={
    item.status === 'published'
        ? 'bg-green-600 hover:bg-green-700'
        : item.status === 'scheduled'
            ? 'bg-blue-500 hover:bg-blue-600 text-white'
            : item.status === 'trashed' ? 'bg-red-600 hover:bg-red-700' : 'text-amber-600 border-amber-300'
}>
    {item.status === 'published' ? t('content.published') : item.status === 'scheduled' ? t('content.scheduled') : item.status === 'trashed' ? t('content.trashed') : t('content.draft')}
</Badge>
```

- [ ] **Step 3 : Ajouter la colonne `scheduled_at` dans les colonnes réservées**

Dans `RESERVED_SLUGS` (ligne ~229), ajouter `'scheduled_at'` :

```tsx
const RESERVED_SLUGS = new Set(['status', 'created_at', 'updated_at', 'deleted_at', 'published_at', 'scheduled_at', 'uuid', 'id']);
```

- [ ] **Step 4 : Reproduire le badge dans ContentTrash**

Dans `assets/js/pages/Content/ContentTrash.tsx`, appliquer la même modification du badge de statut (chercher le `Badge` avec `item.status`).

- [ ] **Step 5 : Vérifier la compilation TypeScript**

```bash
npm run build
```
Expected: build réussi.

- [ ] **Step 6 : Commit**

```bash
git add assets/js/pages/Content/ContentList.tsx assets/js/pages/Content/ContentTrash.tsx
git commit -m "feat(studio): add scheduled status badge and filter in content lists"
```

---

## Task 6 : Traductions + vérification bout en bout

**Files:**
- Modify: `translations/messages.fr.php`
- Modify: `translations/messages.en.php`
- Modify: `translations/messages.ar.php`
- Modify: `translations/messages.es.php`

**Interfaces:**
- Consumes: clés de traduction `content.scheduled`, `content.schedule_btn`, `content.publish_now`, `content.reschedule`
- Produces: libellés dans les 4 langues

- [ ] **Step 1 : Ajouter les clés de traduction**

Dans `translations/messages.fr.php`, ajouter dans la section `content` :

```php
'content' => [
    // ... existants ...
    'scheduled' => 'Planifié',
    'schedule_btn' => 'Planifier',
    'publish_now' => 'Publier maintenant',
    'reschedule' => 'Replanifier',
],
```

Dans `translations/messages.en.php` :

```php
'content' => [
    // ... existants ...
    'scheduled' => 'Scheduled',
    'schedule_btn' => 'Schedule',
    'publish_now' => 'Publish now',
    'reschedule' => 'Reschedule',
],
```

Dans `translations/messages.ar.php` :

```php
'content' => [
    // ... existants ...
    'scheduled' => 'مجدول',
    'schedule_btn' => 'جدولة',
    'publish_now' => 'نشر الآن',
    'reschedule' => 'إعادة الجدولة',
],
```

Dans `translations/messages.es.php` :

```php
'content' => [
    // ... existants ...
    'scheduled' => 'Programado',
    'schedule_btn' => 'Programar',
    'publish_now' => 'Publicar ahora',
    'reschedule' => 'Reprogramar',
],
```

- [ ] **Step 2 : Lancer tous les tests PHP**

```bash
vendor/bin/phpunit
```
Expected: aucun nouveau test en échec.

- [ ] **Step 3 : Build frontend final**

```bash
npm run build
```
Expected: build réussi.

- [ ] **Step 4 : Commit**

```bash
git add translations/
git commit -m "feat(scheduled-publishing): add translations for scheduled status in fr/en/ar/es"
```

---

## Self-Review

- **Couverture spec :**
  - Section 2 (modèle) → Task 1 ✅
  - Section 3 (cron) → Task 3 ✅
  - Section 4 (API) → Task 2 ✅
  - Section 5.1 (ContentForm) → Task 4 ✅
  - Section 5.2 (ContentList) → Task 5 ✅
  - Section 6 (webhooks) → pas de changement nécessaire, `content.updated` déjà dispatché ✅
  - Section 7 (hors périmètre) → vérifié, rien en trop ✅

- **Placeholders :** Aucun TBD, TODO, ou « add appropriate error handling » flou.

- **Cohérence types :** `scheduledAt` nommé identiquement partout (PHP, API JSON, JS). `status = 'scheduled'` cohérent entre entité, API, et frontend.

- **Risque identifié :** Le `Repository` est injecté via un setter dans la commande pour permettre le mock en test. C'est un pattern pragmatique acceptable dans Symfony. Alternative : utiliser le service container complet dans un test d'intégration (KernelTestCase), mais le plan privilégie l'unité pour la vitesse.
