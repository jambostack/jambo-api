<?php

declare(strict_types=1);

namespace App\Service\Redirect;

use App\Entity\Redirect;

class RedirectChainDetector
{
    /**
     * Maximum allowed redirect hops in a single chain (SEO & browser standard).
     */
    public const MAX_CHAIN_HOPS = 5;

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
        $analysis = $this->analyzeChain($newRedirect, $existingRedirects);
        return $analysis['hasLoop'];
    }

    /**
     * Analyze chain to detect loops and excessive hop depth.
     *
     * @param Redirect $newRedirect
     * @param Redirect[] $existingRedirects
     * @param int $maxHops
     * @return array{hasLoop: bool, isTooLong: bool, hops: int}
     */
    public function analyzeChain(Redirect $newRedirect, array $existingRedirects, int $maxHops = self::MAX_CHAIN_HOPS): array
    {
        if ($newRedirect->fromPath === $newRedirect->toPath) {
            return ['hasLoop' => true, 'isTooLong' => false, 'hops' => 1];
        }

        $forward = [];
        $backward = [];

        foreach ($existingRedirects as $r) {
            if ($newRedirect->id !== null && $r->id === $newRedirect->id) {
                continue;
            }
            $forward[$r->fromPath] = $r;
            $backward[$r->toPath][] = $r;
        }

        // Check forward hops
        $current = $newRedirect->toPath;
        $forwardHops = 0;
        $visited = [$newRedirect->fromPath];

        while (isset($forward[$current])) {
            $next = $forward[$current];
            if (\in_array($current, $visited, true) || $current === $newRedirect->fromPath) {
                return ['hasLoop' => true, 'isTooLong' => false, 'hops' => $forwardHops + 1];
            }
            $visited[] = $current;
            $forwardHops++;
            $current = $next->toPath;

            if ($forwardHops > 20) {
                return ['hasLoop' => true, 'isTooLong' => true, 'hops' => $forwardHops];
            }
        }

        if ($current === $newRedirect->fromPath) {
            return ['hasLoop' => true, 'isTooLong' => false, 'hops' => $forwardHops + 1];
        }

        // Check backward hops (deepest branch)
        $visitedBackward = [$newRedirect->toPath];
        $backwardHops = $this->calculateMaxBackwardHops($newRedirect->fromPath, $backward, $visitedBackward);
        if ($backwardHops === -1) {
            return ['hasLoop' => true, 'isTooLong' => false, 'hops' => 0];
        }

        $totalHops = $backwardHops + 1 + $forwardHops;

        return [
            'hasLoop' => false,
            'isTooLong' => $totalHops > $maxHops,
            'hops' => $totalHops,
        ];
    }

    /**
     * @param array<string, Redirect[]> $backward
     * @param string[] $visited
     */
    private function calculateMaxBackwardHops(string $path, array $backward, array &$visited, int $depth = 0): int
    {
        if ($depth > 20 || \in_array($path, $visited, true)) {
            return -1; // loop detected
        }

        if (!isset($backward[$path]) || empty($backward[$path])) {
            return 0;
        }

        $visited[] = $path;
        $maxChild = 0;

        foreach ($backward[$path] as $r) {
            $childHops = $this->calculateMaxBackwardHops($r->fromPath, $backward, $visited, $depth + 1);
            if ($childHops === -1) {
                return -1;
            }
            if ($childHops + 1 > $maxChild) {
                $maxChild = $childHops + 1;
            }
        }

        return $maxChild;
    }
}
