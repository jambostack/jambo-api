<?php

namespace App\Service\ExportImport\Import;

use App\Dto\ConflictItem;
use App\Dto\ImportOptions;
use App\Entity\EndUser;
use App\Entity\Project;
use App\Repository\EndUserRepository;
use App\Service\ExportImport\ImportHandlerInterface;
use Symfony\Component\Uid\Uuid;

class EndUserImportHandler implements ImportHandlerInterface
{
    /** @var EndUser[] */
    private array $importedUsers = [];

    public function __construct(private EndUserRepository $endUserRepository) {}

    public static function getOptionKey(): string
    {
        return 'end_users';
    }

    /** @return EndUser[] */
    public function getImportedUsers(): array
    {
        return $this->importedUsers;
    }

    public function import(Project $project, string $extractedDir, ImportOptions $options, array &$uuidMap): void
    {
        $this->importedUsers = [];
        $path = $extractedDir . '/end_users.json';
        if (!file_exists($path)) {
            return;
        }

        $data = json_decode(file_get_contents($path), true);
        if (!isset($data['end_users'])) {
            return;
        }

        foreach ($data['end_users'] as $userData) {
            $oldUuid = $userData['uuid'] ?? null;
            $existing = $this->endUserRepository->findOneByProjectAndEmail($project, $userData['email']);

            if ($existing !== null) {
                if ($options->strategy === 'skip') {
                    if ($oldUuid) {
                        $uuidMap[$oldUuid] = $existing->uuid?->toString() ?? $oldUuid;
                    }
                    continue;
                }
                $user = $existing;
                $newUuid = $existing->uuid?->toString() ?? $oldUuid;
            } else {
                $user = new EndUser($project, $userData['email']);
                $newUuid = ($options->strategy === 'new_uuids' || !$oldUuid)
                    ? Uuid::v4()->toString()
                    : $oldUuid;
                $user->uuid = Uuid::fromString($newUuid);
                $this->importedUsers[] = $user;
            }

            if ($oldUuid) {
                $uuidMap[$oldUuid] = $newUuid;
            }

            $user->password = $userData['password'];
            $user->name = $userData['name'] ?? null;
            $user->status = $userData['status'] ?? 'active';
            $user->tokenVersion = (int) ($userData['token_version'] ?? 1);

            if (isset($userData['created_at'])) {
                $user->createdAt = new \DateTimeImmutable($userData['created_at']);
            }
            if (isset($userData['updated_at'])) {
                $user->updatedAt = new \DateTimeImmutable($userData['updated_at']);
            }

            // Étape D: remap media/relation UUIDs stored in customFields
            $customFields = $userData['custom_fields'] ?? null;
            if (is_array($customFields) && !empty($uuidMap)) {
                foreach ($customFields as $slug => $value) {
                    if (is_array($value)) {
                        $customFields[$slug] = array_map(
                            static fn($v) => is_string($v) && isset($uuidMap[$v]) ? $uuidMap[$v] : $v,
                            $value,
                        );
                    } elseif (is_string($value) && isset($uuidMap[$value])) {
                        $customFields[$slug] = $uuidMap[$value];
                    }
                }
            }
            $user->customFields = $customFields;
        }
    }

    public function previewConflicts(Project $project, string $extractedDir): array
    {
        $path = $extractedDir . '/end_users.json';
        if (!file_exists($path)) {
            return [];
        }

        $data = json_decode(file_get_contents($path), true);
        $conflicts = [];

        foreach ($data['end_users'] ?? [] as $userData) {
            $existing = $this->endUserRepository->findOneByProjectAndEmail($project, $userData['email']);
            if ($existing !== null) {
                $conflicts[] = ConflictItem::create(
                    'end_user',
                    $userData['email'],
                    $userData['uuid'] ?? '',
                    $existing->uuid?->toString() ?? $userData['email'],
                );
            }
        }

        return $conflicts;
    }
}
