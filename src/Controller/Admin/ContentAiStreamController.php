<?php

namespace App\Controller\Admin;

use App\Repository\ProjectRepository;
use App\Service\AiContentService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class ContentAiStreamController extends AbstractController
{
    public function __construct(
        private readonly ProjectRepository $projectRepository,
        private readonly AiContentService $aiService,
        private readonly ?AuthorizationCheckerInterface $authChecker = null,
    ) {}

    #[Route('/api/projects/{projectUuid}/ai/stream', name: 'api_project_ai_stream', methods: ['POST'])]
    #[Route('/admin/api/content-ai/stream', name: 'admin_content_ai_stream', methods: ['POST'])]
    public function streamAction(Request $request, ?string $projectUuid = null): StreamedResponse
    {
        if ($projectUuid !== null) {
            $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
            if ($project === null) {
                return new StreamedResponse(function () {
                    echo "data: " . json_encode(['error' => 'Project not found']) . "\n\n";
                    flush();
                }, 404, ['Content-Type' => 'text/event-stream']);
            }
            if ($request->attributes->get('_project') !== $project) {
                $this->checkAccess('content.create', $project);
            }
        } else {
            $this->checkAccess('IS_AUTHENTICATED_FULLY');
        }

        $payload = json_decode($request->getContent(), true) ?? [];
        $action = $payload['action'] ?? 'rewrite';
        $text = trim($payload['text'] ?? '');
        $tone = $payload['tone'] ?? 'professional';
        $targetLocale = $payload['target_locale'] ?? 'fr';
        $provider = $payload['provider'] ?? null;
        $model = $payload['model'] ?? 'gpt-4o-mini';

        if ($text === '') {
            return new StreamedResponse(function () {
                echo "data: " . json_encode(['error' => 'Input text is empty']) . "\n\n";
                flush();
            }, 400, ['Content-Type' => 'text/event-stream']);
        }

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
                echo "data: " . json_encode(['error' => $e->getMessage()]) . "\n\n";
                flush();
            }

            echo "event: done\n";
            echo "data: " . json_encode(['done' => true]) . "\n\n";
            flush();
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache, no-transform');
        $response->headers->set('Connection', 'keep-alive');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
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
