<?php

namespace App\Service;

use App\Entity\Collection;
use App\Entity\Field;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Entity\User;
use App\Enum\ProjectMemberStatus;
use App\Exception\SchemaException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Source de vérité du provisioning de structure Jambo (projets, collections,
 * champs). Réutilisé par les façades REST /admin-api, MCP et studio afin de ne
 * pas dupliquer les conventions et la validation.
 */
class SchemaProvisioner
{
    private const ALLOWED_TYPES = [
        'text', 'longtext', 'richtext', 'wysiwyg', 'markdown', 'number', 'decimal', 'rating',
        'boolean', 'checkbox', 'date', 'datetime', 'email', 'url', 'slug', 'color', 'icon',
        'code', 'json', 'array', 'repeater', 'enumeration', 'tags', 'media', 'relation',
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private EndUserSchemaSeeder $endUserSchemaSeeder,
        private FieldRelationOptionsNormalizer $relationNormalizer,
    ) {}

    public function createProject(User $owner, array $dto): Project
    {
        if (empty($dto['name'])) {
            throw new SchemaException('name is required', 422);
        }

        $project = new Project();
        $project->name = $dto['name'];
        $project->description = $dto['description'] ?? null;
        $project->defaultLocale = $dto['default_locale'] ?? 'en';
        $project->locales = $dto['locales'] ?? ['en'];
        $project->disk = $dto['disk'] ?? 'public';
        $project->publicApi = (bool) ($dto['public_api'] ?? false);
        $this->em->persist($project);

        $member = new ProjectMember();
        $member->project = $project;
        $member->user = $owner;
        $member->email = $owner->email;
        $member->status = ProjectMemberStatus::Active;
        $member->joinedAt = new \DateTimeImmutable();
        $this->em->persist($member);

        $this->endUserSchemaSeeder->seed($project);
        $this->em->flush();

        return $project;
    }

    public function updateProject(Project $project, array $dto): Project
    {
        if (isset($dto['name'])) {
            $project->name = $dto['name'];
        }
        if (array_key_exists('description', $dto)) {
            $project->description = $dto['description'];
        }
        if (isset($dto['default_locale'])) {
            $project->defaultLocale = $dto['default_locale'];
        }
        if (isset($dto['locales']) && is_array($dto['locales'])) {
            $project->locales = $dto['locales'];
        }
        if (isset($dto['public_api'])) {
            $project->publicApi = (bool) $dto['public_api'];
        }
        $this->em->flush();
        return $project;
    }

    public function deleteProject(Project $project): void
    {
        $this->em->remove($project);
        $this->em->flush();
    }

    public function createCollection(Project $project, array $dto): Collection
    {
        if (empty($dto['name'])) {
            throw new SchemaException('name is required', 422);
        }
        $slug = NamingConvention::toSnakeCase($dto['slug'] ?? $dto['name']);
        $dup = $this->em->getRepository(Collection::class)->findOneBy(['project' => $project, 'slug' => $slug]);
        if ($dup !== null) {
            throw new SchemaException("Collection slug '$slug' already exists", 409);
        }

        $c = new Collection();
        $c->name = NamingConvention::toPascalCase($dto['name']);
        $c->slug = $slug;
        $c->description = $dto['description'] ?? null;
        $c->isSingleton = (bool) ($dto['is_singleton'] ?? false);
        $c->order = $dto['order'] ?? count($project->collections->toArray());
        $c->project = $project;

        $this->em->persist($c);
        $this->em->flush();
        return $c;
    }

    public function updateCollection(Collection $c, array $dto): Collection
    {
        if (isset($dto['name'])) {
            $c->name = NamingConvention::toPascalCase($dto['name']);
        }
        if (array_key_exists('description', $dto)) {
            $c->description = $dto['description'];
        }
        if (isset($dto['is_singleton'])) {
            $c->isSingleton = (bool) $dto['is_singleton'];
        }
        // Mise à jour des settings (public_create, workflow, seo, etc.)
        if (isset($dto['settings']) && is_array($dto['settings'])) {
            $current = $c->settings ?? [];
            $c->settings = array_merge($current, $dto['settings']);
        }
        // Raccourci pour public_create directement dans le dto
        if (isset($dto['public_create'])) {
            $settings = $c->settings ?? [];
            $settings['public_create'] = (bool) $dto['public_create'];
            $c->settings = $settings;
        }
        $this->em->flush();
        return $c;
    }

    public function deleteCollection(Collection $c): void
    {
        $this->em->remove($c);
        $this->em->flush();
    }

    public function addField(Collection $c, array $dto): Field
    {
        if (empty($dto['name']) || empty($dto['type'])) {
            throw new SchemaException('name and type are required', 422);
        }
        if (!in_array($dto['type'], self::ALLOWED_TYPES, true)) {
            throw new SchemaException("Unknown field type '{$dto['type']}'", 422);
        }
        $slug = NamingConvention::toSnakeCase($dto['slug'] ?? $dto['name']);
        if (in_array($slug, NamingConvention::RESERVED_FIELD_SLUGS, true)) {
            throw new SchemaException("Field slug '$slug' is reserved", 422);
        }
        foreach ($c->fields as $existing) {
            if ($existing->slug === $slug) {
                throw new SchemaException("Field slug '$slug' already exists", 409);
            }
        }

        $f = new Field();
        // Norme canonique Jambo : nom de champ en camelCase.
        $f->name = NamingConvention::toCamelCase($dto['name']);
        $f->slug = $slug;
        $f->type = $dto['type'];
        $f->isRequired = (bool) ($dto['is_required'] ?? false);
        $f->options = $this->normalizeOptions($dto['type'], $dto['options'] ?? null, $c);
        if (isset($dto['validationRules'])) {
            $f->validationRules = $dto['validationRules'];
        }
        $f->collection = $c;
        $f->order = $c->fields->count();

        $this->em->persist($f);
        $this->em->flush();
        return $f;
    }

    public function updateField(Field $f, array $dto): Field
    {
        if (isset($dto['name'])) {
            $f->name = NamingConvention::toCamelCase($dto['name']);
        }
        if (isset($dto['slug'])) {
            $f->slug = NamingConvention::toSnakeCase($dto['slug']);
        }
        if (isset($dto['type'])) {
            if (!in_array($dto['type'], self::ALLOWED_TYPES, true)) {
                throw new SchemaException("Unknown field type '{$dto['type']}'", 422);
            }
            $f->type = $dto['type'];
        }
        if (isset($dto['order'])) {
            $f->order = (int) $dto['order'];
        }
        if (array_key_exists('options', $dto)) {
            $f->options = $this->normalizeOptions($f->type, $dto['options'], $f->collection);
        }
        if (array_key_exists('validationRules', $dto)) {
            $f->validationRules = $dto['validationRules'];
        }
        if (isset($dto['is_required'])) {
            $f->isRequired = (bool) $dto['is_required'];
        }
        $this->em->flush();
        return $f;
    }

    public function deleteField(Field $f): void
    {
        $this->em->remove($f);
        $this->em->flush();
    }

    /**
     * Normalise les options de champ. Pour `relation`, délègue au normalizer
     * canonique (format de persistance). Valide `enumeration.choices`.
     */
    private function normalizeOptions(string $type, ?array $options, Collection $collection): ?array
    {
        if ($options === null) {
            return null;
        }
        if ($type === 'relation') {
            return $this->relationNormalizer->normalize($options, $collection->project, forStorage: true);
        }
        if ($type === 'enumeration' && isset($options['choices']) && !is_array($options['choices'])) {
            throw new SchemaException('enumeration.choices must be an array', 422);
        }
        return $options;
    }
}
