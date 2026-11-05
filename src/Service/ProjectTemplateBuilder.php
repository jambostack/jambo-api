<?php

namespace App\Service;

use App\Controller\FieldController;
use App\Entity\Collection;
use App\Entity\Field;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Enum\ProjectMemberStatus;
use App\Entity\ProjectTemplate;
use App\Entity\User;
use App\Repository\ProjectRepository;
use App\Repository\ProjectTemplateRepository;
use Doctrine\ORM\EntityManagerInterface;

class ProjectTemplateBuilder
{
    public const MAX_COLLECTIONS_PER_TEMPLATE = 100;
    public const MAX_FIELDS_PER_COLLECTION = 100;
    public const MAX_OPTIONS_PAYLOAD_BYTES = 32768; // 32KB

    public function __construct(
        private ProjectRepository $projectRepository,
        private ProjectTemplateRepository $templateRepository,
        private EntityManagerInterface $em,
        private ?EndUserSchemaSeeder $endUserSchemaSeeder = null,
    ) {}

    public function exportFromProject(string $projectUuid, ?string $name = null, ?string $description = null): ?ProjectTemplate
    {
        $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        if ($project === null) {
            return null;
        }

        $structure = [
            'defaultLocale' => $project->defaultLocale,
            'locales'       => $project->locales,
            'collections'   => [],
        ];

        $colCount = 0;
        foreach ($project->collections as $collection) {
            if ($collection->isDeleted()) {
                continue;
            }
            if (++$colCount > self::MAX_COLLECTIONS_PER_TEMPLATE) {
                break;
            }

            $collectionData = [
                'name'        => mb_substr(preg_replace('/[\x00-\x1F\x7F]/', '', $collection->name), 0, 255),
                'slug'        => mb_substr(preg_replace('/[^a-zA-Z0-9_-]/', '', $collection->slug), 0, 128),
                'description' => $collection->description !== null ? mb_substr(preg_replace('/[\x00-\x1F\x7F]/', '', $collection->description), 0, 2000) : null,
                'isSingleton' => $collection->isSingleton,
                'order'       => $collection->order,
                'fields'      => [],
            ];

            $fCount = 0;
            foreach ($collection->fields as $field) {
                if ($field->isDeleted()) {
                    continue;
                }
                if (++$fCount > self::MAX_FIELDS_PER_COLLECTION) {
                    break;
                }

                $fieldType = in_array($field->type, FieldController::ALLOWED_TYPES, true) ? $field->type : 'text';
                $options = is_array($field->options) ? $field->options : null;
                if ($options !== null && strlen((string) json_encode($options)) > self::MAX_OPTIONS_PAYLOAD_BYTES) {
                    $options = null;
                }

                $collectionData['fields'][] = [
                    'name'       => mb_substr(preg_replace('/[\x00-\x1F\x7F]/', '', $field->name), 0, 255),
                    'slug'       => mb_substr(preg_replace('/[^a-zA-Z0-9_-]/', '', $field->slug), 0, 128),
                    'type'       => $fieldType,
                    'options'    => $options,
                    'order'      => min(1000, max(0, $field->order)),
                    'isRequired' => $field->isRequired,
                ];
            }

            $structure['collections'][] = $collectionData;
        }

        $template = new ProjectTemplate();
        $template->name        = $name ?? $project->name . ' Template';
        $template->description = $description;
        $template->structure   = $structure;

        $this->em->persist($template);
        $this->em->flush();

        return $template;
    }

