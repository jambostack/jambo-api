<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Command\AutomationMigrateToFlowCommand;
use App\Command\AutomationRunScheduledCommand;
use App\Entity\Automation;
use App\Entity\AutomationRun;
use App\Entity\Collection;
use App\Entity\ContentEntry;
use App\Entity\Project;
use App\Message\ExecuteAutomationMessage;
use App\MessageHandler\ExecuteAutomationMessageHandler;
use App\Repository\AutomationRepository;
use App\Repository\AutomationRunRepository;
use App\Repository\CollectionRepository;
use App\Repository\ContentEntryRepository;
use App\Repository\ProjectRepository;
use App\Service\Automation\AutomationEngine;
use App\Service\Automation\AutomationExportImportService;
use App\Service\EavFieldHelperService;
use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowInterpreter;
use App\Service\Flow\FlowNodeHandler;
use App\Service\Flow\FlowResult;
use App\Service\Flow\FlowValidator;
use App\Service\Flow\Handlers\Action\CreateEntryHandler;
use App\Service\Flow\Handlers\Action\DeleteEntryHandler;
use App\Service\Flow\Handlers\Action\PublishEntryHandler;
use App\Service\Flow\Handlers\Action\UpdateEntryHandler;
use App\Service\Flow\Handlers\Database\CountEntriesHandler;
use App\Service\Flow\Handlers\Database\FindEntriesHandler;
use App\Service\Flow\NodeRegistry;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

class Cycle79AutomationIsolationI18nTest extends TestCase
{
    private TranslatorInterface $translator;

