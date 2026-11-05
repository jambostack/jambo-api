<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Redirect;
use App\Repository\ProjectRepository;
use App\Repository\RedirectRepository;
use App\Service\Redirect\RedirectChainDetector;
use App\Service\Security\SsrfProtectionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_USER')]
class RedirectController extends AbstractController
{
    private const UUID_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    private const MAX_PAYLOAD_BYTES = 65536; // 64 KB
    private const SECURITY_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options'        => 'DENY',
        'Cache-Control'          => 'no-store',
    ];

    private readonly SsrfProtectionService $ssrfProtection;

    public function __construct(
        private readonly RedirectRepository $redirectRepo,
        private readonly ProjectRepository $projectRepo,
        private readonly EntityManagerInterface $em,
        private readonly RedirectChainDetector $chainDetector,
        ?SsrfProtectionService $ssrfProtection = null,
        private readonly ?TranslatorInterface $translator = null,
    ) {
        $this->ssrfProtection = $ssrfProtection ?? new SsrfProtectionService();
    }

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

    // ── Liste des redirections ────────────────────────────────────────────

    #[Route('/admin-api/{projectUuid}/redirects', name: 'admin_redirects_list', methods: ['GET'])]
    public function index(string $projectUuid, Request $request): JsonResponse
    {
        $project = $this->resolveProject($projectUuid);
        if (!$project) {
            return $this->secureJson(['error' => $this->trans('redirect.admin.project_not_found', [], 'Project not found.'), 'code' => 'PROJECT_NOT_FOUND'], 404);
        }
        $this->denyAccessUnlessGranted('project.view', $project);

        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = min(100, max(1, (int) $request->query->get('per_page', 25)));
        $search = $request->query->get('search');

        $all = $this->redirectRepo->findByProject($project);

        if ($search) {
            $all = array_filter($all, fn (Redirect $r) =>
                stripos($r->fromPath, $search) !== false || stripos($r->toPath, $search) !== false);
        }

        $total = count($all);
        $items = array_slice(array_values($all), ($page - 1) * $perPage, $perPage);

        $data = array_map(fn (Redirect $r) => $this->serializeRedirect($r), $items);

        return $this->secureJson([
            'data' => $data,
            'meta' => ['total' => $total, 'page' => $page, 'per_page' => $perPage],
        ]);
    }

    // ── Détection des chaînes ─────────────────────────────────────────────

    #[Route('/admin-api/{projectUuid}/redirects/chains', name: 'admin_redirects_chains', methods: ['GET'])]
    public function chains(string $projectUuid): JsonResponse
    {
        $project = $this->resolveProject($projectUuid);
        if (!$project) {
            return $this->secureJson(['error' => $this->trans('redirect.admin.project_not_found', [], 'Project not found.'), 'code' => 'PROJECT_NOT_FOUND'], 404);
        }
        $this->denyAccessUnlessGranted('project.view', $project);

        $all = $this->redirectRepo->findByProject($project);
        $chains = $this->chainDetector->detectChains($all);

        $formatted = array_map(fn (array $c) => [
            'shortcut' => $c['shortcut'],
            'length' => count($c['links']),
            'links' => array_map(fn (Redirect $r) => $this->serializeRedirect($r), $c['links']),
        ], $chains);

        return $this->secureJson(['data' => $formatted]);
    }

    // ── Créer ─────────────────────────────────────────────────────────────

    #[Route('/admin-api/{projectUuid}/redirects', name: 'admin_redirects_create', methods: ['POST'])]
    public function create(string $projectUuid, Request $request): JsonResponse
    {
        $project = $this->resolveProject($projectUuid);
        if (!$project) {
            return $this->secureJson(['error' => $this->trans('redirect.admin.project_not_found', [], 'Project not found.'), 'code' => 'PROJECT_NOT_FOUND'], 404);
        }
        $this->denyAccessUnlessGranted('project.manage', $project);

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson(['error' => $this->trans('redirect.admin.payload_too_large', [], 'Redirect payload exceeds maximum allowed size (64 KB).'), 'code' => 'PAYLOAD_TOO_LARGE'], 413);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('redirect.admin.invalid_json', [], 'Invalid JSON payload.'), 'code' => 'INVALID_JSON'], 400);
        }

        $fromPath = trim((string) ($data['fromPath'] ?? ''));
        $toPath = trim((string) ($data['toPath'] ?? ''));
        $isPattern = (bool) ($data['isPattern'] ?? false);

        $pathError = $this->validateRedirectPaths($fromPath, $toPath, $isPattern);
        if ($pathError !== null) {
            return $pathError;
        }

        $httpCode = (int) ($data['httpCode'] ?? 301);
        if (!\in_array($httpCode, [301, 302, 307, 308], true)) {
            return $this->secureJson(['error' => $this->trans('redirect.admin.invalid_http_code', [], 'Invalid HTTP redirect code. Allowed codes: 301, 302, 307, 308.'), 'code' => 'INVALID_HTTP_CODE'], 422);
        }

        $existing = $this->redirectRepo->findByProject($project);
        foreach ($existing as $ex) {
            if ($ex->fromPath === $fromPath && $ex->isPattern === $isPattern) {
                return $this->secureJson(['error' => $this->trans('redirect.admin.from_path_already_exists', [], 'A redirect rule for this source path already exists in this project.'), 'code' => 'FROM_PATH_ALREADY_EXISTS'], 422);
            }
        }

        $redirect = new Redirect();
        $redirect->project = $project;
        $redirect->fromPath = $fromPath;
        $redirect->toPath = $toPath;
        $redirect->httpCode = $httpCode;
        $redirect->isPattern = $isPattern;
        $redirect->isEnabled = (bool) ($data['isEnabled'] ?? true);
        $redirect->createdBy = $this->getUser();

        // Détection de boucle et limitation de profondeur de chaîne
        $chainAnalysis = $this->chainDetector->analyzeChain($redirect, $existing);
        if ($chainAnalysis['hasLoop']) {
            return $this->secureJson(['error' => $this->trans('redirect.admin.loop_detected', [], 'A redirect loop was detected (fromPath directly or indirectly redirects to itself).'), 'code' => 'LOOP_DETECTED'], 422);
        }
        if ($chainAnalysis['isTooLong']) {
            return $this->secureJson(['error' => $this->trans('redirect.admin.chain_depth_exceeded', ['%max%' => (string) RedirectChainDetector::MAX_CHAIN_HOPS], sprintf('The redirect chain exceeds the maximum allowed depth of %d hops.', RedirectChainDetector::MAX_CHAIN_HOPS)), 'code' => 'CHAIN_DEPTH_EXCEEDED'], 422);
        }

        $this->em->persist($redirect);
        $this->em->flush();

        return $this->secureJson($this->serializeRedirect($redirect), 201);
    }

    // ── Lire ───────────────────────────────────────────────────────────────

    #[Route('/admin-api/{projectUuid}/redirects/{id}', name: 'admin_redirects_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(string $projectUuid, int $id): JsonResponse
    {
        $redirect = $this->redirectRepo->find($id);
        if (!$redirect || $redirect->project?->uuid?->toRfc4122() !== $projectUuid) {
            return $this->secureJson(['error' => $this->trans('redirect.admin.redirect_not_found', [], 'Redirect not found.'), 'code' => 'REDIRECT_NOT_FOUND'], 404);
        }
        $this->denyAccessUnlessGranted('project.view', $redirect->project);

        return $this->secureJson($this->serializeRedirect($redirect));
    }

    // ── Mettre à jour ─────────────────────────────────────────────────────

    #[Route('/admin-api/{projectUuid}/redirects/{id}', name: 'admin_redirects_update', requirements: ['id' => '\d+'], methods: ['PUT'])]
    public function update(string $projectUuid, int $id, Request $request): JsonResponse
    {
        $redirect = $this->redirectRepo->find($id);
        if (!$redirect || $redirect->project?->uuid?->toRfc4122() !== $projectUuid) {
            return $this->secureJson(['error' => $this->trans('redirect.admin.redirect_not_found', [], 'Redirect not found.'), 'code' => 'REDIRECT_NOT_FOUND'], 404);
        }
        $this->denyAccessUnlessGranted('project.manage', $redirect->project);

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson(['error' => $this->trans('redirect.admin.payload_too_large', [], 'Redirect payload exceeds maximum allowed size (64 KB).'), 'code' => 'PAYLOAD_TOO_LARGE'], 413);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('redirect.admin.invalid_json', [], 'Invalid JSON payload.'), 'code' => 'INVALID_JSON'], 400);
        }

        $fromPath = array_key_exists('fromPath', $data) ? trim((string) $data['fromPath']) : $redirect->fromPath;
        $toPath = array_key_exists('toPath', $data) ? trim((string) $data['toPath']) : $redirect->toPath;
        $isPattern = array_key_exists('isPattern', $data) ? (bool) $data['isPattern'] : $redirect->isPattern;

        $pathError = $this->validateRedirectPaths($fromPath, $toPath, $isPattern);
        if ($pathError !== null) {
            return $pathError;
        }

        if (array_key_exists('httpCode', $data)) {
            $code = (int) $data['httpCode'];
            if (!\in_array($code, [301, 302, 307, 308], true)) {
                return $this->secureJson(['error' => $this->trans('redirect.admin.invalid_http_code', [], 'Invalid HTTP redirect code. Allowed codes: 301, 302, 307, 308.'), 'code' => 'INVALID_HTTP_CODE'], 422);
            }
            $redirect->httpCode = $code;
        }

        $existing = array_filter(
            $this->redirectRepo->findByProject($redirect->project),
            fn (Redirect $r) => $r->id !== $redirect->id
        );

        foreach ($existing as $ex) {
            if ($ex->fromPath === $fromPath && $ex->isPattern === $isPattern) {
                return $this->secureJson(['error' => $this->trans('redirect.admin.from_path_already_exists', [], 'A redirect rule for this source path already exists in this project.'), 'code' => 'FROM_PATH_ALREADY_EXISTS'], 422);
            }
        }

        $redirect->fromPath = $fromPath;
        $redirect->toPath = $toPath;
        $redirect->isPattern = $isPattern;

        if (array_key_exists('isEnabled', $data)) {
            $redirect->isEnabled = (bool) $data['isEnabled'];
        }

        // Détection de boucle et limitation de profondeur de chaîne
        $chainAnalysis = $this->chainDetector->analyzeChain($redirect, $existing);
        if ($chainAnalysis['hasLoop']) {
            return $this->secureJson(['error' => $this->trans('redirect.admin.loop_detected', [], 'A redirect loop was detected (fromPath directly or indirectly redirects to itself).'), 'code' => 'LOOP_DETECTED'], 422);
        }
        if ($chainAnalysis['isTooLong']) {
            return $this->secureJson(['error' => $this->trans('redirect.admin.chain_depth_exceeded', ['%max%' => (string) RedirectChainDetector::MAX_CHAIN_HOPS], sprintf('The redirect chain exceeds the maximum allowed depth of %d hops.', RedirectChainDetector::MAX_CHAIN_HOPS)), 'code' => 'CHAIN_DEPTH_EXCEEDED'], 422);
        }

        $redirect->updatedBy = $this->getUser();

        $this->em->flush();

        return $this->secureJson($this->serializeRedirect($redirect));
    }

    // ── Supprimer ──────────────────────────────────────────────────────────

    #[Route('/admin-api/{projectUuid}/redirects/{id}', name: 'admin_redirects_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function delete(string $projectUuid, int $id): JsonResponse
    {
        $redirect = $this->redirectRepo->find($id);
        if (!$redirect || $redirect->project?->uuid?->toRfc4122() !== $projectUuid) {
            return $this->secureJson(['error' => $this->trans('redirect.admin.redirect_not_found', [], 'Redirect not found.'), 'code' => 'REDIRECT_NOT_FOUND'], 404);
        }
        $this->denyAccessUnlessGranted('project.manage', $redirect->project);

        $this->em->remove($redirect);
        $this->em->flush();

        return $this->secureJson(null, 204);
    }

    // ── Toggle enabled ─────────────────────────────────────────────────────

    #[Route('/admin-api/{projectUuid}/redirects/{id}/toggle', name: 'admin_redirects_toggle', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggle(string $projectUuid, int $id): JsonResponse
    {
        $redirect = $this->redirectRepo->find($id);
        if (!$redirect || $redirect->project?->uuid?->toRfc4122() !== $projectUuid) {
            return $this->secureJson(['error' => $this->trans('redirect.admin.redirect_not_found', [], 'Redirect not found.'), 'code' => 'REDIRECT_NOT_FOUND'], 404);
        }
        $this->denyAccessUnlessGranted('project.manage', $redirect->project);

        $redirect->isEnabled = !$redirect->isEnabled;
        $redirect->updatedBy = $this->getUser();
        $this->em->flush();

        return $this->secureJson($this->serializeRedirect($redirect));
    }

    // ── Helper ─────────────────────────────────────────────────────────────

    private function resolveProject(string $projectUuid): ?\App\Entity\Project
    {
        if (!preg_match(self::UUID_REGEX, $projectUuid) || !Uuid::isValid($projectUuid)) {
            return null;
        }

        try {
            return $this->projectRepo->findOneBy(['uuid' => $projectUuid]);
        } catch (\Throwable) {
            return null;
        }
    }

    private function validateRedirectPaths(string &$fromPath, string &$toPath, bool $isPattern): ?JsonResponse
    {
        if ($fromPath === '' || $toPath === '') {
            return $this->secureJson(['error' => $this->trans('redirect.admin.paths_required', [], 'Both fromPath and toPath are required.'), 'code' => 'PATHS_REQUIRED'], 422);
        }

        if (strlen($fromPath) > 512) {
            return $this->secureJson(['error' => $this->trans('redirect.admin.from_path_too_long', [], 'fromPath must not exceed 512 characters.'), 'code' => 'FROM_PATH_TOO_LONG'], 422);
        }

        if (strlen($toPath) > 512) {
            return $this->secureJson(['error' => $this->trans('redirect.admin.to_path_too_long', [], 'toPath must not exceed 512 characters.'), 'code' => 'TO_PATH_TOO_LONG'], 422);
        }

        if (preg_match('/[\r\n\x00-\x1F\x7F]/', $fromPath)) {
            return $this->secureJson(['error' => $this->trans('redirect.admin.from_path_control_chars', [], 'fromPath contains invalid control or newline characters.'), 'code' => 'FROM_PATH_CONTROL_CHARS'], 422);
        }

        if (preg_match('/[\r\n\x00-\x1F\x7F]/', $toPath)) {
            return $this->secureJson(['error' => $this->trans('redirect.admin.to_path_control_chars', [], 'toPath contains invalid control or newline characters.'), 'code' => 'TO_PATH_CONTROL_CHARS'], 422);
        }

        $cleanTo = trim($toPath);
        $lowerTo = strtolower($cleanTo);
        $decodedTo = rawurldecode($cleanTo);

        if (
            str_starts_with($cleanTo, '//') ||
            str_starts_with($cleanTo, '/\\') ||
            str_starts_with($cleanTo, '\\\\') ||
            str_starts_with($cleanTo, '\\/') ||
            str_starts_with($cleanTo, '\\') ||
            str_starts_with($decodedTo, '//') ||
            str_starts_with($decodedTo, '\\') ||
            str_starts_with($decodedTo, '/\\')
        ) {
            return $this->secureJson(['error' => $this->trans('redirect.admin.protocol_relative_forbidden', [], 'Protocol-relative URLs starting with // or backslashes are not allowed.'), 'code' => 'PROTOCOL_RELATIVE_FORBIDDEN'], 422);
        }

        if (
            str_starts_with($lowerTo, 'javascript:') ||
            str_starts_with($lowerTo, 'data:') ||
            str_starts_with($lowerTo, 'vbscript:') ||
            str_starts_with($lowerTo, 'file:') ||
            str_starts_with($lowerTo, 'ftp:') ||
            str_starts_with($lowerTo, 'blob:')
        ) {
            return $this->secureJson(['error' => $this->trans('redirect.admin.dangerous_scheme', [], 'Dangerous URI schemes (javascript:, data:, vbscript:) are not allowed.'), 'code' => 'DANGEROUS_SCHEME'], 422);
        }

        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $cleanTo, $matches)) {
            $scheme = strtolower(rtrim($matches[0], ':'));
            if (!in_array($scheme, ['http', 'https'], true)) {
                return $this->secureJson(['error' => $this->trans('redirect.admin.invalid_scheme', ['%scheme%' => $scheme], sprintf('Invalid URI scheme "%s": only http and https are allowed.', $scheme)), 'code' => 'INVALID_SCHEME'], 422);
            }

            // Reject URLs with missing double slashes like http:evil.com or https:evil.com
            if (!preg_match('#^https?://#i', $cleanTo)) {
                return $this->secureJson(['error' => $this->trans('redirect.admin.invalid_scheme', ['%scheme%' => $scheme], sprintf('Invalid URI scheme "%s": only http and https are allowed.', $scheme)), 'code' => 'INVALID_SCHEME'], 422);
            }

            // Reject credentials / userinfo in target URL
            if (parse_url($cleanTo, PHP_URL_USER) !== null || parse_url($cleanTo, PHP_URL_PASS) !== null) {
                return $this->secureJson(['error' => $this->trans('redirect.admin.userinfo_forbidden', [], 'Credentials or userinfo in target URL are forbidden.'), 'code' => 'USERINFO_FORBIDDEN'], 422);
            }

            // Anti-SSRF check on host
            $host = parse_url($cleanTo, PHP_URL_HOST);
            if ($host !== null && trim($host) !== '') {
                $reason = null;
                if (!$this->ssrfProtection->isSafeHost($host, $reason)) {
                    return $this->secureJson(['error' => $this->trans('redirect.admin.ssrf_detected', ['%host%' => $host], 'Target redirect URL points to a restricted or private address (SSRF protection).'), 'code' => 'SSRF_DETECTED'], 422);
                }
            }

            // Port restriction: only standard web ports allowed
            $port = parse_url($cleanTo, PHP_URL_PORT);
            if ($port !== null && !in_array($port, [80, 443, 8080, 8443], true)) {
                return $this->secureJson(['error' => $this->trans('redirect.admin.invalid_port', ['%port%' => (string) $port], sprintf('Target port %s is restricted.', $port)), 'code' => 'INVALID_PORT'], 422);
            }
        } else {
            // Path traversal in relative target paths
            if (str_contains($cleanTo, '/../') || str_ends_with($cleanTo, '/..') || str_starts_with($cleanTo, '../') || $cleanTo === '..') {
                return $this->secureJson(['error' => $this->trans('redirect.admin.path_traversal_forbidden', [], 'Path traversal sequences (..) are not allowed in redirect paths.'), 'code' => 'PATH_TRAVERSAL_FORBIDDEN'], 422);
            }
        }

        if ($isPattern) {
            if (strlen($fromPath) > 256) {
                return $this->secureJson(['error' => $this->trans('redirect.admin.regex_too_long', [], 'Regular expression pattern for fromPath must not exceed 256 characters.'), 'code' => 'REGEX_TOO_LONG'], 422);
            }
            $testRegex = '#' . str_replace('#', '\#', $fromPath) . '#';
            if (@preg_match($testRegex, '') === false) {
                return $this->secureJson(['error' => $this->trans('redirect.admin.regex_invalid', [], 'Invalid regular expression syntax for fromPath pattern.'), 'code' => 'REGEX_INVALID'], 422);
            }
        }

        // Normaliser les chemins relatifs avec un slash initial
        if (!str_starts_with($fromPath, 'http://') && !str_starts_with($fromPath, 'https://')) {
            $fromPath = '/' . ltrim($fromPath, '/');
        }
        if (!str_starts_with($toPath, 'http://') && !str_starts_with($toPath, 'https://')) {
            $toPath = '/' . ltrim($toPath, '/');
        }

        if ($fromPath === $toPath) {
            return $this->secureJson(['error' => $this->trans('redirect.admin.self_redirect', [], 'A redirect cannot point to itself.'), 'code' => 'SELF_REDIRECT'], 422);
        }

        return null;
    }

    private function serializeRedirect(Redirect $r): array
    {
        return [
            'id' => $r->id,
            'uuid' => $r->uuid?->toRfc4122(),
            'projectUuid' => $r->project?->uuid?->toRfc4122(),
            'fromPath' => $r->fromPath,
            'toPath' => $r->toPath,
            'httpCode' => $r->httpCode,
            'isPattern' => $r->isPattern,
            'isEnabled' => $r->isEnabled,
            'hits' => $r->hits,
            'lastHitAt' => $r->lastHitAt?->format('c'),
            'isAuto' => $r->isAuto,
            'sourceEntryUuid' => $r->sourceEntry?->uuid?->toRfc4122(),
            'createdBy' => $r->createdBy?->email,
            'updatedBy' => $r->updatedBy?->email,
            'createdAt' => $r->createdAt->format('c'),
            'updatedAt' => $r->updatedAt->format('c'),
        ];
    }
}
