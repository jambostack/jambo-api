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

    public function __construct(
        private EndUserRepository $endUserRepository,
        private ?\App\Service\EndUserCustomFieldsSanitizer $sanitizer = null,
    ) {}

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
        if (!isset($data['end_users']) || !is_array($data['end_users'])) {
            return;
        }

        if (count($data['end_users']) > 5000) {
            throw new \RuntimeException('end_users.json contains too many users (max 5000).');
        }

        foreach ($data['end_users'] as $userData) {
            $rawEmail = trim((string) ($userData['email'] ?? ''));
            if ($rawEmail === '' || strlen($rawEmail) > 191 || !filter_var($rawEmail, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $password = (string) ($userData['password'] ?? '');
            if ($password === '' || strlen($password) > 255) {
                continue;
            }

            $oldUuid = isset($userData['uuid']) && is_string($userData['uuid']) ? $userData['uuid'] : null;
            $existing = $this->endUserRepository->findOneByProjectAndEmail($project, $rawEmail);

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
                $user = new EndUser($project, $rawEmail);
                // Always generate fresh UUIDs for a brand-new project (same reasoning
                // as ContentImportHandler — global unique constraint on end_user.uuid).
                $newUuid = ($options->strategy === 'new_uuids' || $options->createNewProject || !$oldUuid)
                    ? Uuid::v4()->toString()
                    : $oldUuid;
                $user->uuid = Uuid::fromString($newUuid);
                $this->importedUsers[] = $user;
            }

            if ($oldUuid) {
                $uuidMap[$oldUuid] = $newUuid;
            }

            $user->password = $password;
            $rawUsername = isset($userData['username']) ? trim((string) $userData['username']) : null;
            $user->username = $rawUsername !== null ? preg_replace('/[\x00-\x1F\x7F]/', '', substr($rawUsername, 0, 191)) : null;
            $user->status = in_array($userData['status'] ?? 'active', ['active', 'inactive', 'pending', 'suspended'], true) ? $userData['status'] : 'active';
            $user->tokenVersion = max(1, (int) ($userData['token_version'] ?? 1));

            if (isset($userData['created_at']) && is_string($userData['created_at'])) {
                try {
                    $user->createdAt = new \DateTimeImmutable($userData['created_at']);
                } catch (\Throwable) {}
            }
            if (isset($userData['updated_at']) && is_string($userData['updated_at'])) {
                try {
                    $user->updatedAt = new \DateTimeImmutable($userData['updated_at']);
                } catch (\Throwable) {}
            }

            // Étape D: assainissement strict et remap media/relation UUIDs stockés dans customFields
            $customFields = $userData['custom_fields'] ?? null;
            if (is_array($customFields)) {
                if ($this->sanitizer !== null) {
                    try {
                        $sanResult = $this->sanitizer->sanitize($customFields);
                        $customFields = ($sanResult['valid'] && is_array($sanResult['sanitized']))
                            ? $sanResult['sanitized']
                            : [];
                    } catch (\Throwable) {
                        $customFields = [];
                    }
                }
                if (!empty($uuidMap)) {
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
            } else {
                $customFields = null;
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
