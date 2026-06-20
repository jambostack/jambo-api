<?php

namespace App\GraphQL;

use App\Entity\Collection;
use App\Entity\Field;
use App\Entity\Project;
use App\Repository\CollectionRepository;
use App\Repository\ContentEntryRepository;
use App\Service\EavDataFormatterService;
use App\Service\EavFieldHelperService;
use Doctrine\ORM\EntityManagerInterface;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

class SchemaGenerator
{
    private array $collectionTypes = [];
    private array $collectionInputTypes = [];
    private array $schemaCache = [];

    public function __construct(
        private EntityManagerInterface $em,
        private ContentEntryRepository $entryRepo,
        private EavDataFormatterService $formatter,
        private EavFieldHelperService $fieldHelper,
        private readonly string $projectDir = '',
    ) {}

    /**
     * Build a dynamic GraphQL schema for the given project.
     */
    public function buildSchema(Project $project): \GraphQL\Type\Schema
    {
        $cacheKey = $project->uuid->toRfc4122();

        if (isset($this->schemaCache[$cacheKey])) {
            return $this->schemaCache[$cacheKey];
        }

        $this->collectionTypes = [];
        $this->collectionInputTypes = [];

        $collections = $this->em->getRepository(Collection::class)
            ->findBy(['project' => $project, 'deletedAt' => null], ['order' => 'ASC']);

        foreach ($collections as $collection) {
            $this->buildCollectionType($collection);
        }

        $queryFields = $this->buildQueryFields($collections);
        $mutationFields = $this->buildMutationFields($collections);

        $queryType = new ObjectType(['name' => 'Query', 'fields' => $queryFields]);
        $mutationType = !empty($mutationFields)
            ? new ObjectType(['name' => 'Mutation', 'fields' => $mutationFields])
            : null;
        $subscriptionType = $this->buildSubscriptionType($collections);

        $schema = new \GraphQL\Type\Schema([
            'query' => $queryType,
            'mutation' => $mutationType,
            'subscription' => $subscriptionType,
        ]);

        $this->schemaCache[$cacheKey] = $schema;

        return $schema;
    }

    /**
     * Invalide le cache de schéma pour un projet (à appeler après modification de collection/champ).
     */
    public function invalidateCache(Project $project): void
    {
        unset($this->schemaCache[$project->uuid->toRfc4122()]);
    }

    private function buildCollectionType(Collection $collection): ObjectType
    {
        $typeName = $this->typeName($collection);

        if (isset($this->collectionTypes[$typeName])) {
            return $this->collectionTypes[$typeName];
        }

        $fields = [
            'uuid' => ['type' => Type::nonNull(Type::string()), 'description' => 'UUID unique de l\'entrée'],
            'locale' => ['type' => Type::string()],
            'status' => ['type' => Type::string()],
            'createdAt' => ['type' => Type::string()],
            'updatedAt' => ['type' => Type::string()],
        ];

        /** @var Field[] $entityFields */
        $entityFields = $collection->fields
            ->filter(fn(Field $f) => !$f->isDeleted())
            ->toArray();
        usort($entityFields, fn(Field $a, Field $b) => $a->order <=> $b->order);

        foreach ($entityFields as $field) {
            $fields[$field->slug] = [
                'type' => $this->mapFieldType($field),
                'description' => $field->name,
            ];
        }

        $type = new ObjectType(['name' => $typeName, 'fields' => $fields]);
        $this->collectionTypes[$typeName] = $type;

        return $type;
    }

    private function buildCollectionInputType(Collection $collection): InputObjectType
    {
        $inputName = $this->typeName($collection) . 'Input';

        if (isset($this->collectionInputTypes[$inputName])) {
            return $this->collectionInputTypes[$inputName];
        }

        $fields = ['locale' => ['type' => Type::string()], 'status' => ['type' => Type::string()]];

        /** @var Field[] $entityFields */
        $entityFields = $collection->fields
            ->filter(fn(Field $f) => !$f->isDeleted())
            ->toArray();

        foreach ($entityFields as $field) {
            $fields[$field->slug] = [
                'type' => $this->mapInputFieldType($field),
                'description' => $field->name,
            ];
        }

        $type = new InputObjectType(['name' => $inputName, 'fields' => $fields]);
        $this->collectionInputTypes[$inputName] = $type;

        return $type;
    }

