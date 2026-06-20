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
    public function __construct(
        private EntityManagerInterface $em,
        private SchemaGenerator $schemaGenerator,
    ) {}

    #[Route('/api/projects/{uuid}/graphql', name: 'graphql_endpoint', methods: ['POST', 'GET'])]
    public function handle(string $uuid, Request $request): Response
    {
        $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        if (!$project) {
            return new JsonResponse(['errors' => [['message' => 'Projet introuvable']]], 404);
        }

        $this->denyAccessUnlessGranted('project.view', $project);

        if ($request->isMethod('GET')) {
            $query = $request->query->get('query');
            $variables = $request->query->all('variables') ?? null;
        } else {
            $body = json_decode($request->getContent(), true);
            $query = $body['query'] ?? null;
            $variables = $body['variables'] ?? null;
        }

        if (!$query) {
            return new JsonResponse(['errors' => [['message' => 'Query GraphQL requise']]], 400);
        }

        // ── Détection subscription ───────────────────────────────────
        $isSubscription = $this->isSubscriptionQuery($query);

        if ($isSubscription) {
            return $this->handleSubscription($project, $query);
        }

        // ── Query / Mutation standard ────────────────────────────────
        $schema = $this->schemaGenerator->buildSchema($project);

        $validationRules = [
            new QueryDepth(15),
        ];

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

    private function isSubscriptionQuery(string $query): bool
    {
        try {
            $document = Parser::parse($query);
            foreach ($document->definitions as $def) {
                if ($def instanceof OperationDefinitionNode && $def->operation === 'subscription') {
                    return true;
                }
            }
        } catch (\Throwable) {
            // Parse error — laisser l'exécuteur GraphQL standard gérer l'erreur
        }
        return false;
    }

    private function handleSubscription(Project $project, string $query): JsonResponse
    {
        $projectUuid = $project->uuid->toRfc4122();

        $mercureSecret = $this->getParameter('mercure_jwt_secret') ?? '';
        if ($mercureSecret === '') {
            return new JsonResponse([
                'errors' => [['message' => 'Mercure hub not configured. Install and start the Mercure Docker service.']],
            ], 503);
        }

        // Extraire les champs souscrits pour déterminer les topics
        $topics = $this->extractTopics($projectUuid, $query);

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
     * Extrait les topics Mercure à partir des champs souscrits dans la query GraphQL.
     */
    private function extractTopics(string $projectUuid, string $query): array
    {
        try {
            $document = Parser::parse($query);
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
                        // Champ par collection → topic content
                        $topics[] = "projects/{$projectUuid}/content";
                    }
                }
            }

            return array_values(array_unique($topics ?: ["projects/{$projectUuid}"]));
        } catch (\Throwable) {
            return ["projects/{$projectUuid}"];
        }
    }
}
