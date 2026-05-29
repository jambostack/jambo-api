<?php
// src/Controller/CustomDomainController.php
namespace App\Controller;

use App\Entity\Project;
use App\Repository\CustomDomainRepository;
use App\Repository\HostedAppRepository;
use App\Repository\WorkbenchProjectRepository;
use App\Service\Cloud\CustomDomainService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
class CustomDomainController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WorkbenchProjectRepository $workbenchRepository,
        private readonly HostedAppRepository $hostedAppRepository,
        private readonly CustomDomainRepository $customDomainRepository,
        private readonly CustomDomainService $customDomainService,
    ) {}

    #[Route('/api/projects/{uuid}/workbench/{workbenchUuid}/cloud/domains', name: 'cloud_domain_add', methods: ['POST'])]
    public function add(string $uuid, string $workbenchUuid, Request $request): JsonResponse
    {
        $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        if (!$project) return new JsonResponse(['error' => 'Projet introuvable'], 404);
        $this->denyAccessUnlessGranted('project.manage', $project);

        $workbench = $this->workbenchRepository->findOneBy(['uuid' => $workbenchUuid, 'project' => $project]);
        if (!$workbench) return new JsonResponse(['error' => 'WorkbenchProject introuvable'], 404);

        $hosted = $this->hostedAppRepository->findByWorkbench($workbench);
        if ($hosted === null) return new JsonResponse(['error' => 'Déploie l\'app sur Jambo Cloud d\'abord'], 422);

        $domain = strtolower(trim((string) ($request->toArray()['domain'] ?? '')));
        if ($domain === '' || !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain)) {
            return new JsonResponse(['error' => 'Domaine invalide'], 422);
        }
        if ($this->customDomainRepository->findByDomain($domain) !== null) {
            return new JsonResponse(['error' => 'Domaine déjà utilisé'], 409);
        }

        $cd = $this->customDomainService->addDomain($hosted, $domain);

        return new JsonResponse([
            'domain'        => $cd->domain,
            'record_name'   => $this->customDomainService->challengeRecordName($cd),
            'record_value'  => $this->customDomainService->challengeRecordValue($cd),
            'verified'      => $cd->verified,
        ], 201);
    }

    #[Route('/api/projects/{uuid}/workbench/cloud/domains/{domainUuid}/verify', name: 'cloud_domain_verify', methods: ['POST'])]
    public function verify(string $uuid, string $domainUuid): JsonResponse
    {
        $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        if (!$project) return new JsonResponse(['error' => 'Projet introuvable'], 404);
        $this->denyAccessUnlessGranted('project.manage', $project);

        $cd = $this->customDomainRepository->findOneBy(['uuid' => $domainUuid]);
        if ($cd === null || $cd->hostedApp->workbenchProject->project->id !== $project->id) {
            return new JsonResponse(['error' => 'Domaine introuvable'], 404);
        }

        $ok = $this->customDomainService->verify($cd);

        return new JsonResponse([
            'verified'  => $ok,
            'sslStatus' => $cd->sslStatus,
        ], $ok ? 200 : 422);
    }

    #[Route('/api/projects/{uuid}/workbench/cloud/domains/{domainUuid}', name: 'cloud_domain_delete', methods: ['DELETE'])]
    public function delete(string $uuid, string $domainUuid): JsonResponse
    {
        $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        if (!$project) return new JsonResponse(['error' => 'Projet introuvable'], 404);
        $this->denyAccessUnlessGranted('project.manage', $project);

        $cd = $this->customDomainRepository->findOneBy(['uuid' => $domainUuid]);
        if ($cd === null || $cd->hostedApp->workbenchProject->project->id !== $project->id) {
            return new JsonResponse(['error' => 'Domaine introuvable'], 404);
        }

        $this->em->remove($cd);
        $this->em->flush();
        return new JsonResponse(['deleted' => $domainUuid]);
    }
}
