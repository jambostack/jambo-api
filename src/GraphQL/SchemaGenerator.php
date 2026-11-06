<?php

namespace App\GraphQL;

use App\Entity\Collection;
use App\Entity\Field;
use App\Entity\Project;
use App\Repository\CollectionRepository;
use App\Repository\ContentEntryRepository;
use App\Repository\EndUserRepository;
use App\Repository\MediaRepository;
use App\Service\EavDataFormatterService;
use App\Service\EavFieldHelperService;
use Doctrine\ORM\EntityManagerInterface;
use GraphQL\Error\UserError;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use Symfony\Contracts\Translation\TranslatorInterface;

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
        private ?MediaRepository $mediaRepo = null,
        private ?EndUserRepository $endUserRepo = null,
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

        $reserved = ['uuid' => true, 'locale' => true, 'status' => true, 'createdAt' => true, 'updatedAt' => true];
        foreach ($entityFields as $field) {
            $fieldKey = isset($reserved[$field->slug]) ? 'field_' . $field->slug : $field->slug;
            $fields[$fieldKey] = [
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

        $reserved = ['locale' => true, 'status' => true];
        foreach ($entityFields as $field) {
            $fieldKey = isset($reserved[$field->slug]) ? 'field_' . $field->slug : $field->slug;
            $fields[$fieldKey] = [
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
                    'search' => ['type' => Type::string()],
                    'sort'   => ['type' => Type::string()],
                    'limit'  => ['type' => Type::int(), 'defaultValue' => 50],
                    'offset' => ['type' => Type::int(), 'defaultValue' => 0],
                ],
                'complexity' => function (int $childrenComplexity, array $args): int {
                    $limit = min(100, max(1, (int) ($args['limit'] ?? 50)));
                    return (max(1, $childrenComplexity)) * $limit + 1;
                },
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
                'resolve' => fn($root, array $args, $context = null) => $this->resolveCreate($collection, $args['input'], $context),
            ];

            $fields[$updateName] = [
                'type' => $type,
                'args' => [
                    'uuid' => ['type' => Type::nonNull(Type::string())],
                    'input' => ['type' => Type::nonNull($inputType)],
                ],
                'resolve' => fn($root, array $args, $context = null) => $this->resolveUpdate($collection, $args['uuid'], $args['input'], $context),
            ];

            $fields[$deleteName] = [
                'type' => Type::boolean(),
                'args' => [
                    'uuid' => ['type' => Type::nonNull(Type::string())],
                ],
                'resolve' => fn($root, array $args, $context = null) => $this->resolveDelete($collection, $args['uuid'], $context),
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

    private function isValidUuid(string $uuid): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid);
    }

    private function resolveEntry(Collection $collection, string $uuid): ?array
    {
        if (!$this->isValidUuid($uuid)) {
            return null;
        }

        $entry = $this->em->getRepository(\App\Entity\ContentEntry::class)
            ->findOneBy(['collection' => $collection, 'uuid' => $uuid]);

        if (!$entry || $entry->isDeleted() || $entry->project?->id !== $collection->project?->id) {
            return null;
        }

        return $this->formatter->formatEntry($entry);
    }

    private function resolveEntries(Collection $collection, array $args): array
    {
        $limit = min(100, max(1, (int) ($args['limit'] ?? 50)));
        $offset = max(0, min(10000, (int) ($args['offset'] ?? 0)));
        $locale = isset($args['locale']) && is_string($args['locale']) && preg_match('/^[a-zA-Z]{2,5}(-[a-zA-Z0-9]{2,5})?$/', $args['locale'])
            ? $args['locale']
            : null;
        $status = isset($args['status']) && is_string($args['status']) && preg_match('/^[a-z0-9_-]{1,50}$/i', $args['status'])
            ? $args['status']
            : null;
        $search = isset($args['search']) && is_string($args['search']) ? mb_substr(trim($args['search']), 0, 100) : null;
        $sortArg = isset($args['sort']) && is_string($args['sort']) ? trim($args['sort']) : 'createdAt_DESC';
        $sort = match ($sortArg) {
            'createdAt_ASC', 'ASC' => 'ASC',
            default => 'DESC',
        };

        $page = (int) floor($offset / $limit) + 1;
        $entries = $this->entryRepo->findByCollectionPaginated(
            $collection, $page, $limit, $locale, $status, $search !== '' ? $search : null, $sort
        );

        return array_map(fn($entry) => $this->formatter->formatEntry($entry), $entries);
    }

    private function resolveCreate(Collection $collection, array $input, mixed $context = null): array
    {
        $project = $collection->project;

        // Validation IDOR / isolation multi-tenant du projet
        if (is_array($context) && isset($context['project']) && $context['project'] instanceof Project) {
            if ($context['project']->id !== $project->id) {
                throw new UserError($this->trans('graphql.cross_project_relation_forbidden', 'Accès inter-projet non autorisé.'));
            }
        }

        $entry = new \App\Entity\ContentEntry();
        $entry->collection = $collection;
        $entry->project = $project;

        $locale = $input['locale'] ?? $collection->project->defaultLocale ?? 'en';
        if (!is_string($locale) || !preg_match('/^[a-zA-Z]{2,5}(-[a-zA-Z0-9]{2,5})?$/', $locale)) {
            $locale = $collection->project->defaultLocale ?? 'en';
        }
        $entry->locale = $locale;

        $status = $input['status'] ?? 'draft';
        if (!is_string($status) || !preg_match('/^[a-z0-9_-]{1,50}$/i', $status)) {
            $status = 'draft';
        }
        $workflowStatuses = $collection->getWorkflowStatuses();
        if (!empty($workflowStatuses)) {
            $validSlugs = array_merge(array_column($workflowStatuses, 'slug'), ['draft', 'scheduled', 'published', 'archived']);
            if (!in_array($status, $validSlugs, true)) {
                $status = 'draft';
            }
        }
        $entry->status = $status;

        $baseSlug = null;
        if (isset($input['slug']) && is_string($input['slug']) && trim($input['slug']) !== '') {
            $baseSlug = trim($input['slug']);
        } elseif (isset($input['title']) && is_string($input['title']) && trim($input['title']) !== '') {
            $baseSlug = trim($input['title']);
        } else {
            $baseSlug = 'entry-' . bin2hex(random_bytes(3));
        }
        $entry->slug = $this->ensureUniqueSlug($collection, $baseSlug, $entry->locale);

        $entry->createdAt = new \DateTimeImmutable();
        $entry->updatedAt = new \DateTimeImmutable();

        unset($input['locale'], $input['status']);

        // Cap inputs to 200 fields maximum
        if (count($input) > 200) {
            $input = array_slice($input, 0, 200, true);
        }

        // Vérification des champs obligatoires (isRequired)
        /** @var Field[] $entityFields */
        $entityFields = $collection->fields
            ->filter(fn(Field $f) => !$f->isDeleted())
            ->toArray();

        foreach ($entityFields as $f) {
            if ($f->isRequired) {
                $val = $input[$f->slug] ?? $input['field_' . $f->slug] ?? null;
                if ($val === null || $val === '' || $val === []) {
                    throw new UserError(
                        $this->trans(
                            'graphql.field_required',
                            sprintf('Le champ "%s" est requis.', $f->name),
                            ['%field%' => $f->name]
                        )
                    );
                }
            }
        }

        foreach ($input as $fieldSlug => $value) {
            $cleanSlug = (string) $fieldSlug;
            $field = $this->fieldHelper->findField($collection, $cleanSlug);
            if (!$field && str_starts_with($cleanSlug, 'field_')) {
                $field = $this->fieldHelper->findField($collection, substr($cleanSlug, 6));
            }
            if (!$field) continue;

            $errors = $this->fieldHelper->validateFieldValue($field, $value);
            if (!empty($errors)) {
                throw new UserError(implode(' ', $errors));
            }

            // Validation stricte anti-IDOR et intégrité relationnelle multi-tenant
            $this->validateTenantFieldReference($field, $value, $project);

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

    private function resolveUpdate(Collection $collection, string $uuid, array $input, mixed $context = null): ?array
    {
        if (!$this->isValidUuid($uuid)) {
            return null;
        }

        $project = $collection->project;

        // Validation IDOR / isolation multi-tenant du projet
        if (is_array($context) && isset($context['project']) && $context['project'] instanceof Project) {
            if ($context['project']->id !== $project->id) {
                throw new UserError($this->trans('graphql.cross_project_relation_forbidden', 'Accès inter-projet non autorisé.'));
            }
        }

        $entry = $this->em->getRepository(\App\Entity\ContentEntry::class)
            ->findOneBy(['collection' => $collection, 'uuid' => $uuid]);

        if (!$entry || $entry->isDeleted() || $entry->project?->id !== $project->id) {
            return null;
        }

        if (isset($input['locale'])) {
            $locale = $input['locale'];
            if (is_string($locale) && preg_match('/^[a-zA-Z]{2,5}(-[a-zA-Z0-9]{2,5})?$/', $locale)) {
                $entry->locale = $locale;
            }
        }

        if (isset($input['status'])) {
            $status = $input['status'];
            if (is_string($status) && preg_match('/^[a-z0-9_-]{1,50}$/i', $status)) {
                $workflowStatuses = $collection->getWorkflowStatuses();
                if (empty($workflowStatuses) || in_array($status, array_merge(array_column($workflowStatuses, 'slug'), ['draft', 'scheduled', 'published', 'archived']), true)) {
                    $entry->status = $status;
                }
            }
        }
        unset($input['locale'], $input['status']);

        if (count($input) > 200) {
            $input = array_slice($input, 0, 200, true);
        }

        foreach ($input as $fieldSlug => $value) {
            $cleanSlug = (string) $fieldSlug;
            $field = $this->fieldHelper->findField($collection, $cleanSlug);
            if (!$field && str_starts_with($cleanSlug, 'field_')) {
                $field = $this->fieldHelper->findField($collection, substr($cleanSlug, 6));
            }
            if (!$field) continue;

            $errors = $this->fieldHelper->validateFieldValue($field, $value, $entry);
            if (!empty($errors)) {
                throw new UserError(implode(' ', $errors));
            }

            // Validation stricte anti-IDOR et intégrité relationnelle multi-tenant
            $this->validateTenantFieldReference($field, $value, $project);

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

    private function resolveDelete(Collection $collection, string $uuid, mixed $context = null): bool
    {
        if (!$this->isValidUuid($uuid)) {
            return false;
        }

        $project = $collection->project;

        // Validation IDOR / isolation multi-tenant du projet
        if (is_array($context) && isset($context['project']) && $context['project'] instanceof Project) {
            if ($context['project']->id !== $project->id) {
                return false;
            }
        }

        $entry = $this->em->getRepository(\App\Entity\ContentEntry::class)
            ->findOneBy(['collection' => $collection, 'uuid' => $uuid]);

        if (!$entry || $entry->isDeleted() || $entry->project?->id !== $project->id) {
            return false;
        }

        $entry->deletedAt = new \DateTimeImmutable();
        $this->em->flush();

        return true;
    }

    private function validateTenantFieldReference(Field $field, mixed $value, Project $project): void
    {
        if ($value === null || $value === '' || $value === []) {
            return;
        }

        if ($field->type === 'media') {
            $uuids = $this->extractCandidateUuids($value);
            if (!empty($uuids)) {
                $mediaRepo = $this->mediaRepo ?? $this->em->getRepository(\App\Entity\Media::class);
                if (method_exists($mediaRepo, 'findProjectMediaUuids')) {
                    $validUuids = $mediaRepo->findProjectMediaUuids($project, $uuids);
                    $diff = array_diff($uuids, $validUuids);
                    if (!empty($diff)) {
                        throw new UserError(
                            $this->trans(
                                'graphql.cross_project_media_forbidden',
                                sprintf('Média introuvable ou n\'appartenant pas à ce projet pour le champ "%s".', $field->name),
                                ['%field%' => $field->name]
                            )
                        );
                    }
                }
            }
            return;
        }

        if ($field->type === 'relation') {
            $options = $field->options ?? [];
            $targetColId = $options['relation']['collection'] ?? null;
            $targetIsEndUsers = ($options['targetCollection'] ?? null) === 'end_users';

            if ($targetIsEndUsers) {
                $uuids = $this->extractCandidateUuids($value);
                if (!empty($uuids)) {
                    $endUserRepo = $this->endUserRepo ?? $this->em->getRepository(\App\Entity\EndUser::class);
                    if (method_exists($endUserRepo, 'findProjectEndUserUuids')) {
                        $validUuids = $endUserRepo->findProjectEndUserUuids($project, $uuids);
                        $diff = array_diff($uuids, $validUuids);
                        if (!empty($diff)) {
                            throw new UserError(
                                $this->trans(
                                    'graphql.cross_project_relation_forbidden',
                                    sprintf('Référence de relation introuvable ou n\'appartenant pas à ce projet pour le champ "%s".', $field->name),
                                    ['%field%' => $field->name]
                                )
                            );
                        }
                    }
                }
                $intIds = $this->extractCandidateIntIds($value);
                if (!empty($intIds)) {
                    $endUserRepo = $this->endUserRepo ?? $this->em->getRepository(\App\Entity\EndUser::class);
                    foreach ($intIds as $intId) {
                        $found = $endUserRepo->findOneBy(['id' => $intId, 'project' => $project]);
                        if ($found === null) {
                            throw new UserError(
                                $this->trans(
                                    'graphql.cross_project_relation_forbidden',
                                    sprintf('Référence de relation introuvable ou n\'appartenant pas à ce projet pour le champ "%s".', $field->name),
                                    ['%field%' => $field->name]
                                )
                            );
                        }
                    }
                }
                return;
            }

            // Regular collection relation
            $targetCol = null;
            if ($targetColId !== null && is_numeric($targetColId)) {
                $colRepo = $this->em->getRepository(Collection::class);
                $targetCol = $colRepo->findOneBy([
                    'id' => (int) $targetColId,
                    'project' => $project,
                    'deletedAt' => null,
                ]);
                if ($targetCol === null) {
                    throw new UserError(
                        $this->trans(
                            'graphql.cross_project_relation_forbidden',
                            sprintf('Collection cible introuvable ou non autorisée pour le champ "%s".', $field->name),
                            ['%field%' => $field->name]
                        )
                    );
                }
            }

            $uuids = $this->extractCandidateUuids($value);
            if (!empty($uuids)) {
                $validUuids = $this->entryRepo->findProjectEntryUuids($project, $uuids);
                $diff = array_diff($uuids, $validUuids);
                if (!empty($diff)) {
                    throw new UserError(
                        $this->trans(
                            'graphql.cross_project_relation_forbidden',
                            sprintf('Référence de relation introuvable ou n\'appartenant pas à ce projet pour le champ "%s".', $field->name),
                            ['%field%' => $field->name]
                        )
                    );
                }
                if ($targetCol !== null) {
                    foreach ($uuids as $uuidStr) {
                        $entry = $this->entryRepo->findOneBy([
                            'uuid' => \Symfony\Component\Uid\Uuid::fromString($uuidStr),
                            'collection' => $targetCol,
                            'deletedAt' => null,
                        ]);
                        if ($entry === null) {
                            throw new UserError(
                                $this->trans(
                                    'graphql.cross_project_relation_forbidden',
                                    sprintf('Référence de relation n\'appartenant pas à la collection cible pour le champ "%s".', $field->name),
                                    ['%field%' => $field->name]
                                )
                            );
                        }
                    }
                }
            }

            $intIds = $this->extractCandidateIntIds($value);
            if (!empty($intIds)) {
                foreach ($intIds as $intId) {
                    $criteria = ['id' => $intId, 'project' => $project, 'deletedAt' => null];
                    if ($targetCol !== null) {
                        $criteria['collection'] = $targetCol;
                    }
                    $found = $this->entryRepo->findOneBy($criteria);
                    if ($found === null) {
                        throw new UserError(
                            $this->trans(
                                'graphql.cross_project_relation_forbidden',
                                sprintf('Référence de relation introuvable ou n\'appartenant pas à ce projet pour le champ "%s".', $field->name),
                                ['%field%' => $field->name]
                            )
                        );
                    }
                }
            }
        }
    }

    private function extractCandidateUuids(mixed $value): array
    {
        $items = is_array($value) ? $value : [$value];
        $uuids = [];
        foreach ($items as $item) {
            $raw = is_array($item) ? ($item['uuid'] ?? $item['id'] ?? null) : $item;
            if (is_string($raw) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $raw)) {
                $uuids[] = strtolower($raw);
            }
        }
        return array_values(array_unique($uuids));
    }

    private function extractCandidateIntIds(mixed $value): array
    {
        $items = is_array($value) ? $value : [$value];
        $ids = [];
        foreach ($items as $item) {
            $raw = is_array($item) ? ($item['id'] ?? null) : $item;
            if ($raw !== null && is_numeric($raw) && !is_float($raw + 0)) {
                $ids[] = (int) $raw;
            }
        }
        return array_values(array_unique($ids));
    }

    private function ensureUniqueSlug(Collection $collection, string $baseSlug, string $locale, ?int $excludeEntryId = null): string
    {
        $clean = preg_replace('/[^a-zA-Z0-9_-]/', '-', strtolower(trim($baseSlug)));
        $clean = trim((string) preg_replace('/-+/', '-', (string) $clean), '-');
        if ($clean === '') {
            $clean = 'entry';
        }
        $base = substr($clean, 0, 50);
        $slug = $base;
        $counter = 1;

        while (true) {
            $qb = $this->em->createQueryBuilder();
            $qb->select('COUNT(e.id)')
               ->from(\App\Entity\ContentEntry::class, 'e')
               ->where('e.collection = :collection')
               ->andWhere('e.slug = :slug')
               ->andWhere('e.locale = :locale')
               ->setParameter('collection', $collection)
               ->setParameter('slug', $slug)
               ->setParameter('locale', $locale);

            if ($excludeEntryId !== null) {
                $qb->andWhere('e.id != :excludeId')
                   ->setParameter('excludeId', $excludeEntryId);
            }

            if ((int) $qb->getQuery()->getSingleScalarResult() === 0) {
                return $slug;
            }

            $suffix = '-' . $counter;
            $slug = substr($base, 0, max(1, 50 - strlen($suffix))) . $suffix;
            $counter++;
            if ($counter > 500) {
                return $base . '-' . bin2hex(random_bytes(4));
            }
        }
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
            'complexity' => fn(int $childrenComplexity): int => max(1, $childrenComplexity) * 50 + 10,
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
                'complexity' => fn(int $childrenComplexity): int => max(1, $childrenComplexity) + 5,
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