    private function buildQueryFields(array $collections): array
    {
        $fields = [];
        $fields['_ping'] = [
            'type' => Type::string(),
            'resolve' => fn() => 'pong',
        ];

        $usedNames = [];

        foreach ($collections as $collection) {
            $type = $this->collectionTypes[$this->typeName($collection)];
            $baseName = $this->fieldName($collection);

            // Éviter les collisions de noms (ex: blog_post et blogpost → même camelCase)
            $snake = $baseName;
            if (isset($usedNames[$snake])) {
                $snake = $baseName . '_' . str_replace('-', '_', $collection->slug);
            }
            $usedNames[$snake] = true;

            $listName = $snake . 'List';
            if (isset($usedNames[$listName])) {
                $listName = $snake . '_' . str_replace('-', '_', $collection->slug) . 'List';
            }
            $usedNames[$listName] = true;

            $fields[$snake] = [
                'type' => $type,
                'args' => [
                    'uuid' => ['type' => Type::nonNull(Type::string())],
                ],
                'resolve' => fn($root, array $args) => $this->resolveEntry($collection, $args['uuid']),
            ];

            $fields[$listName] = [
                'type' => Type::listOf($type),
                'args' => [
                    'locale' => ['type' => Type::string()],
                    'status' => ['type' => Type::string()],
                    'limit' => ['type' => Type::int(), 'defaultValue' => 50],
                    'offset' => ['type' => Type::int(), 'defaultValue' => 0],
                ],
                'resolve' => fn($root, array $args) => $this->resolveEntries($collection, $args),
            ];
        }

        return $fields;
    }

    private function buildMutationFields(array $collections): array
    {
        $fields = [];
        $usedNames = [];

        foreach ($collections as $collection) {
            $type = $this->collectionTypes[$this->typeName($collection)];
            $inputType = $this->buildCollectionInputType($collection);
            $baseName = $this->fieldName($collection);

            // Anti-collision pour les mutations
            $snake = $baseName;
            if (isset($usedNames[$snake])) {
                $snake = $baseName . '_' . str_replace('-', '_', $collection->slug);
            }
            $usedNames[$snake] = true;

            $createName = 'create' . ucfirst($snake);
            $updateName = 'update' . ucfirst($snake);
            $deleteName = 'delete' . ucfirst($snake);

            $fields[$createName] = [
                'type' => $type,
                'args' => [
                    'input' => ['type' => Type::nonNull($inputType)],
                ],
                'resolve' => fn($root, array $args) => $this->resolveCreate($collection, $args['input']),
            ];

            $fields[$updateName] = [
                'type' => $type,
                'args' => [
                    'uuid' => ['type' => Type::nonNull(Type::string())],
                    'input' => ['type' => Type::nonNull($inputType)],
                ],
                'resolve' => fn($root, array $args) => $this->resolveUpdate($collection, $args['uuid'], $args['input']),
            ];

            $fields[$deleteName] = [
                'type' => Type::boolean(),
                'args' => [
                    'uuid' => ['type' => Type::nonNull(Type::string())],
                ],
                'resolve' => fn($root, array $args) => $this->resolveDelete($collection, $args['uuid']),
            ];
        }

        return $fields;
    }

    private function mapFieldType(Field $field): Type
    {
        return match ($field->type) {
            'number', 'decimal', 'rating' => Type::float(),
            'boolean', 'checkbox' => Type::boolean(),
            'json', 'array', 'repeater', 'enumeration', 'media', 'relation', 'tags' => Type::string(),
            'date', 'time', 'datetime' => Type::string(),
            default => Type::string(),
        };
    }

    private function mapInputFieldType(Field $field): Type
    {
        return match ($field->type) {
            'number', 'decimal', 'rating' => Type::float(),
            'boolean', 'checkbox' => Type::boolean(),
            'json', 'array', 'repeater', 'tags' => Type::string(),
            default => Type::string(),
        };
    }

    private function resolveEntry(Collection $collection, string $uuid): ?array
    {
        $entry = $this->em->getRepository(\App\Entity\ContentEntry::class)
            ->findOneBy(['collection' => $collection, 'uuid' => $uuid]);

        if (!$entry || $entry->isDeleted()) {
            return null;
        }

        return $this->formatter->formatEntry($entry);
    }

