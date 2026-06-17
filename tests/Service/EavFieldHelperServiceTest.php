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
}
