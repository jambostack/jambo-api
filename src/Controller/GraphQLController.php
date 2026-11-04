<?php

namespace App\Controller;

use App\Entity\Project;
use App\GraphQL\SchemaGenerator;
use Doctrine\ORM\EntityManagerInterface;
use DateTimeImmutable;
use GraphQL\Error\DebugFlag;
use GraphQL\GraphQL as GraphQLExecutor;
use GraphQL\Language\AST\FieldNode;
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
    public const MAX_QUERY_LENGTH   = 65536; // 64 KB
    public const MAX_ALIASES        = 30;    // Limit alias amplification attacks
    public const MAX_OPERATIONS     = 1;     // No batched queries via POST body array

    public function __construct(
        private EntityManagerInterface $em,
        private SchemaGenerator $schemaGenerator,
        private ?\Symfony\Component\RateLimiter\RateLimiterFactory $graphqlLimiter = null,
        private ?TranslatorInterface $translator = null,
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

    #[Route('/api/projects/{uuid}/graphql', name: 'graphql_endpoint', methods: ['POST', 'GET'])]
    public function handle(string $uuid, Request $request): Response
    {
        $identifier = $request->getClientIp() ?? 'anonymous';
        try {
            $user = $this->getUser();
            if ($user !== null) {
                $identifier = $user->getUserIdentifier();
            }
        } catch (\Throwable) {}

        if ($this->graphqlLimiter !== null && false === $this->graphqlLimiter->create($identifier)->consume(1)->isAccepted()) {
            return new JsonResponse(['errors' => [['message' => $this->trans('graphql.too_many_requests', 'Trop de requêtes GraphQL. Veuillez réessayer plus tard.')]]], 429);
        }

        try {
            $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        } catch (\Throwable) {
            return new JsonResponse(['errors' => [['message' => $this->trans('graphql.project_not_found', 'Projet introuvable')]]], 404);
        }
        if (!$project) {
            return new JsonResponse(['errors' => [['message' => $this->trans('graphql.project_not_found', 'Projet introuvable')]]], 404);
        }

        if ($request->attributes->get('_project') !== $project) {
            $this->denyAccessUnlessGranted('project.view', $project);
        }

        if ($request->isMethod('GET')) {
            $query = $request->query->get('query');
            $variables = $request->query->all('variables') ?? null;
        } else {
            $rawContent = $request->getContent();
            if (strlen($rawContent) > self::MAX_QUERY_LENGTH) {
                return new JsonResponse(['errors' => [['message' => $this->trans('graphql.payload_too_large', 'Taille de payload GraphQL dépassée (max 64 Ko)')]]], 413);
            }
            $body = json_decode($rawContent, true);
            if (!is_array($body)) {
                return new JsonResponse(['errors' => [['message' => $this->trans('graphql.invalid_json', 'Invalid JSON body')]]], 400);
            }
            // ── Blocage des requêtes GraphQL en batch (array de queries) ─────────
            // Un tableau JSON au niveau racine indique un batch de plusieurs opérations.
            // Cela contourne les limites de complexité/profondeur par requête → rejeté.
            if (isset($body[0])) {
                return new JsonResponse(['errors' => [['message' => $this->trans('graphql.batch_not_allowed', 'Les requêtes GraphQL en batch ne sont pas autorisées. Envoyez une seule opération par requête.')]]], 400);
            }
            $query = $body['query'] ?? null;
            $variables = $body['variables'] ?? null;
        }

        if (!$query || !is_string($query)) {
            return new JsonResponse(['errors' => [['message' => $this->trans('graphql.query_required', 'Query GraphQL requise')]]], 400);
        }

        if (strlen($query) > self::MAX_QUERY_LENGTH) {
            return new JsonResponse(['errors' => [['message' => $this->trans('graphql.query_too_large', 'Query GraphQL dépasse la taille maximale autorisée (64 Ko)')]]], 413);
        }

        if ($variables !== null && !is_array($variables)) {
            return new JsonResponse(['errors' => [['message' => $this->trans('graphql.invalid_variables', 'Les variables GraphQL doivent être un objet ou tableau')]]], 400);
        }

        // ── Détection subscription ───────────────────────────────────
        $document = null;
        try {
            $document = Parser::parse($query, ['maxTokens' => 5000]);
        } catch (\Throwable) {
            // Parse error — laisser l'exécuteur GraphQL standard gérer l'erreur
        }
        $isSubscription = $document !== null && $this->isSubscriptionDocument($document);

        // ── Anti-amplification par alias ─────────────────────────────
        // Limite le nombre total de champs (alias inclus) dans le document parsé
        // pour éviter l'explosion mémoire via des centaines d'alias identiques.
        if ($document !== null && $this->countAliasedFields($document) > self::MAX_ALIASES) {
            return new JsonResponse([
                'errors' => [['message' => $this->trans(
                    'graphql.too_many_aliases',
                    sprintf('Trop d\'alias de champs GraphQL (max %d). Réduisez le nombre d\'alias dans votre requête.', self::MAX_ALIASES),
                    ['%max%' => self::MAX_ALIASES]
                )]],
            ], 400);
        }

        if ($isSubscription) {
            $projectUuid = $project->uuid->toRfc4122();
            return $this->handleSubscription($projectUuid, $document);
        }

        // ── Query / Mutation standard ────────────────────────────────
        $schema = $this->schemaGenerator->buildSchema($project);

        $maxDepth = (int) ($_ENV['GRAPHQL_MAX_DEPTH'] ?? 10);
        if ($maxDepth <= 0 || $maxDepth > 15) {
            $maxDepth = 10;
        }

        $maxComplexity = (int) ($_ENV['GRAPHQL_MAX_COMPLEXITY'] ?? 500);
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
        $introspectionConfig = $_ENV['GRAPHQL_INTROSPECTION_ENABLED'] ?? null;
        $introspectionDisabled = $introspectionConfig !== null
            ? !filter_var($introspectionConfig, FILTER_VALIDATE_BOOL)
            : ($env === 'prod');

        if ($introspectionDisabled) {
            $validationRules[] = new DisableIntrospection(DisableIntrospection::ENABLED);
        }

        $result = GraphQLExecutor::executeQuery(
            schema: $schema,
            source: $query,
            variableValues: $variables,
            validationRules: $validationRules,
        );

        $debug = $this->getParameter('kernel.debug')
            ? DebugFlag::INCLUDE_DEBUG_MESSAGE | DebugFlag::INCLUDE_TRACE
            : DebugFlag::NONE;

        return new JsonResponse($result->toArray($debug), 200, [
            'Cache-Control' => 'no-store, private',
        ]);
    }

    // ─── Subscription ───────────────────────────────────────────────────

    /**
     * Counts all field selections (including aliases) recursively across all
     * operations in the document. Used to detect alias-amplification attacks.
     */
    private function countAliasedFields($document): int
    {
        $count = 0;
        foreach ($document->definitions as $def) {
            if ($def instanceof OperationDefinitionNode && $def->selectionSet !== null) {
                $count += $this->countSelectionsRecursive($def->selectionSet);
            }
        }
        return $count;
    }

    private function countSelectionsRecursive(SelectionSetNode $set): int
    {
        $count = 0;
        foreach ($set->selections as $sel) {
            $count++;
            if ($sel instanceof FieldNode && $sel->selectionSet !== null) {
                $count += $this->countSelectionsRecursive($sel->selectionSet);
            }
        }
        return $count;
    }

    private function isSubscriptionDocument($document): bool
    {
        foreach ($document->definitions as $def) {
            if ($def instanceof OperationDefinitionNode && $def->operation === 'subscription') {
                return true;
            }
        }
        return false;
    }

    private function handleSubscription(string $projectUuid, $document): JsonResponse
    {
        $mercureSecret = $this->getParameter('mercure_jwt_secret') ?? '';
        if ($mercureSecret === '' || strlen($mercureSecret) < 32) {
            return new JsonResponse([
                'errors' => [['message' => 'Mercure hub not configured. Install and start the Mercure Docker service.']],
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

        $hubUrl = $this->getParameter('mercure_public_url')
            ?? '/.well-known/mercure';

        return new JsonResponse([
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
    private function extractTopicsFromDocument(string $projectUuid, $document): array
    {
        $topics = [];

        foreach ($document->definitions as $def) {
            if (!$def instanceof OperationDefinitionNode) continue;
            if ($def->operation !== 'subscription') continue;

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

        return array_values(array_unique($topics ?: ["projects/{$projectUuid}"]));
    }
}
