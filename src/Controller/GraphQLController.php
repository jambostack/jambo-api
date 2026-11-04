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

class GraphQLController extends AbstractController
{
    public const MAX_QUERY_LENGTH = 65536; // 64 KB

    public function __construct(
        private EntityManagerInterface $em,
        private SchemaGenerator $schemaGenerator,
        private ?\Symfony\Component\RateLimiter\RateLimiterFactory $graphqlLimiter = null,
    ) {}

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
            return new JsonResponse(['errors' => [['message' => 'Trop de requêtes GraphQL. Veuillez réessayer plus tard.']]], 429);
        }

        try {
            $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        } catch (\Throwable) {
            return new JsonResponse(['errors' => [['message' => 'Projet introuvable']]], 404);
        }
        if (!$project) {
            return new JsonResponse(['errors' => [['message' => 'Projet introuvable']]], 404);
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
                return new JsonResponse(['errors' => [['message' => 'Taille de payload GraphQL dépassée (max 64 Ko)']]], 413);
            }
            $body = json_decode($rawContent, true);
            if (!is_array($body)) {
                return new JsonResponse(['errors' => [['message' => 'Invalid JSON body']]], 400);
            }
            $query = $body['query'] ?? null;
            $variables = $body['variables'] ?? null;
        }

        if (!$query || !is_string($query)) {
            return new JsonResponse(['errors' => [['message' => 'Query GraphQL requise']]], 400);
        }

        if (strlen($query) > self::MAX_QUERY_LENGTH) {
            return new JsonResponse(['errors' => [['message' => 'Query GraphQL dépasse la taille maximale autorisée (64 Ko)']]], 413);
        }

        if ($variables !== null && !is_array($variables)) {
            return new JsonResponse(['errors' => [['message' => 'Les variables GraphQL doivent être un objet ou tableau']]], 400);
        }

        // ── Détection subscription ───────────────────────────────────
        $document = null;
        try {
            $document = Parser::parse($query, ['maxTokens' => 5000]);
        } catch (\Throwable) {
            // Parse error — laisser l'exécuteur GraphQL standard gérer l'erreur
        }
        $isSubscription = $document !== null && $this->isSubscriptionDocument($document);

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

        return new JsonResponse($result->toArray($debug));
    }

    // ─── Subscription ───────────────────────────────────────────────────

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
