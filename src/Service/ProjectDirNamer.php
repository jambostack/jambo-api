<?php

namespace App\Service;

use App\Entity\Media;
use Vich\UploaderBundle\Mapping\PropertyMapping;
use Vich\UploaderBundle\Naming\DirectoryNamerInterface;

class ProjectDirNamer implements DirectoryNamerInterface
{
    public function directoryName(object|array $object, PropertyMapping $mapping): string
    {
        /** @var Media $object */
        return (string) $object->project->uuid;
    }
}
