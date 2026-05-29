<?php

namespace App\Message;

final class DeployHostedAppMessage
{
    public function __construct(
        public readonly int $workbenchProjectId,
    ) {}
}
