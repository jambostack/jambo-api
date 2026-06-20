<?php

namespace App\Tests\Service\Flow;

use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowNodeHandler;
use App\Service\Flow\NodeOutput;
use App\Service\Flow\NodeRegistry;
use PHPUnit\Framework\TestCase;

class NodeRegistryTest extends TestCase
{
    private NodeRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new NodeRegistry();
    }

    private function mockHandler(string $category, string $type): FlowNodeHandler
    {
        $className = 'MockHandler_' . str_replace(['.', '-'], '_', uniqid('', true));

        $cat = var_export($category, true);
        $typ = var_export($type, true);
        $fullType = var_export($category . '.' . $type, true);
        $label = var_export(ucfirst($type), true);
        $desc = var_export('Description for ' . $type, true);

        $code = '<?php' . "\n"
            . 'namespace App\\Tests\\Service\\Flow;' . "\n\n"
            . 'class ' . $className . ' implements \\App\\Service\\Flow\\FlowNodeHandler' . "\n"
            . '{' . "\n"
            . '    public static function getCategory(): string { return ' . $cat . '; }' . "\n"
            . '    public static function getType(): string { return ' . $typ . '; }' . "\n"
            . '    public static function getFullType(): string { return ' . $fullType . '; }' . "\n"
            . '    public static function getLabel(): string { return ' . $label . '; }' . "\n"
            . '    public static function getDescription(): string { return ' . $desc . '; }' . "\n"
            . '    public static function getIcon(): string { return \'activity\'; }' . "\n"
            . '    public static function getConfigSchema(): array { return []; }' . "\n"
            . '    public static function getOutputPorts(): array { return [\'default\']; }' . "\n"
            . '    public function execute(array $input, \\App\\Service\\Flow\\FlowContext $ctx): \\App\\Service\\Flow\\NodeOutput' . "\n"
            . '    {' . "\n"
            . '        throw new \\RuntimeException(\'Not implemented in tests\');' . "\n"
            . '    }' . "\n"
            . '}' . "\n";

        $tmpFile = sys_get_temp_dir() . '/' . $className . '.php';
        file_put_contents($tmpFile, $code);
        require $tmpFile;
        $fqcn = 'App\\Tests\\Service\\Flow\\' . $className;
        unlink($tmpFile);

        return new $fqcn();
    }

    // -- Enregistrement et resolution ---------------------------------

    public function testAddAndResolveHandler(): void
    {
        $handler = $this->mockHandler('action', 'send_email');
        $this->registry->addHandler($handler);

        $resolved = $this->registry->resolve('action.send_email');
        $this->assertSame($handler, $resolved);
    }

    public function testResolveReturnsNullForUnknownType(): void
    {
        $this->assertNull($this->registry->resolve('unknown.type'));
    }

    public function testAllReturnsAllHandlers(): void
    {
        $handler1 = $this->mockHandler('trigger', 'content_created');
        $handler2 = $this->mockHandler('action', 'send_email');

        $this->registry->addHandler($handler1);
        $this->registry->addHandler($handler2);

        $all = $this->registry->all();
        $this->assertCount(2, $all);
    }

    // -- Catalogue ----------------------------------------------------

    public function testGetCatalogGroupsByCategory(): void
    {
        $handler1 = $this->mockHandler('action', 'send_email');
        $handler2 = $this->mockHandler('trigger', 'content_created');

        $this->registry->addHandler($handler1);
        $this->registry->addHandler($handler2);

        $catalog = $this->registry->getCatalog();
        $this->assertCount(2, $catalog);

        $categories = array_column($catalog, 'key');
        $this->assertContains('action', $categories);
        $this->assertContains('trigger', $categories);
    }

    public function testGetCatalogIncludesNodeDetails(): void
    {
        $handler = $this->mockHandler('action', 'send_email');
        $this->registry->addHandler($handler);

        $catalog = $this->registry->getCatalog();
        $actionCategory = $catalog[0];
        $node = $actionCategory['nodes'][0];

        $this->assertSame('action.send_email', $node['type']);
        $this->assertSame('Send_email', $node['label']);
        $this->assertArrayHasKey('configSchema', $node);
        $this->assertArrayHasKey('outputPorts', $node);
    }
}
