<?php
// src/Workbench/Templates/BaseTemplate.php
namespace App\Workbench\Templates;

abstract class BaseTemplate
{
    abstract public function getId(): string;
    abstract public function getLabel(): string;

    /**
     * Returns starter file map: [ 'path/to/file' => 'file content' ].
     * @param array<array{name:string,slug:string,fields:array<array{name:string,slug:string,type:string,isRequired:bool}>}> $collections
     * @return array<string,string>
     */
    abstract public function getStarterFiles(
        string $jamboApiUrl,
        string $projectUuid,
        array $collections,
    ): array;

    abstract public function getDevCommand(): string;

    /** Returns the Dockerfile content for production Docker build. */
    abstract public function getDockerfile(): string;

    /** Returns the npm build command. */
    public function getBuildCommand(): string
    {
        return 'npm run build';
    }

    public function getInstallCommand(): string
    {
        return 'npm install --legacy-peer-deps';
    }
}
