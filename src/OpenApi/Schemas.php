<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Reusable OpenAPI schema definitions for JamboAPI.
 */
#[OA\Schema(
    schema: 'Error',
    properties: [
        new OA\Property(property: 'error', type: 'string', example: 'Resource not found.'),
    ]
)]
#[OA\Schema(
    schema: 'EndUser',
    properties: [
        new OA\Property(property: 'uuid', type: 'string', format: 'uuid'),
        new OA\Property(property: 'email', type: 'string', format: 'email'),
        new OA\Property(property: 'name', type: 'string', nullable: true),
        new OA\Property(property: 'status', type: 'string', enum: ['active', 'banned', 'pending']),
        new OA\Property(property: 'avatar_url', type: 'string', nullable: true),
        new OA\Property(property: 'custom_fields', type: 'object', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'TokenPair',
    properties: [
        new OA\Property(property: 'access_token', type: 'string'),
        new OA\Property(property: 'refresh_token', type: 'string'),
    ]
)]
#[OA\Schema(
    schema: 'ContentEntry',
    properties: [
        new OA\Property(property: 'uuid', type: 'string', format: 'uuid'),
        new OA\Property(property: 'status', type: 'string', enum: ['draft', 'published']),
        new OA\Property(property: 'locale', type: 'string', example: 'en'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
        new OA\Property(
            property: 'fields',
            type: 'object',
            additionalProperties: new OA\AdditionalProperties(type: 'string'),
            description: 'Dynamic field values keyed by field slug'
        ),
    ]
)]
#[OA\Schema(
    schema: 'PaginatedMeta',
    properties: [
        new OA\Property(property: 'total', type: 'integer'),
        new OA\Property(property: 'page', type: 'integer'),
        new OA\Property(property: 'per_page', type: 'integer'),
        new OA\Property(property: 'pages', type: 'integer'),
    ]
)]
#[OA\Schema(
    schema: 'MediaFile',
    properties: [
        new OA\Property(property: 'uuid', type: 'string', format: 'uuid'),
        new OA\Property(property: 'fileName', type: 'string'),
        new OA\Property(property: 'originalName', type: 'string'),
        new OA\Property(property: 'mimeType', type: 'string', example: 'image/jpeg'),
        new OA\Property(property: 'fileSize', type: 'integer'),
        new OA\Property(property: 'url', type: 'string'),
        new OA\Property(property: 'alt', type: 'string', nullable: true),
        new OA\Property(property: 'caption', type: 'string', nullable: true),
        new OA\Property(property: 'width', type: 'integer', nullable: true),
        new OA\Property(property: 'height', type: 'integer', nullable: true),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'CollectionField',
    properties: [
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'slug', type: 'string'),
        new OA\Property(property: 'type', type: 'string', example: 'text'),
        new OA\Property(property: 'isRequired', type: 'boolean'),
        new OA\Property(property: 'options', type: 'object', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'Collection',
    properties: [
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'slug', type: 'string'),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'isSingleton', type: 'boolean'),
        new OA\Property(
            property: 'fields',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/CollectionField')
        ),
    ]
)]
class Schemas
{
}
