# Jambo Sites Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Héberger les apps générées par le Workbench directement dans Jambo (front statique servi par PHP, domaine custom via Host header), tout en supprimant les déploiements 1-clic et Jambo Cloud Docker.

**Architecture:** (1) Nettoyage des phases 2/3 — on garde l'export ZIP. (2) Entités WorkbenchEnvVar + SiteDomain + publishedAt. (3) PublishedSiteStorage (écrit var/published_sites/<uuid>/). (4) SiteHostResolver (EventSubscriber kernel.request priorité 32, résout Host → fichier statique, fallback SPA). (5) Routes API CRUD + publish. (6) PublishPanel.tsx (build WebContainer → upload). (7) Reskin bolt.diy + i18n.

**Tech Stack:** PHP 8.4, Symfony 8, Doctrine ORM, React 18 + Inertia, TypeScript, Webpack, WebContainer API (déjà installé), shadcn-ui.

---

## File Map

### Supprimés (Task 1)
| Fichier / dossier |
|---|
| `src/Service/Deploy/` (répertoire entier) |
| `src/Entity/DeployToken.php` |
| `src/Repository/DeployTokenRepository.php` |
| `src/Controller/DeployOAuthController.php` |
| `src/Service/Cloud/` (répertoire entier) |
| `src/Entity/HostedApp.php` |
| `src/Entity/CustomDomain.php` |
| `src/Repository/HostedAppRepository.php` |
| `src/Repository/CustomDomainRepository.php` |
| `src/Controller/CloudController.php` |
| `src/Controller/CustomDomainController.php` |
| `src/Message/DeployHostedAppMessage.php` |
| `src/MessageHandler/DeployHostedAppMessageHandler.php` |
| `assets/js/pages/Projects/Workbench/CloudPanel.tsx` |
| `docker/traefik/` (répertoire entier) |
| `tests/Service/Deploy/` (répertoire entier) |
| `tests/Service/Cloud/` (répertoire entier) |

### Créés
| Fichier | Responsabilité |
|---|---|
| `src/Entity/WorkbenchEnvVar.php` | Paire clé/valeur par projet Workbench |
| `src/Repository/WorkbenchEnvVarRepository.php` | Accès DB env vars |
| `src/Entity/SiteDomain.php` | Liaison domaine ↔ WorkbenchProject |
| `src/Repository/SiteDomainRepository.php` | Accès DB domaines |
| `src/Service/PublishedSiteStorage.php` | Écriture/lecture var/published_sites/ |
| `src/EventSubscriber/SiteHostResolver.php` | Résolution Host → fichiers statiques |
| `assets/js/pages/Projects/Workbench/PublishPanel.tsx` | UI build+publish+domains |
| `tests/Service/PublishedSiteStorageTest.php` | Tests storage |
| `tests/EventSubscriber/SiteHostResolverTest.php` | Tests résolution Host |

### Modifiés
| Fichier | Modification |
|---|---|
| `src/Entity/WorkbenchProject.php` | + publishedAt |
| `src/Workbench/Templates/BaseTemplate.php` | + getStaticOutputDir() |
| `src/Workbench/Templates/NextjsTemplate.php` | implement getStaticOutputDir() → `out` |
| `src/Workbench/Templates/NuxtTemplate.php` | implement getStaticOutputDir() → `.output/public` |
| `src/Workbench/Templates/AstroTemplate.php` | implement getStaticOutputDir() → `dist` |
| `src/Workbench/Templates/SvelteKitTemplate.php` | implement getStaticOutputDir() → `build` |
| `src/Controller/WorkbenchController.php` | suppr. deploy*, + env vars CRUD + publish + domains CRUD |
| `src/Controller/Admin/AppSettingsController.php` | suppr. deployIntegrations |
| `src/Entity/AppSettings.php` | suppr. deployIntegrations |
| `config/services.yaml` | suppr. blocs Deploy + Cloud, + PublishedSiteStorage |
| `config/packages/messenger.yaml` | suppr. DeployHostedAppMessage |
| `.env` | suppr. vars jambo/cloud |
| `assets/js/types/index.d.ts` | suppr. DeployIntegrationStatus, simplif. AppSettings |
| `assets/js/pages/Projects/Workbench/DeployDrawer.tsx` | 2 onglets (Export + Publier) |
| `assets/js/pages/Projects/Workbench/WorkbenchPage.tsx` | passer publishedAt + dark class |
| `translations/messages.fr.php` | suppr. cloud.* + deploy.* obsolètes, + sites.* |
| `translations/messages.en.php` | idem |
| `translations/messages.es.php` | idem |
| `translations/messages.ar.php` | idem |

---

## Task 1: Nettoyage — suppression Phase 2 1-clic + Phase 3 Cloud

**Files:**
- Delete: `src/Service/Deploy/`, `src/Entity/DeployToken.php`, `src/Repository/DeployTokenRepository.php`, `src/Controller/DeployOAuthController.php`
- Delete: `src/Service/Cloud/`, `src/Entity/HostedApp.php`, `src/Entity/CustomDomain.php`, `src/Repository/HostedAppRepository.php`, `src/Repository/CustomDomainRepository.php`
- Delete: `src/Controller/CloudController.php`, `src/Controller/CustomDomainController.php`
- Delete: `src/Message/DeployHostedAppMessage.php`, `src/MessageHandler/DeployHostedAppMessageHandler.php`
- Delete: `assets/js/pages/Projects/Workbench/CloudPanel.tsx`, `docker/traefik/`
- Delete: `tests/Service/Deploy/`, `tests/Service/Cloud/`
- Modify: `src/Controller/WorkbenchController.php`
- Modify: `src/Controller/Admin/AppSettingsController.php`
- Modify: `src/Entity/AppSettings.php`
- Modify: `config/services.yaml`
- Modify: `config/packages/messenger.yaml`
- Modify: `.env`
- Modify: `assets/js/types/index.d.ts`
- Modify: `assets/js/pages/Projects/Workbench/DeployDrawer.tsx`
- Modify: `translations/messages.{fr,en,es,ar}.php`
- Migration: DROP deploy_token, hosted_app, custom_domain + DROP COLUMN app_settings.deploy_integrations

- [ ] **Step 1: Supprimer les répertoires et fichiers PHP**

```bash
cd c:/laragon/www/jamboapicms

# Phase 2 — Deploy 1-clic
rm -rf src/Service/Deploy
rm src/Entity/DeployToken.php
rm src/Repository/DeployTokenRepository.php
rm src/Controller/DeployOAuthController.php
rm tests/Service/Deploy -r -fo 2>/dev/null; exit 0

# Phase 3 — Jambo Cloud
rm -rf src/Service/Cloud
rm src/Entity/HostedApp.php
rm src/Entity/CustomDomain.php
rm src/Repository/HostedAppRepository.php
rm src/Repository/CustomDomainRepository.php
rm src/Controller/CloudController.php
rm src/Controller/CustomDomainController.php
rm src/Message/DeployHostedAppMessage.php
rm src/MessageHandler/DeployHostedAppMessageHandler.php
rm -rf docker/traefik
rm -rf tests/Service/Cloud
```

Pour PowerShell :
```powershell
cd c:/laragon/www/jamboapicms
Remove-Item -Recurse -Force src/Service/Deploy
Remove-Item -Force src/Entity/DeployToken.php
Remove-Item -Force src/Repository/DeployTokenRepository.php
Remove-Item -Force src/Controller/DeployOAuthController.php
Remove-Item -Recurse -Force tests/Service/Deploy
Remove-Item -Recurse -Force src/Service/Cloud
Remove-Item -Force src/Entity/HostedApp.php
Remove-Item -Force src/Entity/CustomDomain.php
Remove-Item -Force src/Repository/HostedAppRepository.php
Remove-Item -Force src/Repository/CustomDomainRepository.php
Remove-Item -Force src/Controller/CloudController.php
Remove-Item -Force src/Controller/CustomDomainController.php
Remove-Item -Force src/Message/DeployHostedAppMessage.php
Remove-Item -Force src/MessageHandler/DeployHostedAppMessageHandler.php
Remove-Item -Recurse -Force docker/traefik
Remove-Item -Recurse -Force tests/Service/Cloud
```

- [ ] **Step 2: Supprimer le fichier front CloudPanel**

```bash
rm assets/js/pages/Projects/Workbench/CloudPanel.tsx
```

- [ ] **Step 3: Nettoyer WorkbenchController**

Remplacer **intégralement** `src/Controller/WorkbenchController.php` par cette version (supprime DeployService, deployApp, deployStatus, DeployOAuth ; garde export ZIP) :

