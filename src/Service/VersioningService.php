<?php

namespace App\Service;

use App\Entity\ContentEntry;
use App\Entity\ContentFieldValue;
use App\Entity\ContentVersion;
use App\Repository\ContentVersionRepository;
use Doctrine\ORM\EntityManagerInterface;

class VersioningService
{
    public function __construct(
        private EntityManagerInterface $em,
        private ContentVersionRepository $versionRepo,
        private EavDataFormatterService $formatter,
        private EavFieldHelperService $fieldHelper,
    ) {}

    /**
     * Create a snapshot of the current entry state.
     */
    public function createVersion(ContentEntry $entry, ?string $label = null): ContentVersion
    {
        return $this->createSnapshot($entry, $this->formatter->formatEntry($entry), $label);
    }

    /**
     * Create a snapshot from a pre-built data array (used by AutoVersionSubscriber for old state).
     */
    public function createSnapshot(ContentEntry $entry, array $snapshotData, ?string $label = null): ContentVersion
    {
        $version = new ContentVersion();
        $version->contentEntry = $entry;
        $version->snapshot = $snapshotData;
        $version->label = $label;
        $version->versionNumber = $this->versionRepo->getNextVersionNumber($entry);

        $this->em->persist($version);
        $this->em->flush();

        return $version;
    }

    /**
     * Restore an entry to a previous version.
     */
    public function restoreVersion(ContentEntry $entry, int $versionNumber): bool
    {
        $version = $this->versionRepo->findVersionByNumber($entry, $versionNumber);
        if (!$version) {
            return false;
        }

        $snapshot = $version->snapshot;

        // Remove current field values
        foreach ($entry->fieldValues as $fv) {
            $this->em->remove($fv);
        }
        $entry->fieldValues->clear();

        // Restore from snapshot
        foreach ($snapshot as $key => $value) {
            if (in_array($key, ['id', 'uuid', 'collection', 'created_at', 'updated_at', 'deleted_at', 'creator', 'updater'])) {
                continue;
            }

            $field = $this->fieldHelper->findField($entry->collection, $key);
            if (!$field) {
                continue;
            }

            $cfv = new ContentFieldValue();
            $cfv->field = $field;
            $cfv->fieldType = $field->type;
            $cfv->contentEntry = $entry;
            $this->fieldHelper->setFieldValue($cfv, $field->type, $value);
            $entry->fieldValues->add($cfv);
        }

        $entry->updatedAt = new \DateTimeImmutable();
        $this->em->flush();

        return true;
    }

    /**
     * Compare two versions and return the diff.
     */
    public function diff(ContentEntry $entry, int $v1, int $v2): array
    {
        $version1 = $this->versionRepo->findVersionByNumber($entry, $v1);
        $version2 = $this->versionRepo->findVersionByNumber($entry, $v2);

        if (!$version1 || !$version2) {
            return ['error' => 'Version introuvable'];
        }

        $s1 = $version1->snapshot;
        $s2 = $version2->snapshot;

        $allKeys = array_unique(array_merge(array_keys($s1), array_keys($s2)));
        $changes = [];

        foreach ($allKeys as $key) {
            $val1 = $s1[$key] ?? null;
            $val2 = $s2[$key] ?? null;

            if ($val1 !== $val2) {
                $changes[$key] = [
                    'from' => $val1,
                    'to' => $val2,
                ];
            }
        }

        return [
            'version1' => $v1,
            'version2' => $v2,
            'changes' => $changes,
        ];
    }
}
