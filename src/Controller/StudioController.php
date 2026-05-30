<?php

namespace App\Controller;

use App\Entity\Collection;
use App\Entity\ContentFieldValue;
use App\Entity\Field;
use App\Entity\Project;
use App\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
class StudioController extends InertiaController
{
    public function __construct(
        private EntityManagerInterface $em,
        private ProjectRepository $projectRepository,
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

        // Utilise le même provider AI que le Workbench
        $settings = $this->em->getRepository(\App\Entity\AppSettings::class)->getOrCreate();
        $config = $settings->aiProviders ?? [];

        $platform = null;
        $model = null;
        foreach (['openai', 'anthropic', 'deepseek', 'ollama'] as $provider) {
            if (!empty($config[$provider]['enabled'])) {
                $platform = match ($provider) {
                    'openai' => new \Symfony\AI\Platform\OpenAI\OpenAIPlatform($config[$provider]['key'] ?? ''),
                    'anthropic' => new \Symfony\AI\Platform\Anthropic\AnthropicPlatform($config[$provider]['key'] ?? ''),
                    'deepseek' => new \Symfony\AI\Platform\DeepSeek\DeepSeekPlatform($config[$provider]['key'] ?? ''),
                    'ollama' => new \Symfony\AI\Platform\Ollama\OllamaPlatform($config[$provider]['url'] ?? ''),
                    default => null,
                };
                $model = $config[$provider]['model'] ?? null;
                break;
            }
        }

        if ($platform === null) {
            // Fallback: génération basée sur des règles (pas d'IA configurée)
            return $this->json($this->ruleBasedSchema($prompt));
        }

        try {
            $result = $platform->chat($model ?? 'gpt-4o', [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $prompt],
            ]);

            $content = $result->getContent();
            // Extraire le JSON (le modèle peut wrapper dans ```json)
            if (preg_match('/\{[\s\S]*\}/', $content, $m)) {
                $data = json_decode($m[0], true);
                if ($data && isset($data['collections'])) {
                    return $this->json($data);
                }
            }

            return $this->json(['error' => 'Échec du parsing JSON de la réponse IA'], 500);
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }
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
