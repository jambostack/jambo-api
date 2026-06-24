<?php

declare(strict_types=1);

namespace App\Service\Redirect;

use App\Entity\Project;
use App\Entity\Redirect;
use App\Repository\RedirectRepository;

class RedirectResolver
{
    private const MAX_HOPS = 10;

    public function __construct(
        private readonly RedirectRepository $repository,
    ) {
    }

    /**
     * Resolve a path against the active redirects for the given project.
     *
     * - Checks exact matches first, then pattern matches.
     * - Follows chains up to {@see self::MAX_HOPS} hops.
     * - Detects loops and returns the last valid redirect before the loop.
     * - Increments hit counter and sets lastHitAt when a redirect is used.
     */
    public function resolve(string $path, Project $project): ?Redirect
    {
        $active = $this->repository->findActiveByProject($project);

        if ($active === []) {
            return null;
        }

        return $this->resolveWithRedirects($path, $active);
    }

    /**
     * Internal resolution: try exact first, then pattern, follow chains.
     *
     * @param Redirect[] $active
     */
    private function resolveWithRedirects(string $path, array $active): ?Redirect
    {
        $visited = [];
        $currentPath = $path;
        $lastUsed = null;

        for ($hop = 0; $hop < self::MAX_HOPS; ++$hop) {
            $match = $this->matchFirst($currentPath, $active);

            if ($match === null) {
                return $lastUsed;
            }

            // Loop detection: if we already visited the matched redirect, stop.
            $redirectId = spl_object_id($match);
            if (\in_array($redirectId, $visited, true)) {
                return $lastUsed;
            }
            $visited[] = $redirectId;

            // Track hits
            ++$match->hits;
            $match->lastHitAt = new \DateTimeImmutable();

            $resolvedTo = $match->isPattern
                ? $this->resolvePattern($currentPath, $match)
                : $match->toPath;

            $lastUsed = $match;
            $currentPath = $resolvedTo;
        }

        return $lastUsed;
    }

    /**
     * Try exact match first, then pattern match.
     *
     * @param Redirect[] $redirects
     */
    private function matchFirst(string $path, array $redirects): ?Redirect
    {
        // Exact match
        foreach ($redirects as $redirect) {
            if ($redirect->fromPath === $path) {
                return $redirect;
            }
        }

        // Pattern match
        foreach ($redirects as $redirect) {
            if ($redirect->isPattern && preg_match('#' . $redirect->fromPath . '#', $path) === 1) {
                return $redirect;
            }
        }

        return null;
    }

    /**
     * Apply pattern replacement on the matched path.
     */
    private function resolvePattern(string $path, Redirect $redirect): string
    {
        return (string) preg_replace(
            '#' . $redirect->fromPath . '#',
            $redirect->toPath,
            $path,
            1,
        );
    }
}
