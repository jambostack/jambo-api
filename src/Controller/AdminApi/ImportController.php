<?php

namespace App\Controller\AdminApi;

use App\Entity\ContentEntry;
use App\Entity\ContentFieldValue;
use App\Exception\SchemaException;
use App\Repository\CollectionRepository;
use App\Repository\FieldRepository;
use App\Repository\ProjectRepository;
use App\Service\ApiTokenChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('/admin-api/projects/{uuid}/collections/{slug}', name: 'admin_api_import_')]
class ImportController extends AbstractController
{
    use AdminApiControllerTrait;

    public function __construct(
        private ProjectRepository $projects,
        private CollectionRepository $collections,
        private FieldRepository $fields,
        private EntityManagerInterface $em,
        private SluggerInterface $slugger,
        private ?\App\Service\EavFieldHelperService $fieldHelper = null,
        private ?\Doctrine\Persistence\ManagerRegistry $doctrine = null,
        private ?RateLimiterFactory $importLimiter = null,
    ) {}

    #[Route('/import-csv', name: 'csv', methods: ['POST'])]
    public function importCsv(string $uuid, string $slug, Request $request): JsonResponse
    {
        $this->requireScope($request, 'content:write');

        $identifier = $this->getUser()?->getUserIdentifier() ?? $request->getClientIp() ?? 'anonymous';
        if ($this->importLimiter !== null && false === $this->importLimiter->create($identifier)->consume(1)->isAccepted()) {
            return $this->json(['error' => 'Too many import requests. Please retry later.'], 429);
        }

        try {
            $project = $this->projects->findOneBy(['uuid' => $uuid]);
        } catch (\Throwable) {
            return $this->json(['error' => 'Project not found'], 404);
        }
        if (!$project) return $this->json(['error' => 'Project not found'], 404);

        $collection = $this->collections->findOneBy(['project' => $project, 'slug' => $slug]);
        if (!$collection) return $this->json(['error' => 'Collection not found'], 404);

        try {
            $body = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['error' => 'Invalid JSON body'], 400);
        }
        $csvData = $body['data'] ?? [];
        $fieldMapping = $body['field_mapping'] ?? [];
        $locale = $body['locale'] ?? $project->defaultLocale;

        if (!is_array($csvData) || !is_array($fieldMapping) || empty($csvData) || empty($fieldMapping)) {
            return $this->json(['error' => 'Champs requis manquants : data[], field_mapping{}'], 422);
        }

        if (count($csvData) > 1000) {
            return $this->json(['error' => 'Le nombre de lignes dépasse la limite autorisée (max 1000 lignes par import).'], 422);
        }

        // Find all collection fields for validation
        $collectionFields = $this->fields->findBy(['collection' => $collection, 'deletedAt' => null]);
        $fieldMap = [];
        foreach ($collectionFields as $f) {
            $fieldMap[$f->slug] = $f;
        }

        $created = 0;
        $errors = [];

        foreach ($csvData as $idx => $row) {
            try {
                $entry = new ContentEntry();
                $entry->project = $project;
                $entry->collection = $collection;
                $entry->locale = $locale;
                $entry->status = $body['status'] ?? 'draft';

                // Build slug from first mapped text field or row index
                $slugSource = '';
                foreach ($fieldMapping as $csvCol => $fieldSlug) {
                    if (isset($row[$csvCol]) && $row[$csvCol] !== '' && $slugSource === '') {
                        $slugSource = $row[$csvCol];
                    }
                }
                if (!empty($body['slug_field']) && isset($row[$body['slug_field']])) {
                    $slugSource = $row[$body['slug_field']];
                }
                $slugStr = $slugSource ?: "imported-$idx-" . bin2hex(random_bytes(3));
                $entry->slug = $this->ensureUniqueSlug($collection, $slugStr, $locale);

                // Set field values
                foreach ($fieldMapping as $csvCol => $fieldSlug) {
                    if (!isset($fieldMap[$fieldSlug]) || !isset($row[$csvCol])) continue;
                    $field = $fieldMap[$fieldSlug];
                    $value = $row[$csvCol];
                    $fv = new ContentFieldValue();
                    $fv->contentEntry = $entry;
                    $fv->field = $field;
                    $fv->fieldType = $field->type;
                    if ($this->fieldHelper !== null) {
                        $this->fieldHelper->setFieldValue($fv, $field->type, $value);
                    } else {
                        switch ($field->type) {
                            case 'number':
                            case 'decimal':
                                $fv->numberValue = is_numeric($value) ? (string) $value : null;
                                break;
                            case 'boolean':
                                $fv->booleanValue = in_array(strtolower((string) $value), ['1', 'true', 'yes', 'oui', 'on'], true);
                                break;
                            default:
                                $fv->textValue = (string) $value;
                        }
                    }
                    $this->em->persist($fv);
                    $entry->fieldValues->add($fv);
                }

                $this->em->persist($entry);
                $this->em->flush();
                $created++;
            } catch (\Throwable $e) {
                $errors[] = ['row' => $idx, 'error' => $e->getMessage()];
                if ($this->doctrine !== null && !$this->em->isOpen()) {
                    $this->em = $this->doctrine->resetManager();
                    $project = $this->projects->findOneBy(['uuid' => $uuid]);
                    $collection = $this->collections->findOneBy(['project' => $project, 'slug' => $slug]);
                    $collectionFields = $this->fields->findBy(['collection' => $collection, 'deletedAt' => null]);
                    $fieldMap = [];
                    foreach ($collectionFields as $f) {
                        $fieldMap[$f->slug] = $f;
                    }
                }
            }
        }

        return $this->json([
            'data' => [
                'created' => $created,
                'errors'  => $errors,
                'total'   => count($csvData),
            ]
        ], empty($errors) ? 201 : 200);
    }

    private function ensureUniqueSlug(\App\Entity\Collection $collection, string $baseSlug, string $locale): string
    {
        $base = (string) $this->slugger->slug($baseSlug)->lower()->truncate(50, '');
        if ($base === '') {
            $base = 'entry';
        }
        $slug = $base;
        $counter = 1;

        while ($counter <= 100) {
            $qb = $this->em->createQueryBuilder();
            $qb->select('COUNT(e.id)')
               ->from(ContentEntry::class, 'e')
               ->where('e.collection = :collection')
               ->andWhere('e.slug = :slug')
               ->andWhere('e.locale = :locale')
               ->setParameter('collection', $collection)
               ->setParameter('slug', $slug)
               ->setParameter('locale', $locale);

            if ((int) $qb->getQuery()->getSingleScalarResult() === 0) {
                return $slug;
            }

            $suffix = '-' . $counter;
            $slug = substr($base, 0, max(1, 50 - strlen($suffix))) . $suffix;
            $counter++;
        }

        // En cas de collisions répétées, ajouter un suffixe aléatoire sécurisé
        return substr($base, 0, 40) . '-' . bin2hex(random_bytes(4));
    }
}
