<?php

namespace App\Tests\Service;

use App\Entity\Field;
use App\Service\FieldConditionEvaluator;
use PHPUnit\Framework\TestCase;

class FieldConditionEvaluatorTest extends TestCase
{
    private FieldConditionEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new FieldConditionEvaluator();
    }

    private function makeField(?array $conditions): Field
    {
        $field = new Field();
        $field->slug = 'test_field';
        $field->name = 'Test Field';
        $field->type = 'text';
        if ($conditions !== null) {
            $field->options = ['conditions' => $conditions];
        }
        return $field;
    }

    // ─── Pas de conditions → toujours visible ──────────────────────

    public function testIsVisibleReturnsTrueWithoutConditions(): void
    {
        $field = $this->makeField(null);
        $this->assertTrue($this->evaluator->isVisible($field, []));
    }

    public function testIsVisibleReturnsTrueWithEmptyConditions(): void
    {
        $field = $this->makeField([]);
        $this->assertTrue($this->evaluator->isVisible($field, []));
    }

    // ─── Opérateur eq (égal) ───────────────────────────────────────

    public function testEqualsOperator(): void
    {
        $field = $this->makeField([['field' => 'category', 'operator' => 'eq', 'value' => 'blog']]);
        $this->assertTrue($this->evaluator->isVisible($field, ['category' => 'blog']));
        $this->assertFalse($this->evaluator->isVisible($field, ['category' => 'news']));
    }

    // ─── Opérateur neq (différent) ─────────────────────────────────

    public function testNotEqualsOperator(): void
    {
        $field = $this->makeField([['field' => 'category', 'operator' => 'neq', 'value' => 'archived']]);
        $this->assertTrue($this->evaluator->isVisible($field, ['category' => 'blog']));
        $this->assertFalse($this->evaluator->isVisible($field, ['category' => 'archived']));
    }

    // ─── Opérateur contains ────────────────────────────────────────

    public function testContainsOperator(): void
    {
        $field = $this->makeField([['field' => 'tags', 'operator' => 'contains', 'value' => 'urgent']]);
        $this->assertTrue($this->evaluator->isVisible($field, ['tags' => 'urgent,blog']));
        $this->assertFalse($this->evaluator->isVisible($field, ['tags' => 'normal,blog']));
    }

    // ─── Opérateur gt / gte / lt / lte ─────────────────────────────

    public function testGreaterThanOperator(): void
    {
        $field = $this->makeField([['field' => 'price', 'operator' => 'gt', 'value' => 100]]);
        $this->assertTrue($this->evaluator->isVisible($field, ['price' => 150]));
        $this->assertFalse($this->evaluator->isVisible($field, ['price' => 50]));
        $this->assertFalse($this->evaluator->isVisible($field, ['price' => 100])); // 100 pas > 100
    }

    public function testGreaterThanOrEqualOperator(): void
    {
        $field = $this->makeField([['field' => 'price', 'operator' => 'gte', 'value' => 100]]);
        $this->assertTrue($this->evaluator->isVisible($field, ['price' => 100]));
    }

    // ─── Opérateur empty / notEmpty ────────────────────────────────

    public function testEmptyOperator(): void
    {
        $field = $this->makeField([['field' => 'notes', 'operator' => 'empty']]);
        $this->assertTrue($this->evaluator->isVisible($field, ['notes' => '']));
        $this->assertTrue($this->evaluator->isVisible($field, ['notes' => null]));
        $this->assertFalse($this->evaluator->isVisible($field, ['notes' => 'something']));
    }

    public function testNotEmptyOperator(): void
    {
        $field = $this->makeField([['field' => 'notes', 'operator' => 'notEmpty']]);
        $this->assertTrue($this->evaluator->isVisible($field, ['notes' => 'something']));
        $this->assertFalse($this->evaluator->isVisible($field, ['notes' => '']));
    }

    // ─── Conditions multiples (AND) ────────────────────────────────

    public function testMultipleConditionsAllMustPass(): void
    {
        $field = $this->makeField([
            ['field' => 'category', 'operator' => 'eq', 'value' => 'blog'],
            ['field' => 'status', 'operator' => 'eq', 'value' => 'published'],
        ]);
        $this->assertTrue($this->evaluator->isVisible($field, ['category' => 'blog', 'status' => 'published']));
        $this->assertFalse($this->evaluator->isVisible($field, ['category' => 'blog', 'status' => 'draft']));
    }

    // ─── Opérateur inconnu ─────────────────────────────────────────

    public function testUnknownOperatorReturnsFalse(): void
    {
        $field = $this->makeField([['field' => 'x', 'operator' => 'unknownOp', 'value' => 'whatever']]);
        $this->assertFalse($this->evaluator->isVisible($field, ['x' => 'whatever']));
    }
}
