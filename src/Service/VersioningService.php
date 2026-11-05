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
        $version = $this->createSnapshot($entry, $this->formatter->formatEntry($entry), $label);
        $this->em->flush();

        return $version;
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
        // Pas de flush ici : appelé depuis onFlush (AutoVersionSubscriber), le flush principal s'en charge

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
            return ['error' => 'version_not_found', 'code' => 'NOT_FOUND'];
        }

        $s1 = $version1->snapshot;
        $s2 = $version2->snapshot;

        // Exclude system/technical metadata from content diff
        $excludedKeys = ['id', 'uuid', 'collection', 'created_at', 'deleted_at'];
        $allKeys = array_unique(array_merge(array_keys($s1), array_keys($s2)));
        $changes = [];

        foreach ($allKeys as $key) {
            if (in_array($key, $excludedKeys, true)) {
                continue;
            }

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

    /**
     * Delete a single version of an entry.
     * Cannot delete the only existing version.
     */
    public function deleteVersion(ContentEntry $entry, int $versionNumber): bool
    {
        $allVersions = $this->versionRepo->findByEntry($entry);
        if (count($allVersions) <= 1) {
            return false;
        }

        $targetVersion = null;
        foreach ($allVersions as $v) {
            if ($v->versionNumber === $versionNumber) {
                $targetVersion = $v;
                break;
            }
        }

        if (!$targetVersion) {
            return false;
        }

        $this->em->remove($targetVersion);
        $this->em->flush();

        return true;
    }

    /**
     * Prune older versions of an entry, keeping the N most recent versions.
     */
    public function pruneVersions(ContentEntry $entry, int $keepCount = 20): int
    {
        if ($keepCount < 1) {
            $keepCount = 1;
        }

        $allVersions = $this->versionRepo->findByEntry($entry);
        if (count($allVersions) <= $keepCount) {
            return 0;
        }

        // $allVersions is sorted DESC by versionNumber
        $toPrune = array_slice($allVersions, $keepCount);
        $prunedCount = 0;

        foreach ($toPrune as $v) {
            $this->em->remove($v);
            $prunedCount++;
        }

        if ($prunedCount > 0) {
            $this->em->flush();
        }

        return $prunedCount;
    }
}
