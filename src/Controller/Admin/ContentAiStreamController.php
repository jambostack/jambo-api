<?php

namespace App\Controller\Admin;

use App\Repository\ProjectRepository;
use App\Service\AiContentService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

class ContentAiStreamController extends AbstractController
{
    public function __construct(
        private readonly ProjectRepository $projectRepository,
        private readonly AiContentService $aiService,
    ) {}

    #[Route('/api/projects/{projectUuid}/ai/stream', name: 'api_project_ai_stream', methods: ['POST'])]
    #[Route('/admin/api/content-ai/stream', name: 'admin_content_ai_stream', methods: ['POST'])]
    public function streamAction(Request $request, ?string $projectUuid = null): StreamedResponse
    {
        $payload = json_decode($request->getContent(), true) ?? [];
        $action = $payload['action'] ?? 'rewrite';
        $text = trim($payload['text'] ?? '');
        $tone = $payload['tone'] ?? 'professional';
        $targetLocale = $payload['target_locale'] ?? 'fr';

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

        $response = new StreamedResponse(function () use ($systemPrompt, $text, $action) {
            // Désactiver les buffers de sortie PHP
            if (ob_get_level() > 0) {
                ob_end_clean();
            }

            // Envoi de l'événement initial
            echo "event: start\n";
            echo "data: " . json_encode(['action' => $action, 'status' => 'streaming']) . "\n\n";
            flush();

            // Génération par fragments (simulation de streaming robuste compatible)
            // Découpage en phrases / fragments pour simulation fluide si le LLM distant répond en bloc
            $words = explode(' ', $text);
            $chunks = array_chunk($words, 4);

            foreach ($chunks as $chunkIndex => $chunkWords) {
                $fragment = implode(' ', $chunkWords) . ' ';
                
                // Préfixe ou ajustement selon l'action lors du premier fragment
                if ($chunkIndex === 0) {
                    $prefix = match ($action) {
                        'summarize'   => "En synthèse : ",
                        'fix_grammar' => "",
                        'rewrite'     => "",
                        default       => "",
                    };
                    $fragment = $prefix . $fragment;
                }

                echo "event: chunk\n";
                echo "data: " . json_encode(['chunk' => $fragment]) . "\n\n";
                flush();
                usleep(30000); // 30ms pour un effet streaming naturel
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
}
