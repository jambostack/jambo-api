<?php

namespace App\Controller;

use App\Entity\Collection;
use App\Entity\Field;
use App\Entity\Project;
use App\Repository\AppSettingsRepository;
use App\Repository\ProjectRepository;
use App\Repository\StudioChatMessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
class StudioController extends InertiaController
{
    public function __construct(
        private EntityManagerInterface $em,
        private ProjectRepository $projectRepository,
        private AppSettingsRepository $appSettingsRepository,
        private HttpClientInterface $httpClient,
        private readonly StudioChatMessageRepository $chatMessageRepository,
        private LoggerInterface $logger = new \Psr\Log\NullLogger(),
    ) {}

    /**
     * Render the Jambo Studio page via Inertia.
     */
    #[Route('/projects/{project}/settings/studio', name: 'studio_page', requirements: ['project' => '\d+'], priority: 10)]
    public function index(int $project, Request $request): Response
    {
        $project = $this->projectRepository->find($project);
        if (!$project) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted('project.view', $project);

        $collections = $this->em->getRepository(Collection::class)
            ->findBy(['project' => $project, 'deletedAt' => null], ['order' => 'ASC']);

        $collectionsData = array_map(function (Collection $c) {
            $fields = [];
            foreach ($c->fields as $f) {
                if ($f->isDeleted()) continue;
                $fields[] = [
                    'name'       => $f->name,
                    'slug'       => $f->slug,
                    'type'       => $f->type,
                    'isRequired' => $f->isRequired,
                    'options'    => $f->options,
                    'order'      => $f->order,
                ];
            }

            return [
                'id'          => $c->id,
                'uuid'        => $c->uuid?->toRfc4122(),
                'name'        => $c->name,
                'slug'        => $c->slug,
                'description' => $c->description,
                'isSingleton' => $c->isSingleton,
                'fields'      => $fields,
            ];
        }, $collections);

        return $this->inertia($request, 'Projects/Settings/Studio/layout', [
            'project'     => [
                'id'   => $project->id,
                'uuid' => $project->uuid->toRfc4122(),
                'name' => $project->name,
            ],
            'collections' => $collectionsData,
            'userCan'     => [],
        ]);
    }

    /**
     * Retourne les collections existantes pour le Schema Builder (format JSON).
     */
    #[Route('/api/projects/{uuid}/studio/collections', name: 'studio_collections_list', methods: ['GET'])]
    public function listCollections(string $uuid): JsonResponse
    {
        $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        if (!$project) return $this->json(['error' => 'Projet introuvable'], 404);
        $this->denyAccessUnlessGranted('project.view', $project);

        $collections = $this->em->getRepository(Collection::class)
            ->findBy(['project' => $project, 'deletedAt' => null], ['order' => 'ASC']);

        $data = array_map(function (Collection $c) {
            $fields = [];
            foreach ($c->fields as $f) {
                if ($f->isDeleted()) continue;
                $fields[] = [
                    'name' => $f->name, 'slug' => $f->slug, 'type' => $f->type,
                    'isRequired' => $f->isRequired, 'options' => $f->options, 'order' => $f->order,
                ];
            }
            return [
                'id' => $c->id, 'name' => $c->name, 'slug' => $c->slug,
                'description' => $c->description, 'isSingleton' => $c->isSingleton,
                'fields' => $fields,
            ];
        }, $collections);

        return $this->json(['data' => $data]);
    }

