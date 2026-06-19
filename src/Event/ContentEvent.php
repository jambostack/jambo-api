<?php

namespace App\Event;

use App\Entity\ContentEntry;
use App\Entity\Project;
use Symfony\Contracts\EventDispatcher\Event;

class ContentEvent extends Event
{
    public const CREATED = 'content.created';
    public const UPDATED = 'content.updated';
    public const DELETED = 'content.deleted';
    public const STATUS_CHANGED = 'content.status_changed';

    public function __construct(
        public readonly string $eventName,
        public readonly Project $project,
        public readonly ContentEntry $entry,
        public readonly string $source = 'cms',
        public string $previousStatus = '',
    ) {}
}
