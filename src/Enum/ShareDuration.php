<?php

namespace App\Enum;

enum ShareDuration: string
{
    case H1 = '1h';
    case H24 = '24h';
    case D7 = '7d';
    case D30 = '30d';
    case NEVER = 'never';

    public static function fromQuery(?string $value): self
    {
        $d = self::tryFrom($value ?? '7d');
        if ($d === null) {
            throw new \InvalidArgumentException('Invalid share duration');
        }
        return $d;
    }

    public function expiresAtFrom(\DateTimeImmutable $now): ?\DateTimeImmutable
    {
        return match ($this) {
            self::H1    => $now->modify('+1 hour'),
            self::H24   => $now->modify('+24 hours'),
            self::D7    => $now->modify('+7 days'),
            self::D30   => $now->modify('+30 days'),
            self::NEVER => null,
        };
    }
}
