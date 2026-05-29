<?php
// tests/Service/Cloud/HostedAppServiceTest.php
namespace App\Tests\Service\Cloud;

use App\Entity\HostedApp;
use App\Entity\Project;
use App\Entity\WorkbenchProject;
use App\Service\Cloud\ContainerOrchestratorInterface;
use App\Service\Cloud\HostedAppService;
use App\Service\Cloud\TraefikLabelBuilder;
use App\Workbench\Templates\NextjsTemplate;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;

class HostedAppServiceTest extends TestCase
{
    public function testDeployBuildsAndRunsContainer(): void
    {
        $orchestrator = $this->createMock(ContainerOrchestratorInterface::class);
        $orchestrator->expects($this->once())->method('buildImage')->willReturn('jambo/app:tag');
        $orchestrator->expects($this->once())->method('runContainer')->willReturn('container-123');

        $service = $this->makeService($orchestrator);
        $hosted  = $service->deploy($this->makeWorkbench());

        $this->assertSame(HostedApp::STATUS_RUNNING, $hosted->status);
        $this->assertSame('container-123', $hosted->containerId);
        $this->assertStringContainsString('blog-', $hosted->subdomain);
        $this->assertSame('jambo/app:tag', $hosted->imageRef);
    }

    public function testDeployMarksFailedOnBuildError(): void
    {
        $orchestrator = $this->createMock(ContainerOrchestratorInterface::class);
        $orchestrator->method('buildImage')->willThrowException(new \RuntimeException('boom'));

        $service = $this->makeService($orchestrator);
        $hosted  = $service->deploy($this->makeWorkbench());

        $this->assertSame(HostedApp::STATUS_FAILED, $hosted->status);
        $this->assertStringContainsString('boom', (string) $hosted->lastError);
    }

    public function testSubdomainIsSlugifiedWithSuffix(): void
    {
        $service = $this->makeService($this->createMock(ContainerOrchestratorInterface::class));
        $sub = $service->generateSubdomain($this->makeWorkbench());

        // "My Blog!" -> "my-blog-XXXXXX"
        $this->assertMatchesRegularExpression('/^my-blog-[0-9a-f]{6}$/', $sub);
    }

    public function testPrepareMarksProvisioningWithoutBuilding(): void
    {
        $orchestrator = $this->createMock(ContainerOrchestratorInterface::class);
        $orchestrator->expects($this->never())->method('buildImage');
        $orchestrator->expects($this->never())->method('runContainer');

        $service = $this->makeService($orchestrator);
        $hosted  = $service->prepare($this->makeWorkbench());

        $this->assertSame(HostedApp::STATUS_PROVISIONING, $hosted->status);
        $this->assertStringStartsWith('my-blog-', $hosted->subdomain);
        $this->assertNull($hosted->containerId);
    }

    private function makeService(ContainerOrchestratorInterface $orchestrator): HostedAppService
    {
        return new HostedAppService(
            $orchestrator,
            new TraefikLabelBuilder('jambo.app', 'letsencrypt'),
            [new NextjsTemplate()],
            'https://cms.example.com',
            'jambo.app',
            null, // HostedAppRepository (not needed for these unit paths)
            null, // EntityManager
        );
    }

    private function makeWorkbench(): WorkbenchProject
    {
        $project = new Project();
        $project->name = 'Demo';

        $w = new WorkbenchProject();
        $w->project   = $project;
        $w->name      = 'My Blog!';
        $w->framework = 'nextjs';
        $w->files     = ['app/page.tsx' => 'export default () => <h1>hi</h1>;'];
        return $w;
    }
}
