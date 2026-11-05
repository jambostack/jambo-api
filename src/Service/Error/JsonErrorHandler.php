<?php

declare(strict_types=1);

namespace App\Service\Error;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Service centralisé pour générer des réponses d'erreur JSON durcies et sécurisées.
 *
 * Applique :
 * - Masquage strict des messages d'erreur 500 en environnement de production
 * - En-têtes anti-mise en cache stricts (Cache-Control: no-store)
 * - En-têtes de sécurité HTTP (X-Content-Type-Options, X-Frame-Options, Referrer-Policy, etc.)
 * - Gestion normalisée des codes d'erreur d'API
 * - Internationalisation des messages d'erreur
 */
class JsonErrorHandler
{
    public function __construct(
        private readonly string $environment = 'prod',
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?TranslatorInterface $translator = null,
    ) {}

    public function createResponse(\Throwable $exception, Request $request, ?int $forceStatusCode = null): JsonResponse
    {
        $status = $forceStatusCode ?? ($exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500);
        $path = $request->getPathInfo();

        if ($status >= 500) {
            $this->logger->error(sprintf('HTTP %d Error on [%s]: %s', $status, $path, $exception->getMessage()), [
                'exception' => $exception,
                'path' => $path,
                'ip' => $request->getClientIp(),
            ]);
        }

        $message = $exception->getMessage();
        if ($status >= 500 && $this->environment === 'prod') {
            $message = $this->translator?->trans('api.unexpected_error', [], 'messages') ?? 'An unexpected error occurred.';
        }

        $payload = ['error' => $message];

        // Format spécifique pour l'API publique /api/
        if (str_starts_with($path, '/api/')) {
            $payload['code'] = match ($status) {
                400 => 'BAD_REQUEST',
                401 => 'UNAUTHORIZED',
                403 => 'FORBIDDEN',
                404 => 'NOT_FOUND',
                405 => 'METHOD_NOT_ALLOWED',
                422 => 'VALIDATION_ERROR',
                429 => 'RATE_LIMITED',
                default => 'INTERNAL_ERROR',
            };
        }

        // Violations de validation si l'exception en dispose
        if (method_exists($exception, 'getViolations')) {
            $violations = [];
            foreach ($exception->getViolations() as $v) {
                $violations[$v->getPropertyPath()] = $v->getMessage();
            }
            if ($violations !== []) {
                $payload['errors'] = $violations;
            }
        }

        $response = new JsonResponse($payload, $status);

        $this->applySecurityAndNoCacheHeaders($response, $request);

        return $response;
    }

    /**
     * Applique les en-têtes HTTP de sécurité et anti-cache sur la réponse d'erreur.
     */
    public function applySecurityAndNoCacheHeaders(JsonResponse $response, Request $request): void
    {
        $headers = $response->headers;
        $path = $request->getPathInfo();

        // 1. Anti-caching absolu sur les erreurs
        $headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $headers->set('Pragma', 'no-cache');
        $headers->set('Expires', '0');

        // 2. MIME Sniffing protection
        $headers->set('X-Content-Type-Options', 'nosniff');

        // 3. Permitted Cross Domain
        $headers->set('X-Permitted-Cross-Domain-Policies', 'none');

        // 4. Referrer Policy
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // 5. Anti-clickjacking
        $isEmbedRoute = str_starts_with($path, '/forms/embed')
            || str_starts_with($path, '/share/')
            || str_starts_with($path, '/embed/');

        if (!$isEmbedRoute) {
            $headers->set('X-Frame-Options', 'DENY');
        } else {
            $headers->set('X-Frame-Options', 'SAMEORIGIN');
        }

        // 6. Suppression de cookies sensibles sur les erreurs 500
        if ($response->getStatusCode() >= 500) {
            $headers->remove('Set-Cookie');
        }
    }
}
