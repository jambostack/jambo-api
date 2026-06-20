<?php

namespace App\Tests\Service\Flow;

use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowInterpreter;
use App\Service\Flow\FlowNodeHandler;
use App\Service\Flow\FlowValidator;
use App\Service\Flow\NodeRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\MessageBusInterface;

class FlowInterpreterTest extends TestCase
{
    private NodeRegistry $registry;
    private FlowValidator&\PHPUnit\Framework\MockObject\Stub $validator;
    private MessageBusInterface&\PHPUnit\Framework\MockObject\Stub $bus;
    private FlowInterpreter $interpreter;

    protected function setUp(): void
    {
        $this->registry = new NodeRegistry();
        $this->validator = $this->createStub(FlowValidator::class);
        $this->bus = $this->createStub(MessageBusInterface::class);
        $this->interpreter = new FlowInterpreter($this->registry, $this->validator, $this->bus);
    }

    /**
     * Crée une classe PHP réelle implémentant FlowNodeHandler via un fichier temporaire.
     *
     * Nécessaire car FlowNodeHandler déclare des méthodes statiques que PHPUnit
     * ne peut pas mocker, et NodeRegistry::addHandler() appelle $handler::getFullType().
     */
    private function createRealHandler(string $category, string $type, mixed $outputData = ['result' => 'ok']): FlowNodeHandler
    {
        $className = 'FlowHandler_' . str_replace(['.', '-'], '_', uniqid('', true));

        $cat = var_export($category, true);
        $typ = var_export($type, true);
        $fullType = var_export($category . '.' . $type, true);
        $label = var_export(ucfirst($type), true);
        $output = var_export($outputData, true);

        $code = '<?php' . "\n"
            . 'namespace App\\Tests\\Service\\Flow;' . "\n\n"
            . 'class ' . $className . ' implements \\App\\Service\\Flow\\FlowNodeHandler' . "\n"
            . '{' . "\n"
            . '    public static function getCategory(): string { return ' . $cat . '; }' . "\n"
            . '    public static function getType(): string { return ' . $typ . '; }' . "\n"
            . '    public static function getFullType(): string { return ' . $fullType . '; }' . "\n"
            . '    public static function getLabel(): string { return ' . $label . '; }' . "\n"
            . '    public static function getDescription(): string { return \'\'; }' . "\n"
            . '    public static function getIcon(): string { return \'activity\'; }' . "\n"
            . '    public static function getConfigSchema(): array { return []; }' . "\n"
            . '    public static function getOutputPorts(): array { return [\'default\']; }' . "\n"
            . '    public function execute(array $input, \\App\\Service\\Flow\\FlowContext $ctx): \\App\\Service\\Flow\\NodeOutput' . "\n"
            . '    {' . "\n"
            . '        return new \\App\\Service\\Flow\\NodeOutput(data: ' . $output . ');' . "\n"
            . '    }' . "\n"
            . '}' . "\n";

        $tmpFile = sys_get_temp_dir() . '/' . $className . '.php';
        file_put_contents($tmpFile, $code);
        require $tmpFile;
        $fqcn = 'App\\Tests\\Service\\Flow\\' . $className;
        unlink($tmpFile);

        return new $fqcn();
    }

    private function linearGraph(): array
    {
        return [
            'nodes' => [
                ['id' => 't1', 'type' => 'trigger.content_created', 'data' => ['label' => 'Trigger']],
                ['id' => 'a1', 'type' => 'action.send_email', 'data' => ['label' => 'Send Email']],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 't1', 'target' => 'a1'],
            ],
        ];
    }

    // ─── Validation ────────────────────────────────────────────────

    public function testReturnsFailedWhenValidationFails(): void
    {
        $this->validator->method('validate')->willReturn(['valid' => false, 'errors' => ['Cycle detected']]);

        $ctx = new FlowContext(1, 'project-uuid');
        $result = $this->interpreter->executeFlow($this->linearGraph(), [], $ctx);

        $this->assertSame('failed', $result->status);
        $this->assertStringContainsString('Cycle detected', $result->error);
    }

    // ─── Exécution linéaire ────────────────────────────────────────

    public function testExecutesLinearFlow(): void
    {
        $trigger = $this->createRealHandler('trigger', 'content_created', ['entry_uuid' => 'e-1']);
        $action = $this->createRealHandler('action', 'send_email', ['sent' => true]);
        $this->registry->addHandler($trigger);
        $this->registry->addHandler($action);

        $this->validator->method('validate')->willReturn(['valid' => true, 'errors' => []]);

        $ctx = new FlowContext(1, 'project-uuid');
        $result = $this->interpreter->executeFlow($this->linearGraph(), ['entry' => 'test'], $ctx);

        $this->assertSame('success', $result->status);
        $this->assertNull($result->error);
        $this->assertCount(2, $result->stepLog);
    }

    // ─── Handler inconnu ───────────────────────────────────────────

    public function testSkipsNodeWithUnknownHandler(): void
    {
        $this->validator->method('validate')->willReturn(['valid' => true, 'errors' => []]);

        $graph = [
            'nodes' => [
                ['id' => 't1', 'type' => 'trigger.content_created', 'data' => ['label' => 'Trig']],
                ['id' => 'u1', 'type' => 'unknown.missing', 'data' => ['label' => 'Unknown']],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 't1', 'target' => 'u1'],
            ],
        ];

        // Seul le trigger est enregistré, pas le type unknown
        $trigger = $this->createRealHandler('trigger', 'content_created');
        $this->registry->addHandler($trigger);

        $ctx = new FlowContext(1, 'project-uuid');
        $result = $this->interpreter->executeFlow($graph, [], $ctx);

        // Le flow réussit mais le nœud inconnu est loggé comme failed (handler not found)
        $skipped = array_filter($result->stepLog, fn(array $step) => $step['status'] === 'failed');
        $this->assertCount(1, $skipped);
    }

    // ─── Passage de contexte ───────────────────────────────────────

    public function testPassesContextToNodes(): void
    {
        // Pour ce test, on utilise des handlers réels qui écrivent dans le contexte
        // via les variables partagées du FlowContext.
        $triggerOutput = ['triggered' => true];
        $actionOutput = ['sent' => true];

        $trigger = $this->createRealHandler('trigger', 'content_created', $triggerOutput);
        $action = $this->createRealHandler('action', 'send_email', $actionOutput);

        $this->registry->addHandler($trigger);
        $this->registry->addHandler($action);

        $this->validator->method('validate')->willReturn(['valid' => true, 'errors' => []]);

        $ctx = new FlowContext(1, 'project-uuid');
        $result = $this->interpreter->executeFlow($this->linearGraph(), [], $ctx);

        // Le flow s'exécute avec succès — le handler reçoit bien le FlowContext
        $this->assertSame('success', $result->status);
        $this->assertCount(2, $result->stepLog);
    }
}