    protected function setUp(): void
    {
        $translations = [
            'en' => require __DIR__ . '/../../translations/messages.en.php',
            'fr' => require __DIR__ . '/../../translations/messages.fr.php',
            'es' => require __DIR__ . '/../../translations/messages.es.php',
            'ar' => require __DIR__ . '/../../translations/messages.ar.php',
        ];

        $this->translator = new class($translations) implements TranslatorInterface {
            public function __construct(private array $catalogs) {}

            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                $lang = $locale ?? 'fr';
                $catalog = $this->catalogs[$lang] ?? $this->catalogs['fr'];
                $msg = $catalog[$id] ?? $id;
                return empty($parameters) ? $msg : strtr($msg, $parameters);
            }

            public function getLocale(): string { return 'fr'; }
        };
    }

    // ─── 1. Tests d'isolation dans ExecuteAutomationMessageHandler ───

    public function testExecuteAutomationMessageHandlerRejectsRunMismatch(): void
    {
        $runRepo = $this->createMock(AutomationRunRepository::class);
        $autoRepo = $this->createMock(AutomationRepository::class);
        $engine = $this->createMock(AutomationEngine::class);
        $em = $this->createMock(EntityManagerInterface::class);

        $autoA = new Automation();
        $autoA->id = 10;
        $autoA->isActive = true;

        $autoB = new Automation();
        $autoB->id = 20;
        $autoB->isActive = true;

        $run = new AutomationRun();
        $run->id = 100;
        $run->status = 'pending';
        $run->automation = $autoB; // Run appartient à autoB

        $runRepo->method('find')->with(100)->willReturn($run);
        $autoRepo->method('find')->with(10)->willReturn($autoA); // Message cible autoA

        $handler = new ExecuteAutomationMessageHandler(
            $autoRepo,
            $runRepo,
            $engine,
            $em,
            $this->translator
        );

        $em->expects($this->once())->method('flush');

        $message = new ExecuteAutomationMessage(
            automationId: 10,
            runId: 100,
            triggerPayload: [],
            projectUuid: '00000000-0000-0000-0000-000000000001',
            debugMode: false
        );

        $handler($message);

        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString("L'exécution d'automatisation n'appartient pas", $run->errorMessage);
    }

    public function testExecuteAutomationMessageHandlerRejectsMissingProject(): void
    {
        $runRepo = $this->createMock(AutomationRunRepository::class);
        $autoRepo = $this->createMock(AutomationRepository::class);
        $engine = $this->createMock(AutomationEngine::class);
        $em = $this->createMock(EntityManagerInterface::class);

        $auto = new Automation();
        $auto->id = 15;
        $auto->isActive = true;
        $auto->project = null; // Pas de projet associé

        $run = new AutomationRun();
        $run->id = 101;
        $run->status = 'pending';
        $run->automation = $auto;

        $runRepo->method('find')->with(101)->willReturn($run);
        $autoRepo->method('find')->with(15)->willReturn($auto);

        $handler = new ExecuteAutomationMessageHandler(
            $autoRepo,
            $runRepo,
            $engine,
            $em,
            $this->translator
        );

        $message = new ExecuteAutomationMessage(
            automationId: 15,
            runId: 101,
            triggerPayload: [],
            projectUuid: '00000000-0000-0000-0000-000000000001',
            debugMode: false
        );

        $handler($message);

        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString("L'automatisation n'a aucun projet associé", $run->errorMessage);
    }

    public function testExecuteAutomationMessageHandlerRejectsCrossTenantExecution(): void
    {
        $runRepo = $this->createMock(AutomationRunRepository::class);
        $autoRepo = $this->createMock(AutomationRepository::class);
        $engine = $this->createMock(AutomationEngine::class);
        $em = $this->createMock(EntityManagerInterface::class);

        $projectA = new Project();
        $projectA->uuid = Uuid::fromString('11111111-1111-1111-1111-111111111111');

        $auto = new Automation();
        $auto->id = 18;
        $auto->isActive = true;
        $auto->project = $projectA;

        $run = new AutomationRun();
        $run->id = 102;
        $run->status = 'pending';
        $run->automation = $auto;

        $runRepo->method('find')->with(102)->willReturn($run);
        $autoRepo->method('find')->with(18)->willReturn($auto);

        $handler = new ExecuteAutomationMessageHandler(
            $autoRepo,
            $runRepo,
            $engine,
            $em,
            $this->translator
        );

        // Tentative d'exécution avec l'UUID du projet B
        $message = new ExecuteAutomationMessage(
            automationId: 18,
            runId: 102,
            triggerPayload: [],
            projectUuid: '22222222-2222-2222-2222-222222222222',
            debugMode: false
        );

        $handler($message);

        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('Exécution cross-tenant interdite', $run->errorMessage);
    }

    // ─── 2. Tests de propagation du projet dans AutomationEngine ───

    public function testAutomationEnginePropagatesProjectContextToFlow(): void
    {
        $interpreter = $this->createMock(FlowInterpreter::class);
        $em = $this->createMock(EntityManagerInterface::class);

        $project = new Project();
        $project->uuid = Uuid::fromString('55555555-5555-5555-5555-555555555555');

        $auto = new Automation();
        $auto->id = 5;
        $auto->flowGraph = ['nodes' => [['id' => 'n1', 'type' => 'trigger.content.created']]];
        $auto->project = $project;

        $interpreter->expects($this->once())
            ->method('executeFlow')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->callback(function (FlowContext $ctx) {
                    return $ctx->projectUuid === '55555555-5555-5555-5555-555555555555';
                })
            )
            ->willReturn(new FlowResult(status: 'success', totalDurationMs: 10));

        $engine = new AutomationEngine($interpreter, $em, $this->translator);
        $run = $engine->execute($auto, []);

        $this->assertSame('success', $run->status);
    }

    // ─── 3. Tests des Handlers de base de données (Count & Find) ───

    public function testCountEntriesHandlerEnforcesTenantContext(): void
    {
        $entryRepo = $this->createMock(ContentEntryRepository::class);
        $colRepo = $this->createMock(CollectionRepository::class);
        $projRepo = $this->createMock(ProjectRepository::class);

        $handler = new CountEntriesHandler($entryRepo, $colRepo, $projRepo, $this->translator);

        // A. ProjectUuid vide
        $ctxEmpty = new FlowContext(1, '');
        $outEmpty = $handler->execute([], $ctxEmpty);
        $this->assertSame(0, $outEmpty->data['count']);
        $this->assertSame('Le contexte de projet est requis.', $outEmpty->data['error']);

        // B. Projet inexistant
        $projRepo->method('findOneBy')->willReturn(null);
        $ctxNotFound = new FlowContext(1, '33333333-3333-3333-3333-333333333333');
        $outNotFound = $handler->execute([], $ctxNotFound);
        $this->assertSame(0, $outNotFound->data['count']);
        $this->assertSame('Projet introuvable.', $outNotFound->data['error']);

        // C. Collection non trouvée dans le projet
        $project = new Project();
        $project->uuid = Uuid::fromString('33333333-3333-3333-3333-333333333333');
        $projRepo2 = $this->createMock(ProjectRepository::class);
        $projRepo2->method('findOneBy')->willReturn($project);
        $colRepo->method('findOneBy')->willReturn(null);

        $handler2 = new CountEntriesHandler($entryRepo, $colRepo, $projRepo2, $this->translator);
        $ctxColNotFound = new FlowContext(1, '33333333-3333-3333-3333-333333333333');
        $ctxColNotFound->variables['_node_config'] = ['collection_slug' => 'articles'];

        $outColNotFound = $handler2->execute([], $ctxColNotFound);
        $this->assertSame(0, $outColNotFound->data['count']);
        $this->assertStringContainsString('Collection "articles" introuvable dans le projet actuel.', $outColNotFound->data['error']);

        // D. Succès avec scoping strict
        $collection = new Collection();
        $collection->slug = 'articles';
        $collection->project = $project;

        $colRepoSuccess = $this->createMock(CollectionRepository::class);
        $colRepoSuccess->method('findOneBy')->with(['slug' => 'articles', 'project' => $project])->willReturn($collection);

        $entryRepoSuccess = $this->createMock(ContentEntryRepository::class);
        $entryRepoSuccess->expects($this->once())
            ->method('count')
            ->with(['collection' => $collection, 'status' => 'draft'])
            ->willReturn(42);

        $handler3 = new CountEntriesHandler($entryRepoSuccess, $colRepoSuccess, $projRepo2, $this->translator);
        $ctxSuccess = new FlowContext(1, '33333333-3333-3333-3333-333333333333');
        $ctxSuccess->variables['_node_config'] = ['collection_slug' => 'articles', 'status' => 'draft'];

        $outSuccess = $handler3->execute([], $ctxSuccess);
        $this->assertSame(42, $outSuccess->data['count']);
        $this->assertSame('articles', $outSuccess->data['collection_slug']);
    }

    public function testFindEntriesHandlerEnforcesTenantContext(): void
    {
        $entryRepo = $this->createMock(ContentEntryRepository::class);
        $colRepo = $this->createMock(CollectionRepository::class);
        $projRepo = $this->createMock(ProjectRepository::class);

        $handler = new FindEntriesHandler($entryRepo, $colRepo, $projRepo, $this->translator);

        // A. ProjectUuid vide
        $ctxEmpty = new FlowContext(1, '');
        $outEmpty = $handler->execute([], $ctxEmpty);
        $this->assertSame(0, $outEmpty->data['count']);
        $this->assertSame('Le contexte de projet est requis.', $outEmpty->data['error']);

        // B. Projet inexistant
        $projRepo->method('findOneBy')->willReturn(null);
        $ctxNotFound = new FlowContext(1, '44444444-4444-4444-4444-444444444444');
        $outNotFound = $handler->execute([], $ctxNotFound);
        $this->assertSame(0, $outNotFound->data['count']);
        $this->assertSame('Projet introuvable.', $outNotFound->data['error']);

        // C. Succès avec scoping strict
        $project = new Project();
        $project->uuid = Uuid::fromString('44444444-4444-4444-4444-444444444444');
        $projRepoSuccess = $this->createMock(ProjectRepository::class);
        $projRepoSuccess->method('findOneBy')->willReturn($project);

        $collection = new Collection();
        $collection->slug = 'posts';
        $collection->project = $project;

        $colRepoSuccess = $this->createMock(CollectionRepository::class);
        $colRepoSuccess->method('findOneBy')->with(['slug' => 'posts', 'project' => $project])->willReturn($collection);

        $e1 = new ContentEntry();
        $e1->id = 1;
        $e1->name = 'Hello';
        $e1->status = 'published';
        $e1->slug = 'hello';

        $entryRepoSuccess = $this->createMock(ContentEntryRepository::class);
        $entryRepoSuccess->expects($this->once())
            ->method('findBy')
            ->with(['collection' => $collection], ['createdAt' => 'DESC'], 5)
            ->willReturn([$e1]);

        $handler2 = new FindEntriesHandler($entryRepoSuccess, $colRepoSuccess, $projRepoSuccess, $this->translator);
        $ctxSuccess = new FlowContext(1, '44444444-4444-4444-4444-444444444444');
        $ctxSuccess->variables['_node_config'] = ['collection_slug' => 'posts', 'status' => 'all', 'limit' => 5];

        $outSuccess = $handler2->execute([], $ctxSuccess);
        $this->assertSame(1, $outSuccess->data['count']);
        $this->assertSame('Hello', $outSuccess->data['entries'][0]['title']);
    }

    // ─── 4. Tests des Handlers d'action (Create, Update, Delete, Publish) ───

    public function testActionHandlersEnforceTenantScoping(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $entryRepo = $this->createMock(ContentEntryRepository::class);
        $colRepo = $this->createMock(CollectionRepository::class);
        $projRepo = $this->createMock(ProjectRepository::class);
        $eavHelper = $this->createMock(EavFieldHelperService::class);

        // Test CreateEntryHandler sans projet
        $createHandler = new CreateEntryHandler($em, $projRepo, $colRepo, $eavHelper, $this->translator);
        $outCreate = $createHandler->execute([], new FlowContext(1, ''));
        $this->assertFalse($outCreate->data['created']);
        $this->assertSame('Le contexte de projet est requis.', $outCreate->data['error']);

        // Test UpdateEntryHandler avec projet introuvable
        $projRepo->method('findOneBy')->willReturn(null);
        $updateHandler = new UpdateEntryHandler($em, $entryRepo, $projRepo, $eavHelper, $this->translator);
        $outUpdate = $updateHandler->execute([], new FlowContext(1, '55555555-5555-5555-5555-555555555555'));
        $this->assertFalse($outUpdate->data['updated']);
        $this->assertSame('Projet introuvable.', $outUpdate->data['error']);

        // Test DeleteEntryHandler avec entrée introuvable dans le projet
        $project = new Project();
        $project->uuid = Uuid::fromString('55555555-5555-5555-5555-555555555555');
        $projRepo2 = $this->createMock(ProjectRepository::class);
        $projRepo2->method('findOneBy')->willReturn($project);
        $entryRepo->method('findOneBy')->willReturn(null);

        $deleteHandler = new DeleteEntryHandler($em, $entryRepo, $projRepo2, $this->translator);
        $ctxDel = new FlowContext(1, '55555555-5555-5555-5555-555555555555');
        $ctxDel->variables['_node_config'] = ['entry_uuid' => '99999999-9999-9999-9999-999999999999'];
        $outDelete = $deleteHandler->execute([], $ctxDel);
        $this->assertFalse($outDelete->data['deleted']);
        $this->assertStringContainsString('Entrée "99999999-9999-9999-9999-999999999999" introuvable dans le projet actuel.', $outDelete->data['error']);

        // Test PublishEntryHandler avec entrée introuvable dans le projet
        $publishHandler = new PublishEntryHandler($em, $entryRepo, $projRepo2, $this->translator);
        $outPublish = $publishHandler->execute([], $ctxDel);
        $this->assertFalse($outPublish->data['published']);
        $this->assertStringContainsString('Entrée "99999999-9999-9999-9999-999999999999" introuvable dans le projet actuel.', $outPublish->data['error']);
    }

    // ─── 5. Tests d'export/import d'automatisations et assainissement tenant ───

    public function testAutomationExportImportServiceSanitizesProjectUuidInFlowGraph(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $autoRepo = $this->createMock(AutomationRepository::class);
        $validator = new FlowValidator();
        $registry = $this->createMock(NodeRegistry::class);

        $handler = $this->createMock(FlowNodeHandler::class);
        $registry->method('resolve')->willReturn($handler);

        $service = new AutomationExportImportService($em, $autoRepo, $validator, $registry, $this->translator);

        $targetProject = new Project();
        $targetProject->uuid = Uuid::fromString('77777777-7777-7777-7777-777777777777');
        $targetProject->name = 'Destination Project';

        // Graphe importé avec un project_uuid provenant d'un projet tiers
        $flowGraphWithCrossTenantUuid = [
            'nodes' => [
                [
                    'id' => 't1',
                    'type' => 'trigger.content.created',
                    'data' => [
                        'config' => [
                            'project_uuid' => '66666666-6666-6666-6666-666666666666', // Tenant tiers !
                        ],
                    ],
                ],
                [
                    'id' => 'a1',
                    'type' => 'action.send_notification',
                    'data' => [
                        'config' => [
                            'title' => 'Test',
                        ],
                    ],
                ],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 't1', 'target' => 'a1'],
            ],
        ];

        $payload = [
            'schema_version' => '1.0',
            'automation' => [
                'name' => 'Cross Tenant Flow',
                'flow_graph' => $flowGraphWithCrossTenantUuid,
            ],
        ];

        $em->expects($this->once())->method('persist');
        $em->expects($this->once())->method('flush');

        $imported = $service->importAutomation($targetProject, $payload);

        // Vérification de l'assainissement multi-tenant
        $this->assertSame($targetProject, $imported->project);
        $sanitizedNodeConfig = $imported->flowGraph['nodes'][0]['data']['config'];
        $this->assertSame('77777777-7777-7777-7777-777777777777', $sanitizedNodeConfig['project_uuid']);
    }

    public function testAutomationExportImportServiceTranslatedExceptions(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $autoRepo = $this->createMock(AutomationRepository::class);
        $validator = new FlowValidator();
        $registry = $this->createMock(NodeRegistry::class);

        $service = new AutomationExportImportService($em, $autoRepo, $validator, $registry, $this->translator);
        $project = new Project();

        // 1. Version de schéma non supportée
        try {
            $service->importAutomation($project, ['schema_version' => '99.9']);
            $this->fail('Expected exception for unsupported schema version');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Version de schéma non supportée "99.9"', $e->getMessage());
        }

        // 2. Objet automation manquant
        try {
            $service->importAutomation($project, ['schema_version' => '1.0']);
            $this->fail('Expected exception for missing automation object');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Le payload ne contient pas l\'objet "automation".', $e->getMessage());
        }

        // 3. Somme de contrôle invalide
        try {
            $service->importAutomation($project, [
                'schema_version' => '1.0',
                'checksum' => 'sha256:badhash',
                'automation' => ['name' => 'Bad'],
            ]);
            $this->fail('Expected exception for checksum mismatch');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Somme de contrôle incorrecte', $e->getMessage());
        }

        // 4. Bundle trop grand
        $tooMany = array_fill(0, 51, ['name' => 'Auto']);
        try {
            $service->importProjectAutomations($project, [
                'schema_version' => '1.0',
                'automations' => $tooMany,
            ]);
            $this->fail('Expected exception for bundle too large');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Le bundle contient trop d\'automatisations', $e->getMessage());
        }
    }

    // ─── 6. Tests des commandes CLI (AutomationRunScheduledCommand & AutomationMigrateToFlowCommand) ───

    public function testAutomationRunScheduledCommandWithProjectFilter(): void
    {
        $autoRepo = $this->createMock(AutomationRepository::class);
        $engine = $this->createMock(AutomationEngine::class);
        $projRepo = $this->createMock(ProjectRepository::class);

        $project = new Project();
        $project->name = 'Mon Projet';
        $project->uuid = Uuid::fromString('88888888-8888-8888-8888-888888888888');

        $projRepo->method('findOneBy')->with(['uuid' => '88888888-8888-8888-8888-888888888888'])->willReturn($project);

        $autoRepo->expects($this->once())
            ->method('findActiveScheduled')
            ->with($project)
            ->willReturn([]);

        $command = new AutomationRunScheduledCommand($autoRepo, $engine, $projRepo, $this->translator);

        $input = new ArrayInput(['--project' => '88888888-8888-8888-8888-888888888888']);
        $output = new BufferedOutput();

        $statusCode = $command->run($input, $output);
        $this->assertSame(0, $statusCode);
        $this->assertStringContainsString('0 automatisation(s) exécutée(s)', $output->fetch());
    }

    public function testAutomationMigrateToFlowCommandOutputsTranslatedMessage(): void
    {
        $autoRepo = $this->createMock(AutomationRepository::class);
        $autoRepo->method('findAll')->willReturn([]);
        $em = $this->createMock(EntityManagerInterface::class);

        $command = new AutomationMigrateToFlowCommand($autoRepo, $em, $this->translator);

        $input = new ArrayInput([]);
        $output = new BufferedOutput();

        $code = $command->run($input, $output);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('0 automatisation(s) migrée(s) avec un flow par défaut.', $output->fetch());
    }

    // ─── 7. Vérification de l'intégrité i18n des dictionnaires (EN, FR, ES, AR) ───

    public function testI18nDictionariesContainAllCycle79Keys(): void
    {
        $en = require __DIR__ . '/../../translations/messages.en.php';
        $fr = require __DIR__ . '/../../translations/messages.fr.php';
        $es = require __DIR__ . '/../../translations/messages.es.php';
        $ar = require __DIR__ . '/../../translations/messages.ar.php';

        $expectedKeys = [
            'automation.export_import.project_required',
            'automation.export_import.unsupported_schema_version',
            'automation.export_import.missing_automation_object',
            'automation.export_import.checksum_mismatch',
            'automation.export_import.default_name',
            'automation.export_import.invalid_flow_graph_format',
            'automation.export_import.invalid_flow_graph',
            'automation.export_import.missing_automations_array',
            'automation.export_import.bundle_too_many_items',
            'automation.export_import.bundle_checksum_mismatch',
            'automation.error.run_mismatch',
            'automation.error.missing_project',
            'automation.error.tenant_mismatch',
            'flow.db.project_uuid_required',
            'flow.db.project_not_found',
            'flow.db.collection_not_found',
            'flow.action.project_uuid_required',
            'flow.action.project_not_found',
            'flow.action.collection_not_found',
            'flow.action.entry_not_found',
            'cli.automation.migrated_count',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $en, "Missing key '$key' in messages.en.php");
            $this->assertArrayHasKey($key, $fr, "Missing key '$key' in messages.fr.php");
            $this->assertArrayHasKey($key, $es, "Missing key '$key' in messages.es.php");
            $this->assertArrayHasKey($key, $ar, "Missing key '$key' in messages.ar.php");

            $this->assertNotEmpty($en[$key], "Empty translation for key '$key' in messages.en.php");
            $this->assertNotEmpty($fr[$key], "Empty translation for key '$key' in messages.fr.php");
            $this->assertNotEmpty($es[$key], "Empty translation for key '$key' in messages.es.php");
            $this->assertNotEmpty($ar[$key], "Empty translation for key '$key' in messages.ar.php");
        }
    }
}
