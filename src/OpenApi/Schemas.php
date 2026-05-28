<?php

namespace App\OpenApi;

/**
 * Schema definitions are declared in config/packages/nelmio_api_doc.yaml
 * under documentation.components.schemas.
 *
 * Nelmio API Doc Bundle 5 only scans route controllers, so non-controller
 * classes with #[OA\Schema] attributes are never picked up.
 */
class Schemas
{
}
