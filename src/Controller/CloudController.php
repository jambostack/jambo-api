<?php
// src/Controller/CloudController.php
namespace App\Controller;

use App\Entity\Project;
use App\Entity\WorkbenchProject;
use App\Repository\HostedAppRepository;
use App\Repository\WorkbenchProjectRepository;
use App\Service\Cloud\HostedAppService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
class CloudController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WorkbenchProjectRepository $workbenchRepository,
        private readonly HostedAppRepository $hostedAppRepository,
        private readonly HostedAppService $hostedAppService,
        private readonly bool $cloudEnabled,
    ) {}

    #[Route('/api/projects/{uuid}/workbench/{workbenchUuid}/cloud/deploy', name: 'cloud_deploy', methods: ['POST'])]
    public function deploy(string $uuid, string $workbenchUuid): JsonResponse
    {
        if (!$this->cloudEnabled) {
            return new JsonResponse(['error' => 'Jambo Cloud n\'est pas activé sur cette instance.'], 503);
        }

        $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        if (!$project) return new JsonResponse(['error' => 'Projet introuvable'], 404);
        $this->denyAccessUnlessGranted('project.manage', $project);

        $workbench = $this->workbenchRepository->findOneBy(['uuid' => $workbenchUuid, 'project' => $project]);
        if (!$workbench) return new JsonResponse(['error' => 'WorkbenchProject introuvable'], 404);
        if (empty($workbench->files)) {
            return new JsonResponse(['error' => 'Aucun fichier à déployer. Génère ton app d\'abord.'], 422);
        }

        $hosted = $this->hostedAppService->deploy($workbench);

        return new JsonResponse([
            'status'  => $hosted->status,
            'url'     => $this->hostedAppService->publicUrl($hosted),
            'error'   => $hosted->lastError,
            'app_uuid'=> $hosted->uuid->toRfc4122(),
        ], $hosted->status === \App\Entity\HostedApp::STATUS_FAILED ? 502 : 200);
    }

    #[Route('/api/projects/{uuid}/workbench/{workbenchUuid}/cloud/status', name: 'cloud_status', methods: ['GET'])]
    public function status(string $uuid, string $workbenchUuid): JsonResponse
    {
        $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        if (!$project) return new JsonResponse(['error' => 'Projet introuvable'], 404);
        $this->denyAccessUnlessGranted('project.view', $project);

        $workbench = $this->workbenchRepository->findOneBy(['uuid' => $workbenchUuid, 'project' => $project]);
        if (!$workbench) return new JsonResponse(['error' => 'WorkbenchProject introuvable'], 404);

        $hosted = $this->hostedAppRepository->findByWorkbench($workbench);
        if ($hosted === null) {
            return new JsonResponse(['hosted' => null]);
        }

        return new JsonResponse(['hosted' => [
            'app_uuid'  => $hosted->uuid->toRfc4122(),
            'status'    => $hosted->status,
            'url'       => $this->hostedAppService->publicUrl($hosted),
            'subdomain' => $hosted->subdomain,
            'domains'   => array_map(fn ($d) => [
                'domain'    => $d->domain,
                'verified'  => $d->verified,
                'sslStatus' => $d->sslStatus,
            ], $hosted->customDomains->toArray()),
        ]]);
    }

    #[Route('/api/projects/{uuid}/workbench/{workbenchUuid}/cloud/stop', name: 'cloud_stop', methods: ['POST'])]
    public function stop(string $uuid, string $workbenchUuid): JsonResponse
    {
        $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        if (!$project) return new JsonResponse(['error' => 'Projet introuvable'], 404);
        $this->denyAccessUnlessGranted('project.manage', $project);

        $workbench = $this->workbenchRepository->findOneBy(['uuid' => $workbenchUuid, 'project' => $project]);
        if (!$workbench) return new JsonResponse(['error' => 'WorkbenchProject introuvable'], 404);

        $hosted = $this->hostedAppRepository->findByWorkbench($workbench);
        if ($hosted === null) return new JsonResponse(['error' => 'App non déployée'], 404);

        $this->hostedAppService->stop($hosted);
        return new JsonResponse(['status' => $hosted->status]);
    }
}
