<?php

namespace App\Service;

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
    public function __construct(
        private ProjectRepository $projectRepository,
        private ProjectTemplateRepository $templateRepository,
        private EntityManagerInterface $em,
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

        foreach ($project->collections as $collection) {
            if ($collection->isDeleted()) {
                continue;
            }

            $collectionData = [
                'name'        => $collection->name,
                'slug'        => $collection->slug,
                'description' => $collection->description,
                'isSingleton' => $collection->isSingleton,
                'order'       => $collection->order,
                'fields'      => [],
            ];

            foreach ($collection->fields as $field) {
                if ($field->isDeleted()) {
                    continue;
                }
                $collectionData['fields'][] = [
                    'name'       => $field->name,
                    'slug'       => $field->slug,
                    'type'       => $field->type,
                    'options'    => $field->options,
                    'order'      => $field->order,
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

        $project = new Project();
        $project->name          = $projectName;
        $project->defaultLocale = $structure['defaultLocale'] ?? 'en';
        $project->locales       = $structure['locales'] ?? ['en'];
        $this->em->persist($project);

        $member           = new ProjectMember();
        $member->project  = $project;
        $member->user     = $owner;
        $member->email    = $owner->email;
        $member->status   = ProjectMemberStatus::Active;
        $member->joinedAt = new \DateTimeImmutable();
        $this->em->persist($member);

        foreach ($structure['collections'] ?? [] as $collData) {
            $collection = new Collection();
            $collection->name        = $collData['name'];
            $collection->slug        = $collData['slug'];
            $collection->description = $collData['description'] ?? null;
            $collection->isSingleton = $collData['isSingleton'] ?? false;
            $collection->order       = $collData['order'] ?? 0;
            $collection->project     = $project;

            $this->em->persist($collection);

            foreach ($collData['fields'] ?? [] as $fieldData) {
                $field = new Field();
                $field->name       = $fieldData['name'];
                $field->slug       = $fieldData['slug'];
                $field->type       = $fieldData['type'] ?? 'text';
                $field->options    = $fieldData['options'] ?? null;
                $field->order      = $fieldData['order'] ?? 0;
                $field->isRequired = $fieldData['isRequired'] ?? false;
                $field->collection = $collection;

                $this->em->persist($field);
            }
        }

        $this->em->flush();

        return $project;
    }
}
