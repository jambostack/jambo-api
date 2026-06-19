<?php

namespace App\Service\Flow;

class NodeOutput
{
    public function __construct(
        public readonly array $data = [],
        public readonly string $branch = 'default',
        public readonly array $meta = [],
    ) {}
}
