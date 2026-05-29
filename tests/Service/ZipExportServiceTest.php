<?php
// tests/Service/ZipExportServiceTest.php
namespace App\Tests\Service;

use App\Entity\Project;
use App\Entity\WorkbenchProject;
use App\Service\ZipExportService;
use App\Workbench\Templates\NextjsTemplate;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;

class ZipExportServiceTest extends TestCase
{
    private ZipExportService $service;

    protected function setUp(): void
    {
        $this->service = new ZipExportService([new NextjsTemplate()]);
    }

    public function testExportReturnsZipBytes(): void
    {
        $workbench = $this->makeWorkbench();
        $zip = $this->service->export($workbench);

        $this->assertNotEmpty($zip);
        // ZIP magic bytes: PK\x03\x04
        $this->assertEquals('PK', substr($zip, 0, 2));
    }

    public function testExportContainsDockerfile(): void
    {
        $workbench = $this->makeWorkbench();
        $tmpFile   = tempnam(sys_get_temp_dir(), 'test_') . '.zip';
        file_put_contents($tmpFile, $this->service->export($workbench));

        $zip = new \ZipArchive();
        $zip->open($tmpFile);
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[] = $zip->getNameIndex($i);
        }
        $zip->close();
        unlink($tmpFile);

        $this->assertContains('Dockerfile', $entries);
        $this->assertContains('docker-compose.yml', $entries);
        $this->assertContains('.env.example', $entries);
        $this->assertContains('.github/workflows/deploy.yml', $entries);
    }

    public function testExportContainsGeneratedFiles(): void
    {
        $workbench = $this->makeWorkbench();
        $tmpFile   = tempnam(sys_get_temp_dir(), 'test_') . '.zip';
        file_put_contents($tmpFile, $this->service->export($workbench));

        $zip = new \ZipArchive();
        $zip->open($tmpFile);
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[] = $zip->getNameIndex($i);
        }
        $zip->close();
        unlink($tmpFile);

        $this->assertContains('app/page.tsx', $entries);
    }

    private function makeWorkbench(): WorkbenchProject
    {
        $project = new Project();
        $project->name          = 'Test Blog';
        $project->defaultLocale = 'en';
        $project->collections   = new ArrayCollection();

        $w = new WorkbenchProject();
        $w->project   = $project;
        $w->name      = 'My Blog';
        $w->framework = 'nextjs';
        $w->files     = ['app/page.tsx' => 'export default function Home() { return <h1>Hello</h1>; }'];

        return $w;
    }
}
