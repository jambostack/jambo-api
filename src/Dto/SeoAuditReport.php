<?php

namespace App\Dto;

class SeoAuditReport
{
    /**
     * @param array{title: string, url: string, statusCode: int}[] $brokenLinks
     * @param string[] $warnings
     */
    public function __construct(
        public SeoScore $score,
        public array $brokenLinks = [],
        public array $warnings = [],
    ) {}
}
