<?php

namespace App\Dto;

class ImportOptions
{
    public string $strategy = 'skip'; // 'overwrite' | 'skip' | 'new_uuids'
    public bool $createNewProject = true;
    public ?string $newProjectName = null;
    public ?string $ownerEmail = null;

    public static function fromRequest(array $data): self
    {
        $options = new self();
        if (isset($data['strategy'])) { $options->strategy = $data['strategy']; }
        if (isset($data['create_new_project'])) { $options->createNewProject = (bool) $data['create_new_project']; }
        if (isset($data['new_project_name'])) { $options->newProjectName = $data['new_project_name']; }
        if (isset($data['owner_email'])) { $options->ownerEmail = $data['owner_email']; }
        return $options;
    }
}
