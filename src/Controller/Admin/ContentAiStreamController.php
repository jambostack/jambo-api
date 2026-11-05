<?php

namespace App\Controller\Admin;

use App\Repository\ProjectRepository;
use App\Service\AiContentService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Translation\TranslatorInterface;

class ContentAiStreamController extends AbstractController
{
    public const MAX_INPUT_LENGTH = 30000;
    public const MIN_INPUT_LENGTH = 3;
    public const ALLOWED_ACTIONS = ['summarize', 'expand', 'rewrite', 'fix_grammar', 'translate', 'improve'];

    private const UUID_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    private const STREAM_HEADERS = [
        'Content-Type'           => 'text/event-stream',
        'Cache-Control'          => 'no-cache, no-store, must-revalidate',
        'Connection'             => 'keep-alive',
        'X-Accel-Buffering'      => 'no',
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options'        => 'DENY',
    ];

    public function __construct(
        private readonly ProjectRepository $projectRepository,
        private readonly AiContentService $aiService,
        private readonly ?AuthorizationCheckerInterface $authChecker = null,
        #[Autowire(service: 'limiter.ai_stream_limiter')]
        private readonly ?RateLimiterFactory $aiStreamLimiter = null,
        private readonly ?TranslatorInterface $translator = null,
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

    #[Route('/api/projects/{projectUuid}/ai/stream', name: 'api_project_ai_stream', methods: ['POST'])]
    #[Route('/admin/api/content-ai/stream', name: 'admin_content_ai_stream', methods: ['POST'])]
    public function streamAction(Request $request, ?string $projectUuid = null): StreamedResponse
    {
        if ($this->aiStreamLimiter !== null) {
            $identifier = $request->getClientIp() ?: '127.0.0.1';
            try {
                $user = $this->getUser();
                if ($user !== null) {
                    $identifier = $user->getUserIdentifier();
                }
            } catch (\Throwable) {}

            if (!$this->aiStreamLimiter->create($identifier)->consume(1)->isAccepted()) {
                return new StreamedResponse(function () {
                    echo "data: " . json_encode(['error' => $this->trans('ai_stream.rate_limit_exceeded', [], 'Too many AI stream requests. Please retry later.')]) . "\n\n";
                    flush();
                }, 429, self::STREAM_HEADERS);
            }
        }

        if ($projectUuid !== null) {
            if (!preg_match(self::UUID_REGEX, $projectUuid)) {
                return new StreamedResponse(function () {
                    echo "data: " . json_encode(['error' => $this->trans('ai_stream.project_not_found', [], 'Project not found')]) . "\n\n";
                    flush();
                }, 404, self::STREAM_HEADERS);
            }

            try {
                $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
            } catch (\Throwable) {
                $project = null;
            }
            if ($project === null) {
                return new StreamedResponse(function () {
                    echo "data: " . json_encode(['error' => $this->trans('ai_stream.project_not_found', [], 'Project not found')]) . "\n\n";
                    flush();
                }, 404, self::STREAM_HEADERS);
            }
            if ($request->attributes->get('_project') !== $project) {
                $this->checkAccess('content.create', $project);
            }
        } else {
            $this->checkAccess('IS_AUTHENTICATED_FULLY');
        }

        $payload = json_decode($request->getContent(), true) ?? [];
        $rawAction = (string) ($payload['action'] ?? 'rewrite');
        $action = in_array($rawAction, self::ALLOWED_ACTIONS, true) ? $rawAction : 'rewrite';

        $rawText = trim((string) ($payload['text'] ?? ''));
        if ($rawText === '') {
            return new StreamedResponse(function () {
                echo "data: " . json_encode(['error' => $this->trans('ai_stream.input_empty', [], 'Input text is empty')]) . "\n\n";
                flush();
            }, 400, self::STREAM_HEADERS);
        }

        $text = $this->sanitizePromptInput($rawText);

        if (mb_strlen($text) > self::MAX_INPUT_LENGTH) {
            return new StreamedResponse(function () {
                echo "data: " . json_encode(['error' => $this->trans('ai_stream.input_too_long', ['%max%' => '30,000'], 'Input text exceeds maximum allowed length of 30,000 characters.')]) . "\n\n";
                flush();
            }, 400, self::STREAM_HEADERS);
        }

        if ($this->containsDangerousPromptBreakout($text)) {
            return new StreamedResponse(function () {
                echo "data: " . json_encode(['error' => $this->trans('ai_stream.prompt_injection_detected', [], 'Prompt rejected: potentially harmful prompt injection detected.')]) . "\n\n";
                flush();
            }, 400, self::STREAM_HEADERS);
        }

        $rawTone = (string) ($payload['tone'] ?? 'professional');
        $tone = (preg_match('/^[a-zA-Z0-9_\- ]{1,50}$/', $rawTone)) ? $rawTone : 'professional';

        $rawLocale = (string) ($payload['target_locale'] ?? 'fr');
        $targetLocale = (preg_match('/^[a-zA-Z]{2,4}([-_][a-zA-Z0-9]{2,8})?$/', $rawLocale)) ? $rawLocale : 'fr';

        $provider = isset($payload['provider']) ? (string) $payload['provider'] : null;
        if ($provider !== null && !preg_match('/^[a-zA-Z0-9_\-\.]{1,60}$/', $provider)) {
            $provider = null;
        }

        $rawModel = (string) ($payload['model'] ?? 'gpt-4o-mini');
        $model = (preg_match('/^[a-zA-Z0-9_\-\.\/]{1,80}$/', $rawModel)) ? $rawModel : 'gpt-4o-mini';

        $systemPrompt = match ($action) {
            'summarize'   => "Résume le texte suivant de manière claire, concise et percutante :",
            'expand'      => "Développe et enrichis le texte suivant avec des détails pertinents et un style soigné :",
            'rewrite'     => "Reformule le texte suivant avec un ton {$tone} tout en conservant son sens initial :",
            'fix_grammar' => "Corrige la grammaire, la syntaxe et l'orthographe du texte suivant sans altérer son style :",
            'translate'   => "Traduis fidèlement le texte suivant dans la langue cible '{$targetLocale}' :",
            default       => "Améliore et perfectionne le texte suivant :",
        };

        $fullPrompt = $systemPrompt . "\n\n" . $text;

        $response = new StreamedResponse(function () use ($fullPrompt, $action, $provider, $model) {
            if (PHP_SAPI !== 'cli') {
                while (ob_get_level() > 0) {
                    @ob_end_flush();
                }
            }

            // Envoi de l'événement initial
            echo "event: start\n";
            echo "data: " . json_encode(['action' => $action, 'status' => 'streaming']) . "\n\n";
            flush();

            try {
                $this->aiService->streamPrompt(
                    prompt: $fullPrompt,
                    onChunk: function (string $token) {
                        if ($token !== '') {
                            echo "event: chunk\n";
                            echo "data: " . json_encode(['chunk' => $token]) . "\n\n";
                            flush();
                        }
                    },
                    provider: $provider,
                    model: $model,
                );
            } catch (\Throwable $e) {
                echo "event: error\n";
                $errMessage = $this->trans('ai_stream.stream_error', ['%message%' => $e->getMessage()], $e->getMessage());
                echo "data: " . json_encode(['error' => $errMessage]) . "\n\n";
                flush();
            }

            echo "event: done\n";
            echo "data: " . json_encode(['done' => true]) . "\n\n";
            flush();
        });

        foreach (self::STREAM_HEADERS as $headerName => $headerVal) {
            $response->headers->set($headerName, $headerVal);
        }

        return $response;
    }

    private function sanitizePromptInput(string $input): string
    {
        // Supprime les octets nuls et caractères de contrôle non imprimables
        $sanitized = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $input) ?? '';
        // Supprime les caractères d'inversion bidirectionnelle (attaques Trojan Source)
        $sanitized = preg_replace('/[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $sanitized) ?? '';
        // Neutralise les marqueurs de délimitation de contexte LLM
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
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $input)) {
                return true;
            }
        }
        return false;
    }

    private function checkAccess(mixed $attribute, mixed $subject = null): void
    {
        if ($this->authChecker !== null) {
            if (!$this->authChecker->isGranted($attribute, $subject)) {
                throw new AccessDeniedException('Access Denied.');
            }
            return;
        }

        if (isset($this->container) && $this->container->has('security.authorization_checker')) {
            $this->denyAccessUnlessGranted($attribute, $subject);
        }
    }
}
