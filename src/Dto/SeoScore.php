<?php

namespace App\Dto;

class SeoScore
{
    /**
     * @param int $score 0-100
     * @param array<string, array{label: string, passed: bool, score: int, maxScore: int, advice: ?string}> $criteria
     * @param string[] $suggestions
     */
    public function __construct(
        public int $score = 0,
        public array $criteria = [],
        public array $suggestions = [],
    ) {}
}
