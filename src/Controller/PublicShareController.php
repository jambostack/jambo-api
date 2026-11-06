<?php

namespace App\Controller;

use App\Service\AuditService;
use App\Service\EavDataFormatterService;
use App\Service\Share\ShareService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class PublicShareController extends AbstractController
{
    public const MAX_TOKEN_LENGTH = 128;
    public const MIN_TOKEN_LENGTH = 8;

    private const SECURITY_HEADERS = [
        'Cache-Control' => 'no-store, no-cache, must-revalidate, private, max-age=0',
        'Pragma' => 'no-cache',
        'Expires' => '0',
        'Surrogate-Control' => 'no-store',
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'no-referrer',
        'X-Robots-Tag' => 'noindex, nofollow, noarchive, nosnippet',
        'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
        'Content-Security-Policy' => "default-src 'none'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https:; font-src 'self' https://fonts.gstatic.com; frame-ancestors 'none'; form-action 'none';",
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
        'Cross-Origin-Resource-Policy' => 'same-origin',
    ];

    public function __construct(
        private readonly ShareService $shares,
        private readonly EavDataFormatterService $formatter,
        #[Autowire(service: 'limiter.share_limiter')]
        private readonly ?RateLimiterFactory $shareLimiter = null,
        private readonly ?TranslatorInterface $translator = null,
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

    #[Route('/share/{token}', name: 'public_share_show', requirements: ['token' => '[A-Za-z0-9_\-]+'], defaults: ['_format' => 'html'], methods: ['GET'])]
    #[Route('/share/{token}.json', name: 'public_share_show_json', requirements: ['token' => '[A-Za-z0-9_\-]+'], defaults: ['_format' => 'json'], methods: ['GET'])]
    public function show(string $token, string $_format, Request $request): Response
    {
        $wantsJson = $_format === 'json'
            || str_contains((string) $request->headers->get('Accept'), 'application/json');

        if (!in_array($_format, ['html', 'json'], true)) {
            $this->auditService?->log(
                toolName: 'public_share_invalid_attempt',
                project: null,
                input: ['format' => $_format, 'ip' => $request->getClientIp()],
                output: null,
                status: 'failure',
                errorMessage: 'Unsupported response format requested',
                createdBy: $request->getClientIp(),
                source: 'public_share',
            );
            $this->auditService?->flush();
            return $this->renderError(404, 'unsupported_format', true);
        }

        $identifier = $request->getClientIp() ?: '127.0.0.1';
        $limiter = $this->shareLimiter?->create($identifier);
        if ($limiter !== null) {
            $limit = $limiter->consume(1);
            if (false === $limit->isAccepted()) {
                $this->auditService?->log(
                    toolName: 'public_share_rate_limit',
                    project: null,
                    input: ['ip' => $identifier],
                    output: null,
                    status: 'failure',
                    errorMessage: 'Rate limit exceeded',
                    createdBy: $identifier,
                    source: 'public_share',
                );
                $this->auditService?->flush();
                $retryAfter = max(1, ($limit->getRetryAfter()?->getTimestamp() ?? (time() + 60)) - time());
                return $this->renderError(429, 'too_many_requests', $wantsJson, [
                    'Retry-After' => (string) $retryAfter,
                    'X-RateLimit-Limit' => (string) $limit->getLimit(),
                    'X-RateLimit-Remaining' => (string) $limit->getRemainingTokens(),
                    'X-RateLimit-Reset' => (string) ($limit->getRetryAfter()?->getTimestamp() ?? (time() + 60)),
                ]);
            }
        }

        $tokenLength = strlen($token);
        if ($tokenLength < self::MIN_TOKEN_LENGTH || $tokenLength > self::MAX_TOKEN_LENGTH || !preg_match('/^[A-Za-z0-9_\-]+$/', $token)) {
            $limiter?->consume(2);
            $this->auditService?->log(
                toolName: 'public_share_invalid_attempt',
                project: null,
                input: [
                    'ip' => $identifier,
                    'token_prefix' => substr($token, 0, 10),
                    'reason' => 'invalid_token_format',
                ],
                output: null,
                status: 'failure',
                errorMessage: 'Invalid token format',
                createdBy: $identifier,
                source: 'public_share',
            );
            $this->auditService?->flush();
            return $this->renderError(404, 'invalid', $wantsJson);
        }

        $share = $this->shares->resolve($token);

        if ($share === null) {
            $limiter?->consume(2);
            $this->auditService?->log(
                toolName: 'public_share_not_found',
                project: null,
                input: [
                    'ip' => $identifier,
                    'token_prefix' => substr($token, 0, 12) . '...',
                    'reason' => 'token_not_found',
                ],
                output: null,
                status: 'failure',
                errorMessage: 'Share token not found',
                createdBy: $identifier,
                source: 'public_share',
            );
            $this->auditService?->flush();
            return $this->renderError(404, 'invalid', $wantsJson);
        }

        if ($share->isRevoked()) {
            $this->auditService?->log(
                toolName: 'public_share_revoked',
                project: $share->project,
                input: [
                    'ip' => $identifier,
                    'share_id' => $share->id,
                    'revoked_at' => $share->revokedAt?->format('c'),
                ],
                output: null,
                status: 'failure',
                errorMessage: 'Share link has been revoked',
                createdBy: $identifier,
                source: 'public_share',
            );
            $this->auditService?->flush();
            return $this->renderError(404, 'invalid', $wantsJson);
        }

        if ($share->isExpired()) {
            $this->auditService?->log(
                toolName: 'public_share_expired',
                project: $share->project,
                input: [
                    'ip' => $identifier,
                    'share_id' => $share->id,
                    'expires_at' => $share->expiresAt?->format('c'),
                ],
                output: null,
                status: 'failure',
                errorMessage: 'Share link has expired',
                createdBy: $identifier,
                source: 'public_share',
            );
            $this->auditService?->flush();
            return $this->renderError(410, 'expired', $wantsJson);
        }

        $entry = $share->entry;
        if ($entry === null || $entry->isDeleted()) {
            $this->auditService?->log(
                toolName: 'public_share_security_violation',
                project: $share->project,
                input: [
                    'ip' => $identifier,
                    'share_id' => $share->id,
                    'reason' => 'entry_missing_or_deleted',
                ],
                output: null,
                status: 'failure',
                errorMessage: 'Entry missing or deleted',
                createdBy: $identifier,
                source: 'public_share',
            );
            $this->auditService?->flush();
            return $this->renderError(404, 'invalid', $wantsJson);
        }

        // Integrity check: if entry has a collection, ensure it is not soft-deleted
        if ($entry->collection !== null && $entry->collection->isDeleted()) {
            $this->auditService?->log(
                toolName: 'public_share_security_violation',
                project: $share->project,
                input: [
                    'ip' => $identifier,
                    'share_id' => $share->id,
                    'reason' => 'collection_deleted',
                ],
                output: null,
                status: 'failure',
                errorMessage: 'Collection deleted',
                createdBy: $identifier,
                source: 'public_share',
            );
            $this->auditService?->flush();
            return $this->renderError(404, 'invalid', $wantsJson);
        }

        // Multi-tenant check: entry must match share project
        if ($share->project === null || $entry->project?->id !== $share->project->id) {
            $this->auditService?->log(
                toolName: 'public_share_security_violation',
                project: $share->project,
                input: [
                    'ip' => $identifier,
                    'share_id' => $share->id,
                    'reason' => 'tenant_mismatch',
                ],
                output: null,
                status: 'failure',
                errorMessage: 'Tenant mismatch',
                createdBy: $identifier,
                source: 'public_share',
            );
            $this->auditService?->flush();
            return $this->renderError(404, 'invalid', $wantsJson);
        }

        $this->shares->recordAccess($share);

        $this->auditService?->log(
            toolName: 'public_share_access',
            project: $share->project,
            input: [
                'ip' => $identifier,
                'share_id' => $share->id,
                'format' => $_format,
            ],
            output: [
                'entry_uuid' => $entry->uuid?->toString(),
                'view_count' => $share->viewCount,
            ],
            status: 'success',
            createdBy: $identifier,
            source: 'public_share',
        );
        $this->auditService?->flush();

        $data = $this->formatter->formatEntry($entry);

        if ($wantsJson) {
            return new JsonResponse($data, 200, self::SECURITY_HEADERS);
        }

        return $this->render('share/show.html.twig', [
            'entry'      => $data,
            'collection' => $entry->collection?->name ?? $data['collection'],
        ], new Response('', 200, self::SECURITY_HEADERS));
    }

    private function renderError(int $status, string $reason, bool $wantsJson = false, array $extraHeaders = []): Response
    {
        $localizedMessage = $this->trans("public_share.error.{$reason}", [], match ($reason) {
            'expired' => 'This shared link has expired.',
            'too_many_requests' => 'Too many requests. Please try again later.',
            'unsupported_format' => 'Requested response format is not supported.',
            default => 'Invalid or non-existent shared link.',
        });

        $headers = array_merge(self::SECURITY_HEADERS, $extraHeaders);

        if ($wantsJson) {
            return new JsonResponse([
                'error'   => $reason,
                'message' => $localizedMessage,
            ], $status, $headers);
        }
        return $this->render('share/error.html.twig', [
            'reason'  => $reason,
            'message' => $localizedMessage,
        ], new Response('', $status, $headers));
    }
}
