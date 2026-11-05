<?php

namespace App\Controller;

use App\Entity\Project;
use App\Entity\User;
use App\Repository\ProjectRepository;
use App\Repository\ProjectTemplateRepository;
use App\Service\AuditService;
use App\Service\ProjectTemplateBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/project-templates', name: 'api_project_template_')]
class ProjectTemplateController extends AbstractController
{
    public const MAX_TEMPLATES_COUNT = 100;
    public const MAX_PAYLOAD_BYTES = 524288; // 512 KB
    public const MAX_NAME_LENGTH = 255;
    public const MAX_DESCRIPTION_LENGTH = 2000;

    private const SECURITY_HEADERS = [
        'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        'Pragma' => 'no-cache',
        'X-Content-Type-Options' => 'nosniff',
    ];

    public function __construct(
        private readonly ProjectTemplateRepository $templateRepository,
        private readonly ProjectTemplateBuilder $builder,
        private readonly EntityManagerInterface $em,
        private readonly ?TranslatorInterface $translator = null,
        #[Autowire(service: 'limiter.template_limiter')]
        private readonly ?RateLimiterFactory $templateLimiter = null,
        private readonly ?AuditService $auditService = null,
    ) {}

    private function trans(string $id, array $parameters = [], ?string $default = null): string
    {
        if ($this->translator !== null) {
            return $this->translator->trans($id, $parameters);
        }
        if ($default !== null) {
            return strtr($default, $parameters);
        }
        return $id;
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(?Request $request = null): JsonResponse
    {
        if ($this->templateLimiter !== null && $request !== null) {
            $identifier = $request->getClientIp() ?: '127.0.0.1';
            try {
                $user = $this->getUser();
                if ($user !== null) {
                    $identifier = $user->getUserIdentifier();
                }
            } catch (\Throwable) {}

            if (false === $this->templateLimiter->create($identifier)->consume(1)->isAccepted()) {
                return new JsonResponse([
                    'error' => $this->trans('template.rate_limit_exceeded', [], 'Too many template requests. Please retry later.'),
                    'code'  => 'RATE_LIMIT_EXCEEDED',
                ], 429, self::SECURITY_HEADERS);
            }
        }

        $templates = $this->templateRepository->findBy([], ['createdAt' => 'DESC']);

        return new JsonResponse([
            'data' => array_map(fn ($t) => [
                'id'          => $t->id,
                'name'        => $t->name,
                'description' => $t->description,
                'createdAt'   => $t->createdAt->format(\DateTimeInterface::ATOM),
            ], $templates),
        ], 200, self::SECURITY_HEADERS);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id, ?Request $request = null): JsonResponse
    {
        if ($this->templateLimiter !== null && $request !== null) {
            $identifier = $request->getClientIp() ?: '127.0.0.1';
            try {
                $user = $this->getUser();
                if ($user !== null) {
                    $identifier = $user->getUserIdentifier();
                }
            } catch (\Throwable) {}

            if (false === $this->templateLimiter->create($identifier)->consume(1)->isAccepted()) {
                return new JsonResponse([
                    'error' => $this->trans('template.rate_limit_exceeded', [], 'Too many template requests. Please retry later.'),
                    'code'  => 'RATE_LIMIT_EXCEEDED',
                ], 429, self::SECURITY_HEADERS);
            }
        }

        if ($id <= 0) {
            return new JsonResponse(['error' => $this->trans('template.not_found', [], 'Template not found')], 404, self::SECURITY_HEADERS);
        }

        $template = $this->templateRepository->find($id);
        if ($template === null) {
            return new JsonResponse(['error' => $this->trans('template.not_found', [], 'Template not found')], 404, self::SECURITY_HEADERS);
        }

        return new JsonResponse([
            'id'          => $template->id,
            'name'        => $template->name,
            'description' => $template->description,
            'structure'   => $template->structure,
            'createdAt'   => $template->createdAt->format(\DateTimeInterface::ATOM),
        ], 200, self::SECURITY_HEADERS);
    }

    #[Route('/from-project/{projectUuid}', name: 'save_from_project', methods: ['POST'])]
    public function saveFromProject(string $projectUuid, Request $request): JsonResponse
    {
        if ($this->templateLimiter !== null) {
            $identifier = $request->getClientIp() ?: '127.0.0.1';
            try {
                $user = $this->getUser();
                if ($user !== null) {
                    $identifier = $user->getUserIdentifier();
                }
            } catch (\Throwable) {}

            if (false === $this->templateLimiter->create($identifier)->consume(1)->isAccepted()) {
                return new JsonResponse([
                    'error' => $this->trans('template.rate_limit_exceeded', [], 'Too many template requests. Please retry later.'),
                    'code'  => 'RATE_LIMIT_EXCEEDED',
                ], 429, self::SECURITY_HEADERS);
            }
        }

        try {
            $user = $this->getUser();
        } catch (\Throwable) {
            $user = null;
        }

        if (!$user && $request->attributes->get('_project') === null) {
            return new JsonResponse(['error' => $this->trans('template.auth_required', [], 'Authentication required')], 401, self::SECURITY_HEADERS);
        }

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return new JsonResponse([
                'error' => $this->trans('template.payload_too_large', [], 'Payload too large (max 512KB).'),
                'code'  => 'PAYLOAD_TOO_LARGE',
            ], 413, self::SECURITY_HEADERS);
        }

        if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $projectUuid)) {
            return new JsonResponse(['error' => $this->trans('template.project_not_found', [], 'Project not found')], 404, self::SECURITY_HEADERS);
        }

        try {
            $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $projectUuid]);
        } catch (\Throwable) {
            return new JsonResponse(['error' => $this->trans('template.project_not_found', [], 'Project not found')], 404, self::SECURITY_HEADERS);
        }
        if ($project === null) {
            return new JsonResponse(['error' => $this->trans('template.project_not_found', [], 'Project not found')], 404, self::SECURITY_HEADERS);
        }

        if ($request->attributes->get('_project') !== $project) {
            $this->denyAccessUnlessGranted('project.view', $project);
        }

        $templateCount = $this->templateRepository->count([]);
        if ($templateCount >= self::MAX_TEMPLATES_COUNT) {
            return new JsonResponse([
                'error' => $this->trans('template.max_templates_exceeded', ['%max%' => self::MAX_TEMPLATES_COUNT], sprintf('Maximum number of templates reached (%d).', self::MAX_TEMPLATES_COUNT)),
                'code' => 'MAX_TEMPLATES_EXCEEDED',
            ], 422, self::SECURITY_HEADERS);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return new JsonResponse(['error' => $this->trans('template.invalid_json', [], 'Invalid JSON')], 400, self::SECURITY_HEADERS);
        }

        $rawName = isset($data['name']) && is_scalar($data['name']) ? trim((string) $data['name']) : null;
        $name = $rawName !== null && $rawName !== '' ? mb_substr(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $rawName), 0, self::MAX_NAME_LENGTH) : null;

        $rawDesc = isset($data['description']) && is_scalar($data['description']) ? trim((string) $data['description']) : null;
        $desc = $rawDesc !== null && $rawDesc !== '' ? mb_substr(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $rawDesc), 0, self::MAX_DESCRIPTION_LENGTH) : null;

        $template = $this->builder->exportFromProject($projectUuid, $name, $desc);

        if ($template === null) {
            return new JsonResponse(['error' => $this->trans('template.project_not_found', [], 'Project not found')], 404, self::SECURITY_HEADERS);
        }

        $this->auditService?->log(
            toolName: 'project_template_export',
            project: $project,
            input: ['project_uuid' => $projectUuid, 'name' => $name],
            output: ['success' => true, 'template_id' => $template->id],
            status: 'success',
            createdBy: $user?->getUserIdentifier() ?? 'system',
            source: 'project_template'
        );
        $this->auditService?->flush();

        return new JsonResponse([
            'id'        => $template->id,
            'name'      => $template->name,
            'createdAt' => $template->createdAt->format(\DateTimeInterface::ATOM),
        ], 201, self::SECURITY_HEADERS);
    }

    #[Route('/{id}/apply', name: 'apply', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function apply(int $id, Request $request): JsonResponse
    {
        if ($this->templateLimiter !== null) {
            $identifier = $request->getClientIp() ?: '127.0.0.1';
            try {
                $user = $this->getUser();
                if ($user !== null) {
                    $identifier = $user->getUserIdentifier();
                }
            } catch (\Throwable) {}

            if (false === $this->templateLimiter->create($identifier)->consume(1)->isAccepted()) {
                return new JsonResponse([
                    'error' => $this->trans('template.rate_limit_exceeded', [], 'Too many template requests. Please retry later.'),
                    'code'  => 'RATE_LIMIT_EXCEEDED',
                ], 429, self::SECURITY_HEADERS);
            }
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => $this->trans('template.auth_required', [], 'Authentication required')], 401, self::SECURITY_HEADERS);
        }

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return new JsonResponse([
                'error' => $this->trans('template.payload_too_large', [], 'Payload too large (max 512KB).'),
                'code'  => 'PAYLOAD_TOO_LARGE',
            ], 413, self::SECURITY_HEADERS);
        }

        if ($id <= 0) {
            return new JsonResponse(['error' => $this->trans('template.not_found', [], 'Template not found')], 404, self::SECURITY_HEADERS);
        }

        $template = $this->templateRepository->find($id);
        if ($template === null) {
            return new JsonResponse(['error' => $this->trans('template.not_found', [], 'Template not found')], 404, self::SECURITY_HEADERS);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return new JsonResponse(['error' => $this->trans('template.invalid_json', [], 'Invalid JSON')], 400, self::SECURITY_HEADERS);
        }

        $rawName = isset($data['name']) && is_scalar($data['name']) ? trim((string) $data['name']) : '';
        $projectName = $rawName !== '' ? mb_substr(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $rawName), 0, self::MAX_NAME_LENGTH) : $template->name;

        $project = $this->builder->applyTemplate($template, $projectName, $user);

        $this->auditService?->log(
            toolName: 'project_template_apply',
            project: $project,
            input: ['template_id' => $id, 'project_name' => $projectName],
            output: ['success' => true, 'project_uuid' => $project->uuid?->toString()],
            status: 'success',
            createdBy: $user->getUserIdentifier(),
            source: 'project_template'
        );
        $this->auditService?->flush();

        return new JsonResponse([
            'uuid' => $project->uuid?->toString(),
            'name' => $project->name,
        ], 201, self::SECURITY_HEADERS);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id, ?Request $request = null): JsonResponse
    {
        if ($this->templateLimiter !== null && $request !== null) {
            $identifier = $request->getClientIp() ?: '127.0.0.1';
            try {
                $user = $this->getUser();
                if ($user !== null) {
                    $identifier = $user->getUserIdentifier();
                }
            } catch (\Throwable) {}

            if (false === $this->templateLimiter->create($identifier)->consume(1)->isAccepted()) {
                return new JsonResponse([
                    'error' => $this->trans('template.rate_limit_exceeded', [], 'Too many template requests. Please retry later.'),
                    'code'  => 'RATE_LIMIT_EXCEEDED',
                ], 429, self::SECURITY_HEADERS);
            }
        }

        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        if ($id <= 0) {
            return new JsonResponse(['error' => $this->trans('template.not_found', [], 'Template not found')], 404, self::SECURITY_HEADERS);
        }

        $template = $this->templateRepository->find($id);
        if ($template === null) {
            return new JsonResponse(['error' => $this->trans('template.not_found', [], 'Template not found')], 404, self::SECURITY_HEADERS);
        }

        try {
            $user = $this->getUser();
        } catch (\Throwable) {
            $user = null;
        }

        $this->auditService?->log(
            toolName: 'project_template_delete',
            project: null,
            input: ['template_id' => $id, 'template_name' => $template->name],
            output: ['success' => true],
            status: 'success',
            createdBy: $user?->getUserIdentifier() ?? 'system',
            source: 'project_template'
        );
        $this->auditService?->flush();

        $this->em->remove($template);
        $this->em->flush();

        return new JsonResponse(null, 204, self::SECURITY_HEADERS);
    }
}