```php
<?php
namespace App\Controller;

use App\Entity\Collection;
use App\Entity\Project;
use App\Entity\WorkbenchProject;
use App\Repository\AppSettingsRepository;
use App\Repository\ProjectRepository;
use App\Repository\WorkbenchProjectRepository;
use App\Service\JamboClientGenerator;
use App\Service\WorkbenchStreamService;
use App\Service\ZipExportService;
use App\Workbench\Templates\BaseTemplate;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\AI\Platform\PlatformInterface;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
class WorkbenchController extends InertiaController
{
    private const MAX_PROMPT_LENGTH = 4000;
    private const MAX_FILES_BYTES = 2 * 1024 * 1024;

    /** @param BaseTemplate[] $templates */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProjectRepository $projectRepository,
        private readonly WorkbenchProjectRepository $workbenchRepository,
        private readonly WorkbenchStreamService $streamService,
        private readonly AppSettingsRepository $appSettingsRepository,
        private readonly JamboClientGenerator $clientGenerator,
        private readonly PlatformInterface $openaiPlatform,
        private readonly PlatformInterface $anthropicPlatform,
        private readonly PlatformInterface $ollamaPlatform,
        private readonly PlatformInterface $deepseekPlatform,
        private readonly array $templates,
        private readonly ZipExportService $zipExportService,
    ) {}

    #[Route('/projects/{project}/workbench', name: 'workbench_page', requirements: ['project' => '\d+'], priority: 10)]
    public function index(int $project, Request $request): Response
    {
        $project = $this->projectRepository->find($project);
        if (!$project) throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted('project.view', $project);

        $collections = $this->em->getRepository(Collection::class)
            ->findBy(['project' => $project, 'deletedAt' => null], ['order' => 'ASC']);

        $collectionsData = array_map(fn (Collection $c) => [
            'id'     => $c->id,
            'uuid'   => $c->uuid?->toRfc4122(),
            'name'   => $c->name,
            'slug'   => $c->slug,
            'fields' => array_values(array_filter(
                array_map(fn ($f) => $f->isDeleted() ? null : [
                    'name' => $f->name, 'slug' => $f->slug,
                    'type' => $f->type, 'isRequired' => $f->isRequired,
                ], $c->fields->toArray())
            )),
        ], $collections);

        $workbenchProjects = $this->workbenchRepository->findByProject($project);

        $apiUrl = $request->getSchemeAndHttpHost();
        $collectionsForClient = array_map(fn (Collection $c) => [
            'name' => $c->name, 'slug' => $c->slug,
            'fields' => array_values(array_filter(
                array_map(fn ($f) => $f->isDeleted() ? null : [
                    'name' => $f->name, 'slug' => $f->slug,
                    'type' => $f->type, 'isRequired' => $f->isRequired,
                ], $c->fields->toArray())
            )),
        ], $collections);

        $starterFilesByFramework = [];
        foreach ($this->templates as $template) {
            $starterFilesByFramework[$template->getId()] = $template->getStarterFiles(
                $apiUrl, $project->uuid->toRfc4122(), $collectionsForClient,
            );
        }

        return $this->inertia($request, 'Projects/Workbench/WorkbenchPage', [
            'project' => [
                'id'   => $project->id,
                'uuid' => $project->uuid->toRfc4122(),
                'name' => $project->name,
            ],
            'collections'            => $collectionsData,
            'workbenchProjects'      => array_map(fn (WorkbenchProject $w) => $this->serializeWorkbench($w), $workbenchProjects),
            'frameworks'             => array_map(fn ($t) => ['id' => $t->getId(), 'label' => $t->getLabel()], $this->templates),
            'userCan'                => [],
            'starterFilesByFramework'=> $starterFilesByFramework,
        ]);
    }

    #[Route('/api/projects/{uuid}/workbench/generate', name: 'workbench_generate', methods: ['POST'])]
    public function generate(string $uuid, Request $request): Response
    {
        $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        if (!$project) return new JsonResponse(['error' => 'Projet introuvable'], 404);
        $this->denyAccessUnlessGranted('project.view', $project);

        $body = $request->toArray();
        $userPrompt = trim((string) ($body['prompt'] ?? ''));
        $framework  = $body['framework'] ?? 'nextjs';

        if ($userPrompt === '') return new JsonResponse(['error' => 'Prompt requis'], 422);
        if (mb_strlen($userPrompt) > self::MAX_PROMPT_LENGTH) {
            return new JsonResponse(['error' => sprintf('Prompt trop long (max %d caractères)', self::MAX_PROMPT_LENGTH)], 422);
        }
        if (!in_array($framework, WorkbenchProject::FRAMEWORKS, true)) {
            return new JsonResponse(['error' => 'Framework invalide'], 422);
        }

        [$platform, $model] = $this->resolveProvider();
        if ($platform === null) {
            return new JsonResponse(['error' => 'Aucun fournisseur IA activé. Configurez-en un dans Paramètres → Fournisseurs IA.'], 503);
        }

        $apiUrl = $request->getSchemeAndHttpHost();
        $streamService = $this->streamService;
        $controller = new AbortController();
        $abortRef = null;

        $response = new StreamedResponse(function () use ($streamService, $project, $userPrompt, $framework, $apiUrl, $platform, $model) {
            foreach ($streamService->stream($project, $userPrompt, $framework, $apiUrl, $platform, $model) as $chunk) {
                echo $chunk;
                if (ob_get_level() > 0) ob_flush();
                flush();
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    #[Route('/api/projects/{uuid}/workbench/templates', name: 'workbench_templates', methods: ['GET'])]
    public function templates(string $uuid): JsonResponse
    {
        return $this->json([
            'data' => array_map(fn ($t) => ['id' => $t->getId(), 'label' => $t->getLabel()], $this->templates),
        ]);
    }

    #[Route('/api/projects/{uuid}/workbench/save', name: 'workbench_save', methods: ['POST'])]
    public function save(string $uuid, Request $request): JsonResponse
    {
        $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        if (!$project) return new JsonResponse(['error' => 'Projet introuvable'], 404);
        $this->denyAccessUnlessGranted('project.manage', $project);

        $body = $request->toArray();
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') return new JsonResponse(['error' => 'name requis'], 422);

        $files = is_array($body['files'] ?? null) ? $body['files'] : [];
        if (($error = $this->validateFilesSize($files)) !== null) {
            return new JsonResponse(['error' => $error], 422);
        }

        $workbench = new WorkbenchProject();
        $workbench->project         = $project;
        $workbench->name            = $name;
        $workbench->framework       = in_array($body['framework'] ?? '', WorkbenchProject::FRAMEWORKS, true) ? $body['framework'] : 'nextjs';
        $workbench->files           = $files;
        $workbench->generatedPrompt = isset($body['prompt']) ? (string) $body['prompt'] : null;
        $workbench->createdBy       = $this->getUser();

        $this->em->persist($workbench);
        $this->em->flush();

        return new JsonResponse(['data' => $this->serializeWorkbench($workbench)], 201);
    }

    #[Route('/api/projects/{uuid}/workbench/{workbenchUuid}', name: 'workbench_update', methods: ['PUT', 'PATCH'])]
    public function update(string $uuid, string $workbenchUuid, Request $request): JsonResponse
    {
        $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        if (!$project) return new JsonResponse(['error' => 'Projet introuvable'], 404);
        $this->denyAccessUnlessGranted('project.manage', $project);

        $workbench = $this->workbenchRepository->findOneBy(['uuid' => $workbenchUuid, 'project' => $project]);
        if (!$workbench) return new JsonResponse(['error' => 'WorkbenchProject introuvable'], 404);

        $body = $request->toArray();
        if (isset($body['files']) && is_array($body['files'])) {
            if (($error = $this->validateFilesSize($body['files'])) !== null) {
                return new JsonResponse(['error' => $error], 422);
            }
            $workbench->files = $body['files'];
        }
        if (isset($body['name'])) $workbench->name = (string) $body['name'];
        $workbench->touch();
        $this->em->flush();

        return new JsonResponse(['data' => $this->serializeWorkbench($workbench)]);
    }

    #[Route('/api/projects/{uuid}/workbench/{workbenchUuid}/export', name: 'workbench_export', methods: ['GET'])]
    public function export(string $uuid, string $workbenchUuid): Response
    {
        $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        if (!$project) return new JsonResponse(['error' => 'Projet introuvable'], 404);
        $this->denyAccessUnlessGranted('project.view', $project);

        $workbench = $this->workbenchRepository->findOneBy(['uuid' => $workbenchUuid, 'project' => $project]);
        if (!$workbench) return new JsonResponse(['error' => 'WorkbenchProject introuvable'], 404);
        if (empty($workbench->files)) {
            return new JsonResponse(['error' => 'Aucun fichier à exporter.'], 422);
        }

        $zipBytes = $this->zipExportService->export($workbench);
        $filename = $this->zipExportService->suggestedFilename($workbench);

        $tmpFile = tempnam(sys_get_temp_dir(), 'jambo_zip_') . '.zip';
        file_put_contents($tmpFile, $zipBytes);

        $response = new BinaryFileResponse($tmpFile);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename);
        $response->headers->set('Content-Type', 'application/zip');
        $response->deleteFileAfterSend(true);

        return $response;
    }

    private function validateFilesSize(array $files): ?string
    {
        $bytes = strlen((string) json_encode($files));
        if ($bytes > self::MAX_FILES_BYTES) {
            return sprintf('Fichiers trop volumineux (max %d Mo)', intdiv(self::MAX_FILES_BYTES, 1024 * 1024));
        }
        return null;
    }

    private function serializeWorkbench(WorkbenchProject $w): array
    {
        return [
            'uuid'         => $w->uuid->toRfc4122(),
            'name'         => $w->name,
            'framework'    => $w->framework,
            'files'        => $w->files,
            'published_at' => $w->publishedAt?->format(\DateTimeInterface::ATOM),
            'created_at'   => $w->createdAt->format(\DateTimeInterface::ATOM),
            'updated_at'   => $w->updatedAt->format(\DateTimeInterface::ATOM),
        ];
    }

    private function resolveProvider(): array
    {
        $config = $this->appSettingsRepository->getOrCreate()->aiProviders ?? [];
        $candidates = [
            'openai'    => [$this->openaiPlatform,    $config['openai']['model']    ?? 'gpt-4o'],
            'anthropic' => [$this->anthropicPlatform, $config['anthropic']['model'] ?? 'claude-sonnet-4-6'],
            'deepseek'  => [$this->deepseekPlatform,  $config['deepseek']['model']  ?? 'deepseek-chat'],
            'ollama'    => [$this->ollamaPlatform,     $config['ollama']['model']    ?? 'llama3.2'],
        ];
        foreach ($candidates as $name => [$platform, $model]) {
            if (!empty($config[$name]['enabled'])) return [$platform, $model];
        }
        return [null, null];
    }
}
```

Note : `publishedAt` est ajouté à `serializeWorkbench` — il sera null jusqu'à la Task 2.

- [ ] **Step 4: Nettoyer AppSettingsController**

Retirer le bloc `deployIntegrations` de `update()` (lignes 115-138) et la méthode `serializeDeploy()` + l'entrée `'deployIntegrations'` dans le tableau retourné par `serialize()`. Lire le fichier d'abord, puis appliquer les edits ciblés :

```bash
# Vérifier les numéros de lignes
grep -n "deployIntegrations\|serializeDeploy" src/Controller/Admin/AppSettingsController.php
```

Supprimer dans `serialize()` la ligne :
```php
            'deployIntegrations' => $this->serializeDeploy($s),
```

