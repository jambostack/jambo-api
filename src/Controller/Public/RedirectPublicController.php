<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Repository\ProjectRepository;
use App\Service\Redirect\RedirectResolver;
use App\Service\Security\SsrfProtectionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class RedirectPublicController extends AbstractController
{
    private const UUID_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    private const MAX_PATH_LENGTH = 2048;
    private const ALLOWED_HTTP_CODES = [301, 302, 303, 307, 308];

    private const SECURITY_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Cache-Control' => 'no-cache, private',
    ];

    public function __construct(
        private readonly ProjectRepository $projectRepo,
        private readonly RedirectResolver $redirectResolver,
        private readonly ?SsrfProtectionService $ssrfProtection = null,
        #[Autowire(service: 'limiter.redirect_limiter')]
        private readonly ?RateLimiterFactory $redirectLimiter = null,
        private readonly ?TranslatorInterface $translator = null,
    ) {}

    private function trans(string $id, array $parameters = [], ?string $default = null): string
    {
        if ($this->translator !== null) {
            return $this->translator->trans($id, $parameters);
        }
        return $default ?? $id;
    }

    private function secureJson(mixed $data, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, array_merge(self::SECURITY_HEADERS, $headers));
    }

    #[Route('/{projectUuid}/redirects/resolve', name: 'public_redirect_resolve')]
    #[Route('/api/projects/{projectUuid}/redirects/resolve', name: 'api_public_redirect_resolve')]
    public function resolve(string $projectUuid, Request $request): Response
    {
        if ($this->redirectLimiter !== null) {
            $limiter = $this->redirectLimiter->create($request->getClientIp() ?: '127.0.0.1');
            if (false === $limiter->consume(1)->isAccepted()) {
                return $this->secureJson([
                    'error' => $this->trans('redirect.rate_limit_exceeded', [], 'Too many redirect requests. Try again later.'),
                    'code' => 'RATE_LIMIT_EXCEEDED',
                ], 429);
            }
        }

        if (!preg_match(self::UUID_REGEX, $projectUuid)) {
            return $this->secureJson([
                'error' => $this->trans('redirect.project_not_found', [], 'Project not found.'),
                'code' => 'PROJECT_NOT_FOUND',
            ], 404);
        }

        try {
            $project = $this->projectRepo->findOneBy(['uuid' => $projectUuid]);
        } catch (\Throwable) {
            return $this->secureJson([
                'error' => $this->trans('redirect.project_not_found', [], 'Project not found.'),
                'code' => 'PROJECT_NOT_FOUND',
            ], 404);
        }
        if (!$project) {
            return $this->secureJson([
                'error' => $this->trans('redirect.project_not_found', [], 'Project not found.'),
                'code' => 'PROJECT_NOT_FOUND',
            ], 404);
        }

        $rawPath = (string) $request->query->get('path', '/');
        if (strlen($rawPath) > self::MAX_PATH_LENGTH) {
            return $this->secureJson([
                'error' => $this->trans('redirect.path_too_long', [], 'Path exceeds maximum length of 2048 characters.'),
                'code' => 'PATH_TOO_LONG',
            ], 400);
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $rawPath)) {
            return $this->secureJson([
                'error' => $this->trans('redirect.path_control_chars', [], 'Path contains invalid control characters.'),
                'code' => 'INVALID_CONTROL_CHARS',
            ], 400);
        }

        $path = $rawPath;
        $redirect = $this->redirectResolver->resolve($path, $project);

        if ($redirect === null) {
            return $this->secureJson(['redirected' => false, 'path' => $path]);
        }

        // Résolution de la destination finale (prise en compte des regex patterns)
        if ($redirect->isPattern) {
            $escaped = str_replace('#', '\#', $redirect->fromPath);
            $result = @preg_replace('#' . $escaped . '#', $redirect->toPath, $path, 1);
            if (preg_last_error() !== PREG_NO_ERROR || $result === null) {
                return $this->secureJson([
                    'error' => $this->trans('redirect.pattern_replace_error', [], 'Redirect pattern could not be applied.'),
                    'code' => 'PATTERN_ERROR',
                ], 400);
            }
            $dest = trim((string) $result);
        } else {
            $dest = trim($redirect->toPath);
        }
        $lowerDest = strtolower($dest);

        // Validation stricte anti-XSS et anti-bypasses protocol-relative
        if (
            preg_match('/[\r\n\x00-\x1F\x7F]/', $dest) ||
            str_starts_with($lowerDest, 'javascript:') ||
            str_starts_with($lowerDest, 'data:') ||
            str_starts_with($lowerDest, 'vbscript:') ||
            str_starts_with($lowerDest, 'file:') ||
            str_starts_with($lowerDest, 'ftp:') ||
            str_starts_with($lowerDest, 'blob:') ||
            str_starts_with($dest, '//') ||
            str_starts_with($dest, '/\\') ||
            str_starts_with($dest, '\\\\') ||
            str_starts_with($dest, '\\/')
        ) {
            return $this->secureJson([
                'error' => $this->trans('redirect.unsafe_destination', [], 'Unsafe redirect destination.'),
                'code' => 'UNSAFE_DESTINATION',
            ], 400);
        }

        // Validation stricte des schemes externes et protection anti-SSRF
        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $dest, $matches)) {
            $scheme = strtolower(rtrim($matches[0], ':'));
            if (!in_array($scheme, ['http', 'https'], true)) {
                return $this->secureJson([
                    'error' => $this->trans('redirect.unsafe_destination', [], 'Unsafe redirect destination.'),
                    'code' => 'UNSAFE_DESTINATION',
                ], 400);
            }

            if ($this->ssrfProtection !== null) {
                $reason = null;
                if (!$this->ssrfProtection->isSafeUrl($dest, false, $reason)) {
                    return $this->secureJson([
                        'error' => $this->trans('redirect.unsafe_destination', [], 'Unsafe redirect destination.'),
                        'code' => 'UNSAFE_DESTINATION',
                    ], 400);
                }
            }
        }

        $httpCode = in_array($redirect->httpCode, self::ALLOWED_HTTP_CODES, true) ? $redirect->httpCode : 302;

        // Retour en JSON ou en redirect HTTP selon le header Accept
        $acceptHeader = (string) $request->headers->get('Accept', '');
        $wantsJson = str_contains($acceptHeader, 'application/json') || $request->query->getBoolean('json');
        if (!$wantsJson && str_contains($acceptHeader, 'text/html')) {
            return new RedirectResponse(
                $dest,
                $httpCode,
                array_merge(self::SECURITY_HEADERS, [
                    'Referrer-Policy' => 'strict-origin-when-cross-origin',
                ])
            );
        }

        return $this->secureJson([
            'redirected' => true,
            'from' => $redirect->fromPath,
            'to' => $dest,
            'httpCode' => $httpCode,
        ]);
    }
}