    private function resolveEntries(Collection $collection, array $args): array
    {
        $page = (int) floor($args['offset'] / $args['limit']) + 1;
        $entries = $this->entryRepo->findByCollectionPaginated(
            $collection, $page, $args['limit'], $args['locale'] ?? null, $args['status'] ?? null
        );

        return array_map(fn($entry) => $this->formatter->formatEntry($entry), $entries);
    }

    private function resolveCreate(Collection $collection, array $input): array
    {
        // Delegate to the existing ContentController logic via a service or direct entity creation
        $entry = new \App\Entity\ContentEntry();
        $entry->collection = $collection;
        $entry->project = $collection->project;
        $entry->locale = $input['locale'] ?? $collection->project->defaultLocale ?? 'en';
        $entry->status = $input['status'] ?? 'draft';
        $entry->createdAt = new \DateTimeImmutable();
        $entry->updatedAt = new \DateTimeImmutable();

        unset($input['locale'], $input['status']);

        foreach ($input as $fieldSlug => $value) {
            $field = $this->fieldHelper->findField($collection, $fieldSlug);
            if (!$field) continue;

            $errors = $this->fieldHelper->validateValue($field->type, $value);
            if (!empty($errors)) continue;

            $cfv = new \App\Entity\ContentFieldValue();
            $cfv->field = $field;
            $cfv->fieldType = $field->type;
            $cfv->contentEntry = $entry;
            $this->fieldHelper->setFieldValue($cfv, $field->type, $value);
            $entry->fieldValues->add($cfv);
        }

        $this->em->persist($entry);
        $this->em->flush();

        return $this->formatter->formatEntry($entry);
    }

    private function resolveUpdate(Collection $collection, string $uuid, array $input): ?array
    {
        $entry = $this->em->getRepository(\App\Entity\ContentEntry::class)
            ->findOneBy(['collection' => $collection, 'uuid' => $uuid]);

        if (!$entry || $entry->isDeleted()) {
            return null;
        }

        if (isset($input['locale'])) { $entry->locale = $input['locale']; }
        if (isset($input['status'])) { $entry->status = $input['status']; }
        unset($input['locale'], $input['status']);

        foreach ($input as $fieldSlug => $value) {
            $field = $this->fieldHelper->findField($collection, $fieldSlug);
            if (!$field) continue;

            $errors = $this->fieldHelper->validateValue($field->type, $value);
            if (!empty($errors)) continue;

            $cfv = $entry->fieldValues->findFirst(
                fn(int $key, \App\Entity\ContentFieldValue $v) => $v->field?->slug === $fieldSlug
            );
            if ($cfv) {
                $this->fieldHelper->setFieldValue($cfv, $field->type, $value);
            } else {
                $cfv = new \App\Entity\ContentFieldValue();
                $cfv->field = $field;
                $cfv->fieldType = $field->type;
                $cfv->contentEntry = $entry;
                $this->fieldHelper->setFieldValue($cfv, $field->type, $value);
                $entry->fieldValues->add($cfv);
            }
        }

        $entry->updatedAt = new \DateTimeImmutable();
        $this->em->flush();

        return $this->formatter->formatEntry($entry);
    }

    private function resolveDelete(Collection $collection, string $uuid): bool
    {
        $entry = $this->em->getRepository(\App\Entity\ContentEntry::class)
            ->findOneBy(['collection' => $collection, 'uuid' => $uuid]);

        if (!$entry || $entry->isDeleted()) {
            return false;
        }

        $entry->deletedAt = new \DateTimeImmutable();
        $this->em->flush();

        return true;
    }

    private function typeName(Collection $collection): string
    {
        return ucfirst($collection->slug);
    }

    private function fieldName(Collection $collection): string
    {
        return lcfirst(str_replace('_', '', ucwords($collection->slug, '_')));
    }

