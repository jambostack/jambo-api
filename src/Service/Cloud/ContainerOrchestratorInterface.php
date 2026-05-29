<?php
// src/Service/Cloud/ContainerOrchestratorInterface.php
namespace App\Service\Cloud;

interface ContainerOrchestratorInterface
{
    /**
     * Build a Docker image from a set of files plus a Dockerfile.
     * @param array<string,string> $files path => content
     * @return string image tag/ref
     */
    public function buildImage(string $tag, array $files, string $dockerfile): string;

    /**
     * Run a container from an image with the given Traefik labels and env.
     * @param array<string,string> $labels
     * @param array<string,string> $env
     * @return string container id
     */
    public function runContainer(string $imageRef, string $name, array $labels, array $env): string;

    public function stopContainer(string $containerId): void;

    public function removeContainer(string $containerId): void;

    /** @return string one of: running|exited|missing */
    public function containerStatus(string $containerId): string;
}
