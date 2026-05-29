<?php
// src/Service/Cloud/HostedAppService.php
namespace App\Service\Cloud;

use App\Entity\CustomDomain;
use App\Entity\HostedApp;
use App\Entity\WorkbenchProject;
use App\Repository\HostedAppRepository;
use App\Workbench\Templates\BaseTemplate;
use Doctrine\ORM\EntityManagerInterface;

class HostedAppService
{
    /** @param BaseTemplate[] $templates */
    public function __construct(
        private readonly ContainerOrchestratorInterface $orchestrator,
        private readonly TraefikLabelBuilder $labelBuilder,
        private readonly iterable $templates,
        private readonly string $jamboApiUrl,
        private readonly string $baseDomain,
        private readonly ?HostedAppRepository $hostedAppRepository = null,
        private readonly ?EntityManagerInterface $em = null,
    ) {}

    /**
     * Find-or-create the HostedApp for a workbench and mark it provisioning,
     * WITHOUT building. Returned immediately to the controller so the actual
     * build+run can run asynchronously (see deploy(), called by the worker).
     */
    public function prepare(WorkbenchProject $project): HostedApp
    {
        $hosted = $this->hostedAppRepository?->findByWorkbench($project) ?? new HostedApp();
        if ($hosted->id === null) {
            $hosted->workbenchProject = $project;
            $hosted->subdomain        = $this->generateSubdomain($project);
        }
        $hosted->status    = HostedApp::STATUS_PROVISIONING;
        $hosted->lastError = null;
        $this->persist($hosted);

        return $hosted;
    }

    /**
     * Build the image, run the container, and persist a HostedApp.
     * Reuses the existing HostedApp for the workbench if present (redeploy).
     */
    public function deploy(WorkbenchProject $project): HostedApp
    {
        $hosted = $this->hostedAppRepository?->findByWorkbench($project) ?? new HostedApp();
        if ($hosted->id === null) {
            $hosted->workbenchProject = $project;
            $hosted->subdomain        = $this->generateSubdomain($project);
        }
        $hosted->status   = HostedApp::STATUS_PROVISIONING;
        $hosted->lastError = null;
        $this->persist($hosted);

        try {
            $template = $this->findTemplate($project->framework);
            if ($template === null) {
                throw new \RuntimeException("Unknown framework: {$project->framework}");
            }

            // The serving port depends on the framework (e.g. Astro/nginx = 80, Node = 3000).
            $hosted->internalPort = $template->getInternalPort();

            $tag        = 'jambo/' . $hosted->subdomain . ':' . substr($project->uuid->toRfc4122(), 0, 8);
            $imageRef   = $this->orchestrator->buildImage($tag, $project->files, $template->getDockerfile());

            $domains = [];
            foreach ($hosted->customDomains as $cd) {
                /** @var CustomDomain $cd */
                if ($cd->verified) {
                    $domains[] = $cd->domain;
                }
            }

            $labels       = $this->labelBuilder->build($hosted->subdomain, $hosted->internalPort, $domains);
            $projectUuid  = $project->project->uuid?->toRfc4122() ?? '';
            $env          = [
                'JAMBO_API_URL'                  => $this->jamboApiUrl,
                'JAMBO_PROJECT_UUID'             => $projectUuid,
                'NEXT_PUBLIC_JAMBO_API_URL'      => $this->jamboApiUrl,
                'NEXT_PUBLIC_JAMBO_PROJECT_UUID' => $projectUuid,
                'PORT'                           => (string) $hosted->internalPort,
            ];

            if ($hosted->containerId !== null) {
                $this->orchestrator->removeContainer($hosted->containerId);
            }

            $containerId = $this->orchestrator->runContainer(
                $imageRef,
                $this->labelBuilder->routerName($hosted->subdomain),
                $labels,
                $env,
            );

            $hosted->imageRef    = $imageRef;
            $hosted->containerId = $containerId;
            $hosted->status      = HostedApp::STATUS_RUNNING;
        } catch (\Throwable $e) {
            $hosted->status    = HostedApp::STATUS_FAILED;
            $hosted->lastError = $e->getMessage();
        }

        $this->persist($hosted);
        return $hosted;
    }

    public function stop(HostedApp $hosted): void
    {
        if ($hosted->containerId !== null) {
            $this->orchestrator->stopContainer($hosted->containerId);
        }
        $hosted->status = HostedApp::STATUS_STOPPED;
        $this->persist($hosted);
    }

    public function destroy(HostedApp $hosted): void
    {
        if ($hosted->containerId !== null) {
            $this->orchestrator->removeContainer($hosted->containerId);
        }
        if ($this->em !== null) {
            $this->em->remove($hosted);
            $this->em->flush();
        }
    }

    public function publicUrl(HostedApp $hosted): string
    {
        return "https://{$hosted->subdomain}.{$this->baseDomain}";
    }

    public function generateSubdomain(WorkbenchProject $project): string
    {
        $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $project->name));
        $slug = trim($slug, '-') ?: 'app';
        return $slug . '-' . bin2hex(random_bytes(3));
    }

    private function findTemplate(string $framework): ?BaseTemplate
    {
        foreach ($this->templates as $t) {
            if ($t->getId() === $framework) return $t;
        }
        return null;
    }

    private function persist(HostedApp $hosted): void
    {
        $this->em?->persist($hosted);
        $this->em?->flush();
    }
}
