<?php

namespace App\Service\Flow\Handlers\Database;

use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowNodeHandler;
use App\Service\Flow\NodeOutput;
use Doctrine\ORM\EntityManagerInterface;

class RawQueryHandler implements FlowNodeHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function execute(array $input, FlowContext $ctx): NodeOutput
    {
        $config = $ctx->variables['_node_config'] ?? [];
        $dql = $config['dql'] ?? '';
        $params = $config['parameters'] ?? [];

        try {
            $query = $this->em->createQuery($dql);
            $query->setMaxResults(min(500, $config['max_results'] ?? 100));
            foreach ($params as $key => $value) {
                $query->setParameter($key, $value);
            }
            $results = $query->getResult();

            $serialized = array_map(function ($entity) {
                if (method_exists($entity, '__toString')) {
                    return (string) $entity;
                }
                if (method_exists($entity, 'getId')) {
                    return ['id' => $entity->getId()];
                }
                return [];
            }, $results);

            return new NodeOutput(data: [
                'results' => $serialized,
                'count' => count($serialized),
            ]);
        } catch (\Throwable $e) {
            return new NodeOutput(data: [
                'error' => $e->getMessage(),
                'results' => [],
                'count' => 0,
            ]);
        }
    }

    public static function getCategory(): string { return 'db'; }
    public static function getType(): string { return 'raw_query'; }
    public static function getFullType(): string { return 'db.raw_query'; }
    public static function getLabel(): string { return 'Requete DQL'; }
    public static function getDescription(): string { return 'Execute une requete DQL personnalisee'; }
    public static function getIcon(): string { return 'Database'; }
    public static function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['dql'],
            'properties' => [
                'dql' => ['type' => 'string', 'format' => 'textarea', 'title' => 'Requete DQL', 'template' => true],
                'parameters' => ['type' => 'object', 'title' => 'Parametres', 'default' => []],
                'max_results' => ['type' => 'number', 'title' => 'Max resultats', 'default' => 100],
            ],
        ];
    }
    public static function getOutputPorts(): array { return ['default']; }
}
