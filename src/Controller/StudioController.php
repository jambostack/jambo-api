<?php

namespace App\Controller;

use App\Entity\Collection;
use App\Entity\ContentEntry;
use App\Entity\ContentFieldValue;
use App\Entity\Field;
use App\Entity\Media;
use App\Entity\Project;
use App\Entity\StudioChatMessage;
use App\Repository\AppSettingsRepository;
use App\Repository\MediaRepository;
use App\Repository\ProjectRepository;
use App\Repository\StudioChatMessageRepository;
use App\Service\AiContentService;
use Doctrine\Inflector\Inflector;
use Doctrine\Inflector\InflectorFactory;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\File as HttpFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
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
        private readonly \App\Repository\EndUserRepository $endUserRepository,
        private readonly \Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface $passwordHasher,
        private readonly \App\Repository\MediaRepository $mediaRepository,
        private readonly \App\Service\AiContentService $aiService,
        private readonly \App\Service\FieldRelationOptionsNormalizer $relationOptionsNormalizer,
        private \App\Service\FieldValueHydrator $fieldValueHydrator,
        private LoggerInterface $logger = new \Psr\Log\NullLogger(),
    ) {}

    /** Conventions de nommage imposées aux schémas générés par l'IA. */
    private const NAMING_CONVENTIONS = <<<RULES
## Naming conventions (STRICT â€” always enforce, regardless of the conversation language)
- ALL `name` and `slug` values MUST be in English.
- Collection `name`: PascalCase, starts with an UPPERCASE letter, NO spaces, and PLURAL for regular collections (e.g. "BlogPosts", "Products", "TeamMembers"). For singletons (isSingleton: true) use the SINGULAR form (e.g. "About", "HomePage", "Contact").
- Field `name`: camelCase, starts with a LOWERCASE letter, NO spaces (e.g. "title", "publishedAt", "featuredImage").
- Multi-word names use CamelCase WITHOUT any separator inside the name: collection = UpperCamelCase ("TeamMembers"), field = lowerCamelCase ("featuredImage"). Underscores ONLY appear in slugs.
- Every `slug` (collection and field): lowercase snake_case, ASCII only, words separated by single underscores, matching ^[a-z][a-z0-9_]*$ (e.g. "blog_post", "published_at"). Never start with a digit.
- NEVER use spaces, accents, hyphens or special characters in any `name` or `slug`.
- Do NOT create system/automatic fields: id, uuid, status, locale, created_at, updated_at, deleted_at â€” they already exist.
- Field slugs must be UNIQUE within a collection; collection slugs must be unique across the project.
- Prefer concise descriptive English names; no abbreviations unless standard (url, id, seo).
- Each content collection SHOULD include a "title" (text) field and a "slug" (type slug) field.
- Boolean field names should be affirmative (e.g. "isPublished", "featured").
- Relation fields must reference an existing or newly created collection.
RULES;

    /** Bloc EndUser injecté dans tous les prompts systèmes. */
    private const ENDUSER_AWARENESS = <<<EUSER
## User Management System (EndUsers)
- The project has a BUILT-IN user collection: EndUsers (slug: end_users).
- EndUsers ALWAYS exists â€” it is NOT a regular collection, it's a system entity.
- EndUsers fields: email (email*, required), name (text), status (active/banned/pending), avatar_url (text), custom_fields (json).
- Custom fields CAN be added to EndUsers: profile data (bio, website, phone), preferences, roles, company info, etc.
- RULES for user-related requests:
  1. When the user mentions "users", "authors", "members", "customers", "admins", "profiles", "clients", or any person/account entity â†’ this is EndUsers. Do NOT create a new "Users", "Accounts", "Members" or "Profiles" collection.
  2. If the user asks to add profile fields (bio, website, phone, role, avatar, preferences, social links, company...), propose adding them TO EndUsers â€” modify the existing EndUsers collection in your JSON output.
  3. For ownership/assignment/authoring, use "relation" fields pointing TO EndUsers (e.g., author, owner, assignedTo, createdBy, customer).
  4. Never duplicate EndUsers system fields (email, name, status, avatar_url) â€” only propose NEW custom fields.
  5. EndUsers does NOT need title/slug fields â€” it's already handled by the system.
