<?php

namespace App\Mcp;

use App\Entity\Collection;
use App\Entity\ContentEntry;
use App\Entity\ContentFieldValue;
use App\Entity\Field;
use App\Entity\Media;
use App\Entity\Project;
use App\Entity\EndUser;
use App\Repository\CollectionRepository;
use App\Repository\ContentEntryRepository;
use App\Service\AiContentService;
use App\Service\EavDataFormatterService;
use App\Service\EavFieldHelperService;
use App\Service\ImageTransformService;
use App\Service\SearchService;
use App\Service\VersioningService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class JamboApiMcpServer extends McpServer
{
    private EntityManagerInterface $em;
    private EavDataFormatterService $formatter;

    private EavFieldHelperService $fieldHelper;

    public function __construct(
        EntityManagerInterface $entityManager,
        EavDataFormatterService $formatterService,
        SearchService $searchService,
        AiContentService $aiService,
        VersioningService $versioningService,
        ImageTransformService $imageService,
        EavFieldHelperService $fieldHelperService,
    ) {
        parent::__construct('JamboApi CMS', '2.0.0');

        $this->em = $entityManager;
        $this->formatter = $formatterService;
        $this->fieldHelper = $fieldHelperService;

        $this->registerExplorationTools();
        $this->registerContentTools();
        $this->registerSchemaTools();
        $this->registerMediaTools();
        $this->registerUserTools();
        $this->registerAiTools($aiService, $searchService, $versioningService, $imageService);
        $this->registerResources();
    }

    // ===== EXPLORATION =====

    private function registerExplorationTools(): void
    {
        $this->registerTool(new McpTool(
            'list_projects',
            'Liste tous les projets accessibles',
            McpTool::schema([
                'search' => McpTool::stringProp('Filtre par nom'),
            ]),
            function (array $args, array $ctx): array {
                $qb = $this->em->getRepository(Project::class)->createQueryBuilder('p');
                if (!empty($args['search'])) {
                    $qb->andWhere('p.name LIKE :q')->setParameter('q', '%' . $args['search'] . '%');
                }
                $projects = $qb->getQuery()->getResult();
                return array_map(fn(Project $p) => [
                    'uuid' => $p->uuid->toRfc4122(),
                    'name' => $p->name,
                    'description' => $p->description,
                    'locales' => $p->locales,
                    'defaultLocale' => $p->defaultLocale,
                ], $projects);
            }
        ));

        $this->registerTool(new McpTool(
            'list_collections',
            'Liste les collections d\'un projet avec leurs champs',
            McpTool::schema([
                'project_uuid' => McpTool::stringProp('UUID du projet', true),
                'include_fields' => McpTool::boolProp('Inclure la description des champs'),
            ], ['project_uuid']),
            function (array $args, array $ctx): array {
                $project = $this->findProject($args['project_uuid']);
                if (!$project) throw new McpException('Projet introuvable', 404);

                $collections = $this->em->getRepository(Collection::class)
                    ->findBy(['project' => $project, 'deletedAt' => null], ['order' => 'ASC']);

                return array_map(function (Collection $c) use ($args) {
                    $data = [
                        'uuid' => $c->uuid->toRfc4122(),
                        'name' => $c->name,
                        'slug' => $c->slug,
                        'description' => $c->description,
                        'isSingleton' => $c->isSingleton,
                        'fieldCount' => $c->fields->filter(fn(Field $f) => !$f->isDeleted())->count(),
                    ];
                    if (!empty($args['include_fields'])) {
                        $data['fields'] = array_map(fn(Field $f) => [
                            'name' => $f->name,
                            'slug' => $f->slug,
                            'type' => $f->type,
                            'isRequired' => $f->isRequired,
                            'options' => $f->options,
                        ], $c->fields->filter(fn(Field $f) => !$f->isDeleted())->toArray());
                    }
                    return $data;
                }, $collections);
            }
        ));

        $this->registerTool(new McpTool(
            'get_collection_schema',
            'Obtient le schéma complet d\'une collection',
            McpTool::schema([
                'project_uuid' => McpTool::stringProp('UUID du projet', true),
                'collection_slug' => McpTool::stringProp('Slug de la collection', true),
            ], ['project_uuid', 'collection_slug']),
            function (array $args, array $ctx): array {
                $collection = $this->findCollection($args['project_uuid'], $args['collection_slug']);
                if (!$collection) throw new McpException('Collection introuvable', 404);

                $fields = [];
                foreach ($collection->fields as $f) {
                    if ($f->isDeleted()) continue;
                    $fields[] = [
                        'name' => $f->name,
                        'slug' => $f->slug,
                        'type' => $f->type,
                        'isRequired' => $f->isRequired,
                        'options' => $f->options,
                        'order' => $f->order,
                    ];
                }

                return [
                    'uuid' => $collection->uuid->toRfc4122(),
                    'name' => $collection->name,
                    'slug' => $collection->slug,
                    'description' => $collection->description,
                    'isSingleton' => $collection->isSingleton,
                    'fields' => $fields,
                ];
            }
        ));
    }

    // ===== CONTENT =====

    private function registerContentTools(): void
    {
        $this->registerTool(new McpTool(
            'list_entries',
            'Liste les entrées d\'une collection avec filtres et pagination',
            McpTool::schema([
                'project_uuid' => McpTool::stringProp('UUID du projet', true),
                'collection_slug' => McpTool::stringProp('Slug de la collection', true),
                'locale' => McpTool::stringProp('Filtre par locale'),
                'status' => McpTool::enumProp(['draft', 'published'], 'Filtre par statut'),
                'limit' => McpTool::intProp('Nombre d\'entrées (défaut 50)'),
                'offset' => McpTool::intProp('Décalage (défaut 0)'),
            ], ['project_uuid', 'collection_slug']),
            function (array $args, array $ctx): array {
                $collection = $this->findCollection($args['project_uuid'], $args['collection_slug']);
                if (!$collection) throw new McpException('Collection introuvable', 404);

                $limit = min($args['limit'] ?? 50, 200);
                $offset = $args['offset'] ?? 0;
                $page = (int) floor($offset / $limit) + 1;

                $entries = $this->em->getRepository(ContentEntry::class)
                    ->findByCollectionPaginated($collection, $page, $limit,
                        $args['locale'] ?? null, $args['status'] ?? null);

                return [
                    'total' => count($entries),
                    'limit' => $limit,
                    'offset' => $offset,
                    'entries' => array_map(fn(ContentEntry $e) => $this->formatter->formatEntry($e), $entries),
                ];
            }
        ));

        $this->registerTool(new McpTool(
            'get_entry',
            'Obtient une entrée par son UUID',
            McpTool::schema([
                'project_uuid' => McpTool::stringProp('UUID du projet', true),
                'collection_slug' => McpTool::stringProp('Slug de la collection', true),
                'entry_uuid' => McpTool::stringProp('UUID de l\'entrée', true),
            ], ['project_uuid', 'collection_slug', 'entry_uuid']),
            function (array $args, array $ctx): array {
                $entry = $this->findEntry($args['project_uuid'], $args['collection_slug'], $args['entry_uuid']);
                if (!$entry) throw new McpException('Entrée introuvable', 404);

                return $this->formatter->formatEntry($entry);
            }
        ));

        $this->registerTool(new McpTool(
            'create_entry',
            'Crée une nouvelle entrée dans une collection',
            McpTool::schema([
                'project_uuid' => McpTool::stringProp('UUID du projet', true),
                'collection_slug' => McpTool::stringProp('Slug de la collection', true),
                'locale' => McpTool::stringProp('Locale (par défaut celle du projet)'),
                'data' => ['type' => 'object', 'description' => 'Données de l\'entrée (clés = slugs des champs)'],
            ], ['project_uuid', 'collection_slug', 'data']),
            function (array $args, array $ctx): array {
                $collection = $this->findCollection($args['project_uuid'], $args['collection_slug']);
                if (!$collection) throw new McpException('Collection introuvable', 404);

                if ($collection->isSingleton) {
                    $existing = $this->em->getRepository(ContentEntry::class)
                        ->findOneBy(['collection' => $collection, 'deletedAt' => null]);
                    if ($existing) {
                        throw new McpException('Cette collection singleton a déjà une entrée');
                    }
                }

                $entry = new ContentEntry();
                $entry->collection = $collection;
                $entry->project = $collection->project;
                $entry->locale = $args['locale'] ?? $collection->project->defaultLocale ?? 'en';
                $entry->status = 'draft';

                foreach ($args['data'] as $fieldSlug => $value) {
                    $field = $this->fieldHelper->findField($collection, $fieldSlug);
                    if (!$field) continue;
                    $cfv = $this->createFieldValue($field, $value);
                    $cfv->contentEntry = $entry;
                    $entry->fieldValues->add($cfv);
                }

                $this->em->persist($entry);
                $this->em->flush();

                return $this->formatter->formatEntry($entry);
            }
        ));

        $this->registerTool(new McpTool(
            'update_entry',
            'Met à jour une entrée existante',
            McpTool::schema([
                'project_uuid' => McpTool::stringProp('UUID du projet', true),
                'collection_slug' => McpTool::stringProp('Slug de la collection', true),
                'entry_uuid' => McpTool::stringProp('UUID de l\'entrée', true),
                'data' => ['type' => 'object', 'description' => 'Champs à mettre à jour'],
                'status' => McpTool::enumProp(['draft', 'published'], 'Nouveau statut'),
            ], ['project_uuid', 'collection_slug', 'entry_uuid', 'data']),
            function (array $args, array $ctx): array {
                $entry = $this->findEntry($args['project_uuid'], $args['collection_slug'], $args['entry_uuid']);
                if (!$entry) throw new McpException('Entrée introuvable', 404);

                if (isset($args['status'])) $entry->status = $args['status'];

                foreach ($args['data'] as $fieldSlug => $value) {
                    $field = $this->fieldHelper->findField($entry->collection, $fieldSlug);
                    if (!$field) continue;
                    $cfv = $entry->fieldValues->findFirst(
                        fn(int $k, ContentFieldValue $v) => $v->field?->slug === $fieldSlug
                    );
                    if ($cfv) {
                        $this->fieldHelper->setFieldValue($cfv, $field->type, $value);
                    } else {
                        $cfv = $this->createFieldValue($field, $value);
                        $cfv->contentEntry = $entry;
                        $entry->fieldValues->add($cfv);
                    }
                }

                $entry->updatedAt = new \DateTimeImmutable();
                $this->em->flush();

                return $this->formatter->formatEntry($entry);
            }
        ));

        $this->registerTool(new McpTool(
            'delete_entry',
            'Supprime (soft-delete) une entrée',
            McpTool::schema([
                'project_uuid' => McpTool::stringProp('UUID du projet', true),
                'collection_slug' => McpTool::stringProp('Slug de la collection', true),
                'entry_uuid' => McpTool::stringProp('UUID de l\'entrée', true),
            ], ['project_uuid', 'collection_slug', 'entry_uuid']),
            function (array $args, array $ctx): array {
                $entry = $this->findEntry($args['project_uuid'], $args['collection_slug'], $args['entry_uuid']);
                if (!$entry) throw new McpException('Entrée introuvable', 404);

                $entry->deletedAt = new \DateTimeImmutable();
                $this->em->flush();

                return ['deleted' => true, 'uuid' => $entry->uuid->toRfc4122()];
            }
        ));

        $this->registerTool(new McpTool(
            'publish_entry',
            'Publie une entrée (passe le statut à published)',
            McpTool::schema([
                'project_uuid' => McpTool::stringProp('UUID du projet', true),
                'collection_slug' => McpTool::stringProp('Slug de la collection', true),
                'entry_uuid' => McpTool::stringProp('UUID de l\'entrée', true),
            ], ['project_uuid', 'collection_slug', 'entry_uuid']),
            function (array $args, array $ctx): array {
                $entry = $this->findEntry($args['project_uuid'], $args['collection_slug'], $args['entry_uuid']);
                if (!$entry) throw new McpException('Entrée introuvable', 404);

                $entry->status = 'published';
                $entry->updatedAt = new \DateTimeImmutable();
                $this->em->flush();

                return ['published' => true, 'uuid' => $entry->uuid->toRfc4122()];
            }
        ));

        $this->registerTool(new McpTool(
            'search_content',
            'Recherche full-text dans le contenu',
            McpTool::schema([
                'project_uuid' => McpTool::stringProp('UUID du projet', true),
                'query' => McpTool::stringProp('Termes de recherche', true),
                'collection' => McpTool::stringProp('Filtrer par collection'),
                'locale' => McpTool::stringProp('Filtrer par locale'),
                'limit' => McpTool::intProp('Nombre de résultats (défaut 20)'),
            ], ['project_uuid', 'query']),
            function (array $args, array $ctx) use ($searchService): array {
                $project = $this->findProject($args['project_uuid']);
                if (!$project) throw new McpException('Projet introuvable', 404);

                $options = ['limit' => min($args['limit'] ?? 20, 100)];
                if (!empty($args['collection'])) $options['filter'] = "_collection = {$args['collection']}";
                if (!empty($args['locale'])) $options['filter'] = isset($options['filter'])
                    ? $options['filter'] . " AND locale = {$args['locale']}"
                    : "locale = {$args['locale']}";

                return $searchService->search($project, $args['query'], $options);
            }
        ));
    }

    // ===== SCHEMA =====

    private function registerSchemaTools(): void
    {
        $this->registerTool(new McpTool(
            'create_collection',
            'Crée une nouvelle collection dans un projet',
            McpTool::schema([
                'project_uuid' => McpTool::stringProp('UUID du projet', true),
                'name' => McpTool::stringProp('Nom de la collection', true),
                'slug' => McpTool::stringProp('Slug (généré automatiquement si vide)'),
                'description' => McpTool::stringProp('Description'),
                'is_singleton' => McpTool::boolProp('Collection singleton (une seule entrée)'),
            ], ['project_uuid', 'name']),
            function (array $args, array $ctx): array {
                $project = $this->findProject($args['project_uuid']);
                if (!$project) throw new McpException('Projet introuvable', 404);

                $collection = new Collection();
                $collection->name = $args['name'];
                $collection->slug = $args['slug'] ?? $this->slugify($args['name']);
                $collection->description = $args['description'] ?? null;
                $collection->isSingleton = $args['is_singleton'] ?? false;
                $collection->project = $project;
                $collection->order = count($project->collections->toArray());

                $this->em->persist($collection);
                $this->em->flush();

                return [
                    'uuid' => $collection->uuid->toRfc4122(),
                    'name' => $collection->name,
                    'slug' => $collection->slug,
                    'isSingleton' => $collection->isSingleton,
                ];
            }
        ));

        $this->registerTool(new McpTool(
            'add_field',
            'Ajoute un champ à une collection',
            McpTool::schema([
                'project_uuid' => McpTool::stringProp('UUID du projet', true),
                'collection_slug' => McpTool::stringProp('Slug de la collection', true),
                'name' => McpTool::stringProp('Nom du champ', true),
                'slug' => McpTool::stringProp('Slug du champ'),
                'type' => McpTool::enumProp(
                    ['text', 'longtext', 'richtext', 'number', 'boolean', 'date', 'datetime', 'email', 'slug', 'color', 'json', 'enumeration', 'media', 'relation'],
                    'Type du champ', true
                ),
                'is_required' => McpTool::boolProp('Champ obligatoire'),
                'options' => ['type' => 'object', 'description' => 'Options du champ (ex: {"choices": ["a","b"]})'],
            ], ['project_uuid', 'collection_slug', 'name', 'type']),
            function (array $args, array $ctx): array {
                $collection = $this->findCollection($args['project_uuid'], $args['collection_slug']);
                if (!$collection) throw new McpException('Collection introuvable', 404);

                $field = new Field();
                $field->name = $args['name'];
                $field->slug = $args['slug'] ?? $this->slugify($args['name']);
                $field->type = $args['type'];
                $field->isRequired = $args['is_required'] ?? false;
                $field->options = $args['options'] ?? null;
                $field->collection = $collection;
                $field->order = $collection->fields->count();

                $this->em->persist($field);
                $this->em->flush();

                return [
                    'name' => $field->name,
                    'slug' => $field->slug,
                    'type' => $field->type,
                    'isRequired' => $field->isRequired,
                ];
            }
        ));

        $this->registerTool(new McpTool(
            'apply_template',
            'Applique un template de projet pour créer un projet avec sa structure',
            McpTool::schema([
                'template_id' => McpTool::intProp('ID du template de projet', true),
                'project_name' => McpTool::stringProp('Nom du nouveau projet', true),
            ], ['template_id', 'project_name']),
            function (array $args, array $ctx): array {
                $template = $this->em->getRepository(\App\Entity\ProjectTemplate::class)
                    ->find($args['template_id']);
                if (!$template) throw new McpException('Template introuvable', 404);

                $project = new Project();
                $project->name = $args['project_name'];
                $project->description = "Créé depuis le template: {$template->name}";
                $project->locales = $template->structure['locales'] ?? ['en'];
                $project->defaultLocale = $template->structure['defaultLocale'] ?? 'en';

                $this->em->persist($project);

                foreach ($template->structure['collections'] ?? [] as $collData) {
                    $collection = new Collection();
                    $collection->name = $collData['name'];
                    $collection->slug = $collData['slug'];
                    $collection->isSingleton = $collData['isSingleton'] ?? false;
                    $collection->project = $project;
                    $this->em->persist($collection);

                    foreach ($collData['fields'] ?? [] as $i => $fieldData) {
                        $field = new Field();
                        $field->name = $fieldData['name'];
                        $field->slug = $fieldData['slug'];
                        $field->type = $fieldData['type'];
                        $field->isRequired = $fieldData['isRequired'] ?? false;
                        $field->options = $fieldData['options'] ?? null;
                        $field->collection = $collection;
                        $field->order = $i;
                        $this->em->persist($field);
                    }
                }

                $this->em->flush();

                return [
                    'project_uuid' => $project->uuid->toRfc4122(),
                    'project_name' => $project->name,
                    'collections_count' => count($template->structure['collections'] ?? []),
                ];
            }
        ));
    }

    // ===== MEDIA =====

    private function registerMediaTools(): void
    {
        $this->registerTool(new McpTool(
            'list_media',
            'Liste les médias d\'un projet',
            McpTool::schema([
                'project_uuid' => McpTool::stringProp('UUID du projet', true),
                'search' => McpTool::stringProp('Recherche par nom'),
                'limit' => McpTool::intProp('Nombre (défaut 50)'),
                'offset' => McpTool::intProp('Décalage (défaut 0)'),
            ], ['project_uuid']),
            function (array $args, array $ctx): array {
                $project = $this->findProject($args['project_uuid']);
                if (!$project) throw new McpException('Projet introuvable', 404);

                $qb = $this->em->getRepository(Media::class)->createQueryBuilder('m')
                    ->where('m.project = :project')->andWhere('m.deletedAt IS NULL')
                    ->setParameter('project', $project)
                    ->orderBy('m.createdAt', 'DESC')
                    ->setMaxResults(min($args['limit'] ?? 50, 200))
                    ->setFirstResult($args['offset'] ?? 0);

                if (!empty($args['search'])) {
                    $qb->andWhere('m.originalName LIKE :q OR m.fileName LIKE :q')
                        ->setParameter('q', '%' . $args['search'] . '%');
                }

                return array_map(fn(Media $m) => [
                    'uuid' => $m->uuid->toRfc4122(),
                    'name' => $m->originalName ?? $m->fileName,
                    'mimeType' => $m->mimeType,
                    'size' => $m->fileSize,
                    'url' => "/cdn/media/{$m->uuid->toRfc4122()}",
                    'createdAt' => $m->createdAt?->format(\DateTimeInterface::ATOM),
                ], $qb->getQuery()->getResult());
            }
        ));

        $this->registerTool(new McpTool(
            'get_media_info',
            'Obtient les informations détaillées d\'un média',
            McpTool::schema([
                'media_uuid' => McpTool::stringProp('UUID du média', true),
            ], ['media_uuid']),
            function (array $args, array $ctx) use ($imageService): array {
                $media = $this->em->getRepository(Media::class)->findOneBy(['uuid' => $args['media_uuid']]);
                if (!$media) throw new McpException('Média introuvable', 404);

                return [
                    'uuid' => $media->uuid->toRfc4122(),
                    'name' => $media->originalName ?? $media->fileName,
                    'mimeType' => $media->mimeType,
                    'size' => $media->fileSize,
                    'alt' => $media->alt,
                    'caption' => $media->caption,
                    'url' => "/cdn/media/{$media->uuid->toRfc4122()}",
                    'transformations' => [
                        'thumbnail' => "/cdn/media/{$media->uuid->toRfc4122()}?w=200&h=200&fit=crop&fmt=webp&q=80",
                        'medium' => "/cdn/media/{$media->uuid->toRfc4122()}?w=800&h=600&fit=scale-down",
                        'webp' => "/cdn/media/{$media->uuid->toRfc4122()}?fmt=webp&q=80",
                    ],
                ];
            }
        ));
    }

    // ===== USERS =====

    private function registerUserTools(): void
    {
        $this->registerTool(new McpTool(
            'list_end_users',
            'Liste les utilisateurs finaux d\'un projet',
            McpTool::schema([
                'project_uuid' => McpTool::stringProp('UUID du projet', true),
                'status' => McpTool::enumProp(['active', 'banned', 'pending'], 'Filtre par statut'),
                'limit' => McpTool::intProp('Nombre (défaut 50)'),
                'offset' => McpTool::intProp('Décalage (défaut 0)'),
            ], ['project_uuid']),
            function (array $args, array $ctx): array {
                $project = $this->findProject($args['project_uuid']);
                if (!$project) throw new McpException('Projet introuvable', 404);

                $qb = $this->em->getRepository(EndUser::class)->createQueryBuilder('u')
                    ->where('u.project = :project')->setParameter('project', $project)
                    ->setMaxResults(min($args['limit'] ?? 50, 200))
                    ->setFirstResult($args['offset'] ?? 0);

                if (!empty($args['status'])) {
                    $qb->andWhere('u.status = :status')->setParameter('status', $args['status']);
                }

                return array_map(fn(EndUser $u) => [
                    'uuid' => $u->uuid->toRfc4122(),
                    'email' => $u->email,
                    'name' => $u->name,
                    'status' => $u->status,
                    'avatarUrl' => $u->avatarUrl,
                    'customFields' => $u->customFields,
                    'createdAt' => $u->createdAt->format(\DateTimeInterface::ATOM),
                ], $qb->getQuery()->getResult());
            }
        ));

        $this->registerTool(new McpTool(
            'get_end_user_schema',
            'Obtient le schéma des champs personnalisés pour les utilisateurs finaux',
            McpTool::schema([
                'project_uuid' => McpTool::stringProp('UUID du projet', true),
            ], ['project_uuid']),
            function (array $args, array $ctx): array {
                $project = $this->findProject($args['project_uuid']);
                if (!$project) throw new McpException('Projet introuvable', 404);

                $fields = $this->em->getRepository(\App\Entity\EndUserField::class)
                    ->findBy(['project' => $project], ['order' => 'ASC']);

                return array_map(fn(\App\Entity\EndUserField $f) => [
                    'name' => $f->name,
                    'slug' => $f->slug,
                    'type' => $f->type,
                    'isRequired' => $f->isRequired,
                    'isSystem' => $f->isSystem,
                    'options' => $f->options,
                ], $fields);
            }
        ));
    }

    // ===== AI =====

    private function registerAiTools(
        AiContentService $aiService,
        SearchService $searchService,
        VersioningService $versioningService,
        ImageTransformService $imageService,
    ): void {
        $this->registerTool(new McpTool(
            'ai_generate_content',
            'Génère du contenu via IA à partir d\'un brief en langage naturel',
            McpTool::schema([
                'project_uuid' => McpTool::stringProp('UUID du projet', true),
                'collection_slug' => McpTool::stringProp('Collection cible', true),
                'brief' => McpTool::stringProp('Description du contenu à générer', true),
                'locale' => McpTool::stringProp('Locale (défaut: fr)'),
            ], ['project_uuid', 'collection_slug', 'brief']),
            function (array $args, array $ctx) use ($aiService): array {
                $collection = $this->findCollection($args['project_uuid'], $args['collection_slug']);
                if (!$collection) throw new McpException('Collection introuvable', 404);

                return $aiService->generateContent(
                    $args['brief'], $collection, $args['locale'] ?? 'fr'
                );
            }
        ));

        $this->registerTool(new McpTool(
            'ai_translate_content',
            'Traduit un contenu dans une autre locale',
            McpTool::schema([
                'content' => ['type' => 'object', 'description' => 'Contenu JSON à traduire', 'required' => true],
                'target_locale' => McpTool::stringProp('Locale cible (ex: en, es, ar)', true),
            ], ['content', 'target_locale']),
            function (array $args, array $ctx) use ($aiService): array {
                return $aiService->translateContent($args['content'], $args['target_locale']);
            }
        ));

        $this->registerTool(new McpTool(
            'ai_summarize_text',
            'Résume un texte via IA',
            McpTool::schema([
                'text' => McpTool::stringProp('Texte à résumer', true),
                'max_words' => McpTool::intProp('Nombre maximum de mots (défaut 80)'),
            ], ['text']),
            function (array $args, array $ctx) use ($aiService): array {
                return ['summary' => $aiService->summarize($args['text'], $args['max_words'] ?? 80)];
            }
        ));

        $this->registerTool(new McpTool(
            'ai_generate_seo',
            'Génère les métadonnées SEO d\'un contenu',
            McpTool::schema([
                'content' => ['type' => 'object', 'description' => 'Contenu à analyser', 'required' => true],
            ], ['content']),
            function (array $args, array $ctx) use ($aiService): array {
                return $aiService->generateSeo($args['content']);
            }
        ));

        $this->registerTool(new McpTool(
            'ai_suggest_schema',
            'Suggère des améliorations de schéma pour une collection',
            McpTool::schema([
                'project_uuid' => McpTool::stringProp('UUID du projet', true),
                'collection_slug' => McpTool::stringProp('Collection à analyser', true),
            ], ['project_uuid', 'collection_slug']),
            function (array $args, array $ctx) use ($aiService): array {
                $collection = $this->findCollection($args['project_uuid'], $args['collection_slug']);
                if (!$collection) throw new McpException('Collection introuvable', 404);

                return $aiService->suggestSchema($collection);
            }
        ));

        $this->registerTool(new McpTool(
            'search_content_semantic',
            'Recherche sémantique dans le contenu (full-text)',
            McpTool::schema([
                'project_uuid' => McpTool::stringProp('UUID du projet', true),
                'query' => McpTool::stringProp('Termes de recherche', true),
                'limit' => McpTool::intProp('Nombre de résultats'),
            ], ['project_uuid', 'query']),
            function (array $args, array $ctx) use ($searchService): array {
                $project = $this->findProject($args['project_uuid']);
                if (!$project) throw new McpException('Projet introuvable', 404);

                return $searchService->search($project, $args['query'], [
                    'limit' => min($args['limit'] ?? 20, 100),
                ]);
            }
        ));

        $this->registerTool(new McpTool(
            'create_version',
            'Crée un snapshot de version d\'une entrée',
            McpTool::schema([
                'project_uuid' => McpTool::stringProp('UUID du projet', true),
                'collection_slug' => McpTool::stringProp('Slug de la collection', true),
                'entry_uuid' => McpTool::stringProp('UUID de l\'entrée', true),
                'label' => McpTool::stringProp('Libellé de la version'),
            ], ['project_uuid', 'collection_slug', 'entry_uuid']),
            function (array $args, array $ctx) use ($versioningService): array {
                $entry = $this->findEntry($args['project_uuid'], $args['collection_slug'], $args['entry_uuid']);
                if (!$entry) throw new McpException('Entrée introuvable', 404);

                $version = $versioningService->createVersion($entry, $args['label'] ?? null);

                return [
                    'uuid' => $version->uuid->toRfc4122(),
                    'versionNumber' => $version->versionNumber,
                    'label' => $version->label,
                    'createdAt' => $version->createdAt->format(\DateTimeInterface::ATOM),
                ];
            }
        ));

        $this->registerTool(new McpTool(
            'list_versions',
            'Liste les versions d\'une entrée',
            McpTool::schema([
                'project_uuid' => McpTool::stringProp('UUID du projet', true),
                'collection_slug' => McpTool::stringProp('Slug de la collection', true),
                'entry_uuid' => McpTool::stringProp('UUID de l\'entrée', true),
            ], ['project_uuid', 'collection_slug', 'entry_uuid']),
            function (array $args, array $ctx): array {
                $entry = $this->findEntry($args['project_uuid'], $args['collection_slug'], $args['entry_uuid']);
                if (!$entry) throw new McpException('Entrée introuvable', 404);

                $repo = $this->em->getRepository(\App\Entity\ContentVersion::class);
                return array_map(fn($v) => [
                    'uuid' => $v->uuid->toRfc4122(),
                    'versionNumber' => $v->versionNumber,
                    'label' => $v->label,
                    'createdAt' => $v->createdAt->format(\DateTimeInterface::ATOM),
                ], $repo->findByEntry($entry));
            }
        ));

        $this->registerTool(new McpTool(
            'transform_image',
            'Obtient l\'URL d\'une image transformée',
            McpTool::schema([
                'media_uuid' => McpTool::stringProp('UUID du média', true),
                'width' => McpTool::intProp('Largeur en pixels'),
                'height' => McpTool::intProp('Hauteur en pixels'),
                'fit' => McpTool::enumProp(['contain', 'cover', 'crop', 'fill', 'scale-down'], 'Mode d\'ajustement'),
                'format' => McpTool::enumProp(['webp', 'avif', 'png', 'jpg'], 'Format de sortie'),
                'quality' => McpTool::intProp('Qualité (1-100)'),
            ], ['media_uuid']),
            function (array $args, array $ctx): array {
                $media = $this->em->getRepository(Media::class)->findOneBy(['uuid' => $args['media_uuid']]);
                if (!$media) throw new McpException('Média introuvable', 404);

                $params = [];
                if (isset($args['width'])) $params['w'] = $args['width'];
                if (isset($args['height'])) $params['h'] = $args['height'];
                if (isset($args['fit'])) $params['fit'] = $args['fit'];
                if (isset($args['format'])) $params['fmt'] = $args['format'];
                if (isset($args['quality'])) $params['q'] = $args['quality'];

                $query = http_build_query($params);
                return [
                    'url' => "/cdn/media/{$args['media_uuid']}" . ($query ? "?$query" : ''),
                    'params' => $params,
                ];
            }
        ));
    }

    // ===== RESOURCES =====

    private function registerResources(): void
    {
        $this->registerResource(new McpResource(
            'jamboapi://server/info',
            'Informations sur le serveur JamboApi',
            'Version, statut et capacités du serveur',
            'application/json',
            fn(array $ctx) => [
                'name' => 'JamboApi CMS',
                'version' => '2.0.0',
                'php' => PHP_VERSION,
                'symfony' => \Symfony\Component\HttpKernel\Kernel::VERSION,
                'tools' => count($this->tools),
                'resources' => count($this->resources),
            ]
        ));
    }

    // ===== HELPERS =====

    private function findProject(string $uuid): ?Project
    {
        return $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
    }

    private function findCollection(string $projectUuid, string $slug): ?Collection
    {
        $project = $this->findProject($projectUuid);
        if (!$project) return null;

        return $this->em->getRepository(Collection::class)->findOneBy([
            'project' => $project, 'slug' => $slug, 'deletedAt' => null,
        ]);
    }

    private function findEntry(string $projectUuid, string $collectionSlug, string $entryUuid): ?ContentEntry
    {
        $collection = $this->findCollection($projectUuid, $collectionSlug);
        if (!$collection) return null;

        return $this->em->getRepository(ContentEntry::class)->findOneBy([
            'collection' => $collection, 'uuid' => $entryUuid, 'deletedAt' => null,
        ]);
    }

    private function createFieldValue(Field $field, mixed $value): ContentFieldValue
    {
        $cfv = new ContentFieldValue();
        $cfv->field = $field;
        $cfv->fieldType = $field->type;
        $this->fieldHelper->setFieldValue($cfv, $field->type, $value);

        return $cfv;
    }

    private function slugify(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9]+/', '_', $text);
        return trim($text, '_');
    }
}
