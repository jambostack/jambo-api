<?php

namespace App\Controller;

use App\Entity\ContentEntry;
use App\Entity\Project;
use App\Repository\ContentEntryRepository;
use App\Repository\ProjectRepository;
use App\Service\EavDataFormatterService;
use App\Service\PreviewTokenService;
use App\Service\Security\SsrfProtectionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpoints pour le systeme de Live Preview.
 *
 * /token/{entryUuid}   — genere un JWT de preview (appele par l'admin)
 * /validate-url        — valide strictement l'URL cible de preview (anti-SSRF)
 * /content/{col}/{eid} — retourne l'entree (meme brouillon) via JWT preview
 */
#[Route('/api/projects/{projectUuid}/preview', name: 'api_preview_')]
class PreviewController extends AbstractController
{
    public function __construct(
        private readonly ProjectRepository $projectRepository,
        private readonly ContentEntryRepository $entryRepository,
        private readonly PreviewTokenService $tokenService,
        private readonly EavDataFormatterService $formatter,
        private readonly SsrfProtectionService $ssrfProtection,
        private readonly ?RateLimiterFactory $previewLimiter = null,
    ) {}

    /**
     * Genere un token de preview pour une entree.
     * Protege par l'auth admin Symfony standard.
     */
    #[Route('/token/{entryUuid}', name: 'token', methods: ['GET'])]
    public function token(string $projectUuid, string $entryUuid, Request $request): JsonResponse
    {
        if ($this->previewLimiter !== null) {
            $limiter = $this->previewLimiter->create($request->getClientIp() ?: '127.0.0.1');
            if (false === $limiter->consume(1)->isAccepted()) {
                return new JsonResponse(['error' => 'Too many preview requests. Try again later.'], 429);
            }
        }

        if (!$this->getUser()) {
            return $this->json(['error' => 'Authentication required'], 401);
        }

        try {
            $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        } catch (\Throwable) {
            return $this->json(['error' => 'Project not found'], 404);
        }
        if (!$project) {
            return $this->json(['error' => 'Project not found'], 404);
        }

        if ($request->attributes->get('_project') !== $project) {
            $this->denyAccessUnlessGranted('project.view', $project);
        }

        try {
            $entry = $this->entryRepository->findOneBy(['uuid' => $entryUuid, 'project' => $project]);
        } catch (\Throwable) {
            return $this->json(['error' => 'Entry not found'], 404);
        }
        if (!$entry || $entry->isDeleted()) {
            return $this->json(['error' => 'Entry not found'], 404);
        }

        // Validation du TTL (entre 60s et 86400s)
        $ttl = PreviewTokenService::DEFAULT_TTL;
        if ($request->query->has('ttl')) {
            $rawTtl = $request->query->get('ttl');
            if (!is_numeric($rawTtl)) {
                return $this->json(['error' => 'Invalid preview token TTL: must be an integer.'], 422);
            }
            $ttl = (int) $rawTtl;
            if ($ttl < PreviewTokenService::MIN_TTL || $ttl > PreviewTokenService::MAX_TTL) {
                return $this->json(['error' => sprintf(
                    'Invalid preview token TTL: must be between %d and %d seconds.',
                    PreviewTokenService::MIN_TTL,
                    PreviewTokenService::MAX_TTL
                )], 422);
            }
        }

        // Validation stricte de l'URL cible si fournie
        $targetUrl = $request->query->get('target_url') ?? $request->query->get('preview_url');
        if ($targetUrl !== null && trim((string) $targetUrl) !== '') {
            $validationError = $this->validatePreviewTargetUrl((string) $targetUrl);
            if ($validationError !== null) {
                return $this->json(['error' => $validationError], 422);
            }
        }

        $token = $this->tokenService->createToken($entry, $ttl);

        return $this->json([
            'token'      => $token,
            'expires_in' => $ttl,
        ]);
    }

    /**
     * Valide une URL cible de preview (schémas http/https uniquement, anti-SSRF).
     */
    #[Route('/validate-url', name: 'validate_url', methods: ['GET', 'POST'])]
    public function validateUrl(string $projectUuid, Request $request): JsonResponse
    {
        if ($this->previewLimiter !== null) {
            $limiter = $this->previewLimiter->create($request->getClientIp() ?: '127.0.0.1');
            if (false === $limiter->consume(1)->isAccepted()) {
                return new JsonResponse(['error' => 'Too many preview requests. Try again later.'], 429);
            }
        }

        $url = $request->query->get('url') ?? $request->query->get('target_url') ?? $request->query->get('preview_url');
        if ($url === null && $request->isMethod('POST')) {
            try {
                $body = $request->toArray();
                $url = $body['url'] ?? $body['target_url'] ?? $body['preview_url'] ?? null;
            } catch (\Throwable) {
                // Ignore parsing errors
            }
        }

        if ($url === null || trim((string) $url) === '') {
            return new JsonResponse(['error' => 'URL is required.'], 422);
        }

        $error = $this->validatePreviewTargetUrl((string) $url);
        if ($error !== null) {
            return new JsonResponse(['valid' => false, 'error' => $error], 422);
        }

        return new JsonResponse(['valid' => true, 'url' => (string) $url]);
    }

    /**
     * Valide strictement une URL cible de prévisualisation :
     * format, longueur, contrôle anti-CRLF, schéma http/https, interdiction credentials, anti-SSRF.
     */
    public function validatePreviewTargetUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return 'Invalid preview target URL: URL cannot be empty.';
        }
        if (strlen($url) > 2048) {
            return 'Invalid preview target URL: exceeds 2048 characters.';
        }
        if (preg_match('/[\r\n\x00-\x1F\x7F]/', $url)) {
            return 'Invalid preview target URL: control characters detected.';
        }

        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme'])) {
            return 'Invalid preview target URL: malformed URL.';
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return 'Invalid preview target URL: only http and https allowed.';
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return 'Invalid preview target URL: malformed URL.';
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'Invalid preview target URL: credentials in URL are strictly forbidden.';
        }

        $host = strtolower(trim($parts['host'] ?? '', " \t\n\r\0\x0B[]"));
        if ($host === '') {
            return 'Invalid preview target URL: missing host in URL.';
        }

        $reason = null;
        if (!$this->ssrfProtection->isSafeHost($host, $reason)) {
            return 'Invalid preview target URL: target must not point to private or reserved addresses.';
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        $forbiddenPorts = [21, 22, 23, 25, 53, 110, 143, 3306, 5432, 6379, 11211, 27017, 9200, 9300];
        if (in_array($port, $forbiddenPorts, true)) {
            return sprintf('Invalid preview target URL: port %d is not allowed for preview.', $port);
        }

        return null;
    }

    /**
     * Retourne une entree via le token de preview.
     * Permet d'acceder aux brouillons sans auth admin standard.
     */
    #[Route('/content/{collection}/{entryUuid}', name: 'content', methods: ['GET'])]
    public function content(string $projectUuid, string $collection, string $entryUuid, Request $request): JsonResponse
    {
        if ($this->previewLimiter !== null) {
            $limiter = $this->previewLimiter->create($request->getClientIp() ?: '127.0.0.1');
            if (false === $limiter->consume(1)->isAccepted()) {
                return new JsonResponse(['error' => 'Too many preview requests. Try again later.'], 429);
            }
        }

        // Extraire le token du header Authorization ou des query params (?token= ou ?preview_token=)
        $token = null;
        $authHeader = $request->headers->get('Authorization');
        if ($authHeader !== null && str_starts_with($authHeader, 'Bearer ')) {
            $token = substr($authHeader, 7);
        } elseif ($request->query->has('token')) {
            $token = (string) $request->query->get('token');
        } elseif ($request->query->has('preview_token')) {
            $token = (string) $request->query->get('preview_token');
        }

        if ($token === null || trim($token) === '') {
            return $this->json(['error' => 'Missing or malformed Authorization header or preview token parameter'], 401);
        }

        $claims = $this->tokenService->validateToken($token);

        if ($claims === null) {
            return $this->json(['error' => 'Invalid or expired preview token'], 401);
        }

        // Verifier que le token correspond a l'URL
        if ($claims['pid'] !== $projectUuid) {
            return $this->json(['error' => 'Token does not match project'], 403);
        }
        if ($claims['eid'] !== $entryUuid) {
            return $this->json(['error' => 'Token does not match entry'], 403);
        }
        if ($claims['col'] !== $collection) {
            return $this->json(['error' => 'Token does not match collection'], 403);
        }

        try {
            $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        } catch (\Throwable) {
            return $this->json(['error' => 'Project not found'], 404);
        }
        if (!$project) {
            return $this->json(['error' => 'Project not found'], 404);
        }

        // Verifier que la preview est activee pour ce projet
        if (!$project->previewEnabled) {
            return $this->json(['error' => 'Preview not enabled for this project'], 403);
        }

        try {
            $entry = $this->entryRepository->findOneBy(['uuid' => $entryUuid, 'project' => $project]);
        } catch (\Throwable) {
            return $this->json(['error' => 'Entry not found'], 404);
        }
        if (!$entry || $entry->isDeleted()) {
            return $this->json(['error' => 'Entry not found'], 404);
        }

        return new JsonResponse($this->formatter->formatEntry($entry), 200, [
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
