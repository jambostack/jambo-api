<?php

namespace App\Tests\Service;

use App\Entity\ContentFieldValue;
use App\Service\EavFieldHelperService;
use PHPUnit\Framework\TestCase;

class EavFieldHelperServiceTest extends TestCase
{
    private EavFieldHelperService $helper;

    protected function setUp(): void
    {
        $this->helper = new EavFieldHelperService();
    }

    public function testSetFieldValueRoutesTagsToJsonColumn(): void
    {
        $cfv = new ContentFieldValue();
        $this->helper->setFieldValue($cfv, 'tags', ['php', 'symfony']);

        $this->assertSame(['php', 'symfony'], $cfv->jsonValue);
        $this->assertNull($cfv->textValue);
    }

    public function testSetFieldValueDecodesTagsJsonString(): void
    {
        $cfv = new ContentFieldValue();
        $this->helper->setFieldValue($cfv, 'tags', '["a","b"]');

        $this->assertSame(['a', 'b'], $cfv->jsonValue);
    }

    public function testSetFieldValueRoutesRatingToNumberColumn(): void
    {
        $cfv = new ContentFieldValue();
        $this->helper->setFieldValue($cfv, 'rating', 4);

        $this->assertSame('4', $cfv->numberValue);
        $this->assertNull($cfv->textValue);
    }

    public function testValidateUrlAcceptsValidUrl(): void
    {
        $this->assertSame([], $this->helper->validateValue('url', 'https://example.com'));
    }

    public function testValidateUrlRejectsInvalidUrl(): void
    {
        $this->assertSame(['Format URL invalide'], $this->helper->validateValue('url', 'not a url'));
    }

    public function testValidateTagsRequiresArray(): void
    {
        $this->assertSame([], $this->helper->validateValue('tags', ['a', 'b']));
        $this->assertSame(['Liste de valeurs attendue (tableau)'], $this->helper->validateValue('tags', 'a,b'));
    }

    public function testValidateRatingRequiresNumeric(): void
    {
        $this->assertSame([], $this->helper->validateValue('rating', 5));
        $this->assertSame(['Note numérique attendue'], $this->helper->validateValue('rating', 'five'));
    }

    public function testValidateEmptyValueIsAlwaysValid(): void
    {
        $this->assertSame([], $this->helper->validateValue('url', ''));
        $this->assertSame([], $this->helper->validateValue('rating', null));
    }
}
