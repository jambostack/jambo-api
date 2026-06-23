<?php

namespace App\Enum;

enum InsightsRange: string
{
    case D7 = '7d';
    case D30 = '30d';
    case D90 = '90d';

    public static function fromQuery(?string $value): self
    {
        $range = self::tryFrom($value ?? '30d');
        if ($range === null) {
            throw new \InvalidArgumentException('Invalid range');
        }
        return $range;
    }

    public function days(): int
    {
        return match ($this) {
            self::D7 => 7,
            self::D30 => 30,
            self::D90 => 90,
        };
    }

    public function since(): \DateTimeImmutable
    {
        return new \DateTimeImmutable("-{$this->days()} days");
    }
}
