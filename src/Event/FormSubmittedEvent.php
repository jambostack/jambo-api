<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\Form;
use App\Entity\FormSubmission;
use App\Entity\Project;
use Symfony\Contracts\EventDispatcher\Event;

class FormSubmittedEvent extends Event
{
    public const SUBMITTED = 'form.submitted';

    public function __construct(
        public readonly Form $form,
        public readonly FormSubmission $submission,
        public readonly Project $project,
        public readonly array $data = [],
    ) {}
}
