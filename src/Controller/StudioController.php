<?php

namespace App\Controller;

use App\Entity\Collection;
use App\Entity\Field;
use App\Entity\Project;
use App\Repository\AppSettingsRepository;
use App\Repository\ProjectRepository;
use App\Repository\StudioChatMessageRepository;
use Doctrine\Inflector\Inflector;
use Doctrine\Inflector\InflectorFactory;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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

    /** Conventions de nommage imposées aux schémas générés par l'IA. */
    private const NAMING_CONVENTIONS = <<<RULES
## Naming conventions (STRICT — always enforce, regardless of the conversation language)
- ALL `name` and `slug` values MUST be in English.
- Collection `name`: PascalCase, starts with an UPPERCASE letter, NO spaces, and PLURAL for regular collections (e.g. "BlogPosts", "Products", "TeamMembers"). For singletons (isSingleton: true) use the SINGULAR form (e.g. "About", "HomePage", "Contact").
- Field `name`: camelCase, starts with a LOWERCASE letter, NO spaces (e.g. "title", "publishedAt", "featuredImage").
- Multi-word names use CamelCase WITHOUT any separator inside the name: collection = UpperCamelCase ("TeamMembers"), field = lowerCamelCase ("featuredImage"). Underscores ONLY appear in slugs.
- Every `slug` (collection and field): lowercase snake_case, ASCII only, words separated by single underscores, matching ^[a-z][a-z0-9_]*$ (e.g. "blog_post", "published_at"). Never start with a digit.
- NEVER use spaces, accents, hyphens or special characters in any `name` or `slug`.
- Do NOT create system/automatic fields: id, uuid, status, locale, created_at, updated_at, deleted_at — they already exist.
- Field slugs must be UNIQUE within a collection; collection slugs must be unique across the project.
- Prefer concise descriptive English names; no abbreviations unless standard (url, id, seo).
- Each content collection SHOULD include a "title" (text) field and a "slug" (type slug) field.
- Boolean field names should be affirmative (e.g. "isPublished", "featured").
- Relation fields must reference an existing or newly created collection.
RULES;

    /** Champs système gérés automatiquement — jamais générés par l'IA. */
    private const RESERVED_FIELD_SLUGS = ['id', 'uuid', 'status', 'locale', 'created_at', 'updated_at', 'deleted_at'];

    private ?Inflector $inflector = null;

    private function inflector(): Inflector
    {
        return $this->inflector ??= InflectorFactory::create()->build();
    }

    /**
     * Normalise un schéma généré par l'IA pour GARANTIR les conventions de
     * nommage, même si le modèle ne les respecte pas :
     *  - nom de collection : PascalCase, PLURIEL (SINGULIER pour les singletons) ;
     *  - nom de champ : camelCase ;
     *  - slugs : snake_case, dérivés du nom (cohérence garantie) ;
     *  - suppression des champs système et des doublons.
     *
     * @param array<int,mixed> $collections
     * @return array<int,array<string,mixed>>
     */
    private function normalizeSchema(array $collections): array
    {
        $out = [];
        $seenCollectionSlugs = [];
        foreach ($collections as $col) {
            if (!is_array($col)) continue;

            $isSingleton = (bool) ($col['isSingleton'] ?? false);
            $name = $this->toPascalCase((string) ($col['name'] ?? ''));
            if ($name === '') {
                $name = $isSingleton ? 'Item' : 'Items';
            } else {
                // Forme canonique : on singularise puis on (re)pluralise au besoin,
                // ce qui corrige aussi bien "BlogPost" que "BlogPosts".
                $singular = $this->inflector()->singularize($name);
                $name = $isSingleton ? $singular : $this->inflector()->pluralize($singular);
            }
            $slug = $this->toSnakeCase($name) ?: 'collection';
            // Unicité des slugs de collection
            $base = $slug; $i = 2;
            while (isset($seenCollectionSlugs[$slug])) { $slug = $base . '_' . $i++; }
            $seenCollectionSlugs[$slug] = true;

            $col['name'] = $name;
            $col['slug'] = $slug;

            $fields = [];
            $seenFieldSlugs = [];
            foreach (($col['fields'] ?? []) as $field) {
                if (!is_array($field)) continue;
                $fname = $this->toCamelCase((string) ($field['name'] ?? ''));
                if ($fname === '') continue;
                $fslug = $this->toSnakeCase($fname);
                if ($fslug === '' || in_array($fslug, self::RESERVED_FIELD_SLUGS, true)) continue;
                if (isset($seenFieldSlugs[$fslug])) continue;
                $seenFieldSlugs[$fslug] = true;
                $field['name'] = $fname;
                $field['slug'] = $fslug;
                $fields[] = $field;
            }
            $col['fields'] = $fields;
            $out[] = $col;
        }
        return $out;
    }

    /**
     * Extrait de façon robuste le 1er objet JSON équilibré contenant la clé
     * "collections" dans la réponse de l'IA (fencé ```json ou inline, à n'importe
     * quel niveau d'imbrication). Remplace les regex fragiles.
     *
     * @return array{data: array<string,mixed>, raw: string}|null
     */
    private function extractSchemaJson(string $content): ?array
    {
        // Priorité aux blocs ```json … ``` (le modèle y met généralement le schéma).
        if (preg_match_all('/```(?:json)?\s*([\s\S]*?)```/', $content, $blocks)) {
            foreach ($blocks[1] as $block) {
                $raw = $this->balancedJsonContaining($block, 'collections');
                if ($raw !== null) {
                    $data = json_decode($raw, true);
                    if (is_array($data) && isset($data['collections'])) {
                        return ['data' => $data, 'raw' => $raw];
                    }
                }
            }
        }
        // Sinon, balayer tout le contenu.
        $raw = $this->balancedJsonContaining($content, 'collections');
        if ($raw !== null) {
            $data = json_decode($raw, true);
            if (is_array($data) && isset($data['collections'])) {
                return ['data' => $data, 'raw' => $raw];
            }
        }
        return null;
    }

    /**
     * Retourne le 1er objet JSON équilibré (en tenant compte des chaînes/échappements)
     * qui contient la clé "$needle", ou null.
     */
    private function balancedJsonContaining(string $text, string $needle): ?string
    {
        $len = strlen($text);
        for ($start = 0; $start < $len; $start++) {
            if ($text[$start] !== '{') continue;
            $depth = 0; $inStr = false; $esc = false;
            for ($i = $start; $i < $len; $i++) {
                $ch = $text[$i];
                if ($inStr) {
                    if ($esc) { $esc = false; }
                    elseif ($ch === '\\') { $esc = true; }
                    elseif ($ch === '"') { $inStr = false; }
                    continue;
                }
                if ($ch === '"') { $inStr = true; }
                elseif ($ch === '{') { $depth++; }
                elseif ($ch === '}') {
                    if (--$depth === 0) {
                        $candidate = substr($text, $start, $i - $start + 1);
                        if (str_contains($candidate, '"' . $needle . '"')) {
                            return $candidate;
                        }
                        break; // cet objet ne contient pas la clé → essayer le prochain '{'
                    }
                }
            }
        }
        return null;
    }

    /** Table de repli des accents latins → lettre de base (déterministe, multi-plateforme). */
    private const ACCENT_MAP = [
        'à'=>'a','â'=>'a','ä'=>'a','á'=>'a','ã'=>'a','å'=>'a','è'=>'e','é'=>'e','ê'=>'e','ë'=>'e',
        'ì'=>'i','í'=>'i','î'=>'i','ï'=>'i','ò'=>'o','ó'=>'o','ô'=>'o','ö'=>'o','õ'=>'o',
        'ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u','ç'=>'c','ñ'=>'n','ÿ'=>'y','œ'=>'oe','æ'=>'ae','ß'=>'ss',
        'À'=>'A','Â'=>'A','Ä'=>'A','Á'=>'A','Ã'=>'A','È'=>'E','É'=>'E','Ê'=>'E','Ë'=>'E',
        'Ì'=>'I','Í'=>'I','Î'=>'I','Ï'=>'I','Ò'=>'O','Ó'=>'O','Ô'=>'O','Ö'=>'O','Õ'=>'O',
        'Ù'=>'U','Ú'=>'U','Û'=>'U','Ü'=>'U','Ç'=>'C','Ñ'=>'N',
    ];

    /** @return string[] mots ASCII extraits d'une chaîne (gère camelCase, espaces, séparateurs). */
    private function splitWords(string $value): array
    {
        // Replie les accents en ASCII de façon déterministe, puis retire tout
        // caractère non-ASCII résiduel.
        $value = strtr($value, self::ACCENT_MAP);
        $value = preg_replace('/[^\x20-\x7E]/', '', $value) ?? $value;
        // Coupe aux frontières camelCase puis aux non-alphanumériques.
        $value = preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $value) ?? $value;
        $parts = preg_split('/[^A-Za-z0-9]+/', $value) ?: [];
        return array_values(array_filter($parts, fn ($p) => $p !== ''));
    }

    private function toPascalCase(string $value): string
    {
        $words = $this->splitWords($value);
        $out = implode('', array_map(fn ($w) => ucfirst(strtolower($w)), $words));
        if ($out !== '' && !ctype_alpha($out[0])) $out = 'C' . $out; // doit commencer par une lettre
        return $out;
    }

    private function toCamelCase(string $value): string
    {
        $pascal = $this->toPascalCase($value);
        return $pascal === '' ? '' : lcfirst($pascal);
    }

    private function toSnakeCase(string $value): string
    {
        $words = array_map('strtolower', $this->splitWords($value));
        $out = implode('_', $words);
        if ($out !== '' && ctype_digit($out[0])) $out = 'f_' . $out; // ne doit pas commencer par un chiffre
        return $out;
    }

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

        $namingRules = self::NAMING_CONVENTIONS;
        $systemPrompt = <<<PROMPT
