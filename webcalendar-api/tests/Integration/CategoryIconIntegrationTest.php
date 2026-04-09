<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\CategoryIconRepository;
use App\Service\EmojiValidator;

final class CategoryIconIntegrationTest extends IntegrationTestCase
{
    private CategoryIconRepository $icons;

    protected function setUp(): void
    {
        parent::setUp();
        $this->icons = new CategoryIconRepository($this->pdo);
        $this->icons->ensureSchema();
    }

    public function testSetAndGetIcon(): void
    {
        $this->icons->set(1, 'admin', '🎂');
        $this->assertSame('🎂', $this->icons->get(1, 'admin'));
    }

    public function testGetMissingReturnsNull(): void
    {
        $this->assertNull($this->icons->get(999, 'admin'));
    }

    public function testSetNullClearsIcon(): void
    {
        $this->icons->set(1, 'admin', '🎉');
        $this->icons->set(1, 'admin', null);
        $this->assertNull($this->icons->get(1, 'admin'));
    }

    public function testSetOverwritesExisting(): void
    {
        $this->icons->set(1, 'admin', '🎂');
        $this->icons->set(1, 'admin', '🎈');
        $this->assertSame('🎈', $this->icons->get(1, 'admin'));
    }

    public function testOwnerScoped(): void
    {
        // Same cat_id, different owners (global vs personal) → distinct rows
        $this->icons->set(1, null, '🌐');
        $this->icons->set(1, 'alice', '👤');
        $this->assertSame('🌐', $this->icons->get(1, null));
        $this->assertSame('👤', $this->icons->get(1, 'alice'));
    }

    public function testDelete(): void
    {
        $this->icons->set(5, 'admin', '📌');
        $this->icons->delete(5, 'admin');
        $this->assertNull($this->icons->get(5, 'admin'));
    }

    public function testGetBatch(): void
    {
        $this->icons->set(1, 'admin', '🎂');
        $this->icons->set(2, 'admin', '🎉');
        // id=3 has no icon
        $result = $this->icons->getBatchForOwner([1, 2, 3], 'admin');
        $this->assertSame('🎂', $result[1] ?? null);
        $this->assertSame('🎉', $result[2] ?? null);
        $this->assertArrayNotHasKey(3, $result);
    }

    public function testEnsureSchemaIsIdempotent(): void
    {
        $this->icons->ensureSchema();
        $this->icons->ensureSchema(); // Second call must not error
        $this->icons->set(1, 'admin', '✅');
        $this->assertSame('✅', $this->icons->get(1, 'admin'));
    }

    // -- EmojiValidator --

    public function testValidatorAcceptsSingleEmoji(): void
    {
        $v = new EmojiValidator();
        $this->assertTrue($v->isValid('🎂'));
        $this->assertTrue($v->isValid('🎉'));
        $this->assertTrue($v->isValid('📌'));
    }

    public function testValidatorAcceptsMultiCodepointEmoji(): void
    {
        $v = new EmojiValidator();
        // Family emoji is multiple codepoints joined with ZWJ — one grapheme
        $this->assertTrue($v->isValid('👨‍👩‍👧'));
        $this->assertTrue($v->isValid('🏳️‍🌈'));
    }

    public function testValidatorRejectsPlainText(): void
    {
        $v = new EmojiValidator();
        $this->assertFalse($v->isValid('IMPORTANT'));
        $this->assertFalse($v->isValid('A'));
        $this->assertFalse($v->isValid('!'));
    }

    public function testValidatorRejectsMultipleGraphemes(): void
    {
        $v = new EmojiValidator();
        $this->assertFalse($v->isValid('🎂🎉'));
        $this->assertFalse($v->isValid('hi 🎂'));
    }

    public function testValidatorAcceptsNullAndEmpty(): void
    {
        $v = new EmojiValidator();
        $this->assertTrue($v->isValid(null));
        $this->assertTrue($v->isValid(''));
    }
}