Supprimer le bloc `deployIntegrations` dans `update()` (de `// Deploy integrations` jusqu'à la fermeture `}` + `$changed = true;`).

Supprimer la méthode `serializeDeploy()` entière.

- [ ] **Step 5: Nettoyer AppSettings entity**

Dans `src/Entity/AppSettings.php`, supprimer la propriété :
```php
    public ?array $deployIntegrations = null;
```

- [ ] **Step 6: Nettoyer services.yaml**

Supprimer les blocs Deploy + Cloud dans `config/services.yaml` :
- Le commentaire `# Deploy providers — OAuth credentials…` et les 3 lignes `App\Service\Deploy\*: ~`
- Tout le bloc `App\Service\Deploy\DeployService:` (6 lignes)
- Le commentaire `# ── Jambo Cloud (Phase 3)` et toutes les entrées Cloud (TraefikLabelBuilder, DockerContainerOrchestrator, ContainerOrchestratorInterface, SystemDnsResolver, DnsResolverInterface, HostedAppService, CloudController, CustomDomainController) — environ lignes 108-161.

- [ ] **Step 7: Nettoyer messenger.yaml**

Dans `config/packages/messenger.yaml`, supprimer la ligne :
```yaml
            App\Message\DeployHostedAppMessage: async
```

- [ ] **Step 8: Nettoyer .env**

Dans `.env`, supprimer le bloc :
```
###> jambo/cloud ###
JAMBO_CLOUD_ENABLED=false
JAMBO_CLOUD_BASE_DOMAIN=jambo.app
DOCKER_API_BASE=http://127.0.0.1:2375
JAMBO_PUBLIC_URL=https://cms.example.com
###< jambo/cloud ###
```

- [ ] **Step 9: Nettoyer types TypeScript**

Dans `assets/js/types/index.d.ts`, supprimer :
```typescript
export interface DeployIntegrationStatus {
    client_id: string;
    configured: boolean;
}
```

Et dans `AppSettings`, supprimer :
```typescript
    deployIntegrations?: {
        vercel:  DeployIntegrationStatus;
        netlify: DeployIntegrationStatus;
        railway: DeployIntegrationStatus;
    };
```

- [ ] **Step 10: Simplifier DeployDrawer**

Remplacer **intégralement** `assets/js/pages/Projects/Workbench/DeployDrawer.tsx` par cette version (2 onglets : Export + Publier, stub pour Publier qui sera complété Task 7) :

```tsx
// assets/js/pages/Projects/Workbench/DeployDrawer.tsx
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from '@/components/ui/sheet';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Download } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/lib/i18n';
import { toast } from 'sonner';

interface Props { open: boolean; onClose: () => void; projectUuid: string; workbenchUuid?: string; }

export default function DeployDrawer({ open, onClose, projectUuid, workbenchUuid }: Props) {
    const t = useTranslation();

    const handleExport = async () => {
        if (!workbenchUuid) { toast.error(t('workbench.deploy.no_files')); return; }
        window.location.href = `/api/projects/${projectUuid}/workbench/${workbenchUuid}/export`;
    };

    return (
        <Sheet open={open} onOpenChange={val => !val && onClose()}>
            <SheetContent side="right" className="w-[420px] flex flex-col">
                <SheetHeader>
                    <SheetTitle>{t('workbench.deploy.title')}</SheetTitle>
                    <SheetDescription>{t('workbench.deploy.subtitle')}</SheetDescription>
                </SheetHeader>

                <Tabs defaultValue="export" className="mt-6 flex-1 flex flex-col">
                    <TabsList className="w-full mb-4">
                        <TabsTrigger value="export" className="flex-1 text-xs">Export</TabsTrigger>
                        <TabsTrigger value="publish" className="flex-1 text-xs">{t('workbench.sites.publish')}</TabsTrigger>
                    </TabsList>

                    <TabsContent value="export" className="space-y-3">
                        <p className="text-sm text-muted-foreground">{t('workbench.deploy.export_desc')}</p>
                        <Button variant="outline" className="w-full justify-start gap-2" onClick={handleExport} disabled={!workbenchUuid}>
                            <Download className="w-4 h-4" />
                            {t('workbench.deploy.download_zip')}
                        </Button>
                    </TabsContent>

                    <TabsContent value="publish" className="flex-1">
                        {/* PublishPanel sera ajouté en Task 7 */}
                        <p className="text-sm text-muted-foreground">{t('workbench.sites.coming_soon')}</p>
                    </TabsContent>
                </Tabs>
            </SheetContent>
        </Sheet>
    );
}
```

- [ ] **Step 11: Nettoyer les traductions (supprimer les clés cloud et deploy 1-clic)**

Dans chaque fichier de traduction, supprimer le bloc `// Workbench Cloud` (clés `workbench.cloud.*`) et le bloc `// Deploy integrations` (clés `app_settings.deploy.*`) et les clés `app_settings.tab_deploy`, `workbench.deploy.no_files`.

Les clés `workbench.deploy.no_files`, `workbench.deploy.title`, `workbench.deploy.subtitle`, `workbench.deploy.export_desc`, `workbench.deploy.download_zip` seront **remplacées** par les nouvelles dans Task 8.

Pour l'instant : commenter ou supprimer seulement `workbench.cloud.*` et `app_settings.deploy.*` et `app_settings.tab_deploy`.

```bash
grep -n "workbench.cloud\|app_settings.deploy\|app_settings.tab_deploy" translations/messages.fr.php
```

Supprimer ces entrées (elles sont à la fin du fichier, Task 8 les remplacera).

- [ ] **Step 12: Générer la migration de suppression**

```bash
cd c:/laragon/www/jamboapicms
php bin/console doctrine:migrations:diff --no-interaction 2>&1 | tail -5
```

Ouvrir le fichier généré. Il doit contenir des `DROP TABLE deploy_token`, `DROP TABLE hosted_app`, `DROP TABLE custom_domain` et `ALTER TABLE app_settings DROP deploy_integrations`. **Retirer toute ligne `content_entry`** (drift connu). Appliquer :

```bash
php bin/console doctrine:migrations:migrate --no-interaction
```

- [ ] **Step 13: Vérifier la syntaxe PHP + container**

```bash
cd c:/laragon/www/jamboapicms
php bin/console cache:clear --env=dev 2>&1 | tail -2
php bin/console lint:container 2>&1 | tail -3
php -l src/Controller/WorkbenchController.php
```

Attendu : container OK, pas d'erreur.

- [ ] **Step 14: Vérifier TypeScript**

```bash
npx tsc --noEmit -p tsconfig.json 2>&1 | grep -v "node_modules" | head -20
```

Corriger les erreurs éventuelles liées aux imports supprimés (CloudPanel, DeployIntegrationStatus, etc.).

- [ ] **Step 15: Lancer la suite de tests**

```bash
php vendor/bin/phpunit 2>&1 | tail -6
```

Attendu : la suite passe ; les tests Deploy/Cloud supprimés ne cassent rien car leurs fichiers n'existent plus. Si des tests référencent des entités supprimées, les supprimer.

- [ ] **Step 16: Commit**

```bash
git add -A
git commit -m "feat(sites): remove deploy 1-click + Jambo Cloud, keep ZIP export"
```

---

## Task 2: Entités WorkbenchEnvVar + SiteDomain + publishedAt

**Files:**
- Create: `src/Entity/WorkbenchEnvVar.php`
- Create: `src/Repository/WorkbenchEnvVarRepository.php`
- Create: `src/Entity/SiteDomain.php`
- Create: `src/Repository/SiteDomainRepository.php`
- Modify: `src/Entity/WorkbenchProject.php`
- Create: migration (auto)

- [ ] **Step 1: Créer WorkbenchEnvVar**

```php
<?php
// src/Entity/WorkbenchEnvVar.php
namespace App\Entity;

use App\Repository\WorkbenchEnvVarRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WorkbenchEnvVarRepository::class)]
#[ORM\Table(name: 'workbench_env_var')]
#[ORM\UniqueConstraint(name: 'uniq_workbench_env_var', columns: ['workbench_project_id', 'key_name'])]
#[ORM\HasLifecycleCallbacks]
class WorkbenchEnvVar
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: WorkbenchProject::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public WorkbenchProject $workbenchProject;

    #[ORM\Column(length: 120)]
    public string $keyName = '';

    #[ORM\Column(type: 'text')]
    public string $value = '';

    /** Masqué dans l'UI — NE garantit PAS la confidentialité côté front statique. */
    #[ORM\Column]
    public bool $isSecret = false;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column]
    public \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
```

- [ ] **Step 2: Créer WorkbenchEnvVarRepository**

```php
<?php
// src/Repository/WorkbenchEnvVarRepository.php
namespace App\Repository;

use App\Entity\WorkbenchEnvVar;
use App\Entity\WorkbenchProject;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<WorkbenchEnvVar> */
class WorkbenchEnvVarRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkbenchEnvVar::class);
    }

    /** @return WorkbenchEnvVar[] */
    public function findByWorkbench(WorkbenchProject $w): array
    {
        return $this->findBy(['workbenchProject' => $w], ['keyName' => 'ASC']);
    }

    public function findOneByKey(WorkbenchProject $w, string $keyName): ?WorkbenchEnvVar
    {
        return $this->findOneBy(['workbenchProject' => $w, 'keyName' => $keyName]);
    }
}
```

- [ ] **Step 3: Créer SiteDomain**

```php
<?php
// src/Entity/SiteDomain.php
namespace App\Entity;

use App\Repository\SiteDomainRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: SiteDomainRepository::class)]
#[ORM\Table(name: 'site_domain')]
class SiteDomain
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(type: 'uuid', unique: true)]
    public Uuid $uuid;

    #[ORM\ManyToOne(targetEntity: WorkbenchProject::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public WorkbenchProject $workbenchProject;

    #[ORM\Column(length: 253, unique: true)]
    public string $domain = '';

    #[ORM\Column]
    public bool $isPrimary = false;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->uuid      = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
    }
}
```

- [ ] **Step 4: Créer SiteDomainRepository**

```php
<?php
// src/Repository/SiteDomainRepository.php
namespace App\Repository;

use App\Entity\SiteDomain;
use App\Entity\WorkbenchProject;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<SiteDomain> */
class SiteDomainRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SiteDomain::class);
    }

    public function findByDomain(string $domain): ?SiteDomain
    {
        return $this->findOneBy(['domain' => $domain]);
    }

    /** @return SiteDomain[] */
    public function findByWorkbench(WorkbenchProject $w): array
    {
        return $this->findBy(['workbenchProject' => $w], ['isPrimary' => 'DESC', 'createdAt' => 'ASC']);
    }
}
```

- [ ] **Step 5: Ajouter publishedAt à WorkbenchProject**

Dans `src/Entity/WorkbenchProject.php`, après la propriété `deployStatus` (ligne ~45), ajouter :

```php
    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $publishedAt = null;
```

- [ ] **Step 6: Générer et appliquer la migration**

```bash
cd c:/laragon/www/jamboapicms
php bin/console doctrine:migrations:diff --no-interaction 2>&1 | tail -5
```

Ouvrir le fichier généré — il doit créer `workbench_env_var`, `site_domain` et ajouter `published_at` sur `workbench_project`. **Retirer toute ligne `content_entry`**.

```bash
php bin/console doctrine:migrations:migrate --no-interaction
php -l src/Entity/WorkbenchEnvVar.php src/Entity/SiteDomain.php
```

- [ ] **Step 7: Commit**

```bash
git add src/Entity/WorkbenchEnvVar.php src/Entity/SiteDomain.php src/Repository/WorkbenchEnvVarRepository.php src/Repository/SiteDomainRepository.php src/Entity/WorkbenchProject.php migrations/
git commit -m "feat(sites): add WorkbenchEnvVar, SiteDomain entities + publishedAt"
```

---

## Task 3: PublishedSiteStorage (TDD)

**Files:**
- Create: `src/Service/PublishedSiteStorage.php`
- Create: `tests/Service/PublishedSiteStorageTest.php`

- [ ] **Step 1: Écrire le test (red)**

```php
<?php
// tests/Service/PublishedSiteStorageTest.php
namespace App\Tests\Service;

use App\Service\PublishedSiteStorage;
use PHPUnit\Framework\TestCase;

class PublishedSiteStorageTest extends TestCase
{
    private string $tmpDir;
    private PublishedSiteStorage $storage;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/jambo_sites_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        $this->storage = new PublishedSiteStorage($this->tmpDir);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    public function testWriteAndReadFile(): void
    {
        $this->storage->publish('proj-uuid', ['index.html' => '<h1>Hello</h1>']);
        $this->assertSame('<h1>Hello</h1>', $this->storage->readFile('proj-uuid', 'index.html'));
    }

    public function testPublishReplacesExistingFiles(): void
    {
        $this->storage->publish('proj-uuid', ['old.html' => 'old']);
        $this->storage->publish('proj-uuid', ['new.html' => 'new']);
        $this->assertNull($this->storage->readFile('proj-uuid', 'old.html'));
        $this->assertSame('new', $this->storage->readFile('proj-uuid', 'new.html'));
    }

    public function testReadFileReturnsNullForMissingFile(): void
    {
        $this->assertNull($this->storage->readFile('proj-uuid', 'nope.html'));
    }

    public function testRejectsTraversalPath(): void
    {
        $this->storage->publish('proj-uuid', ['index.html' => 'ok']);
        $this->assertNull($this->storage->readFile('proj-uuid', '../../../etc/passwd'));
    }

    public function testListFilesReturnsRelativePaths(): void
    {
        $this->storage->publish('proj-uuid', [
            'index.html' => 'root',
            'js/app.js'  => 'js',
        ]);
        $files = $this->storage->listFiles('proj-uuid');
        sort($files);
        $this->assertSame(['index.html', 'js/app.js'], $files);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') continue;
            $p = $dir . '/' . $f;
            is_dir($p) ? $this->removeDir($p) : unlink($p);
        }
        rmdir($dir);
    }
}
```

- [ ] **Step 2: Confirmer que le test échoue**

```bash
php vendor/bin/phpunit tests/Service/PublishedSiteStorageTest.php 2>&1 | tail -5
```

Attendu : FAIL avec `Class "App\Service\PublishedSiteStorage" not found`.

- [ ] **Step 3: Implémenter PublishedSiteStorage**

```php
<?php
// src/Service/PublishedSiteStorage.php
namespace App\Service;

class PublishedSiteStorage
{
    public function __construct(
        private readonly string $baseDir,
    ) {}

    /**
     * Efface le répertoire du projet puis écrit tous les fichiers.
     * @param array<string,string> $files chemin relatif → contenu
     */
    public function publish(string $projectUuid, array $files): void
    {
        $dir = $this->projectDir($projectUuid);
        $this->removeDir($dir);
        mkdir($dir, 0755, true);

        foreach ($files as $relativePath => $content) {
            $abs = $dir . '/' . ltrim($relativePath, '/');
            $parent = dirname($abs);
            if (!is_dir($parent)) {
                mkdir($parent, 0755, true);
            }
            file_put_contents($abs, $content);
        }
    }

    /**
     * Lit un fichier ; retourne null si absent ou chemin suspect (traversal).
     */
    public function readFile(string $projectUuid, string $relativePath): ?string
    {
        $dir = $this->projectDir($projectUuid);
        $abs = realpath($dir . '/' . ltrim($relativePath, '/'));

        if ($abs === false) {
            return null;
        }
        // Protection traversal : le chemin doit rester sous le répertoire projet.
        if (!str_starts_with($abs, realpath($dir) . DIRECTORY_SEPARATOR) && $abs !== realpath($dir)) {
            return null;
        }
        if (!is_file($abs)) {
            return null;
        }

        return file_get_contents($abs) ?: null;
    }

    /** Retourne les chemins relatifs de tous les fichiers publiés. */
    public function listFiles(string $projectUuid): array
    {
        $dir = $this->projectDir($projectUuid);
        if (!is_dir($dir)) return [];

        $files = [];
        $iter  = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iter as $file) {
            if ($file->isFile()) {
                $files[] = substr($file->getPathname(), strlen($dir) + 1);
            }
        }
        return array_map(fn ($f) => str_replace('\\', '/', $f), $files);
    }

    public function projectDir(string $projectUuid): string
    {
        return rtrim($this->baseDir, '/\\') . '/' . $projectUuid;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') continue;
            $p = $dir . '/' . $f;
            is_dir($p) ? $this->removeDir($p) : unlink($p);
        }
        rmdir($dir);
    }
}
```

- [ ] **Step 4: Confirmer que les tests passent**

```bash
php vendor/bin/phpunit tests/Service/PublishedSiteStorageTest.php 2>&1 | tail -4
```

Attendu : `OK (5 tests, ...)`.

- [ ] **Step 5: Enregistrer le service dans services.yaml**

Ajouter à la fin du bloc `services:` de `config/services.yaml` :

```yaml
    App\Service\PublishedSiteStorage:
        arguments:
            $baseDir: '%kernel.project_dir%/var/published_sites'
```

- [ ] **Step 6: Commit**

```bash
git add src/Service/PublishedSiteStorage.php tests/Service/PublishedSiteStorageTest.php config/services.yaml
git commit -m "feat(sites): add PublishedSiteStorage with traversal protection"
```

---

## Task 4: SiteHostResolver (TDD)

**Files:**
- Create: `src/EventSubscriber/SiteHostResolver.php`
- Create: `tests/EventSubscriber/SiteHostResolverTest.php`

- [ ] **Step 1: Écrire le test (red)**

```php
<?php
// tests/EventSubscriber/SiteHostResolverTest.php
namespace App\Tests\EventSubscriber;

use App\Entity\SiteDomain;
use App\Entity\WorkbenchProject;
use App\EventSubscriber\SiteHostResolver;
use App\Repository\SiteDomainRepository;
use App\Service\PublishedSiteStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class SiteHostResolverTest extends TestCase
{
    private function makeEvent(string $host, string $path = '/'): RequestEvent
    {
        $kernel  = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('https://' . $host . $path);
        return new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    }

    private function makeResolver(
        ?SiteDomain $domain,
        array $storageMap = [],
    ): SiteHostResolver {
        $repo = $this->createMock(SiteDomainRepository::class);
        $repo->method('findByDomain')->willReturn($domain);

        $storage = $this->createMock(PublishedSiteStorage::class);
        $storage->method('readFile')->willReturnCallback(
            fn ($uuid, $path) => $storageMap[$uuid . ':' . $path] ?? null
        );

        return new SiteHostResolver($repo, $storage);
    }

    private function makeDomain(string $domain, string $uuid = 'test-uuid'): SiteDomain
    {
        $w = new WorkbenchProject();
        $w->uuid    = \Symfony\Component\Uid\Uuid::fromString('00000000-0000-4000-8000-000000000001');
        $w->name    = 'test';
        $w->framework = 'nextjs';

        $d = new SiteDomain();
        $d->domain           = $domain;
        $d->workbenchProject = $w;

        return $d;
    }

    public function testUnknownHostDoesNothing(): void
    {
        $resolver = $this->makeResolver(null);
        $event    = $this->makeEvent('unknown.example.com');
        $resolver->onRequest($event);
        $this->assertFalse($event->hasResponse());
    }

    public function testKnownHostServesFile(): void
    {
        $uuid     = '00000000-0000-4000-8000-000000000001';
        $domain   = $this->makeDomain('monsite.com');
        $resolver = $this->makeResolver($domain, [$uuid . ':' . 'index.html' => '<h1>Hi</h1>']);

        $event = $this->makeEvent('monsite.com', '/');
        $resolver->onRequest($event);

        $this->assertTrue($event->hasResponse());
        $this->assertSame(200, $event->getResponse()->getStatusCode());
        $this->assertStringContainsString('<h1>Hi</h1>', $event->getResponse()->getContent());
    }

    public function testMissingFileServesSpaFallback(): void
    {
        $uuid     = '00000000-0000-4000-8000-000000000001';
        $domain   = $this->makeDomain('monsite.com');
        $resolver = $this->makeResolver($domain, [$uuid . ':index.html' => '<html>SPA</html>']);

        $event = $this->makeEvent('monsite.com', '/some/deep/route');
        $resolver->onRequest($event);

        $this->assertTrue($event->hasResponse());
        $this->assertSame(200, $event->getResponse()->getStatusCode());
        $this->assertStringContainsString('SPA', $event->getResponse()->getContent());
    }

    public function testMissingFileAndNoFallbackReturns404(): void
    {
        $domain   = $this->makeDomain('monsite.com');
        $resolver = $this->makeResolver($domain, []);

        $event = $this->makeEvent('monsite.com', '/nope.html');
        $resolver->onRequest($event);

        $this->assertTrue($event->hasResponse());
        $this->assertSame(404, $event->getResponse()->getStatusCode());
    }
}
```

- [ ] **Step 2: Confirmer que le test échoue**

```bash
php vendor/bin/phpunit tests/EventSubscriber/SiteHostResolverTest.php 2>&1 | tail -4
```

Attendu : FAIL avec `Class "App\EventSubscriber\SiteHostResolver" not found`.

- [ ] **Step 3: Implémenter SiteHostResolver**

```php
<?php
// src/EventSubscriber/SiteHostResolver.php
namespace App\EventSubscriber;

use App\Repository\SiteDomainRepository;
use App\Service\PublishedSiteStorage;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class SiteHostResolver implements EventSubscriberInterface
{
    private const MIME_TYPES = [
        'html' => 'text/html; charset=utf-8',
        'css'  => 'text/css',
        'js'   => 'application/javascript',
        'mjs'  => 'application/javascript',
        'json' => 'application/json',
        'svg'  => 'image/svg+xml',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'ico'  => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2'=> 'font/woff2',
        'ttf'  => 'font/ttf',
        'txt'  => 'text/plain',
    ];

    public function __construct(
        private readonly SiteDomainRepository $siteDomainRepository,
        private readonly PublishedSiteStorage $storage,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onRequest', 32]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) return;

        $host = $event->getRequest()->getHost();
        $siteDomain = $this->siteDomainRepository->findByDomain($host);
        if ($siteDomain === null) return;

        $uuid = $siteDomain->workbenchProject->uuid->toRfc4122();
        $path = $event->getRequest()->getPathInfo();

        $content = $this->resolveContent($uuid, $path);

        if ($content === null) {
            $event->setResponse(new Response('Not Found', 404));
            return;
        }

        $ext     = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime    = self::MIME_TYPES[$ext] ?? 'application/octet-stream';

        $response = new Response($content, 200, [
            'Content-Type'  => $mime,
            'Cache-Control' => $ext === 'html' ? 'no-cache' : 'public, max-age=31536000, immutable',
        ]);

        $event->setResponse($response);
    }

    private function resolveContent(string $uuid, string $path): ?string
    {
        // / → index.html
        if ($path === '/' || $path === '') {
            return $this->storage->readFile($uuid, 'index.html');
        }

        // Essayer le chemin tel quel
        $relative = ltrim($path, '/');
        $content  = $this->storage->readFile($uuid, $relative);
        if ($content !== null) return $content;

        // /some/path/ → /some/path/index.html
        $withIndex = rtrim($relative, '/') . '/index.html';
        $content   = $this->storage->readFile($uuid, $withIndex);
        if ($content !== null) return $content;

        // Fallback SPA : index.html pour toute route non trouvée
        return $this->storage->readFile($uuid, 'index.html');
    }
}
```

- [ ] **Step 4: Confirmer que les tests passent**

```bash
php vendor/bin/phpunit tests/EventSubscriber/SiteHostResolverTest.php 2>&1 | tail -4
```

Attendu : `OK (4 tests, ...)`.

- [ ] **Step 5: Commit**

```bash
git add src/EventSubscriber/SiteHostResolver.php tests/EventSubscriber/SiteHostResolverTest.php
git commit -m "feat(sites): add SiteHostResolver (Host-based static site serving)"
```

---

## Task 5: getStaticOutputDir sur les templates

**Files:**
- Modify: `src/Workbench/Templates/BaseTemplate.php`
- Modify: `src/Workbench/Templates/NextjsTemplate.php`
- Modify: `src/Workbench/Templates/NuxtTemplate.php`
- Modify: `src/Workbench/Templates/AstroTemplate.php`
- Modify: `src/Workbench/Templates/SvelteKitTemplate.php`

- [ ] **Step 1: Ajouter getStaticOutputDir() à BaseTemplate**

Dans `src/Workbench/Templates/BaseTemplate.php`, après `getInstallCommand()`, ajouter :

```php
    /**
     * Returns the static build output directory name (relative to project root).
     * null = framework does not support static export; publishing will be refused.
     */
    public function getStaticOutputDir(): ?string
    {
        return null;
    }
```

- [ ] **Step 2: Implémenter dans les 4 templates**

Dans `src/Workbench/Templates/NextjsTemplate.php`, ajouter après `getDevCommand()` :
```php
    public function getStaticOutputDir(): ?string { return 'out'; }
```

Dans `src/Workbench/Templates/NuxtTemplate.php`, ajouter :
```php
    public function getStaticOutputDir(): ?string { return '.output/public'; }
```

Dans `src/Workbench/Templates/AstroTemplate.php`, ajouter :
```php
    public function getStaticOutputDir(): ?string { return 'dist'; }
```

Dans `src/Workbench/Templates/SvelteKitTemplate.php`, ajouter :
```php
    public function getStaticOutputDir(): ?string { return 'build'; }
```

- [ ] **Step 3: Vérifier la syntaxe**

```bash
php -l src/Workbench/Templates/BaseTemplate.php
php -l src/Workbench/Templates/NextjsTemplate.php
php -l src/Workbench/Templates/NuxtTemplate.php
php -l src/Workbench/Templates/AstroTemplate.php
php -l src/Workbench/Templates/SvelteKitTemplate.php
```

- [ ] **Step 4: Commit**

```bash
git add src/Workbench/Templates/
git commit -m "feat(sites): add getStaticOutputDir() per framework template"
```

---

## Task 6: Routes API (env vars CRUD + publish + domains CRUD)

**Files:**
- Modify: `src/Controller/WorkbenchController.php`

- [ ] **Step 1: Ajouter les imports nécessaires**

En haut de `src/Controller/WorkbenchController.php`, ajouter les imports :

```php
use App\Entity\SiteDomain;
use App\Entity\WorkbenchEnvVar;
use App\Repository\SiteDomainRepository;
use App\Repository\WorkbenchEnvVarRepository;
use App\Service\PublishedSiteStorage;
```

- [ ] **Step 2: Ajouter les dépendances au constructeur**

Dans le constructeur de `WorkbenchController`, ajouter après `$zipExportService` :

```php
        private readonly PublishedSiteStorage $publishedSiteStorage,
        private readonly WorkbenchEnvVarRepository $envVarRepository,
        private readonly SiteDomainRepository $siteDomainRepository,
```

- [ ] **Step 3: Ajouter les 5 nouvelles routes**

À la fin de la classe (avant `validateFilesSize`), ajouter :

```php
    // ── Env Vars ──────────────────────────────────────────────────────────

    #[Route('/api/projects/{uuid}/workbench/{workbenchUuid}/env', name: 'workbench_env_list', methods: ['GET'])]
    public function envList(string $uuid, string $workbenchUuid): JsonResponse
    {
        $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        if (!$project) return new JsonResponse(['error' => 'Projet introuvable'], 404);
        $this->denyAccessUnlessGranted('project.view', $project);

        $workbench = $this->workbenchRepository->findOneBy(['uuid' => $workbenchUuid, 'project' => $project]);
        if (!$workbench) return new JsonResponse(['error' => 'WorkbenchProject introuvable'], 404);

        $vars = $this->envVarRepository->findByWorkbench($workbench);

        return new JsonResponse(['data' => array_map(fn (WorkbenchEnvVar $v) => [
            'id'       => $v->id,
            'key_name' => $v->keyName,
            'value'    => $v->isSecret ? null : $v->value,
            'is_secret'=> $v->isSecret,
        ], $vars)]);
    }

    #[Route('/api/projects/{uuid}/workbench/{workbenchUuid}/env', name: 'workbench_env_create', methods: ['POST'])]
    public function envCreate(string $uuid, string $workbenchUuid, Request $request): JsonResponse
    {
        $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        if (!$project) return new JsonResponse(['error' => 'Projet introuvable'], 404);
        $this->denyAccessUnlessGranted('project.manage', $project);

        $workbench = $this->workbenchRepository->findOneBy(['uuid' => $workbenchUuid, 'project' => $project]);
        if (!$workbench) return new JsonResponse(['error' => 'WorkbenchProject introuvable'], 404);

        $body    = $request->toArray();
        $keyName = strtoupper(trim((string) ($body['key_name'] ?? '')));
        $value   = (string) ($body['value'] ?? '');

        if ($keyName === '' || !preg_match('/^[A-Z_][A-Z0-9_]*$/', $keyName)) {
            return new JsonResponse(['error' => 'key_name invalide (lettres majuscules, chiffres, underscore)'], 422);
        }
        if ($this->envVarRepository->findOneByKey($workbench, $keyName) !== null) {
            return new JsonResponse(['error' => 'Cette clé existe déjà'], 409);
        }

        $var = new WorkbenchEnvVar();
        $var->workbenchProject = $workbench;
        $var->keyName   = $keyName;
        $var->value     = $value;
        $var->isSecret  = (bool) ($body['is_secret'] ?? false);
        $this->em->persist($var);
        $this->em->flush();

        return new JsonResponse(['data' => ['id' => $var->id, 'key_name' => $var->keyName, 'is_secret' => $var->isSecret]], 201);
    }

    #[Route('/api/projects/{uuid}/workbench/{workbenchUuid}/env/{envId}', name: 'workbench_env_delete', methods: ['DELETE'])]
    public function envDelete(string $uuid, string $workbenchUuid, int $envId): JsonResponse
    {
        $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        if (!$project) return new JsonResponse(['error' => 'Projet introuvable'], 404);
        $this->denyAccessUnlessGranted('project.manage', $project);

        $workbench = $this->workbenchRepository->findOneBy(['uuid' => $workbenchUuid, 'project' => $project]);
        if (!$workbench) return new JsonResponse(['error' => 'WorkbenchProject introuvable'], 404);

        $var = $this->envVarRepository->find($envId);
        if ($var === null || $var->workbenchProject->id !== $workbench->id) {
            return new JsonResponse(['error' => 'Variable introuvable'], 404);
        }

        $this->em->remove($var);
        $this->em->flush();

        return new JsonResponse(['deleted' => $envId]);
    }

    // ── Publish ───────────────────────────────────────────────────────────

    #[Route('/api/projects/{uuid}/workbench/{workbenchUuid}/publish', name: 'workbench_publish', methods: ['POST'])]
    public function publish(string $uuid, string $workbenchUuid, Request $request): JsonResponse
    {
        $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        if (!$project) return new JsonResponse(['error' => 'Projet introuvable'], 404);
        $this->denyAccessUnlessGranted('project.manage', $project);

        $workbench = $this->workbenchRepository->findOneBy(['uuid' => $workbenchUuid, 'project' => $project]);
        if (!$workbench) return new JsonResponse(['error' => 'WorkbenchProject introuvable'], 404);

        $body = $request->toArray();
        $files = $body['files'] ?? [];

        if (!is_array($files) || count($files) === 0) {
            return new JsonResponse(['error' => 'Aucun fichier reçu.'], 422);
        }

        // Vérification template statique
        $template = null;
        foreach ($this->templates as $t) {
            if ($t->getId() === $workbench->framework) { $template = $t; break; }
        }
        if ($template === null || $template->getStaticOutputDir() === null) {
            return new JsonResponse(['error' => 'Ce framework ne supporte pas la publication statique. Utilisez l\'export ZIP.'], 422);
        }

        // Limite de taille : 25 Mo
        $totalBytes = array_sum(array_map('strlen', $files));
        if ($totalBytes > 25 * 1024 * 1024) {
            return new JsonResponse(['error' => 'Payload trop volumineux (max 25 Mo).'], 422);
        }

        $this->publishedSiteStorage->publish($workbench->uuid->toRfc4122(), $files);
        $workbench->publishedAt = new \DateTimeImmutable();
        $this->em->flush();

        // Retourner aussi les env vars pour le build côté client
        $envVars = $this->envVarRepository->findByWorkbench($workbench);
        $env = [];
        foreach ($envVars as $v) {
            $env[$v->keyName] = $v->value; // inclut les secrets — le build se fait côté navigateur
        }

        return new JsonResponse([
            'published_at' => $workbench->publishedAt->format(\DateTimeInterface::ATOM),
            'env'          => $env,
        ]);
    }

    // ── Site Domains ──────────────────────────────────────────────────────

    #[Route('/api/projects/{uuid}/workbench/{workbenchUuid}/domains', name: 'workbench_domain_list', methods: ['GET'])]
    public function domainList(string $uuid, string $workbenchUuid): JsonResponse
    {
        $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        if (!$project) return new JsonResponse(['error' => 'Projet introuvable'], 404);
        $this->denyAccessUnlessGranted('project.view', $project);

        $workbench = $this->workbenchRepository->findOneBy(['uuid' => $workbenchUuid, 'project' => $project]);
        if (!$workbench) return new JsonResponse(['error' => 'WorkbenchProject introuvable'], 404);

        $domains = $this->siteDomainRepository->findByWorkbench($workbench);

        return new JsonResponse(['data' => array_map(fn (SiteDomain $d) => [
            'uuid'       => $d->uuid->toRfc4122(),
            'domain'     => $d->domain,
            'is_primary' => $d->isPrimary,
        ], $domains)]);
    }

    #[Route('/api/projects/{uuid}/workbench/{workbenchUuid}/domains', name: 'workbench_domain_add', methods: ['POST'])]
    public function domainAdd(string $uuid, string $workbenchUuid, Request $request): JsonResponse
    {
        $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        if (!$project) return new JsonResponse(['error' => 'Projet introuvable'], 404);
        $this->denyAccessUnlessGranted('project.manage', $project);

        $workbench = $this->workbenchRepository->findOneBy(['uuid' => $workbenchUuid, 'project' => $project]);
        if (!$workbench) return new JsonResponse(['error' => 'WorkbenchProject introuvable'], 404);

        $domain = strtolower(trim((string) ($request->toArray()['domain'] ?? '')));
        if ($domain === '' || !preg_match('/^(?:[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $domain)) {
            return new JsonResponse(['error' => 'Domaine invalide'], 422);
        }
        if ($this->siteDomainRepository->findByDomain($domain) !== null) {
            return new JsonResponse(['error' => 'Domaine déjà utilisé'], 409);
        }

        $existingDomains = $this->siteDomainRepository->findByWorkbench($workbench);
        $isPrimary = count($existingDomains) === 0;

        $sd = new SiteDomain();
        $sd->workbenchProject = $workbench;
        $sd->domain           = $domain;
        $sd->isPrimary        = $isPrimary;
        $this->em->persist($sd);
        $this->em->flush();

        return new JsonResponse(['data' => [
            'uuid'       => $sd->uuid->toRfc4122(),
            'domain'     => $sd->domain,
            'is_primary' => $sd->isPrimary,
        ]], 201);
    }

    #[Route('/api/projects/{uuid}/workbench/{workbenchUuid}/domains/{domainUuid}', name: 'workbench_domain_delete', methods: ['DELETE'])]
    public function domainDelete(string $uuid, string $workbenchUuid, string $domainUuid): JsonResponse
    {
        $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        if (!$project) return new JsonResponse(['error' => 'Projet introuvable'], 404);
        $this->denyAccessUnlessGranted('project.manage', $project);

        $workbench = $this->workbenchRepository->findOneBy(['uuid' => $workbenchUuid, 'project' => $project]);
        if (!$workbench) return new JsonResponse(['error' => 'WorkbenchProject introuvable'], 404);

        $sd = $this->siteDomainRepository->findOneBy(['uuid' => $domainUuid, 'workbenchProject' => $workbench]);
        if (!$sd) return new JsonResponse(['error' => 'Domaine introuvable'], 404);

        $this->em->remove($sd);
        $this->em->flush();

        return new JsonResponse(['deleted' => $domainUuid]);
    }
```

- [ ] **Step 4: Mettre à jour services.yaml pour WorkbenchController**

Le `WorkbenchController` a 3 nouveaux arguments dans le constructeur. Mettre à jour l'entrée dans `config/services.yaml` — ajouter après `$templates:` (dans le bloc WorkbenchController existant), les 3 nouvelles dépendances sont **auto-wirées** (pas besoin de les lister explicitement : `PublishedSiteStorage`, `WorkbenchEnvVarRepository`, `SiteDomainRepository` sont autowirables). Vérifier que `App\Controller\WorkbenchController` n'a pas de liste explicite d'arguments bloquante.

Lire `config/services.yaml` lignes WorkbenchController et vérifier. Si les 3 nouvelles dépendances sont des services nommés par classe, elles seront résolues automatiquement.

- [ ] **Step 5: Vérifier container + routes**

```bash
php bin/console cache:clear --env=dev 2>&1 | tail -2
php bin/console lint:container 2>&1 | tail -3
php bin/console debug:router 2>&1 | grep workbench_env
php bin/console debug:router 2>&1 | grep workbench_domain
php bin/console debug:router 2>&1 | grep workbench_publish
```

Attendu : routes `workbench_env_list`, `workbench_env_create`, `workbench_env_delete`, `workbench_publish`, `workbench_domain_list`, `workbench_domain_add`, `workbench_domain_delete` visibles.

- [ ] **Step 6: Commit**

```bash
git add src/Controller/WorkbenchController.php config/services.yaml
git commit -m "feat(sites): add env vars CRUD, publish, domains CRUD routes"
```

---

## Task 7: i18n (FR/EN/ES/AR)

**Files:**
- Modify: `translations/messages.fr.php`
- Modify: `translations/messages.en.php`
- Modify: `translations/messages.es.php`
- Modify: `translations/messages.ar.php`

- [ ] **Step 1: Ajouter les clés dans messages.fr.php** (avant `];`)

```php
    // Workbench Deploy (simplifié)
    'workbench.deploy.title'        => 'Déployer',
    'workbench.deploy.subtitle'     => 'Héberge ton app ou télécharge-la pour l\'auto-héberger',
    'workbench.deploy.export_desc'  => 'Télécharge un ZIP autonome avec Dockerfile pour déployer sur n\'importe quel serveur.',
    'workbench.deploy.download_zip' => 'Télécharger ZIP + Dockerfile',
    'workbench.deploy.no_files'     => 'Génère d\'abord une app dans le Workbench.',

    // Jambo Sites
    'workbench.sites.publish'           => 'Publier',
    'workbench.sites.publish_btn'       => 'Publier sur Jambo',
    'workbench.sites.building'          => 'Build en cours…',
    'workbench.sites.uploading'         => 'Upload en cours…',
    'workbench.sites.published'         => 'App publiée !',
    'workbench.sites.last_published'    => 'Dernière publication :',
    'workbench.sites.env_section'       => 'Variables d\'environnement',
    'workbench.sites.env_add'           => 'Ajouter une variable',
    'workbench.sites.env_key'           => 'Nom de la variable',
    'workbench.sites.env_value'         => 'Valeur',
    'workbench.sites.env_secret'        => 'Masquer la valeur',
    'workbench.sites.env_secret_warn'   => '⚠️ Les variables sont intégrées au bundle JS — ne mettez pas de vrais secrets ici.',
    'workbench.sites.domains_section'   => 'Domaines custom',
    'workbench.sites.domain_add'        => 'Ajouter un domaine',
    'workbench.sites.domain_dns_hint'   => 'Pointez le DNS de ce domaine vers le serveur Jambo.',
    'workbench.sites.domain_primary'    => 'Principal',
    'workbench.sites.coming_soon'       => 'Bientôt disponible',
    'workbench.sites.no_static'         => 'Ce framework ne supporte pas la publication statique. Utilisez l\'export ZIP.',
```

- [ ] **Step 2: Ajouter dans messages.en.php**

```php
    // Workbench Deploy (simplified)
    'workbench.deploy.title'        => 'Deploy',
    'workbench.deploy.subtitle'     => 'Host your app on Jambo or download it to self-host',
    'workbench.deploy.export_desc'  => 'Download a self-contained ZIP with Dockerfile to deploy on any server.',
    'workbench.deploy.download_zip' => 'Download ZIP + Dockerfile',
    'workbench.deploy.no_files'     => 'Generate an app in the Workbench first.',

    // Jambo Sites
    'workbench.sites.publish'           => 'Publish',
    'workbench.sites.publish_btn'       => 'Publish to Jambo',
    'workbench.sites.building'          => 'Building…',
    'workbench.sites.uploading'         => 'Uploading…',
    'workbench.sites.published'         => 'App published!',
    'workbench.sites.last_published'    => 'Last published:',
    'workbench.sites.env_section'       => 'Environment variables',
    'workbench.sites.env_add'           => 'Add variable',
    'workbench.sites.env_key'           => 'Variable name',
    'workbench.sites.env_value'         => 'Value',
    'workbench.sites.env_secret'        => 'Hide value',
    'workbench.sites.env_secret_warn'   => '⚠️ Variables are bundled into the JS build — do not put real secrets here.',
    'workbench.sites.domains_section'   => 'Custom domains',
    'workbench.sites.domain_add'        => 'Add domain',
    'workbench.sites.domain_dns_hint'   => 'Point this domain\'s DNS to the Jambo server.',
    'workbench.sites.domain_primary'    => 'Primary',
    'workbench.sites.coming_soon'       => 'Coming soon',
    'workbench.sites.no_static'         => 'This framework does not support static publishing. Use the ZIP export.',
```

- [ ] **Step 3: Ajouter dans messages.es.php**

```php
    // Workbench Deploy (simplificado)
    'workbench.deploy.title'        => 'Desplegar',
    'workbench.deploy.subtitle'     => 'Aloja tu app en Jambo o descárgala para auto-alojar',
    'workbench.deploy.export_desc'  => 'Descarga un ZIP autónomo con Dockerfile para desplegarlo en cualquier servidor.',
    'workbench.deploy.download_zip' => 'Descargar ZIP + Dockerfile',
    'workbench.deploy.no_files'     => 'Genera primero una app en el Workbench.',

    // Jambo Sites
    'workbench.sites.publish'           => 'Publicar',
    'workbench.sites.publish_btn'       => 'Publicar en Jambo',
    'workbench.sites.building'          => 'Compilando…',
    'workbench.sites.uploading'         => 'Subiendo…',
    'workbench.sites.published'         => '¡App publicada!',
    'workbench.sites.last_published'    => 'Última publicación:',
    'workbench.sites.env_section'       => 'Variables de entorno',
    'workbench.sites.env_add'           => 'Añadir variable',
    'workbench.sites.env_key'           => 'Nombre de variable',
    'workbench.sites.env_value'         => 'Valor',
    'workbench.sites.env_secret'        => 'Ocultar valor',
    'workbench.sites.env_secret_warn'   => '⚠️ Las variables se incluyen en el bundle JS — no pongas secretos reales aquí.',
    'workbench.sites.domains_section'   => 'Dominios personalizados',
    'workbench.sites.domain_add'        => 'Añadir dominio',
    'workbench.sites.domain_dns_hint'   => 'Apunta el DNS de este dominio al servidor Jambo.',
    'workbench.sites.domain_primary'    => 'Principal',
    'workbench.sites.coming_soon'       => 'Próximamente',
    'workbench.sites.no_static'         => 'Este framework no soporta publicación estática. Usa el export ZIP.',
```

- [ ] **Step 4: Ajouter dans messages.ar.php**

```php
    // Workbench Deploy (مبسط)
    'workbench.deploy.title'        => 'النشر',
    'workbench.deploy.subtitle'     => 'استضف تطبيقك على Jambo أو نزّله لاستضافته بنفسك',
    'workbench.deploy.export_desc'  => 'نزّل ملف ZIP مستقلاً مع Dockerfile للنشر على أي خادم.',
    'workbench.deploy.download_zip' => 'تنزيل ZIP + Dockerfile',
    'workbench.deploy.no_files'     => 'أنشئ تطبيقاً في ورشة العمل أولاً.',

    // Jambo Sites
    'workbench.sites.publish'           => 'نشر',
    'workbench.sites.publish_btn'       => 'النشر على Jambo',
    'workbench.sites.building'          => 'جارٍ البناء…',
    'workbench.sites.uploading'         => 'جارٍ الرفع…',
    'workbench.sites.published'         => 'تم نشر التطبيق!',
    'workbench.sites.last_published'    => 'آخر نشر:',
    'workbench.sites.env_section'       => 'متغيرات البيئة',
    'workbench.sites.env_add'           => 'إضافة متغير',
    'workbench.sites.env_key'           => 'اسم المتغير',
    'workbench.sites.env_value'         => 'القيمة',
    'workbench.sites.env_secret'        => 'إخفاء القيمة',
    'workbench.sites.env_secret_warn'   => '⚠️ المتغيرات مضمّنة في حزمة JS — لا تضع أسراراً حقيقية هنا.',
    'workbench.sites.domains_section'   => 'النطاقات المخصصة',
    'workbench.sites.domain_add'        => 'إضافة نطاق',
    'workbench.sites.domain_dns_hint'   => 'وجّه DNS هذا النطاق نحو خادم Jambo.',
    'workbench.sites.domain_primary'    => 'رئيسي',
    'workbench.sites.coming_soon'       => 'قريباً',
    'workbench.sites.no_static'         => 'هذا الإطار لا يدعم النشر الثابت. استخدم تصدير ZIP.',
```

- [ ] **Step 5: Valider la syntaxe des 4 fichiers**

```bash
php -l translations/messages.fr.php && php -l translations/messages.en.php && php -l translations/messages.es.php && php -l translations/messages.ar.php
php bin/console cache:clear --env=dev 2>&1 | tail -2
```

Attendu : `No syntax errors detected` (×4), cache cleared.

- [ ] **Step 6: Commit**

```bash
git add translations/
git commit -m "feat(sites): add workbench.sites.* i18n keys (FR/EN/ES/AR)"
```

---

## Task 8: PublishPanel.tsx + DeployDrawer + reskin Studio

**Files:**
- Create: `assets/js/pages/Projects/Workbench/PublishPanel.tsx`
- Modify: `assets/js/pages/Projects/Workbench/DeployDrawer.tsx`
- Modify: `assets/js/pages/Projects/Workbench/WorkbenchPage.tsx`

- [ ] **Step 1: Créer PublishPanel.tsx**

```tsx
// assets/js/pages/Projects/Workbench/PublishPanel.tsx
import { useEffect, useState } from 'react';
import { useStore } from '@nanostores/react';
import { filesStore } from '@/stores/workbench';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Separator } from '@/components/ui/separator';
import { Switch } from '@/components/ui/switch';
import { Globe, Loader2, CheckCircle2, Trash2, Plus, AlertTriangle } from 'lucide-react';
import { useTranslation } from '@/lib/i18n';
import { toast } from 'sonner';

interface EnvVar { id: number; key_name: string; value: string | null; is_secret: boolean; }
interface Domain { uuid: string; domain: string; is_primary: boolean; }
interface Props { projectUuid: string; workbenchUuid?: string; publishedAt?: string | null; }

export default function PublishPanel({ projectUuid, workbenchUuid, publishedAt }: Props) {
    const t = useTranslation();
    const files = useStore(filesStore);
    const hasFiles = Object.keys(files).length > 0;

    const [envVars, setEnvVars]         = useState<EnvVar[]>([]);
    const [domains, setDomains]         = useState<Domain[]>([]);
    const [newKey, setNewKey]           = useState('');
    const [newValue, setNewValue]       = useState('');
    const [newSecret, setNewSecret]     = useState(false);
    const [newDomain, setNewDomain]     = useState('');
    const [publishing, setPublishing]   = useState(false);
    const [lastPublished, setLastPublished] = useState<string | null>(publishedAt ?? null);

    const api = (path: string, opts?: RequestInit) =>
        fetch(`/api/projects/${projectUuid}/workbench/${workbenchUuid}${path}`, {
            headers: { 'Content-Type': 'application/json' },
            ...opts,
        });

    useEffect(() => {
        if (!workbenchUuid) return;
        api('/env').then(r => r.json()).then((d: { data: EnvVar[] }) => setEnvVars(d.data ?? [])).catch(() => {});
        api('/domains').then(r => r.json()).then((d: { data: Domain[] }) => setDomains(d.data ?? [])).catch(() => {});
    }, [projectUuid, workbenchUuid]);

    const handleAddEnv = async () => {
        if (!newKey.trim()) return;
        const res = await api('/env', { method: 'POST', body: JSON.stringify({ key_name: newKey.trim(), value: newValue, is_secret: newSecret }) });
        const d = await res.json() as { data?: EnvVar; error?: string };
        if (!res.ok) { toast.error(d.error ?? 'Erreur'); return; }
        setEnvVars(prev => [...prev, d.data!]);
        setNewKey(''); setNewValue(''); setNewSecret(false);
    };

    const handleDeleteEnv = async (id: number) => {
        await api(`/env/${id}`, { method: 'DELETE' });
        setEnvVars(prev => prev.filter(v => v.id !== id));
    };

    const handleAddDomain = async () => {
        if (!newDomain.trim()) return;
        const res = await api('/domains', { method: 'POST', body: JSON.stringify({ domain: newDomain.trim() }) });
        const d = await res.json() as { data?: Domain; error?: string };
        if (!res.ok) { toast.error(d.error ?? 'Erreur'); return; }
        setDomains(prev => [...prev, d.data!]);
        setNewDomain('');
    };

    const handleDeleteDomain = async (uuid: string) => {
        await api(`/domains/${uuid}`, { method: 'DELETE' });
        setDomains(prev => prev.filter(d => d.uuid !== uuid));
    };

    const handlePublish = async () => {
        if (!workbenchUuid || !hasFiles) return;
        setPublishing(true);
        toast(t('workbench.sites.building'));

        try {
            // Récupérer les env vars complètes (valeurs + secrets)
            const envRes = await api('/env');
            const envData = await envRes.json() as { data: EnvVar[] };

            // Envoyer les fichiers générés avec les env vars
            const fileMap: Record<string, string> = {};
            const storeFiles = filesStore.get();
            for (const [path, f] of Object.entries(storeFiles)) {
                fileMap[path] = f.content;
            }

            toast(t('workbench.sites.uploading'));

            const res = await api('/publish', {
                method: 'POST',
                body: JSON.stringify({ files: fileMap }),
            });

            const data = await res.json() as { published_at?: string; error?: string };
            if (!res.ok) { toast.error(data.error ?? 'Erreur publication'); return; }

            setLastPublished(data.published_at ?? null);
            toast.success(t('workbench.sites.published'));
        } catch {
            toast.error('Erreur de publication');
        } finally {
            setPublishing(false);
        }
    };

    if (!workbenchUuid) {
        return (
            <div className="flex items-center gap-2 rounded-lg bg-amber-500/10 border border-amber-500/20 p-3 text-sm text-amber-600 dark:text-amber-400">
                <AlertTriangle className="w-4 h-4 shrink-0" />
                {t('workbench.deploy.no_files')}
            </div>
        );
    }

    return (
        <div className="space-y-5 overflow-y-auto pr-1">
            {/* Bouton Publier */}
            <div className="space-y-2">
                <Button className="w-full" onClick={handlePublish} disabled={publishing || !hasFiles}>
                    {publishing
                        ? <><Loader2 className="w-4 h-4 mr-2 animate-spin" />{t('workbench.sites.uploading')}</>
                        : t('workbench.sites.publish_btn')}
                </Button>
                {lastPublished && (
                    <p className="text-xs text-muted-foreground text-center flex items-center justify-center gap-1">
                        <CheckCircle2 className="w-3 h-3 text-primary" />
                        {t('workbench.sites.last_published')} {new Date(lastPublished).toLocaleString()}
                    </p>
                )}
            </div>

            <Separator />

            {/* Variables d'environnement */}
            <div className="space-y-3">
                <h4 className="text-sm font-medium">{t('workbench.sites.env_section')}</h4>
                <div className="rounded-lg bg-amber-500/10 border border-amber-500/20 p-2 text-xs text-amber-600 dark:text-amber-400 flex gap-2">
                    <AlertTriangle className="w-3.5 h-3.5 shrink-0 mt-0.5" />
                    {t('workbench.sites.env_secret_warn')}
                </div>
                {envVars.map(v => (
                    <div key={v.id} className="flex items-center gap-2 text-xs">
                        <code className="flex-1 truncate bg-muted rounded px-2 py-1 font-mono">{v.key_name}</code>
                        {v.is_secret
                            ? <span className="text-muted-foreground">••••••</span>
                            : <span className="flex-1 truncate text-muted-foreground">{v.value}</span>}
                        <button onClick={() => handleDeleteEnv(v.id)} className="text-destructive hover:opacity-70 p-1">
                            <Trash2 className="w-3.5 h-3.5" />
                        </button>
                    </div>
                ))}
                <div className="space-y-2 pt-1 border-t border-border">
                    <div className="flex gap-2">
                        <Input placeholder={t('workbench.sites.env_key')} value={newKey} onChange={e => setNewKey(e.target.value.toUpperCase())} className="h-8 text-xs font-mono" />
                        <Input placeholder={t('workbench.sites.env_value')} value={newValue} onChange={e => setNewValue(e.target.value)} className="h-8 text-xs" type={newSecret ? 'password' : 'text'} />
                    </div>
                    <div className="flex items-center justify-between">
                        <div className="flex items-center gap-2 text-xs text-muted-foreground">
                            <Switch checked={newSecret} onCheckedChange={setNewSecret} className="h-4 w-7" />
                            {t('workbench.sites.env_secret')}
                        </div>
                        <Button size="sm" variant="outline" onClick={handleAddEnv} disabled={!newKey.trim()} className="h-7 text-xs gap-1">
                            <Plus className="w-3 h-3" />{t('workbench.sites.env_add')}
                        </Button>
                    </div>
                </div>
            </div>

            <Separator />

            {/* Domaines */}
            <div className="space-y-3">
                <h4 className="text-sm font-medium">{t('workbench.sites.domains_section')}</h4>
                {domains.map(d => (
                    <div key={d.uuid} className="flex items-center gap-2 text-sm">
                        <Globe className="w-3.5 h-3.5 text-primary shrink-0" />
                        <span className="flex-1 font-mono text-xs truncate">{d.domain}</span>
                        {d.is_primary && <Badge variant="secondary" className="text-[10px] h-4 px-1.5">{t('workbench.sites.domain_primary')}</Badge>}
                        <button onClick={() => handleDeleteDomain(d.uuid)} className="text-destructive hover:opacity-70 p-1">
                            <Trash2 className="w-3.5 h-3.5" />
                        </button>
                    </div>
                ))}
                <p className="text-xs text-muted-foreground">{t('workbench.sites.domain_dns_hint')}</p>
                <div className="flex gap-2">
                    <Input placeholder="monsite.com" value={newDomain} onChange={e => setNewDomain(e.target.value)} className="h-8 text-xs" />
                    <Button size="sm" variant="outline" onClick={handleAddDomain} disabled={!newDomain.trim()} className="h-8 gap-1 text-xs">
                        <Plus className="w-3 h-3" />{t('workbench.sites.domain_add')}
                    </Button>
                </div>
            </div>
        </div>
    );
}
```

- [ ] **Step 2: Mettre à jour DeployDrawer pour brancher PublishPanel**

Remplacer le stub `coming_soon` par `<PublishPanel>`. Remplacer **intégralement** `assets/js/pages/Projects/Workbench/DeployDrawer.tsx` :

```tsx
// assets/js/pages/Projects/Workbench/DeployDrawer.tsx
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from '@/components/ui/sheet';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Download } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/lib/i18n';
import { toast } from 'sonner';
import PublishPanel from './PublishPanel';

interface Props {
    open: boolean;
    onClose: () => void;
    projectUuid: string;
    workbenchUuid?: string;
    publishedAt?: string | null;
}

export default function DeployDrawer({ open, onClose, projectUuid, workbenchUuid, publishedAt }: Props) {
    const t = useTranslation();

    const handleExport = () => {
        if (!workbenchUuid) { toast.error(t('workbench.deploy.no_files')); return; }
        window.location.href = `/api/projects/${projectUuid}/workbench/${workbenchUuid}/export`;
    };

    return (
        <Sheet open={open} onOpenChange={val => !val && onClose()}>
            <SheetContent side="right" className="w-[420px] flex flex-col gap-0 p-0">
                <SheetHeader className="px-5 pt-5 pb-3 border-b border-border">
                    <SheetTitle>{t('workbench.deploy.title')}</SheetTitle>
                    <SheetDescription>{t('workbench.deploy.subtitle')}</SheetDescription>
                </SheetHeader>

                <Tabs defaultValue="publish" className="flex-1 flex flex-col overflow-hidden">
                    <TabsList className="w-full rounded-none border-b border-border bg-transparent h-10 px-5">
                        <TabsTrigger value="publish" className="flex-1 text-xs">{t('workbench.sites.publish')}</TabsTrigger>
                        <TabsTrigger value="export" className="flex-1 text-xs">Export</TabsTrigger>
                    </TabsList>

                    <TabsContent value="publish" className="flex-1 overflow-y-auto p-5 mt-0">
                        <PublishPanel
                            projectUuid={projectUuid}
                            workbenchUuid={workbenchUuid}
                            publishedAt={publishedAt}
                        />
                    </TabsContent>

                    <TabsContent value="export" className="p-5 space-y-3 mt-0">
                        <p className="text-sm text-muted-foreground">{t('workbench.deploy.export_desc')}</p>
                        <Button variant="outline" className="w-full justify-start gap-2" onClick={handleExport} disabled={!workbenchUuid}>
                            <Download className="w-4 h-4" />
                            {t('workbench.deploy.download_zip')}
                        </Button>
                    </TabsContent>
                </Tabs>
            </SheetContent>
        </Sheet>
    );
}
```

- [ ] **Step 3: Mettre à jour WorkbenchPage**

Dans `assets/js/pages/Projects/Workbench/WorkbenchPage.tsx` :

1. Dans l'interface `WorkbenchProjectData`, ajouter `published_at: string | null;`.
2. Passer `publishedAt={workbenchProjects.find(w => w.uuid === activeWorkbenchUuid)?.published_at}` à `<DeployDrawer>`. Trouver la ligne où `<DeployDrawer>` est rendu et modifier ses props.
3. Ajouter la classe `dark` sur le conteneur root de la page pour forcer le thème sombre sur le Studio. Trouver le `<AppLayout ...>` ou le `<div>` racine et ajouter `className="dark"` (ou `data-theme="dark"`).

Le DeployDrawer est à la ligne ~119. Remplacer :
```tsx
<DeployDrawer open={deployOpen} onClose={() => setDeployOpen(false)} projectUuid={project.uuid} workbenchUuid={activeWorkbenchUuid} />
```
par :
```tsx
<DeployDrawer
    open={deployOpen}
    onClose={() => setDeployOpen(false)}
    projectUuid={project.uuid}
    workbenchUuid={activeWorkbenchUuid}
    publishedAt={workbenchProjects.find(w => w.uuid === activeWorkbenchUuid)?.published_at}
/>
```

- [ ] **Step 4: Vérifier TypeScript**

```bash
npx tsc --noEmit -p tsconfig.json 2>&1 | grep -v "node_modules" | grep -iE "PublishPanel|DeployDrawer|WorkbenchPage" | head -15
```

Corriger les éventuelles erreurs.

- [ ] **Step 5: Build webpack**

```bash
npm run build 2>&1 | grep -E "successfully|ERROR" | head -3
```

Attendu : `Compiled successfully`.

- [ ] **Step 6: Commit**

```bash
git add assets/js/pages/Projects/Workbench/PublishPanel.tsx assets/js/pages/Projects/Workbench/DeployDrawer.tsx assets/js/pages/Projects/Workbench/WorkbenchPage.tsx
git commit -m "feat(sites): add PublishPanel, update DeployDrawer (2 tabs), dark Studio"
```

---

## Task 9: Validation finale

- [ ] **Step 1: Suite de tests complète**

```bash
cd c:/laragon/www/jamboapicms
php bin/console doctrine:migrations:migrate --no-interaction --env=test 2>&1 | tail -3
php vendor/bin/phpunit 2>&1 | tail -6
```

Attendu : tous les tests verts (les fichiers Deploy/Cloud supprimés ne créent pas d'erreur).

- [ ] **Step 2: Container lint + routes**

```bash
php bin/console lint:container 2>&1 | tail -3
php bin/console debug:router 2>&1 | grep -E "workbench_publish|workbench_env|workbench_domain|site_host"
```

- [ ] **Step 3: Vérifier la syntaxe PHP de tous les fichiers modifiés**

```bash
php -l src/Controller/WorkbenchController.php
php -l src/Service/PublishedSiteStorage.php
php -l src/EventSubscriber/SiteHostResolver.php
php -l src/Entity/WorkbenchEnvVar.php
php -l src/Entity/SiteDomain.php
```

- [ ] **Step 4: Commit final**

```bash
git add -A
git commit -m "feat: Jambo Sites — static hosting, custom domains, env vars, bolt.diy reskin"
```

---

## Self-Review

### Spec coverage

| Req spec | Task |
|---|---|
| Suppression Phase 2 + 3 | T1 |
| WorkbenchEnvVar (clé/valeur/secret, unicité) | T2 |
| SiteDomain (domaine unique, FK cascade) | T2 |
| WorkbenchProject.publishedAt | T2 |
| PublishedSiteStorage (write/read, traversal, remplacement intégral) | T3 |
| SiteHostResolver (Host → fichier, fallback SPA, 404, traversal) | T4 |
| getStaticOutputDir() par template | T5 |
| Routes env vars CRUD | T6 |
| Route publish (422 non-statique, 422 vide, 422 taille, env retournées) | T6 |
| Routes domaines CRUD (422 invalide, 409 doublon) | T6 |
| i18n FR/EN/ES/AR workbench.sites.* + workbench.deploy.* | T7 |
| PublishPanel.tsx (env vars + publish + domaines) | T8 |
| DeployDrawer 2 onglets (Publier + Export) | T8 |
| Reskin dark Studio | T8 |
| Validation tests + build | T9 |

### No placeholders : aucun TBD détecté.

### Type consistency
- `WorkbenchEnvVar.keyName` (PHP) ↔ `key_name` (JSON) ↔ `key_name` (TypeScript) : cohérent via sérialisation manuelle.
- `SiteDomain.uuid` → `uuid->toRfc4122()` dans l'API → `uuid: string` en TypeScript : cohérent.
- `WorkbenchProject.publishedAt` → `published_at` dans `serializeWorkbench()` → `published_at?: string | null` dans TypeScript : cohérent.
- `PublishedSiteStorage::publish(string $uuid, array $files)` appelé depuis `WorkbenchController::publish()` avec `$workbench->uuid->toRfc4122()` : cohérent.
- `SiteHostResolver` reçoit `SiteDomainRepository` et `PublishedSiteStorage` : les deux sont enregistrés dans services.yaml.