EUSER;

    /** Champs système gérés automatiquement â€” jamais générés par l'IA. */
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
     * Extraction JSON : cherche "collections" dans la réponse IA.
     * Délègue à  extractJsonResponse (qui gère aussi "entries").
     *
     * @return array{data: array<string,mixed>, raw: string}|null
     */
    private function extractSchemaJson(string $content): ?array
    {
        return $this->extractJsonResponse($content);
    }

    /**
     * Retourne le 1er objet JSON équilibré (en tenant compte des chaà®nes/échappements)
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
                        break; // cet objet ne contient pas la clé â†’ essayer le prochain '{'
                    }
                }
            }
        }
        return null;
    }

    /** Table de repli des accents latins â†’ lettre de base (déterministe, multi-plateforme). */
    private const ACCENT_MAP = [
        'à '=>'a','à¢'=>'a','à¤'=>'a','à¡'=>'a','à£'=>'a','à¥'=>'a','è'=>'e','é'=>'e','ê'=>'e','à«'=>'e',
        'à¬'=>'i','à­'=>'i','à®'=>'i','à¯'=>'i','à²'=>'o','à³'=>'o','à´'=>'o','à¶'=>'o','àµ'=>'o',
        'à¹'=>'u','àº'=>'u','à»'=>'u','à¼'=>'u','à§'=>'c','à±'=>'n','à¿'=>'y','Å“'=>'oe','à¦'=>'ae','àŸ'=>'ss',
        'à€'=>'A','à‚'=>'A','à„'=>'A','à'=>'A','àƒ'=>'A','àˆ'=>'E','à‰'=>'E','àŠ'=>'E','à‹'=>'E',
        'àŒ'=>'I','à'=>'I','àŽ'=>'I','à'=>'I','à’'=>'O','à“'=>'O','à”'=>'O','à–'=>'O','à•'=>'O',
        'à™'=>'U','àš'=>'U','à›'=>'U','àœ'=>'U','à‡'=>'C','à‘'=>'N',
    ];

    /** @return string[] mots ASCII extraits d'une chaà®ne (gère camelCase, espaces, séparateurs). */
    private function splitWords(string $value): array
    {
        // Replie les accents en ASCII de faà§on déterministe, puis retire tout
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
                'settings'    => $c->settings,
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
                'settings' => $c->settings,
                'fields' => $fields,
            ];
        }, $collections);

        return $this->json(['data' => $data]);
    }

    /**
     * Génère un schéma de collection via IA à  partir d'un prompt.
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

## Field types available
text, longtext, richtext, slug, email, password, number, decimal, boolean, date, datetime, time, color, json, enumeration, media, relation

## API automatiquement générée
Chaque collection créée expose automatiquement :
- REST: GET /api/{project}/{slug} (liste), POST (créer), GET/PATCH/DELETE /api/{project}/{slug}/{uuid}
- GraphQL: POST /api/projects/{project}/graphql â€” schema auto-généré (queries, mutations, filtres, pagination)
- Spécification OpenAPI: GET /api/{project}/openapi.json
- Auth: Bearer token (API token) ou JWT (end-users via /auth/login, /auth/register)

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

            return $this->json(['error' => 'à‰chec du parsing JSON de la réponse IA'], 500);
        } catch (\Throwable $e) {
            $this->logger->error('AI schema generation failed', [
                'exception' => $e,
                'project'   => $uuid,
            ]);
            return $this->json(['error' => 'à‰chec de la génération IA. Réessayez.'], 500);
        }
    }

    /**
     * Chat IA conversationnel pour générer/modifier le schéma de collections.
     * Reà§oit l'historique de conversation + le contexte des collections existantes.
     */
    #[Route('/api/projects/{uuid}/studio/ai-chat', name: 'studio_ai_chat', methods: ['POST'])]
    public function aiChat(string $uuid, Request $request): Response
    {
        $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        if (!$project) return $this->json(['error' => 'Projet introuvable'], 404);
        $this->denyAccessUnlessGranted('project.manage', $project);

        $body = json_decode($request->getContent(), true);
        $prompt  = trim((string) ($body['prompt'] ?? ''));
        $context = trim((string) ($body['context'] ?? ''));
        $history = is_array($body['history'] ?? null) ? $body['history'] : [];
        $command = isset($body['command']) && in_array($body['command'], ['schema','data','all'], true)
            ? $body['command']
            : 'schema';
        $attachment = is_array($body['attachment'] ?? null) ? $body['attachment'] : null;

        if ($prompt === '') return $this->json(['error' => 'Prompt requis'], 422);

        $attachmentContext = '';
        if ($attachment !== null) {
            $name     = (string) ($attachment['name'] ?? 'fichier');
            $mimeType = (string) ($attachment['mimeType'] ?? '');
            $text     = isset($attachment['text']) ? trim((string) $attachment['text']) : null;
            if ($text !== null && $text !== '') {
                $attachmentContext = "\n\n--- FICHIER JOINT : {$name} ---\n" . mb_substr($text, 0, 8000) . "\n--- FIN DU FICHIER ---";
            } elseif (!str_starts_with($mimeType, 'image/') && empty($attachment['base64']) && empty($attachment['mediaUuid'])) {
                $attachmentContext = "\n\n[Fichier joint : {$name} — contenu non extractible]";
            }
        }

        $commandPrefix = $command !== 'schema' ? '/' . $command . ' ' : '';
        $userMsg = new \App\Entity\StudioChatMessage();
        $userMsg->project = $project;
        $userMsg->role = 'user';
        $userMsg->content = $commandPrefix . $prompt;
        $this->em->persist($userMsg);
        $this->em->flush();

        $systemPrompt = $this->buildSystemPrompt($command, self::NAMING_CONVENTIONS, self::ENDUSER_AWARENESS, $context . $attachmentContext);
        [$provider, $apiKey, $model, $endpoint] = $this->resolveAiConfig();

        // Fallback sans IA : réponse immédiate enveloppée en SSE pour cohérence
        if ($provider === null) {
            $fallbackData = json_decode($this->commandFallback($command, $prompt)->getContent(), true);
            return new StreamedResponse(function () use ($fallbackData) {
                while (ob_get_level() > 0) { ob_end_flush(); }
                ob_implicit_flush(true);
                echo ": ping\n\ndata: " . json_encode($fallbackData) . "\n\n";
            }, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache', 'X-Accel-Buffering' => 'no']);
        }

        $msgs = [['role' => 'system', 'content' => $systemPrompt]];
        foreach ($history as $h) {
            $role = ($h['role'] === 'assistant' || $h['role'] === 'user') ? $h['role'] : 'user';
            $msgs[] = ['role' => $role, 'content' => (string) $h['content']];
        }
        $msgs[] = $this->buildUserMessage($provider, $prompt, $attachment, $project);
        [$aiUrl, $aiOptions] = $this->buildAiRequestArgs($provider, $apiKey, $model, $endpoint, $msgs);

        return new StreamedResponse(function () use ($aiUrl, $aiOptions, $provider, $command, $project) {
            set_time_limit(300);
            ignore_user_abort(true);

            // output_buffering de php.ini crée un ob level qui retient les données.
            // ob_flush() + flush() ensemble percent tous les niveaux de buffer.
            while (ob_get_level() > 0) { ob_end_flush(); }
            ob_implicit_flush(true);

            // Heartbeat immédiat pour éviter le timeout Apache 30 s
            echo ": ping\n\n";
            flush();

            try {
                $httpResponse = $this->httpClient->request('POST', $aiUrl, $aiOptions);

                // Envoie un heartbeat toutes les 20 s pendant l'attente de la réponse IA
                foreach ($this->httpClient->stream($httpResponse, 20.0) as $chunk) {
                    if ($chunk->isTimeout()) {
                        echo ": ping\n\n";
                        flush();
                    }
                }

                $content = $this->parseAiContent($provider, $httpResponse->toArray());

                $collections = null;
                $entries     = null;
                $reply       = $content;

                $extracted = $this->extractJsonResponse($content);
                if ($extracted !== null) {
                    $collections = $extracted['data']['collections'] ?? null;
                    $entries     = $extracted['data']['entries'] ?? null;
                    $reply = trim(str_replace($extracted['raw'], '', $content));
                    $reply = trim(preg_replace('/```(?:json)?\s*```/', '', $reply) ?? $reply);
                }

                if (is_array($collections)) {
                    $collections = $this->normalizeSchema($collections);
                }

                $reply = trim(preg_replace('/```\s*$/', '', $reply));

                if ($reply === '') {
                    $reply = match ($command) {
                        'data'  => ($entries !== null ? 'Contenu généré. Applique-le ci-dessous.' : "Je n'ai pas pu générer de contenu. Peux-tu être plus précis ?"),
                        'all'   => 'Schéma et contenu générés. Applique chaque bloc ci-dessous.',
                        default => ($collections !== null ? 'Schéma généré. Applique-le ci-dessous.' : 'Je ne peux pas générer de schéma pour cette demande. Peux-tu être plus précis ?'),
                    };
                }

                $assistantMsg = new \App\Entity\StudioChatMessage();
                $assistantMsg->project = $project;
                $assistantMsg->role = 'assistant';
                $assistantMsg->content = $reply;
                $assistantMsg->schema = $collections;
                $assistantMsg->entries = $entries;
                $this->em->persist($assistantMsg);
                $this->em->flush();

                $result = ['reply' => $reply];
                if ($command !== 'data' || $collections !== null) {
                    $result['collections'] = $collections;
                }
                if ($command === 'data' || $command === 'all') {
                    $result['entries'] = $entries;
                }

                echo "data: " . json_encode($result) . "\n\n";
                flush();
            } catch (\Throwable $e) {
                $this->logger->error('AI chat failed', ['exception' => $e]);
                echo "data: " . json_encode(['reply' => "Désolé, une erreur est survenue. Réessaie.", 'error' => 'AI chat failed']) . "\n\n";
                flush();
            }
        }, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache', 'X-Accel-Buffering' => 'no']);
    }

    /** Construit le prompt système adapté à  la commande. */
    private function buildSystemPrompt(string $command, string $namingRules, string $endUserBlock, string $context): string
    {
        $agentToolsBlock = "## AGENT MODE — Tools Available\n"
            . "You have access to real CMS tools. When the user asks for BULK operations\n"
            . "(creating dozens of entries, generating images, modifying or deleting content),\n"
            . "respond with a tool execution plan instead of plain JSON.\n\n"
            . "Format:\n"
            . "```json\n"
            . "{\n"
            . "  \"plan\": \"Resume de ce que je vais faire\",\n"
            . "  \"actions\": [\n"
            . "    { \"tool\": \"explore_schema\", \"params\": {} },\n"
            . "    { \"tool\": \"create_collections\", \"params\": { \"collections\": [...] } },\n"
            . "    { \"tool\": \"create_entries\", \"params\": { \"collection\": \"slug\", \"entries\": [...], \"locales\": [\"fr\",\"en\",\"es\",\"ar\"] } },\n"
            . "    { \"tool\": \"update_entries\", \"params\": { \"collection\": \"slug\", \"uuids\": [...], \"patch\": {...} } },\n"
            . "    { \"tool\": \"delete_entries\", \"params\": { \"collection\": \"slug\", \"uuids\": [...] } },\n"
            . "    { \"tool\": \"generate_images\", \"params\": { \"prompts\": [{\"label\":\"Hero\",\"description\":\"Dark dashboard...\"}] } }\n"
            . "  ]\n"
            . "}\n"
            . "```\n\n"
            . "Tools:\n"
            . "- explore_schema: returns all collections with their fields\n"
            . "- read_entries(collection, locale?, limit?): reads existing entries\n"
            . "- create_collections(collections): creates collections + fields (auto-confirmed)\n"
            . "- create_entries(collection, entries, locales?): bulk create (auto-confirmed, supports multi-locale)\n"
            . "- update_entries(collection, uuids, patch): bulk update (preview required)\n"
            . "- delete_entries(collection, uuids): bulk soft-delete (preview + confirmation required)\n"
            . "- generate_images(prompts): AI image generation + auto-upload to media library\n\n"
            . "RULES:\n"
            . "- Use tools for bulk operations (3+ entries, multi-locale, mass update/delete).\n"
            . "- Use standard JSON format for simple /schema /data /all queries (1-2 collections, 3-5 entries).\n"
            . "- ALWAYS start with a \"plan\" summary.\n"
            . "- For multi-locale: use the locales array. The system creates entries in all specified locales.\n"
            . "- For images: describe what you want visually. The system auto-generates and uploads.\n\n";

        $baseGuidelines = "## Field types available\n"
            . "text, longtext, richtext, slug, email, password, number, decimal, boolean, date, datetime, time, color, json, enumeration, media, relation\n\n"
            . "CRITICAL for relation fields: MUST include \"options\": { \"targetCollection\": \"slug_of_target\" } (e.g., \"end_users\" for user references).\n"
            . "CRITICAL for enumeration fields: MUST include \"options\": { \"values\": [\"option1\", \"option2\"] }.\n\n"
            . "## API auto-générée par collection\n"
            . "Chaque collection expose automatiquement :\n"
            . "- REST: GET /api/{project}/{slug} (liste paginée), POST (créer), GET/PATCH/DELETE /api/{project}/{slug}/{uuid}\n"
            . "- GraphQL: POST /api/projects/{project}/graphql\n"
            . "- OpenAPI: GET /api/{project}/openapi.json\n"
            . "- Auth: Bearer token (API token) ou JWT (end-users via /auth/login, /auth/register)\n"
            . "- Fichiers: GET/POST /api/{project}/files\n";

        $dataPrompt = "You are a professional content writer for a CMS. Generate REAL, publication-ready content.\n\n" . $agentToolsBlock
            . "## CRITICAL — Output format\n"
            . "You MUST output EXACTLY ONE ```json fenced code block. NO text before it. NO text after it. NO markdown tables. NO bullet lists. NO 'Voici les données'. ONLY the JSON block.\n\n"
            . "```json`n{\n  \"entries\": [\n    {\n      \"collection\": \"slug_of_collection\",\n      \"entries\": [\n        { \"title\": \"Titre pro\", \"slug\": \"titre-pro\", ... }\n      ]\n    }\n  ]\n}\n```\n\n"
            . "## Rules\n"
            . "- Write content in French.\n"
            . "- Content MUST be professional — NO lorem ipsum.\n"
            . "- Generate 3-5 entries minimum per collection.\n"
            . "- Fill ALL non-system fields for each entry.\n"
            . "- System fields to NEVER include: uuid, id, status, locale, created_at, updated_at, deleted_at.\n"
            . "- Dates: realistic and recent (2025-2026). Emails: real-looking.\n"
            . "- For relation fields: use the target slug as value (e.g., \"end_users\").\n"
            . "- For enumeration fields: use one of the configured values.\n"
            . "- When generating EndUsers, include: email, name, and custom fields.\n\n"
            . $endUserBlock . "\n\n"
            . $baseGuidelines . "\n"
            . "## Current project context\n" . $context;

        $allPrompt = "You are a CMS schema architect AND professional content writer. Generate BOTH schema AND content.\n\n" . $agentToolsBlock
            . "## CRITICAL — Output format\n"
            . "You MUST output EXACTLY ONE ```json fenced code block. NO text before/after. NO markdown tables. ONLY the JSON:\n\n"
            . "```json`n{\n  \"collections\": [...],\n  \"entries\": [{\"collection\":\"blog_posts\",\"entries\":[{\"title\":\"...\",\"slug\":\"...\"}]}]\n}\n```\n\n"
            . "## Rules\n"
            . "- Write in French — but schema names/slugs MUST stay in English.\n"
            . "- You can only PROPOSE. User clicks « Appliquer » to make it real.\n"
            . "- Content MUST be professional — NO lorem ipsum.\n"
            . "- For relation: \"options\":{\"targetCollection\":\"slug\"} MANDATORY.\n"
            . "- For enumeration: \"options\":{\"values\":[...]} MANDATORY.\n"
            . "- Generate 3-5 entries per collection. Max 5 collections, max 10 fields each.\n\n"
            . $namingRules . "\n\n"
            . $baseGuidelines . "\n"
            . $endUserBlock . "\n\n"
            . "## Current project context\n" . $context;

        $schemaPrompt = "You are a CMS schema architect. Design collections based on user requirements.\n\n" . $agentToolsBlock
            . "## Rules\n"
            . "- Always respond in French — but schema name/slug values MUST stay in English.\n"
            . "- You can only PROPOSE. User clicks « Appliquer » to persist.\n"
            . "- NEVER claim a collection is 'créée' — say 'proposée'.\n"
            . "- Include FULL schema as valid JSON in a ```json fenced code block.\n"
            . "- Return COMPLETE collection(s), not just changed fields.\n"
            . "- JSON structure (IMPORTANT: relation fields MUST have targetCollection, enum MUST have values):\n"
            . "```json`n{\n  \"collections\": [{\n    \"name\":\"BlogPosts\",\"slug\":\"blog_posts\",\"description\":\"Blog articles\",\"isSingleton\":false,\n    \"fields\":[\n      {\"name\":\"title\",\"slug\":\"title\",\"type\":\"text\",\"isRequired\":true},\n      {\"name\":\"slug\",\"slug\":\"slug\",\"type\":\"slug\",\"isRequired\":true},\n      {\"name\":\"body\",\"slug\":\"body\",\"type\":\"richtext\",\"isRequired\":true},\n      {\"name\":\"author\",\"slug\":\"author\",\"type\":\"relation\",\"isRequired\":true,\"options\":{\"targetCollection\":\"end_users\"}},\n      {\"name\":\"category\",\"slug\":\"category\",\"type\":\"enumeration\",\"isRequired\":false,\"options\":{\"values\":[\"tech\",\"lifestyle\"]}}\n    ]\n  }]\n}\n```\n\n"
            . $namingRules . "\n\n"
            . $baseGuidelines . "\n"
            . "## Guidelines\n"
            . "- Use \"slug\" type for URL-friendly identifiers (mark required).\n"
            . "- Use \"media\" for images/photos/files, \"richtext\" for HTML content.\n"
            . "- Use \"date\" for dates, \"datetime\" for timestamps.\n"
            . "- If modifying existing collections, reference by name, return complete collection.\n"
            . "- Generate 3-5 fields per collection minimum.\n"
            . "- Maximum 5 collections per response. Maximum 10 fields per collection.\n\n"
            . $endUserBlock . "\n\n"
            . "## Current project context\n" . $context;

        return match ($command) {
            'data' => $dataPrompt,
            'all'  => $allPrompt,
            default => $schemaPrompt,
        };
    }
    /** Fallback quand aucun provider IA n'est configuré. */
    private function commandFallback(string $command, string $prompt): JsonResponse
    {
        $schema = $this->ruleBasedSchema($prompt);
        $schema['collections'] = $this->normalizeSchema($schema['collections'] ?? []);
        $names = array_map(fn ($c) => $c['name'], $schema['collections'] ?? []);

        $reply = $names === []
            ? "Je n'ai pas pu générer de schéma pour cette demande. Peux-tu être plus précis ?"
            : "Voici un schéma de base pour : " . implode(', ', $names) . ". Tu peux l'appliquer et le modifier manuellement.";

        $response = ['reply' => $reply];
        if ($command !== 'data') {
            $response['collections'] = $schema['collections'] ?? [];
        } else {
            $response['entries'] = null;
        }
        return $this->json($response);
    }

    /**
     * Extraction JSON améliorée : cherche "collections" ET/OU "entries".
     */
    private function extractJsonResponse(string $content): ?array
    {
        // Priorité aux blocs ```json â€¦ ```
        if (preg_match_all('/```(?:json)?\s*([\s\S]*?)```/', $content, $blocks)) {
            foreach ($blocks[1] as $block) {
                $raw = $this->balancedJsonContainingAny($block, ['collections', 'entries']);
                if ($raw !== null) {
                    $data = json_decode($raw, true);
                    if (is_array($data) && (isset($data['collections']) || isset($data['entries']))) {
                        return ['data' => $data, 'raw' => $raw];
                    }
                }
            }
        }
        // Sinon, balayer tout le contenu
        $raw = $this->balancedJsonContainingAny($content, ['collections', 'entries']);
        if ($raw !== null) {
            $data = json_decode($raw, true);
            if (is_array($data) && (isset($data['collections']) || isset($data['entries']))) {
                return ['data' => $data, 'raw' => $raw];
            }
        }
        return null;
    }

    /**
     * Comme balancedJsonContaining mais accepte plusieurs clés possibles.
     */
    private function balancedJsonContainingAny(string $text, array $needles): ?string
    {
        foreach ($needles as $needle) {
            $result = $this->balancedJsonContaining($text, $needle);
            if ($result !== null) {
                return $result;
            }
        }
        return null;
    }

    /**
     * Crée des entrées de contenu générées par l'IA (/data ou /all).
     */
    #[Route('/api/projects/{uuid}/studio/apply-entries', name: 'studio_apply_entries', methods: ['POST'])]
    public function applyEntries(string $uuid, Request $request): JsonResponse
    {
        $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        if (!$project) return $this->json(['error' => 'Projet introuvable'], 404);
        $this->denyAccessUnlessGranted('project.manage', $project);

        $body = json_decode($request->getContent(), true);
        $collectionSlug = $body['collection'] ?? '';
        $entryData = $body['entry'] ?? [];

        if ($collectionSlug === '' || empty($entryData)) {
            return $this->json(['error' => 'Collection et entry requis'], 422);
        }

        // Cas spécial EndUsers
        if ($collectionSlug === 'end_users') {
            return $this->createEndUserEntry($project, $entryData);
        }

        // Collection standard
        $collection = $this->em->getRepository(Collection::class)
            ->findOneBy(['project' => $project, 'slug' => $collectionSlug, 'deletedAt' => null]);

        if (!$collection) {
            return $this->json(['error' => "Collection '$collectionSlug' introuvable"], 404);
        }

        $entry = new \App\Entity\ContentEntry();
        $entry->project = $project;
        $entry->collection = $collection;
        $entry->locale = $project->defaultLocale;
        $entry->status = $entryData['status'] ?? 'published';

        $this->em->persist($entry);
        $this->em->flush(); // flush pour avoir l'ID avant les field values

        // Sauvegarder les field values
        $this->saveFieldValues($entry, $collection, $entryData);

        $this->em->flush();

        return $this->json(['success' => true, 'uuid' => $entry->uuid?->toRfc4122()], 201);
    }

    private function createEndUserEntry(Project $project, array $data): JsonResponse
    {
        $email = $data['email'] ?? null;
        if (!$email) {
            return $this->json(['error' => 'Email requis pour EndUser'], 422);
        }

        // Vérifier si l'utilisateur existe déjà 
        $existing = $this->em->getRepository(\App\Entity\EndUser::class)
            ->findOneBy(['project' => $project, 'email' => $email]);

        if ($existing) {
            return $this->json(['success' => true, 'uuid' => $existing->uuid?->toRfc4122(), 'existed' => true]);
        }

        $endUser = new \App\Entity\EndUser($project, $email);
        $endUser->name = $data['name'] ?? '';
        $endUser->status = 'active'; // Toujours actif à la création, non modifiable par l'IA
        $endUser->password = $this->passwordHasher->hashPassword($endUser, bin2hex(random_bytes(16)));

        // Champs personnalisés : collecter tout ce qui n'est pas système
        // Les EndUserFields (définis dans le Schema Builder EndUser) sont attendus par nom.
        $customData = [];
        $systemFields = ['email', 'name', 'status', 'avatar_url', 'password', 'uuid', 'id', 'created_at', 'updated_at', 'collection', 'entries', 'token_version'];
        foreach ($data as $k => $v) {
            if (!in_array($k, $systemFields, true)) {
                $customData[$k] = $v;
            }
        }
        if ($customData !== []) {
            $endUser->customFields = $customData;
        }

        $this->em->persist($endUser);
        $this->em->flush();

        return $this->json(['success' => true, 'uuid' => $endUser->uuid?->toRfc4122()], 201);
    }

    /**
     * Sauvegarde les field values pour une ContentEntry (réutilise la logique du ContentController).
     */
    private function saveFieldValues(\App\Entity\ContentEntry $entry, Collection $collection, array $data): void
    {
        $fields = $this->em->getRepository(Field::class)->findByCollection($collection);
        $fieldMap = [];
        foreach ($fields as $f) {
            $fieldMap[$f->slug] = $f;
        }

        foreach ($data as $slug => $value) {
            if (!isset($fieldMap[$slug])) continue;
            $field = $fieldMap[$slug];

            $fv = new \App\Entity\ContentFieldValue();
            $fv->contentEntry = $entry;
            $fv->field = $field;

            $this->fieldValueHydrator->hydrate($fv, $value, $field->type);

            $this->em->persist($fv);
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
                'entries' => $m->entries,
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
    /** Prépare URL + options pour l'appel HTTP vers le provider IA. */
    private function buildAiRequestArgs(string $provider, string $apiKey, string $model, string $endpoint, array $messages): array
    {
        $headers = ['Content-Type' => 'application/json'];

        if ($provider === 'gemini') {
            $headers['x-goog-api-key'] = $apiKey;
            $parts = [];
            foreach ($messages as $m) {
                $parts[] = ['text' => $m['content']];
            }
            return [$endpoint, ['headers' => $headers, 'json' => ['contents' => [['parts' => $parts]]], 'timeout' => 120]];
        }

        if ($provider === 'anthropic') {
            $headers['x-api-key'] = $apiKey;
            $headers['anthropic-version'] = '2023-06-01';
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

        return [$endpoint, ['headers' => $headers, 'json' => $body, 'timeout' => 120]];
    }

    /** Extrait le texte de la réponse selon le format du provider. */
    private function parseAiContent(string $provider, array $data): string
    {
        return match ($provider) {
            'anthropic' => $data['content'][0]['text'] ?? '',
            'gemini'    => $data['candidates'][0]['content']['parts'][0]['text'] ?? '',
            default     => $data['choices'][0]['message']['content'] ?? '',
        };
    }

    private function callAiApi(string $provider, string $apiKey, string $model, string $endpoint, array $messages): string
    {
        [$url, $options] = $this->buildAiRequestArgs($provider, $apiKey, $model, $endpoint, $messages);
        return $this->parseAiContent($provider, $this->httpClient->request('POST', $url, $options)->toArray());
    }

    /**
     * Construit le message utilisateur, avec vision si le provider le supporte et si une image est jointe.
     */
    private function buildUserMessage(string $provider, string $prompt, ?array $attachment, ?\App\Entity\Project $project = null): array
    {
        if ($attachment === null) {
            return ['role' => 'user', 'content' => $prompt];
        }

        $mimeType  = (string) ($attachment['mimeType'] ?? '');
        $base64    = (string) ($attachment['base64'] ?? '');
        $mediaUuid = isset($attachment['mediaUuid']) ? (string) $attachment['mediaUuid'] : null;
        $isImage   = str_starts_with($mimeType, 'image/');

        // Résoudre l'UUID médiathèque → base64 si image
        if ($mediaUuid !== null && $isImage && $base64 === '' && $project !== null) {
            $base64 = $this->resolveMediaAsBase64($mediaUuid, $project) ?? '';
        }

        // Vision disponible uniquement pour OpenAI, Anthropic et Gemini
        $visionProviders = ['openai', 'anthropic', 'gemini'];
        if ($isImage && $base64 !== '' && in_array($provider, $visionProviders, true)) {
            return $this->buildVisionMessage($provider, $prompt, $mimeType, $base64);
        }

        // Fallback texte : note dans le prompt
        $name = (string) ($attachment['name'] ?? 'fichier');
        $note = "\n\n[Fichier joint : {$name} — analyse visuelle non disponible avec ce provider]";
        return ['role' => 'user', 'content' => $prompt . $note];
    }

    /**
     * Construit un message multi-part avec vision selon le format du provider.
     */
    private function buildVisionMessage(string $provider, string $prompt, string $mimeType, string $base64): array
    {
        if ($provider === 'openai') {
            return [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $prompt],
                    ['type' => 'image_url', 'image_url' => ['url' => "data:{$mimeType};base64,{$base64}"]],
                ],
            ];
        }

        if ($provider === 'anthropic') {
            return [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $prompt],
                    ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mimeType, 'data' => $base64]],
                ],
            ];
        }

        // Gemini — format intermédiaire reconnu par callAiApi()
        return [
            'role' => 'user',
            'content' => $prompt,
            '_vision' => ['mimeType' => $mimeType, 'base64' => $base64],
        ];
    }

    /**
     * Résout un UUID médiathèque en chaîne base64 (pour vision).
     * L'entité Media a: $uuid (Uuid), $fileName (string), path physique: public/uploads/media/{fileName}
     */
    private function resolveMediaAsBase64(string $mediaUuid, \App\Entity\Project $project): ?string
    {
        $media = $this->em->getRepository(\App\Entity\Media::class)->findOneBy([
            'uuid'    => $mediaUuid,
            'project' => $project,
        ]);
        if ($media === null || $media->fileName === null) return null;

        // basename() empêche toute traversée de chemin via un fileName compromis
        $fullPath = $this->getParameter('kernel.project_dir') . '/public/uploads/media/' . basename($media->fileName);
        if (!is_file($fullPath)) return null;

        $content = file_get_contents($fullPath);
        return $content !== false ? base64_encode($content) : null;
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

        // Collecter les identifiants des collections envoyées pour savoir
        // lesquelles doivent être supprimées (celles en DB mais absentes du payload).
        $keptCollectionUuids = [];
        $keptCollectionSlugs = [];

        // ── Passe 1 : upsert des collections (sans leurs champs) ──
        // Un flush intermédiaire assigne les ids des nouvelles collections,
        // indispensable pour résoudre les relations slug→id de la passe 2.
        $collectionPairs = [];
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
            $collection->deletedAt = null; // Restaurer si soft-deletée auparavant

            if (isset($colData['settings'])) {
                $collection->settings = $colData['settings'];
            }

            $keptCollectionUuids[] = $collection->uuid?->toRfc4122();
            $keptCollectionSlugs[] = $slug;
            $this->em->persist($collection);

            $collectionPairs[] = [$collection, $colData];
        }
        $this->em->flush();

        // ── Passe 2 : upsert des champs, options relation normalisées ──
        foreach ($collectionPairs as [$collection, $colData]) {
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
                $field->options = $this->normalizeFieldOptions($field->type, $fieldData['options'] ?? null, $project);
                $field->order = $order++;

                $this->em->persist($field);
            }

            // Soft-delete removed fields
            foreach ($existingSlugs as $oldField) {
                $oldField->deletedAt = new \DateTimeImmutable();
                $this->em->persist($oldField);
            }
        }

        // Soft-delete collections that are in DB but absent from the request
        $allDbCollections = $this->em->getRepository(Collection::class)
            ->findBy(['project' => $project, 'deletedAt' => null]);
        $deleted = 0;
        foreach ($allDbCollections as $dbCol) {
            $uuid = $dbCol->uuid?->toRfc4122();
            // Garder si UUID présent dans la liste, ou slug présent (fallback pour
            // les nouvelles collections dont l'UUID n'est assigné qu'au flush).
            $isKept = ($uuid !== null && in_array($uuid, $keptCollectionUuids, true))
                || in_array($dbCol->slug, $keptCollectionSlugs, true);
            if (!$isKept) {
                $dbCol->deletedAt = new \DateTimeImmutable();
                $this->em->persist($dbCol);
                $deleted++;
            }
        }

        $this->em->flush();

        return $this->json([
            'success' => true,
            'created' => $created,
            'updated' => $updated,
            'deleted' => $deleted,
        ]);
    }

    // ════════════════════ AGENT IA v3 — Endpoints ════════════════════

    /**
     * Retourne le profil de capacités du provider IA configuré.
     */
    #[Route('/api/projects/{uuid}/studio/ai-capabilities', name: 'studio_ai_capabilities', methods: ['GET'])]
    public function aiCapabilities(string $uuid): JsonResponse
    {
        $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        if (!$project) return $this->json(['error' => 'Projet introuvable'], 404);
        $this->denyAccessUnlessGranted('project.view', $project);

        return $this->json($this->aiService->getCapabilities());
    }

    /**
     * Exécute un plan d'actions généré par l'IA (collections, entrées, images).
     */
    #[Route('/api/projects/{uuid}/studio/ai-execute', name: 'studio_ai_execute', methods: ['POST'])]
    public function aiExecute(string $uuid, Request $request): JsonResponse
    {
        $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        if (!$project) return $this->json(['error' => 'Projet introuvable'], 404);
        $this->denyAccessUnlessGranted('project.manage', $project);

        $body = json_decode($request->getContent(), true);
        $actions = $body['actions'] ?? [];
        if (empty($actions)) return $this->json(['error' => 'Aucune action fournie'], 422);

        $log = [];
        $halt = false;

        foreach ($actions as $action) {
            if ($halt) break;

            $tool   = $action['tool'] ?? '';
            $params = $action['params'] ?? [];

            try {
                $result = match ($tool) {
                    'create_collections' => $this->executeCreateCollections($project, $params),
                    'create_entries'     => $this->executeCreateEntries($project, $params),
                    'update_entries'     => $this->executeUpdateEntries($project, $params),
                    'delete_entries'     => $this->executeDeleteEntries($project, $params),
                    'generate_images'    => $this->executeGenerateImages($project, $params),
                    'read_entries'       => $this->executeReadEntries($project, $params),
                    'translate_entries'  => $this->executeTranslateEntries($project, $params),
                    'explore_schema'     => $this->executeExploreSchema($project),
                    default              => ['error' => "Tool '$tool' inconnu"],
                };
            } catch (\Throwable $e) {
                $this->logger->error('ai-execute tool failed', [
                    'tool'   => $tool,
                    'error'  => $e->getMessage(),
                    'trace'  => $e->getTraceAsString(),
                ]);
                $result = ['error' => 'Internal error executing tool. Check server logs for details.'];
            }

            // Prévenir le frontend si une confirmation est nécessaire
            if (in_array($tool, ['update_entries', 'delete_entries']) && !($body['auto_confirm'] ?? false)) {
                $result['needs_confirmation'] = true;
                $halt = true;
            }

            $log[] = ['tool' => $tool, 'result' => $result];
        }

        return $this->json(['log' => $log, 'halted' => $halt]);
    }

    /**
     * Génère une image via le provider IA et l'upload dans la médiathèque.
     */
    #[Route('/api/projects/{uuid}/studio/ai-generate-image', name: 'studio_ai_generate_image', methods: ['POST'])]
    public function aiGenerateImage(string $uuid, Request $request): JsonResponse
    {
        $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        if (!$project) return $this->json(['error' => 'Projet introuvable'], 404);
        $this->denyAccessUnlessGranted('project.manage', $project);

        $body = json_decode($request->getContent(), true);
        $prompt = $body['prompt'] ?? '';
        if ($prompt === '') return $this->json(['error' => 'Prompt requis'], 422);

        // Tenter la génération via le provider
        $imageUrl = $this->aiService->generateImage($prompt);
        if ($imageUrl === null) {
            // Fallback placeholder
            $placeholder = $this->aiService->generatePlaceholder($body['label'] ?? 'Jambo');
            return $this->json(['url' => $placeholder, 'type' => 'placeholder']);
        }

        // Télécharger l'image et l'uploader dans la médiathèque
        $tmpFile = null;
        try {
            $imageData = @file_get_contents($imageUrl);
            if ($imageData === false) throw new \RuntimeException('Download failed');

            $tmpFile = tempnam(sys_get_temp_dir(), 'jambo_ai_img_');
            file_put_contents($tmpFile, $imageData);

            $media = new \App\Entity\Media();
            $media->project = $project;
            $media->originalName = 'ai-generated-' . substr(md5($prompt), 0, 8) . '.png';
            $media->alt = $body['alt'] ?? $prompt;
            $media->setFile(new \Symfony\Component\HttpFoundation\File\File($tmpFile));
            $this->em->persist($media);
            $this->em->flush();

            return $this->json([
                'url'  => $media->getPublicUrl(),
                'uuid' => $media->uuid?->toRfc4122(),
                'type' => 'generated',
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('AI image generation upload failed', [
                'project' => $uuid,
                'error'   => $e->getMessage(),
            ]);
            $placeholder = $this->aiService->generatePlaceholder($body['label'] ?? 'Jambo');
            return $this->json(['url' => $placeholder, 'type' => 'placeholder', 'error' => 'Image generation failed. Using placeholder instead.']);
        } finally {
            if ($tmpFile !== null) @unlink($tmpFile);
        }
    }

    // ═══════════════ TOOL EXECUTORS ═══════════════

    /** Normalise les options d'un champ avant persistance (format canonique relation). */
    private function normalizeFieldOptions(string $type, ?array $options, Project $project): ?array
    {
        if ($type !== 'relation') {
            return $options;
        }

        return $this->relationOptionsNormalizer->normalize($options ?? [], $project, forStorage: true);
    }

    private function executeCreateCollections(Project $project, array $params): array
    {
        $collections = $params['collections'] ?? [];
        if (empty($collections)) return ['error' => 'Aucune collection fournie'];

        $normalized = $this->normalizeSchema($collections);
        $created = 0;

        // Passe 1 : persister les collections (flush pour assigner les ids,
        // nécessaires à la résolution des relations slug→id de la passe 2).
        $newPairs = [];
        foreach ($normalized as $colData) {
            if (empty($colData['name'])) continue;
            $slug = $colData['slug'];

            // Chercher d'abord par UUID (prioritaire), puis par slug
            $existing = null;
            if (!empty($colData['uuid'])) {
                $existing = $this->em->getRepository(Collection::class)
                    ->findOneBy(['project' => $project, 'uuid' => $colData['uuid'], 'deletedAt' => null]);
            }
            if (!$existing) {
                $existing = $this->em->getRepository(Collection::class)
                    ->findOneBy(['project' => $project, 'slug' => $slug, 'deletedAt' => null]);
            }
            if ($existing) continue;

            $collection = new Collection();
            $collection->project = $project;
            $collection->name = $colData['name'];
            $collection->slug = $slug;
            $collection->description = $colData['description'] ?? null;
            $collection->isSingleton = $colData['isSingleton'] ?? false;
            $this->em->persist($collection);

            $newPairs[] = [$collection, $colData];
            $created++;
        }
        $this->em->flush();

        // Passe 2 : champs avec options relation normalisées.
        foreach ($newPairs as [$collection, $colData]) {
            $order = 0;
            foreach ($colData['fields'] ?? [] as $fData) {
                if (empty($fData['name'])) continue;
                $field = new Field();
                $field->collection = $collection;
                $field->name = $fData['name'];
                $field->slug = $fData['slug'] ?: $collection->slug . '_' . $order;
                $field->type = $fData['type'] ?? 'text';
                $field->isRequired = $fData['isRequired'] ?? false;
                $field->options = $this->normalizeFieldOptions($field->type, $fData['options'] ?? null, $project);
                $field->order = $order++;
                $this->em->persist($field);
            }
        }
        $this->em->flush();
        return ['created' => $created, 'collections' => array_column($normalized, 'slug')];
    }

    private function executeCreateEntries(Project $project, array $params): array
    {
        $collectionSlug = $params['collection'] ?? '';
        $entries = $params['entries'] ?? [];
        $locales = $params['locales'] ?? null; // null = locale par défaut uniquement

        if ($collectionSlug === '' || empty($entries)) {
            return ['error' => 'Collection et entries requis'];
        }

        // EndUsers
        if ($collectionSlug === 'end_users') {
            $created = 0; $errors = 0;
            foreach ($entries as $entryData) {
                try {
                    $email = $entryData['email'] ?? null;
                    if (!$email) { $errors++; continue; }
                    $existing = $this->em->getRepository(\App\Entity\EndUser::class)
                        ->findOneBy(['project' => $project, 'email' => $email]);
                    if ($existing) continue;
                    $endUser = new \App\Entity\EndUser($project, $email);
                    $endUser->name = $entryData['name'] ?? '';
                    $endUser->password = $this->passwordHasher->hashPassword($endUser, bin2hex(random_bytes(16)));
                    $customData = [];
                    foreach ($entryData as $k => $v) {
                        if (!in_array($k, ['email','name','status','password'], true)) $customData[$k] = $v;
                    }
                    if ($customData) $endUser->customFields = $customData;
                    $this->em->persist($endUser);
                    $created++;
                } catch (\Throwable $e) {
                    $errors++;
                    $this->logger->error('executeCreateEntries end_users failed', [
                        'email' => $entryData['email'] ?? '?',
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            $this->em->flush();
            return ['created' => $created, 'errors' => $errors, 'note' => 'Mot de passe aleatoire. Utilisateurs crees sans possibilite de connexion directe.'];
        }

        // Collection standard
        $collection = $this->em->getRepository(Collection::class)
            ->findOneBy(['project' => $project, 'slug' => $collectionSlug, 'deletedAt' => null]);
        if (!$collection) return ['error' => "Collection '$collectionSlug' introuvable"];

        $targetLocales = $locales ?? [$project->defaultLocale];
        $created = 0; $errors = 0;

        foreach ($entries as $entryData) {
            foreach ($targetLocales as $locale) {
                try {
                    $entry = new \App\Entity\ContentEntry();
                    $entry->project = $project;
                    $entry->collection = $collection;
                    $entry->locale = $locale;
                    $entry->status = $entryData['status'] ?? 'published';
                    $this->em->persist($entry);
                    $this->em->flush(); // flush pour générer l'UUID avant saveFieldValues
                    $this->saveFieldValues($entry, $collection, $entryData);
                    $created++;
                } catch (\Throwable $e) {
                    $errors++;
                    $this->logger->error('executeCreateEntries failed', [
                        'collection' => $collectionSlug,
                        'locale'     => $locale,
                        'error'      => $e->getMessage(),
                    ]);
                }
            }
        }
        $this->em->flush();
        return ['created' => $created, 'errors' => $errors];
    }

    private function executeUpdateEntries(Project $project, array $params): array
    {
        $collectionSlug = $params['collection'] ?? '';
        $uuids = $params['uuids'] ?? [];
        $patch = $params['patch'] ?? [];

        if (empty($uuids) || empty($patch)) return ['error' => 'uuids et patch requis'];
        if ($collectionSlug === '') return ['error' => 'Collection requise'];

        $collection = $this->em->getRepository(Collection::class)
            ->findOneBy(['project' => $project, 'slug' => $collectionSlug, 'deletedAt' => null]);
        if (!$collection) return ['error' => 'Collection introuvable'];

        // Pré-charger tous les champs (évite N+1 queries)
        $fields = $this->em->getRepository(Field::class)->findByCollection($collection);
        $fieldMap = [];
        foreach ($fields as $f) {
            if (!$f->isDeleted()) $fieldMap[$f->slug] = $f;
        }

        $updated = 0;
        foreach ($uuids as $uuid) {
            $entry = $this->em->getRepository(\App\Entity\ContentEntry::class)
                ->findOneBy(['uuid' => $uuid, 'collection' => $collection]);
            if (!$entry) continue;

            foreach ($patch as $slug => $value) {
                $field = $fieldMap[$slug] ?? null;
                if ($field === null) continue;

                // Chercher field value existante
                $fv = null;
                foreach ($entry->fieldValues as $existingFv) {
                    if ($existingFv->field?->id === $field->id) { $fv = $existingFv; break; }
                }
                if (!$fv) {
                    $fv = new \App\Entity\ContentFieldValue();
                    $fv->contentEntry = $entry;
                    $fv->field = $field;
                    $entry->fieldValues->add($fv);
                    $this->em->persist($fv);
                }

                $this->fieldValueHydrator->hydrate($fv, $value, $field->type);
            }
            $updated++;
        }
        $this->em->flush();
        return ['updated' => $updated];
    }

    private function executeDeleteEntries(Project $project, array $params): array
    {
        $collectionSlug = $params['collection'] ?? '';
        $uuids = $params['uuids'] ?? [];

        if ($collectionSlug === '') return ['error' => 'Collection requise'];
        if (empty($uuids)) return ['deleted' => 0];

        $collection = $this->em->getRepository(Collection::class)
            ->findOneBy(['project' => $project, 'slug' => $collectionSlug, 'deletedAt' => null]);
        if (!$collection) return ['error' => 'Collection introuvable'];

        $deleted = 0;
        foreach ($uuids as $uuid) {
            $entry = $this->em->getRepository(\App\Entity\ContentEntry::class)
                ->findOneBy(['uuid' => $uuid, 'collection' => $collection]);
            if ($entry) {
                $entry->deletedAt = new \DateTimeImmutable();
                $this->em->persist($entry);
                $deleted++;
            }
        }
        $this->em->flush();
        return ['deleted' => $deleted];
    }

    private function executeGenerateImages(Project $project, array $params): array
    {
        $prompts = $params['prompts'] ?? [];
        if (empty($prompts)) return ['error' => 'Aucun prompt'];

        $caps = $this->aiService->getCapabilities();
        if (!$caps['images']) {
            $results = [];
            foreach ($prompts as $p) {
                $results[] = [
                    'url'  => $this->aiService->generatePlaceholder($p['label'] ?? 'Jambo'),
                    'type' => 'placeholder',
                ];
            }
            return ['images' => $results, 'warning' => 'provider_images_indisponible'];
        }

        $results = [];
        foreach ($prompts as $p) {
            $tmpFile = null;
            try {
                $imageUrl = $this->aiService->generateImage($p['description'] ?? $p['label'] ?? '');
                if ($imageUrl) {
                    $imageData = @file_get_contents($imageUrl);
                    if ($imageData) {
                        $tmpFile = tempnam(sys_get_temp_dir(), 'jambo_ai_');
                        file_put_contents($tmpFile, $imageData);
                        $media = new \App\Entity\Media();
                        $media->project = $project;
                        $media->originalName = 'ai-' . substr(md5($p['description'] ?? ''), 0, 8) . '.png';
                        $media->alt = $p['alt'] ?? ($p['label'] ?? '');
                        $media->setFile(new \Symfony\Component\HttpFoundation\File\File($tmpFile));
                        $this->em->persist($media);
                        $this->em->flush();
                        $results[] = ['url' => $media->getPublicUrl(), 'uuid' => $media->uuid?->toRfc4122(), 'type' => 'generated'];
                        continue;
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->error('executeGenerateImages failed', ['error' => $e->getMessage()]);
            } finally {
                if ($tmpFile !== null) @unlink($tmpFile);
            }
            $results[] = ['url' => $this->aiService->generatePlaceholder($p['label'] ?? 'Jambo'), 'type' => 'placeholder'];
        }
        return ['images' => $results];
    }

    private function executeTranslateEntries(Project $project, array $params): array
    {
        $collectionSlug = $params['collection'] ?? '';
        $sourceLocale  = $params['source_locale'] ?? $project->defaultLocale;
        $targetLocales = $params['target_locales'] ?? [];
        $uuids         = $params['uuids'] ?? [];

        if ($collectionSlug === '' || empty($targetLocales)) {
            return ['error' => 'Collection et target_locales requis'];
        }

        // Récupérer la collection
        $collection = $this->em->getRepository(Collection::class)
            ->findOneBy(['project' => $project, 'slug' => $collectionSlug, 'deletedAt' => null]);
        if (!$collection) return ['error' => "Collection '$collectionSlug' introuvable"];

        // Récupérer les entrées source
        if (empty($uuids)) {
            $entries = $this->em->getRepository(\App\Entity\ContentEntry::class)
                ->findByCollectionPaginated($collection, 1, 200, $sourceLocale);
        } else {
            $entries = [];
            foreach ($uuids as $uuid) {
                $e = $this->em->getRepository(\App\Entity\ContentEntry::class)
                    ->findOneBy(['uuid' => $uuid, 'collection' => $collection, 'locale' => $sourceLocale]);
                if ($e) $entries[] = $e;
            }
        }

        if (empty($entries)) return ['error' => 'Aucune entree source trouvee'];

        $created = 0; $errors = 0;

        foreach ($entries as $sourceEntry) {
            // Extraire les valeurs texte de l'entrée source
            $fieldValues = $this->extractEntryFieldValues($sourceEntry);
            // Filtrer les champs textuels uniquement (pas les relations, media, dates)
            $textFields = [];
            foreach ($fieldValues as $slug => $val) {
                // Garder tous les champs; l'IA traduira les textes, les autres restent inchangés
                if (is_string($val) && $val !== '') $textFields[$slug] = $val;
            }

            if (empty($textFields)) continue;

            // Construire le prompt de traduction
            $promptJson = json_encode($textFields, JSON_UNESCAPED_UNICODE);
            $langNames = implode(', ', $targetLocales);

            foreach ($targetLocales as $targetLocale) {
                try {
                    // Appeler l'IA pour traduire
                    $aiPrompt = "Translate the following JSON content from $sourceLocale to $targetLocale. "
                        . "Return ONLY the translated JSON, same structure. Preserve all keys exactly."
                        . "\n\n$promptJson";

                    $translated = $this->aiService->ask($aiPrompt);
                    $translatedData = json_decode($translated, true);

                    if (!is_array($translatedData)) {
                        $errors++; continue;
                    }

                    // Créer la nouvelle entrée dans la locale cible
                    $newEntry = new \App\Entity\ContentEntry();
                    $newEntry->project = $project;
                    $newEntry->collection = $collection;
                    $newEntry->locale = $targetLocale;
                    $newEntry->status = $sourceEntry->status;
                    $this->em->persist($newEntry);
                    $this->em->flush();

                    // Fusionner: valeurs traduites + valeurs non-textuelles de la source
                    $mergedData = array_merge($fieldValues, $translatedData);
                    $this->saveFieldValues($newEntry, $collection, $mergedData);
                    $created++;
                } catch (\Throwable $e) {
                    $errors++;
                    $this->logger->error('executeTranslateEntries failed', [
                        'collection' => $collectionSlug,
                        'locale'     => $targetLocale,
                        'error'      => $e->getMessage(),
                    ]);
                }
            }
        }
        $this->em->flush();
        return ['created' => $created, 'errors' => $errors, 'source_locale' => $sourceLocale, 'target_locales' => $targetLocales];
    }

    private function executeReadEntries(Project $project, array $params): array
    {
        $collectionSlug = $params['collection'] ?? '';
        $locale = $params['locale'] ?? $project->defaultLocale;
        $limit = min(100, $params['limit'] ?? 50);

        if ($collectionSlug === '') return ['error' => 'Collection requise'];

        $collection = $this->em->getRepository(Collection::class)
            ->findOneBy(['project' => $project, 'slug' => $collectionSlug, 'deletedAt' => null]);
        if (!$collection) return ['entries' => []];

        $entries = $this->em->getRepository(\App\Entity\ContentEntry::class)
            ->findByCollectionPaginated($collection, 1, $limit, $locale);

        return [
            'collection' => $collectionSlug,
            'count'      => count($entries),
            'entries'    => array_map(fn ($e) => [
                'uuid' => $e->uuid?->toRfc4122(),
                'locale' => $e->locale,
                'status' => $e->status,
                'fields' => $this->extractEntryFieldValues($e),
            ], $entries),
        ];
    }

    private function executeExploreSchema(Project $project): array
    {
        $collections = $this->em->getRepository(Collection::class)
            ->findBy(['project' => $project, 'deletedAt' => null], ['order' => 'ASC']);

        return [
            'collections' => array_map(function (Collection $c) {
                $fields = [];
                foreach ($c->fields as $f) {
                    if ($f->isDeleted()) continue;
                    $fields[] = [
                        'name' => $f->name, 'slug' => $f->slug,
                        'type' => $f->type, 'isRequired' => $f->isRequired,
                        'options' => $f->options,
                    ];
                }
                return [
                    'name' => $c->name, 'slug' => $c->slug,
                    'description' => $c->description,
                    'isSingleton' => $c->isSingleton,
                    'fields' => $fields,
                ];
            }, $collections),
        ];
    }

    private function extractEntryFieldValues(\App\Entity\ContentEntry $entry): array
    {
        $values = [];
        foreach ($entry->fieldValues as $fv) {
            $values[$fv->field?->slug ?? ''] = $fv->getValue();
        }
        return $values;
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
