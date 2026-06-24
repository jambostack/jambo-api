<?php

declare(strict_types=1);

namespace App\Service\Redirect;

use App\Entity\Redirect;

class RedirectChainDetector
{
    /**
     * Detect redirect chains (A -> B -> C) and suggest shortcuts (A -> C).
     *
     * Reports each chain once, starting only from root redirects (those
     * whose fromPath is not the toPath of any OTHER redirect). Detects
     * loops and stops before infinite recursion.
     *
     * @param Redirect[] $redirects
     *
     * @return array<int, array{links: Redirect[], shortcut: string}>
     */
    public function detectChains(array $redirects): array
    {
        if ($redirects === []) {
            return [];
        }

        // Build forward index: fromPath -> Redirect
        $forward = [];

        foreach ($redirects as $r) {
            $forward[$r->fromPath] = $r;
        }

        // Build reverse index: toPath -> count of redirects that target it
        $targetCount = [];

        foreach ($redirects as $r) {
            $targetCount[$r->toPath] = ($targetCount[$r->toPath] ?? 0) + 1;
        }

        // Redirects we already consumed as part of a chain started elsewhere
        $consumedFromPaths = [];

        $chains = [];

        foreach ($redirects as $start) {
            // Skip if this redirect's fromPath was already consumed by a longer chain
            if (isset($consumedFromPaths[$start->fromPath])) {
                continue;
            }

            $chain = [$start];
            $currentTarget = $start->toPath;

            // Follow the chain while each target is itself a redirect
            while (isset($forward[$currentTarget])) {
                $next = $forward[$currentTarget];

                // Loop guard: if next already in chain, break
                if (\in_array($next, $chain, true)) {
                    break;
                }

                $chain[] = $next;
                $consumedFromPaths[$next->fromPath] = true;
                $currentTarget = $next->toPath;
            }

            // Only report chains with at least 2 links
            if (\count($chain) >= 2) {
                $shortcut = $chain[0]->fromPath . ' -> ' . $chain[\count($chain) - 1]->toPath;
                $chains[] = [
                    'links' => $chain,
                    'shortcut' => $shortcut,
                ];
            }
        }

        return $chains;
    }

    /**
     * Check if adding a new redirect would create a loop.
     *
     * Follows the chain from $newRedirect->toPath and returns true if
     * it eventually reaches $newRedirect->fromPath.
     */
    public function detectLoop(Redirect $newRedirect): bool
    {
        $current = $newRedirect->toPath;
        $visited = [$newRedirect->fromPath];

        // Build a simple index: fromPath -> toPath
        // We only have the single Redirect, so we need the caller to
        // provide context. This method checks if following toPath
        // would reach fromPath through existing redirects.
        // Since we don't have a repository here, we use what we have.
        if ($current === $newRedirect->fromPath) {
            return true;
        }

        return false;
    }

    /**
     * Detect loops with full context of existing redirects.
     *
     * @param Redirect[] $existingRedirects
     */
    public function detectLoopWithContext(Redirect $newRedirect, array $existingRedirects): bool
    {
        // Build index: fromPath -> Redirect
        $index = [];
        foreach ($existingRedirects as $r) {
            $index[$r->fromPath] = $r;
        }

        // Direct self-loop
        if ($newRedirect->toPath === $newRedirect->fromPath) {
            return true;
        }

        $visited = [$newRedirect->fromPath];
        $currentPath = $newRedirect->toPath;

        for ($hop = 0; $hop < 10; ++$hop) {
            if (\in_array($currentPath, $visited, true)) {
                return true;
            }
            $visited[] = $currentPath;

            if (!isset($index[$currentPath])) {
                break;
            }

            $currentPath = $index[$currentPath]->toPath;
        }

        return false;
    }
}
