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
            return $this->renderError(404, 'unsupported_format', true);
        }

        if ($this->shareLimiter !== null) {
            $identifier = $request->getClientIp() ?: '127.0.0.1';
            $limiter = $this->shareLimiter->create($identifier);
            if (false === $limiter->consume(1)->isAccepted()) {
                $this->auditService?->log(
                    toolName: 'public_share_rate_limit',
                    project: null,
                    input: ['ip' => $identifier],
                    output: null,
                    status: 'failure',
                    errorMessage: 'Rate limit exceeded',
                    source: 'public_share',
                );
                return $this->renderError(429, 'too_many_requests', $wantsJson);
            }
        }

        $tokenLength = strlen($token);
        if ($tokenLength < self::MIN_TOKEN_LENGTH || $tokenLength > self::MAX_TOKEN_LENGTH || !preg_match('/^[A-Za-z0-9_\-]+$/', $token)) {
            return $this->renderError(404, 'invalid', $wantsJson);
        }

        $share = $this->shares->resolve($token);

        if ($share === null || $share->isRevoked()) {
            return $this->renderError(404, 'invalid', $wantsJson);
        }
        if ($share->isExpired()) {
            return $this->renderError(410, 'expired', $wantsJson);
        }
        $entry = $share->entry;
        if ($entry === null || $entry->isDeleted()) {
            return $this->renderError(404, 'invalid', $wantsJson);
        }

        // Integrity check: if entry has a collection, ensure it is not soft-deleted
        if ($entry->collection !== null && $entry->collection->isDeleted()) {
            return $this->renderError(404, 'invalid', $wantsJson);
        }

        // Multi-tenant check: entry must match share project
        if ($share->project === null || $entry->project?->id !== $share->project->id) {
            return $this->renderError(404, 'invalid', $wantsJson);
        }

        $this->shares->recordAccess($share);

        $data = $this->formatter->formatEntry($entry);

        if ($wantsJson) {
            return new JsonResponse($data, 200, self::SECURITY_HEADERS);
        }

        return $this->render('share/show.html.twig', [
            'entry'      => $data,
            'collection' => $entry->collection?->name ?? $data['collection'],
        ], new Response('', 200, self::SECURITY_HEADERS));
    }

    private function renderError(int $status, string $reason, bool $wantsJson = false): Response
    {
        $localizedMessage = $this->trans("public_share.error.{$reason}", [], match ($reason) {
            'expired' => 'This shared link has expired.',
            'too_many_requests' => 'Too many requests. Please try again later.',
            'unsupported_format' => 'Requested response format is not supported.',
            default => 'Invalid or non-existent shared link.',
        });

        if ($wantsJson) {
            return new JsonResponse([
                'error'   => $reason,
                'message' => $localizedMessage,
            ], $status, self::SECURITY_HEADERS);
        }
        return $this->render('share/error.html.twig', [
            'reason'  => $reason,
            'message' => $localizedMessage,
        ], new Response('', $status, self::SECURITY_HEADERS));
    }
}