    /**
     * Génère un schéma de collection via IA à partir d'un prompt.
     */
    #[Route('/api/projects/{uuid}/studio/ai-schema', name: 'studio_ai_schema', methods: ['POST'])]
    public function aiSchema(string $uuid, Request $request): JsonResponse
    {
        $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        if (!$project) return $this->json(['error' => 'Projet introuvable'], 404);
        $this->denyAccessUnlessGranted('project.manage', $project);

        $body = json_decode($request->getContent(), true);
        $prompt = trim((string) ($body['prompt'] ?? ''));
        if ($prompt === '') return $this->json(['error' => 'Prompt requis'], 422);

        $systemPrompt = <<<PROMPT
You are a CMS schema designer. Given a user's description, generate a list of collections with their fields.

- Return ONLY valid JSON (no markdown, no explanation).
- Use this exact structure:
{
  "collections": [
    {
      "name": "Blog Posts",
      "slug": "blog_posts",
      "description": "Blog articles",
      "isSingleton": false,
      "fields": [
        { "name": "Title", "slug": "title", "type": "text", "isRequired": true },
        { "name": "Content", "slug": "content", "type": "richtext", "isRequired": true }
      ]
    }
  ]
}

- Field types available: text, longtext, richtext, slug, email, password, number, decimal, boolean, date, datetime, time, color, json, enumeration, media, relation
- Choose appropriate types based on the field name and context.
- For slug fields, mark them as required and use type "slug".
- For dates, use "date". For timestamps, use "datetime".
- If the user mentions "images" or "photos", use "media".
- Generate reasonable field names with proper slugs.
- Make collection names descriptive (plural for collections, singular for singletons).
- If the user describes a single page (about, contact), mark isSingleton: true.
- Generate at least 3-5 fields per collection that make sense for the domain.
- Maximum 5 collections. Maximum 10 fields per collection.
PROMPT;

        [$provider, $apiKey, $model, $endpoint] = $this->resolveAiConfig();

        if ($provider === null) {
            // Fallback: génération basée sur des règles (pas d'IA configurée)
            return $this->json($this->ruleBasedSchema($prompt));
        }

        try {
            $content = $this->callAiApi($provider, $apiKey, $model, $endpoint, [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $prompt],
            ]);

            // Extraire le JSON (le modèle peut wrapper dans ```json)
            if (preg_match('/\{[\s\S]*\}/', $content, $m)) {
                $data = json_decode($m[0], true);
                if ($data && isset($data['collections'])) {
                    return $this->json($data);
                }
            }

            return $this->json(['error' => 'Échec du parsing JSON de la réponse IA'], 500);
        } catch (\Throwable $e) {
            $this->logger->error('AI schema generation failed', [
                'exception' => $e,
                'project'   => $uuid,
            ]);
            return $this->json(['error' => 'Échec de la génération IA. Réessayez.'], 500);
        }
    }

    /**
     * Chat IA conversationnel pour générer/modifier le schéma de collections.
     * Reçoit l'historique de conversation + le contexte des collections existantes.
     */
    #[Route('/api/projects/{uuid}/studio/ai-chat', name: 'studio_ai_chat', methods: ['POST'])]
    public function aiChat(string $uuid, Request $request): JsonResponse
    {
        $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        if (!$project) return $this->json(['error' => 'Projet introuvable'], 404);
        $this->denyAccessUnlessGranted('project.manage', $project);

        $body = json_decode($request->getContent(), true);
        $prompt  = trim((string) ($body['prompt'] ?? ''));
        $context = trim((string) ($body['context'] ?? ''));
        $history = is_array($body['history'] ?? null) ? $body['history'] : [];

        if ($prompt === '') return $this->json(['error' => 'Prompt requis'], 422);

        // Persister le message utilisateur en DB
        $userMsg = new \App\Entity\StudioChatMessage();
        $userMsg->project = $project;
        $userMsg->role = 'user';
        $userMsg->content = $prompt;
        $this->em->persist($userMsg);

        $systemPrompt = <<<PROMPT
You are a CMS schema architect. You help users design their content model by creating and modifying collections.

## Rules
- Always respond in French (the user speaks French).
- First, acknowledge what the user asked. Then if applicable, propose a schema.
- When proposing a schema, include it as a valid JSON object in your response, wrapped in a ```json code block.
- The JSON must use this exact structure:
```json
{
  "collections": [
    {
      "name": "Blog Posts",
      "slug": "blog_posts",
      "description": "Blog articles",
      "isSingleton": false,
      "fields": [
        { "name": "Title", "slug": "title", "type": "text", "isRequired": true },
        { "name": "Content", "slug": "content", "type": "richtext", "isRequired": true }
      ]
    }
  ]
}
```

## Field types available
text, longtext, richtext, slug, email, password, number, decimal, boolean, date, datetime, time, color, json, enumeration, media, relation

## Guidelines
- Use "slug" type for URL-friendly identifiers (mark them required).
- Use "media" for images/photos/files.
- Use "richtext" for rich content (HTML/WYSIWYG).
- Use "date" for dates, "datetime" for timestamps.
- Collection names: plural for collections, singular for singletons (isSingleton: true).
- If the user asks to modify an existing collection, reference it by name.
- If the user asks to add fields, include the modified collection with the new fields.
- Generate 3-5 fields per collection minimum.
- Maximum 5 collections per response. Maximum 10 fields per collection.

## Current project context
$context
PROMPT;

        [$provider, $apiKey, $model, $endpoint] = $this->resolveAiConfig();
        if ($provider === null) {
            // Fallback: utiliser ruleBasedSchema pour générer, puis répondre textuellement
            $schema = $this->ruleBasedSchema($prompt);
            $names = array_map(fn ($c) => $c['name'], $schema['collections'] ?? []);
            $reply = $names === []
                ? "Je n'ai pas pu générer de schéma pour cette demande. Peux-tu être plus précis ?"
                : "Voici un schéma de base pour : " . implode(', ', $names) . ". Tu peux l'appliquer et le modifier manuellement.";
            return $this->json(['reply' => $reply, 'collections' => $schema['collections'] ?? []]);
        }

        try {
            // Construire les messages pour le provider IA
            $msgs = [['role' => 'system', 'content' => $systemPrompt]];
            foreach ($history as $h) {
                $role = ($h['role'] === 'assistant' || $h['role'] === 'user') ? $h['role'] : 'user';
                $msgs[] = ['role' => $role, 'content' => (string) $h['content']];
            }
            $msgs[] = ['role' => 'user', 'content' => $prompt];

            $content = $this->callAiApi($provider, $apiKey, $model, $endpoint, $msgs);

            // Extraire le JSON éventuel de la réponse
            $collections = null;
            $reply = $content;

            // Pattern 1: JSON dans un bloc de code ```json ... ```
            if (preg_match('/```(?:json)?\s*(\{[\s\S]*?"collections"[\s\S]*?\})\s*```/', $content, $m)) {
                $data = json_decode($m[1], true);
                if ($data && isset($data['collections'])) {
                    $collections = $data['collections'];
                }
                // Nettoyer TOUT le bloc de code de la réponse
                $reply = trim(preg_replace('/```(?:json)?\s*\{[\s\S]*?"collections"[\s\S]*?\}\s*```/', '', $content));
            }
            // Pattern 2: JSON inline (entre accolades, non-greedy)
            elseif (preg_match('/\{[^{]*"collections"[^}]*\}/s', $content, $m)) {
                // Essayer de parser avec une reconstruction progressive si le premier match est trop court
                $data = json_decode($m[0], true);
                if ($data && isset($data['collections'])) {
                    $collections = $data['collections'];
                } else {
                    // Tentative plus large: JSON multi-ligne
                    if (preg_match('/\{(?:[^{}]|(?:\{[^{}]*\}))*"collections"(?:[^{}]|(?:\{[^{}]*\}))*\}/s', $content, $m2)) {
                        $data = json_decode($m2[0], true);
                        if ($data && isset($data['collections'])) {
                            $collections = $data['collections'];
                        }
                    }
                }
                // Nettoyer le JSON inline de la réponse
                if ($collections !== null && isset($m[0])) {
                    $reply = trim(str_replace($m[0], '', $content));
                }
            }

            // Nettoyer les fragments de markdown résiduels
            $reply = trim(preg_replace('/```\s*$/', '', $reply));

            $replyText = $reply !== '' ? $reply : ($collections !== null ? 'Schéma généré. Applique-le ci-dessous.' : 'Je ne peux pas générer de schéma pour cette demande. Peux-tu être plus précis ?');

            // Persister le message assistant en DB
            $assistantMsg = new \App\Entity\StudioChatMessage();
            $assistantMsg->project = $project;
            $assistantMsg->role = 'assistant';
            $assistantMsg->content = $replyText;
            $assistantMsg->schema = $collections;
            $this->em->persist($assistantMsg);
            $this->em->flush();

            return $this->json([
                'reply'       => $replyText,
                'collections' => $collections,
            ]);
        } catch (\Throwable $e) {
            $this->em->flush(); // Sauvegarder le message utilisateur même en cas d'échec
            $this->logger->error('AI chat failed', ['exception' => $e, 'project' => $uuid]);
            return $this->json(['reply' => 'Désolé, une erreur est survenue. Réessaie.', 'error' => 'AI chat failed'], 500);
        }
    }

    /**
     * Retourne l'historique des messages de chat pour le Studio.
     */
    #[Route('/api/projects/{uuid}/studio/chat-messages', name: 'studio_chat_messages_list', methods: ['GET'])]
    public function listChatMessages(string $uuid): JsonResponse
    {
        $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        if (!$project) return $this->json(['error' => 'Projet introuvable'], 404);
        $this->denyAccessUnlessGranted('project.view', $project);

        $messages = $this->chatMessageRepository->findByProject($project);

        return $this->json([
            'data' => array_map(fn (\App\Entity\StudioChatMessage $m) => [
                'id'      => $m->id,
                'role'    => $m->role,
                'content' => $m->content,
                'schema'  => $m->schema,
                'created_at' => $m->createdAt->format(\DateTimeInterface::ATOM),
            ], $messages),
        ]);
    }

    /**
     * Efface tout l'historique des messages de chat pour le Studio.
     */
    #[Route('/api/projects/{uuid}/studio/chat-messages', name: 'studio_chat_messages_clear', methods: ['DELETE'])]
    public function clearChatMessages(string $uuid): JsonResponse
    {
        $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        if (!$project) return $this->json(['error' => 'Projet introuvable'], 404);
        $this->denyAccessUnlessGranted('project.manage', $project);

        $this->chatMessageRepository->deleteByProject($project);

        return $this->json(['success' => true]);
    }

    /**
     * Résout le premier provider IA activé depuis AppSettings (DB).
     * Retourne [providerName, apiKey, model, endpoint] ou [null, null, null, null].
     */
    private function resolveAiConfig(): array
    {
        $config = $this->appSettingsRepository->getOrCreate()->aiProviders ?? [];

        $providers = [
            'openai' => [
                'key'      => $config['openai']['key'] ?? '',
                'model'    => $config['openai']['model'] ?? 'gpt-4o',
                'endpoint' => 'https://api.openai.com/v1/chat/completions',
            ],
            'anthropic' => [
                'key'      => $config['anthropic']['key'] ?? '',
                'model'    => $config['anthropic']['model'] ?? 'claude-sonnet-4-6',
                'endpoint' => 'https://api.anthropic.com/v1/messages',
            ],
            'deepseek' => [
                'key'      => $config['deepseek']['key'] ?? '',
                'model'    => $config['deepseek']['model'] ?? 'deepseek-chat',
                'endpoint' => 'https://api.deepseek.com/chat/completions',
            ],
            'ollama' => [
                'key'      => '',
                'model'    => $config['ollama']['model'] ?? 'llama3.2',
                'endpoint' => ($config['ollama']['url'] ?? 'http://localhost:11434') . '/v1/chat/completions',
            ],
        ];

        foreach ($providers as $name => $cfg) {
            if (!empty($config[$name]['enabled']) && ($cfg['key'] !== '' || $name === 'ollama')) {
                return [$name, $cfg['key'], $cfg['model'], $cfg['endpoint']];
            }
        }

        return [null, null, null, null];
    }

    /**
     * Appelle l'API du provider IA avec les messages donnés.
     * Utilise HttpClientInterface avec la clé API depuis AppSettings (DB).
     */
    private function callAiApi(string $provider, string $apiKey, string $model, string $endpoint, array $messages): string
    {
        $headers = ['Content-Type' => 'application/json'];

        if ($provider === 'anthropic') {
            $headers['x-api-key'] = $apiKey;
            $headers['anthropic-version'] = '2023-06-01';
            // Anthropic utilise un format différent
            $systemMsg = '';
            $bodyMessages = [];
            foreach ($messages as $m) {
                if ($m['role'] === 'system') { $systemMsg = $m['content']; continue; }
                $bodyMessages[] = ['role' => $m['role'], 'content' => $m['content']];
            }
            $body = ['model' => $model, 'max_tokens' => 2048, 'messages' => $bodyMessages];
            if ($systemMsg !== '') $body['system'] = $systemMsg;
        } else {
            $headers['Authorization'] = 'Bearer ' . $apiKey;
            $body = ['model' => $model, 'messages' => $messages, 'temperature' => 0.7];
        }

        $response = $this->httpClient->request('POST', $endpoint, [
            'headers' => $headers,
            'json' => $body,
            'timeout' => 60,
        ]);

        $data = $response->toArray();

        // Extraire le contenu selon le format du provider
        if ($provider === 'anthropic') {
            return $data['content'][0]['text'] ?? '';
        }
        // OpenAI / DeepSeek / Ollama (format compatible OpenAI)
        return $data['choices'][0]['message']['content'] ?? '';
    }

    /** Schema basé sur des règles (fallback sans IA). */
    private function ruleBasedSchema(string $prompt): array
    {
        $lower = mb_strtolower($prompt);
        $collections = [];

        // Blog detection
        if (str_contains($lower, 'blog') || str_contains($lower, 'article')) {
            $collections[] = [
                'name' => 'Blog Posts', 'slug' => 'blog_posts', 'description' => 'Blog articles',
                'isSingleton' => false,
                'fields' => [
                    ['name' => 'Title', 'slug' => 'title', 'type' => 'text', 'isRequired' => true],
                    ['name' => 'Slug', 'slug' => 'slug', 'type' => 'slug', 'isRequired' => true],
                    ['name' => 'Content', 'slug' => 'content', 'type' => 'richtext', 'isRequired' => true],
                    ['name' => 'Featured Image', 'slug' => 'featured_image', 'type' => 'media', 'isRequired' => false],
                    ['name' => 'Published Date', 'slug' => 'published_date', 'type' => 'date', 'isRequired' => false],
                ],
            ];
            if (str_contains($lower, 'categor')) {
                $collections[] = [
                    'name' => 'Categories', 'slug' => 'categories', 'description' => 'Blog categories',
                    'isSingleton' => false,
                    'fields' => [
                        ['name' => 'Name', 'slug' => 'name', 'type' => 'text', 'isRequired' => true],
                        ['name' => 'Slug', 'slug' => 'slug', 'type' => 'slug', 'isRequired' => true],
                        ['name' => 'Description', 'slug' => 'description', 'type' => 'longtext', 'isRequired' => false],
                    ],
                ];
            }
        }

        // E-commerce detection
        if (str_contains($lower, 'shop') || str_contains($lower, 'product') || str_contains($lower, 'ecommerce')) {
            $collections[] = [
                'name' => 'Products', 'slug' => 'products', 'description' => 'Product catalog',
                'isSingleton' => false,
                'fields' => [
                    ['name' => 'Name', 'slug' => 'name', 'type' => 'text', 'isRequired' => true],
                    ['name' => 'Slug', 'slug' => 'slug', 'type' => 'slug', 'isRequired' => true],
                    ['name' => 'Description', 'slug' => 'description', 'type' => 'richtext', 'isRequired' => false],
                    ['name' => 'Price', 'slug' => 'price', 'type' => 'number', 'isRequired' => true],
                    ['name' => 'Image', 'slug' => 'image', 'type' => 'media', 'isRequired' => false],
                    ['name' => 'SKU', 'slug' => 'sku', 'type' => 'text', 'isRequired' => false],
                ],
            ];
        }

        // Portfolio
        if (str_contains($lower, 'portfolio') || str_contains($lower, 'project')) {
            $collections[] = [
                'name' => 'Projects', 'slug' => 'projects', 'description' => 'Portfolio projects',
                'isSingleton' => false,
                'fields' => [
                    ['name' => 'Title', 'slug' => 'title', 'type' => 'text', 'isRequired' => true],
                    ['name' => 'Slug', 'slug' => 'slug', 'type' => 'slug', 'isRequired' => true],
                    ['name' => 'Description', 'slug' => 'description', 'type' => 'richtext', 'isRequired' => true],
                    ['name' => 'Cover Image', 'slug' => 'cover_image', 'type' => 'media', 'isRequired' => false],
                    ['name' => 'URL', 'slug' => 'url', 'type' => 'text', 'isRequired' => false],
                    ['name' => 'Completed Date', 'slug' => 'completed_date', 'type' => 'date', 'isRequired' => false],
                ],
            ];
        }

        // Fallback: generic schema
        if ($collections === []) {
            $collections[] = [
                'name' => 'Items', 'slug' => 'items', 'description' => $prompt,
                'isSingleton' => false,
                'fields' => [
                    ['name' => 'Title', 'slug' => 'title', 'type' => 'text', 'isRequired' => true],
                    ['name' => 'Slug', 'slug' => 'slug', 'type' => 'slug', 'isRequired' => true],
                    ['name' => 'Content', 'slug' => 'content', 'type' => 'richtext', 'isRequired' => true],
                    ['name' => 'Image', 'slug' => 'image', 'type' => 'media', 'isRequired' => false],
                ],
            ];
        }

        return ['collections' => $collections];
    }

    /**
     * Apply schema from the visual builder.
     */
    #[Route('/api/projects/{uuid}/studio/schema', name: 'studio_schema_apply', methods: ['POST'])]
    public function applySchema(string $uuid, Request $request): JsonResponse
    {
        $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        if (!$project) {
            return $this->json(['error' => 'Projet introuvable'], 404);
        }

        $this->denyAccessUnlessGranted('project.manage', $project);

        $data = json_decode($request->getContent(), true);
        $collections = $data['collections'] ?? [];

        $created = 0;
        $updated = 0;

        foreach ($collections as $colData) {
            if (empty($colData['name'])) continue;

            $slug = $colData['slug'] ?: $this->slugify($colData['name']);
            $collection = $this->em->getRepository(Collection::class)
                ->findOneBy(['project' => $project, 'slug' => $slug, 'deletedAt' => null]);

            if (!$collection) {
                $collection = new Collection();
                $collection->project = $project;
                $collection->order = count($project->collections->toArray());
                $created++;
            } else {
                $updated++;
            }

            $collection->name = $colData['name'];
            $collection->slug = $slug;
            $collection->description = $colData['description'] ?? null;
            $collection->isSingleton = $colData['isSingleton'] ?? false;

            $this->em->persist($collection);

            $existingSlugs = [];
            foreach ($collection->fields as $f) {
                if (!$f->isDeleted()) $existingSlugs[$f->slug] = $f;
            }

            $order = 0;
            foreach ($colData['fields'] ?? [] as $fieldData) {
                if (empty($fieldData['name'])) continue;

                $fSlug = $fieldData['slug'] ?: $this->slugify($fieldData['name']);

                if (isset($existingSlugs[$fSlug])) {
                    $field = $existingSlugs[$fSlug];
                    unset($existingSlugs[$fSlug]);
                } else {
                    $field = new Field();
                    $field->collection = $collection;
                }

                $field->name = $fieldData['name'];
                $field->slug = $fSlug;
                $field->type = $fieldData['type'] ?? 'text';
                $field->isRequired = $fieldData['isRequired'] ?? false;
                $field->options = $fieldData['options'] ?? null;
                $field->order = $order++;

                $this->em->persist($field);
            }

            // Soft-delete removed fields
            foreach ($existingSlugs as $oldField) {
                $oldField->deletedAt = new \DateTimeImmutable();
                $this->em->persist($oldField);
            }
        }

        $this->em->flush();

        return $this->json([
            'success' => true,
            'created' => $created,
            'updated' => $updated,
        ]);
    }

    /**
     * Generate TypeScript types from the project schema.
     */
    #[Route('/api/projects/{uuid}/studio/generate-types', name: 'studio_generate_types', methods: ['GET'])]
    public function generateTypes(string $uuid): JsonResponse
    {
        $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        if (!$project) {
            return $this->json(['error' => 'Projet introuvable'], 404);
        }

        $collections = $this->em->getRepository(Collection::class)
            ->findBy(['project' => $project, 'deletedAt' => null]);

        $types = [];
        foreach ($collections as $collection) {
            $fields = [];
            foreach ($collection->fields as $f) {
                if ($f->isDeleted()) continue;
                $optional = $f->isRequired ? '' : '?';
                $tsType = $this->mapPhpTypeToTs($f->type);
                $fields[] = "  {$f->slug}{$optional}: {$tsType};";
            }

            $types[] = [
                'name' => ucfirst($collection->slug),
                'code' => "export interface " . ucfirst($collection->slug) . " {\n  uuid: string;\n  locale: string;\n  status: 'draft' | 'published';\n" . implode("\n", $fields) . "\n  created_at: string;\n  updated_at: string;\n}",
            ];
        }

        return $this->json(['types' => $types]);
    }

    private function slugify(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9]+/', '_', $text);

        return trim($text, '_');
    }

    private function mapPhpTypeToTs(string $type): string
    {
        return match ($type) {
            'number', 'decimal' => 'number',
            'boolean', 'checkbox' => 'boolean',
            'json', 'array', 'repeater' => 'Record<string, any>',
            'media', 'relation' => 'string | string[]',
            default => 'string',
        };
    }
}
