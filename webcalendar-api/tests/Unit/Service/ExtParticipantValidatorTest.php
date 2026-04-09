<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\ExtParticipantValidator;
use PHPUnit\Framework\TestCase;

final class ExtParticipantValidatorTest extends TestCase
{
    private ExtParticipantValidator $v;

    protected function setUp(): void
    {
        $this->v = new ExtParticipantValidator();
    }

    public function testNullReturnsEmpty(): void
    {
        $this->assertSame([], $this->v->parse(null));
    }

    public function testEmptyArray(): void
    {
        $this->assertSame([], $this->v->parse([]));
    }

    public function testHappyPath(): void
    {
        $result = $this->v->parse([
            ['name' => 'Alice Guest', 'email' => 'alice@external.com'],
            ['name' => 'Bob Vendor', 'email' => null],
        ]);
        $this->assertCount(2, $result);
        $this->assertSame('Alice Guest', $result[0]['name']);
        $this->assertSame('alice@external.com', $result[0]['email']);
        $this->assertNull($result[1]['email']);
    }

    public function testTrimsNameAndEmail(): void
    {
        $result = $this->v->parse([['name' => '  Alice  ', 'email' => '  alice@ex.com  ']]);
        $this->assertSame('Alice', $result[0]['name']);
        $this->assertSame('alice@ex.com', $result[0]['email']);
    }

    public function testRejectsNonArrayTop(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->v->parse('not an array');
    }

    public function testRejectsNonArrayEntry(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->v->parse(['scalar']);
    }

    public function testRequiresName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/name is required/');
        $this->v->parse([['email' => 'x@y.com']]);
    }

    public function testRejectsBlankName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->v->parse([['name' => '   ', 'email' => 'x@y.com']]);
    }

    public function testRejectsTooLongName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/exceeds 60/');
        $this->v->parse([['name' => str_repeat('a', 61)]]);
    }

    public function testRejectsInvalidEmail(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not a valid email/');
        $this->v->parse([['name' => 'X', 'email' => 'not-an-email']]);
    }

    public function testRejectsNonStringEmail(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->v->parse([['name' => 'X', 'email' => 123]]);
    }

    public function testRejectsTooLongEmail(): void
    {
        $long = str_repeat('a', 70) . '@x.com'; // >75 chars
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/exceeds 75/');
        $this->v->parse([['name' => 'X', 'email' => $long]]);
    }

    public function testEmptyEmailBecomesNull(): void
    {
        $result = $this->v->parse([['name' => 'X', 'email' => '']]);
        $this->assertNull($result[0]['email']);
    }

    public function testDeduplicatesByName(): void
    {
        $result = $this->v->parse([
            ['name' => 'Alice', 'email' => 'first@ex.com'],
            ['name' => 'Alice', 'email' => 'second@ex.com'],
        ]);
        $this->assertCount(1, $result);
        $this->assertSame('first@ex.com', $result[0]['email']);
    }
}
