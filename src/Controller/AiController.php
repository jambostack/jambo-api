<?php

namespace App\Controller;

use App\Entity\Collection;
use App\Entity\Project;
use App\Service\AiContentService;
use App\Service\ApiTokenChecker;
use App\Service\AuditService;
use App\Service\EavDataFormatterService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class AiController extends AbstractController
{
    public const MAX_PAYLOAD_BYTES = 1048576; // 1 MB
    public const MIN_BRIEF_LENGTH = 3;
    public const MAX_BRIEF_LENGTH = 10000;
    public const MIN_TEXT_LENGTH = 3;
    public const MAX_TEXT_LENGTH = 50000;
    public const MIN_WORDS = 5;
    public const MAX_WORDS = 2000;
    public const MAX_CONTENT_KEYS = 100;
    public const MAX_CONTENT_DEPTH = 5;
    public const MIN_INFERENCE_TOKENS = 16;
    public const MAX_INFERENCE_TOKENS = 4096;
    public const DEFAULT_INFERENCE_TOKENS = 1024;

    private const SECURITY_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options'        => 'DENY',
        'Cache-Control'          => 'no-store',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AiContentService $ai,
        private readonly EavDataFormatterService $formatter,
        #[Autowire(service: 'limiter.ai_limiter')]
        private readonly ?RateLimiterFactory $aiLimiter = null,
        private readonly ?ApiTokenChecker $tokenChecker = null,
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

    private function secureJson(mixed $data, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, array_merge(self::SECURITY_HEADERS, $headers));
    }

    private function validateContentType(Request $request): ?JsonResponse
    {
        $contentType = (string) $request->headers->get('Content-Type', '');
        if ($contentType !== '' && !str_starts_with(strtolower($contentType), 'application/json') && !str_starts_with(strtolower($contentType), 'application/x-www-form-urlencoded')) {
            return $this->secureJson([
                'error' => $this->trans('ai.invalid_content_type', [], 'Invalid Content-Type header, application/json expected.'),
            ], 415);
        }
        return null;
    }

    /**
     * @return array{0: int, 1: ?string}
     */
    private function resolveMaxTokens(mixed $rawTokens): array
    {
        if ($rawTokens === null || $rawTokens === '') {
            return [self::DEFAULT_INFERENCE_TOKENS, null];
        }
        if (!is_int($rawTokens) && !ctype_digit((string) $rawTokens)) {
            return [0, $this->trans('ai.invalid_max_tokens', ['%min%' => (string) self::MIN_INFERENCE_TOKENS, '%max%' => (string) self::MAX_INFERENCE_TOKENS], sprintf('Invalid maximum inference tokens (must be between %d and %d).', self::MIN_INFERENCE_TOKENS, self::MAX_INFERENCE_TOKENS))];
        }
        $tokens = (int) $rawTokens;
        if ($tokens < self::MIN_INFERENCE_TOKENS || $tokens > self::MAX_INFERENCE_TOKENS) {
            return [0, $this->trans('ai.invalid_max_tokens', ['%min%' => (string) self::MIN_INFERENCE_TOKENS, '%max%' => (string) self::MAX_INFERENCE_TOKENS], sprintf('Invalid maximum inference tokens (must be between %d and %d).', self::MIN_INFERENCE_TOKENS, self::MAX_INFERENCE_TOKENS))];
        }
        return [$tokens, null];
    }

    /**
     * @return array{0: float, 1: ?string}
     */
    private function resolveTemperature(mixed $rawTemp): array
    {
        if ($rawTemp === null || $rawTemp === '') {
            return [0.7, null];
        }
        if (!is_numeric($rawTemp)) {
            return [0.0, $this->trans('ai.invalid_temperature', [], 'Invalid temperature (must be between 0.0 and 2.0).')];
        }
        $temp = (float) $rawTemp;
        if ($temp < 0.0 || $temp > 2.0) {
            return [0.0, $this->trans('ai.invalid_temperature', [], 'Invalid temperature (must be between 0.0 and 2.0).')];
        }
        return [$temp, null];
    }

    private function validateModelName(?string $model): ?string
    {
        if ($model === null || $model === '') {
            return null;
        }
        if (!preg_match('/^[a-zA-Z0-9_\-\.\/]{1,80}$/', $model)) {
            return $this->trans('ai.invalid_model', [], 'Invalid AI model specified.');
        }
        return null;
    }

    private function validateProviderName(?string $provider): ?string
    {
        if ($provider === null || $provider === '') {
            return null;
        }
        if (!preg_match('/^[a-zA-Z0-9_\-]{2,30}$/', $provider)) {
            return $this->trans('ai.invalid_provider', [], 'Invalid AI provider specified.');
        }
        return null;
    }

    #[Route('/api/projects/{uuid}/ai/generate', name: 'ai_generate', methods: ['POST'])]
    public function generate(string $uuid, Request $request): JsonResponse
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        $contentLength = (int) $request->headers->get('Content-Length', 0);
        if ($contentLength > self::MAX_PAYLOAD_BYTES || strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson(['error' => $this->trans('ai.payload_too_large', [], 'AI payload too large (max 1MB).')], 413);
        }

        if ($contentError = $this->validateContentType($request)) {
            return $contentError;
        }

        [$project, $errorResponse] = $this->findProjectAndCheckAccess($uuid, $request, 'content.create');
        if ($errorResponse !== null) {
            return $errorResponse;
        }

        $body = json_decode($request->getContent(), true);
        if (!is_array($body)) {
            return $this->secureJson(['error' => $this->trans('ai.invalid_json', [], 'Invalid JSON payload.')], 400);
        }

        $rawBrief = (string) ($body['brief'] ?? '');
        $collectionSlug = trim((string) ($body['collection'] ?? ''));

        if (trim($rawBrief) === '' || $collectionSlug === '') {
            return $this->secureJson(['error' => $this->trans('ai.brief_and_collection_required', [], 'Brief and collection are required.')], 400);
        }

        $brief = $this->sanitizePromptInput($rawBrief);
        $briefLength = mb_strlen($brief);

        if ($briefLength < self::MIN_BRIEF_LENGTH) {
            return $this->secureJson(['error' => $this->trans('ai.brief_too_short', ['%min%' => self::MIN_BRIEF_LENGTH], sprintf('Brief is too short (minimum %d characters).', self::MIN_BRIEF_LENGTH))], 400);
        }

        if ($briefLength > self::MAX_BRIEF_LENGTH) {
            return $this->secureJson(['error' => $this->trans('ai.brief_too_long', ['%max%' => self::MAX_BRIEF_LENGTH], sprintf('Brief exceeds maximum allowed length (%d characters).', self::MAX_BRIEF_LENGTH))], 400);
        }

        if ($this->containsDangerousPromptBreakout($brief)) {
            $this->auditService?->log(
                toolName: 'ai_generate',
                project: $project,
                input: ['collection' => $collectionSlug],
                output: ['error' => 'prompt_injection_detected'],
                status: 'blocked',
                createdBy: $this->getUser()?->getUserIdentifier() ?? 'api_token',
                source: 'ai_controller',
            );
            $this->auditService?->flush();
            return $this->secureJson(['error' => $this->trans('ai.prompt_injection_detected', [], 'Prompt rejected: potentially harmful prompt injection detected.')], 400);
        }

        [$maxTokens, $tokenError] = $this->resolveMaxTokens($body['max_tokens'] ?? $body['maxTokens'] ?? null);
        if ($tokenError !== null) {
            return $this->secureJson(['error' => $tokenError], 400);
        }

        [$temperature, $tempError] = $this->resolveTemperature($body['temperature'] ?? null);
        if ($tempError !== null) {
            return $this->secureJson(['error' => $tempError], 400);
        }

        if (isset($body['model']) && ($modelError = $this->validateModelName((string) $body['model']))) {
            return $this->secureJson(['error' => $modelError], 400);
        }

        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $collectionSlug)) {
            return $this->secureJson(['error' => $this->trans('ai.invalid_collection_slug', [], 'Invalid collection slug format.')], 400);
        }

        $collection = $this->em->getRepository(Collection::class)->findOneBy([
            'project' => $project,
            'slug' => $collectionSlug,
            'deletedAt' => null,
        ]);
        if (!$collection) {
            return $this->secureJson(['error' => $this->trans('ai.collection_not_found', [], 'Collection not found.')], 404);
        }

        $locale = (string) ($body['locale'] ?? $project->defaultLocale ?? 'fr');
        if (!preg_match('/^[a-zA-Z]{2,4}([-_][a-zA-Z0-9]{2,8})?$/', $locale)) {
            return $this->secureJson(['error' => $this->trans('ai.invalid_locale_format', [], 'Invalid locale format.')], 400);
        }

        try {
            $result = $this->ai->generateContent($brief, $collection, $locale);
            $this->auditService?->log(
                toolName: 'ai_generate',
                project: $project,
                input: ['collection' => $collectionSlug, 'locale' => $locale, 'max_tokens' => $maxTokens, 'temperature' => $temperature],
                output: ['success' => true],
                status: 'success',
                createdBy: $this->getUser()?->getUserIdentifier() ?? 'api_token',
                source: 'ai_controller',
            );
            $this->auditService?->flush();
            return $this->secureJson($result);
        } catch (\Throwable $e) {
            return $this->handleAiException($e);
        }
    }

    #[Route('/api/projects/{uuid}/ai/translate', name: 'ai_translate', methods: ['POST'])]
    public function translate(string $uuid, Request $request): JsonResponse
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        $contentLength = (int) $request->headers->get('Content-Length', 0);
        if ($contentLength > self::MAX_PAYLOAD_BYTES || strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson(['error' => $this->trans('ai.payload_too_large', [], 'AI payload too large (max 1MB).')], 413);
        }

        if ($contentError = $this->validateContentType($request)) {
            return $contentError;
        }

        [$project, $errorResponse] = $this->findProjectAndCheckAccess($uuid, $request, 'content.update');
        if ($errorResponse !== null) {
            return $errorResponse;
        }

        $body = json_decode($request->getContent(), true);
        if (!is_array($body)) {
            return $this->secureJson(['error' => $this->trans('ai.invalid_json', [], 'Invalid JSON payload.')], 400);
        }

        $content = $body['content'] ?? null;
        if (empty($content) || !is_array($content)) {
            return $this->secureJson(['error' => $this->trans('ai.content_required', [], 'Content is required.')], 400);
        }

        $structError = $this->validateContentStructure($content);
        if ($structError !== null) {
            return $this->secureJson(['error' => $this->trans($structError)], 400);
        }

        $encoded = json_encode($content, JSON_UNESCAPED_UNICODE);
        if ($encoded === false || strlen($encoded) > self::MAX_TEXT_LENGTH) {
            return $this->secureJson(['error' => $this->trans('ai.content_too_large', [], 'Content payload exceeds maximum allowed size.')], 400);
        }

        $targetLocale = (string) ($body['locale'] ?? 'en');
        if (!preg_match('/^[a-zA-Z]{2,4}([-_][a-zA-Z0-9]{2,8})?$/', $targetLocale)) {
            return $this->secureJson(['error' => $this->trans('ai.invalid_locale_format', [], 'Invalid locale format.')], 400);
        }

        [$maxTokens, $tokenError] = $this->resolveMaxTokens($body['max_tokens'] ?? $body['maxTokens'] ?? null);
        if ($tokenError !== null) {
            return $this->secureJson(['error' => $tokenError], 400);
        }

        if (isset($body['model']) && ($modelError = $this->validateModelName((string) $body['model']))) {
            return $this->secureJson(['error' => $modelError], 400);
        }

        try {
            $translated = $this->ai->translateContent($content, $targetLocale, $project);
            $this->auditService?->log(
                toolName: 'ai_translate',
                project: $project,
                input: ['locale' => $targetLocale, 'max_tokens' => $maxTokens],
                output: ['success' => true],
                status: 'success',
                createdBy: $this->getUser()?->getUserIdentifier() ?? 'api_token',
                source: 'ai_controller',
            );
            $this->auditService?->flush();
            return $this->secureJson(['original' => $content, 'translated' => $translated, 'locale' => $targetLocale]);
        } catch (\Throwable $e) {
            return $this->handleAiException($e);
        }
    }

    #[Route('/api/projects/{uuid}/ai/summarize', name: 'ai_summarize', methods: ['POST'])]
    public function summarize(string $uuid, Request $request): JsonResponse
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        $contentLength = (int) $request->headers->get('Content-Length', 0);
        if ($contentLength > self::MAX_PAYLOAD_BYTES || strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson(['error' => $this->trans('ai.payload_too_large', [], 'AI payload too large (max 1MB).')], 413);
        }

        if ($contentError = $this->validateContentType($request)) {
            return $contentError;
        }

        [$project, $errorResponse] = $this->findProjectAndCheckAccess($uuid, $request, 'project.view');
        if ($errorResponse !== null) {
            return $errorResponse;
        }

        $body = json_decode($request->getContent(), true);
        if (!is_array($body)) {
            return $this->secureJson(['error' => $this->trans('ai.invalid_json', [], 'Invalid JSON payload.')], 400);
        }

        $rawText = (string) ($body['text'] ?? '');
        if (trim($rawText) === '') {
            return $this->secureJson(['error' => $this->trans('ai.text_required', [], 'Text is required.')], 400);
        }

        $text = $this->sanitizePromptInput($rawText);
        $textLength = mb_strlen($text);

        if ($textLength < self::MIN_TEXT_LENGTH) {
            return $this->secureJson(['error' => $this->trans('ai.text_too_short', ['%min%' => (string) self::MIN_TEXT_LENGTH], sprintf('Text is too short (minimum %d characters).', self::MIN_TEXT_LENGTH))], 400);
        }

        if ($textLength > self::MAX_TEXT_LENGTH) {
            return $this->secureJson(['error' => $this->trans('ai.text_too_long', ['%max%' => (string) self::MAX_TEXT_LENGTH], sprintf('Text exceeds maximum allowed length (%d characters).', self::MAX_TEXT_LENGTH))], 400);
        }

        if ($this->containsDangerousPromptBreakout($text)) {
            $this->auditService?->log(
                toolName: 'ai_summarize',
                project: $project,
                input: ['text_length' => $textLength],
                output: ['error' => 'prompt_injection_detected'],
                status: 'blocked',
                createdBy: $this->getUser()?->getUserIdentifier() ?? 'api_token',
                source: 'ai_controller',
            );
            $this->auditService?->flush();
            return $this->secureJson(['error' => $this->trans('ai.prompt_injection_detected', [], 'Prompt rejected: potentially harmful prompt injection detected.')], 400);
        }

        $maxWords = min(self::MAX_WORDS, max(self::MIN_WORDS, (int) ($body['maxWords'] ?? 80)));

        [$maxTokens, $tokenError] = $this->resolveMaxTokens($body['max_tokens'] ?? $body['maxTokens'] ?? null);
        if ($tokenError !== null) {
            return $this->secureJson(['error' => $tokenError], 400);
        }

        if (isset($body['model']) && ($modelError = $this->validateModelName((string) $body['model']))) {
            return $this->secureJson(['error' => $modelError], 400);
        }

        try {
            $summary = $this->ai->summarize($text, $maxWords, $project);
            $this->auditService?->log(
                toolName: 'ai_summarize',
                project: $project,
                input: ['maxWords' => $maxWords, 'max_tokens' => $maxTokens],
                output: ['success' => true],
                status: 'success',
                createdBy: $this->getUser()?->getUserIdentifier() ?? 'api_token',
                source: 'ai_controller',
            );
            $this->auditService?->flush();
            return $this->secureJson(['summary' => $summary]);
        } catch (\Throwable $e) {
            return $this->handleAiException($e);
        }
    }

    #[Route('/api/projects/{uuid}/ai/seo', name: 'ai_seo', methods: ['POST'])]
    public function seo(string $uuid, Request $request): JsonResponse
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        $contentLength = (int) $request->headers->get('Content-Length', 0);
        if ($contentLength > self::MAX_PAYLOAD_BYTES || strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson(['error' => $this->trans('ai.payload_too_large', [], 'AI payload too large (max 1MB).')], 413);
        }

        if ($contentError = $this->validateContentType($request)) {
            return $contentError;
        }

        [$project, $errorResponse] = $this->findProjectAndCheckAccess($uuid, $request, 'project.view');
        if ($errorResponse !== null) {
            return $errorResponse;
        }

        $body = json_decode($request->getContent(), true);
        if (!is_array($body)) {
            return $this->secureJson(['error' => $this->trans('ai.invalid_json', [], 'Invalid JSON payload.')], 400);
        }

        $content = $body['content'] ?? null;
        if (empty($content) || !is_array($content)) {
            return $this->secureJson(['error' => $this->trans('ai.content_required', [], 'Content is required.')], 400);
        }

        $structError = $this->validateContentStructure($content);
        if ($structError !== null) {
            return $this->secureJson(['error' => $this->trans($structError)], 400);
        }

        $encoded = json_encode($content, JSON_UNESCAPED_UNICODE);
        if ($encoded === false || strlen($encoded) > self::MAX_TEXT_LENGTH) {
            return $this->secureJson(['error' => $this->trans('ai.content_too_large', [], 'Content payload exceeds maximum allowed size.')], 400);
        }

        [$maxTokens, $tokenError] = $this->resolveMaxTokens($body['max_tokens'] ?? $body['maxTokens'] ?? null);
        if ($tokenError !== null) {
            return $this->secureJson(['error' => $tokenError], 400);
        }

        if (isset($body['model']) && ($modelError = $this->validateModelName((string) $body['model']))) {
            return $this->secureJson(['error' => $modelError], 400);
        }

        try {
            $seoResult = $this->ai->generateSeo($content, $project);
            $this->auditService?->log(
                toolName: 'ai_seo',
                project: $project,
                input: ['fields' => array_keys($content), 'max_tokens' => $maxTokens],
                output: ['success' => true],
                status: 'success',
                createdBy: $this->getUser()?->getUserIdentifier() ?? 'api_token',
                source: 'ai_controller',
            );
            $this->auditService?->flush();
            return $this->secureJson($seoResult);
        } catch (\Throwable $e) {
            return $this->handleAiException($e, 'ai.error.seo_generation_failed');
        }
    }

    #[Route('/api/projects/{uuid}/ai/suggest-schema', name: 'ai_suggest_schema', methods: ['GET'])]
    public function suggestSchema(string $uuid, Request $request): JsonResponse
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        [$project, $errorResponse] = $this->findProjectAndCheckAccess($uuid, $request, 'project.manage');
        if ($errorResponse !== null) {
            return $errorResponse;
        }

        $collectionSlug = trim((string) $request->query->get('collection', ''));
        if ($collectionSlug === '') {
            return $this->secureJson(['error' => $this->trans('ai.collection_parameter_required', [], 'Collection parameter is required.')], 400);
        }

        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $collectionSlug)) {
            return $this->secureJson(['error' => $this->trans('ai.invalid_collection_slug', [], 'Invalid collection slug format.')], 400);
        }

        $collection = $this->em->getRepository(Collection::class)->findOneBy([
            'project' => $project,
            'slug' => $collectionSlug,
            'deletedAt' => null,
        ]);
        if (!$collection) {
            return $this->secureJson(['error' => $this->trans('ai.collection_not_found', [], 'Collection not found.')], 404);
        }

        try {
            $schema = $this->ai->suggestSchema($collection);
            $this->auditService?->log(
                toolName: 'ai_suggest_schema',
                project: $project,
                input: ['collection' => $collectionSlug],
                output: ['success' => true],
                status: 'success',
                createdBy: $this->getUser()?->getUserIdentifier() ?? 'api_token',
                source: 'ai_controller',
            );
            $this->auditService?->flush();
            return $this->secureJson($schema);
        } catch (\Throwable $e) {
            return $this->handleAiException($e);
        }
    }

    #[Route('/api/projects/{uuid}/ai/models', name: 'ai_models', methods: ['GET'])]
    public function models(string $uuid, Request $request): JsonResponse
    {
        [$project, $errorResponse] = $this->findProjectAndCheckAccess($uuid, $request, 'project.view');
        if ($errorResponse !== null) {
            return $errorResponse;
        }

        try {
            return $this->secureJson($this->ai->getAvailableModels());
        } catch (\Throwable $e) {
            return $this->handleAiException($e);
        }
    }

    #[Route('/api/projects/{uuid}/ai/ask', name: 'ai_ask', methods: ['POST'])]
    public function ask(string $uuid, Request $request): JsonResponse
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        $contentLength = (int) $request->headers->get('Content-Length', 0);
        if ($contentLength > self::MAX_PAYLOAD_BYTES || strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson(['error' => $this->trans('ai.payload_too_large', [], 'AI payload too large (max 1MB).')], 413);
        }

        if ($contentError = $this->validateContentType($request)) {
            return $contentError;
        }

        [$project, $errorResponse] = $this->findProjectAndCheckAccess($uuid, $request, 'content.create');
        if ($errorResponse !== null) {
            return $errorResponse;
        }

        $body = json_decode($request->getContent(), true);
        if (!is_array($body)) {
            return $this->secureJson(['error' => $this->trans('ai.invalid_json', [], 'Invalid JSON payload.')], 400);
        }

        $rawPrompt = (string) ($body['prompt'] ?? '');
        if (trim($rawPrompt) === '') {
            return $this->secureJson(['error' => $this->trans('ai.prompt_required', [], 'Prompt is required.')], 400);
        }

        $prompt = $this->sanitizePromptInput($rawPrompt);
        $promptLen = mb_strlen($prompt);

        if ($promptLen < self::MIN_BRIEF_LENGTH) {
            return $this->secureJson(['error' => $this->trans('ai.prompt_too_short', ['%min%' => (string) self::MIN_BRIEF_LENGTH], sprintf('Prompt is too short (minimum %d characters).', self::MIN_BRIEF_LENGTH))], 400);
        }

        if ($promptLen > self::MAX_BRIEF_LENGTH) {
            return $this->secureJson(['error' => $this->trans('ai.prompt_too_long', ['%max%' => (string) self::MAX_BRIEF_LENGTH], sprintf('Prompt exceeds maximum allowed length (%d characters).', self::MAX_BRIEF_LENGTH))], 400);
        }

        if ($this->containsDangerousPromptBreakout($prompt)) {
            $this->auditService?->log(
                toolName: 'ai_ask',
                project: $project,
                input: ['prompt_length' => $promptLen],
                output: ['error' => 'prompt_injection_detected'],
                status: 'blocked',
                createdBy: $this->getUser()?->getUserIdentifier() ?? 'api_token',
                source: 'ai_controller',
            );
            $this->auditService?->flush();
            return $this->secureJson(['error' => $this->trans('ai.prompt_injection_detected', [], 'Prompt rejected: potentially harmful prompt injection detected.')], 400);
        }

        [$maxTokens, $tokenError] = $this->resolveMaxTokens($body['max_tokens'] ?? $body['maxTokens'] ?? null);
        if ($tokenError !== null) {
            return $this->secureJson(['error' => $tokenError], 400);
        }

        [$temperature, $tempError] = $this->resolveTemperature($body['temperature'] ?? null);
        if ($tempError !== null) {
            return $this->secureJson(['error' => $tempError], 400);
        }

        $rawModel = isset($body['model']) ? (string) $body['model'] : 'gpt-4o-mini';
        if ($modelError = $this->validateModelName($rawModel)) {
            return $this->secureJson(['error' => $modelError], 400);
        }

        $rawProvider = isset($body['provider']) ? (string) $body['provider'] : null;
        if ($rawProvider !== null && ($providerError = $this->validateProviderName($rawProvider))) {
            return $this->secureJson(['error' => $providerError], 400);
        }

        try {
            $answer = $this->ai->ask($prompt, $rawModel, $rawProvider);
            $this->auditService?->log(
                toolName: 'ai_ask',
                project: $project,
                input: ['model' => $rawModel, 'max_tokens' => $maxTokens, 'temperature' => $temperature],
                output: ['success' => true],
                status: 'success',
                createdBy: $this->getUser()?->getUserIdentifier() ?? 'api_token',
                source: 'ai_controller',
            );
            $this->auditService?->flush();

            return $this->secureJson([
                'prompt'      => $prompt,
                'response'    => $answer,
                'model'       => $rawModel,
                'max_tokens'  => $maxTokens,
                'temperature' => $temperature,
            ]);
        } catch (\Throwable $e) {
            return $this->handleAiException($e);
        }
    }

    private function sanitizePromptInput(string $input): string
    {
        // Supprime les octets nuls et caractères de contrôle non imprimables (sauf tabulations, retours à la ligne)
        $sanitized = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $input) ?? '';
        // Supprime les caractères d'inversion bidirectionnelle (attaques trojan source / spoofing)
        $sanitized = preg_replace('/[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $sanitized) ?? '';
        // Neutralise les délimiteurs spéciaux de LLMs
        $sanitized = str_ireplace(
            ['<|im_start|>', '<|im_end|>', '[INST]', '[/INST]', '<<SYS>>', '<</SYS>>', '[SYSTEM]', '[/SYSTEM]'],
            ['[marker:im_start]', '[marker:im_end]', '[marker:inst]', '[marker:inst_end]', '[marker:sys]', '[marker:sys_end]', '[marker:system]', '[marker:system_end]'],
            $sanitized
        );
        return trim($sanitized);
    }

    private function containsDangerousPromptBreakout(string $input): bool
    {
        $patterns = [
            '/\bignore\s+(?:all\s+)?previous\s+instructions\b/i',
            '/\bdisregard\s+(?:all\s+)?prior\s+(?:instructions|guidelines|rules)\b/i',
            '/\byou\s+are\s+now\s+(?:in\s+)?(?:developer|dan|jailbreak)\s+mode\b/i',
            '/\b(?:reveal|output|display|show)\s+(?:your\s+)?system\s+prompt\b/i',
            '/\bforget\s+(?:all\s+)?(?:safety|system)\s+(?:rules|instructions)\b/i',
            '/\bbypass\s+(?:content\s+)?filters\b/i',
            '/\bact\s+as\s+unrestricted\b/i',
            '/\bdo\s+anything\s+now\b/i',
            '/\bsudo\s+mode\b/i',
            '/\boverride\s+(?:system|safety)\s+protocols\b/i',
            '/\bjailbreak\b/i',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $input)) {
                return true;
            }
        }
        return false;
    }

    private function validateContentStructure(mixed $content, int $currentDepth = 0): ?string
    {
        if (!is_array($content)) {
            return 'ai.invalid_content_format';
        }
        if ($currentDepth > self::MAX_CONTENT_DEPTH) {
            return 'ai.content_depth_exceeded';
        }
        if (count($content) > self::MAX_CONTENT_KEYS) {
            return 'ai.content_too_many_keys';
        }
        foreach ($content as $key => $value) {
            if (is_string($key) && mb_strlen($key) > 255) {
                return 'ai.content_key_too_long';
            }
            if (is_array($value)) {
                $err = $this->validateContentStructure($value, $currentDepth + 1);
                if ($err !== null) {
                    return $err;
                }
            } elseif (is_string($value)) {
                if (mb_strlen($value) > self::MAX_TEXT_LENGTH) {
                    return 'ai.content_value_too_large';
                }
            }
        }
        return null;
    }

    private function handleAiException(\Throwable $e, ?string $fallbackKey = null): JsonResponse
    {
        $msg = $e->getMessage();
        $lower = strtolower($msg);

        if ($e instanceof TransportExceptionInterface || str_contains($lower, 'timeout') || str_contains($lower, 'timed out')) {
            return $this->secureJson([
                'error' => $this->trans('ai.error.timeout', [], 'The AI service timed out. Please try again later.'),
            ], 504);
        }

        if (str_contains($lower, 'rate limit') || str_contains($lower, '429') || str_contains($lower, 'too many requests')) {
            return $this->secureJson([
                'error' => $this->trans('ai.error.upstream_rate_limited', [], 'AI upstream rate limit reached. Please retry later.'),
            ], 429);
        }

        if (str_contains($lower, 'aucun fournisseur') || str_contains($lower, 'no provider')) {
            return $this->secureJson([
                'error' => $this->trans('ai.error.no_provider', [], 'No AI provider is configured or available.'),
            ], 503);
        }

        $key = $fallbackKey ?? 'ai.error.execution_failed';
        return $this->secureJson([
            'error' => $this->trans($key, [], 'An error occurred during AI processing.'),
        ], 422);
    }

    private function checkRateLimit(Request $request): ?JsonResponse
    {
        if ($this->aiLimiter !== null) {
            $identifier = $request->getClientIp() ?: '127.0.0.1';
            try {
                $user = $this->getUser();
                if ($user !== null) {
                    $identifier = $user->getUserIdentifier();
                }
            } catch (\Throwable) {}

            if (!$this->aiLimiter->create($identifier)->consume(1)->isAccepted()) {
                $this->auditService?->log(
                    toolName: 'ai_rate_limit',
                    project: null,
                    input: ['identifier' => $identifier],
                    output: ['error' => 'rate_limit_exceeded'],
                    status: 'blocked',
                    createdBy: $identifier,
                    source: 'ai_controller',
                );
                $this->auditService?->flush();
                return $this->secureJson([
                    'error' => $this->trans('ai.rate_limit_exceeded', [], 'Too many AI requests. Please retry later.')
                ], 429);
            }
        }
        return null;
    }

    /**
     * @return array{0: ?Project, 1: ?JsonResponse}
     */
    private function findProjectAndCheckAccess(string $uuid, Request $request, string $attribute): array
    {
        if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $uuid)) {
            return [null, $this->secureJson(['error' => $this->trans('ai.project_not_found', [], 'Project not found.')], 404)];
        }

        $project = $this->findProject($uuid);
        if (!$project) {
            return [null, $this->secureJson(['error' => $this->trans('ai.project_not_found', [], 'Project not found.')], 404)];
        }

        // Cloisonnement strict multi-tenant
        $attrProject = $request->attributes->get('_project');
        if ($attrProject instanceof Project && $attrProject->uuid?->toRfc4122() !== $project->uuid?->toRfc4122()) {
            $this->auditService?->log(
                toolName: 'ai_tenant_isolation',
                project: $project,
                input: ['requested_uuid' => $uuid],
                output: ['error' => 'token_project_mismatch'],
                status: 'blocked',
                createdBy: $this->getUser()?->getUserIdentifier() ?? 'api_token',
                source: 'ai_controller',
            );
            $this->auditService?->flush();
            return [null, $this->secureJson(['error' => $this->trans('ai.token_project_mismatch', [], 'Forbidden: token belongs to another project.')], 403)];
        }

        $token = $this->tokenChecker?->resolve($request);
        if ($token !== null) {
            if ($token->project->uuid?->toRfc4122() !== $project->uuid?->toRfc4122()) {
                $this->auditService?->log(
                    toolName: 'ai_tenant_isolation',
                    project: $project,
                    input: ['requested_uuid' => $uuid],
                    output: ['error' => 'token_project_mismatch'],
                    status: 'blocked',
                    createdBy: $this->getUser()?->getUserIdentifier() ?? 'api_token',
                    source: 'ai_controller',
                );
                $this->auditService?->flush();
                return [null, $this->secureJson(['error' => $this->trans('ai.token_project_mismatch', [], 'Forbidden: token belongs to another project.')], 403)];
            }
            $requiredAbility = ($attribute === 'project.view' || $attribute === 'project.read') ? 'read' : 'write';
            if (!$token->can('*') && !$token->can($requiredAbility)) {
                $this->auditService?->log(
                    toolName: 'ai_permissions',
                    project: $project,
                    input: ['requested_uuid' => $uuid, 'required' => $requiredAbility],
                    output: ['error' => 'token_missing_ability'],
                    status: 'blocked',
                    createdBy: $this->getUser()?->getUserIdentifier() ?? 'api_token',
                    source: 'ai_controller',
                );
                $this->auditService?->flush();
                return [null, $this->secureJson(['error' => $this->trans('ai.token_missing_ability', [], 'Forbidden: token missing required ability.')], 403)];
            }
        } else {
            if ($request->attributes->get('_project') !== $project) {
                $this->denyAccessUnlessGranted($attribute, $project);
            }
        }

        return [$project, null];
    }

    private function findProject(string $uuid): ?Project
    {
        try {
            return $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        } catch (\Throwable) {
            return null;
        }
    }
}