    /**
     * Build the Subscription type for the project.
     * Each resolver reads the JSONL buffer written by MercurePublisher
     * and returns the last events (max 50, within the last hour).
     */
    private function buildSubscriptionType(array $collections): ObjectType
    {
        $fields = [];
        $projectDir = $this->projectDir;

        // Resolver commun : lit le buffer JSONL et retourne les derniers événements
        $makeResolver = function (?array $actionFilter = null) use ($projectDir) {
            return function ($root, array $args) use ($projectDir, $actionFilter) {
                $projectUuid = $args['uuid'];
                // Protection path traversal : valider le format UUID avant concaténation
                if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $projectUuid)) {
                    return [];
                }
                $path = $projectDir . '/var/realtime/' . $projectUuid . '.jsonl';

                if (!file_exists($path)) {
                    return [];
                }

                $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                if (!$lines) return [];

                $lines = array_slice($lines, -50);
                $cutoff = time() - 3600;

                $events = [];
                foreach (array_reverse($lines) as $line) {
                    $event = json_decode($line, true);
                    if (!$event) continue;
                    if (($event['data']['time'] ?? 0) < $cutoff) continue;

                    // Filtre par action si demandé
                    $effectiveFilter = $args['actions'] ?? $actionFilter;
                    if (!empty($effectiveFilter)) {
                        $eventAction = $event['data']['action']
                            ?? ($event['event'] ?? '');
                        if (!in_array($eventAction, $effectiveFilter, true)) continue;
                    }

                    $events[] = $event;
                }

                return $events;
            };
        };

        // Champ global : tous les événements du projet
        $fields['projectEvents'] = [
            'type'    => Type::nonNull(Type::listOf($this->projectEventType())),
            'args'    => [
                'uuid' => ['type' => Type::nonNull(Type::string()), 'description' => 'UUID du projet'],
            ],
            'resolve' => $makeResolver(),
        ];

        // Un champ par collection (filtre content uniquement)
        foreach ($collections as $collection) {
            $fieldName = lcfirst(str_replace('_', '', ucwords($collection->slug, '_')));
            $fields[$fieldName] = [
                'type'    => $this->contentEventType(),
                'args'    => [
                    'uuid'    => ['type' => Type::nonNull(Type::string()), 'description' => 'UUID du projet'],
                    'actions' => [
                        'type'         => Type::listOf(Type::string()),
                        'description'  => 'Filtrer par actions : created, updated, deleted',
                        'defaultValue' => null,
                    ],
                ],
                'resolve' => $makeResolver(['created', 'updated', 'deleted']),
            ];
        }

        return new ObjectType([
            'name'   => 'Subscription',
            'fields' => $fields,
        ]);
    }

    private ?ObjectType $cachedProjectEventType = null;
    private ?ObjectType $cachedContentEventType = null;

    /**
     * Type pour les événements globaux du projet (media, status, webhook…).
     */
    private function projectEventType(): ObjectType
    {
        return $this->cachedProjectEventType ??= new ObjectType([
            'name'   => 'ProjectEvent',
            'fields' => [
                'event'     => ['type' => Type::nonNull(Type::string()), 'description' => 'Nom de l\'événement : entry.created, media.uploaded, status.changed…'],
                'data'      => ['type' => Type::string(), 'description' => 'Payload JSON de l\'événement'],
                'projectId' => ['type' => Type::nonNull(Type::string()), 'description' => 'UUID du projet'],
                'timestamp' => ['type' => Type::nonNull(Type::string()), 'description' => 'Timestamp ISO 8601'],
            ],
        ]);
    }

    /**
     * Type pour les événements liés à une collection spécifique (content).
     */
    private function contentEventType(): ObjectType
    {
        return $this->cachedContentEventType ??= new ObjectType([
            'name'   => 'ContentEvent',
            'fields' => [
                'action'    => ['type' => Type::nonNull(Type::string()), 'description' => 'created | updated | deleted'],
                'uuid'      => ['type' => Type::nonNull(Type::string()), 'description' => 'UUID de l\'entrée concernée'],
                'locale'    => ['type' => Type::string(), 'description' => 'Locale de l\'entrée'],
                'status'    => ['type' => Type::string(), 'description' => 'Statut workflow'],
                'title'     => ['type' => Type::string(), 'description' => 'Titre de l\'entrée'],
                'timestamp' => ['type' => Type::nonNull(Type::string()), 'description' => 'Timestamp ISO 8601'],
            ],
        ]);
    }
}
