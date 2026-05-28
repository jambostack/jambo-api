<?php

namespace App\Service\ExportImport\Export;

use App\Entity\Project;
use App\Repository\EndUserRepository;
use App\Service\ExportImport\ExportHandlerInterface;

class EndUserExportHandler implements ExportHandlerInterface
{
    public function __construct(private EndUserRepository $endUserRepository) {}

    public static function getOptionKey(): string
    {
        return 'end_users';
    }

    public function export(Project $project, string $tempDir): array
    {
        $users = [];
        foreach ($this->endUserRepository->findByProject($project) as $user) {
            $users[] = [
                'uuid'          => $user->uuid?->toString(),
                'email'         => $user->email,
                'password'      => $user->password,
                'name'          => $user->name,
                'status'        => $user->status,
                'token_version' => $user->tokenVersion,
                'custom_fields' => $user->customFields,
                'created_at'    => $user->createdAt->format(\DateTimeInterface::ATOM),
                'updated_at'    => $user->updatedAt->format(\DateTimeInterface::ATOM),
            ];
        }

        $data = json_encode(['end_users' => $users], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        file_put_contents($tempDir . '/end_users.json', $data);

        return [
            'manifest' => ['file' => 'end_users.json', 'entityCount' => count($users)],
            'files'    => ['end_users.json'],
        ];
    }
}
