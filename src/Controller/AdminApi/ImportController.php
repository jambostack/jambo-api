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
use Symfony\Contracts\Translation\TranslatorInterface;

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
        private ?TranslatorInterface $translator = null,
    ) {}

    private function trans(string $id, array $parameters = []): string
    {
        return $this->translator?->trans($id, $parameters) ?? $id;
    }

    public const MAX_PAYLOAD_BYTES = 10485760; // 10 MB
    public const MAX_ROWS = 1000;
    public const MAX_COLUMNS = 100;
    public const MAX_CELL_LENGTH = 65536;

    #[Route('/import-csv', name: 'csv', methods: ['POST'])]
    public function importCsv(string $uuid, string $slug, Request $request): JsonResponse
    {
        $this->requireScope($request, 'content:write');

        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid)) {
            return $this->json(['error' => $this->trans('admin_api.import.invalid_uuid')], 422);
        }

        $identifier = $this->getUser()?->getUserIdentifier() ?? $request->getClientIp() ?? 'anonymous';
        if ($this->importLimiter !== null && false === $this->importLimiter->create($identifier)->consume(1)->isAccepted()) {
            return $this->json(['error' => $this->trans('admin_api.import.too_many_requests')], 429);
        }

        // Contrôle de taille globale du payload
        $contentLength = (int) $request->headers->get('Content-Length', 0);
        if ($contentLength > self::MAX_PAYLOAD_BYTES) {
            return $this->json(['error' => $this->trans('admin_api.import.payload_too_large')], 413);
        }

        try {
            $project = $this->projects->findOneBy(['uuid' => $uuid]);
        } catch (\Throwable) {
            return $this->json(['error' => $this->trans('admin_api.import.project_not_found')], 404);
        }
        if (!$project) return $this->json(['error' => $this->trans('admin_api.import.project_not_found')], 404);

        $collection = $this->collections->findOneBy(['project' => $project, 'slug' => $slug]);
        if (!$collection) return $this->json(['error' => $this->trans('admin_api.import.collection_not_found')], 404);

        // Find all collection fields for validation
        $collectionFields = $this->fields->findBy(['collection' => $collection, 'deletedAt' => null]);
        $fieldMap = [];
        foreach ($collectionFields as $f) {
            $fieldMap[$f->slug] = $f;
        }

        // Support d'un fichier CSV uploadé en multipart/form-data via stream chunking
        $uploadedFile = $request->files->get('file');
        if ($uploadedFile !== null) {
            if (!$uploadedFile->isValid()) {
                return $this->json(['error' => $this->trans('admin_api.import.upload_error', ['%error%' => $uploadedFile->getErrorMessage()])], 422);
            }
            if ($uploadedFile->getSize() > self::MAX_PAYLOAD_BYTES) {
                return $this->json(['error' => $this->trans('admin_api.import.file_too_large')], 413);
            }

            return $this->processCsvFileStream(
                $uploadedFile->getPathname(),
                $project,
                $collection,
                $fieldMap,
                $request->request->all(),
                $uuid,
                $slug
            );
        }

        // Import via payload JSON
        $rawContent = $request->getContent();
        if (strlen($rawContent) > self::MAX_PAYLOAD_BYTES) {
            return $this->json(['error' => $this->trans('admin_api.import.payload_too_large')], 413);
        }

        try {
            $body = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['error' => $this->trans('admin_api.import.invalid_json')], 400);
        }
        $csvData = $body['data'] ?? [];
        $fieldMapping = $body['field_mapping'] ?? [];
        $locale = $body['locale'] ?? $project->defaultLocale;

        if (!is_array($csvData) || !is_array($fieldMapping) || empty($csvData) || empty($fieldMapping)) {
            return $this->json(['error' => $this->trans('admin_api.import.missing_required_fields')], 422);
        }

        if (count($csvData) > self::MAX_ROWS) {
            return $this->json(['error' => $this->trans('admin_api.import.rows_limit_exceeded', ['%max%' => self::MAX_ROWS])], 422);
        }

        if (count($fieldMapping) > self::MAX_COLUMNS) {
            return $this->json(['error' => $this->trans('admin_api.import.columns_limit_exceeded', ['%max%' => self::MAX_COLUMNS])], 422);
        }

        // Validation stricte des colonnes mappées
        foreach ($fieldMapping as $csvCol => $fieldSlug) {
            if (!is_string($csvCol) || !is_string($fieldSlug) || trim($csvCol) === '' || trim($fieldSlug) === '' || strlen($csvCol) > 128 || strlen($fieldSlug) > 128) {
                return $this->json(['error' => $this->trans('admin_api.import.invalid_mapping_structure')], 422);
            }
            if (!isset($fieldMap[$fieldSlug])) {
                return $this->json(['error' => $this->trans('admin_api.import.target_column_not_found', ['%field%' => $fieldSlug, '%collection%' => $slug])], 422);
            }
        }

        $created = 0;
        $errors = [];

        foreach ($csvData as $idx => $row) {
            if (!is_array($row)) {
                $errors[] = ['row' => $idx, 'error' => $this->trans('admin_api.import.invalid_row_format')];
                continue;
            }
            if (count($row) > self::MAX_COLUMNS + 50) {
                $errors[] = ['row' => $idx, 'error' => $this->trans('admin_api.import.excessive_columns_in_row')];
                continue;
            }

            try {
                // Contrôle de longueur des cellules
                foreach ($row as $cellKey => $cellValue) {
                    if (is_string($cellValue) && strlen($cellValue) > self::MAX_CELL_LENGTH) {
                        throw new \InvalidArgumentException($this->trans('admin_api.import.cell_too_large', ['%column%' => (string) $cellKey]));
                    }
                }

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

                // Protection mémoire (stream chunking / detach)
                $this->em->detach($entry);
                foreach ($entry->fieldValues as $fv) {
                    $this->em->detach($fv);
                }

                if ($idx > 0 && $idx % 50 === 0) {
                    gc_collect_cycles();
                }
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

    /**
     * Traite un fichier CSV streamé par blocs avec protection mémoire stricte.
     */
    private function processCsvFileStream(
        string $filePath,
        \App\Entity\Project $project,
        \App\Entity\Collection $collection,
        array $fieldMap,
        array $body,
        string $uuid,
        string $slug
    ): JsonResponse {
        $handle = @fopen($filePath, 'r');
        if (!$handle) {
            return $this->json(['error' => $this->trans('admin_api.import.csv_read_error')], 422);
        }

        $header = fgetcsv($handle);
        if (!$header || empty($header)) {
            fclose($handle);
            return $this->json(['error' => $this->trans('admin_api.import.csv_headers_invalid')], 422);
        }

        if (count($header) > self::MAX_COLUMNS) {
            fclose($handle);
            return $this->json(['error' => $this->trans('admin_api.import.csv_too_many_columns', ['%max%' => self::MAX_COLUMNS])], 422);
        }

        // Mapping par défaut si non spécifié : slugs identiques aux colonnes
        $rawMapping = $body['field_mapping'] ?? [];
        if (is_string($rawMapping)) {
            $rawMapping = json_decode($rawMapping, true) ?: [];
        }

        $fieldMapping = [];
        if (!empty($rawMapping) && is_array($rawMapping)) {
            foreach ($rawMapping as $csvCol => $targetSlug) {
                if (!is_string($csvCol) || !is_string($targetSlug) || !isset($fieldMap[$targetSlug])) {
                    fclose($handle);
                    return $this->json(['error' => $this->trans('admin_api.import.invalid_column_mapping', ['%column%' => (string) $targetSlug])], 422);
                }
                $fieldMapping[$csvCol] = $targetSlug;
            }
        } else {
            foreach ($header as $col) {
                $trimmed = trim((string) $col);
                if (isset($fieldMap[$trimmed])) {
                    $fieldMapping[$trimmed] = $trimmed;
                }
            }
        }

        if (empty($fieldMapping)) {
            fclose($handle);
            return $this->json(['error' => $this->trans('admin_api.import.no_matching_columns')], 422);
        }

        $locale = $body['locale'] ?? $project->defaultLocale;
        $created = 0;
        $errors = [];
        $rowCount = 0;

        while (($rowValues = fgetcsv($handle)) !== false) {
            $rowCount++;
            if ($rowCount > self::MAX_ROWS) {
                $errors[] = ['row' => $rowCount, 'error' => $this->trans('admin_api.import.max_rows_reached', ['%max%' => self::MAX_ROWS])];
                break;
            }

            if (count($rowValues) > count($header) + 50) {
                $errors[] = ['row' => $rowCount, 'error' => $this->trans('admin_api.import.cell_count_mismatch')];
                continue;
            }

            $row = [];
            foreach ($header as $i => $h) {
                $val = $rowValues[$i] ?? '';
                if (is_string($val) && strlen($val) > self::MAX_CELL_LENGTH) {
                    $val = substr($val, 0, self::MAX_CELL_LENGTH);
                }
                $row[$h] = $val;
            }

            try {
                $entry = new ContentEntry();
                $entry->project = $project;
                $entry->collection = $collection;
                $entry->locale = $locale;
                $entry->status = $body['status'] ?? 'draft';

                $slugSource = '';
                foreach ($fieldMapping as $csvCol => $fieldSlug) {
                    if (isset($row[$csvCol]) && $row[$csvCol] !== '' && $slugSource === '') {
                        $slugSource = $row[$csvCol];
                    }
                }
                $slugStr = $slugSource ?: "imported-$rowCount-" . bin2hex(random_bytes(3));
                $entry->slug = $this->ensureUniqueSlug($collection, $slugStr, $locale);

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

                // Détachement immédiat pour maintien constant de l'empreinte mémoire
                $this->em->detach($entry);
                foreach ($entry->fieldValues as $fv) {
                    $this->em->detach($fv);
                }

                if ($rowCount % 50 === 0) {
                    gc_collect_cycles();
                }
            } catch (\Throwable $e) {
                $errors[] = ['row' => $rowCount, 'error' => $e->getMessage()];
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

        fclose($handle);

        return $this->json([
            'data' => [
                'created' => $created,
                'errors'  => $errors,
                'total'   => $rowCount,
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
