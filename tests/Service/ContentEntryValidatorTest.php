<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Collection;
use App\Entity\Field;
use App\Entity\Project;
use App\Service\ContentEntryValidator;
use App\Service\EavFieldHelperService;
use PHPUnit\Framework\TestCase;

class ContentEntryValidatorTest extends TestCase
{
    private ContentEntryValidator $validator;

    protected function setUp(): void
    {
        $fieldHelper = new EavFieldHelperService();
        $this->validator = new ContentEntryValidator($fieldHelper);
    }

    private function createCollectionWithFields(array $fieldDefinitions): Collection
    {
        $project = new Project();
        $collection = new Collection();
        $collection->project = $project;
        $collection->name = 'Articles';
        $collection->slug = 'articles';

        foreach ($fieldDefinitions as $def) {
            $field = new Field();
            $field->collection = $collection;
            $field->name = $def['name'];
            $field->slug = $def['slug'];
            $field->type = $def['type'];
            $field->isRequired = $def['isRequired'] ?? false;
            $field->options = $def['options'] ?? null;
            $field->validationRules = $def['validationRules'] ?? null;
            $collection->fields->add($field);
        }

        return $collection;
    }

    public function testRequiredFieldsValidation(): void
    {
        $collection = $this->createCollectionWithFields([
            ['name' => 'Titre', 'slug' => 'title', 'type' => 'text', 'isRequired' => true],
            ['name' => 'Contenu', 'slug' => 'body', 'type' => 'richtext', 'isRequired' => false],
        ]);

        $errors = $this->validator->validateEntry($collection, ['body' => 'Quelque chose']);
        $this->assertArrayHasKey('title', $errors);
        $this->assertStringContainsString('obligatoire', $errors['title'][0]);

        $validErrors = $this->validator->validateEntry($collection, ['title' => 'Mon titre']);
        $this->assertEmpty($validErrors);
    }

    public function testRepeaterValidationMinMaxItems(): void
    {
        $collection = $this->createCollectionWithFields([
            [
                'name' => 'Sections',
                'slug' => 'sections',
                'type' => 'repeater',
                'isRequired' => true,
                'validationRules' => ['min_items' => 2, 'max_items' => 4],
            ],
        ]);

        // Non-array input
        $errors = $this->validator->validateEntry($collection, ['sections' => 'not an array']);
        $this->assertArrayHasKey('sections', $errors);

        // Less than min_items
        $errors = $this->validator->validateEntry($collection, ['sections' => [['title' => 'Seule section']]]);
        $this->assertArrayHasKey('sections', $errors);
        $this->assertStringContainsString('au moins 2', $errors['sections'][0]);

        // Exceeding max_items
        $errors = $this->validator->validateEntry($collection, [
            'sections' => [
                ['title' => '1'], ['title' => '2'], ['title' => '3'], ['title' => '4'], ['title' => '5']
            ]
        ]);
        $this->assertArrayHasKey('sections', $errors);
        $this->assertStringContainsString('dépasser 4', $errors['sections'][0]);

        // Valid count
        $valid = $this->validator->validateEntry($collection, [
            'sections' => [['title' => '1'], ['title' => '2']]
        ]);
        $this->assertEmpty($valid);
    }

    public function testRepeaterSubfieldRequirements(): void
    {
        $collection = $this->createCollectionWithFields([
            [
                'name' => 'Slides',
                'slug' => 'slides',
                'type' => 'repeater',
                'options' => [
                    'fields' => [
                        ['name' => 'Image URL', 'slug' => 'imageUrl', 'required' => true],
                        ['name' => 'Légende', 'slug' => 'caption', 'required' => false],
                    ],
                ],
            ],
        ]);

        // Missing required subfield imageUrl
        $errors = $this->validator->validateEntry($collection, [
            'slides' => [
                ['caption' => 'Photo sans image']
            ]
        ]);

        $this->assertArrayHasKey('slides', $errors);
        $this->assertStringContainsString('imageUrl', $errors['slides'][0]);

        // Valid subfields
        $valid = $this->validator->validateEntry($collection, [
            'slides' => [
                ['imageUrl' => 'https://cdn.example.com/1.jpg', 'caption' => 'Slide 1']
            ]
        ]);
        $this->assertEmpty($valid);
    }

    public function testJsonSchemaValidationOnComplexField(): void
    {
        $collection = $this->createCollectionWithFields([
            [
                'name' => 'Configuration Produit',
                'slug' => 'productConfig',
                'type' => 'json',
                'validationRules' => [
                    'schema' => [
                        'type' => 'object',
                        'required' => ['sku', 'price'],
                        'properties' => [
                            'sku'   => ['type' => 'string'],
                            'price' => ['type' => 'number'],
                        ],
                    ],
                ],
            ],
        ]);

        // Missing required property 'price' and wrong type for 'sku'
        $errors = $this->validator->validateEntry($collection, [
            'productConfig' => [
                'sku' => 12345, // should be string
            ]
        ]);

        $this->assertArrayHasKey('productConfig', $errors);
        $errorStrings = implode(' ', $errors['productConfig']);
        $this->assertStringContainsString('price', $errorStrings);
        $this->assertStringContainsString('type string', $errorStrings);

        // Correct JSON payload
        $valid = $this->validator->validateEntry($collection, [
            'productConfig' => [
                'sku' => 'PROD-9988',
                'price' => 49.99,
            ]
        ]);
        $this->assertEmpty($valid);
    }
}