    public function applyTemplate(ProjectTemplate $template, string $projectName, User $owner): Project
    {
        $structure = $template->structure;

        $cleanProjectName = trim(preg_replace('/[\x00-\x1F\x7F]/', '', $projectName));
        if ($cleanProjectName === '') {
            $cleanProjectName = $template->name ?: 'New Project';
        }
        if (mb_strlen($cleanProjectName) > 255) {
            $cleanProjectName = mb_substr($cleanProjectName, 0, 255);
        }

        $defaultLocale = (string) ($structure['defaultLocale'] ?? 'en');
        if (!preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]+)?$/i', $defaultLocale) || strlen($defaultLocale) > 10) {
            $defaultLocale = 'en';
        }

        $project = new Project();
        $project->name          = $cleanProjectName;
        $project->defaultLocale = $defaultLocale;
        $project->locales       = is_array($structure['locales'] ?? null) ? $structure['locales'] : [$defaultLocale];
        $this->em->persist($project);

        $member           = new ProjectMember();
        $member->project  = $project;
        $member->user     = $owner;
        $member->email    = $owner->email;
        $member->status   = ProjectMemberStatus::Active;
        $member->joinedAt = new \DateTimeImmutable();
        $this->em->persist($member);

        $collections = is_array($structure['collections'] ?? null) ? $structure['collections'] : [];
        foreach (array_slice($collections, 0, self::MAX_COLLECTIONS_PER_TEMPLATE) as $collData) {
            if (!is_array($collData)) {
                continue;
            }
            $rawCollName = trim(preg_replace('/[\x00-\x1F\x7F]/', '', (string) ($collData['name'] ?? 'Collection')));
            $collName = mb_substr($rawCollName !== '' ? $rawCollName : 'Collection', 0, 255);

            $rawCollSlug = trim((string) ($collData['slug'] ?? ''));
            $collSlug = preg_match('/^[a-zA-Z0-9_-]+$/', $rawCollSlug)
                ? mb_substr($rawCollSlug, 0, 128)
                : 'collection_' . bin2hex(random_bytes(3));

            $collection = new Collection();
            $collection->name        = $collName;
            $collection->slug        = $collSlug;
            $collection->description = isset($collData['description']) && is_scalar($collData['description']) ? mb_substr(preg_replace('/[\x00-\x1F\x7F]/', '', (string) $collData['description']), 0, 2000) : null;
            $collection->isSingleton = (bool) ($collData['isSingleton'] ?? false);
            $collection->order       = min(1000, max(0, (int) ($collData['order'] ?? 0)));
            $collection->project     = $project;

            $this->em->persist($collection);

            $fields = is_array($collData['fields'] ?? null) ? $collData['fields'] : [];
            foreach (array_slice($fields, 0, self::MAX_FIELDS_PER_COLLECTION) as $i => $fieldData) {
                if (!is_array($fieldData)) {
                    continue;
                }
                $rawFieldName = trim(preg_replace('/[\x00-\x1F\x7F]/', '', (string) ($fieldData['name'] ?? 'Field')));
                $fieldName = mb_substr($rawFieldName !== '' ? $rawFieldName : 'Field', 0, 255);

                $rawFieldSlug = trim((string) ($fieldData['slug'] ?? ''));
                $fieldSlug = preg_match('/^[a-zA-Z0-9_-]+$/', $rawFieldSlug)
                    ? mb_substr($rawFieldSlug, 0, 128)
                    : 'field_' . $i . '_' . bin2hex(random_bytes(2));

                $rawType = (string) ($fieldData['type'] ?? 'text');
                $fieldType = in_array($rawType, FieldController::ALLOWED_TYPES, true) ? $rawType : 'text';

                $options = is_array($fieldData['options'] ?? null) ? $fieldData['options'] : null;
                if ($options !== null && strlen((string) json_encode($options)) > self::MAX_OPTIONS_PAYLOAD_BYTES) {
                    $options = null;
                }

                $field = new Field();
                $field->name       = $fieldName;
                $field->slug       = $fieldSlug;
                $field->type       = $fieldType;
                $field->options    = $options;
                $field->order      = min(1000, max(0, (int) ($fieldData['order'] ?? $i)));
                $field->isRequired = (bool) ($fieldData['isRequired'] ?? false);
                $field->collection = $collection;

                $this->em->persist($field);
            }
        }

        $this->em->flush();

        // Seed system EndUser fields for the newly created project
        $this->endUserSchemaSeeder?->seed($project);
        $this->em->flush();

        return $project;
    }
}
