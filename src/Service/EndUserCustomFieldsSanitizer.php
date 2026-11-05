<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Service de validation et d'assainissement strict des champs personnalisés (custom_fields) d'un EndUser.
 * Protection contre :
 * - Prototype pollution (__proto__, constructor, prototype)
 * - Injection d'opérateurs ($where, $gt, etc.)
 * - Écrasement / contournement d'attributs internes (id, uuid, roles, password, tokenVersion, twoFactorSecret)
 * - Dépassement de pile par récursion excessive (max depth: 5)
 * - Déni de service par saturation mémoire / surcharge de clés (max 100 clés, max 64KB JSON)
 * - Caractères de contrôle et octets nuls (\0).
 */
class EndUserCustomFieldsSanitizer
{
    public const MAX_BYTES = 65536; // 64 KB
    public const MAX_DEPTH = 5;
    public const MAX_KEYS = 100;
    public const MAX_KEY_LENGTH = 64;

    /** Liste noire de clés interdites (prototype pollution, mass-assignment, injection). */
    private const FORBIDDEN_KEYS = [
        '__proto__',
        'constructor',
        'prototype',
        'id',
        'uuid',
        'roles',
        'role',
        'password',
        'tokenversion',
        'token_version',
        'twofactorsecret',
        'two_factor_secret',
        'project',
        'project_id',
        'isadmin',
        'is_admin',
        'status',
        'email',
        'createdat',
        'created_at',
        'updatedat',
        'updated_at',
    ];

    public function __construct(
        private ?TranslatorInterface $translator = null,
    ) {}

    /**
     * Valide et assainit un tableau ou objet de custom_fields.
     *
     * @return array{valid: bool, error: ?string, sanitized: ?array<string, mixed>}
     */
    public function sanitize(mixed $input): array
    {
        if ($input === null) {
            return ['valid' => true, 'error' => null, 'sanitized' => null];
        }

        if (!is_array($input)) {
            return [
                'valid' => false,
                'error' => $this->trans('enduser.custom_fields.must_be_object', [], 'Custom fields must be a valid key-value object.'),
                'sanitized' => null,
            ];
        }

        // Vérification de la taille brute JSON
        $json = json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || strlen($json) > self::MAX_BYTES) {
            return [
                'valid' => false,
                'error' => $this->trans('enduser.custom_fields.too_large', ['%max%' => (string) self::MAX_BYTES], 'Custom fields exceed maximum allowed size (max 64KB).'),
                'sanitized' => null,
            ];
        }

        $keyCount = 0;
        try {
            $sanitized = $this->sanitizeRecursive($input, 1, $keyCount);
        } catch (\InvalidArgumentException $e) {
            return [
                'valid' => false,
                'error' => $e->getMessage(),
                'sanitized' => null,
            ];
        }

        return [
            'valid' => true,
            'error' => null,
            'sanitized' => $sanitized,
        ];
    }

    /**
     * @param array<mixed> $data
     * @return array<string, mixed>
     */
    private function sanitizeRecursive(array $data, int $depth, int &$keyCount): array
    {
        if ($depth > self::MAX_DEPTH) {
            throw new \InvalidArgumentException(
                $this->trans('enduser.custom_fields.max_depth_exceeded', ['%max%' => (string) self::MAX_DEPTH], 'Custom fields nesting exceeds maximum depth of 5.')
            );
        }

        $result = [];

        foreach ($data as $key => $value) {
            $keyCount++;
            if ($keyCount > self::MAX_KEYS) {
                throw new \InvalidArgumentException(
                    $this->trans('enduser.custom_fields.too_many_keys', ['%max%' => (string) self::MAX_KEYS], 'Custom fields contain too many entries (max 100).')
                );
            }

            $stringKey = (string) $key;

            if (strlen($stringKey) > self::MAX_KEY_LENGTH) {
                throw new \InvalidArgumentException(
                    $this->trans('enduser.custom_fields.key_too_long', ['%max%' => (string) self::MAX_KEY_LENGTH], 'Custom field name exceeds maximum length of 64 characters.')
                );
            }

            // Clé ne doit pas commencer par '$' (anti-opérateur injection) ni contenir de null bytes
            if (str_starts_with($stringKey, '$') || str_contains($stringKey, "\0")) {
                throw new \InvalidArgumentException(
                    $this->trans('enduser.custom_fields.invalid_key_name', ['%key%' => $stringKey], sprintf('Invalid custom field key "%s".', $stringKey))
                );
            }

            // Vérification de la liste noire (insensible à la casse)
            $normalizedKey = strtolower(trim($stringKey));
            if (in_array($normalizedKey, self::FORBIDDEN_KEYS, true)) {
                throw new \InvalidArgumentException(
                    $this->trans('enduser.custom_fields.forbidden_key', ['%key%' => $stringKey], sprintf('Field key "%s" is reserved and cannot be modified.', $stringKey))
                );
            }

            if (is_array($value)) {
                $result[$stringKey] = $this->sanitizeRecursive($value, $depth + 1, $keyCount);
            } elseif (is_string($value)) {
                // Supprimer les octets nuls et caractères de contrôle dangereux
                $cleaned = str_replace("\0", '', $value);
                // Préserver retours chariots et tabulations, supprimer les autres caractères de contrôle ASCII
                $cleaned = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $cleaned);
                $result[$stringKey] = $cleaned ?? '';
            } elseif (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
                $result[$stringKey] = $value;
            } else {
                // Types non sérialisables (ressources, objets arbitraires)
                $result[$stringKey] = null;
            }
        }

        return $result;
    }

    private function trans(string $key, array $params = [], string $default = ''): string
    {
        if ($this->translator !== null) {
            $translated = $this->translator->trans($key, $params);
            if ($translated !== $key) {
                return $translated;
            }
        }

        return strtr($default !== '' ? $default : $key, $params);
    }
}