You are a CMS schema designer. Given a user's description, generate a list of collections with their fields.

- Return ONLY valid JSON (no markdown, no explanation).
- Use this exact structure:
{
  "collections": [
    {
      "name": "BlogPosts",
      "slug": "blog_posts",
      "description": "Blog articles",
      "isSingleton": false,
      "fields": [
        { "name": "title", "slug": "title", "type": "text", "isRequired": true },
        { "name": "slug", "slug": "slug", "type": "slug", "isRequired": true },
        { "name": "body", "slug": "body", "type": "richtext", "isRequired": true },
        { "name": "publishedAt", "slug": "published_at", "type": "datetime", "isRequired": false }
      ]
    }
  ]
}

$namingRules

- Field types available: text, longtext, richtext, slug, email, password, number, decimal, boolean, date, datetime, time, color, json, enumeration, media, relation
- Choose appropriate types based on the field name and context.
- For slug fields, mark them as required and use type "slug".
- For dates, use "date". For timestamps, use "datetime".
- If the user mentions "images" or "photos", use "media".
- If the user describes a single page (about, contact), mark isSingleton: true.
- Generate at least 3-5 fields per collection that make sense for the domain.
- Maximum 5 collections. Maximum 10 fields per collection.
PROMPT;

        [$provider, $apiKey, $model, $endpoint] = $this->resolveAiConfig();

        if ($provider === null) {
            // Fallback: génération basée sur des règles (pas d'IA configurée)
            $schema = $this->ruleBasedSchema($prompt);
            $schema['collections'] = $this->normalizeSchema($schema['collections'] ?? []);
            return $this->json($schema);
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
                    $data['collections'] = $this->normalizeSchema($data['collections']);
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

        // Persister le message utilisateur en DB immédiatement
        $userMsg = new \App\Entity\StudioChatMessage();
        $userMsg->project = $project;
        $userMsg->role = 'user';
        $userMsg->content = $prompt;
        $this->em->persist($userMsg);
        $this->em->flush();

        $namingRules = self::NAMING_CONVENTIONS;
        $systemPrompt = <<<PROMPT
You are a CMS schema architect. You help users design their content model by creating and modifying collections.

## Rules
- Always respond in French (the user speaks French) — but the schema `name`/`slug` values MUST stay in English (see naming conventions).
- IMPORTANT: You can only PROPOSE a schema. You CANNOT create, add, apply, modify or save collections yourself. The schema becomes real ONLY when the user clicks the « Appliquer » button under it (and then saves). NEVER claim a collection is "créée", "ajoutée", "appliquée" or "enregistrée" — instead say it is "proposée" and invite the user to cliquer « Appliquer ».
- First, acknowledge what the user asked. Then if applicable, propose a schema.
- When proposing or modifying a schema, you MUST include the FULL schema as valid JSON wrapped in a ```json fenced code block (never describe changes only in prose — without the JSON block, nothing can be applied).
- When modifying/adding to existing collections, return the COMPLETE collection(s) concerned (not just the changed fields).
- The JSON must use this exact structure:
```json
{
  "collections": [
    {
      "name": "BlogPosts",
      "slug": "blog_posts",
      "description": "Blog articles",
      "isSingleton": false,
      "fields": [
        { "name": "title", "slug": "title", "type": "text", "isRequired": true },
        { "name": "slug", "slug": "slug", "type": "slug", "isRequired": true },
        { "name": "body", "slug": "body", "type": "richtext", "isRequired": true },
        { "name": "publishedAt", "slug": "published_at", "type": "datetime", "isRequired": false }
      ]
    }
  ]
}
```

$namingRules

## Field types available
text, longtext, richtext, slug, email, password, number, decimal, boolean, date, datetime, time, color, json, enumeration, media, relation

## Guidelines
- Use "slug" type for URL-friendly identifiers (mark them required).
- Use "media" for images/photos/files.
- Use "richtext" for rich content (HTML/WYSIWYG).
- Use "date" for dates, "datetime" for timestamps.
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
            $schema['collections'] = $this->normalizeSchema($schema['collections'] ?? []);
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

            // Extraction robuste : on cherche un objet JSON équilibré contenant
            // "collections" (quel que soit le niveau d'imbrication, fencé ou non).
            $collections = null;
            $reply = $content;
            $extracted = $this->extractSchemaJson($content);
            if ($extracted !== null) {
                $collections = $extracted['data']['collections'] ?? null;
                // Retire le JSON (et les fences ```) du texte affiché à l'utilisateur.
                $reply = trim(str_replace($extracted['raw'], '', $content));
                $reply = trim(preg_replace('/```(?:json)?\s*```/', '', $reply) ?? $reply);
            }

            // Normaliser le schéma proposé pour garantir les conventions de
            // nommage (PascalCase collection, camelCase champ, slugs snake_case).
            if (is_array($collections)) {
                $collections = $this->normalizeSchema($collections);
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
            'gemini' => [
                'key'      => $config['gemini']['key'] ?? '',
                'model'    => $config['gemini']['model'] ?? 'gemini-2.0-flash',
                'endpoint' => 'https://generativelanguage.googleapis.com/v1beta/models/' . ($config['gemini']['model'] ?? 'gemini-2.0-flash') . ':generateContent',
            ],
            'openrouter' => [
                'key'      => $config['openrouter']['key'] ?? '',
                'model'    => $config['openrouter']['model'] ?? 'openai/gpt-4o',
                'endpoint' => 'https://openrouter.ai/api/v1/chat/completions',
            ],
            'mistral' => [
                'key'      => $config['mistral']['key'] ?? '',
                'model'    => $config['mistral']['model'] ?? 'mistral-large-latest',
                'endpoint' => 'https://api.mistral.ai/v1/chat/completions',
            ],
            'groq' => [
                'key'      => $config['groq']['key'] ?? '',
                'model'    => $config['groq']['model'] ?? 'llama-3.3-70b-versatile',
                'endpoint' => 'https://api.groq.com/openai/v1/chat/completions',
            ],
            'xai' => [
                'key'      => $config['xai']['key'] ?? '',
                'model'    => $config['xai']['model'] ?? 'grok-2-latest',
                'endpoint' => 'https://api.x.ai/v1/chat/completions',
            ],
            'perplexity' => [
                'key'      => $config['perplexity']['key'] ?? '',
                'model'    => $config['perplexity']['model'] ?? 'sonar-pro',
                'endpoint' => 'https://api.perplexity.ai/chat/completions',
            ],
            'qwen' => [
                'key'      => $config['qwen']['key'] ?? '',
                'model'    => $config['qwen']['model'] ?? 'qwen-max',
                'endpoint' => 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1/chat/completions',
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

        if ($provider === 'gemini') {
            // Clé API dans le header x-goog-api-key, pas en URL
            $headers['x-goog-api-key'] = $apiKey;
            $parts = [];
            foreach ($messages as $m) {
                $parts[] = ['text' => $m['content']];
            }
            $body = ['contents' => [['parts' => $parts]]];
            $response = $this->httpClient->request('POST', $endpoint, [
                'headers' => $headers,
                'json'    => $body,
                'timeout' => 60,
            ]);
            $arr = $response->toArray();
            return $arr['candidates'][0]['content']['parts'][0]['text'] ?? '';
        }

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

    /**
     * Flush sécurisé : ne fait rien si l'EntityManager est fermé
     * (par ex. après une exception réseau lors d'un appel API).
     */
    private function safeFlush(): void
    {
        if ($this->em->isOpen()) {
            $this->em->flush();
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
        // Garantie ultime : on normalise au point de persistance, donc quelle que
        // soit la source (chat IA, schéma IA, builder visuel), les conventions de
        // nommage sont toujours respectées en base.
        $collections = $this->normalizeSchema($data['collections'] ?? []);

        $created = 0;
        $updated = 0;

        foreach ($collections as $colData) {
            if (empty($colData['name'])) continue;

            $slug = $colData['slug'] ?: $this->slugify($colData['name']);

            // Chercher par UUID d'abord (permet de renommer une collection sans créer de doublon)
            $collection = null;
            if (!empty($colData['uuid'])) {
                $collection = $this->em->getRepository(Collection::class)
                    ->findOneBy(['project' => $project, 'uuid' => $colData['uuid'], 'deletedAt' => null]);
            }
            // Fallback : chercher par slug
            if (!$collection) {
                $collection = $this->em->getRepository(Collection::class)
                    ->findOneBy(['project' => $project, 'slug' => $slug, 'deletedAt' => null]);
            }

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
