<?php
namespace App\Tests\Service\Form;

use App\Entity\Form;
use App\Service\Form\FormBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraints as Assert;

class FormBuilderTest extends TestCase
{
    private FormBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new FormBuilder();
    }

    public function testValidateDefinitionValidFields(): void
    {
        $fields = [
            ['id' => 'name', 'type' => 'text', 'label' => 'Name'],
            ['id' => 'email', 'type' => 'email', 'label' => 'Email'],
            ['id' => 'message', 'type' => 'textarea', 'label' => 'Message'],
        ];

        $result = $this->builder->validateDefinition($fields);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    public function testValidateDefinitionMissingType(): void
    {
        $fields = [
            ['id' => 'name', 'label' => 'Name'],
        ];

        $result = $this->builder->validateDefinition($fields);

        $this->assertFalse($result['valid']);
        $this->assertCount(1, $result['errors']);
    }

    public function testValidateDefinitionMissingLabel(): void
    {
        $fields = [
            ['id' => 'name', 'type' => 'text'],
        ];

        $result = $this->builder->validateDefinition($fields);

        $this->assertFalse($result['valid']);
        $this->assertCount(1, $result['errors']);
    }

    public function testValidateDefinitionInvalidType(): void
    {
        $fields = [
            ['id' => 'myfield', 'type' => 'invalid_type', 'label' => 'My Field'],
        ];

        $result = $this->builder->validateDefinition($fields);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('Invalid field type', $result['errors']['myfield'][0]);
    }

    public function testValidateDefinitionAllowsHeadingType(): void
    {
        $fields = [
            ['id' => 'section', 'type' => 'heading', 'label' => 'Section Title'],
        ];

        $result = $this->builder->validateDefinition($fields);

        $this->assertTrue($result['valid']);
    }

    public function testBuildFormSchemaEmailType(): void
    {
        $form = $this->createFormWithFields([
            ['name' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true],
        ]);

        $schema = $this->builder->buildFormSchema($form);

        $this->assertArrayHasKey('email', $schema);
        $this->assertCount(2, $schema['email']); // Email constraint + NotBlank

        $constraintClasses = array_map(fn($c) => $c::class, $schema['email']);
        $this->assertContains(Assert\Email::class, $constraintClasses);
        $this->assertContains(Assert\NotBlank::class, $constraintClasses);
    }

    public function testBuildFormSchemaTelType(): void
    {
        $form = $this->createFormWithFields([
            ['name' => 'phone', 'type' => 'tel', 'label' => 'Phone'],
        ]);

        $schema = $this->builder->buildFormSchema($form);

        $this->assertArrayHasKey('phone', $schema);
        $this->assertCount(1, $schema['phone']);

        $this->assertInstanceOf(Assert\Regex::class, $schema['phone'][0]);
    }

    public function testBuildFormSchemaNumberType(): void
    {
        $form = $this->createFormWithFields([
            ['name' => 'age', 'type' => 'number', 'label' => 'Age'],
        ]);

        $schema = $this->builder->buildFormSchema($form);

        $this->assertArrayHasKey('age', $schema);
        $this->assertInstanceOf(Assert\Type::class, $schema['age'][0]);
    }

    public function testBuildFormSchemaDateType(): void
    {
        $form = $this->createFormWithFields([
            ['name' => 'birthday', 'type' => 'date', 'label' => 'Birthday'],
        ]);

        $schema = $this->builder->buildFormSchema($form);

        $this->assertArrayHasKey('birthday', $schema);
        $this->assertInstanceOf(Assert\Date::class, $schema['birthday'][0]);
    }

    public function testBuildFormSchemaWithLengthConstraints(): void
    {
        $form = $this->createFormWithFields([
            ['name' => 'bio', 'type' => 'textarea', 'label' => 'Bio', 'minLength' => 10, 'maxLength' => 500],
        ]);

        $schema = $this->builder->buildFormSchema($form);

        $this->assertArrayHasKey('bio', $schema);
        $this->assertCount(1, $schema['bio']); // min + max in a single Length constraint

        $constraint = $schema['bio'][0];
        $this->assertInstanceOf(Assert\Length::class, $constraint);
        $this->assertSame(10, $constraint->min);
        $this->assertSame(500, $constraint->max);
    }

    public function testBuildFormSchemaWithChoiceOptions(): void
    {
        $form = $this->createFormWithFields([
            [
                'name' => 'country',
                'type' => 'select',
                'label' => 'Country',
                'options' => [
                    ['value' => 'fr', 'label' => 'France'],
                    ['value' => 'uk', 'label' => 'UK'],
                ],
            ],
        ]);

        $schema = $this->builder->buildFormSchema($form);

        $this->assertArrayHasKey('country', $schema);
        $this->assertInstanceOf(Assert\Choice::class, $schema['country'][0]);
    }

    public function testBuildFormSchemaSkipsFieldsWithoutName(): void
    {
        $form = $this->createFormWithFields([
            ['type' => 'text', 'label' => 'No Name'],
        ]);

        $schema = $this->builder->buildFormSchema($form);

        $this->assertEmpty($schema);
    }

    public function testBuildFormSchemaTextTypeNoConstraints(): void
    {
        $form = $this->createFormWithFields([
            ['name' => 'name', 'type' => 'text', 'label' => 'Name'],
        ]);

        $schema = $this->builder->buildFormSchema($form);

        // text type has no type constraint, and not required
        $this->assertArrayNotHasKey('name', $schema);
    }

    public function testResolveConditionsNoConditions(): void
    {
        $fields = [
            ['name' => 'name', 'type' => 'text', 'label' => 'Name'],
            ['name' => 'email', 'type' => 'email', 'label' => 'Email'],
        ];

        $visible = $this->builder->resolveConditions($fields, []);

        $this->assertEquals(['name', 'email'], $visible);
    }

    public function testResolveConditionsEquals(): void
    {
        $fields = [
            ['name' => 'contact_method', 'type' => 'select', 'label' => 'Method'],
            [
                'name' => 'phone',
                'type' => 'tel',
                'label' => 'Phone',
                'conditions' => ['field' => 'contact_method', 'operator' => 'equals', 'value' => 'phone'],
            ],
        ];

        $visible = $this->builder->resolveConditions($fields, ['contact_method' => 'phone']);
        $this->assertContains('phone', $visible);

        $hidden = $this->builder->resolveConditions($fields, ['contact_method' => 'email']);
        $this->assertNotContains('phone', $hidden);
    }

    public function testResolveConditionsNotEquals(): void
    {
        $fields = [
            ['name' => 'newsletter', 'type' => 'checkbox', 'label' => 'Newsletter'],
            [
                'name' => 'email',
                'type' => 'email',
                'label' => 'Email',
                'conditions' => ['field' => 'newsletter', 'operator' => 'not_equals', 'value' => 'opt_out'],
            ],
        ];

        $visible = $this->builder->resolveConditions($fields, ['newsletter' => 'subscribed']);
        $this->assertContains('email', $visible);

        $hidden = $this->builder->resolveConditions($fields, ['newsletter' => 'opt_out']);
        $this->assertNotContains('email', $hidden);
    }

    public function testResolveConditionsContains(): void
    {
        $fields = [
            ['name' => 'query', 'type' => 'text', 'label' => 'Query'],
            [
                'name' => 'urgent',
                'type' => 'checkbox',
                'label' => 'Urgent',
                'conditions' => ['field' => 'query', 'operator' => 'contains', 'value' => 'urgent'],
            ],
        ];

        $visible = $this->builder->resolveConditions($fields, ['query' => 'This is urgent help']);
        $this->assertContains('urgent', $visible);

        $hidden = $this->builder->resolveConditions($fields, ['query' => 'Just a question']);
        $this->assertNotContains('urgent', $hidden);
    }

    public function testResolveConditionsGreaterThan(): void
    {
        $fields = [
            ['name' => 'age', 'type' => 'number', 'label' => 'Age'],
            [
                'name' => 'is_adult',
                'type' => 'checkbox',
                'label' => 'Is adult',
                'conditions' => ['field' => 'age', 'operator' => 'greater_than', 'value' => 18],
            ],
        ];

        $visible = $this->builder->resolveConditions($fields, ['age' => '25']);
        $this->assertContains('is_adult', $visible);

        $visibleUnder = $this->builder->resolveConditions($fields, ['age' => '15']);
        $this->assertNotContains('is_adult', $visibleUnder);
    }

    public function testResolveConditionsIsEmpty(): void
    {
        $fields = [
            ['name' => 'other_contact', 'type' => 'text', 'label' => 'Other contact'],
            [
                'name' => 'other_detail',
                'type' => 'text',
                'label' => 'Detail',
                'conditions' => ['field' => 'other_contact', 'operator' => 'is_empty'],
            ],
        ];

        $visible = $this->builder->resolveConditions($fields, ['other_contact' => '']);
        $this->assertContains('other_detail', $visible);

        $hidden = $this->builder->resolveConditions($fields, ['other_contact' => 'John']);
        $this->assertNotContains('other_detail', $hidden);
    }

    public function testResolveConditionsIsNotEmpty(): void
    {
        $fields = [
            ['name' => 'other_contact', 'type' => 'text', 'label' => 'Other contact'],
            [
                'name' => 'other_detail',
                'type' => 'text',
                'label' => 'Detail',
                'conditions' => ['field' => 'other_contact', 'operator' => 'is_not_empty'],
            ],
        ];

        $visible = $this->builder->resolveConditions($fields, ['other_contact' => 'John']);
        $this->assertContains('other_detail', $visible);

        $hidden = $this->builder->resolveConditions($fields, ['other_contact' => '']);
        $this->assertNotContains('other_detail', $hidden);
    }

    public function testResolveConditionsAndOperator(): void
    {
        $fields = [
            ['name' => 'age', 'type' => 'number', 'label' => 'Age'],
            ['name' => 'country', 'type' => 'select', 'label' => 'Country'],
            [
                'name' => 'guardian_name',
                'type' => 'text',
                'label' => 'Guardian name',
                'conditions' => [
                    'operator' => 'and',
                    'conditions' => [
                        ['field' => 'age', 'operator' => 'greater_than', 'value' => 18],
                        ['field' => 'country', 'operator' => 'equals', 'value' => 'US'],
                    ],
                ],
            ],
        ];

        // Both conditions met
        $visible = $this->builder->resolveConditions($fields, ['age' => '25', 'country' => 'US']);
        $this->assertContains('guardian_name', $visible);

        // Only one condition met
        $hidden = $this->builder->resolveConditions($fields, ['age' => '25', 'country' => 'FR']);
        $this->assertNotContains('guardian_name', $hidden);
    }

    public function testResolveConditionsOrOperator(): void
    {
        $fields = [
            ['name' => 'role', 'type' => 'select', 'label' => 'Role'],
            [
                'name' => 'admin_section',
                'type' => 'text',
                'label' => 'Admin section',
                'conditions' => [
                    'operator' => 'or',
                    'conditions' => [
                        ['field' => 'role', 'operator' => 'equals', 'value' => 'admin'],
                        ['field' => 'role', 'operator' => 'equals', 'value' => 'superadmin'],
                    ],
                ],
            ],
        ];

        $visible = $this->builder->resolveConditions($fields, ['role' => 'admin']);
        $this->assertContains('admin_section', $visible);

        $visible2 = $this->builder->resolveConditions($fields, ['role' => 'superadmin']);
        $this->assertContains('admin_section', $visible2);

        $hidden = $this->builder->resolveConditions($fields, ['role' => 'user']);
        $this->assertNotContains('admin_section', $hidden);
    }

    public function testResolveConditionsFieldWithoutName(): void
    {
        $fields = [
            ['type' => 'text', 'label' => 'No name'],
        ];

        $visible = $this->builder->resolveConditions($fields, []);
        $this->assertEmpty($visible);
    }

    private function createFormWithFields(array $fields): Form
    {
        $form = new Form();
        $form->fields = $fields;
        return $form;
    }
}
