<?php

namespace App\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Trait providing security hardening, localization, credential redaction,
 * and input validation helpers for Symfony Console CLI commands.
 */
trait CliSecurityTrait
{
    private ?TranslatorInterface $translator = null;

    public function setTranslator(?TranslatorInterface $translator): void
    {
        $this->translator = $translator;
    }

    protected function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null, string $fallback = ''): string
    {
        if ($this->translator !== null) {
            $translated = $this->translator->trans($id, $parameters, $domain, $locale);
            if ($translated !== $id) {
                return $translated;
            }
        }

        if ($fallback !== '') {
            $result = $fallback;
            foreach ($parameters as $k => $v) {
                $result = str_replace($k, (string) $v, $result);
            }
            return $result;
        }

        return $id;
    }

    /**
     * Asserts that a file or directory path is safe from null byte injections and
     * traversal attacks targeting sensitive configuration or system files.
     */
    protected function assertSafePath(string $path, ?string $baseDir = null): string
    {
        if (str_contains($path, "\0")) {
            throw new \InvalidArgumentException('Path contains null bytes.');
        }

        $normalized = str_replace('\\', '/', $path);

        // Disallow accessing or writing to critical project files and hidden system configs
        if (preg_match('#(^|/)(\.env|\.git|composer\.(json|lock)|config/|\.\./\.\.)#i', $normalized)) {
            throw new \InvalidArgumentException("Target path '$path' points to restricted system resources.");
        }

        if ($baseDir !== null) {
            $realBase = realpath($baseDir) ?: $baseDir;
            $realBase = rtrim(str_replace('\\', '/', $realBase), '/');
            $realPath = realpath($path);
            if ($realPath !== false) {
                $realPath = str_replace('\\', '/', $realPath);
                if (!str_starts_with($realPath, $realBase)) {
                    throw new \InvalidArgumentException("Path '$path' traverses outside allowed directory.");
                }
            }
        }

        return $path;
    }

    /**
     * Checks password complexity: at least 8 characters with lowercase, uppercase, and digit.
     */
    protected function validatePasswordComplexity(string $password): bool
    {
        return strlen($password) >= 8
            && preg_match('/[A-Z]/', $password)
            && preg_match('/[a-z]/', $password)
            && preg_match('/[0-9]/', $password);
    }

    /**
     * Generates a cryptographically secure random password guaranteeing uppercase,
     * lowercase, digit, and special characters.
     */
    protected function generateSecurePassword(int $length = 16): string
    {
        $length = max(8, $length);
        $lowers = 'abcdefghjkmnpqrstuvwxyz';
        $uppers = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $digits = '23456789';
        $specials = '!@#$%&*';
        $all = $lowers . $uppers . $digits . $specials;

        $pw = [
            $lowers[random_int(0, strlen($lowers) - 1)],
            $uppers[random_int(0, strlen($uppers) - 1)],
            $digits[random_int(0, strlen($digits) - 1)],
            $specials[random_int(0, strlen($specials) - 1)],
        ];

        for ($i = 4; $i < $length; $i++) {
            $pw[] = $all[random_int(0, strlen($all) - 1)];
        }

        shuffle($pw);
        return implode('', $pw);
    }

    /**
     * Escapes SQL LIKE special wildcard characters (% and _).
     */
    protected function sanitizeLikePattern(string $input): string
    {
        return addcslashes($input, '%_');
    }

    /**
     * Handles confirmation for destructive CLI commands.
     * If --force is passed, proceeds immediately.
     * In interactive mode, prompts the user.
     * In non-interactive mode without --force, safely rejects execution.
     */
    protected function confirmOrAbort(InputInterface $input, SymfonyStyle $io, string $question, bool $default = false): bool
    {
        if ($input->hasOption('force') && $input->getOption('force')) {
            return true;
        }

        if ($input->isInteractive()) {
            return $io->confirm($question, $default);
        }

        $io->error($this->trans('cli.force_required', [], null, null, 'Non-interactive mode requires the --force flag to execute this action.'));
        return false;
    }
}
