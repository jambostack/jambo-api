<?php

namespace App\Controller;

use App\Entity\Project;
use App\GraphQL\SchemaGenerator;
use App\Service\ApiTokenChecker;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use GraphQL\Error\DebugFlag;
use GraphQL\GraphQL as GraphQLExecutor;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\AST\FieldNode;
use GraphQL\Language\AST\FragmentDefinitionNode;
use GraphQL\Language\AST\FragmentSpreadNode;
use GraphQL\Language\AST\InlineFragmentNode;
use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Language\AST\SelectionSetNode;
use GraphQL\Language\Parser;
use GraphQL\Validator\DocumentValidator;
use GraphQL\Validator\Rules\DisableIntrospection;
use GraphQL\Validator\Rules\QueryComplexity;
use GraphQL\Validator\Rules\QueryDepth;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class GraphQLController extends AbstractController
{
    private const UUID_REGEX        = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';
    public const MAX_QUERY_LENGTH   = 65536; // 64 KB
    public const MAX_ALIASES        = 30;    // Limit alias amplification attacks
    public const MAX_OPERATIONS     = 1;     // Single operation per request (no batched operations)
    public const MAX_FRAGMENTS      = 10;    // Limit fragment definition spam
    public const MAX_DIRECTIVES     = 15;    // Limit directive injection/amplification
    public const MAX_SELECTIONS     = 150;   // Limit total field selections in AST
    public const MAX_AST_DEPTH      = 15;    // Limit maximum nesting depth of selection sets

    private const SECURITY_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options'        => 'DENY',
        'Cache-Control'          => 'no-store',
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private SchemaGenerator $schemaGenerator,
        private ?\Symfony\Component\RateLimiter\RateLimiterFactory $graphqlLimiter = null,
        private ?TranslatorInterface $translator = null,
        private ?ApiTokenChecker $tokenChecker = null,
        private ?\App\Service\AuditService $auditService = null,
    ) {}

    private function trans(string $key, string $default, array $parameters = []): string
    {
        if ($this->translator !== null) {
            $translated = $this->translator->trans($key, $parameters, 'messages');
            if ($translated !== $key) {
                return $translated;
            }
        }
        return strtr($default, $parameters);
    }

    private function secureJson(mixed $data, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, array_merge(self::SECURITY_HEADERS, $headers));
    }

    #[Route('/api/projects/{uuid}/graphql', name: 'graphql_endpoint', methods: ['POST', 'GET'])]
    public function handle(string $uuid, Request $request): Response
    {
        $identifier = $request->getClientIp() ?? 'anonymous';
        $user = null;
        try {
            $user = $this->getUser();
            if ($user !== null) {
                $identifier = 'user_' . $user->getUserIdentifier();
            }
        } catch (\Throwable) {}

        $apiToken = null;
        if ($user === null && $this->tokenChecker !== null) {
            $apiToken = $this->tokenChecker->resolve($request);
            if ($apiToken !== null) {
                $identifier = 'token_' . $apiToken->id;
            }
        }

        if ($this->graphqlLimiter !== null && false === $this->graphqlLimiter->create($identifier)->consume(1)->isAccepted()) {
            $this->auditService?->log(
                toolName: 'graphql_rate_limit_exceeded',
                project: null,
                input: ['identifier' => $identifier],
                output: null,
                status: 'failed',
                errorMessage: 'Rate limit exceeded for GraphQL endpoint',
                createdBy: $identifier,
                source: 'graphql',
            );
            return $this->secureJson(['errors' => [['message' => $this->trans('graphql.too_many_requests', 'Trop de requêtes GraphQL. Veuillez réessayer plus tard.')]]], 429);
        }

        if (!preg_match(self::UUID_REGEX, $uuid)) {
            return $this->secureJson(['errors' => [['message' => $this->trans('graphql.project_not_found', 'Projet introuvable.')]]], 404);
        }

        try {
            $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        } catch (\Throwable) {
            return $this->secureJson(['errors' => [['message' => $this->trans('graphql.project_not_found', 'Projet introuvable.')]]], 404);
        }
        if (!$project) {
            return $this->secureJson(['errors' => [['message' => $this->trans('graphql.project_not_found', 'Projet introuvable.')]]], 404);
        }

        if ($request->attributes->get('_project') !== $project) {
            $this->denyAccessUnlessGranted('project.view', $project);
        }

        if ($request->isMethod('GET')) {
            $query = $request->query->get('query');
            $rawVariables = $request->query->get('variables');
            if (is_string($rawVariables)) {
                $decoded = json_decode($rawVariables, true);
                $variables = is_array($decoded) ? $decoded : null;
            } else {
                $variables = $request->query->all('variables') ?: null;
            }
        } else {
            $rawContent = $request->getContent();
            if (strlen($rawContent) > self::MAX_QUERY_LENGTH) {
                return $this->secureJson(['errors' => [['message' => $this->trans('graphql.payload_too_large', 'Taille de payload GraphQL dépassée (max 64 Ko).')]]], 413);
            }
            $body = json_decode($rawContent, true);
            if (!is_array($body)) {
                return $this->secureJson(['errors' => [['message' => $this->trans('graphql.invalid_json', 'Corps JSON invalide.')]]], 400);
            }
            // ── Blocage des requêtes GraphQL en batch (array de queries) ─────────
            if (isset($body[0])) {
                $this->auditService?->log(
                    toolName: 'graphql_security_blocked',
                    project: $project,
                    input: ['reason' => 'batch_not_allowed'],
                    output: null,
                    status: 'failed',
                    errorMessage: 'Batch GraphQL queries not allowed',
                    createdBy: $identifier,
                    source: 'graphql',
                );
                return $this->secureJson(['errors' => [['message' => $this->trans('graphql.batch_not_allowed', 'Les requêtes GraphQL en batch ne sont pas autorisées. Envoyez une seule opération par requête.')]]], 400);
            }
            $query = $body['query'] ?? null;
            $variables = $body['variables'] ?? null;
        }

        if (!$query || !is_string($query)) {
            return $this->secureJson(['errors' => [['message' => $this->trans('graphql.query_required', 'Query GraphQL requise.')]]], 400);
        }

        if (strlen($query) > self::MAX_QUERY_LENGTH) {
            return $this->secureJson(['errors' => [['message' => $this->trans('graphql.query_too_large', 'Query GraphQL dépasse la taille maximale autorisée (64 Ko).')]]], 413);
        }

        if ($variables !== null && !is_array($variables)) {
            return $this->secureJson(['errors' => [['message' => $this->trans('graphql.invalid_variables', 'Les variables GraphQL doivent être un objet ou tableau.')]]], 400);
        }

        // ── Validation syntaxique & limitation de tokens ─────────────
        try {
            $document = Parser::parse($query, ['maxTokens' => 5000]);
        } catch (\GraphQL\Error\SyntaxError $e) {
            return $this->secureJson([
                'errors' => [[
                    'message' => $e->getMessage(),
                    'locations' => $e->getLocations(),
                ]],
            ], 400);
        } catch (\Throwable $e) {
            return $this->secureJson([
                'errors' => [['message' => $this->trans('graphql.parse_error', 'Erreur de syntaxe dans la requête GraphQL.')]],
            ], 400);
        }

        // ── Validation du nombre d'opérations et fragments ───────────
        $operations = [];
        $fragments = [];
        foreach ($document->definitions as $def) {
            if ($def instanceof OperationDefinitionNode) {
                $operations[] = $def;
            } elseif ($def instanceof FragmentDefinitionNode) {
                $fragments[] = $def;
            }
        }

        if (count($operations) > self::MAX_OPERATIONS) {
            $this->auditService?->log(
                toolName: 'graphql_security_blocked',
                project: $project,
                input: ['reason' => 'too_many_operations', 'operations' => count($operations)],
                output: null,
                status: 'failed',
                errorMessage: 'Too many GraphQL operations',
                createdBy: $identifier,
                source: 'graphql',
            );
            return $this->secureJson([
                'errors' => [['message' => $this->trans('graphql.too_many_operations', 'Une seule opération GraphQL est autorisée par requête.')]],
            ], 400);
        }

        if (count($fragments) > self::MAX_FRAGMENTS) {
            $this->auditService?->log(
                toolName: 'graphql_security_blocked',
                project: $project,
                input: ['reason' => 'too_many_fragments', 'fragments' => count($fragments)],
                output: null,
                status: 'failed',
                errorMessage: 'Too many GraphQL fragments',
                createdBy: $identifier,
                source: 'graphql',
            );
            return $this->secureJson([
                'errors' => [['message' => $this->trans(
                    'graphql.too_many_fragments',
                    sprintf('Trop de fragments GraphQL (max %d).', self::MAX_FRAGMENTS),
                    ['%max%' => self::MAX_FRAGMENTS]
                )]],
            ], 400);
        }

        // ── Contrôle approfondi de l'AST : profondeur, alias, directives, sélections ──
        $metrics = $this->inspectAstMetrics($document);

        if ($metrics['max_depth'] > self::MAX_AST_DEPTH) {
            $this->auditService?->log(
                toolName: 'graphql_security_blocked',
                project: $project,
                input: ['reason' => 'ast_depth_exceeded', 'depth' => $metrics['max_depth'], 'max' => self::MAX_AST_DEPTH],
                output: null,
                status: 'failed',
                errorMessage: 'GraphQL query AST depth exceeded',
                createdBy: $identifier,
                source: 'graphql',
            );
            return $this->secureJson([
                'errors' => [['message' => $this->trans(
                    'graphql.ast_depth_exceeded',
                    sprintf('Profondeur de requête GraphQL dépassée (max %d niveaux).', self::MAX_AST_DEPTH),
                    ['%max%' => self::MAX_AST_DEPTH]
                )]],
            ], 400);
        }

        if ($metrics['aliases'] > self::MAX_ALIASES) {
            $this->auditService?->log(
                toolName: 'graphql_security_blocked',
                project: $project,
                input: ['reason' => 'too_many_aliases', 'aliases' => $metrics['aliases'], 'max' => self::MAX_ALIASES],
                output: null,
                status: 'failed',
                errorMessage: 'Too many GraphQL aliases',
                createdBy: $identifier,
                source: 'graphql',
            );
            return $this->secureJson([
                'errors' => [['message' => $this->trans(
                    'graphql.too_many_aliases',
                    sprintf('Trop d\'alias de champs GraphQL (max %d). Réduisez le nombre d\'alias dans votre requête.', self::MAX_ALIASES),
                    ['%max%' => self::MAX_ALIASES]
                )]],
            ], 400);
        }

        if ($metrics['directives'] > self::MAX_DIRECTIVES) {
            $this->auditService?->log(
                toolName: 'graphql_security_blocked',
                project: $project,
                input: ['reason' => 'too_many_directives', 'directives' => $metrics['directives'], 'max' => self::MAX_DIRECTIVES],
                output: null,
                status: 'failed',
                errorMessage: 'Too many GraphQL directives',
                createdBy: $identifier,
                source: 'graphql',
            );
            return $this->secureJson([
                'errors' => [['message' => $this->trans(
                    'graphql.too_many_directives',
                    sprintf('Trop de directives GraphQL (max %d).', self::MAX_DIRECTIVES),
                    ['%max%' => self::MAX_DIRECTIVES]
                )]],
            ], 400);
        }

        if ($metrics['selections'] > self::MAX_SELECTIONS) {
            $this->auditService?->log(
                toolName: 'graphql_security_blocked',
                project: $project,
                input: ['reason' => 'too_many_selections', 'selections' => $metrics['selections'], 'max' => self::MAX_SELECTIONS],
                output: null,
                status: 'failed',
                errorMessage: 'Too many GraphQL selections',
                createdBy: $identifier,
                source: 'graphql',
            );
            return $this->secureJson([
                'errors' => [['message' => $this->trans(
                    'graphql.too_many_selections',
                    sprintf('Trop de sélections de champs GraphQL (max %d).', self::MAX_SELECTIONS),
                    ['%max%' => self::MAX_SELECTIONS]
                )]],
            ], 400);
        }

        // ── Détection subscription ───────────────────────────────────
        if ($this->isSubscriptionDocument($document)) {
            $projectUuid = $project->uuid->toRfc4122();
            return $this->handleSubscription($projectUuid, $document);
        }

        // ── Protection contre les mutations non autorisées ───────────
        if ($this->isMutationDocument($document)) {
            // Rejet absolu des mutations en GET (OWASP GraphQL / HTTP RFC)
            if ($request->isMethod('GET')) {
                $this->auditService?->log(
                    toolName: 'graphql_security_blocked',
                    project: $project,
                    input: ['reason' => 'mutation_via_get'],
                    output: null,
                    status: 'failed',
                    errorMessage: 'GraphQL mutations not allowed via GET',
                    createdBy: $identifier,
                    source: 'graphql',
                );
                return $this->secureJson([
                    'errors' => [['message' => $this->trans('graphql.mutations_not_allowed_via_get', 'Les mutations GraphQL ne sont pas autorisées via les requêtes GET.')]],
                ], 405);
            }

            // Vérification des droits sur ApiToken
            if ($apiToken !== null && !$apiToken->can('write') && !$apiToken->can('create') && !$apiToken->can('*')) {
                $this->auditService?->log(
                    toolName: 'graphql_mutation_denied',
                    project: $project,
                    input: ['query' => mb_substr($query, 0, 500)],
                    output: null,
                    status: 'failed',
                    errorMessage: 'Insufficient permissions for GraphQL mutation',
                    createdBy: $identifier,
                    source: 'graphql',
                );
                return $this->secureJson([
                    'errors' => [['message' => $this->trans('graphql.mutation_permission_denied', 'Permissions insuffisantes pour exécuter des mutations GraphQL.')]],
                ], 403);
            }

            // Vérification des droits sur Utilisateur session
            if ($user !== null && !$this->isGranted('ROLE_SUPER_ADMIN') && !$this->isGranted('project.manage', $project) && !$this->isGranted('content.create', $project) && !$this->isGranted('content.update', $project)) {
                $this->auditService?->log(
                    toolName: 'graphql_mutation_denied',
                    project: $project,
                    input: ['query' => mb_substr($query, 0, 500)],
                    output: null,
                    status: 'failed',
                    errorMessage: 'Insufficient permissions for GraphQL mutation',
                    createdBy: $identifier,
                    source: 'graphql',
                );
                return $this->secureJson([
                    'errors' => [['message' => $this->trans('graphql.mutation_permission_denied', 'Permissions insuffisantes pour exécuter des mutations GraphQL.')]],
                ], 403);
            }
        }

        // ── Query / Mutation standard ────────────────────────────────
        $schema = $this->schemaGenerator->buildSchema($project);

        $maxDepth = (int) ($_ENV['GRAPHQL_MAX_DEPTH'] ?? $_SERVER['GRAPHQL_MAX_DEPTH'] ?? 10);
        if ($maxDepth <= 0 || $maxDepth > 15) {
            $maxDepth = 10;
        }

        $maxComplexity = (int) ($_ENV['GRAPHQL_MAX_COMPLEXITY'] ?? $_SERVER['GRAPHQL_MAX_COMPLEXITY'] ?? 500);
        if ($maxComplexity <= 0 || $maxComplexity > 1000) {
            $maxComplexity = 500;
        }

        $validationRules = array_merge(
            DocumentValidator::defaultRules(),
            [
                new QueryDepth($maxDepth),
                new QueryComplexity($maxComplexity),
            ]
        );

        // En production, désactiver l'introspection GraphQL (__schema, __type)
        // pour empêcher l'énumération non autorisée du schéma et des modèles
        $env = (string) $this->getParameter('kernel.environment');
        $introspectionConfig = $_ENV['GRAPHQL_INTROSPECTION_ENABLED'] ?? $_SERVER['GRAPHQL_INTROSPECTION_ENABLED'] ?? null;
        $introspectionDisabled = $introspectionConfig !== null
            ? !filter_var($introspectionConfig, FILTER_VALIDATE_BOOL)
            : ($env === 'prod');

        if ($introspectionDisabled) {
            $validationRules[] = new DisableIntrospection(DisableIntrospection::ENABLED);
        }

        $isMutation = $this->isMutationDocument($document);
        $startTime = microtime(true);
        $result = GraphQLExecutor::executeQuery(
            schema: $schema,
            source: $document,
            variableValues: $variables,
            validationRules: $validationRules,
        );
        $durationMs = (int) round((microtime(true) - $startTime) * 1000);

        if ($isMutation) {
            $hasErrors = !empty($result->errors);
            $this->auditService?->log(
                toolName: 'graphql_mutation',
                project: $project,
                input: ['query' => mb_substr($query, 0, 500), 'variables' => $variables],
                output: $hasErrors ? ['errors_count' => count($result->errors)] : ['success' => true],
                status: $hasErrors ? 'failed' : 'success',
                errorMessage: $hasErrors ? $result->errors[0]->getMessage() : null,
                createdBy: $identifier,
                source: 'graphql',
                durationMs: $durationMs,
            );
        }

        $debug = $this->getParameter('kernel.debug')
            ? DebugFlag::INCLUDE_DEBUG_MESSAGE | DebugFlag::INCLUDE_TRACE
            : DebugFlag::NONE;

        return new JsonResponse($result->toArray($debug), 200, array_merge(self::SECURITY_HEADERS, [
            'Cache-Control' => 'no-store, private',
        ]));
    }

    // ─── Sécurité de l'AST ───────────────────────────────────────────────

    /**
     * Inspects the AST document to count aliases, total selections, directives, and nesting depth across
     * all operations and fragments, preventing amplification and resource exhaustion attacks.
     *
     * @return array{aliases: int, selections: int, directives: int, max_depth: int}
     */
    private function inspectAstMetrics(DocumentNode $document): array
    {
        $metrics = ['aliases' => 0, 'selections' => 0, 'directives' => 0, 'max_depth' => 0];

        foreach ($document->definitions as $def) {
            if ($def instanceof OperationDefinitionNode) {
                $metrics['directives'] += count($def->directives ?? []);
                if ($def->selectionSet !== null) {
                    $this->traverseSelectionSet($def->selectionSet, $metrics, 1);
                }
            } elseif ($def instanceof FragmentDefinitionNode) {
                $metrics['directives'] += count($def->directives ?? []);
                if ($def->selectionSet !== null) {
                    $this->traverseSelectionSet($def->selectionSet, $metrics, 1);
                }
            }
        }

        return $metrics;
    }

    private function traverseSelectionSet(SelectionSetNode $set, array &$metrics, int $currentDepth = 1): void
    {
        if ($currentDepth > $metrics['max_depth']) {
            $metrics['max_depth'] = $currentDepth;
        }

        if ($currentDepth > self::MAX_AST_DEPTH) {
            return;
        }

        foreach ($set->selections as $sel) {
            $metrics['selections']++;
            if ($sel instanceof FieldNode) {
                if ($sel->alias !== null) {
                    $metrics['aliases']++;
                }
                $metrics['directives'] += count($sel->directives ?? []);
                if ($sel->selectionSet !== null) {
                    $this->traverseSelectionSet($sel->selectionSet, $metrics, $currentDepth + 1);
                }
            } elseif ($sel instanceof InlineFragmentNode) {
                $metrics['directives'] += count($sel->directives ?? []);
                if ($sel->selectionSet !== null) {
                    $this->traverseSelectionSet($sel->selectionSet, $metrics, $currentDepth + 1);
                }
            } elseif ($sel instanceof FragmentSpreadNode) {
                $metrics['directives'] += count($sel->directives ?? []);
            }
        }
    }

    private function isMutationDocument(DocumentNode $document): bool
    {
        foreach ($document->definitions as $def) {
            if ($def instanceof OperationDefinitionNode && $def->operation === 'mutation') {
                return true;
            }
        }
        return false;
    }

    private function isSubscriptionDocument(DocumentNode $document): bool
    {
        foreach ($document->definitions as $def) {
            if ($def instanceof OperationDefinitionNode && $def->operation === 'subscription') {
                return true;
            }
        }
        return false;
    }

    // ─── Subscription Mercure ───────────────────────────────────────────

    private function handleSubscription(string $projectUuid, DocumentNode $document): JsonResponse
    {
        $mercureSecret = $this->hasParameter('mercure_jwt_secret') ? (string) $this->getParameter('mercure_jwt_secret') : '';
        if ($mercureSecret === '' || strlen($mercureSecret) < 32) {
            return $this->secureJson([
                'errors' => [['message' => $this->trans('graphql.mercure_not_configured', 'Mercure hub not configured. Install and start the Mercure Docker service.')]],
            ], 503);
        }

        // Extraire les topics depuis l'AST déjà parsé
        $topics = $this->extractTopicsFromDocument($projectUuid, $document);

        // Générer le JWT Mercure
        $jwtConfig = Configuration::forSymmetricSigner(
            new Sha256(),
            InMemory::plainText($mercureSecret),
        );

        $now = new DateTimeImmutable();
        $token = $jwtConfig->builder()
            ->issuedAt($now)
            ->expiresAt($now->modify('+1 hour'))
            ->withClaim('mercure', [
                'subscribe' => $topics,
                'publish'   => [],
            ])
            ->getToken($jwtConfig->signer(), $jwtConfig->signingKey())
            ->toString();

        $hubUrl = $this->hasParameter('mercure_public_url')
            ? (string) $this->getParameter('mercure_public_url')
            : '/.well-known/mercure';

        return $this->secureJson([
            'data' => [
                'subscription' => [
                    'topics'  => $topics,
                    'hub_url' => $hubUrl,
                    'token'   => $token,
                ],
            ],
        ], 200, [
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * Extrait les topics Mercure à partir de l'AST GraphQL déjà parsé.
     */
    private function extractTopicsFromDocument(string $projectUuid, DocumentNode $document): array
    {
        $topics = [];

        foreach ($document->definitions as $def) {
            if (!$def instanceof OperationDefinitionNode) continue;
            if ($def->operation !== 'subscription') continue;

            if ($def->selectionSet !== null) {
                foreach ($def->selectionSet->selections as $selection) {
                    if (!($selection instanceof FieldNode)) continue;
                    $fieldName = $selection->name->value;

                    if ($fieldName === 'projectEvents') {
                        $topics[] = "projects/{$projectUuid}";
                    } else {
                        $topics[] = "projects/{$projectUuid}/content";
                    }
                }
            }
        }

        return array_values(array_unique($topics ?: ["projects/{$projectUuid}"]));
    }
}
