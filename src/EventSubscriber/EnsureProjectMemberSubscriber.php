<?php

namespace App\EventSubscriber;

use App\Entity\User;
use App\Repository\ProjectMemberRepository;
use App\Repository\ProjectRepository;
use App\Service\ApiTokenChecker;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class EnsureProjectMemberSubscriber implements EventSubscriberInterface
{
    private const SECURITY_HEADERS = [
        'Cache-Control'          => 'no-store, no-cache, must-revalidate, private',
        'Pragma'                 => 'no-cache',
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options'        => 'DENY',
    ];
    public function __construct(
        private ProjectRepository $projectRepository,
        private ProjectMemberRepository $memberRepo,
        private TokenStorageInterface $tokenStorage,
        private ApiTokenChecker $tokenChecker,
        private ?TranslatorInterface $translator = null,
    ) {}

    private function trans(string $id, array $parameters = [], string $default = ''): string
    {
        return $this->translator?->trans($id, $parameters) ?? $default;
    }

    private function secureJson(mixed $data, int $status = 200): JsonResponse
    {
        return new JsonResponse($data, $status, self::SECURITY_HEADERS);
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 5]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request     = $event->getRequest();
        $projectUuid = $request->attributes->get('projectUuid');
        if ($projectUuid === null && str_starts_with($request->getPathInfo(), '/api/projects/')) {
            $projectUuid = $request->attributes->get('uuid') ?? $request->attributes->get('project');
        }

        if ($projectUuid instanceof \App\Entity\Project) {
            $projectUuid = $projectUuid->uuid?->toString();
        } elseif (is_object($projectUuid) && method_exists($projectUuid, '__toString')) {
            $projectUuid = (string) $projectUuid;
        }

        if ($projectUuid === null || $request->isMethod('OPTIONS')) {
            return;
        }

        // Les endpoints publics (captcha, formulaires publics, redirects, seo) sont hors périmètre
        $path = $request->getPathInfo();
        if (
            str_ends_with($path, '/captcha') ||
            preg_match('#^/api/projects/[^/]+/(forms/|redirects/resolve|sitemap|robots\.txt|preview/content/)#', $path)
        ) {
            return;
        }

        $token = $this->tokenStorage->getToken();
        $user  = $token?->getUser();

        if (!$user instanceof User) {
            // Pas de session utilisateur admin : seules les routes admin (/api/projects/*)
            // sont concernées pour l'authentification par jeton API (CRM).
            // Les routes publiques restent hors du périmètre.
            if (!str_starts_with($request->getPathInfo(), '/api/projects/')) {
                return;
            }
            $this->handleUnauthenticated($event, $request, (string) $projectUuid);
            return;
        }

        if (in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
            return;
        }

        try {
            $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        } catch (\Throwable) {
            $event->setResponse($this->secureJson([
                'error' => $this->trans('collection.admin.project_not_found', [], 'Project not found.'),
                'code' => 'PROJECT_NOT_FOUND',
            ], 404));
            return;
        }

        if ($project === null) {
            $event->setResponse($this->secureJson([
                'error' => $this->trans('collection.admin.project_not_found', [], 'Project not found.'),
                'code' => 'PROJECT_NOT_FOUND',
            ], 404));
            return;
        }

        $member = $this->memberRepo->findActiveByUserAndProject($user, $project);

        if ($member === null) {
            $event->setResponse($this->secureJson([
                'error' => $this->trans('project.access_denied_to_project', [], 'Access denied to this project.'),
                'code' => 'ACCESS_DENIED',
            ], 403));
            return;
        }

        $request->attributes->set('_project', $project);
    }

    private function handleUnauthenticated(RequestEvent $event, Request $request, string $projectUuid): void
    {
        $apiToken = $this->tokenChecker->resolve($request);
        if ($apiToken === null) {
            $event->setResponse($this->secureJson([
                'error' => $this->trans('field.admin.auth_required', [], 'Authentication required.'),
                'code' => 'AUTH_REQUIRED',
            ], 401));
            return;
        }

        if ($apiToken->project->uuid?->toString() !== $projectUuid) {
            $event->setResponse($this->secureJson([
                'error' => $this->trans('project.access_denied_to_project', [], 'Access denied to this project.'),
                'code' => 'ACCESS_DENIED',
            ], 403));
            return;
        }

        // Cloisonnement strict des portées selon la méthode HTTP via ApiTokenChecker
        // Pour les endpoints GraphQL, les requêtes POST peuvent transporter des lectures (queries) ou mutations ;
        // la vérification fine des droits de mutation est déléguée au GraphQLController.
        $path = $request->getPathInfo();
        $methodToCheck = (str_ends_with($path, '/graphql') && $request->isMethod('POST')) ? 'GET' : $request->getMethod();
        if (!$this->tokenChecker->isAllowedForMethod($apiToken, $methodToCheck)) {
            $event->setResponse($this->secureJson([
                'error' => $this->trans('project.insufficient_token_abilities', [], 'Insufficient token abilities.'),
                'code' => 'INSUFFICIENT_ABILITIES',
            ], 403));
            return;
        }

        $request->attributes->set('_project', $apiToken->project);
    }
}
