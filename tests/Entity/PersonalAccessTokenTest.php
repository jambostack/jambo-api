<?php

namespace App\Tests\Entity;

use App\Entity\PersonalAccessToken;
use PHPUnit\Framework\TestCase;

class PersonalAccessTokenTest extends TestCase
{
    public function testCanChecksScope(): void
    {
        $pat = new PersonalAccessToken();
        $pat->scopes = ['schema:write', 'projects:write'];

        self::assertTrue($pat->can('schema:write'));
        self::assertTrue($pat->can('projects:write'));
        self::assertFalse($pat->can('content:delete'));
    }

    public function testIsExpired(): void
    {
        $pat = new PersonalAccessToken();
        self::assertFalse($pat->isExpired(), 'null expiry never expires');

        $pat->expiresAt = new \DateTimeImmutable('-1 hour');
        self::assertTrue($pat->isExpired());

        $pat->expiresAt = new \DateTimeImmutable('+1 hour');
        self::assertFalse($pat->isExpired());
    }

    public function testDefaultScopeIsSchemaWrite(): void
    {
        self::assertSame(['schema:write'], (new PersonalAccessToken())->scopes);
    }
}
