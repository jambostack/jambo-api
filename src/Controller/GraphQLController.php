<?php

namespace App\Controller;

use App\Entity\Project;
use App\GraphQL\SchemaGenerator;
use Doctrine\ORM\EntityManagerInterface;
use GraphQL\Error\DebugFlag;
use GraphQL\GraphQL as GraphQLExecutor;
use GraphQL\Validator\Rules\QueryDepth;
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

        $schema = $this->schemaGenerator->buildSchema($project);

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
}
